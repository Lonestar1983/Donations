<?php
/**
 * Handle donation creation, PayPal returns, capture requests, and cancellation.
 *
 * Modal requests receive JSON; regular requests receive an approval redirect or
 * a standalone confirmation page. Payment-changing actions require POST and
 * CSRF validation. Return and capture actions also verify checkout ownership.
 * Existing reconciliation and PayPal request IDs support capture retries.
 */

defined( 'MODULE_FILE' ) || define( 'MODULE_FILE', true );

ob_start();

require_once __DIR__ . '/../../mainfile.php';
require_once __DIR__ . '/includes/functions.php';

evo_donations_load_language();

header( 'Cache-Control: no-store, private' );
header( 'Referrer-Policy: no-referrer' );
header( 'X-Content-Type-Options: nosniff' );

$request_method = $_SERVER[ 'REQUEST_METHOD' ] ?? '';
$response_code  = 200;
$ed_modal       = $request_method === 'POST' && ( $_POST[ 'ed_modal' ] ?? '' ) === '1';

/**
 * Send a JSON checkout response and terminate the request.
 *
 * Clears the endpoint output buffer before sending JSON so bootstrap output does
 * not corrupt the response consumed by the PayPal button callbacks.
 *
 * @param array $data Response payload containing safe client-facing values.
 * @param int   $code HTTP status code. Defaults to 200.
 *
 * @return void This function terminates execution after sending the response.
 */
function evo_donations_send_json_response( array $data, $code = 200 ) {
	ob_end_clean();
	http_response_code( $code );
	header( 'Content-Type: application/json; charset=UTF-8' );
	echo json_encode( $data, JSON_INVALID_UTF8_SUBSTITUTE );
	exit();
}
$message = 'error';
$confirm = null;
$mode = '';
try {
	$installation = evo_donations_get_installation_state();

	if ( empty( $installation[ 'ready' ] ) ) {
		throw new RuntimeException( evo_donations_translate( 'exception_donations_have_not_been_installed' ) );
	}
	evo_donations_ensure_session();
	$action = $_POST[ 'action' ] ?? ( $_GET[ 'action' ] ?? '' );
	if ( ! is_string( $action ) ) {
		throw new RuntimeException( evo_donations_translate( 'exception_invalid_action' ) );
	}
	if ( $action === 'create' || $action === 'capture' ) {
		if ( $request_method !== 'POST' ) {
			$response_code = 405;
			header( 'Allow: POST' );
			throw new RuntimeException( evo_donations_translate( 'exception_post_required' ) );
		}

		evo_donations_validate_csrf_token();
	}
	if ( $action === 'create' ) {
		$settings = evo_donations_get_settings();
		$mode = $settings[ 'environment' ];
		if ( ! $settings[ 'enabled' ] || ( ! $settings[ 'allow_guests' ] && ! is_user() ) ) {
			throw new RuntimeException( evo_donations_translate( 'exception_donations_unavailable' ) );
		}
		if ( time() - (int) ( $_SESSION[ 'ed_last_create' ] ?? 0 ) < 10 ) {
			throw new RuntimeException( evo_donations_translate( 'exception_please_wait' ) );
		}
		$_SESSION[ 'ed_last_create' ] = time();
		$submitted_amount = $_POST[ 'amount' ] ?? '';
		if ( ! is_string( $submitted_amount ) ) {
			throw new RuntimeException( evo_donations_translate( 'exception_invalid_donation_amount' ) );
		}
		$amount = evo_donations_amount_to_cents( $submitted_amount );
		if ( $amount < (int) $settings[ 'min_cents' ] || $amount > (int) $settings[ 'max_cents' ] ) {
			throw new RuntimeException( evo_donations_translate( 'exception_amount_outside_limits' ) );
		}

		$paypal_config = evo_donations_get_paypal_config( $settings[ 'environment' ] );
		$id = bin2hex( random_bytes( 16 ) );
		global $userinfo;
		// Guest donations are always anonymous in public supporter lists.
		$user_id = is_user() ? (int) ( $userinfo[ 'user_id' ] ?? 0 ) : 0;
		$name = $user_id
			? substr( (string) ( $userinfo[ 'username' ] ?? '' ), 0, 100 )
			: evo_donations_translate( 'guest' );
		$anonymous = isset( $_POST[ 'anonymous' ] ) || ! $user_id ? 1 : 0;

		evo_donations_run_query(
			'INSERT INTO ' .
				evo_donations_get_table_name( 'transactions' ) .
				' (id,environment,user_id,display_name,anonymous,amount_cents,currency,status,created_at,updated_at) VALUES (' .
				evo_donations_quote_sql_string( $id ) .
				',' .
				evo_donations_quote_sql_string( $settings[ 'environment' ] ) .
				',' .
				$user_id .
				',' .
				evo_donations_quote_sql_string( $name ) .
				',' .
				$anonymous .
				',' .
				$amount .
				',' .
				evo_donations_quote_sql_string( $settings[ 'currency' ] ) .
				",'CREATED',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
		);
		if ( ! is_array( $_SESSION[ 'ed_orders' ] ?? null ) ) {
			$_SESSION[ 'ed_orders' ] = [];
		}
		$_SESSION[ 'ed_orders' ][ $id ] = time();
		// Retain only recent checkout ownership markers in this session.
		$_SESSION[ 'ed_orders' ] = array_filter(
			$_SESSION[ 'ed_orders' ],
			/**
			 * Retain checkout ownership markers created within the last three days.
			 *
			 * @param mixed $created Unix timestamp when checkout ownership was recorded.
			 * @return bool Whether the ownership marker is still valid.
			 */
			static function ( $created ) {
				return is_int( $created ) && $created > time() - 259200;
			},
		);

		$url = evo_donations_get_checkout_url() . '?action=return&donation=' . $id;

		$order = evo_donations_call_paypal_api(
			$settings[ 'environment' ],
			'POST',
			'/v2/checkout/orders',
			[
				'intent' => 'CAPTURE',
				'purchase_units' => [
					[
						'reference_id' => 'donation',
						'custom_id' => $id,
						'description' => 'Community donation',
						'payee' => [ 'merchant_id' => $paypal_config[ 'merchant_id' ] ],
						'amount' => [
							'currency_code' => $settings[ 'currency' ],
							'value' => evo_donations_format_amount( $amount ),
						],
					],
				],
				'payment_source' => [
					'paypal' => [
						'experience_context' => [
							'shipping_preference' => 'NO_SHIPPING',
							'user_action' => 'PAY_NOW',
							'return_url' => $url,
							'cancel_url' => evo_donations_get_checkout_url() . '?action=cancel',
						],
					],
				],
			],
			'create-' . $id,
		);
		if ( ! is_string( $order[ 'id' ] ?? null ) || ! preg_match( '/^[A-Z0-9]{1,36}$/D', $order[ 'id' ] ) ) {
			throw new RuntimeException( evo_donations_translate( 'exception_invalid_paypal_order' ) );
		}
		evo_donations_run_query(
			'UPDATE ' .
				evo_donations_get_table_name( 'transactions' ) .
				' SET order_id=' .
				evo_donations_quote_sql_string( $order[ 'id' ] ) .
				' WHERE id=' .
				evo_donations_quote_sql_string( $id ),
		);

		if ( $ed_modal ) {
			evo_donations_send_json_response( [ 'orderID' => $order[ 'id' ], 'donation' => $id ] );
		}
		$approval = null;
		$approval_links = is_array( $order[ 'links' ] ?? null ) ? $order[ 'links' ] : [];
		foreach ( $approval_links as $link ) {
			if ( is_array( $link ) && in_array( $link[ 'rel' ] ?? '', [ 'approve', 'payer-action' ], true ) ) {
				$approval = $link[ 'href' ] ?? null;
				break;
			}
		}
		$host = $settings[ 'environment' ] === 'live' ? 'www.paypal.com' : 'www.sandbox.paypal.com';
		if (
			! is_string( $approval ) ||
			preg_match( '/[\x00-\x20\x7f]/', $approval ) ||
			parse_url( $approval, PHP_URL_SCHEME ) !== 'https' ||
			parse_url( $approval, PHP_URL_HOST ) !== $host ||
			parse_url( $approval, PHP_URL_USER ) !== null
		) {
			throw new RuntimeException( evo_donations_translate( 'exception_invalid_approval_url' ) );
		}
		ob_end_clean();
		header( 'Location: ' . $approval, true, 303 );
		exit();
	} elseif ( $action === 'return' ) {
		$donation_id = $_GET[ 'donation' ] ?? '';
		if ( ! is_string( $donation_id ) ) {
			throw new RuntimeException( evo_donations_translate( 'exception_invalid_donation_identifier' ) );
		}
		$donation = evo_donations_get_donation( $donation_id );
		evo_donations_require_checkout_owner( $donation );
		if ( ! is_string( $_GET[ 'token' ] ?? null ) || ! hash_equals( (string) $donation[ 'order_id' ], $_GET[ 'token' ] ) ) {
			throw new RuntimeException( evo_donations_translate( 'exception_order_mismatch' ) );
		}
		$mode = $donation[ 'environment' ];
		$status = evo_donations_reconcile_payment( $donation );
		if ( $status === 'COMPLETED' ) {
			$message = 'complete';
		} elseif ( $donation[ 'capture_id' ] || $status !== 'CREATED' ) {
			$message = 'pending';
		} else {
			$confirm = $donation;
			$message = 'confirm_help';
		}
	} elseif ( $action === 'capture' ) {
		$donation_id = $_POST[ 'donation' ] ?? '';
		if ( ! is_string( $donation_id ) ) {
			throw new RuntimeException( evo_donations_translate( 'exception_invalid_donation_identifier' ) );
		}
		$donation = evo_donations_get_donation( $donation_id );
		evo_donations_require_checkout_owner( $donation );
		if (
			$ed_modal &&
			( ! is_string( $_POST[ 'orderID' ] ?? null ) ||
				! hash_equals( (string) $donation[ 'order_id' ], $_POST[ 'orderID' ] ) )
		) {
			throw new RuntimeException( evo_donations_translate( 'exception_order_mismatch' ) );
		}
		if ( empty( $donation[ 'order_id' ] ) ) {
			throw new RuntimeException( evo_donations_translate( 'exception_order_missing' ) );
		}
		$mode = $donation[ 'environment' ];
		$status = evo_donations_reconcile_payment( $donation );
		if ( $status === 'CREATED' ) {
			try {
				evo_donations_call_paypal_api(
					$donation[ 'environment' ],
					'POST',
					'/v2/checkout/orders/' . rawurlencode( $donation[ 'order_id' ] ) . '/capture',
					new stdClass(),
					'capture-' . $donation[ 'id' ],
				);
			} catch ( Throwable $exception ) {
				/* Reconcile uncertain captures before reporting. */
			}
			$status = evo_donations_reconcile_payment( $donation );
		}
		$message = $status === 'COMPLETED' ? 'complete' : 'pending';
		if ( $ed_modal ) {
			evo_donations_send_json_response( [
				'status' => $status,
				'message' => evo_donations_translate( $message ),
			] );
		}
	} elseif ( $action === 'cancel' ) {
		$message = 'cancel';
	} else {
		throw new RuntimeException( evo_donations_translate( 'exception_invalid_action' ) );
	}
} catch ( Throwable $exception ) {
	$response_code = $response_code >= 400 ? $response_code : 400;
	if ( $ed_modal ) {
		evo_donations_send_json_response( [ 'error' => evo_donations_translate( 'error' ) ], $response_code );
	}
	http_response_code( $response_code );
	$message = 'error';
}
ob_end_clean();
header( 'Content-Type: text/html; charset=UTF-8' );
?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width,initial-scale=1">
	<title><?php echo evo_donations_escape( evo_donations_translate( 'title' ) ); ?></title>
	<link rel="stylesheet" href="assets/donations.css">
</head>
<body>
	<main class="ed-donations ed-checkout">
		<h1><?php echo evo_donations_escape( evo_donations_translate( 'title' ) ); ?></h1>
		<p role="status"><?php echo evo_donations_escape( evo_donations_translate( $message ) ); ?></p>

		<?php if ( $confirm ) : ?>
			<p><?php echo evo_donations_escape( evo_donations_format_amount( $confirm['amount_cents'] ) . ' ' . $confirm['currency'] ); ?></p>
			<form method="post">
				<?php echo evo_donations_csrf_field(); ?>
				<input type="hidden" name="action" value="capture">
				<input type="hidden" name="donation" value="<?php echo evo_donations_escape( $confirm['id'] ); ?>">
				<button type="submit"><?php echo evo_donations_escape( evo_donations_translate( 'confirm' ) ); ?></button>
			</form>
		<?php endif; ?>

		<p><a href="../../modules.php?name=Donations"><?php echo evo_donations_escape( evo_donations_translate( 'back' ) ); ?></a></p>
	</main>
</body>
</html>

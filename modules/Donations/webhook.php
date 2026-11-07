<?php
/**
 * Receive and reconcile PayPal donation webhook events.
 *
 * This public endpoint authenticates events through PayPal's signature
 * verification API rather than browser sessions or CSRF tokens. Transaction
 * lookups are restricted to the requested PayPal environment. Payment state
 * is reconciled through the existing donation helpers after verification.
 *
 * Successful and unhandled verified events receive HTTP 204. Invalid requests
 * receive a client error; processing failures receive HTTP 503 for redelivery.
 */

defined( 'MODULE_FILE' ) || define( 'MODULE_FILE', true );

// Discard CMS output so the response contains only the webhook result.
$webhook_buffer_level = ob_get_level();
ob_start();
$webhook_status = 503;

try {
	require_once __DIR__ . '/../../mainfile.php';
	require_once __DIR__ . '/includes/functions.php';

	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		$webhook_status = 405;
		throw new RuntimeException( evo_donations_translate( 'exception_post_required' ) );
	}

	$installation = evo_donations_get_installation_state();

	if ( empty( $installation['ready'] ) ) {
		throw new RuntimeException( evo_donations_translate( 'exception_donations_have_not_been_installed' ) );
	}

	$mode = $_GET['mode'] ?? '';

	if ( ! is_string( $mode ) ) {
		$webhook_status = 400;
		throw new RuntimeException( evo_donations_translate( 'exception_invalid_environment_parameter' ) );
	}

	$config = evo_donations_get_paypal_config( $mode );
	$raw    = file_get_contents( 'php://input', false, null, 0, 262145 );

	if ( false === $raw ) {
		throw new RuntimeException( evo_donations_translate( 'exception_could_not_read_the_request_body' ) );
	}

	// Read one byte beyond the 256 KiB limit to detect oversized payloads.
	if ( strlen( $raw ) > 262144 ) {
		$webhook_status = 413;
		throw new RuntimeException( evo_donations_translate( 'exception_payload_too_large' ) );
	}

	try {
		$event = json_decode( $raw, true, 64, JSON_THROW_ON_ERROR );
	} catch ( JsonException $exception ) {
		$webhook_status = 400;
		throw new RuntimeException( evo_donations_translate( 'exception_invalid_json_payload' ), 0, $exception );
	}

	if (
		! is_array( $event ) ||
		! is_string( $event['id'] ?? null ) ||
		'' === trim( $event['id'] ) ||
		! is_string( $event['event_type'] ?? null ) ||
		'' === trim( $event['event_type'] )
	) {
		$webhook_status = 400;
		throw new RuntimeException( evo_donations_translate( 'exception_invalid_event' ) );
	}

	$payload = array(
		'webhook_id'    => $config['webhook_id'],
		'webhook_event' => $event,
	);

	$signature_headers = array(
		'auth_algo'         => 'AUTH_ALGO',
		'cert_url'          => 'CERT_URL',
		'transmission_id'   => 'TRANSMISSION_ID',
		'transmission_sig'  => 'TRANSMISSION_SIG',
		'transmission_time' => 'TRANSMISSION_TIME',
	);

	foreach ( $signature_headers as $key => $header ) {
		$value = $_SERVER[ 'HTTP_PAYPAL_' . $header ] ?? '';

		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > 2048 ) {
			$webhook_status = 400;
			throw new RuntimeException( evo_donations_translate( 'exception_missing_or_invalid_signature_header' ) );
		}

		$payload[ $key ] = $value;
	}

	$verified = evo_donations_call_paypal_api(
		$mode,
		'POST',
		'/v1/notifications/verify-webhook-signature',
		$payload
	);

	if ( 'SUCCESS' !== ( $verified['verification_status'] ?? '' ) ) {
		$webhook_status = 400;
		throw new RuntimeException( evo_donations_translate( 'exception_signature_rejected' ) );
	}

	$supported_events = array(
		'PAYMENT.CAPTURE.COMPLETED',
		'PAYMENT.CAPTURE.PENDING',
		'PAYMENT.CAPTURE.DENIED',
		'PAYMENT.CAPTURE.REFUNDED',
		'PAYMENT.CAPTURE.REVERSED',
	);

	$event_type = $event['event_type'];

	if ( in_array( $event_type, $supported_events, true ) ) {
		$resource = $event['resource'] ?? null;

		if ( ! is_array( $resource ) ) {
			$webhook_status = 400;
			throw new RuntimeException( evo_donations_translate( 'exception_invalid_event_resource' ) );
		}

		/**
		 * Find a donation by a validated PayPal order or capture identifier.
		 *
		 * The column allowlist prevents dynamic SQL identifiers from being
		 * supplied by the webhook. Values use the module's SQL quoting helper.
		 *
		 * @param string $column     Transaction identifier column.
		 * @param mixed  $identifier PayPal identifier from the verified event.
		 * @return array|null Matching transaction, or null when unavailable.
		 */
		$find_transaction = static function ( $column, $identifier ) use ( $mode ) {
			if (
				! in_array( $column, array( 'order_id', 'capture_id' ), true ) ||
				! is_string( $identifier ) ||
				! preg_match( '/\A[A-Z0-9]{1,36}\z/', $identifier )
			) {
				return null;
			}

			$row = evo_donations_fetch_row(
				'SELECT * FROM ' . evo_donations_get_table_name( 'transactions' )
				. ' WHERE environment=' . evo_donations_quote_sql_string( $mode )
				. ' AND ' . $column . '=' . evo_donations_quote_sql_string( $identifier )
			);

			return is_array( $row ) ? $row : null;
		};

		$order_id    = $resource['supplementary_data']['related_ids']['order_id'] ?? null;
		$capture_id  = $resource['supplementary_data']['related_ids']['capture_id'] ?? ( $resource['id'] ?? null );
		$is_refund   = 'PAYMENT.CAPTURE.REFUNDED' === $event_type;
		$transaction = $find_transaction( 'order_id', $order_id );

		if ( ! $transaction ) {
			$transaction = $find_transaction( 'capture_id', $capture_id );
		}

		if (
			! $transaction &&
			! $is_refund &&
			is_string( $capture_id ) &&
			preg_match( '/\A[A-Z0-9]{1,36}\z/', $capture_id )
		) {
			// Recover orders when the donor did not return after checkout.
			$capture = evo_donations_call_paypal_api(
				$mode,
				'GET',
				'/v2/payments/captures/' . $capture_id
			);

			$parent_order = $capture['supplementary_data']['related_ids']['order_id'] ?? null;
			$transaction  = $find_transaction( 'order_id', $parent_order );
		}

		if ( ! $transaction && $is_refund ) {
			$links = is_array( $resource['links'] ?? null ) ? $resource['links'] : array();

			// Extract a parent capture ID only; never request an event-supplied URL.
			foreach ( $links as $link ) {
				if (
					! is_array( $link ) ||
					'up' !== ( $link['rel'] ?? '' ) ||
					! is_string( $link['href'] ?? null ) ||
					! preg_match( '~/v2/payments/captures/([A-Z0-9]{1,36})\z~', $link['href'], $matches )
				) {
					continue;
				}

				$transaction = $find_transaction( 'capture_id', $matches[1] );
				break;
			}
		}

		if ( $transaction ) {
			$reversal = 'PAYMENT.CAPTURE.REVERSED' === $event_type ? ( $resource['id'] ?? '' ) : null;
			$status   = evo_donations_reconcile_payment( $transaction, $reversal );

			// Request redelivery if PayPal's API has not reflected the adverse event.
			if (
				in_array( $event_type, array( 'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.DENIED' ), true ) &&
				'COMPLETED' === $status
			) {
				throw new RuntimeException( evo_donations_translate( 'exception_paypal_state_has_not_caught_up_with_event' ) );
			}
		} elseif ( $order_id ) {
			throw new RuntimeException( evo_donations_translate( 'exception_order_not_available_yet' ) );
		}
	}

	$webhook_status = 204;
} catch ( Throwable $exception ) {
	// Keep internal exception details out of this public endpoint's response.
} finally {
	while ( ob_get_level() > $webhook_buffer_level ) {
		if ( ! ob_end_clean() ) {
			break;
		}
	}
}

http_response_code( $webhook_status );

if ( 405 === $webhook_status ) {
	header( 'Allow: POST' );
}

if ( 204 !== $webhook_status ) {
	header( 'Content-Type: text/plain; charset=UTF-8' );
	echo 'Webhook not processed.';
}

exit;

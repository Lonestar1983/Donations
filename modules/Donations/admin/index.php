<?php
/**
 * Manage donation installation, settings, and PayPal transaction reconciliation.
 *
 * Access is restricted to Donations administrators. The dashboard displays
 * configuration status, paginated transactions, and installation/uninstallation
 * screens. Existing helper functions perform installation and removal checks.
 */

defined( 'ADMIN_FILE' ) || exit;

if ( ! is_mod_admin( 'Donations' ) ) {
	http_response_code( 403 );
	exit( 'Access Denied' );
}

require_once __DIR__ . '/../includes/functions.php';
evo_donations_load_language();
evo_donations_ensure_session();

$request_method = $_SERVER['REQUEST_METHOD'] ?? '';
$post_action    = $_POST['action'] ?? '';
$post_action    = is_string( $post_action ) ? $post_action : '';

global $db, $admin_file;

// Validate every POST before dispatching installation, removal or settings actions.
if ( 'POST' === $request_method ) {
	try {
		$csrf_token = $_POST['csrf_token'] ?? null;

		if ( ! is_string( $csrf_token ) ) {
			throw new RuntimeException( evo_donations_translate( 'exception_invalid_form_token' ) );
		}

		evo_donations_validate_csrf_token();
	} catch ( Throwable $error ) {
		$error_page = new Evo_Error_Page( 403 );
		$error_page->render();
		return;
	}
}

$environments  = array( 'sandbox', 'live' );
$currencies    = array( 'GBP', 'USD', 'EUR', 'AUD', 'CAD' );
$amount_fields = array(
	'minimum'     => 'min_cents',
	'maximum'     => 'max_cents',
	'goal_amount' => 'goal_cents',
);

$uninstall_failed = false;

// Complete removal before loading dashboard assets or querying module data.
if ( $request_method === 'POST' && $post_action === 'uninstall' ) {
	require_once __DIR__ . '/../includes/uninstall.php';

	try {
		$uninstall_result = evo_donations_uninstall();
	} catch ( Throwable $error ) {
		$uninstall_failed = true;
	}

	if ( ! $uninstall_failed ) {
		get_header();
		OpenTable();
		echo '<h2>' . evo_donations_escape( evo_donations_translate( 'uninstall_complete' ) ) . '</h2>';
		echo '<p>' . evo_donations_escape( evo_donations_translate( 'uninstall_done' ) ) . '</p>';
		if ( $uninstall_result ) {
			echo '<p role="alert">' .
				evo_donations_escape( evo_donations_translate( 'uninstall_cleanup' ) ) .
				'</p><ul>';
			foreach ( $uninstall_result as $path ) {
				echo '<li>' . evo_donations_escape( $path ) . '</li>';
			}
			echo '</ul>';
		}
		echo '<p><a href="' .
			escape_url( $admin_file . '.php' ) .
			'">' .
			evo_donations_escape( evo_donations_translate( 'admin_home' ) ) .
			'</a></p>';
		CloseTable();
		get_footer();
		return;
	}
}

// Process actions before rendering the dashboard.
$notice = ! empty( $uninstall_failed ) ? evo_donations_translate( 'uninstall_failed' ) : '';
$installed = false;
$install_error = false;
/** @var array{ready: bool, incompatible: array} $install_state Installation readiness and schema issues. */
$install_state = array(
	'ready'        => false,
	'incompatible' => array(),
);
try {
	$install_state = evo_donations_get_installation_state();
	if ( $request_method === 'POST' && $post_action === 'install' ) {
		evo_donations_install();
		$install_state = evo_donations_get_installation_state( true );
		if ( empty( $install_state['ready'] ) ) {
			throw new RuntimeException( evo_donations_translate( 'exception_installation_is_not_ready' ) );
		}
		$notice = evo_donations_translate( 'install_success' );
	}
	$installed = ! empty( $install_state['ready'] );
	if ( $installed ) {
		// Keep $s available to the existing included administration screens.
		$s = evo_donations_get_settings();
		if ( $request_method === 'POST' ) {
			if ( $post_action === 'sync' ) {
				$donation_id = $_POST['donation'] ?? '';
				if ( ! is_string( $donation_id ) ) {
					throw new RuntimeException( evo_donations_translate( 'exception_invalid_donation_identifier' ) );
				}
				$donation = evo_donations_get_donation( $donation_id );

				if ( ! is_array( $donation ) || empty( $donation['id'] ) ) {
					throw new RuntimeException( evo_donations_translate( 'exception_invalid_donation_identifier' ) );
				}

				evo_donations_reconcile_payment( $donation );
				$notice = evo_donations_translate( 'sync_done' );
			} elseif ( $post_action === 'save' ) {
				$mode = $_POST['environment'] ?? '';
				$currency = $_POST['currency'] ?? '';
				if (
					! in_array( $mode, $environments, true ) ||
					! in_array( $currency, $currencies, true )
				) {
					throw new RuntimeException( evo_donations_translate( 'exception_invalid_currency_environment' ) );
				}
				// Reject array input before passing amounts to the conversion helper.
				foreach ( array_keys( $amount_fields ) as $amount_field ) {
					if ( ! is_string( $_POST[$amount_field] ?? '' ) ) {
						throw new RuntimeException( evo_donations_translate( 'exception_invalid_donation_amount' ) );
					}
				}
				$min = evo_donations_amount_to_cents( $_POST['minimum'] ?? '' );
				$max = evo_donations_amount_to_cents( $_POST['maximum'] ?? '' );
				$goal = evo_donations_amount_to_cents( $_POST['goal_amount'] ?? '' );
				if ( $min < 100 || $max < $min || $max > 100000000 ) {
					throw new RuntimeException(
						evo_donations_translate( 'exception_use_a_minimum_of_1_00_and_a_maximum_no_greater_than_1000000_00' ),
					);
				}
				$date = $_POST['goal_start'] ?? '';
				$dt = is_string( $date )
					? DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new DateTimeZone( 'UTC' ) )
					: false;
				if ( ! $dt || $dt->format( 'Y-m-d' ) !== $date ) {
					throw new RuntimeException( evo_donations_translate( 'exception_invalid_date' ) );
				}
				if ( isset( $_POST['enabled'] ) ) {
					evo_donations_get_paypal_config( $mode );
					evo_donations_get_site_url();
				}
				evo_donations_save_settings( array(
					'enabled'      => isset( $_POST['enabled'] ) ? 1 : 0,
					'allow_guests' => isset( $_POST['guests'] ) ? 1 : 0,
					'environment'  => $mode,
					'currency'     => $currency,
					'min_cents'    => $min,
					'max_cents'    => $max,
					'goal_cents'   => $goal,
					'goal_start'   => $date,
				) );
				$s = evo_donations_get_settings();
				$notice = evo_donations_translate( 'saved' );
			}
		}
	}
} catch ( Throwable $e ) {
	if ( ! $installed ) {
		$install_error = true;
		$notice = evo_donations_translate( 'install_failed' );
	} else {
		$notice = $e->getMessage();
	}
}
evo_include_style( 'evo-donations-admin', 'modules/Donations/assets/admin.css', EVO_DONATIONS_VERSION );
evo_include_script( 'evo-donations-admin', 'modules/Donations/assets/admin.js', EVO_DONATIONS_VERSION, true );
get_header();
OpenTable();
echo '<section class="ed-admin"><header class="ed-admin-header"><div><h2>' .
	evo_donations_escape( evo_donations_translate( 'admin' ) ) .
	'</h2></div><a class="ed-admin-secondary" href="modules.php?name=Donations">' .
	evo_donations_escape( evo_donations_translate( 'view_module' ) ) .
	'</a></header>';
if ( $notice ) {
	echo '<p role="status" class="ed-admin-notice">' . evo_donations_escape( $notice ) . '</p>';
}
if ( ! $installed ) {
	require __DIR__ . '/../includes/install-screen.php';
} elseif ( ! isset( $s ) ) {
	echo '<p>' . evo_donations_escape( evo_donations_translate( 'disabled' ) ) . '</p>';
} else {
	echo '<div class="ed-admin-grid"><section class="ed-admin-panel ed-admin-config"><h3 class="catHead">' .
		evo_donations_escape( evo_donations_translate( 'configuration' ) ) .
		'</h3><div class="ed-admin-panel-body row1">';
	foreach ( $environments as $env ) {
		try {
			evo_donations_get_paypal_config( $env );
			$state = 'configured';
		} catch ( Throwable $e ) {
			$state = 'not_configured';
		}
		echo '<article class="ed-admin-environment"><div class="ed-admin-environment-heading"><strong>' .
			evo_donations_escape( ucfirst( $env ) ) .
			'</strong><span class="ed-admin-badge ' .
			( $state === 'configured' ? 'is-ready' : 'is-missing' ) .
			'">' .
			evo_donations_escape( evo_donations_translate( $state ) ) .
			'</span></div>';
		try {
			echo '<p>' .
				evo_donations_escape( evo_donations_translate( 'webhook' ) ) .
				'</p><code>' .
				evo_donations_escape(
					evo_donations_get_site_url() . '/modules/Donations/webhook.php?mode=' . $env,
				) .
				'</code>';
		} catch ( Throwable $e ) {
			echo '<p>' . evo_donations_escape( $e->getMessage() ) . '</p>';
		}
		echo '</article>';
	}
	echo '</div></section>';
	$url = $admin_file . '.php?op=evo_donations';
	echo '<section class="ed-admin-panel"><h3 class="catHead">' .
		evo_donations_escape( evo_donations_translate( 'settings' ) ) .
		'</h3><form class="ed-admin-form row1" action="' .
		evo_donations_escape( $url ) .
		'" method="post">' .
		evo_donations_csrf_field() .
		'<input type="hidden" name="action" value="save">';
	foreach ( array(
		'enabled' => 'enabled',
		'guests'  => 'allow_guests',
	) as $label => $key ) {
		echo '<label class="ed-admin-check"><input type="checkbox" name="' .
			$label .
			'" value="1"' .
			( $s[$key] ? ' checked' : '' ) .
			'> ' .
			evo_donations_escape( evo_donations_translate( $label ) ) .
			'</label>';
	}
	foreach (
		array(
			'environment' => $environments,
			'currency'    => $currencies,
		)
		as $key => $options
	) {
		echo '<label>' . evo_donations_escape( evo_donations_translate( $key ) ) . '<select name="' . $key . '">';
		foreach ( $options as $opt ) {
			echo '<option value="' .
				$opt .
				'"' .
				( $s[$key] === $opt ? ' selected' : '' ) .
				'>' .
				evo_donations_escape( $opt ) .
				'</option>';
		}
		echo '</select></label>';
	}
	foreach ( $amount_fields as $label => $key ) {
		echo '<label>' .
			evo_donations_escape( evo_donations_translate( $label ) ) .
			'<input type="number" step="0.01" min="0" name="' .
			$label .
			'" value="' .
			evo_donations_escape( evo_donations_format_amount( $s[$key] ) ) .
			'" required></label>';
	}
	echo '<label>' .
		evo_donations_escape( evo_donations_translate( 'goal_start' ) ) .
		'<input type="date" name="goal_start" value="' .
		evo_donations_escape( $s['goal_start'] ) .
		'" required></label><div class="ed-admin-actions"><button type="submit">' .
		evo_donations_escape( evo_donations_translate( 'save' ) ) .
		'</button></div></form></section></div>';
	echo '<section class="ed-admin-panel ed-admin-transactions"><h3 class="catHead">' .
		evo_donations_escape( evo_donations_translate( 'transactions' ) ) .
		'</h3><div class="ed-admin-panel-body row1"><p class="ed-admin-help">' .
		evo_donations_escape( evo_donations_translate( 'review' ) ) .
		'</p>';
	// Fetch one extra transaction to determine whether a next page exists.
	$requested_page = $_GET['page'] ?? 0;
	$page           = is_scalar( $requested_page ) ? max( 0, min( 100000, (int) $requested_page ) ) : 0;
	$per_page       = 10;
	$offset         = $page * $per_page;
	$rs = evo_donations_run_query(
		'SELECT * FROM ' .
			evo_donations_get_table_name( 'transactions' ) .
			' ORDER BY created_at DESC,id DESC LIMIT ' .
			$offset .
			',' .
			( $per_page + 1 ),
	);
	$rows = array();
	while ( $r = $db->sql_fetchrow( $rs, MYSQLI_ASSOC ) ) {
		$rows[] = $r;
	}
	$db->sql_freeresult( $rs );
	$more = count( $rows ) > $per_page;
	foreach ( array_slice( $rows, 0, $per_page ) as $r ) {
		echo '<details class="ed-admin-transaction row1"><summary class="ed-admin-transaction-summary">';
		echo '<span class="ed-admin-summary-name">' . evo_donations_escape( $r['display_name'] ) . '</span>';
		echo '<strong>' .
			evo_donations_escape( evo_donations_format_amount( $r['amount_cents'] ) . ' ' . $r['currency'] ) .
			'</strong>';
		echo '<span class="ed-admin-badge">' . evo_donations_escape( $r['status'] ) . '</span>';
		echo '<span class="ed-admin-summary-date">' . evo_donations_escape( $r['created_at'] ) . '</span>';
		echo '</summary><div class="ed-admin-transaction-body"><dl>';
		foreach (
			array(
				'id'        => $r['id'] . ' / ' . ( $r['order_id'] ?: '—' ) . ' / ' . ( $r['capture_id'] ?: '—' ),
				'supporter' => $r['display_name'] . ' (#' . $r['user_id'] . ')',
				'amount'    => evo_donations_format_amount( $r['amount_cents'] ) . ' ' . $r['currency'],
				'status'    => $r['status'],
				'mode'      => $r['environment'],
				'date'      => $r['created_at'],
			)
			as $key => $value
		) {
			echo '<div><dt>' .
				evo_donations_escape( evo_donations_translate( $key ) ) .
				'</dt><dd>' .
				evo_donations_escape( $value ) .
				'</dd></div>';
		}
		echo '</dl>';
		if ( $r['order_id'] ) {
			echo '<form action="' .
				evo_donations_escape( $url . '&page=' . $page ) .
				'" method="post">' .
				evo_donations_csrf_field() .
				'<input type="hidden" name="action" value="sync"><input type="hidden" name="donation" value="' .
				evo_donations_escape( $r['id'] ) .
				'"><button type="submit">' .
				evo_donations_escape( evo_donations_translate( 'sync' ) ) .
				'</button></form>';
		}
		echo '</div></details>';
	}
	if ( ! $rows ) {
		echo '<p class="ed-admin-empty">' .
			evo_donations_escape( evo_donations_translate( 'no_transactions' ) ) .
			'</p>';
	}
	echo '<nav class="ed-admin-pages">';
	if ( $page > 0 ) {
		echo '<a href="' .
			evo_donations_escape( $url . '&page=' . ( $page - 1 ) ) .
			'">' .
			evo_donations_escape( evo_donations_translate( 'previous' ) ) .
			'</a> ';
	}
	if ( $more ) {
		echo '<a href="' .
			evo_donations_escape( $url . '&page=' . ( $page + 1 ) ) .
			'">' .
			evo_donations_escape( evo_donations_translate( 'next' ) ) .
			'</a>';
	}
	echo '</nav></div></section>';
	require __DIR__ . '/../includes/uninstall-screen.php';
}
echo '</section>';
CloseTable();
get_footer();

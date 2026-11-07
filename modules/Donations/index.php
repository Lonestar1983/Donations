<?php

/**
 * Display the public Donations page and PayPal checkout dialog.
 *
 * Loads the module language and assets, displays donation progress, and offers
 * checkout when donations are enabled and the visitor is eligible. The checkout
 * endpoint remains responsible for validating submitted amounts and requests.
 */

defined( 'NUKE_EVO' ) || exit;

require_once __DIR__ . '/includes/functions.php';

evo_donations_load_language();

evo_include_style(
	'evo-donations',
	'modules/Donations/assets/donations.css',
	EVO_DONATIONS_VERSION
);

evo_include_script(
	'evo-donation-checkout',
	'modules/Donations/assets/checkout.js',
	EVO_DONATIONS_VERSION,
	true
);

// Prepare the session before the header sends any page output.
evo_donations_ensure_session();

require_once NUKE_BASE_DIR . 'header.php';

OpenTable();
?>
<section class="ed-donations">
	<h2><?php echo evo_donations_escape( evo_donations_translate( 'title' ) ); ?></h2>
	<p><?php echo evo_donations_escape( evo_donations_translate( 'intro' ) ); ?></p>
	<?php
	try {
		/**
		 * Render donation availability, progress, and the checkout interface.
		 *
		 * Early returns keep unavailable checkout states out of the form markup.
		 * The closure also keeps page-specific variables out of the CMS scope.
		 *
		 * @return void
		 */
		( static function () {
			$installation = evo_donations_get_installation_state();

			if ( empty( $installation['ready'] ) ) {
				echo '<p role="status">'
					. evo_donations_escape( evo_donations_translate( 'install_not_configured' ) )
					. '</p>';
				return;
			}

			$settings = evo_donations_get_settings();

			if ( 'sandbox' === ( $settings['environment'] ?? '' ) ) {
				echo '<p class="ed-notice">'
					. evo_donations_escape( evo_donations_translate( 'sandbox' ) )
					. '</p>';
			}

			// The summary helper returns rendered HTML rather than plain text.
			echo evo_donations_render_summary( $settings );

			if ( empty( $settings['enabled'] ) ) {
				echo '<p>' . evo_donations_escape( evo_donations_translate( 'disabled' ) ) . '</p>';
				return;
			}

			if ( empty( $settings['allow_guests'] ) && ! is_user() ) {
				echo '<p>' . evo_donations_escape( evo_donations_translate( 'login' ) ) . '</p>';
				return;
			}

			$paypal_config = evo_donations_get_paypal_config( $settings['environment'] );
			$currency      = (string) $settings['currency'];
			$minimum_cents = (int) $settings['min_cents'];
			$maximum_cents = (int) $settings['max_cents'];

			if ( $minimum_cents < 1 || $maximum_cents < $minimum_cents ) {
				throw new RuntimeException( evo_donations_translate( 'exception_invalid_donation_amount_limits' ) );
			}

			// Prefer 5.00, while keeping the suggested amount within both limits.
			$default_cents  = min( $maximum_cents, max( 500, $minimum_cents ) );
			$minimum_amount = evo_donations_format_amount( $minimum_cents );
			$maximum_amount = evo_donations_format_amount( $maximum_cents );
			$default_amount = evo_donations_format_amount( $default_cents );
			?>
			<form
				class="ed-form"
				data-ed-checkout="1"
				data-client="<?php echo evo_donations_escape( $paypal_config['client_id'] ); ?>"
				data-currency="<?php echo evo_donations_escape( $currency ); ?>"
				method="post"
				action="<?php echo evo_donations_escape( evo_donations_get_checkout_url() ); ?>"
			>
				<?php echo evo_donations_csrf_field(); ?>
				<input type="hidden" name="action" value="create">

				<label>
					<?php echo evo_donations_escape( evo_donations_translate( 'amount' ) . ' (' . $currency . ')' ); ?>
					<input
						type="number"
						name="amount"
						step="0.01"
						min="<?php echo evo_donations_escape( $minimum_amount ); ?>"
						max="<?php echo evo_donations_escape( $maximum_amount ); ?>"
						value="<?php echo evo_donations_escape( $default_amount ); ?>"
						required
						inputmode="decimal"
					>
				</label>

				<p><?php echo evo_donations_escape( evo_donations_translate( 'limits' ) . ': ' . $minimum_amount . '–' . $maximum_amount . ' ' . $currency ); ?></p>

				<label class="ed-check">
					<input type="checkbox" name="anonymous" value="1" checked>
					<?php echo evo_donations_escape( evo_donations_translate( 'anonymous_label' ) ); ?>
				</label>

				<p><?php echo evo_donations_escape( evo_donations_translate( 'privacy' ) ); ?></p>
				<button type="submit"><?php echo evo_donations_escape( evo_donations_translate( 'donate' ) ); ?></button>
			</form>

			<dialog
				class="ed-payment-modal"
				aria-labelledby="ed-payment-title"
				data-loading="<?php echo evo_donations_escape( evo_donations_translate( 'loading' ) ); ?>"
				data-error="<?php echo evo_donations_escape( evo_donations_translate( 'checkout_error' ) ); ?>"
				data-cancel="<?php echo evo_donations_escape( evo_donations_translate( 'checkout_cancelled' ) ); ?>"
			>
				<div class="ed-payment-heading">
					<h2 id="ed-payment-title"><?php echo evo_donations_escape( evo_donations_translate( 'confirm' ) ); ?></h2>
					<button
						type="button"
						class="ed-payment-close"
						aria-label="<?php echo evo_donations_escape( evo_donations_translate( 'close' ) ); ?>"
					>×</button>
				</div>
				<p class="ed-payment-amount"></p>
				<p class="ed-payment-status" role="status" aria-live="polite"></p>
				<div class="ed-paypal-buttons"></div>
				<a class="ed-payment-refresh" href="modules.php?name=Donations" hidden><?php echo evo_donations_escape( evo_donations_translate( 'back' ) ); ?></a>
			</dialog>
			<?php
		} )();
	} catch ( Throwable $exception ) {
		echo '<p role="alert">'
			. evo_donations_escape( evo_donations_translate( 'disabled' ) )
			. '</p>';
	}
	?>
</section>
<?php
CloseTable();

require_once NUKE_BASE_DIR . 'footer.php';

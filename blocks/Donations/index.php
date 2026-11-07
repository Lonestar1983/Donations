<?php
/**
 * Render the donation goal and supporters block.
 *
 * Loads the block's translations with an English fallback and restores the
 * previous donation language data after rendering.
 *
 * Add this block through the Evo Blocks administrator.
 */

defined( 'BLOCK_FILE' ) || exit;

// Version the companion block independently of the module.
if ( ! defined( 'EVO_DONATIONS_BLOCK_VERSION' ) ) {
	define( 'EVO_DONATIONS_BLOCK_VERSION', '1.0.0' );
}

require_once NUKE_MODULES_DIR . 'Donations/includes/functions.php';

evo_include_style(
	'evo-donations-block',
	'blocks/Donations/assets/donations.css',
	EVO_DONATIONS_BLOCK_VERSION
);

echo ( static function () {
	global $currentlang, $ed_lang;

	$previous_language = $ed_lang ?? null;
	$html              = '<section class="evo-donation-widget">';

	try {
		// Load block translations independently of the module's language data.
		$ed_lang = array();

		require __DIR__ . '/language/lang-english.php';

		$defaults = is_array( $ed_lang ) ? $ed_lang : array();
		$language = is_string( $currentlang ) ? $currentlang : 'english';

		if (
			'english' !== $language &&
			preg_match( '/\A[a-zA-Z0-9_-]+\z/', $language )
		) {
			$language_file = __DIR__ . '/language/lang-' . $language . '.php';

			if ( is_file( $language_file ) ) {
				require $language_file;
			}
		}

		$ed_lang = array_replace(
			$defaults,
			is_array( $ed_lang ) ? $ed_lang : array()
		);

		$installation = evo_donations_get_installation_state();

		if ( empty( $installation['ready'] ) ) {
			return $html
				. '<p role="status">'
				. evo_donations_escape(
					evo_donations_translate( 'install_not_configured' )
				)
				. '</p></section>';
		}

		$settings = evo_donations_get_settings();

		if ( 'sandbox' === ( $settings['environment'] ?? '' ) ) {
			$html .= '<p>'
				. evo_donations_escape(
					evo_donations_translate( 'sandbox' )
				)
				. '</p>';
		}

		$html .= str_replace(
			'class="ed-summary"',
			'class="evo-donation-widget-summary"',
			evo_donations_render_summary( $settings, 'widget' )
		);

		if ( ! empty( $settings['enabled'] ) ) {
			$html .= '<a class="evo-donation-widget-button" href="modules.php?name=Donations">'
				. evo_donations_escape(
					evo_donations_translate( 'title' )
				)
				. '</a>';
		} else {
			$html .= '<p>'
				. evo_donations_escape(
					evo_donations_translate( 'disabled' )
				)
				. '</p>';
		}
	} catch ( Throwable $exception ) {
		error_log(
			'[Donations block] ' . $exception->getMessage()
		);

		$html .= '<p role="status">'
			. evo_donations_escape(
				evo_donations_translate( 'disabled' )
			)
			. '</p>';
	} finally {
		$ed_lang = $previous_language;
	}

	return $html . '</section>';
} )();
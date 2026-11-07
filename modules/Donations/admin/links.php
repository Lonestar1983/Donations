<?php
/**
 * Register the Donations administration menu link.
 */

defined( 'ADMIN_FILE' ) || exit;

global $admin_file;

if ( ! is_mod_admin( 'Donations' ) ) {
	return;
}

require_once NUKE_MODULES_DIR . 'Donations/includes/functions.php';

evo_donations_load_language();

adminmenu(
	$admin_file . '.php?op=evo_donations',
	evo_donations_translate( 'admin' ),
	'donations.webp'
);
<?php
/**
 * Render the Donations uninstall confirmation.
 *
 * Included only by the authorized Donations admin dashboard.
 */

defined( 'ADMIN_FILE' ) || exit;

if ( ! is_mod_admin( 'Donations' ) ) {
    exit;
}

echo '<section class="ed-admin-panel ed-admin-uninstall">';
echo '<h3 class="catHead">' . evo_donations_escape( evo_donations_translate( 'uninstall_title' ) ) . '</h3>';
echo '<div class="ed-admin-panel-body row1">';

echo '<p>' . evo_donations_escape( evo_donations_translate( 'uninstall_intro' ) ) . '</p>';

echo '<details>';
echo '<summary>' . evo_donations_escape( evo_donations_translate( 'uninstall_title' ) ) . '</summary>';
echo '<p class="ed-admin-notice" role="alert">' . evo_donations_escape( evo_donations_translate( 'uninstall_warning' ) ) . '</p>';

echo '<form method="post" action="' . escape_url( $admin_file . '.php?op=evo_donations' ) . '">';
echo evo_donations_csrf_field();
echo '<input type="hidden" name="action" value="uninstall">';

echo '<label class="ed-admin-uninstall-confirm">';
echo '<input type="checkbox" name="confirm_uninstall" value="1" required>';
echo ' ' . evo_donations_escape( evo_donations_translate( 'uninstall_confirm' ) );
echo '</label>';

echo '<button class="ed-admin-danger" type="submit">' . evo_donations_escape( evo_donations_translate( 'uninstall_button' ) ) . '</button>';
echo '</form>';

echo '</details>';
echo '</div>';
echo '</section>';

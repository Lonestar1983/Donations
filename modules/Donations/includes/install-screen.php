<?php
/**
 * Render the Donations installation screen.
 *
 * Included only by the authorized Donations admin controller.
 */

defined( 'ADMIN_FILE' ) || exit;

if ( ! is_mod_admin( 'Donations' ) ) {
    exit;
}

echo '<section class="ed-admin-panel">';
echo '<h3 class="catHead">' . evo_donations_escape( evo_donations_translate( 'install_title' ) ) . '</h3>';
echo '<div class="ed-admin-panel-body">';

echo '<p>' . evo_donations_escape( evo_donations_translate( 'install_description' ) ) . '</p>';

if ( ! empty( $install_state['incompatible'] ) ) {
    echo '<p role="alert">' . evo_donations_escape( evo_donations_translate( 'install_incompatible' ) ) . '</p>';
} else {
    echo '<p>' . evo_donations_escape( evo_donations_translate( 'install_defaults' ) ) . '</p>';

    echo '<form method="post" action="' . escape_url( $admin_file . '.php?op=evo_donations' ) . '">';
    echo evo_donations_csrf_field();
    echo '<input type="hidden" name="action" value="install">';
    echo '<button type="submit">' . evo_donations_escape( evo_donations_translate( 'install_button' ) ) . '</button>';
    echo '</form>';
}

echo '</div>';
echo '</section>';

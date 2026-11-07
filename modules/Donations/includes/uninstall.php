<?php
/** Permanent donation data and file removal.
 * @package EvoDonations
 * @version 1.0.0
 */

defined( "NUKE_EVO" ) || exit;

/**
 * Collect exact module-owned files for removal without following symbolic links.
 *
 * Paths are supplied by the uninstaller, never from submitted request values.
 * Every parent directory must be writable before database removal starts.
 *
 * @param string $root Absolute directory owned by Donations.
 * @return string[] Files and directories in child-first removal order.
 * @throws RuntimeException If the tree cannot be read or removed by this PHP process.
 */
function evo_donations_get_removal_paths( $root ) {
    if ( is_link( $root ) ) { throw new RuntimeException( evo_donations_translate( 'exception_linked_module_directory_requires_manual_removal' ) ); }
    if ( ! file_exists( $root ) ) { return array(); }
    if ( ! is_dir( $root ) || ! is_readable( $root ) || ! is_writable( dirname( $root ) ) ) {
        throw new RuntimeException( evo_donations_translate( 'exception_donation_files_are_not_removable' ) );
    }
    $paths = array();
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $iterator as $entry ) {
        $path = $entry->getPathname();
        if ( ! is_writable( dirname( $path ) ) ) { throw new RuntimeException( evo_donations_translate( 'exception_donation_files_are_not_removable' ) ); }
        $paths[] = $path;
    }
    $paths[] = $root;
    return $paths;
}

/**
 * Permanently uninstall donation data and the two fixed, module-owned folders.
 *
 * Requires authorized module administration, POST, a valid CSRF token and an
 * explicit checkbox value. CMS core files and external config files are untouched.
 * File permissions are checked first. Database and filesystem operations are not
 * atomic; any files left by a cleanup failure are returned for manual removal.
 * Symlinks inside the folders are unlinked without touching their targets.
 *
 * @global string $prefix CMS database prefix.
 * @global array $evoconfig Loaded Evolution options.
 * @global object $cache CMS cache service.
 * @return string[] Remaining relative paths, empty when all files were removed.
 * @throws RuntimeException If confirmation, permissions or database operations fail.
 */
function evo_donations_uninstall() {
    global $prefix, $evoconfig, $cache;
    if ( ! defined( "ADMIN_FILE" ) || ! is_mod_admin( "Donations" ) ) {
        throw new RuntimeException( evo_donations_translate( 'exception_access_denied' ) );
    }
    if ( ( $_SERVER["REQUEST_METHOD"] ?? "" ) !== "POST" ) { throw new RuntimeException( evo_donations_translate( 'exception_post_required' ) ); }
    evo_donations_validate_csrf_token();
    if ( ( $_POST["confirm_uninstall"] ?? "" ) !== "1" ) { throw new RuntimeException( evo_donations_translate( 'exception_uninstall_confirmation_required' ) ); }

    $base = realpath( NUKE_BASE_DIR );
    $module = realpath( dirname( __DIR__ ) );
    $module_parent = realpath( NUKE_MODULES_DIR );
    if ( ! $base || ! $module || ! $module_parent ||
        $module !== $module_parent . DIRECTORY_SEPARATOR . "Donations" ||
        is_link( rtrim( NUKE_MODULES_DIR, "/\\" ) . DIRECTORY_SEPARATOR . "Donations" ) ||
        is_link( $base . DIRECTORY_SEPARATOR . "blocks" ) ) {
        throw new RuntimeException( evo_donations_translate( 'exception_unexpected_donation_installation_path' ) );
    }
    $block = $base . DIRECTORY_SEPARATOR . "blocks" . DIRECTORY_SEPARATOR . "Donations";
    $paths = array_merge( evo_donations_get_removal_paths( $block ), evo_donations_get_removal_paths( $module ) );
    // Include only the known legacy entry point and supplied admin icon, if present.
    foreach ( array( $base . "/blocks/block-Donations.php", $base . "/images/admin/donations.png" ) as $extra ) {
        if ( is_file( $extra ) || is_link( $extra ) ) {
            if ( ! is_writable( dirname( $extra ) ) ) { throw new RuntimeException( evo_donations_translate( 'exception_donation_files_are_not_removable' ) ); }
            // An ancestor symlink could point outside the site; leave that file alone.
            if ( realpath( dirname( $extra ) ) === dirname( $extra ) ) { array_unshift( $paths, $extra ); }
        }
    }

    // Prevent new checkouts before removing history. No PayPal refund API is called.
    $settings = evo_donations_get_settings();
    $settings["enabled"] = 0;
    evo_donations_save_settings( $settings );
    evo_donations_run_query( "DROP TABLE IF EXISTS " . evo_donations_get_table_name( "transactions" ) );
    evo_donations_run_query( "DROP TABLE IF EXISTS " . evo_donations_get_table_name( "settings" ) );
    evo_donations_run_query( "DELETE FROM " . evo_donations_get_options_table_name() . " WHERE evo_field = 'donations_settings'" );
    // The prefix was validated by get_table_name() above; these are fixed core tables.
    $modules_table = "`" . $prefix . "_modules`";
    $blocks_table = "`" . $prefix . "_blocks`";
    evo_donations_run_query( "DELETE FROM " . $blocks_table . " WHERE blockfile IN ('block-Donations.php', 'Donations/index.php')" );
    evo_donations_run_query( "DELETE FROM " . $modules_table . " WHERE title = 'Donations'" );
    unset( $evoconfig["donations_settings"], $_SESSION["ed_orders"], $_SESSION["ed_last_create"] );
    if ( is_object( $cache ) && is_callable( array( $cache, "delete" ) ) ) {
        foreach ( array( "evoconfig", "blocks", "modules", "active_modules", "inactive_modules" ) as $key ) {
            $cache->delete( $key, "config" );
        }
    }

    $remaining = array();
    foreach ( $paths as $path ) {
        if ( is_link( $path ) || is_file( $path ) ) { $removed = @unlink( $path ); }
        elseif ( is_dir( $path ) ) { $removed = @rmdir( $path ); }
        else { continue; }
        if ( ! $removed ) {
            $relative = strpos( $path, $base . DIRECTORY_SEPARATOR ) === 0 ? substr( $path, strlen( $base ) + 1 ) : "modules/Donations";
            $remaining[] = $relative;
        }
    }
    return array_values( array_unique( $remaining ) );
}

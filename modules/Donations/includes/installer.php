<?php
/** Donation schema installation and serialized Evo option storage. @version 1.0.0 */
defined( "NUKE_EVO" ) || exit;

/**
 * Return the configured Evolution options table as a quoted identifier.
 * @global string $prefix CMS database prefix.
 * @return string Quoted core options table name.
 * @throws RuntimeException If the identifier contains unsupported characters.
 */
function evo_donations_get_options_table_name() {
    global $prefix;
    $name = defined( "_EVOCONFIG_TABLE" ) ? constant( "_EVOCONFIG_TABLE" ) : $prefix . "_evolution";
    $name = trim( $name, "`" );
    if ( ! preg_match( '/^[a-zA-Z0-9_]+$/D', $name ) ) {
        throw new RuntimeException( evo_donations_translate( 'exception_invalid_evolution_options_table' ) );
    }
    return function_exists( "db_quote_identifier" ) ? db_quote_identifier( $name ) : "`" . $name . "`";
}

/**
 * Return disabled sandbox defaults for a new installation.
 * @return array Donation preferences; API credentials remain in config.php.
 */
function evo_donations_get_default_settings() {
    return array(
        "enabled" => 0,
        "environment" => "sandbox",
        "currency" => "GBP",
        "allow_guests" => 0,
        "min_cents" => 100,
        "max_cents" => 100000,
        "goal_cents" => 10000,
        "goal_start" => gmdate( "Y-m-d" ),
    );
}

/**
 * Validate settings, retaining only recognized preferences.
 * @param array $settings Submitted, stored or migrated donation preferences.
 * @return array Normalized preferences suitable for serialization.
 * @throws RuntimeException If a required preference is missing or invalid.
 */
function evo_donations_validate_settings( array $settings ) {
    $defaults = evo_donations_get_default_settings();
    if ( array_diff_key( $defaults, $settings ) ) {
        throw new RuntimeException( evo_donations_translate( 'exception_donation_settings_are_incomplete' ) );
    }
    $settings = array_intersect_key( $settings, $defaults );
    if ( ! in_array( $settings["environment"], array( "sandbox", "live" ), true ) ||
        ! in_array( $settings["currency"], array( "GBP", "USD", "EUR", "AUD", "CAD" ), true ) ) {
        throw new RuntimeException( evo_donations_translate( 'exception_invalid_donation_settings' ) );
    }
    foreach ( array( "enabled", "allow_guests", "min_cents", "max_cents", "goal_cents" ) as $key ) {
        $value = $settings[ $key ];
        if ( ! is_scalar( $value ) || ! preg_match( '/^[0-9]{1,10}$/D', (string) $value ) ) {
            throw new RuntimeException( evo_donations_translate( 'exception_invalid_donation_settings' ) );
        }
        $settings[ $key ] = (int) $value;
    }
    $date = is_string( $settings["goal_start"] ) ? DateTimeImmutable::createFromFormat( "!Y-m-d", $settings["goal_start"] ) : false;
    if ( ! $date || $date->format( "Y-m-d" ) !== $settings["goal_start"] ||
        $settings["enabled"] > 1 || $settings["allow_guests"] > 1 ||
        $settings["min_cents"] < 100 || $settings["max_cents"] < $settings["min_cents"] ||
        $settings["max_cents"] > 100000000 || $settings["goal_cents"] > 999999999 ) {
        throw new RuntimeException( evo_donations_translate( 'exception_invalid_donation_settings' ) );
    }
    return $settings;
}

/**
 * Store all preferences in one serialized Evolution option and refresh its cache.
 * @global array $evoconfig Request-local Evolution configuration.
 * @global object $cache CMS cache service.
 * @param array $settings Donation preferences to save.
 * @param bool $insert_only Preserve an option already present during installation.
 * @return void
 * @throws RuntimeException If validation or the database write fails.
 */
function evo_donations_save_settings( array $settings, $insert_only = false ) {
    global $evoconfig, $cache;
    $settings = evo_donations_validate_settings( $settings );
    $serialized = serialize( $settings );
    $sql = "INSERT " . ( $insert_only ? "IGNORE " : "" ) . "INTO " . evo_donations_get_options_table_name() .
        " (evo_field, evo_value) VALUES ('donations_settings', " . evo_donations_quote_sql_string( $serialized ) . ")";
    if ( ! $insert_only ) {
        $sql .= " ON DUPLICATE KEY UPDATE evo_value = " . evo_donations_quote_sql_string( $serialized );
    }
    evo_donations_run_query( $sql );
    // Read the winning value if another install request inserted the option first.
    if ( $insert_only ) {
        $row = evo_donations_fetch_row( "SELECT evo_value FROM " . evo_donations_get_options_table_name() . " WHERE evo_field = 'donations_settings'" );
        if ( ! $row ) { throw new RuntimeException( evo_donations_translate( 'exception_donation_settings_are_missing' ) ); }
        $serialized = $row["evo_value"];
    }
    if ( ! is_array( $evoconfig ) ) { $evoconfig = array(); }
    $evoconfig["donations_settings"] = $serialized;
    if ( is_object( $cache ) && is_callable( array( $cache, "delete" ) ) ) {
        $cache->delete( "evoconfig", "config" );
    }
}

/**
 * Inspect tables and the serialized option without querying missing tables.
 * @param bool $refresh Discard the request-local installation-state cache.
 * @return array Ready flag, missing/incompatible tables and legacy settings availability.
 * @throws RuntimeException If metadata or option reads fail.
 */
function evo_donations_get_installation_state( $refresh = false ) {
    global $db, $prefix, $evoconfig;
    static $state = null;
    if ( ! $refresh && null !== $state ) { return $state; }
    $options = trim( evo_donations_get_options_table_name(), "`" );
    evo_donations_get_table_name( "transactions" );
    $transactions = $prefix . "_donations_transactions";
    $legacy = $prefix . "_donations_settings";
    $required = array(
        $options => array( "evo_field", "evo_value" ),
        $transactions => array( "id", "environment", "order_id", "capture_id", "user_id", "display_name", "anonymous", "amount_cents", "excluded_cents", "currency", "status", "created_at", "updated_at" ),
    );
    $result = evo_donations_run_query( "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (" .
        evo_donations_quote_sql_string( $options ) . "," . evo_donations_quote_sql_string( $transactions ) . "," . evo_donations_quote_sql_string( $legacy ) . ")" );
    $found = array();
    try {
        while ( $row = $db->sql_fetchrow( $result, MYSQLI_ASSOC ) ) { $found[ $row["TABLE_NAME"] ][] = $row["COLUMN_NAME"]; }
    } finally { $db->sql_freeresult( $result ); }
    $check = array( "ready" => false, "missing" => array(), "incompatible" => array(), "legacy" => isset( $found[ $legacy ] ) );
    foreach ( $required as $table => $columns ) {
        if ( ! isset( $found[ $table ] ) ) { $check["missing"][] = $table; }
        elseif ( array_diff( $columns, $found[ $table ] ) ) { $check["incompatible"][] = $table; }
    }
    if ( in_array( $options, $check["missing"], true ) ) { $check["incompatible"][] = $options; }
    if ( ! $check["missing"] && ! $check["incompatible"] ) {
        $row = evo_donations_fetch_row( "SELECT evo_value FROM " . evo_donations_get_options_table_name() . " WHERE evo_field = 'donations_settings'" );
        if ( $row ) {
            if ( ! is_array( $evoconfig ) ) { $evoconfig = array(); }
            $evoconfig["donations_settings"] = $row["evo_value"];
            $value = get_evo_option( "donations_settings", null );
            if ( is_array( $value ) ) {
                try { evo_donations_validate_settings( $value ); $check["ready"] = true; }
                catch ( RuntimeException $error ) { /* Keep the installer visible for invalid options. */ }
            }
        }
    }
    $state = $check;
    return $state;
}

/**
 * Create the transaction table and install or migrate the serialized option.
 * @return void
 * @throws RuntimeException If authorization, CSRF, schema validation or installation fails.
 */
function evo_donations_install() {
    if ( ! defined( "ADMIN_FILE" ) || ! is_mod_admin( "Donations" ) ) { throw new RuntimeException( evo_donations_translate( 'exception_access_denied' ) ); }
    if ( ( $_SERVER["REQUEST_METHOD"] ?? "" ) !== "POST" ) { throw new RuntimeException( evo_donations_translate( 'exception_post_required' ) ); }
    evo_donations_validate_csrf_token();
    $state = evo_donations_get_installation_state( true );
    if ( $state["ready"] ) { return; }
    if ( $state["incompatible"] ) { throw new RuntimeException( evo_donations_translate( 'exception_donation_schema_requires_review' ) ); }
    $existing = evo_donations_fetch_row( "SELECT evo_value FROM " . evo_donations_get_options_table_name() . " WHERE evo_field = 'donations_settings'" );
    if ( $existing ) {
        // Do not silently replace a malformed existing option with fresh defaults.
        $value = maybe_unserialize( $existing["evo_value"] );
        if ( ! is_array( $value ) ) { throw new RuntimeException( evo_donations_translate( 'exception_donation_settings_require_review' ) ); }
        $settings = evo_donations_validate_settings( $value );
    } else {
        $settings = evo_donations_get_default_settings();
        if ( $state["legacy"] ) {
            $legacy = evo_donations_fetch_row( "SELECT * FROM " . evo_donations_get_table_name( "settings" ) . " WHERE id = 1" );
            if ( $legacy ) { $settings = evo_donations_validate_settings( $legacy ); }
        }
    }
    evo_donations_run_query(
"CREATE TABLE IF NOT EXISTS " . evo_donations_get_table_name( "transactions" ) . " (\n `id` char(32) NOT NULL PRIMARY KEY,\n `environment` varchar(7) NOT NULL,\n `order_id` varchar(36) DEFAULT NULL,\n `capture_id` varchar(36) DEFAULT NULL,\n `user_id` int unsigned NOT NULL DEFAULT 0,\n `display_name` varchar(100) NOT NULL,\n `anonymous` tinyint unsigned NOT NULL DEFAULT 1,\n `amount_cents` bigint unsigned NOT NULL,\n `excluded_cents` bigint unsigned NOT NULL DEFAULT 0,\n `currency` char(3) NOT NULL,\n `status` varchar(24) NOT NULL DEFAULT 'CREATED',\n `created_at` datetime NOT NULL,\n `updated_at` datetime NOT NULL,\n UNIQUE KEY `paypal_order` (`environment`,`order_id`),\n UNIQUE KEY `paypal_capture` (`environment`,`capture_id`),\n KEY `public_totals` (`environment`,`currency`,`status`,`created_at`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    if ( ! $existing ) { evo_donations_save_settings( $settings, true ); }
    if ( ! evo_donations_get_installation_state( true )["ready"] ) { throw new RuntimeException( evo_donations_translate( 'exception_donation_installation_incomplete' ) ); }
}

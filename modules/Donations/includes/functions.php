<?php
/**
 * Shared donation, PayPal and presentation helpers.
 *
 * Provides exact amount validation, database access, session ownership, CSRF
 * protection and server-side payment reconciliation for Evo Donations.
 *
 * @package EvoDonations
 * @version 1.0.0
 */
defined( "NUKE_EVO" ) || exit;

// Keep all module CSS and JavaScript cache versions in sync.
if ( ! defined( 'EVO_DONATIONS_VERSION' ) ) {
	define( 'EVO_DONATIONS_VERSION', '1.0.0' );
}

/**
 * Escape a value for HTML text or a quoted attribute.
 *
 * Uses the CMS escape_html() helper when available, with a compatible fallback
 * for older Evo installations. Keeps existing evo_donations_escape() callers compatible.
 *
 * @param mixed $value Value to escape for HTML output.
 *
 * @return string Escaped HTML text.
 */
function evo_donations_escape( $value ) {
	if ( function_exists( "escape_html" ) ) {
		return escape_html( (string) $value );
	}

	// Compatibility with Evo versions predating the shared escaping helper.
	return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8" );
}

/**
 * Quote a SQL string with the escaping method provided by the Evo driver.
 *
 * @global object $db Evo database driver.
 *
 * @param string $value Value to escape and enclose in SQL quotes.
 *
 * @return string Quoted SQL string.
 *
 * @throws RuntimeException If no supported database escaping method is available.
 */
function evo_donations_quote_sql_string( $value ) {
	global $db;

	foreach ( array( "sql_escapestring", "sql_escape" ) as $method ) {
		if ( is_object( $db ) && is_callable( array( $db, $method ) ) ) {
			return "'" . $db->$method( (string) $value ) . "'";
		}
	}

	throw new RuntimeException( evo_donations_translate( 'exception_a_supported_database_escaping_method_is_required' ) );
}

/**
 * Return a validated, quoted donation table identifier.
 *
 * Validates both components before passing the complete identifier to the CMS
 * db_quote_identifier() helper. Older Evo versions use validated backtick quoting.
 *
 * @global string $prefix Database table prefix.
 *
 * @param string $suffix Donation table suffix, such as "settings" or "transactions".
 *
 * @return string Validated table name enclosed in backticks.
 *
 * @throws RuntimeException If the prefix or suffix contains invalid characters.
 */
function evo_donations_get_table_name( $suffix ) {
	global $prefix;

	if ( ! is_string( $prefix ) || ! preg_match( '/^[a-zA-Z0-9_]+$/D', $prefix ) ) {
		throw new RuntimeException( evo_donations_translate( 'exception_invalid_table_prefix' ) );
	}

	if ( ! is_string( $suffix ) || ! preg_match( '/^[a-zA-Z0-9_]+$/D', $suffix ) ) {
		throw new RuntimeException( evo_donations_translate( 'exception_invalid_donation_table_suffix' ) );
	}

	$table = $prefix . "_donations_" . $suffix;

	if ( function_exists( "db_quote_identifier" ) ) {
		return db_quote_identifier( $table );
	}

	// Both components have already been restricted to safe identifier characters.
	return "`" . $table . "`";
}

/**
 * Run a database query, throwing a generic error if the driver rejects it.
 *
 * @global object $db Evo database driver.
 *
 * @param string $sql SQL statement to execute.
 *
 * @return mixed Result returned by the Evo database driver.
 *
 * @throws RuntimeException If the query fails.
 */
function evo_donations_run_query( $sql ) {
	global $db;

	$result = $db->sql_query( $sql );

	if ( ! $result ) {
		throw new RuntimeException( evo_donations_translate( 'exception_donation_database_operation_failed' ) );
	}

	return $result;
}

/**
 * Fetch one row and always release its result set.
 *
 * @global object $db Evo database driver.
 *
 * @param string $sql SQL query expected to return at most one relevant row.
 *
 * @return array|null First result row, or null if no row is available.
 *
 * @throws RuntimeException If the query fails.
 */
function evo_donations_fetch_row( $sql ) {
	global $db;

	$result = evo_donations_run_query( $sql );

	try {
		$row = $db->sql_fetchrow( $result, MYSQLI_ASSOC );
		return $row ?: null;
	} finally {
		$db->sql_freeresult( $result );
	}
}

/**
 * Load the serialized donations option through the CMS option reader.
 *
 * @return array Donation settings from the donations_settings Evo option.
 *
 * @throws RuntimeException If settings are missing or the query fails.
 */
function evo_donations_get_settings() {
    if ( ! evo_donations_get_installation_state()["ready"] ) {
        throw new RuntimeException( evo_donations_translate( 'exception_donations_have_not_been_installed' ) );
    }

    $settings = get_evo_option( "donations_settings", null );
    if ( ! is_array( $settings ) ) {
        throw new RuntimeException( evo_donations_translate( 'exception_donation_settings_are_missing' ) );
    }

    return $settings;
}

/**
 * Load English defaults, then overlay the selected locale without losing keys.
 *
 * @global string|null $currentlang Selected CMS language.
 * @global array $ed_lang Donation language strings populated by this function.
 *
 * @return void
 */
function evo_donations_load_language() {
	global $currentlang, $ed_lang;

	require __DIR__ . "/../language/lang-english.php";
	$defaults = $ed_lang;

	if (
		is_string( $currentlang ) &&
		"english" !== $currentlang &&
		preg_match( '/^[a-zA-Z0-9_-]+$/D', $currentlang )
	) {
		$file = __DIR__ . "/../language/lang-" . $currentlang . ".php";

		if ( is_file( $file ) ) {
			include $file;
		}
	}

	$ed_lang = array_replace( $defaults, is_array( $ed_lang ) ? $ed_lang : array() );
}

/**
 * Return a translated label, loading language defaults on demand.
 *
 * Shared helpers can raise exceptions before an endpoint explicitly loads
 * language strings. Missing locale keys fall back to the English defaults.
 *
 * @global array $ed_lang Loaded donation language strings.
 *
 * @param string $key Language array key.
 *
 * @return string Translated label, or the original key when unavailable.
 */
function evo_donations_translate( $key ) {
	global $ed_lang;

	if ( ! isset( $ed_lang[ $key ] ) ) {
		evo_donations_load_language();
	}

	return $ed_lang[ $key ] ?? $key;
}

/**
 * Ensure the PHP session is available before accessing checkout state.
 *
 * @return void
 *
 * @throws RuntimeException If sessions are disabled, headers were sent or startup fails.
 */
function evo_donations_ensure_session() {
	if ( PHP_SESSION_ACTIVE === session_status() ) {
		return;
	}

	if ( PHP_SESSION_DISABLED === session_status() || headers_sent() || ! session_start() ) {
		throw new RuntimeException( evo_donations_translate( 'exception_session_unavailable' ) );
	}
}

/**
 * Return the CMS session token for donation forms.
 *
 * @return string CSRF token generated by the CMS.
 * @throws RuntimeException If the session cannot be started.
 * @throws Exception If secure random bytes cannot be generated.
 */
function evo_donations_get_csrf_token() {
	evo_donations_ensure_session();

	return generateCsrfToken();
}

/**
 * Reject missing, malformed or mismatched submitted CMS CSRF tokens.
 *
 * @return void
 * @throws RuntimeException If the session is unavailable or the token is invalid.
 */
function evo_donations_validate_csrf_token() {
	evo_donations_ensure_session();
	$token = $_POST[ "csrf_token" ] ?? "";

	if ( ! validateCsrfToken( $token ) ) {
		throw new RuntimeException( evo_donations_translate( 'exception_invalid_form_token' ) );
	}
}

/**
 * Return the CMS CSRF hidden field. The caller echoes the result.
 *
 * @return string HTML hidden input containing the escaped CMS session token.
 * @throws RuntimeException If the session cannot be started.
 * @throws Exception If secure random bytes cannot be generated.
 */
function evo_donations_csrf_field() {
	evo_donations_ensure_session();

	return csrfInputField();
}

/**
 * Convert a non-negative decimal string to exact integer cents.
 *
 * Accepts up to seven whole digits and two fractional digits. Scientific
 * notation, whitespace, signs and thousands separators are rejected.
 *
 * @param string $value Non-negative decimal amount, such as "5.00".
 *
 * @return int Exact amount in cents.
 *
 * @throws InvalidArgumentException If the amount has an unsupported format.
 */
function evo_donations_amount_to_cents( $value ) {
	if (
		! is_string( $value ) ||
		! preg_match( '/^(0|[1-9][0-9]{0,6})(?:\.([0-9]{1,2}))?$/D', $value, $matches )
	) {
		throw new InvalidArgumentException( evo_donations_translate( 'exception_invalid_amount' ) );
	}

	return ( (int) $matches[ 1 ] * 100 ) + (int) str_pad( $matches[ 2 ] ?? "", 2, "0" );
}

/**
 * Format non-negative integer cents without floating-point arithmetic.
 *
 * @param int|string $cents Non-negative integer cents, including a database numeric string.
 *
 * @return string Decimal amount with exactly two fractional digits.
 *
 * @throws InvalidArgumentException If the amount is negative after conversion to an integer.
 */
function evo_donations_format_amount( $cents ) {
	$cents = (int) $cents;

	if ( $cents < 0 ) {
		throw new InvalidArgumentException( evo_donations_translate( 'exception_invalid_amount' ) );
	}

	return intdiv( $cents, 100 ) . "." . str_pad( (string) ( $cents % 100 ), 2, "0", STR_PAD_LEFT );
}

/**
 * Load server-side credentials for exactly one PayPal environment.
 *
 * @param string $mode PayPal environment: "sandbox" or "live".
 *
 * @return array Server-side credentials for the requested environment.
 *
 * @throws RuntimeException If the environment, configuration file or credentials are invalid.
 */
function evo_donations_get_paypal_config( $mode ) {
	if ( ! in_array( $mode, array( "sandbox", "live" ), true ) ) {
		throw new RuntimeException( evo_donations_translate( 'exception_invalid_environment' ) );
	}

	$path = getenv( "EVO_DONATIONS_CONFIG" ) ?: __DIR__ . "/config.php";

	if ( ! is_file( $path ) || ! is_readable( $path ) ) {
		throw new RuntimeException( evo_donations_translate( 'exception_paypal_configuration_is_missing' ) );
	}

	$all = require $path;
	$config = is_array( $all ) ? ( $all[ $mode ] ?? null ) : null;

	if ( ! is_array( $config ) ) {
		throw new RuntimeException( evo_donations_translate( 'exception_paypal_configuration_is_incomplete' ) );
	}

	$required = array(
		"client_id",
		"secret",
		"merchant_id",
		"webhook_id",
	);

	foreach ( $required as $key ) {
		if ( ! isset( $config[ $key ] ) || ! is_string( $config[ $key ] ) || "" === trim( $config[ $key ] ) ) {
			throw new RuntimeException( evo_donations_translate( 'exception_paypal_configuration_is_incomplete' ) );
		}
	}

	return $config;
}

/**
 * Return the canonical HTTPS site URL without a trailing slash.
 *
 * @global string $nukeurl Configured Evo site URL.
 *
 * @return string Canonical HTTPS site URL without a trailing slash.
 *
 * @throws RuntimeException If the site URL is invalid or contains unsupported URL components.
 */
function evo_donations_get_site_url() {
	global $nukeurl;

	$url = rtrim( (string) $nukeurl, "/" );
	$parts = parse_url( $url );

	if (
		! filter_var( $url, FILTER_VALIDATE_URL ) ||
		! is_array( $parts ) ||
		"https" !== strtolower( $parts[ "scheme" ] ?? "" ) ||
		isset( $parts[ "pass" ] ) ||
		isset( $parts[ "user" ] ) ||
		isset( $parts[ "query" ] ) ||
		isset( $parts[ "fragment" ] )
	) {
		throw new RuntimeException( evo_donations_translate( 'exception_the_configured_site_url_must_use_https' ) );
	}

	return $url;
}

/**
 * Return the absolute checkout endpoint, preserving the Evo subdirectory.
 *
 * This URL stays unescaped for API payloads and HTTP headers. When rendering it
 * into HTML, pass it through the CMS escape_url() helper at the output boundary.
 *
 * @return string Absolute HTTPS URL of the donation checkout endpoint.
 *
 * @throws RuntimeException If the configured site URL is invalid.
 */
function evo_donations_get_checkout_url() {
	return evo_donations_get_site_url() . "/modules/Donations/checkout.php";
}

/**
 * Send a request to a fixed PayPal API host with TLS verification enabled.
 *
 * Credentials and request/response bodies are never logged. Redirects are
 * disabled; paths must be relative API paths with no embedded query string.
 *
 * @param string $mode PayPal environment: "sandbox" or "live".
 * @param string $method HTTP method: "GET" or "POST".
 * @param string $path Relative versioned PayPal API path, without a query string.
 * @param string|null $body Encoded request body, or null to omit it.
 * @param string[] $headers HTTP headers to send to PayPal.
 * @param string|null $basic Basic authentication credentials in client-id:secret form.
 *
 * @return array Decoded JSON response.
 *
 * @throws RuntimeException If validation, cURL setup, transport or the API response fails.
 */
function evo_donations_send_paypal_request( $mode, $method, $path, $body, array $headers, $basic = null ) {
	if ( ! in_array( $mode, array( "sandbox", "live" ), true ) ) {
		throw new RuntimeException( evo_donations_translate( 'exception_invalid_environment' ) );
	}

	if ( ! is_string( $path ) || ! preg_match( '#^/v[0-9]+/[a-zA-Z0-9/_-]+$#D', $path ) ) {
		throw new RuntimeException( evo_donations_translate( 'exception_invalid_paypal_api_path' ) );
	}

	if ( ! in_array( $method, array( "GET", "POST" ), true ) ) {
		throw new RuntimeException( evo_donations_translate( 'exception_invalid_paypal_request_method' ) );
	}

	if ( ! function_exists( "curl_init" ) ) {
		throw new RuntimeException( evo_donations_translate( 'exception_php_curl_is_required' ) );
	}

	$base = "live" === $mode ? "https://api-m.paypal.com" : "https://api-m.sandbox.paypal.com";
	$handle = curl_init( $base . $path );

	if ( false === $handle ) {
		throw new RuntimeException( evo_donations_translate( 'exception_paypal_request_failed' ) );
	}

	$options = array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CUSTOMREQUEST  => $method,
		CURLOPT_HTTPHEADER     => $headers,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_TIMEOUT        => 35,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
		CURLOPT_FOLLOWLOCATION => false,
	);

	if ( null !== $body ) {
		$options[ CURLOPT_POSTFIELDS ] = $body;
	}

	if ( null !== $basic ) {
		$options[ CURLOPT_USERPWD ] = $basic;
		$options[ CURLOPT_HTTPAUTH ] = CURLAUTH_BASIC;
	}

	try {
		if ( ! curl_setopt_array( $handle, $options ) ) {
			throw new RuntimeException( evo_donations_translate( 'exception_paypal_request_failed' ) );
		}

		$raw = curl_exec( $handle );
		$code = (int) curl_getinfo( $handle, CURLINFO_HTTP_CODE );
	} finally {
		curl_close( $handle );
	}

	$data = is_string( $raw ) ? json_decode( $raw, true ) : null;

	if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
		throw new RuntimeException( evo_donations_translate( 'exception_paypal_request_failed' ) );
	}

	return $data;
}

/**
 * Authenticate once per environment for this request, then call the API.
 *
 * @param string $mode PayPal environment: "sandbox" or "live".
 * @param string $method HTTP method: "GET" or "POST".
 * @param string $path Relative versioned PayPal API path.
 * @param mixed $body JSON-serializable payload, or null to omit the request body.
 * @param string|null $request_id Stable idempotency key for a retryable request.
 *
 * @return array Decoded PayPal API response.
 *
 * @throws RuntimeException If configuration, authentication or the API request fails.
 * @throws JsonException If the request body cannot be encoded as JSON.
 */
function evo_donations_call_paypal_api( $mode, $method, $path, $body = null, $request_id = null ) {
	static $tokens = array();

	if ( ! in_array( $mode, array( "sandbox", "live" ), true ) ) {
		throw new RuntimeException( evo_donations_translate( 'exception_invalid_environment' ) );
	}

	if (
		null !== $request_id &&
		( ! is_string( $request_id ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,108}$/D', $request_id ) )
	) {
		throw new RuntimeException( evo_donations_translate( 'exception_invalid_paypal_request_id' ) );
	}

	if ( ! isset( $tokens[ $mode ] ) ) {
		$config = evo_donations_get_paypal_config( $mode );
		$token  = evo_donations_send_paypal_request(
			$mode,
			"POST",
			"/v1/oauth2/token",
			"grant_type=client_credentials",
			array( "Content-Type: application/x-www-form-urlencoded" ),
			$config[ "client_id" ] . ":" . $config[ "secret" ]
		);

		if ( empty( $token[ "access_token" ] ) || ! is_string( $token[ "access_token" ] ) ) {
			throw new RuntimeException( evo_donations_translate( 'exception_paypal_token_missing' ) );
		}

		$tokens[ $mode ] = $token[ "access_token" ];
	}

	$headers = array(
		"Authorization: Bearer " . $tokens[ $mode ],
		"Content-Type: application/json",
		"Prefer: return=representation",
	);

	if ( null !== $request_id ) {
		$headers[] = "PayPal-Request-Id: " . $request_id;
	}

	$payload = null !== $body ? json_encode( $body, JSON_THROW_ON_ERROR ) : null;
	return evo_donations_send_paypal_request( $mode, $method, $path, $payload, $headers );
}

/**
 * Find a donation by its generated local identifier.
 *
 * @param string $id Local donation identifier containing 32 lowercase hexadecimal characters.
 *
 * @return array Stored donation transaction.
 *
 * @throws RuntimeException If the identifier is invalid, the query fails or no donation exists.
 */
function evo_donations_get_donation( $id ) {
	if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{32}$/D', $id ) ) {
		throw new RuntimeException( evo_donations_translate( 'exception_invalid_donation' ) );
	}

	$row = evo_donations_fetch_row( "SELECT * FROM " . evo_donations_get_table_name( "transactions" ) . " WHERE id = " . evo_donations_quote_sql_string( $id ) );

	if ( ! $row ) {
		throw new RuntimeException( evo_donations_translate( 'exception_donation_not_found' ) );
	}

	return $row;
}

/**
 * Require checkout ownership in the current session, valid for three days.
 *
 * @param array $row Stored donation containing its local "id".
 *
 * @return void
 *
 * @throws RuntimeException If the session is unavailable or checkout ownership has expired.
 */
function evo_donations_require_checkout_owner( $row ) {
	evo_donations_ensure_session();
	$created = $_SESSION[ "ed_orders" ][ $row[ "id" ] ] ?? null;

	if ( ! is_numeric( $created ) || (int) $created < time() - 259200 ) {
		throw new RuntimeException( evo_donations_translate( 'exception_donation_session_expired' ) );
	}
}

/**
 * Verify the order's local reference, receiving merchant, currency and amount.
 *
 * @param array $row Stored donation with its reference, environment, currency and amount.
 * @param array $unit PayPal purchase unit to verify against the stored donation.
 *
 * @return void
 *
 * @throws RuntimeException If credentials are invalid or purchase-unit verification fails.
 * @throws InvalidArgumentException If the returned amount has an invalid decimal format.
 */
function evo_donations_validate_purchase_unit( $row, $unit ) {
	$config = evo_donations_get_paypal_config( $row[ "environment" ] );

	if (
		( $unit[ "custom_id" ] ?? "" ) !== $row[ "id" ] ||
		( $unit[ "payee" ][ "merchant_id" ] ?? "" ) !== $config[ "merchant_id" ] ||
		( $unit[ "amount" ][ "currency_code" ] ?? "" ) !== $row[ "currency" ] ||
		evo_donations_amount_to_cents( $unit[ "amount" ][ "value" ] ?? "" ) !== (int) $row[ "amount_cents" ]
	) {
		throw new RuntimeException( evo_donations_translate( 'exception_paypal_order_verification_failed' ) );
	}
}

/**
 * Serialize reconciliation so an older API snapshot cannot overwrite a newer one.
 *
 * @param array $row Stored donation transaction to reconcile.
 * @param string|null $reversed_capture Capture ID from an already verified reversal event, if any.
 *
 * @return string Reconciled payment status.
 *
 * @throws RuntimeException If locking, database access or payment verification fails.
 * @throws InvalidArgumentException If PayPal returns an invalid decimal amount.
 */
function evo_donations_reconcile_payment( $row, $reversed_capture = null ) {
	$key = "ed-" . sha1( evo_donations_get_table_name( "transactions" ) . $row[ "id" ] );
	$lock = evo_donations_fetch_row( "SELECT GET_LOCK(" . evo_donations_quote_sql_string( $key ) . ", 5) AS acquired" );

	if ( 1 !== (int) ( $lock[ "acquired" ] ?? 0 ) ) {
		throw new RuntimeException( evo_donations_translate( 'exception_donation_is_being_reconciled_retry_shortly' ) );
	}

	try {
		return evo_donations_reconcile_payment_locked( $row, $reversed_capture );
	} finally {
		// Fetch and free the RELEASE_LOCK result just like the acquisition query.
		evo_donations_fetch_row( "SELECT RELEASE_LOCK(" . evo_donations_quote_sql_string( $key ) . ") AS released" );
	}
}

/**
 * Read and verify current PayPal state while holding the donation lock.
 *
 * Call through evo_donations_reconcile_payment(). Only verified capture state is persisted. Partial
 * refunds remain conservatively excluded in full, as in the existing module.
 *
 * @param array $row Stored donation transaction to reconcile while its lock is held.
 * @param string|null $reversed_capture Capture ID from an already verified reversal event, if any.
 *
 * @return string Verified status persisted to the transaction record.
 *
 * @throws RuntimeException If the order, capture, reversal or database update is invalid.
 * @throws InvalidArgumentException If PayPal returns an invalid decimal amount.
 */
function evo_donations_reconcile_payment_locked( $row, $reversed_capture = null ) {
	if ( empty( $row[ "order_id" ] ) || ! preg_match( '/^[A-Z0-9]{1,36}$/D', $row[ "order_id" ] ) ) {
		throw new RuntimeException( evo_donations_translate( 'exception_order_is_not_ready' ) );
	}

	$order = evo_donations_call_paypal_api( $row[ "environment" ], "GET", "/v2/checkout/orders/" . rawurlencode( $row[ "order_id" ] ) );
	$units = $order[ "purchase_units" ] ?? null;

	if ( ( $order[ "id" ] ?? "" ) !== $row[ "order_id" ] || ! is_array( $units ) || count( $units ) !== 1 || ! isset( $units[ 0 ] ) ) {
		throw new RuntimeException( evo_donations_translate( 'exception_unexpected_paypal_order' ) );
	}

	$unit = $units[ 0 ];
	evo_donations_validate_purchase_unit( $row, $unit );
	$captures = $unit[ "payments" ][ "captures" ] ?? array();

	if ( ! is_array( $captures ) || count( $captures ) > 1 ) {
		throw new RuntimeException( evo_donations_translate( 'exception_unexpected_split_capture' ) );
	}

	$status         = "CREATED";
	$capture_id     = null;
	$excluded_cents = 0;

	if ( $captures ) {
		$capture_id = $captures[ 0 ][ "id" ] ?? "";

		if ( ! is_string( $capture_id ) || ! preg_match( '/^[A-Z0-9]{1,36}$/D', $capture_id ) ) {
			throw new RuntimeException( evo_donations_translate( 'exception_invalid_capture' ) );
		}

		$capture = evo_donations_call_paypal_api( $row[ "environment" ], "GET", "/v2/payments/captures/" . $capture_id );
		$config  = evo_donations_get_paypal_config( $row[ "environment" ] );

		if (
			( $capture[ "id" ] ?? "" ) !== $capture_id ||
			( $capture[ "payee" ][ "merchant_id" ] ?? "" ) !== $config[ "merchant_id" ] ||
			( $capture[ "supplementary_data" ][ "related_ids" ][ "order_id" ] ?? "" ) !== $row[ "order_id" ] ||
			( $capture[ "amount" ][ "currency_code" ] ?? "" ) !== $row[ "currency" ] ||
			evo_donations_amount_to_cents( $capture[ "amount" ][ "value" ] ?? "" ) !== (int) $row[ "amount_cents" ]
		) {
			throw new RuntimeException( evo_donations_translate( 'exception_paypal_capture_verification_failed' ) );
		}

		$allowed_statuses = array(
			"COMPLETED",
			"PENDING",
			"DECLINED",
			"FAILED",
			"REFUNDED",
			"PARTIALLY_REFUNDED",
			"REVERSED",
		);

		$status = $capture[ "status" ] ?? "PENDING";

		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = "PENDING";
		}

		if ( in_array( $status, array( "REFUNDED", "PARTIALLY_REFUNDED", "REVERSED" ), true ) ) {
			$excluded_cents = (int) $row[ "amount_cents" ];
		}
	}

	// A verified reversal remains terminal even if a later capture read lags it.
	$stored = evo_donations_fetch_row( "SELECT status FROM " . evo_donations_get_table_name( "transactions" ) . " WHERE id = " . evo_donations_quote_sql_string( $row[ "id" ] ) );

	if ( null !== $reversed_capture ) {
		if ( ! is_string( $reversed_capture ) || ! $capture_id || ! hash_equals( $capture_id, $reversed_capture ) ) {
			throw new RuntimeException( evo_donations_translate( 'exception_reversal_capture_mismatch' ) );
		}

		$status = "REVERSED";
		$excluded_cents = (int) $row[ "amount_cents" ];
	} elseif ( "REVERSED" === ( $stored[ "status" ] ?? "" ) ) {
		$status = "REVERSED";
		$excluded_cents = (int) $row[ "amount_cents" ];
	}

	evo_donations_run_query(
		"UPDATE " . evo_donations_get_table_name( "transactions" ) .
		" SET status = " . evo_donations_quote_sql_string( $status ) .
		", capture_id = " . ( $capture_id ? evo_donations_quote_sql_string( $capture_id ) : "NULL" ) .
		", excluded_cents = " . $excluded_cents .
		", updated_at = UTC_TIMESTAMP() WHERE id = " . evo_donations_quote_sql_string( $row[ "id" ] )
	);

	// Only a newly verified successful donation invalidates public donation data.
	if ( 'COMPLETED' === $status && 'COMPLETED' !== ( $stored['status'] ?? '' ) ) {
		evo_donations_clear_public_cache();
	}

	return $status;
}

/**
 * Clear the module and widget data together after a successful donation.
 *
 * @global object $cache Evo cache service.
 * @return void
 */
function evo_donations_clear_public_cache() {
	global $cache;

	if ( ! is_object( $cache ) ) {
		return;
	}

	$cache->delete( 'module', 'donations' );
	$cache->delete( 'widget', 'donations' );
}

/**
 * Load public summary data through the Evo cache service.
 *
 * Cache data rather than forms or HTML so language, settings and CSRF tokens
 * remain current. Each consumer keeps one settings-qualified snapshot; a settings
 * change replaces that snapshot without clearing either public cache.
 *
 * @global object $cache Evo cache service.
 * @global object $db Evo database driver.
 * @param array  $settings Active donation settings.
 * @param string $context  Module or widget cache key.
 * @return array Totals and recent completed donations.
 */
function evo_donations_get_summary_data( $settings, $context = 'module' ) {
	global $cache, $db;

	$key = 'widget' === $context ? 'widget' : 'module';
	$signature = hash( 'sha256', serialize( array(
		evo_donations_get_table_name( 'transactions' ),
		$settings['environment'],
		$settings['currency'],
		$settings['goal_start'],
	) ) );
	$cached = is_object( $cache ) ? $cache->load( $key, 'donations' ) : false;

	if ( is_array( $cached ) && ( $cached['signature'] ?? '' ) === $signature ) {
		return $cached['data'];
	}

	$data = array(
		'totals' => evo_donations_get_goal_totals( $settings ),
		'recent' => array(),
	);
	$result = evo_donations_run_query(
		"SELECT display_name, anonymous, amount_cents, currency FROM " . evo_donations_get_table_name( 'transactions' ) .
		" WHERE environment = " . evo_donations_quote_sql_string( $settings['environment'] ) .
		" AND currency = " . evo_donations_quote_sql_string( $settings['currency'] ) .
		" AND status = 'COMPLETED' ORDER BY created_at DESC, id DESC LIMIT 5"
	);

	try {
		while ( $row = $db->sql_fetchrow( $result, MYSQLI_ASSOC ) ) {
			$data['recent'][] = $row;
		}
	} finally {
		$db->sql_freeresult( $result );
	}

	if ( is_object( $cache ) ) {
		$cache->save( $key, 'donations', array(
			'signature' => $signature,
			'data'      => $data,
		) );

		// Remove the scalar entry written under the old, incorrect category.
		if ( 'donations' === $cache->load( $key, 'Array' ) ) {
			$cache->delete( $key, 'Array' );
		}
	}

	return $data;
}

/**
 * Fetch gross completed donations for the active goal, environment and currency.
 *
 * @param array $settings Settings containing environment, currency and goal_start.
 *
 * @return array|null Aggregate row containing total cents and transaction count.
 *
 * @throws RuntimeException If the aggregate query fails.
 */
function evo_donations_get_goal_totals( $settings ) {
	return evo_donations_fetch_row(
		"SELECT COALESCE(SUM(amount_cents - excluded_cents), 0) AS total, COUNT(*) AS donors" .
		" FROM " . evo_donations_get_table_name( "transactions" ) .
		" WHERE environment = " . evo_donations_quote_sql_string( $settings[ "environment" ] ) .
		" AND currency = " . evo_donations_quote_sql_string( $settings[ "currency" ] ) .
		" AND status = 'COMPLETED' AND created_at >= " . evo_donations_quote_sql_string( $settings[ "goal_start" ] . " 00:00:00" )
	);
}

/**
 * Return the escaped goal summary and the five most recent completed donations.
 *
 * @param array  $settings Donation settings used to select the goal and recent supporters.
 * @param string $context  Module or widget cache key.
 *
 * @return string Escaped summary HTML for the module or companion block.
 *
 * @throws RuntimeException If a database query fails.
 * @throws InvalidArgumentException If a stored donation amount is negative.
 */
function evo_donations_render_summary( $settings, $context = 'module' ) {
	$data     = evo_donations_get_summary_data( $settings, $context );
	$stats    = $data['totals'];
	$goal     = (int) $settings[ "goal_cents" ];
	$total    = max( 0, (int) ( $stats[ "total" ] ?? 0 ) );
	$percent  = $goal > 0 ? min( 100, round( $total * 100 / $goal ) ) : 0;
	$currency = (string) $settings[ "currency" ];

	$html = '<div class="ed-summary"><p><strong>' .
		evo_donations_escape( evo_donations_format_amount( $total ) . " " . $currency ) . '</strong> ' . evo_donations_escape( evo_donations_translate( "raised" ) ) . '</p>';

	if ( $goal > 0 ) {
		$html .= '<progress max="100" value="' . (int) $percent . '" aria-label="' . evo_donations_escape( evo_donations_translate( "progress" ) ) . '"></progress>' .
			'<p>' . evo_donations_escape( evo_donations_translate( "goal" ) . ": " . evo_donations_format_amount( $goal ) . " " . $currency ) . '</p>';
	}

	$html .= '<h3>' . evo_donations_escape( evo_donations_translate( "recent" ) ) . '</h3><ul>';
	$found = ! empty( $data['recent'] );

	foreach ( $data['recent'] as $row ) {
		$name = $row['anonymous'] ? evo_donations_translate( 'anonymous' ) : $row['display_name'];
		$html .= '<li>' . evo_donations_escape( $name ) . ' <span>' .
			evo_donations_escape( evo_donations_format_amount( $row['amount_cents'] ) . ' ' . $row['currency'] ) . '</span></li>';
	}

	if ( ! $found ) {
		$html .= '<li>' . evo_donations_escape( evo_donations_translate( "none" ) ) . '</li>';
	}

	return $html . '</ul></div>';
}

require_once __DIR__ . "/installer.php";

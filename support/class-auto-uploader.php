<?php
/**
 * Digipay Auto-Uploader
 *
 * When the plugin detects a critical condition (many HMAC failures, fatal
 * errors, etc.) and the merchant has opted in, automatically POSTs a
 * diagnostic bundle to the Digipay ingestion endpoint for faster triage.
 *
 * Opt-in is off by default. The merchant must enable it on the Support page.
 *
 * @package DigipayMasterPlugin
 * @since 13.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Auto-uploads diagnostic bundles on critical conditions.
 */
class WCPG_Auto_Uploader {

	// ------------------------------------------------------------------
	// Constants
	// ------------------------------------------------------------------

	/**
	 * Option that stores the merchant's opt-in flag (bool).
	 */
	const OPTION_ENABLED = 'wcpg_support_autoupload_enabled';

	/**
	 * Option that stores a merchant-settable ingest URL override.
	 * Falls back to the WCPG_SUPPORT_INGEST_URL PHP constant if defined.
	 */
	const OPTION_INGEST_URL = 'wcpg_support_ingest_url';

	/**
	 * Transient key for the 1-hour upload throttle.
	 */
	const THROTTLE_TRANSIENT = 'wcpg_support_autoupload_throttle';

	/**
	 * How long (in seconds) to wait between successive auto-uploads.
	 */
	const THROTTLE_WINDOW = HOUR_IN_SECONDS;

	/**
	 * Transient key for the "hmac critical event already fired" sentinel.
	 */
	const HMAC_CRITICAL_FIRED_TRANSIENT = 'wcpg_hmac_critical_fired';

	/**
	 * How many hmac_fail occurrences in the 24h window trigger the event.
	 */
	const HMAC_CRITICAL_THRESHOLD = 10;

	// ------------------------------------------------------------------
	// Public API
	// ------------------------------------------------------------------

	/**
	 * Register the critical event listener hook.
	 */
	public function register() {
		add_action( 'wcpg_critical_event', array( $this, 'handle_critical_event' ), 10, 2 );
	}

	/**
	 * Handle a critical event: validate opt-in + URL, throttle, build
	 * bundle, POST to ingestion endpoint, record result in event log.
	 *
	 * @param string $reason  Short slug describing the critical condition.
	 * @param array  $context Arbitrary context data (will be JSON-encoded).
	 */
	public function handle_critical_event( $reason, $context ) {
		// 1. Opt-in check.
		if ( ! get_option( self::OPTION_ENABLED, false ) ) {
			return;
		}

		// 2. Resolve ingest URL.
		$ingest_url = get_option( self::OPTION_INGEST_URL, '' );
		if ( empty( $ingest_url ) && defined( 'WCPG_SUPPORT_INGEST_URL' ) ) {
			$ingest_url = WCPG_SUPPORT_INGEST_URL;
		}
		if ( empty( $ingest_url ) ) {
			return;
		}

		// 3. Throttle check.
		if ( get_transient( self::THROTTLE_TRANSIENT ) ) {
			return;
		}

		// 4. Build diagnostic bundle.
		try {
			$bundle = ( new WCPG_Context_Bundler() )->build();
		} catch ( \Throwable $e ) {
			if ( class_exists( 'WCPG_Event_Log' ) ) {
				WCPG_Event_Log::record(
					WCPG_Event_Log::TYPE_CRITICAL,
					array(
						'action'  => 'auto_upload',
						'reason'  => $reason,
						'error'   => 'bundle_build_failed: ' . $e->getMessage(),
						'success' => false,
					)
				);
			}
			return;
		}

		// 5. Build request body.
		$ts        = (string) time();
		$site_url  = home_url();
		$site_id   = self::get_or_create_site_id();
		$body_data = array(
			'site_url' => $site_url,
			'reason'   => $reason,
			'context'  => $context,
			'bundle'   => $bundle,
		);
		$json_body = wp_json_encode( $body_data );

		// 6. Compute HMAC-SHA512 signature.
		$secret    = self::get_or_create_site_secret();
		$signature = hash_hmac( 'sha512', $ts . '.' . $json_body, $secret );

		// 7. POST to ingestion endpoint.
		$response = wcpg_http_request(
			$ingest_url,
			array(
				'method'  => 'POST',
				'body'    => $json_body,
				'headers' => array(
					'Content-Type'         => 'application/json',
					'X-Digipay-Site-Id'    => $site_id,
					'X-Digipay-Timestamp'  => $ts,
					'X-Digipay-Signature'  => $signature,
				),
				'timeout' => 15,
			)
		);

		// 8. Set throttle transient.
		set_transient( self::THROTTLE_TRANSIENT, 1, self::THROTTLE_WINDOW );

		// 9. Record result in event log.
		$is_wp_error    = is_array( $response ) ? false : ( function_exists( 'is_wp_error' ) ? is_wp_error( $response ) : false );
		$response_code  = $is_wp_error ? 0 : ( is_array( $response ) && isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0 );
		$success        = ! $is_wp_error && $response_code >= 200 && $response_code < 300;

		if ( class_exists( 'WCPG_Event_Log' ) ) {
			WCPG_Event_Log::record(
				WCPG_Event_Log::TYPE_CRITICAL,
				array(
					'action'        => 'auto_upload',
					'reason'        => $reason,
					'success'       => $success,
					'response_code' => $response_code,
				)
			);
		}
	}

	/**
	 * Check if hmac_fail counter has reached the threshold and, if so, fire
	 * the critical event once per HMAC_CRITICAL_FIRED_TRANSIENT window.
	 *
	 * Called from WCPG_ETransfer_Webhook_Handler after bump_counter('hmac_fail').
	 */
	public static function maybe_fire_hmac_critical() {
		// Already fired in this window?
		if ( get_transient( self::HMAC_CRITICAL_FIRED_TRANSIENT ) ) {
			return;
		}

		// Read health counters.
		$counts   = get_transient( 'wcpg_etw_health' );
		$hmac_fail = is_array( $counts ) && isset( $counts['hmac_fail'] ) ? (int) $counts['hmac_fail'] : 0;

		if ( $hmac_fail < self::HMAC_CRITICAL_THRESHOLD ) {
			return;
		}

		// Mark as fired (1h window to prevent duplicate firing).
		set_transient( self::HMAC_CRITICAL_FIRED_TRANSIENT, 1, HOUR_IN_SECONDS );

		do_action(
			'wcpg_critical_event',
			'hmac_failures',
			array(
				'hmac_fail_count' => $hmac_fail,
				'threshold'       => self::HMAC_CRITICAL_THRESHOLD,
			)
		);
	}

	/**
	 * Shutdown callback: check error_get_last() for fatal errors that
	 * originated in our plugin directory, and fire a critical event.
	 */
	public static function check_for_fatals() {
		$error = error_get_last();
		if ( ! is_array( $error ) ) {
			return;
		}
		if ( E_ERROR !== $error['type'] ) {
			return;
		}
		// Only care about fatals originating in our plugin dir.
		if ( false === strpos( $error['file'], 'secure_plugin' ) ) {
			return;
		}

		do_action(
			'wcpg_critical_event',
			'plugin_fatal',
			array(
				'file'    => $error['file'],
				'line'    => $error['line'],
				'message' => $error['message'],
			)
		);
	}

	/**
	 * Return the stored site secret, generating one if it does not exist yet.
	 *
	 * @return string 32-hex-char secret (16 random bytes in hex).
	 */
	public static function get_or_create_site_secret() {
		$secret = get_option( 'wcpg_support_site_secret', '' );
		if ( ! empty( $secret ) ) {
			return $secret;
		}

		try {
			$secret = bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $e ) {
			// Fallback — very unlikely path.
			$secret = bin2hex( uniqid( '', true ) );
		}

		update_option( 'wcpg_support_site_secret', $secret, false );
		return $secret;
	}

	/**
	 * Return the stored site ID, generating one if it does not exist yet.
	 *
	 * @return string UUID-like identifier (16-hex-char, 8 random bytes).
	 */
	public static function get_or_create_site_id() {
		$site_id = get_option( 'wcpg_support_site_id', '' );
		if ( ! empty( $site_id ) ) {
			return $site_id;
		}

		try {
			$site_id = bin2hex( random_bytes( 8 ) );
		} catch ( \Exception $e ) {
			$site_id = bin2hex( uniqid( '', true ) );
		}

		update_option( 'wcpg_support_site_id', $site_id, false );
		return $site_id;
	}
}

<?php
/**
 * Digipay Auto-Uploader
 *
 * When the plugin detects a critical condition (many HMAC failures, fatal
 * errors, etc.) and the merchant has opted in, automatically POSTs a
 * diagnostic bundle to the Digipay ingestion endpoint for faster triage.
 *
 * Enabled by default (opt-out). The merchant can disable it on the Support page.
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
	 * Default ingestion endpoint — Supabase edge function plugin-bundle-ingest.
	 * Overridable by setting the wcpg_support_ingest_url option or defining
	 * the WCPG_SUPPORT_INGEST_URL constant in wp-config.php.
	 */
	const DEFAULT_INGEST_URL = 'https://hzdybwclwqkcobpwxzoo.supabase.co/functions/v1/plugin-bundle-ingest';

	/**
	 * Public handshake key used to sign telemetry uploads.
	 *
	 * This is intentionally NOT a secret. It is baked into the plugin source
	 * and published on GitHub. Its only purpose is to filter drive-by bots
	 * from the ingest endpoint — anyone who reads the repo can produce a
	 * valid signature. Real abuse protection is handled server-side by:
	 *   - HTTP body size cap
	 *   - IP rate limit (per minute)
	 *   - install_uuid rate limit (per hour and per day)
	 *   - 5-minute replay window on the timestamp
	 *
	 * Rotate by:
	 *   1. Updating this constant
	 *   2. Updating DIGIPAY_INGEST_HANDSHAKE on the Supabase edge function
	 *   3. Shipping a new plugin release
	 */
	const INGEST_HANDSHAKE_KEY = 'dp_ingest_v1_a74f8c3e9b2d6051f8a7c3e4b9d10287';

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
		// 1. Opt-out check (enabled by default).
		if ( ! get_option( self::OPTION_ENABLED, true ) ) {
			return;
		}

		// 2. Resolve ingest URL: option override → wp-config constant → default.
		$ingest_url = get_option( self::OPTION_INGEST_URL, '' );
		if ( empty( $ingest_url ) && defined( 'WCPG_SUPPORT_INGEST_URL' ) ) {
			$ingest_url = WCPG_SUPPORT_INGEST_URL;
		}
		if ( empty( $ingest_url ) ) {
			$ingest_url = self::DEFAULT_INGEST_URL;
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

		// 5. Run the issue catalog locally so the dashboard receives detections
		// without having to duplicate the PHP detector logic in the edge function.
		$detected_issues = array();
		if ( class_exists( 'WCPG_Issue_Catalog' ) ) {
			try {
				$detected_issues = WCPG_Issue_Catalog::detect_all( $bundle );
			} catch ( \Throwable $e ) {
				$detected_issues = array();
			}
		}

		// 6. Build request body.
		$ts        = (string) time();
		$site_url  = home_url();
		$install_uuid = self::get_or_create_install_uuid();
		$body_data = array(
			'site_url'        => $site_url,
			'reason'          => $reason,
			'context'         => $context,
			'bundle'          => $bundle,
			'detected_issues' => $detected_issues,
		);
		$json_body = wp_json_encode( $body_data );

		// 7. Compute HMAC-SHA512 signature using the baked-in handshake key.
		// This is a public obfuscation filter, not a real auth secret — see
		// the INGEST_HANDSHAKE_KEY constant docblock for details.
		$secret = self::INGEST_HANDSHAKE_KEY;
		if ( '' === $secret ) {
			if ( class_exists( 'WCPG_Event_Log' ) ) {
				WCPG_Event_Log::record(
					WCPG_Event_Log::TYPE_CRITICAL,
					array(
						'action'  => 'auto_upload',
						'reason'  => $reason,
						'error'   => 'no_csprng: could not generate site secret',
						'success' => false,
					)
				);
			}
			return;
		}
		$signature = hash_hmac( 'sha512', $ts . '.' . $json_body, $secret );

		// 8. POST to ingestion endpoint.
		$response = wcpg_http_request(
			$ingest_url,
			array(
				'method'  => 'POST',
				'body'    => $json_body,
				'headers' => array(
					'Content-Type'         => 'application/json',
					'X-Digipay-Install-Uuid' => $install_uuid,
					'X-Digipay-Timestamp'  => $ts,
					'X-Digipay-Signature'  => $signature,
				),
				'timeout' => 15,
			)
		);

		// 9. Determine success and set throttle transient.
		$is_wp_error   = function_exists( 'is_wp_error' ) ? is_wp_error( $response ) : false;
		$response_code = $is_wp_error ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$success       = ! $is_wp_error && $response_code >= 200 && $response_code < 300;

		// Full 1-hour throttle on success; short 5-minute retry window on failure.
		$ttl = $success ? self::THROTTLE_WINDOW : ( 5 * MINUTE_IN_SECONDS );
		set_transient( self::THROTTLE_TRANSIENT, 1, $ttl );

		// 10. Record result in event log.

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
			'hmac_threshold',
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
			// random_bytes failed; try openssl as CSPRNG fallback.
			if ( function_exists( 'openssl_random_pseudo_bytes' ) ) {
				$strong = false;
				$raw    = openssl_random_pseudo_bytes( 16, $strong );
				if ( $strong && false !== $raw ) {
					$secret = bin2hex( $raw );
				} else {
					return ''; // Give up — caller must handle.
				}
			} else {
				return ''; // No CSPRNG available; abort.
			}
		}

		update_option( 'wcpg_support_site_secret', $secret, false );
		return $secret;
	}

	/**
	 * Return the stored install UUID, generating one if it does not exist yet.
	 *
	 * This is a stable, auto-generated per-install identifier used to tag
	 * diagnostic bundle uploads. It is unrelated to the 4-digit CPT gateway
	 * "Site ID" configured in the payment gateway settings.
	 *
	 * Backwards compatible with pre-rename installs that stored the value
	 * in `wcpg_support_site_id` — reads the legacy option, migrates it to
	 * `wcpg_install_uuid`, and deletes the old key.
	 *
	 * @return string UUID-like identifier (16-hex-char, 8 random bytes).
	 */
	public static function get_or_create_install_uuid() {
		$install_uuid = get_option( 'wcpg_install_uuid', '' );
		if ( ! empty( $install_uuid ) ) {
			return $install_uuid;
		}

		// Migrate from legacy option name if present.
		$legacy = get_option( 'wcpg_support_site_id', '' );
		if ( ! empty( $legacy ) ) {
			update_option( 'wcpg_install_uuid', $legacy, false );
			delete_option( 'wcpg_support_site_id' );
			return $legacy;
		}

		try {
			$install_uuid = bin2hex( random_bytes( 8 ) );
		} catch ( \Exception $e ) {
			// random_bytes failed; try openssl as CSPRNG fallback.
			if ( function_exists( 'openssl_random_pseudo_bytes' ) ) {
				$strong = false;
				$raw    = openssl_random_pseudo_bytes( 8, $strong );
				if ( $strong && false !== $raw ) {
					$install_uuid = bin2hex( $raw );
				} else {
					return ''; // Give up — caller must handle.
				}
			} else {
				return ''; // No CSPRNG available; abort.
			}
		}

		update_option( 'wcpg_install_uuid', $install_uuid, false );
		return $install_uuid;
	}
}

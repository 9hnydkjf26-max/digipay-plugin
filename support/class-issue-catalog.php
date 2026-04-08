<?php
/**
 * Digipay Issue Catalog
 *
 * Catalog of known plugin issues with stable IDs. Each issue has a detector
 * closure that accepts a diagnostic bundle array and returns true when the
 * issue is present.
 *
 * Usage:
 *   $issues  = WCPG_Issue_Catalog::detect_all( $bundle );   // full bundle
 *   $issues  = WCPG_Issue_Catalog::detect_config_only( $gateway_settings_by_id );
 *
 * @package DigipayMasterPlugin
 * @since 13.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Known-issue catalog with stable IDs and detectors.
 *
 * All methods are static; the class is never instantiated.
 */
class WCPG_Issue_Catalog {

	// ------------------------------------------------------------------
	// Severity constants
	// ------------------------------------------------------------------

	const SEV_INFO     = 'info';
	const SEV_WARNING  = 'warning';
	const SEV_ERROR    = 'error';
	const SEV_CRITICAL = 'critical';

	// ------------------------------------------------------------------
	// Public API
	// ------------------------------------------------------------------

	/**
	 * Return the full list of known issues (including detector callables).
	 *
	 * @return array[] Each entry: id, title, plain_english, fix, severity, config_only, detector.
	 */
	public static function all() {
		return array(

			// ----------------------------------------------------------
			// WCPG-P-001  Stale postback URL test matcher false negative
			// ----------------------------------------------------------
			array(
				'id'            => 'WCPG-P-001',
				'title'         => 'Stale postback URL test matcher false negative',
				'plain_english' => 'The built-in postback URL self-test is giving a false error. Your endpoint is actually reachable, but the test is looking for old response text that was updated in a recent plugin version.',
				'fix'           => 'No merchant action needed. Digipay will fix the test matcher in a future release.',
				'severity'      => self::SEV_WARNING,
				'config_only'   => false,
				'detector'      => static function ( array $bundle ) {
					$test = isset( $bundle['connectivity_tests']['postback_url'] )
						? $bundle['connectivity_tests']['postback_url']
						: null;

					if ( ! is_array( $test ) ) {
						return false;
					}
					if ( ! empty( $test['success'] ) ) {
						return false;
					}

					// Check both possible preview keys.
					$preview = '';
					if ( isset( $test['body_preview'] ) ) {
						$preview = (string) $test['body_preview'];
					} elseif ( isset( $test['response_body'] ) ) {
						$preview = (string) $test['response_body'];
					}

					return false !== strpos( $preview, 'order_not_found' );
				},
			),

			// ----------------------------------------------------------
			// WCPG-P-002  Postback error rate > 20 %
			// ----------------------------------------------------------
			array(
				'id'            => 'WCPG-P-002',
				'title'         => 'Postback error rate above 20%',
				'plain_english' => 'More than 1 in 5 payment postbacks is failing. Orders may be stuck in Pending state.',
				'fix'           => 'Open WooCommerce → Digipay Support and generate a diagnostic report. Share the report with support@digipay.co.',
				'severity'      => self::SEV_ERROR,
				'config_only'   => false,
				'detector'      => static function ( array $bundle ) {
					// Try diagnostics section first, then option_snapshots.
					$stats = isset( $bundle['diagnostics']['postback_stats'] )
						? $bundle['diagnostics']['postback_stats']
						: null;

					if ( ! is_array( $stats ) ) {
						$stats = isset( $bundle['option_snapshots']['wcpg_postback_stats'] )
							? $bundle['option_snapshots']['wcpg_postback_stats']
							: null;
					}

					if ( ! is_array( $stats ) ) {
						return false;
					}

					$success = isset( $stats['success_count'] ) ? (int) $stats['success_count'] : 0;
					$errors  = isset( $stats['error_count'] )   ? (int) $stats['error_count']   : 0;
					$total   = $success + $errors;

					if ( $total < 5 ) {
						return false;
					}

					return ( $errors / $total ) > 0.2;
				},
			),

			// ----------------------------------------------------------
			// WCPG-W-001  E-Transfer webhook HMAC failures in last 24 h
			// ----------------------------------------------------------
			array(
				'id'            => 'WCPG-W-001',
				'title'         => 'E-Transfer webhook HMAC signature failures',
				'plain_english' => 'Your site is receiving e-Transfer webhook callbacks but rejecting them because the signature does not match. This usually means your webhook secret does not match the one configured at the payment provider.',
				'fix'           => 'Check the webhook_secret_key field in WooCommerce → Settings → Payments → Interac e-Transfer. If empty or incorrect, contact support@digipay.co to re-sync the secret.',
				'severity'      => self::SEV_ERROR,
				'config_only'   => false,
				'detector'      => static function ( array $bundle ) {
					$health = isset( $bundle['webhook_health'] ) ? $bundle['webhook_health'] : array();

					if ( ! is_array( $health ) ) {
						return false;
					}

					$hmac_fail = isset( $health['hmac_fail'] ) ? (int) $health['hmac_fail'] : 0;
					return $hmac_fail >= 1;
				},
			),

			// ----------------------------------------------------------
			// WCPG-W-002  E-Transfer enabled but webhook_secret_key empty
			// ----------------------------------------------------------
			array(
				'id'            => 'WCPG-W-002',
				'title'         => 'E-Transfer webhook secret key not configured',
				'plain_english' => 'E-Transfer is turned on but you haven\'t entered a webhook secret. Transaction status updates from the provider will be rejected.',
				'fix'           => 'Enter the webhook secret in WooCommerce → Settings → Payments → Interac e-Transfer. If you don\'t have one, request it from support@digipay.co.',
				'severity'      => self::SEV_WARNING,
				'config_only'   => true,
				'detector'      => static function ( array $bundle ) {
					$settings = isset( $bundle['gateways']['digipay_etransfer'] )
						? $bundle['gateways']['digipay_etransfer']
						: null;

					if ( ! is_array( $settings ) ) {
						return false;
					}
					if ( ( isset( $settings['enabled'] ) ? $settings['enabled'] : '' ) !== 'yes' ) {
						return false;
					}

					$secret = isset( $settings['webhook_secret_key'] ) ? (string) $settings['webhook_secret_key'] : '';

					// Match the redacted form (bundler has already run) OR raw empty value.
					return $secret === '[REDACTED:length=0]' || $secret === '';
				},
			),

			// WCPG-X-001 (encryption key using hardcoded fallback) intentionally
			// omitted: the fallback is a known default and cannot be changed per-
			// merchant without a wp-config.php edit we do not ask merchants to do.
			// Surfacing it as a warning was noise; the info is still in the raw
			// bundle's encryption_key_status section for support review.

			// ----------------------------------------------------------
			// WCPG-X-002  LiteSpeed Cache active and REST endpoint NOT excluded
			// ----------------------------------------------------------
			array(
				'id'            => 'WCPG-X-002',
				'title'         => 'LiteSpeed Cache not excluding Digipay REST endpoints',
				'plain_english' => 'LiteSpeed Cache is installed but the Digipay REST endpoints are not on its do-not-cache list. Cached responses can cause postbacks and webhooks to be silently dropped.',
				'fix'           => 'In LiteSpeed Cache → Cache → Excludes → Do Not Cache URIs, add a line containing `/wp-json/digipay/`. Save settings, then purge all caches.',
				'severity'      => self::SEV_ERROR,
				'config_only'   => false,
				'detector'      => static function ( array $bundle ) {
					$detail = isset( $bundle['environment_detail'] ) ? $bundle['environment_detail'] : array();

					if ( ! is_array( $detail ) ) {
						return false;
					}
					if ( ! array_key_exists( 'litespeed_rest_excluded', $detail ) ) {
						return false;
					}

					// null = LSCWP not active — no issue. false = active but not excluded — issue!
					return $detail['litespeed_rest_excluded'] === false;
				},
			),

			// ----------------------------------------------------------
			// WCPG-X-003  PHP < 7.4
			// ----------------------------------------------------------
			array(
				'id'            => 'WCPG-X-003',
				'title'         => 'PHP version below minimum requirement (7.4)',
				'plain_english' => 'Your site is running an outdated PHP version. The plugin requires PHP 7.4 or newer for security and compatibility.',
				'fix'           => 'Contact your hosting provider and ask them to upgrade PHP to at least 8.0.',
				'severity'      => self::SEV_ERROR,
				'config_only'   => false,
				'detector'      => static function ( array $bundle ) {
					$php_version = isset( $bundle['site']['php_version'] )
						? (string) $bundle['site']['php_version']
						: null;

					if ( null === $php_version || '' === $php_version ) {
						return false;
					}

					return version_compare( $php_version, '7.4', '<' );
				},
			),

			// ----------------------------------------------------------
			// WCPG-X-004  WordPress < 6.0
			// ----------------------------------------------------------
			array(
				'id'            => 'WCPG-X-004',
				'title'         => 'WordPress version below recommended minimum (6.0)',
				'plain_english' => 'Your site is running an outdated WordPress version. Newer versions include security fixes and REST API improvements needed by the plugin.',
				'fix'           => 'Update WordPress from Dashboard → Updates.',
				'severity'      => self::SEV_WARNING,
				'config_only'   => false,
				'detector'      => static function ( array $bundle ) {
					$wp_version = isset( $bundle['site']['wp_version'] )
						? (string) $bundle['site']['wp_version']
						: null;

					if ( null === $wp_version || '' === $wp_version ) {
						return false;
					}

					return version_compare( $wp_version, '6.0', '<' );
				},
			),

			// ----------------------------------------------------------
			// WCPG-C-001  Crypto gateway enabled but Finvaro keys empty
			// ----------------------------------------------------------
			array(
				'id'            => 'WCPG-C-001',
				'title'         => 'Crypto gateway enabled without Finvaro API keys',
				'plain_english' => 'The Crypto gateway is enabled but you have not entered your Finvaro API keys. Customers who try to pay with crypto will see an error.',
				'fix'           => 'Either enter your Finvaro Public Key and Private Key in WooCommerce → Settings → Payments → Pay with Crypto, or disable the gateway.',
				'severity'      => self::SEV_WARNING,
				'config_only'   => true,
				'detector'      => static function ( array $bundle ) {
					$settings = isset( $bundle['gateways']['wcpg_crypto'] )
						? $bundle['gateways']['wcpg_crypto']
						: null;

					if ( ! is_array( $settings ) ) {
						return false;
					}
					if ( ( isset( $settings['enabled'] ) ? $settings['enabled'] : '' ) !== 'yes' ) {
						return false;
					}

					$public_key = isset( $settings['public_key'] ) ? (string) $settings['public_key'] : '';

					return $public_key === '[REDACTED:length=0]' || $public_key === '';
				},
			),

			// ----------------------------------------------------------
			// WCPG-E-001  E-Transfer delivery method set to 'none' while enabled
			// ----------------------------------------------------------
			array(
				'id'            => 'WCPG-E-001',
				'title'         => 'E-Transfer enabled but no delivery method selected',
				'plain_english' => 'E-Transfer is enabled but no delivery method is selected. Customers won\'t be able to check out with e-Transfer.',
				'fix'           => 'In WooCommerce → Settings → Payments → Interac e-Transfer, choose a delivery method (email, URL popup, or manual).',
				'severity'      => self::SEV_WARNING,
				'config_only'   => true,
				'detector'      => static function ( array $bundle ) {
					$settings = isset( $bundle['gateways']['digipay_etransfer'] )
						? $bundle['gateways']['digipay_etransfer']
						: null;

					if ( ! is_array( $settings ) ) {
						return false;
					}
					if ( ( isset( $settings['enabled'] ) ? $settings['enabled'] : '' ) !== 'yes' ) {
						return false;
					}

					$method = isset( $settings['delivery_method'] ) ? (string) $settings['delivery_method'] : '';
					return $method === 'none';
				},
			),

			// ----------------------------------------------------------
			// WCPG-S-001  Daily limit set but max ticket > daily limit
			// ----------------------------------------------------------
			array(
				'id'            => 'WCPG-S-001',
				'title'         => 'Maximum order amount exceeds daily transaction limit',
				'plain_english' => 'Your maximum order amount is higher than your daily transaction limit. Large orders will be declined even on a fresh day.',
				'fix'           => 'Reduce the Maximum Order Amount to be at most your Daily Transaction Limit in WooCommerce → Settings → Payments → Paygo → Credit Card.',
				'severity'      => self::SEV_WARNING,
				'config_only'   => false,
				'detector'      => static function ( array $bundle ) {
					// Prefer gateway settings; fall back to option_snapshots.
					$cc = isset( $bundle['gateways']['paygobillingcc'] )
						? $bundle['gateways']['paygobillingcc']
						: array();

					$max   = null;
					$daily = null;

					if ( is_array( $cc ) ) {
						if ( isset( $cc['max_ticket_size'] ) && (string) $cc['max_ticket_size'] !== '' ) {
							$max = (float) $cc['max_ticket_size'];
						}
						if ( isset( $cc['daily_limit'] ) && (string) $cc['daily_limit'] !== '' ) {
							$daily = (float) $cc['daily_limit'];
						}
					}

					// If not found in gateway settings, try option_snapshots.
					if ( null === $max || null === $daily ) {
						$snap = isset( $bundle['option_snapshots'] ) ? $bundle['option_snapshots'] : array();
						if ( is_array( $snap ) ) {
							if ( null === $max && isset( $snap['max_ticket_size'] ) ) {
								$max = (float) $snap['max_ticket_size'];
							}
							if ( null === $daily && isset( $snap['daily_limit'] ) ) {
								$daily = (float) $snap['daily_limit'];
							}
						}
					}

					if ( null === $max || null === $daily ) {
						return false;
					}

					// daily_limit of 0 means "no limit" — skip.
					if ( $daily <= 0 ) {
						return false;
					}

					return $max > $daily;
				},
			),

			// ----------------------------------------------------------
			// WCPG-X-005  Outbound IP probe failed
			// ----------------------------------------------------------
			array(
				'id'            => 'WCPG-X-005',
				'title'         => 'Outbound IP address unknown',
				'plain_english' => "The plugin couldn't determine your server's outbound IP address. This is not a blocker, but it means support can't verify firewall allow-listing for you.",
				'fix'           => 'No action required. If you\'re having connectivity issues, provide your server IP to Digipay support manually.',
				'severity'      => self::SEV_INFO,
				'config_only'   => false,
				'detector'      => static function ( array $bundle ) {
					if ( ! isset( $bundle['site'] ) || ! is_array( $bundle['site'] ) ) {
						return false;
					}

					$ip = isset( $bundle['site']['outbound_ip'] ) ? $bundle['site']['outbound_ip'] : null;
					return null === $ip || '' === (string) $ip;
				},
			),

		); // end return array.
	}

	/**
	 * Run all detectors against a full diagnostic bundle.
	 *
	 * Returns matched issues with the 'detector' field removed.
	 *
	 * @param array $bundle Full diagnostic bundle from WCPG_Context_Bundler::build().
	 * @return array Matched issues (each without 'detector' key).
	 */
	public static function detect_all( array $bundle ) {
		$matched = array();

		foreach ( self::all() as $issue ) {
			$detector = $issue['detector'];
			if ( is_callable( $detector ) && $detector( $bundle ) ) {
				unset( $issue['detector'] );
				$matched[] = $issue;
			}
		}

		return $matched;
	}

	/**
	 * Run only config-only detectors, using gateway settings as the sole input.
	 *
	 * @param array $gateway_settings_by_id Map of gateway ID => settings array (may be raw or pre-redacted).
	 * @return array Matched issues (each without 'detector' key).
	 */
	public static function detect_config_only( array $gateway_settings_by_id ) {
		$bundle  = array( 'gateways' => $gateway_settings_by_id );
		$matched = array();

		foreach ( self::all() as $issue ) {
			if ( ! $issue['config_only'] ) {
				continue;
			}
			$detector = $issue['detector'];
			if ( is_callable( $detector ) && $detector( $bundle ) ) {
				unset( $issue['detector'] );
				$matched[] = $issue;
			}
		}

		return $matched;
	}
}

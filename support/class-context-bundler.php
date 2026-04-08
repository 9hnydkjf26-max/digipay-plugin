<?php
/**
 * Digipay Context Bundler
 *
 * Collects a full diagnostic snapshot (config, logs, diagnostics, recent
 * failed orders, option snapshots) into a single array for export.
 *
 * Secrets are redacted, customer PII is scrubbed from log lines.
 *
 * @package DigipayMasterPlugin
 * @since 13.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a diagnostic bundle describing the plugin's current state.
 */
class WCPG_Context_Bundler {

	/**
	 * Gateway IDs we collect settings from.
	 *
	 * @var string[]
	 */
	const GATEWAY_IDS = array( 'paygobillingcc', 'digipay_etransfer', 'wcpg_crypto' );

	/**
	 * WooCommerce log sources the bundler reads.
	 *
	 * @var string[]
	 */
	const LOG_SOURCES = array(
		'digipay-postback',
		'etransfer-webhook',
		'digipay-etransfer',
		'wcpg_crypto',
	);

	/**
	 * Regex matching setting keys whose value must be redacted.
	 *
	 * @var string
	 */
	const REDACT_KEY_REGEX = '/key|secret|token|password|credential/i';

	/**
	 * Build the full diagnostic bundle.
	 *
	 * @return array
	 */
	public function build() {
		$bundle = array(
			'bundle_meta'          => $this->build_meta(),
			'site'                 => $this->build_site(),
			'environment'          => $this->build_environment(),
			'gateways'             => $this->build_gateways(),
			'encryption_key_status' => $this->build_encryption_key_status(),
			'diagnostics'          => $this->build_diagnostics(),
			'connectivity_tests'   => $this->build_connectivity_tests(),
			'webhook_health'       => $this->build_webhook_health(),
			'recent_failed_orders' => $this->build_recent_failed_orders(),
			'order_correlations'   => $this->build_order_correlations(),
			'logs'                 => $this->build_logs(),
			'events'               => $this->build_events(),
			'settings_changes'     => $this->build_settings_changes(),
			'option_snapshots'     => $this->build_option_snapshots(),
		);

		// Add content hash at the end (excludes itself).
		$bundle['bundle_meta']['content_sha256'] = hash( 'sha256', wp_json_encode( $bundle ) );

		return $bundle;
	}

	// ------------------------------------------------------------------
	// Sections
	// ------------------------------------------------------------------

	/**
	 * Bundle metadata.
	 *
	 * @return array
	 */
	protected function build_meta() {
		return array(
			'schema_version'    => 1,
			'bundle_id'         => $this->uuid4(),
			'generated_at_utc'  => gmdate( 'c' ),
			'generated_at_pt'   => function_exists( 'wcpg_get_pacific_date' )
				? wcpg_get_pacific_date( 'c' )
				: null,
			'generator'         => 'WCPG_Context_Bundler',
			'generator_version' => defined( 'WCPG_VERSION' ) ? WCPG_VERSION : 'unknown',
		);
	}

	/**
	 * Basic site + platform info.
	 *
	 * @return array
	 */
	protected function build_site() {
		global $wp_version, $wpdb;

		return array(
			'home_url'        => function_exists( 'home_url' ) ? home_url() : null,
			'site_url'        => function_exists( 'site_url' ) ? site_url() : null,
			'plugin_version'  => defined( 'WCPG_VERSION' ) ? WCPG_VERSION : null,
			'wp_version'      => isset( $wp_version ) ? $wp_version : null,
			'wc_version'      => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'php_version'     => PHP_VERSION,
			'server_software' => isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : null,
			'mysql_version'   => isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'db_version' )
				? $wpdb->db_version()
				: null,
			'timezone_string' => function_exists( 'get_option' ) ? get_option( 'timezone_string' ) : null,
			'active_theme'    => $this->active_theme_info(),
			'outbound_ip'     => $this->get_outbound_ip(),
		);
	}

	/**
	 * Plugin/environment info.
	 *
	 * @return array
	 */
	protected function build_environment() {
		$active_plugins = function_exists( 'get_option' ) ? get_option( 'active_plugins', array() ) : array();
		if ( ! is_array( $active_plugins ) ) {
			$active_plugins = array();
		}

		return array(
			'active_plugins' => array_values( $active_plugins ),
			'is_multisite'   => function_exists( 'is_multisite' ) ? is_multisite() : false,
			'ssl'            => function_exists( 'wcpg_check_ssl' ) ? wcpg_check_ssl() : null,
			'curl'           => function_exists( 'wcpg_check_curl' ) ? wcpg_check_curl() : null,
			'openssl'        => function_exists( 'wcpg_check_openssl' ) ? wcpg_check_openssl() : null,
		);
	}

	/**
	 * Per-gateway settings with secrets redacted.
	 *
	 * @return array
	 */
	protected function build_gateways() {
		$out = array();
		foreach ( self::GATEWAY_IDS as $id ) {
			$settings = function_exists( 'get_option' )
				? get_option( 'woocommerce_' . $id . '_settings', array() )
				: array();
			if ( ! is_array( $settings ) ) {
				$settings = array();
			}
			$out[ $id ] = self::redact_settings( $settings );
		}
		return $out;
	}

	/**
	 * Describe the encryption key without leaking it.
	 *
	 * @return array
	 */
	public function build_encryption_key_status() {
		$is_defined        = defined( 'DIGIPAY_ENCRYPTION_KEY' );
		$value             = $is_defined ? DIGIPAY_ENCRYPTION_KEY : '';
		// Mirror of the fallback in wcpg_get_encryption_key(); keep in sync.
		$fallback_default  = 'fluidcastplgpaygowoo22';
		$is_default        = ( ! $is_defined || empty( $value ) || $value === $fallback_default );

		return array(
			'constant_defined'           => $is_defined,
			'length'                     => is_string( $value ) ? strlen( $value ) : 0,
			'using_default'              => $is_default,
			'encryption_key_fingerprint' => $is_default
				? null
				: substr( hash( 'sha256', $value ), 0, 12 ),
		);
	}

	/**
	 * Stored + fresh diagnostic results.
	 *
	 * @return array
	 */
	protected function build_diagnostics() {
		return array(
			'stored_diagnostic_results' => function_exists( 'get_option' ) ? get_option( 'wcpg_diagnostic_results', null ) : null,
			'postback_stats'            => function_exists( 'get_option' ) ? get_option( 'wcpg_postback_stats', null ) : null,
			'api_last_test'             => function_exists( 'get_option' ) ? get_option( 'wcpg_api_last_test', null ) : null,
			'postback_url_test'         => function_exists( 'get_option' ) ? get_option( 'wcpg_postback_url_test', null ) : null,
			'fresh_run'                 => function_exists( 'wcpg_run_diagnostics' ) ? wcpg_run_diagnostics() : null,
		);
	}

	/**
	 * Fresh connectivity test results.
	 *
	 * @return array
	 */
	protected function build_connectivity_tests() {
		return array(
			'api_connection' => function_exists( 'wcpg_test_api_connection' ) ? wcpg_test_api_connection() : null,
			'postback_url'   => function_exists( 'wcpg_test_postback_url' ) ? wcpg_test_postback_url() : null,
		);
	}

	/**
	 * E-Transfer webhook health counters.
	 *
	 * @return array
	 */
	protected function build_webhook_health() {
		if ( class_exists( 'WCPG_ETransfer_Webhook_Handler' )
			&& method_exists( 'WCPG_ETransfer_Webhook_Handler', 'get_health_counters' ) ) {
			return WCPG_ETransfer_Webhook_Handler::get_health_counters();
		}
		return array();
	}

	/**
	 * Recent failed/stuck orders across our gateways.
	 *
	 * @return array
	 */
	protected function build_recent_failed_orders() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$args = array(
			'limit'        => 30,
			'status'       => array( 'failed', 'pending', 'on-hold', 'cancelled' ),
			'payment_method' => self::GATEWAY_IDS,
			'date_created' => '>' . ( time() - ( 14 * DAY_IN_SECONDS ) ),
			'return'       => 'objects',
		);

		$orders = wc_get_orders( $args );
		if ( ! is_array( $orders ) ) {
			return array();
		}

		$out = array();
		foreach ( $orders as $order ) {
			if ( ! is_object( $order ) ) {
				continue;
			}
			$out[] = array(
				'id'             => method_exists( $order, 'get_id' ) ? $order->get_id() : null,
				'status'         => method_exists( $order, 'get_status' ) ? $order->get_status() : null,
				'total'          => method_exists( $order, 'get_total' ) ? $order->get_total() : null,
				'currency'       => method_exists( $order, 'get_currency' ) ? $order->get_currency() : null,
				'payment_method' => method_exists( $order, 'get_payment_method' ) ? $order->get_payment_method() : null,
				'date_created'   => ( method_exists( $order, 'get_date_created' ) && $order->get_date_created() )
					? $order->get_date_created()->date( 'c' )
					: null,
				'paygo_transaction_id'    => method_exists( $order, 'get_meta' ) ? $order->get_meta( '_paygo_cc_transaction_id' ) : null,
				'paygo_status'            => method_exists( $order, 'get_meta' ) ? $order->get_meta( '_paygo_cc_transaction_status' ) : null,
				'etransfer_reference'     => method_exists( $order, 'get_meta' ) ? $order->get_meta( '_etransfer_reference' ) : null,
				'etransfer_status'        => method_exists( $order, 'get_meta' ) ? $order->get_meta( '_etransfer_transaction_status' ) : null,
			);
		}
		return $out;
	}

	/**
	 * Build per-order correlation data: all events and notes for each recent failed order.
	 *
	 * @return array
	 */
	protected function build_order_correlations() {
		$failed_orders = $this->build_recent_failed_orders();
		if ( empty( $failed_orders ) ) {
			return array();
		}

		// Pre-fetch event slices once to avoid repeated calls per order.
		$postback_events  = class_exists( 'WCPG_Event_Log' )
			? WCPG_Event_Log::recent( 500, WCPG_Event_Log::TYPE_POSTBACK )
			: array();
		$webhook_events   = class_exists( 'WCPG_Event_Log' )
			? WCPG_Event_Log::recent( 500, WCPG_Event_Log::TYPE_WEBHOOK )
			: array();
		$api_call_events  = class_exists( 'WCPG_Event_Log' )
			? WCPG_Event_Log::recent( 500, WCPG_Event_Log::TYPE_API_CALL )
			: array();

		$thirty_days_ago = time() - ( 30 * DAY_IN_SECONDS );
		$out             = array();

		foreach ( $failed_orders as $order_entry ) {
			$order_id = isset( $order_entry['id'] ) ? (int) $order_entry['id'] : 0;
			if ( $order_id <= 0 ) {
				continue;
			}

			$etransfer_ref = isset( $order_entry['etransfer_reference'] )
				? (string) $order_entry['etransfer_reference']
				: '';

			// --- Postback events ---
			$matched_postbacks = array();
			foreach ( $postback_events as $ev ) {
				if ( isset( $ev['order_id'] ) && (int) $ev['order_id'] === $order_id ) {
					$matched_postbacks[] = $ev;
				}
			}
			if ( count( $matched_postbacks ) > 50 ) {
				$matched_postbacks = array_slice( $matched_postbacks, -50 );
			}

			// --- Webhook events ---
			$matched_webhooks = array();
			foreach ( $webhook_events as $ev ) {
				$matches_order = isset( $ev['order_id'] ) && (int) $ev['order_id'] === $order_id;
				$matches_ref   = $etransfer_ref !== ''
					&& isset( $ev['data']['reference'] )
					&& (string) $ev['data']['reference'] === $etransfer_ref;
				if ( $matches_order || $matches_ref ) {
					$matched_webhooks[] = $ev;
				}
			}

			// --- API call events ---
			$matched_api = array();
			$order_id_str = (string) $order_id;
			foreach ( $api_call_events as $ev ) {
				$url_str  = isset( $ev['data']['url'] )          ? (string) $ev['data']['url']          : '';
				$body_str = isset( $ev['data']['body_preview'] ) ? (string) $ev['data']['body_preview'] : '';
				if ( false !== strpos( $url_str, $order_id_str )
					|| false !== strpos( $body_str, $order_id_str ) ) {
					$matched_api[] = $ev;
				}
			}
			if ( count( $matched_api ) > 20 ) {
				$matched_api = array_slice( $matched_api, -20 );
			}

			// --- Recent order notes (last 10, last 30 days) ---
			$recent_notes    = array();
			$status_history  = array();

			if ( function_exists( 'wc_get_order' ) ) {
				$order_obj = wc_get_order( $order_id );

				if ( $order_obj && is_object( $order_obj ) ) {
					// Notes.
					if ( method_exists( $order_obj, 'get_customer_order_notes' ) ) {
						$notes = $order_obj->get_customer_order_notes();
						if ( is_array( $notes ) ) {
							// Filter to last 30 days.
							foreach ( $notes as $note ) {
								$note_ts = is_object( $note ) && isset( $note->comment_date_gmt )
									? strtotime( $note->comment_date_gmt )
									: 0;
								if ( $note_ts < $thirty_days_ago ) {
									continue;
								}
								$content = is_object( $note ) && isset( $note->comment_content )
									? (string) $note->comment_content
									: '';
								$ts_str  = is_object( $note ) && isset( $note->comment_date_gmt )
									? $note->comment_date_gmt
									: '';
								$recent_notes[] = array(
									'ts'      => $ts_str,
									'content' => self::scrub_pii( $content ),
								);
							}
							// Most recent first, cap at 10.
							$recent_notes = array_reverse( $recent_notes );
							if ( count( $recent_notes ) > 10 ) {
								$recent_notes = array_slice( $recent_notes, 0, 10 );
							}
						}
					}

					// Status history.
					if ( method_exists( $order_obj, 'get_meta' ) ) {
						$history_meta = $order_obj->get_meta( '_status_transition_history' );
						if ( ! empty( $history_meta ) && is_array( $history_meta ) ) {
							$status_history = $history_meta;
						} elseif ( method_exists( $order_obj, 'get_status' )
							&& method_exists( $order_obj, 'get_date_modified' )
							&& $order_obj->get_date_modified() ) {
							$status_history = array(
								array(
									'status' => $order_obj->get_status(),
									'ts'     => $order_obj->get_date_modified()->format( 'c' ),
								),
							);
						}
					}
				}
			}

			$out[] = array(
				'order_id'        => $order_id,
				'status'          => isset( $order_entry['status'] )         ? $order_entry['status']         : null,
				'payment_method'  => isset( $order_entry['payment_method'] ) ? $order_entry['payment_method'] : null,
				'total'           => isset( $order_entry['total'] )          ? $order_entry['total']          : null,
				'currency'        => isset( $order_entry['currency'] )       ? $order_entry['currency']       : null,
				'date_created'    => isset( $order_entry['date_created'] )   ? $order_entry['date_created']   : null,
				'postback_events' => $matched_postbacks,
				'webhook_events'  => $matched_webhooks,
				'api_call_events' => $matched_api,
				'recent_notes'    => $recent_notes,
				'status_history'  => $status_history,
			);
		}

		return $out;
	}

	/**
	 * Read tail of each WooCommerce log source, with PII scrubbed.
	 *
	 * @return array
	 */
	protected function build_logs() {
		$out = array();
		foreach ( self::LOG_SOURCES as $source ) {
			$file  = $this->find_log_file( $source );
			$lines = $file ? $this->tail_file( $file, 500 ) : array();
			$out[ $source ] = array(
				'file'  => $file,
				'lines' => array_map( array( __CLASS__, 'scrub_pii' ), $lines ),
			);
		}
		return $out;
	}

	/**
	 * Snapshot of all wcpg_* options plus daily total transients.
	 *
	 * @return array
	 */
	protected function build_option_snapshots() {
		$snapshot = array();
		$keys     = array(
			'wcpg_instance_token',
			'wcpg_diagnostic_results',
			'wcpg_postback_stats',
			'wcpg_api_last_test',
			'wcpg_postback_url_test',
		);
		foreach ( $keys as $key ) {
			$snapshot[ $key ] = function_exists( 'get_option' ) ? get_option( $key, null ) : null;
		}

		if ( function_exists( 'wcpg_get_pacific_date' ) && function_exists( 'get_transient' ) ) {
			$today              = wcpg_get_pacific_date( 'Y-m-d' );
			$yesterday          = gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) );
			$snapshot['daily_total_today_pt']     = get_transient( 'wcpg_daily_total_' . $today );
			$snapshot['daily_total_yesterday_pt'] = get_transient( 'wcpg_daily_total_' . $yesterday );
		}

		return $snapshot;
	}

	/**
	 * Recent plugin events from the event log ring buffer.
	 *
	 * @return array
	 */
	protected function build_events() {
		return class_exists( 'WCPG_Event_Log' ) ? WCPG_Event_Log::recent( 200 ) : array();
	}

	/**
	 * Recent settings-change events (last 50) from the event log.
	 *
	 * @return array
	 */
	protected function build_settings_changes() {
		return class_exists( 'WCPG_Event_Log' )
			? WCPG_Event_Log::recent( 50, WCPG_Event_Log::TYPE_SETTINGS_CHANGE )
			: array();
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Recursively redact keys matching the secret regex.
	 *
	 * @param mixed $settings Input data (associative array expected).
	 * @return mixed
	 */
	public static function redact_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}
		$out = array();
		foreach ( $settings as $key => $value ) {
			if ( is_string( $key ) && preg_match( self::REDACT_KEY_REGEX, $key ) ) {
				$len = is_string( $value ) ? strlen( $value ) : 0;
				$out[ $key ] = '[REDACTED:length=' . $len . ']';
				continue;
			}
			if ( is_array( $value ) ) {
				$out[ $key ] = self::redact_settings( $value );
			} else {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Scrub PII (emails, phone, card PANs, JWTs) from a string.
	 *
	 * @param string $line Input line.
	 * @return string
	 */
	public static function scrub_pii( $line ) {
		if ( ! is_string( $line ) ) {
			return $line;
		}
		// Emails.
		$line = preg_replace( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[EMAIL]', $line );
		// JWT-ish (three base64url segments separated by dots, each >= 10 chars).
		$line = preg_replace( '/\b[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\b/', '[JWT]', $line );
		// Card-PAN-shaped digit runs (14-19 digits, optionally spaced/dashed).
		$line = preg_replace( '/\b(?:\d[ \-]?){13,18}\d\b/', '[CARD]', $line );
		// North American phone numbers.
		$line = preg_replace( '/\b\+?1?[\s\-\.]?\(?\d{3}\)?[\s\-\.]?\d{3}[\s\-\.]?\d{4}\b/', '[PHONE]', $line );
		return $line;
	}

	/**
	 * Locate the most recent log file for a WooCommerce log source.
	 *
	 * @param string $source Log source handle.
	 * @return string|null Absolute path or null if not found.
	 */
	protected function find_log_file( $source ) {
		if ( ! defined( 'WC_LOG_DIR' ) ) {
			return null;
		}
		$pattern = WC_LOG_DIR . $source . '-*.log';
		$matches = glob( $pattern );
		if ( empty( $matches ) ) {
			return null;
		}
		// Return newest.
		usort( $matches, function ( $a, $b ) {
			return filemtime( $b ) - filemtime( $a );
		} );
		return $matches[0];
	}

	/**
	 * Return the last $n lines of a file.
	 *
	 * @param string $path File path.
	 * @param int    $n    Number of lines.
	 * @return array
	 */
	protected function tail_file( $path, $n ) {
		if ( ! is_readable( $path ) ) {
			return array();
		}
		$lines = @file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( ! is_array( $lines ) ) {
			return array();
		}
		if ( count( $lines ) > $n ) {
			$lines = array_slice( $lines, -1 * $n );
		}
		return $lines;
	}

	/**
	 * Get the server's outbound IP (cached 1h).
	 *
	 * @return string|null
	 */
	protected function get_outbound_ip() {
		if ( function_exists( 'get_transient' ) ) {
			$cached = get_transient( 'wcpg_support_outbound_ip' );
			if ( $cached !== false && $cached !== null ) {
				return $cached;
			}
		}
		if ( ! function_exists( 'wp_remote_get' ) ) {
			return null;
		}
		$response = wp_remote_get( 'https://api.ipify.org', array( 'timeout' => 5 ) );
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			return null;
		}
		$body = '';
		if ( function_exists( 'wp_remote_retrieve_body' ) ) {
			$body = wp_remote_retrieve_body( $response );
		} elseif ( is_array( $response ) && isset( $response['body'] ) ) {
			$body = $response['body'];
		}
		$ip = trim( (string) $body );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}
		if ( function_exists( 'set_transient' ) ) {
			set_transient( 'wcpg_support_outbound_ip', $ip, HOUR_IN_SECONDS );
		}
		return $ip;
	}

	/**
	 * Active theme metadata if available.
	 *
	 * @return array
	 */
	protected function active_theme_info() {
		if ( function_exists( 'wp_get_theme' ) ) {
			$theme = wp_get_theme();
			if ( is_object( $theme ) ) {
				return array(
					'name'    => method_exists( $theme, 'get' ) ? $theme->get( 'Name' ) : null,
					'version' => method_exists( $theme, 'get' ) ? $theme->get( 'Version' ) : null,
				);
			}
		}
		return array( 'name' => null, 'version' => null );
	}

	/**
	 * Generate a v4 UUID.
	 *
	 * @return string
	 */
	protected function uuid4() {
		try {
			$data    = random_bytes( 16 );
			$data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 );
			$data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 );
			return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
		} catch ( \Exception $e ) {
			return uniqid( 'wcpg-', true );
		}
	}
}

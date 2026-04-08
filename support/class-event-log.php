<?php
/**
 * Digipay Event Log
 *
 * Lightweight ring buffer that captures plugin-level events (postbacks,
 * webhook receipts, order transitions, API calls, etc.) in a single
 * wp_option. Entries are trimmed to MAX_ENTRIES to bound storage.
 *
 * @package DigipayMasterPlugin
 * @since 13.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static event log backed by a wp_option ring buffer.
 */
class WCPG_Event_Log {

	// ------------------------------------------------------------------
	// Event type constants
	// ------------------------------------------------------------------

	const TYPE_POSTBACK        = 'postback';
	const TYPE_WEBHOOK         = 'webhook';
	const TYPE_ORDER_TRANSITION = 'order_transition';
	const TYPE_API_CALL        = 'api_call';
	const TYPE_LIMITS_REFRESH  = 'limits_refresh';
	const TYPE_SETTINGS_CHANGE = 'settings_change';
	const TYPE_CRITICAL        = 'critical';

	// ------------------------------------------------------------------
	// Storage constants
	// ------------------------------------------------------------------

	/**
	 * Maximum number of entries retained in the ring buffer.
	 */
	const MAX_ENTRIES = 500;

	/**
	 * wp_options key used to store the log.
	 */
	const OPTION_KEY = 'wcpg_event_log';

	// ------------------------------------------------------------------
	// Public API
	// ------------------------------------------------------------------

	/**
	 * Append a new entry to the event log.
	 *
	 * If the log exceeds MAX_ENTRIES after appending, the oldest entries
	 * are trimmed so that only the most recent MAX_ENTRIES are kept.
	 *
	 * @param string      $type     One of the TYPE_* constants.
	 * @param array       $data     Arbitrary key/value context data.
	 * @param string|null $gateway  Gateway ID (e.g. 'paygobillingcc').
	 * @param int|null    $order_id WooCommerce order ID, if applicable.
	 * @return void
	 */
	public static function record( $type, array $data = array(), $gateway = null, $order_id = null ) {
		$log = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = array(
			'ts'       => gmdate( 'c' ),
			'type'     => $type,
			'gateway'  => $gateway,
			'order_id' => $order_id,
			'data'     => $data,
		);

		if ( count( $log ) > self::MAX_ENTRIES ) {
			$log = array_slice( $log, -1 * self::MAX_ENTRIES );
		}

		update_option( self::OPTION_KEY, $log, false );
	}

	/**
	 * Retrieve recent log entries.
	 *
	 * @param int         $limit Maximum number of entries to return (from the end).
	 * @param string|null $type  If non-null, only entries with this type are returned.
	 * @return array Indexed array of log entries, oldest first within the result set.
	 */
	public static function recent( $limit = 100, $type = null ) {
		$log = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $log ) ) {
			return array();
		}

		if ( null !== $type ) {
			$log = array_values( array_filter( $log, function ( $entry ) use ( $type ) {
				return isset( $entry['type'] ) && $entry['type'] === $type;
			} ) );
		}

		if ( count( $log ) > $limit ) {
			$log = array_slice( $log, -1 * $limit );
		}

		return array_values( $log );
	}

	/**
	 * Delete all log entries.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION_KEY );
	}
}

/**
 * Redact sensitive query-string parameter values from a URL.
 *
 * Any parameter whose KEY matches /key|secret|token|password|credential/i
 * has its value replaced with [REDACTED]. Host and path are unchanged.
 *
 * @param string $url Input URL.
 * @return string URL with sensitive query-param values redacted.
 */
function wcpg_redact_url_query( $url ) {
	if ( ! is_string( $url ) || '' === $url ) {
		return $url;
	}

	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) ) {
		// Unparseable URL — return as-is.
		return $url;
	}

	if ( empty( $parts['query'] ) ) {
		return $url;
	}

	// Parse query string into key => value pairs.
	parse_str( $parts['query'], $query_params );

	$redact_regex = '/key|secret|token|password|credential/i';
	$changed      = false;
	foreach ( $query_params as $k => $v ) {
		if ( preg_match( $redact_regex, $k ) ) {
			$query_params[ $k ] = '[REDACTED]';
			$changed            = true;
		}
	}

	if ( ! $changed ) {
		return $url;
	}

	// Rebuild URL from parts.
	$rebuilt = '';
	if ( ! empty( $parts['scheme'] ) ) {
		$rebuilt .= $parts['scheme'] . '://';
	}
	if ( ! empty( $parts['host'] ) ) {
		$rebuilt .= $parts['host'];
	}
	if ( ! empty( $parts['port'] ) ) {
		$rebuilt .= ':' . $parts['port'];
	}
	if ( ! empty( $parts['path'] ) ) {
		$rebuilt .= $parts['path'];
	}
	$rebuilt .= '?' . http_build_query( $query_params );
	if ( ! empty( $parts['fragment'] ) ) {
		$rebuilt .= '#' . $parts['fragment'];
	}

	return $rebuilt;
}

/**
 * Drop-in wrapper around wp_remote_request that times the request and
 * records a TYPE_API_CALL event to WCPG_Event_Log.
 *
 * @param string $url  Request URL.
 * @param array  $args wp_remote_request args (method, headers, body, timeout, …).
 * @return array|WP_Error Raw wp_remote_request response.
 */
function wcpg_http_request( $url, $args = array() ) {
	$start  = microtime( true );
	$response = wp_remote_request( $url, $args );
	$elapsed  = (int) ( ( microtime( true ) - $start ) * 1000 );

	$method = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';

	// Safely extract status, body, and error depending on WP_Error vs normal response.
	if ( is_wp_error( $response ) ) {
		$status        = 0;
		$body_raw      = '';
		$error_message = $response->get_error_message();
	} else {
		$code   = wp_remote_retrieve_response_code( $response );
		$status = ( is_numeric( $code ) && '' !== $code ) ? (int) $code : 0;

		$body_raw      = (string) wp_remote_retrieve_body( $response );
		$error_message = '';
	}

	// Redact the URL.
	$redacted_url = wcpg_redact_url_query( $url );

	// Build a PII-scrubbed body preview (max 500 chars).
	$preview = substr( $body_raw, 0, 500 );
	if ( class_exists( 'WCPG_Context_Bundler' ) && method_exists( 'WCPG_Context_Bundler', 'scrub_pii' ) ) {
		$preview = WCPG_Context_Bundler::scrub_pii( $preview );
	}

	// Record event (guard against load-order issues).
	if ( class_exists( 'WCPG_Event_Log' ) ) {
		WCPG_Event_Log::record(
			WCPG_Event_Log::TYPE_API_CALL,
			array(
				'method'       => $method,
				'url'          => $redacted_url,
				'status'       => $status,
				'elapsed_ms'   => $elapsed,
				'body_preview' => $preview,
				'error'        => $error_message,
			),
			null,
			null
		);
	}

	return $response;
}

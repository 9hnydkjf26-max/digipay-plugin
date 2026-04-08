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

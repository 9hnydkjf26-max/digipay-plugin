<?php
/**
 * Digipay Settings Change Watcher
 *
 * Hooks into WooCommerce gateway option updates and records field-level diffs
 * to the WCPG_Event_Log ring buffer. Raw values are never stored; only 8-char
 * SHA-1 hashes and empty-state flags are persisted.
 *
 * @package DigipayMasterPlugin
 * @since 13.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Watches gateway settings updates and records field-level change events.
 */
class WCPG_Settings_Change_Watcher {

	/**
	 * Map of WooCommerce option name suffix => gateway ID.
	 *
	 * @var array<string,string>
	 */
	const OPTION_GATEWAY_MAP = array(
		'woocommerce_paygobillingcc_settings'     => 'paygobillingcc',
		'woocommerce_digipay_etransfer_settings'  => 'digipay_etransfer',
		'woocommerce_wcpg_crypto_settings'        => 'wcpg_crypto',
	);

	/**
	 * Register WordPress hooks for all three gateway option names.
	 *
	 * @return void
	 */
	public function register() {
		foreach ( self::OPTION_GATEWAY_MAP as $option => $gateway ) {
			// Capture $gateway in closure scope.
			$gw = $gateway;
			add_action(
				'update_option_' . $option,
				function ( $old_value, $value ) use ( $gw ) {
					self::diff_and_record( $gw, $old_value, $value );
				},
				10,
				2
			);
		}
	}

	/**
	 * Compute the field-level diff between $old_value and $new_value and record
	 * a TYPE_SETTINGS_CHANGE event for every changed, added, or removed key.
	 *
	 * This is the public static entry point used both by register() closures and
	 * directly in tests.
	 *
	 * @param string $gateway   Gateway ID (e.g. 'paygobillingcc').
	 * @param mixed  $old_value Previous option value.
	 * @param mixed  $new_value New option value.
	 * @return void
	 */
	public static function diff_and_record( $gateway, $old_value, $new_value ) {
		// Guard: if either value is not an array, skip gracefully.
		if ( ! is_array( $old_value ) && ! is_array( $new_value ) ) {
			return;
		}

		// Treat non-array sides as empty arrays so we can still detect additions/removals.
		if ( ! is_array( $old_value ) ) {
			// Unusual WP state (e.g. option never set) — skip entirely per spec.
			return;
		}
		if ( ! is_array( $new_value ) ) {
			return;
		}

		if ( ! class_exists( 'WCPG_Event_Log' ) ) {
			return;
		}

		$all_keys = array_unique( array_merge( array_keys( $old_value ), array_keys( $new_value ) ) );

		foreach ( $all_keys as $field ) {
			$has_old = array_key_exists( $field, $old_value );
			$has_new = array_key_exists( $field, $new_value );

			$old_field = $has_old ? $old_value[ $field ] : null;
			$new_field = $has_new ? $new_value[ $field ] : null;

			// Skip fields that are identical in both (and present in both).
			if ( $has_old && $has_new && $old_field === $new_field ) {
				continue;
			}

			// Compute hashes (or sentinel for missing keys).
			if ( $has_old ) {
				$old_str  = is_array( $old_field ) ? wp_json_encode( $old_field ) : (string) $old_field;
				$old_hash = substr( sha1( $old_str ), 0, 8 );
			} else {
				$old_hash = '(missing)';
				$old_str  = null;
			}

			if ( $has_new ) {
				$new_str  = is_array( $new_field ) ? wp_json_encode( $new_field ) : (string) $new_field;
				$new_hash = substr( sha1( $new_str ), 0, 8 );
			} else {
				$new_hash = '(missing)';
				$new_str  = null;
			}

			// Compute was_empty / now_empty flags.
			$was_empty = $has_old ? ( $old_field === '' || $old_field === null ) : true;
			$now_empty = $has_new ? ( $new_field === '' || $new_field === null ) : true;

			WCPG_Event_Log::record(
				WCPG_Event_Log::TYPE_SETTINGS_CHANGE,
				array(
					'field'     => $field,
					'old_hash'  => $old_hash,
					'new_hash'  => $new_hash,
					'was_empty' => $was_empty,
					'now_empty' => $now_empty,
				),
				$gateway,
				null
			);
		}
	}

	/**
	 * Resolve the gateway ID from an option name.
	 *
	 * @param string $option_name Full option name.
	 * @return string|null Gateway ID or null if unrecognised.
	 */
	public static function gateway_from_option( $option_name ) {
		return isset( self::OPTION_GATEWAY_MAP[ $option_name ] )
			? self::OPTION_GATEWAY_MAP[ $option_name ]
			: null;
	}
}

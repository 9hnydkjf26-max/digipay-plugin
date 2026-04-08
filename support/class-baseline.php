<?php
/**
 * Digipay Healthy Baseline
 *
 * Records a snapshot of key metrics after a successful zero-issue diagnose run,
 * and provides comparison deltas on subsequent bundle builds.
 *
 * @package DigipayMasterPlugin
 * @since 13.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and compares healthy-baseline metric snapshots.
 *
 * All methods are static; the class is never instantiated.
 */
class WCPG_Baseline {

	/**
	 * WordPress option key used to persist the baseline.
	 */
	const OPTION_KEY = 'wcpg_healthy_baseline';

	/**
	 * Current snapshot schema version.
	 */
	const SCHEMA_VERSION = 1;

	// ------------------------------------------------------------------
	// Public API
	// ------------------------------------------------------------------

	/**
	 * Record a healthy baseline from the given bundle.
	 *
	 * Should be called only when WCPG_Issue_Catalog::detect_all() returns an
	 * empty array (zero issues detected).
	 *
	 * @param array $bundle Bundle array produced by WCPG_Context_Bundler::build().
	 */
	public static function record( array $bundle ) {
		$snapshot = array(
			'recorded_at'          => gmdate( 'c' ),
			'schema_version'       => self::SCHEMA_VERSION,
			'api_response_time_ms' => self::extract_api_response_time( $bundle ),
			'postback_success_rate' => self::extract_postback_success_rate( $bundle ),
			'webhook_processed'    => isset( $bundle['webhook_health']['processed'] )
				? (int) $bundle['webhook_health']['processed']
				: 0,
			'webhook_hmac_fail'    => isset( $bundle['webhook_health']['hmac_fail'] )
				? (int) $bundle['webhook_health']['hmac_fail']
				: 0,
			'php_memory_peak_mb'   => isset( $bundle['environment_detail']['php_memory_peak_mb'] )
				? $bundle['environment_detail']['php_memory_peak_mb']
				: null,
		);

		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_KEY, $snapshot, false );
		}
	}

	/**
	 * Read the stored baseline snapshot.
	 *
	 * @return array|null Snapshot array, or null if no baseline is stored.
	 */
	public static function read() {
		if ( ! function_exists( 'get_option' ) ) {
			return null;
		}

		$stored = get_option( self::OPTION_KEY, null );

		if ( ! is_array( $stored ) ) {
			return null;
		}

		return $stored;
	}

	/**
	 * Compare the current bundle against the stored baseline.
	 *
	 * Accepts a partial bundle that must contain at least: `diagnostics`,
	 * `webhook_health`, `environment_detail`. Does NOT call build() internally
	 * to avoid recursion.
	 *
	 * @param array $bundle Current (partial or full) bundle array.
	 * @return array Delta map. Contains `available=false` when no baseline exists.
	 */
	public static function compare( array $bundle ) {
		$baseline = self::read();

		if ( null === $baseline ) {
			return array(
				'baseline_recorded_at' => null,
				'available'            => false,
			);
		}

		$current_metrics = array(
			'api_response_time_ms'  => self::extract_api_response_time( $bundle ),
			'postback_success_rate' => self::extract_postback_success_rate( $bundle ),
			'webhook_processed'     => isset( $bundle['webhook_health']['processed'] )
				? (int) $bundle['webhook_health']['processed']
				: null,
			'webhook_hmac_fail'     => isset( $bundle['webhook_health']['hmac_fail'] )
				? (int) $bundle['webhook_health']['hmac_fail']
				: null,
			'php_memory_peak_mb'    => isset( $bundle['environment_detail']['php_memory_peak_mb'] )
				? $bundle['environment_detail']['php_memory_peak_mb']
				: null,
		);

		$out = array(
			'baseline_recorded_at' => isset( $baseline['recorded_at'] ) ? $baseline['recorded_at'] : null,
			'available'            => true,
		);

		$metric_keys = array(
			'api_response_time_ms',
			'postback_success_rate',
			'webhook_processed',
			'webhook_hmac_fail',
			'php_memory_peak_mb',
		);

		foreach ( $metric_keys as $key ) {
			$baseline_key = $key;
			// Handle the stored key naming: webhook_processed / webhook_hmac_fail match directly.
			// For metrics not found in snapshot, fall back to null.
			$baseline_val = isset( $baseline[ $baseline_key ] ) ? $baseline[ $baseline_key ] : null;
			$current_val  = isset( $current_metrics[ $key ] )   ? $current_metrics[ $key ]   : null;

			$out[ $key ] = array(
				'current'   => $current_val,
				'baseline'  => $baseline_val,
				'delta_pct' => self::delta_pct( $current_val, $baseline_val ),
			);
		}

		return $out;
	}

	/**
	 * Delete the stored baseline option.
	 */
	public static function clear() {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::OPTION_KEY );
		}
	}

	// ------------------------------------------------------------------
	// Private helpers
	// ------------------------------------------------------------------

	/**
	 * Extract API response time (ms) from a bundle.
	 *
	 * @param array $bundle Bundle array.
	 * @return int|null
	 */
	private static function extract_api_response_time( array $bundle ) {
		$val = isset( $bundle['diagnostics']['api_last_test']['response_time_ms'] )
			? $bundle['diagnostics']['api_last_test']['response_time_ms']
			: null;

		return null !== $val ? (int) $val : null;
	}

	/**
	 * Compute postback success rate (float 0.0–1.0) from bundle postback stats.
	 *
	 * Returns null when there are no postback records (total = 0).
	 *
	 * @param array $bundle Bundle array.
	 * @return float|null
	 */
	private static function extract_postback_success_rate( array $bundle ) {
		$stats = isset( $bundle['diagnostics']['postback_stats'] )
			? $bundle['diagnostics']['postback_stats']
			: null;

		if ( ! is_array( $stats ) ) {
			return null;
		}

		$success = isset( $stats['success_count'] ) ? (int) $stats['success_count'] : 0;
		$errors  = isset( $stats['error_count'] )   ? (int) $stats['error_count']   : 0;
		$total   = $success + $errors;

		if ( $total <= 0 ) {
			return null;
		}

		return round( $success / $total, 4 );
	}

	/**
	 * Compute delta percentage between current and baseline values.
	 *
	 * Formula: ($current - $baseline) / $baseline * 100, rounded to 1 decimal.
	 * Returns null when baseline is null or zero (no division possible).
	 *
	 * @param float|int|null $current  Current metric value.
	 * @param float|int|null $baseline Baseline metric value.
	 * @return float|null
	 */
	private static function delta_pct( $current, $baseline ) {
		if ( null === $baseline || 0 == $baseline ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
			return null;
		}
		if ( null === $current ) {
			return null;
		}
		return round( ( (float) $current - (float) $baseline ) / (float) $baseline * 100, 1 );
	}
}

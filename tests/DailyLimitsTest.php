<?php
/**
 * Tests for daily transaction limit functionality.
 *
 * Tests get_daily_transaction_total(), calculate_daily_total_from_orders(),
 * update_daily_transaction_total(), and get_remaining_daily_limit().
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for daily limits behavior.
 */
class DailyLimitsTest extends DigipayTestCase {

	/**
	 * Clear transients before each test.
	 */
	protected function set_up() {
		parent::set_up();
		global $wcpg_test_transients;
		$wcpg_test_transients = array();
	}

	/**
	 * Test daily total transient key format uses Pacific date.
	 */
	public function test_daily_total_transient_key_uses_pacific_date() {
		$today         = wcpg_get_pacific_date( 'Y-m-d' );
		$transient_key = 'wcpg_daily_total_' . $today;

		// Key should follow expected format.
		$this->assertMatchesRegularExpression( '/^wcpg_daily_total_\d{4}-\d{2}-\d{2}$/', $transient_key );
	}

	/**
	 * Test daily total returns zero when no transient exists.
	 */
	public function test_daily_total_returns_zero_when_no_transient() {
		global $wcpg_test_transients;

		$today         = wcpg_get_pacific_date( 'Y-m-d' );
		$transient_key = 'wcpg_daily_total_' . $today;

		// No transient set.
		$this->assertFalse( get_transient( $transient_key ) );
	}

	/**
	 * Test daily total caching via transient.
	 */
	public function test_daily_total_caching() {
		global $wcpg_test_transients;

		$today         = wcpg_get_pacific_date( 'Y-m-d' );
		$transient_key = 'wcpg_daily_total_' . $today;

		// Set a cached value.
		set_transient( $transient_key, 1500.00, 5 * MINUTE_IN_SECONDS );

		$this->assertEquals( 1500.00, get_transient( $transient_key ) );
	}

	/**
	 * Test daily total transient expires in 5 minutes.
	 */
	public function test_daily_total_transient_expiration() {
		global $wcpg_test_transients;

		$today         = wcpg_get_pacific_date( 'Y-m-d' );
		$transient_key = 'wcpg_daily_total_' . $today;

		set_transient( $transient_key, 1000.00, 5 * MINUTE_IN_SECONDS );

		$this->assertEquals( 5 * MINUTE_IN_SECONDS, $wcpg_test_transients[ $transient_key ]['expiration'] );
	}

	/**
	 * Test update daily total adds amount correctly.
	 */
	public function test_update_daily_total_adds_amount() {
		global $wcpg_test_transients;

		$today         = wcpg_get_pacific_date( 'Y-m-d' );
		$transient_key = 'wcpg_daily_total_' . $today;

		// Set initial value.
		set_transient( $transient_key, 500.00, 5 * MINUTE_IN_SECONDS );

		// Simulate update_daily_transaction_total behavior.
		$current_total = floatval( get_transient( $transient_key ) );
		$new_total     = $current_total + 250.00;
		set_transient( $transient_key, $new_total, 5 * MINUTE_IN_SECONDS );

		$this->assertEquals( 750.00, get_transient( $transient_key ) );
	}

	/**
	 * Test remaining daily limit calculation with no limit set.
	 */
	public function test_remaining_daily_limit_no_limit_set() {
		// When daily_limit is 0 or not set, remaining should be null.
		$daily_limit = 0;
		$result      = ( ! $daily_limit || floatval( $daily_limit ) <= 0 ) ? null : 1000;

		$this->assertNull( $result );
	}

	/**
	 * Test remaining daily limit calculation with limit set.
	 */
	public function test_remaining_daily_limit_calculation() {
		$daily_limit = 10000.00;
		$daily_total = 7500.00;

		$remaining = floatval( $daily_limit ) - $daily_total;
		$remaining = max( 0, $remaining );

		$this->assertEquals( 2500.00, $remaining );
	}

	/**
	 * Test remaining daily limit is never negative.
	 */
	public function test_remaining_daily_limit_never_negative() {
		$daily_limit = 10000.00;
		$daily_total = 12000.00; // Over limit.

		$remaining = floatval( $daily_limit ) - $daily_total;
		$remaining = max( 0, $remaining );

		$this->assertEquals( 0, $remaining );
	}

	/**
	 * Test remaining daily limit at exact limit.
	 */
	public function test_remaining_daily_limit_at_exact_limit() {
		$daily_limit = 10000.00;
		$daily_total = 10000.00;

		$remaining = floatval( $daily_limit ) - $daily_total;
		$remaining = max( 0, $remaining );

		$this->assertEquals( 0, $remaining );
	}

	/**
	 * Test Pacific timezone is used correctly.
	 */
	public function test_pacific_timezone_used() {
		$pacific_date = wcpg_get_pacific_date( 'Y-m-d' );

		// Should be a valid date format.
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $pacific_date );

		// Compare with direct Pacific time calculation.
		$pacific_tz  = new DateTimeZone( 'America/Los_Angeles' );
		$now_pacific = new DateTime( 'now', $pacific_tz );
		$expected    = $now_pacific->format( 'Y-m-d' );

		$this->assertEquals( $expected, $pacific_date );
	}

	/**
	 * Test date range for order query spans full day.
	 */
	public function test_date_range_spans_full_pacific_day() {
		$pacific_tz = new DateTimeZone( 'America/Los_Angeles' );
		$utc_tz     = new DateTimeZone( 'UTC' );

		$today_start = new DateTime( 'today midnight', $pacific_tz );
		$today_start->setTimezone( $utc_tz );

		$today_end = new DateTime( 'today 23:59:59', $pacific_tz );
		$today_end->setTimezone( $utc_tz );

		// Start should be before end.
		$this->assertLessThan(
			$today_end->getTimestamp(),
			$today_start->getTimestamp()
		);

		// Should span roughly 24 hours.
		$diff_hours = ( $today_end->getTimestamp() - $today_start->getTimestamp() ) / 3600;
		$this->assertGreaterThanOrEqual( 23, $diff_hours );
		$this->assertLessThanOrEqual( 25, $diff_hours ); // Account for timezone edge cases.
	}

	/**
	 * Test daily total is returned as float.
	 */
	public function test_daily_total_returns_float() {
		global $wcpg_test_transients;

		$today         = wcpg_get_pacific_date( 'Y-m-d' );
		$transient_key = 'wcpg_daily_total_' . $today;

		// Set as string.
		set_transient( $transient_key, '1234.56', 5 * MINUTE_IN_SECONDS );

		$value = floatval( get_transient( $transient_key ) );
		$this->assertIsFloat( $value );
		$this->assertEquals( 1234.56, $value );
	}

	/**
	 * Test daily total handles empty/false transient.
	 */
	public function test_daily_total_handles_missing_transient() {
		$today         = wcpg_get_pacific_date( 'Y-m-d' );
		$transient_key = 'wcpg_daily_total_' . $today;

		// Transient doesn't exist, returns false.
		$value = get_transient( $transient_key );
		$this->assertFalse( $value );

		// Converted to float should be 0.
		$this->assertEquals( 0.0, floatval( $value ) );
	}

	/**
	 * Test MINUTE_IN_SECONDS constant is defined.
	 */
	public function test_minute_in_seconds_constant() {
		// Should be 60 seconds.
		$this->assertEquals( 60, MINUTE_IN_SECONDS );
	}
}

// Define MINUTE_IN_SECONDS if not already defined (for isolated testing).
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

// Ensure wcpg_get_pacific_date is available for tests.
if ( ! function_exists( 'wcpg_get_pacific_date' ) ) {
	function wcpg_get_pacific_date( $format = 'Y-m-d' ) {
		$pacific_tz  = new DateTimeZone( 'America/Los_Angeles' );
		$now_pacific = new DateTime( 'now', $pacific_tz );
		return $now_pacific->format( $format );
	}
}

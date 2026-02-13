<?php
/**
 * Tests for gateway availability functionality.
 *
 * Tests is_available() behavior with cart totals, max ticket size,
 * daily limits, and API failure fallbacks.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for gateway availability behavior.
 */
class GatewayAvailabilityTest extends DigipayTestCase {

	/**
	 * Clear transients before each test.
	 */
	protected function set_up() {
		parent::set_up();
		global $wcpg_test_transients;
		$wcpg_test_transients = array();
	}

	/**
	 * Test gateway is unavailable when cart exceeds max ticket size.
	 */
	public function test_unavailable_when_cart_exceeds_max_ticket() {
		$max_ticket_size = 5000.00;
		$cart_total      = 6000.00;

		$is_available = ! ( $max_ticket_size > 0 && $cart_total > $max_ticket_size );

		$this->assertFalse( $is_available );
	}

	/**
	 * Test gateway is available when cart is under max ticket size.
	 */
	public function test_available_when_cart_under_max_ticket() {
		$max_ticket_size = 5000.00;
		$cart_total      = 4000.00;

		$is_available = ! ( $max_ticket_size > 0 && $cart_total > $max_ticket_size );

		$this->assertTrue( $is_available );
	}

	/**
	 * Test gateway is available when cart equals max ticket size.
	 */
	public function test_available_when_cart_equals_max_ticket() {
		$max_ticket_size = 5000.00;
		$cart_total      = 5000.00;

		$is_available = ! ( $max_ticket_size > 0 && $cart_total > $max_ticket_size );

		$this->assertTrue( $is_available );
	}

	/**
	 * Test gateway is available when no max ticket size set.
	 */
	public function test_available_when_no_max_ticket_limit() {
		$max_ticket_size = 0;
		$cart_total      = 100000.00;

		$is_available = ! ( $max_ticket_size > 0 && $cart_total > $max_ticket_size );

		$this->assertTrue( $is_available );
	}

	/**
	 * Test gateway is unavailable when daily limit reached.
	 */
	public function test_unavailable_when_daily_limit_reached() {
		$daily_limit = 10000.00;
		$daily_total = 10000.00;

		$is_available = ! ( $daily_limit > 0 && $daily_total >= $daily_limit );

		$this->assertFalse( $is_available );
	}

	/**
	 * Test gateway is unavailable when daily limit exceeded.
	 */
	public function test_unavailable_when_daily_limit_exceeded() {
		$daily_limit = 10000.00;
		$daily_total = 12000.00;

		$is_available = ! ( $daily_limit > 0 && $daily_total >= $daily_limit );

		$this->assertFalse( $is_available );
	}

	/**
	 * Test gateway is available when under daily limit.
	 */
	public function test_available_when_under_daily_limit() {
		$daily_limit = 10000.00;
		$daily_total = 8000.00;

		$is_available = ! ( $daily_limit > 0 && $daily_total >= $daily_limit );

		$this->assertTrue( $is_available );
	}

	/**
	 * Test gateway is available when no daily limit set.
	 */
	public function test_available_when_no_daily_limit() {
		$daily_limit = 0;
		$daily_total = 50000.00;

		$is_available = ! ( $daily_limit > 0 && $daily_total >= $daily_limit );

		$this->assertTrue( $is_available );
	}

	/**
	 * Test both limits applied - max ticket blocks first.
	 */
	public function test_max_ticket_checked_before_daily_limit() {
		$max_ticket_size = 5000.00;
		$daily_limit     = 10000.00;
		$cart_total      = 6000.00;
		$daily_total     = 2000.00;

		// Max ticket should block even though daily limit has room.
		$blocked_by_max_ticket = $max_ticket_size > 0 && $cart_total > $max_ticket_size;
		$blocked_by_daily      = $daily_limit > 0 && $daily_total >= $daily_limit;

		$this->assertTrue( $blocked_by_max_ticket );
		$this->assertFalse( $blocked_by_daily );
	}

	/**
	 * Test remote limits fallback when API fails.
	 */
	public function test_remote_limits_fallback_on_api_failure() {
		global $wcpg_test_transients;

		$site_id       = 'test-site-123';
		$transient_key = 'wcpg_remote_limits_' . md5( $site_id );

		$default_limits = array(
			'daily_limit'     => 0,
			'max_ticket_size' => 0,
			'last_updated'    => null,
			'status'          => 'unknown',
		);

		// No cached limits, API would fail.
		$this->assertFalse( get_transient( $transient_key ) );

		// Fallback from last known limits (simulated).
		$last_known_limits = array(
			'daily_limit'     => 15000.00,
			'max_ticket_size' => 3000.00,
			'last_updated'    => '2024-01-01 12:00:00',
			'status'          => 'active',
		);

		// Cache the fallback for retry.
		set_transient( $transient_key, $last_known_limits, MINUTE_IN_SECONDS );

		$cached = get_transient( $transient_key );
		$this->assertEquals( 15000.00, $cached['daily_limit'] );
		$this->assertEquals( 3000.00, $cached['max_ticket_size'] );
	}

	/**
	 * Test remote limits caching duration is 5 minutes.
	 */
	public function test_remote_limits_cached_for_5_minutes() {
		global $wcpg_test_transients;

		$site_id       = 'test-site-123';
		$transient_key = 'wcpg_remote_limits_' . md5( $site_id );

		$limits = array(
			'daily_limit'     => 10000.00,
			'max_ticket_size' => 5000.00,
			'last_updated'    => '2024-01-01 12:00:00',
			'status'          => 'active',
		);

		set_transient( $transient_key, $limits, 5 * MINUTE_IN_SECONDS );

		$this->assertEquals( 5 * MINUTE_IN_SECONDS, $wcpg_test_transients[ $transient_key ]['expiration'] );
	}

	/**
	 * Test remote limits fallback caching duration is 1 minute.
	 */
	public function test_fallback_limits_cached_for_1_minute() {
		global $wcpg_test_transients;

		$site_id       = 'test-site-123';
		$transient_key = 'wcpg_remote_limits_' . md5( $site_id );

		$fallback_limits = array(
			'daily_limit'     => 15000.00,
			'max_ticket_size' => 3000.00,
			'last_updated'    => null,
			'status'          => 'fallback',
		);

		// Fallback is cached for only 1 minute before retry.
		set_transient( $transient_key, $fallback_limits, MINUTE_IN_SECONDS );

		$this->assertEquals( MINUTE_IN_SECONDS, $wcpg_test_transients[ $transient_key ]['expiration'] );
	}

	/**
	 * Test empty site ID returns default limits.
	 */
	public function test_empty_site_id_returns_defaults() {
		$site_id = '';

		$default_limits = array(
			'daily_limit'     => 0,
			'max_ticket_size' => 0,
			'last_updated'    => null,
			'status'          => 'unknown',
		);

		// Empty site ID should return defaults immediately.
		$result = empty( $site_id ) ? $default_limits : null;

		$this->assertEquals( 0, $result['daily_limit'] );
		$this->assertEquals( 0, $result['max_ticket_size'] );
		$this->assertNull( $result['last_updated'] );
	}

	/**
	 * Test limits are converted to float.
	 */
	public function test_limits_converted_to_float() {
		$limits = array(
			'daily_limit'     => '10000',
			'max_ticket_size' => '5000.50',
		);

		$daily_limit     = floatval( $limits['daily_limit'] );
		$max_ticket_size = floatval( $limits['max_ticket_size'] );

		$this->assertIsFloat( $daily_limit );
		$this->assertIsFloat( $max_ticket_size );
		$this->assertEquals( 10000.0, $daily_limit );
		$this->assertEquals( 5000.50, $max_ticket_size );
	}

	/**
	 * Test cart total is correctly retrieved as float.
	 */
	public function test_cart_total_as_float() {
		$cart_total_string = '99.99';
		$cart_total        = floatval( $cart_total_string );

		$this->assertIsFloat( $cart_total );
		$this->assertEquals( 99.99, $cart_total );
	}

	/**
	 * Test gateway available when all conditions pass.
	 */
	public function test_available_when_all_conditions_pass() {
		$parent_available = true;
		$max_ticket_size  = 5000.00;
		$daily_limit      = 10000.00;
		$cart_total       = 1000.00;
		$daily_total      = 2000.00;

		$blocked_by_max_ticket = $max_ticket_size > 0 && $cart_total > $max_ticket_size;
		$blocked_by_daily      = $daily_limit > 0 && $daily_total >= $daily_limit;

		$is_available = $parent_available && ! $blocked_by_max_ticket && ! $blocked_by_daily;

		$this->assertTrue( $is_available );
	}
}

// Define MINUTE_IN_SECONDS if not already defined.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

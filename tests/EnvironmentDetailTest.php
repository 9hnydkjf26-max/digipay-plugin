<?php
/**
 * Tests for environment_detail bundle section and request counter helpers.
 *
 * @package Digipay
 */

require_once __DIR__ . '/../support/class-event-log.php';
require_once __DIR__ . '/../support/class-context-bundler.php';

/**
 * Environment detail section tests.
 */
class EnvironmentDetailTest extends DigipayTestCase {

	/**
	 * Clean up request counter option after each test.
	 */
	protected function tear_down() {
		global $wcpg_mock_options;
		unset( $wcpg_mock_options['wcpg_request_counter'] );
		parent::tear_down();
	}

	/**
	 * The bundle must include an 'environment_detail' key with an array value.
	 */
	public function test_build_includes_environment_detail_section() {
		$bundler = new WCPG_Context_Bundler();
		$bundle  = $bundler->build();

		$this->assertArrayHasKey( 'environment_detail', $bundle );
		$this->assertIsArray( $bundle['environment_detail'] );
	}

	/**
	 * All 8 required keys must be present in environment_detail.
	 */
	public function test_environment_detail_has_expected_keys() {
		$bundler = new WCPG_Context_Bundler();
		$bundle  = $bundler->build();
		$detail  = $bundle['environment_detail'];

		$expected_keys = array(
			'site_health_critical_count',
			'recent_fatal_errors',
			'object_cache_dropin',
			'advanced_cache_dropin',
			'litespeed_rest_excluded',
			'php_memory_peak_mb',
			'php_memory_limit',
			'requests_last_24h',
		);

		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $detail, "Missing key: $key" );
		}
	}

	/**
	 * Bumping the counter twice should result in a 24h count of 2.
	 */
	public function test_request_counter_bumps_count() {
		wcpg_bump_request_counter();
		wcpg_bump_request_counter();

		$count = wcpg_get_requests_last_24h();
		$this->assertSame( 2, $count );
	}

	/**
	 * When the stored date is yesterday, bumping once rolls the counter.
	 * Previous count becomes prev_count, new count starts at 1.
	 * The 24h total should be prev_count + new count = 5 + 1 = 6.
	 */
	public function test_request_counter_rolls_on_date_change() {
		global $wcpg_mock_options;

		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		// Pre-seed an old counter entry with yesterday's date and count 5.
		$wcpg_mock_options['wcpg_request_counter'] = array(
			'date'       => $yesterday,
			'count'      => 5,
			'prev_date'  => '',
			'prev_count' => 0,
		);

		// Bump once — this should roll yesterday's 5 into prev_count and start count=1.
		wcpg_bump_request_counter();

		$stored = get_option( 'wcpg_request_counter' );

		$this->assertSame( 1, $stored['count'], 'Current count should be 1 after roll' );
		$this->assertSame( 5, $stored['prev_count'], 'Previous count should be 5 after roll' );
		$this->assertSame( 6, wcpg_get_requests_last_24h(), '24h total should be 6 (1 + 5)' );
	}

	/**
	 * php_memory_peak_mb must be a positive float.
	 */
	public function test_php_memory_peak_is_numeric() {
		$bundler = new WCPG_Context_Bundler();
		$bundle  = $bundler->build();
		$peak    = $bundle['environment_detail']['php_memory_peak_mb'];

		$this->assertIsFloat( $peak );
		$this->assertGreaterThan( 0, $peak );
	}

	/**
	 * litespeed_rest_excluded must be null when LSCWP is not active (test env).
	 */
	public function test_litespeed_rest_excluded_null_when_not_active() {
		$bundler = new WCPG_Context_Bundler();
		$bundle  = $bundler->build();

		$this->assertNull(
			$bundle['environment_detail']['litespeed_rest_excluded'],
			'Should be null because LSCWP classes/functions are not present in the test environment'
		);
	}
}

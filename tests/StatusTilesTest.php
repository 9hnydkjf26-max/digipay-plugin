<?php
/**
 * Tests for WCPG_Support_Admin_Page::build_status_tiles().
 *
 * @package Digipay
 */

require_once __DIR__ . '/../support/class-context-bundler.php';
require_once __DIR__ . '/../support/class-report-renderer.php';
require_once __DIR__ . '/../support/class-support-admin-page.php';

/**
 * Status tile grid tests.
 */
class StatusTilesTest extends DigipayTestCase {

	/** @var WCPG_Support_Admin_Page */
	private $page;

	protected function set_up() {
		parent::set_up();
		$this->page = new WCPG_Support_Admin_Page();

		// Clear options and transients before each test.
		global $wcpg_mock_options, $wcpg_test_transients;
		$wcpg_mock_options    = array();
		$wcpg_test_transients = array();
	}

	protected function tear_down() {
		global $wcpg_mock_options, $wcpg_test_transients;
		$wcpg_mock_options    = array();
		$wcpg_test_transients = array();
		parent::tear_down();
	}

	// ── Helper: invoke protected method ──────────────────────────────────────

	private function get_tiles() {
		$ref    = new ReflectionMethod( $this->page, 'build_status_tiles' );
		$ref->setAccessible( true );
		return $ref->invoke( $this->page );
	}

	// ── Structural tests ──────────────────────────────────────────────────────

	public function test_build_status_tiles_returns_four_tiles() {
		$tiles = $this->get_tiles();
		$this->assertIsArray( $tiles );
		$this->assertCount( 4, $tiles );
	}

	public function test_each_tile_has_required_fields() {
		$tiles = $this->get_tiles();
		foreach ( $tiles as $tile ) {
			$this->assertArrayHasKey( 'key', $tile );
			$this->assertArrayHasKey( 'label', $tile );
			$this->assertArrayHasKey( 'status', $tile );
			$this->assertArrayHasKey( 'headline', $tile );
			$this->assertArrayHasKey( 'detail', $tile );
		}
	}

	public function test_tiles_have_correct_keys() {
		$tiles    = $this->get_tiles();
		$keys     = array_column( $tiles, 'key' );
		$expected = array( 'postbacks', 'webhook', 'api', 'orders' );
		$this->assertSame( $expected, $keys );
	}

	public function test_status_value_is_valid_color() {
		$tiles       = $this->get_tiles();
		$valid_colors = array( 'green', 'yellow', 'red', 'gray' );
		foreach ( $tiles as $tile ) {
			$this->assertContains(
				$tile['status'],
				$valid_colors,
				"Tile '{$tile['key']}' has invalid status '{$tile['status']}'"
			);
		}
	}

	// ── Postbacks tile ────────────────────────────────────────────────────────

	public function test_postbacks_tile_gray_when_no_stats() {
		// No option set — defaults to empty array.
		$tiles = $this->get_tiles();
		$tile  = $tiles[0]; // postbacks is first.
		$this->assertSame( 'postbacks', $tile['key'] );
		$this->assertSame( 'gray', $tile['status'] );
		$this->assertSame( 'Unknown', $tile['headline'] );
		$this->assertSame( 'No transactions yet', $tile['detail'] );
	}

	public function test_postbacks_tile_green_for_low_error_rate() {
		global $wcpg_mock_options;
		$wcpg_mock_options['wcpg_postback_stats'] = array(
			'success_count' => 95,
			'error_count'   => 2,
		);
		$tiles = $this->get_tiles();
		$tile  = $tiles[0];
		$this->assertSame( 'green', $tile['status'] );
		$this->assertSame( 'Healthy', $tile['headline'] );
		$this->assertStringContainsString( '95', $tile['detail'] );
		$this->assertStringContainsString( '97', $tile['detail'] ); // total = 97.
	}

	public function test_postbacks_tile_yellow_for_medium_error_rate() {
		global $wcpg_mock_options;
		$wcpg_mock_options['wcpg_postback_stats'] = array(
			'success_count' => 80,
			'error_count'   => 15,
		);
		$tiles = $this->get_tiles();
		$tile  = $tiles[0];
		$this->assertSame( 'yellow', $tile['status'] );
		$this->assertSame( 'Degraded', $tile['headline'] );
		$this->assertStringContainsString( '15', $tile['detail'] );
	}

	public function test_postbacks_tile_red_for_high_error_rate() {
		global $wcpg_mock_options;
		$wcpg_mock_options['wcpg_postback_stats'] = array(
			'success_count' => 50,
			'error_count'   => 50,
		);
		$tiles = $this->get_tiles();
		$tile  = $tiles[0];
		$this->assertSame( 'red', $tile['status'] );
		$this->assertSame( 'Failing', $tile['headline'] );
	}

	// ── API tile ──────────────────────────────────────────────────────────────

	public function test_api_tile_gray_when_no_test() {
		// No option set.
		$tiles = $this->get_tiles();
		$tile  = $tiles[2]; // api is third.
		$this->assertSame( 'api', $tile['key'] );
		$this->assertSame( 'gray', $tile['status'] );
		$this->assertSame( 'Unknown', $tile['headline'] );
		$this->assertSame( 'No API test run yet', $tile['detail'] );
	}

	public function test_api_tile_green_for_fast_success() {
		global $wcpg_mock_options;
		$wcpg_mock_options['wcpg_api_last_test'] = array(
			'time'             => '2026-04-07T12:00:00Z',
			'success'          => true,
			'response_time_ms' => 200,
		);
		$tiles = $this->get_tiles();
		$tile  = $tiles[2];
		$this->assertSame( 'green', $tile['status'] );
		$this->assertSame( 'Healthy', $tile['headline'] );
		$this->assertStringContainsString( '200', $tile['detail'] );
	}

	public function test_api_tile_yellow_for_slow_success() {
		global $wcpg_mock_options;
		$wcpg_mock_options['wcpg_api_last_test'] = array(
			'time'             => '2026-04-07T12:00:00Z',
			'success'          => true,
			'response_time_ms' => 1500,
		);
		$tiles = $this->get_tiles();
		$tile  = $tiles[2];
		$this->assertSame( 'yellow', $tile['status'] );
		$this->assertSame( 'Slow', $tile['headline'] );
		$this->assertStringContainsString( '1500', $tile['detail'] );
	}

	public function test_api_tile_red_for_failure() {
		global $wcpg_mock_options;
		$wcpg_mock_options['wcpg_api_last_test'] = array(
			'time'    => '2026-04-07T12:00:00Z',
			'success' => false,
		);
		$tiles = $this->get_tiles();
		$tile  = $tiles[2];
		$this->assertSame( 'red', $tile['status'] );
		$this->assertSame( 'Failing', $tile['headline'] );
	}

	// ── Webhook tile ──────────────────────────────────────────────────────────

	public function test_webhook_tile_gray_when_no_events() {
		// No transient set — get_health_counters returns [].
		$tiles = $this->get_tiles();
		$tile  = $tiles[1]; // webhook is second.
		$this->assertSame( 'webhook', $tile['key'] );
		$this->assertSame( 'gray', $tile['status'] );
		$this->assertSame( 'Unknown', $tile['headline'] );
		$this->assertSame( 'No webhook events recorded', $tile['detail'] );
	}

	public function test_webhook_tile_green_when_only_processed() {
		global $wcpg_test_transients;
		$wcpg_test_transients['wcpg_etw_health'] = array(
			'value'      => array( 'processed' => 10 ),
			'expiration' => 86400,
		);
		$tiles = $this->get_tiles();
		$tile  = $tiles[1];
		$this->assertSame( 'green', $tile['status'] );
		$this->assertSame( 'Healthy', $tile['headline'] );
		$this->assertStringContainsString( '10', $tile['detail'] );
	}

	public function test_webhook_tile_yellow_for_few_failures() {
		global $wcpg_test_transients;
		$wcpg_test_transients['wcpg_etw_health'] = array(
			'value'      => array( 'processed' => 10, 'hmac_fail' => 2 ),
			'expiration' => 86400,
		);
		$tiles = $this->get_tiles();
		$tile  = $tiles[1];
		$this->assertSame( 'yellow', $tile['status'] );
		$this->assertSame( 'Degraded', $tile['headline'] );
		$this->assertStringContainsString( '2', $tile['detail'] );
	}

	public function test_webhook_tile_red_for_many_failures() {
		global $wcpg_test_transients;
		$wcpg_test_transients['wcpg_etw_health'] = array(
			'value'      => array( 'processed' => 10, 'hmac_fail' => 10 ),
			'expiration' => 86400,
		);
		$tiles = $this->get_tiles();
		$tile  = $tiles[1];
		$this->assertSame( 'red', $tile['status'] );
		$this->assertSame( 'Failing', $tile['headline'] );
		$this->assertStringContainsString( '10', $tile['detail'] );
	}

	// ── Orders tile ───────────────────────────────────────────────────────────

	public function test_orders_tile_returns_structure_without_woocommerce() {
		// In the test environment wc_get_orders is not defined, so it falls back
		// to 0 stuck orders → green.
		$tiles = $this->get_tiles();
		$tile  = $tiles[3]; // orders is fourth.
		$this->assertSame( 'orders', $tile['key'] );
		$this->assertArrayHasKey( 'status', $tile );
		$this->assertArrayHasKey( 'headline', $tile );
		$this->assertArrayHasKey( 'detail', $tile );
		// Fallback count 0 → green.
		$this->assertSame( 'green', $tile['status'] );
		$this->assertStringContainsString( 'No stuck orders', $tile['detail'] );
	}
}

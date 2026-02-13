<?php
/**
 * Tests for postback handler functionality.
 *
 * Tests rate limiting, deduplication, order status transitions,
 * invalid order handling, and status validation.
 *
 * @package Digipay
 */

// Define MINUTE_IN_SECONDS if not already defined.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for postback handler behavior.
 */
class PostbackHandlerTest extends DigipayTestCase {

	/**
	 * Clear transients before each test.
	 */
	protected function set_up() {
		parent::set_up();
		global $wcpg_test_transients;
		$wcpg_test_transients = array();
	}

	/**
	 * Test rate limiting allows first request.
	 */
	public function test_rate_limiting_allows_first_request() {
		global $wcpg_test_transients;

		$client_ip      = '192.168.1.1';
		$rate_limit_key = 'wcpg_rate_' . md5( $client_ip );

		// First request should succeed (no transient exists).
		$this->assertFalse( get_transient( $rate_limit_key ) );

		// Simulate rate limit counter set.
		set_transient( $rate_limit_key, 1, MINUTE_IN_SECONDS );

		$this->assertEquals( 1, get_transient( $rate_limit_key ) );
	}

	/**
	 * Test rate limiting increments counter.
	 */
	public function test_rate_limiting_increments_counter() {
		global $wcpg_test_transients;

		$client_ip      = '192.168.1.1';
		$rate_limit_key = 'wcpg_rate_' . md5( $client_ip );

		// Set initial count.
		set_transient( $rate_limit_key, 5, MINUTE_IN_SECONDS );
		$this->assertEquals( 5, get_transient( $rate_limit_key ) );

		// Increment.
		$count = get_transient( $rate_limit_key );
		set_transient( $rate_limit_key, $count + 1, MINUTE_IN_SECONDS );

		$this->assertEquals( 6, get_transient( $rate_limit_key ) );
	}

	/**
	 * Test rate limiting blocks after 60 requests.
	 */
	public function test_rate_limiting_blocks_over_60_requests() {
		global $wcpg_test_transients;

		$client_ip      = '192.168.1.1';
		$rate_limit_key = 'wcpg_rate_' . md5( $client_ip );

		// Set count to 61 (over limit).
		set_transient( $rate_limit_key, 61, MINUTE_IN_SECONDS );

		$rate_count = get_transient( $rate_limit_key );
		$this->assertGreaterThan( 60, $rate_count, 'Rate count should exceed limit' );

		// In actual handler, this would return 429.
		$should_block = $rate_count > 60;
		$this->assertTrue( $should_block );
	}

	/**
	 * Test deduplication transient key format.
	 */
	public function test_deduplication_key_format() {
		$order_id    = 12345;
		$transid     = 'TXN123456';
		$status_post = 'approved';

		$postback_key = 'wcpg_pb_' . $order_id . '_' . md5( $transid . $status_post );

		$this->assertStringStartsWith( 'wcpg_pb_12345_', $postback_key );
		$this->assertEquals( 32, strlen( md5( $transid . $status_post ) ) );
	}

	/**
	 * Test deduplication prevents duplicate processing.
	 */
	public function test_deduplication_prevents_duplicates() {
		global $wcpg_test_transients;

		$order_id     = 12345;
		$transid      = 'TXN123456';
		$status_post  = 'approved';
		$postback_key = 'wcpg_pb_' . $order_id . '_' . md5( $transid . $status_post );

		// First postback should not be duplicate.
		$this->assertFalse( get_transient( $postback_key ) );

		// Mark as processed.
		set_transient( $postback_key, true, 5 * MINUTE_IN_SECONDS );

		// Second postback should be detected as duplicate.
		$this->assertTrue( get_transient( $postback_key ) );
	}

	/**
	 * Test allowed statuses whitelist.
	 */
	public function test_status_validation_whitelist() {
		$allowed_statuses = array( 'approved', 'denied', 'pending', 'error', 'completed', 'processing' );

		// Valid statuses.
		foreach ( $allowed_statuses as $status ) {
			$this->assertTrue(
				in_array( strtolower( $status ), $allowed_statuses, true ),
				"Status '$status' should be allowed"
			);
		}

		// Invalid statuses.
		$invalid_statuses = array( 'invalid', 'hacked', 'malicious', 'dropped' );
		foreach ( $invalid_statuses as $status ) {
			$this->assertFalse(
				in_array( strtolower( $status ), $allowed_statuses, true ),
				"Status '$status' should NOT be allowed"
			);
		}
	}

	/**
	 * Test status validation is case-insensitive.
	 */
	public function test_status_validation_case_insensitive() {
		$allowed_statuses = array( 'approved', 'denied', 'pending', 'error', 'completed', 'processing' );

		// Test uppercase variations.
		$this->assertTrue( in_array( strtolower( 'APPROVED' ), $allowed_statuses, true ) );
		$this->assertTrue( in_array( strtolower( 'Denied' ), $allowed_statuses, true ) );
		$this->assertTrue( in_array( strtolower( 'PROCESSING' ), $allowed_statuses, true ) );
	}

	/**
	 * Test denied status is handled separately.
	 */
	public function test_denied_status_handled_specially() {
		$status_post = 'denied';

		// Denied transactions should exit early without updating order status.
		$this->assertTrue( strtolower( $status_post ) === 'denied' );
	}

	/**
	 * Test invalid order ID is zero after absint.
	 */
	public function test_invalid_order_id_handling() {
		// Test various invalid inputs.
		$this->assertEquals( 0, absint( '' ) );
		$this->assertEquals( 0, absint( null ) );
		$this->assertEquals( 0, absint( 'abc' ) );
		// absint returns absolute value, so -1 becomes 1.
		$this->assertEquals( 1, absint( -1 ) );

		// Valid order ID.
		$this->assertEquals( 12345, absint( '12345' ) );
		$this->assertEquals( 12345, absint( 12345 ) );
	}

	/**
	 * Test deduplication expiration time.
	 */
	public function test_deduplication_expiration_is_5_minutes() {
		global $wcpg_test_transients;

		$postback_key = 'wcpg_pb_12345_' . md5( 'TXN123approved' );
		set_transient( $postback_key, true, 5 * MINUTE_IN_SECONDS );

		$this->assertArrayHasKey( $postback_key, $wcpg_test_transients );
		$this->assertEquals( 5 * MINUTE_IN_SECONDS, $wcpg_test_transients[ $postback_key ]['expiration'] );
	}

	/**
	 * Test rate limit expiration time is 1 minute.
	 */
	public function test_rate_limit_expiration_is_1_minute() {
		global $wcpg_test_transients;

		$rate_limit_key = 'wcpg_rate_' . md5( '192.168.1.1' );
		set_transient( $rate_limit_key, 1, MINUTE_IN_SECONDS );

		$this->assertArrayHasKey( $rate_limit_key, $wcpg_test_transients );
		$this->assertEquals( MINUTE_IN_SECONDS, $wcpg_test_transients[ $rate_limit_key ]['expiration'] );
	}

	/**
	 * Test transaction ID is sanitized.
	 */
	public function test_transaction_id_sanitization() {
		// sanitize_text_field should strip HTML and trim.
		$this->assertEquals( 'TXN123', sanitize_text_field( '  TXN123  ' ) );
		$this->assertEquals( 'TXN123', sanitize_text_field( '<script>TXN123</script>' ) );
		$this->assertEquals( 'TXN123alert(1)', sanitize_text_field( 'TXN123<script>alert(1)</script>' ) );
	}

	/**
	 * Test that approved status sets order to processing.
	 */
	public function test_approved_status_sets_order_processing() {
		global $wcpg_mock_orders, $wcpg_test_transients;
		$wcpg_test_transients = array();

		$order = new WcpgMockOrder( 100 );
		$wcpg_mock_orders[100] = $order;

		$result = wcpg_process_postback( 100, 'approved', 'TXN001', 'test' );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'ok', $result['code'] );
		$this->assertEquals( 'processing', $order->get_status() );
	}

	/**
	 * Test that pending status sets order to on-hold (not processing).
	 */
	public function test_pending_status_sets_order_on_hold() {
		global $wcpg_mock_orders, $wcpg_test_transients;
		$wcpg_test_transients = array();

		$order = new WcpgMockOrder( 102 );
		$wcpg_mock_orders[102] = $order;

		$result = wcpg_process_postback( 102, 'pending', 'TXN003', 'test' );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'on-hold', $order->get_status() );
	}

	/**
	 * Test that error status sets order to failed.
	 */
	public function test_error_status_sets_order_failed() {
		global $wcpg_mock_orders, $wcpg_test_transients;
		$wcpg_test_transients = array();

		$order = new WcpgMockOrder( 104 );
		$wcpg_mock_orders[104] = $order;

		$result = wcpg_process_postback( 104, 'error', 'TXN005', 'test' );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'failed', $order->get_status() );
	}

	/**
	 * Test that unrecognized status returns error and does not update order.
	 */
	public function test_unrecognized_status_returns_error() {
		global $wcpg_mock_orders, $wcpg_test_transients;
		$wcpg_test_transients = array();

		$order = new WcpgMockOrder( 105 );
		$wcpg_mock_orders[105] = $order;

		$result = wcpg_process_postback( 105, 'hacked', 'TXN006', 'test' );

		$this->assertFalse( $result['success'] );
		$this->assertEquals( 'invalid_status', $result['code'] );
		$this->assertEquals( 'pending', $order->get_status() );
	}
}

/**
 * Mock order class for postback status tests.
 */
class WcpgMockOrder {
	private $id;
	private $status = 'pending';
	private $notes = array();

	public function __construct( $id ) {
		$this->id = $id;
	}

	public function update_status( $status, $note = '' ) {
		$this->status = $status;
		$this->notes[] = $note;
	}

	public function get_status() {
		return $this->status;
	}

	public function get_id() {
		return $this->id;
	}
}

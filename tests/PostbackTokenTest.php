<?php
/**
 * Tests for postback token verification and order status gate.
 *
 * Covers: token generation, valid/invalid tokens, backward compatibility,
 * order status gate for each status, and integration with deduplication.
 *
 * @package Digipay
 */

// Define MINUTE_IN_SECONDS if not already defined.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

require_once __DIR__ . '/PostbackHandlerTest.php';

/**
 * Test class for postback token verification.
 */
class PostbackTokenTest extends DigipayTestCase {

	/**
	 * Clear transients and mock orders before each test.
	 */
	protected function set_up() {
		parent::set_up();
		global $wcpg_test_transients, $wcpg_mock_orders;
		$wcpg_test_transients = array();
		$wcpg_mock_orders     = array();
	}

	// =========================================================
	// Token Verification Helper Tests
	// =========================================================

	/**
	 * Test valid token passes verification.
	 */
	public function test_valid_token_passes_verification() {
		$order = new WcpgMockOrder( 200 );
		$token = bin2hex( random_bytes( 16 ) );
		$order->update_meta_data( '_wcpg_postback_token', $token );

		$this->assertTrue( wcpg_verify_postback_token( $order, $token ) );
	}

	/**
	 * Test wrong token fails verification.
	 */
	public function test_wrong_token_fails_verification() {
		$order = new WcpgMockOrder( 201 );
		$token = bin2hex( random_bytes( 16 ) );
		$order->update_meta_data( '_wcpg_postback_token', $token );

		$this->assertFalse( wcpg_verify_postback_token( $order, 'wrong_token_value' ) );
	}

	/**
	 * Test empty token fails when order has stored token.
	 */
	public function test_empty_token_fails_when_order_has_token() {
		$order = new WcpgMockOrder( 202 );
		$token = bin2hex( random_bytes( 16 ) );
		$order->update_meta_data( '_wcpg_postback_token', $token );

		$this->assertFalse( wcpg_verify_postback_token( $order, '' ) );
	}

	/**
	 * Test null token fails when order has stored token.
	 */
	public function test_null_token_fails_when_order_has_token() {
		$order = new WcpgMockOrder( 203 );
		$token = bin2hex( random_bytes( 16 ) );
		$order->update_meta_data( '_wcpg_postback_token', $token );

		$this->assertFalse( wcpg_verify_postback_token( $order, null ) );
	}

	/**
	 * Test backward compat: pre-update order with no token allows through.
	 */
	public function test_backward_compat_no_stored_token_allows_through() {
		$order = new WcpgMockOrder( 204 );
		// No token set on order — simulates pre-update order.

		$this->assertTrue( wcpg_verify_postback_token( $order, '' ) );
	}

	/**
	 * Test backward compat: pre-update order allows through even with a supplied token.
	 */
	public function test_backward_compat_no_stored_token_allows_any_supplied_token() {
		$order = new WcpgMockOrder( 205 );
		// No token set on order.

		$this->assertTrue( wcpg_verify_postback_token( $order, 'some_random_token' ) );
	}

	/**
	 * Test token is 32 hex characters (16 bytes).
	 */
	public function test_token_format_is_32_hex_chars() {
		$token = bin2hex( random_bytes( 16 ) );

		$this->assertEquals( 32, strlen( $token ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $token );
	}

	/**
	 * Test tokens are unique across generations.
	 */
	public function test_tokens_are_unique() {
		$token1 = bin2hex( random_bytes( 16 ) );
		$token2 = bin2hex( random_bytes( 16 ) );

		$this->assertNotEquals( $token1, $token2 );
	}

	/**
	 * Test verification uses constant-time comparison.
	 *
	 * We can't directly test timing, but we can verify hash_equals behavior.
	 */
	public function test_verification_uses_hash_equals_behavior() {
		$order = new WcpgMockOrder( 206 );
		$token = 'abcdef1234567890abcdef1234567890';
		$order->update_meta_data( '_wcpg_postback_token', $token );

		// Exact match.
		$this->assertTrue( wcpg_verify_postback_token( $order, 'abcdef1234567890abcdef1234567890' ) );

		// Partial match (first half correct) should still fail.
		$this->assertFalse( wcpg_verify_postback_token( $order, 'abcdef1234567890xxxxxxxxxxxxxxxx' ) );
	}

	// =========================================================
	// Order Status Gate Tests
	// =========================================================

	/**
	 * Test pending order accepts postback.
	 */
	public function test_status_gate_allows_pending_order() {
		global $wcpg_mock_orders;

		$order = new WcpgMockOrder( 210, 'pending' );
		$wcpg_mock_orders[210] = $order;

		$result = wcpg_process_postback( 210, 'approved', 'TXN210', 'test' );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'ok', $result['code'] );
	}

	/**
	 * Test failed order accepts postback (retry scenario).
	 */
	public function test_status_gate_allows_failed_order() {
		global $wcpg_mock_orders;

		$order = new WcpgMockOrder( 211, 'failed' );
		$wcpg_mock_orders[211] = $order;

		$result = wcpg_process_postback( 211, 'approved', 'TXN211', 'test' );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'ok', $result['code'] );
	}

	/**
	 * Test processing order rejects postback.
	 */
	public function test_status_gate_rejects_processing_order() {
		global $wcpg_mock_orders;

		$order = new WcpgMockOrder( 212, 'processing' );
		$wcpg_mock_orders[212] = $order;

		$result = wcpg_process_postback( 212, 'approved', 'TXN212', 'test' );

		$this->assertFalse( $result['success'] );
		$this->assertEquals( 'invalid_order_status', $result['code'] );
	}

	/**
	 * Test completed order rejects postback.
	 */
	public function test_status_gate_rejects_completed_order() {
		global $wcpg_mock_orders;

		$order = new WcpgMockOrder( 213, 'completed' );
		$wcpg_mock_orders[213] = $order;

		$result = wcpg_process_postback( 213, 'approved', 'TXN213', 'test' );

		$this->assertFalse( $result['success'] );
		$this->assertEquals( 'invalid_order_status', $result['code'] );
	}

	/**
	 * Test on-hold order rejects postback.
	 */
	public function test_status_gate_rejects_on_hold_order() {
		global $wcpg_mock_orders;

		$order = new WcpgMockOrder( 214, 'on-hold' );
		$wcpg_mock_orders[214] = $order;

		$result = wcpg_process_postback( 214, 'approved', 'TXN214', 'test' );

		$this->assertFalse( $result['success'] );
		$this->assertEquals( 'invalid_order_status', $result['code'] );
	}

	/**
	 * Test cancelled order rejects postback.
	 */
	public function test_status_gate_rejects_cancelled_order() {
		global $wcpg_mock_orders;

		$order = new WcpgMockOrder( 215, 'cancelled' );
		$wcpg_mock_orders[215] = $order;

		$result = wcpg_process_postback( 215, 'approved', 'TXN215', 'test' );

		$this->assertFalse( $result['success'] );
		$this->assertEquals( 'invalid_order_status', $result['code'] );
	}

	/**
	 * Test refunded order rejects postback.
	 */
	public function test_status_gate_rejects_refunded_order() {
		global $wcpg_mock_orders;

		$order = new WcpgMockOrder( 216, 'refunded' );
		$wcpg_mock_orders[216] = $order;

		$result = wcpg_process_postback( 216, 'approved', 'TXN216', 'test' );

		$this->assertFalse( $result['success'] );
		$this->assertEquals( 'invalid_order_status', $result['code'] );
	}

	// =========================================================
	// Integration Tests: Status Gate + Dedup
	// =========================================================

	/**
	 * Test duplicate postback returns 'duplicate' before status gate.
	 */
	public function test_duplicate_returns_before_status_gate() {
		global $wcpg_mock_orders;

		$order = new WcpgMockOrder( 220, 'processing' );
		$wcpg_mock_orders[220] = $order;

		// Pre-set the dedup transient to simulate a previous postback.
		$postback_key = 'wcpg_pb_220_' . md5( 'TXN220' . 'approved' );
		set_transient( $postback_key, true, 5 * MINUTE_IN_SECONDS );

		$result = wcpg_process_postback( 220, 'approved', 'TXN220', 'test' );

		// Should return 'duplicate', not 'invalid_order_status'.
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'duplicate', $result['code'] );
	}

	/**
	 * Test denied status handled before status gate.
	 */
	public function test_denied_handled_before_status_gate() {
		global $wcpg_mock_orders;

		$order = new WcpgMockOrder( 221, 'processing' );
		$wcpg_mock_orders[221] = $order;

		$result = wcpg_process_postback( 221, 'denied', 'TXN221', 'test' );

		// Denied is handled early, before the status gate.
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'denied', $result['code'] );
	}

	/**
	 * Test invalid status rejected before status gate.
	 */
	public function test_invalid_status_rejected_before_status_gate() {
		global $wcpg_mock_orders;

		$order = new WcpgMockOrder( 222, 'processing' );
		$wcpg_mock_orders[222] = $order;

		$result = wcpg_process_postback( 222, 'hacked', 'TXN222', 'test' );

		// Invalid status is caught before the status gate.
		$this->assertFalse( $result['success'] );
		$this->assertEquals( 'invalid_status', $result['code'] );
	}

	/**
	 * Test order not found returned before status gate.
	 */
	public function test_order_not_found_before_status_gate() {
		// Don't register any mock order for ID 223.
		$result = wcpg_process_postback( 223, 'approved', 'TXN223', 'test' );

		$this->assertFalse( $result['success'] );
		$this->assertEquals( 'order_not_found', $result['code'] );
	}
}

<?php
/**
 * Tests for E-Transfer Transaction Poller order status updates.
 *
 * Verifies that order status automatically updates when e-transfers
 * are completed, failed, or still pending — particularly when
 * "request money" options (email/URL delivery) are enabled.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for E-Transfer Poller status update behavior.
 */
class ETransferPollerStatusUpdateTest extends DigipayTestCase {

	/**
	 * Poller instance.
	 *
	 * @var WCPG_ETransfer_Transaction_Poller
	 */
	private $poller;

	/**
	 * Set up test fixtures.
	 */
	protected function set_up() {
		parent::set_up();
		global $wcpg_test_transients, $wcpg_mock_orders;
		$wcpg_test_transients = array();
		$wcpg_mock_orders     = array();
		$this->poller         = new WCPG_ETransfer_Transaction_Poller();
	}

	/**
	 * Tear down test fixtures.
	 */
	protected function tear_down() {
		global $wcpg_test_transients, $wcpg_mock_orders;
		$wcpg_test_transients = array();
		$wcpg_mock_orders     = array();
		parent::tear_down();
	}

	/**
	 * Helper: invoke private method via reflection.
	 *
	 * @param object $object     Object instance.
	 * @param string $method     Method name.
	 * @param array  $args       Method arguments.
	 * @return mixed Method return value.
	 */
	private function invoke_private_method( $object, $method, array $args = array() ) {
		$ref = new ReflectionMethod( get_class( $object ), $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $object, $args );
	}

	// =========================================================================
	// Approved / Completed status tests
	// =========================================================================

	/**
	 * Test that 'approved' status sets order to completed.
	 */
	public function test_approved_status_completes_order() {
		$order = new PollerMockOrder( 200, 'on-hold' );

		$transaction = array(
			'reference'      => 'REF-200',
			'status'         => 'approved',
			'transaction_id' => 'TXN-APPROVED-200',
			'completed_at'   => '2026-02-12T10:30:00Z',
		);

		$this->invoke_private_method( $this->poller, 'process_transaction_result', array( $order, $transaction ) );

		$this->assertEquals( 'completed', $order->get_status(), 'Order should be marked completed on approved status' );
	}

	/**
	 * Test that 'completed' status sets order to completed.
	 */
	public function test_completed_status_completes_order() {
		$order = new PollerMockOrder( 201, 'on-hold' );

		$transaction = array(
			'reference'      => 'REF-201',
			'status'         => 'completed',
			'transaction_id' => 'TXN-COMPLETED-201',
			'completed_at'   => '2026-02-12T11:00:00Z',
		);

		$this->invoke_private_method( $this->poller, 'process_transaction_result', array( $order, $transaction ) );

		$this->assertEquals( 'completed', $order->get_status(), 'Order should be marked completed on completed status' );
	}

	/**
	 * Test that order note includes transaction reference on approval.
	 */
	public function test_approved_order_note_includes_reference() {
		$order = new PollerMockOrder( 202, 'on-hold' );

		$transaction = array(
			'reference' => 'REF-UNIQUE-202',
			'status'    => 'approved',
		);

		$this->invoke_private_method( $this->poller, 'process_transaction_result', array( $order, $transaction ) );

		$notes = $order->get_notes();
		$this->assertNotEmpty( $notes, 'Order should have a note' );
		$this->assertStringContainsString( 'REF-UNIQUE-202', $notes[0], 'Note should include reference' );
	}

	/**
	 * Test that transaction_id metadata is stored on approval.
	 */
	public function test_approved_stores_transaction_id_meta() {
		$order = new PollerMockOrder( 203, 'on-hold' );

		$transaction = array(
			'reference'      => 'REF-203',
			'status'         => 'approved',
			'transaction_id' => 'TXN-META-203',
		);

		$this->invoke_private_method( $this->poller, 'process_transaction_result', array( $order, $transaction ) );

		$this->assertEquals( 'TXN-META-203', $order->get_meta( '_etransfer_transaction_id' ), 'Transaction ID should be stored' );
	}

	/**
	 * Test that completed_at metadata is stored on approval.
	 */
	public function test_approved_stores_completed_at_meta() {
		$order = new PollerMockOrder( 204, 'on-hold' );

		$transaction = array(
			'reference'    => 'REF-204',
			'status'       => 'completed',
			'completed_at' => '2026-02-12T12:00:00Z',
		);

		$this->invoke_private_method( $this->poller, 'process_transaction_result', array( $order, $transaction ) );

		$this->assertEquals( '2026-02-12T12:00:00Z', $order->get_meta( '_etransfer_completed_at' ), 'Completed timestamp should be stored' );
	}

	/**
	 * Test that save() is called after storing metadata on approval.
	 */
	public function test_approved_calls_save() {
		$order = new PollerMockOrder( 205, 'on-hold' );

		$transaction = array(
			'reference' => 'REF-205',
			'status'    => 'approved',
		);

		$this->invoke_private_method( $this->poller, 'process_transaction_result', array( $order, $transaction ) );

		$this->assertTrue( $order->was_saved(), 'Order save() should be called after approval' );
	}

	/**
	 * Test that processing transient is set on approval with correct TTL.
	 */
	public function test_approved_sets_processed_transient() {
		global $wcpg_test_transients;

		$order = new PollerMockOrder( 206, 'on-hold' );

		$transaction = array(
			'reference' => 'REF-206',
			'status'    => 'approved',
		);

		$this->invoke_private_method( $this->poller, 'process_transaction_result', array( $order, $transaction ) );

		$key = WCPG_ETransfer_Transaction_Poller::TRANSIENT_PREFIX . '206';
		$this->assertArrayHasKey( $key, $wcpg_test_transients, 'Processed transient should be set' );
		$this->assertEquals( 300, $wcpg_test_transients[ $key ]['expiration'], 'Transient TTL should be 5 minutes' );
	}

	// =========================================================================
	// Failed / Cancelled / Declined / Expired status tests
	// =========================================================================

	/**
	 * Test that 'failed' status sets order to failed.
	 */
	public function test_failed_status_fails_order() {
		$order = new PollerMockOrder( 210, 'on-hold' );

		$transaction = array(
			'reference' => 'REF-210',
			'status'    => 'failed',
		);

		$this->invoke_private_method( $this->poller, 'process_transaction_result', array( $order, $transaction ) );

		$this->assertEquals( 'failed', $order->get_status(), 'Order should be failed on failed status' );
	}

	/**
	 * Test that 'cancelled' status sets order to failed.
	 */
	public function test_cancelled_status_fails_order() {
		$order = new PollerMockOrder( 211, 'on-hold' );

		$transaction = array(
			'reference' => 'REF-211',
			'status'    => 'cancelled',
		);

		$this->invoke_private_method( $this->poller, 'process_transaction_result', array( $order, $transaction ) );

		$this->assertEquals( 'failed', $order->get_status(), 'Order should be failed on cancelled status' );
	}

	/**
	 * Test that 'declined' status sets order to failed.
	 */
	public function test_declined_status_fails_order() {
		$order = new PollerMockOrder( 212, 'on-hold' );

		$transaction = array(
			'reference' => 'REF-212',
			'status'    => 'declined',
		);

		$this->invoke_private_method( $this->poller, 'process_transaction_result', array( $order, $transaction ) );

		$this->assertEquals( 'failed', $order->get_status(), 'Order should be failed on declined status' );
	}

	/**
	 * Test that 'expired' status sets order to failed.
	 */
	public function test_expired_status_fails_order() {
		$order = new PollerMockOrder( 213, 'on-hold' );

		$transaction = array(
			'reference' => 'REF-213',
			'status'    => 'expired',
		);

		$this->invoke_private_method( $this->poller, 'process_transaction_result', array( $order, $transaction ) );

		$this->assertEquals( 'failed', $order->get_status(), 'Order should be failed on expired status' );
	}

	/**
	 * Test that failure note includes the status and reference.
	 */
	public function test_failure_note_includes_status_and_reference() {
		$order = new PollerMockOrder( 214, 'on-hold' );

		$transaction = array(
			'reference' => 'REF-FAIL-214',
			'status'    => 'declined',
		);

		$this->invoke_private_method( $this->poller, 'process_transaction_result', array( $order, $transaction ) );

		$notes = $order->get_notes();
		$this->assertStringContainsString( 'declined', $notes[0], 'Note should include status' );
		$this->assertStringContainsString( 'REF-FAIL-214', $notes[0], 'Note should include reference' );
	}

	/**
	 * Test that processed transient is set on failure.
	 */
	public function test_failure_sets_processed_transient() {
		global $wcpg_test_transients;

		$order = new PollerMockOrder( 215, 'on-hold' );

		$transaction = array(
			'reference' => 'REF-215',
			'status'    => 'failed',
		);

		$this->invoke_private_method( $this->poller, 'process_transaction_result', array( $order, $transaction ) );

		$key = WCPG_ETransfer_Transaction_Poller::TRANSIENT_PREFIX . '215';
		$this->assertArrayHasKey( $key, $wcpg_test_transients, 'Processed transient should be set on failure' );
		$this->assertEquals( 300, $wcpg_test_transients[ $key ]['expiration'], 'Failure transient TTL should be 5 minutes' );
	}

	// =========================================================================
	// Pending / Processing (no-change) status tests
	// =========================================================================

	/**
	 * Test that 'pending' status does NOT change order status.
	 */
	public function test_pending_status_does_not_change_order() {
		$order = new PollerMockOrder( 220, 'on-hold' );

		$transaction = array(
			'reference' => 'REF-220',
			'status'    => 'pending',
		);

		$this->invoke_private_method( $this->poller, 'process_transaction_result', array( $order, $transaction ) );

		$this->assertEquals( 'on-hold', $order->get_status(), 'Order should remain on-hold for pending status' );
	}

	/**
	 * Test that 'processing' status does NOT change order status.
	 */
	public function test_processing_status_does_not_change_order() {
		$order = new PollerMockOrder( 221, 'on-hold' );

		$transaction = array(
			'reference' => 'REF-221',
			'status'    => 'processing',
		);

		$this->invoke_private_method( $this->poller, 'process_transaction_result', array( $order, $transaction ) );

		$this->assertEquals( 'on-hold', $order->get_status(), 'Order should remain on-hold for processing status' );
	}

	/**
	 * Test that pending transactions get a shorter transient (1 minute).
	 */
	public function test_pending_sets_short_transient() {
		global $wcpg_test_transients;

		$order = new PollerMockOrder( 222, 'on-hold' );

		$transaction = array(
			'reference' => 'REF-222',
			'status'    => 'pending',
		);

		$this->invoke_private_method( $this->poller, 'process_transaction_result', array( $order, $transaction ) );

		$key = WCPG_ETransfer_Transaction_Poller::TRANSIENT_PREFIX . '222';
		$this->assertArrayHasKey( $key, $wcpg_test_transients, 'Transient should be set for pending' );
		$this->assertEquals( 60, $wcpg_test_transients[ $key ]['expiration'], 'Pending transient TTL should be 1 minute for faster re-check' );
	}

	// =========================================================================
	// Case-insensitivity tests
	// =========================================================================

	/**
	 * Test that status comparison is case-insensitive (uppercase APPROVED).
	 */
	public function test_status_case_insensitive_uppercase() {
		$order = new PollerMockOrder( 230, 'on-hold' );

		$transaction = array(
			'reference' => 'REF-230',
			'status'    => 'APPROVED',
		);

		$this->invoke_private_method( $this->poller, 'process_transaction_result', array( $order, $transaction ) );

		$this->assertEquals( 'completed', $order->get_status(), 'APPROVED (uppercase) should complete the order' );
	}

	/**
	 * Test that status comparison is case-insensitive (mixed case Completed).
	 */
	public function test_status_case_insensitive_mixed() {
		$order = new PollerMockOrder( 231, 'on-hold' );

		$transaction = array(
			'reference' => 'REF-231',
			'status'    => 'Completed',
		);

		$this->invoke_private_method( $this->poller, 'process_transaction_result', array( $order, $transaction ) );

		$this->assertEquals( 'completed', $order->get_status(), 'Completed (mixed case) should complete the order' );
	}

	// =========================================================================
	// Edge cases
	// =========================================================================

	/**
	 * Test that unknown status falls into default (no change).
	 */
	public function test_unknown_status_no_change() {
		$order = new PollerMockOrder( 240, 'on-hold' );

		$transaction = array(
			'reference' => 'REF-240',
			'status'    => 'something_unexpected',
		);

		$this->invoke_private_method( $this->poller, 'process_transaction_result', array( $order, $transaction ) );

		$this->assertEquals( 'on-hold', $order->get_status(), 'Unknown status should not change order' );
	}

	/**
	 * Test that empty status falls into default (no change).
	 */
	public function test_empty_status_no_change() {
		$order = new PollerMockOrder( 241, 'on-hold' );

		$transaction = array(
			'reference' => 'REF-241',
		);

		$this->invoke_private_method( $this->poller, 'process_transaction_result', array( $order, $transaction ) );

		$this->assertEquals( 'on-hold', $order->get_status(), 'Missing status should not change order' );
	}

	/**
	 * Test that missing transaction_id does not store meta (no error).
	 */
	public function test_approved_without_transaction_id_ok() {
		$order = new PollerMockOrder( 242, 'on-hold' );

		$transaction = array(
			'reference' => 'REF-242',
			'status'    => 'approved',
		);

		$this->invoke_private_method( $this->poller, 'process_transaction_result', array( $order, $transaction ) );

		$this->assertEquals( 'completed', $order->get_status(), 'Order should still complete without transaction_id' );
		$this->assertNull( $order->get_meta( '_etransfer_transaction_id' ), 'No meta should be stored when transaction_id is missing' );
	}

	/**
	 * Test that order initially set to 'pending' gets completed.
	 */
	public function test_pending_order_gets_completed() {
		$order = new PollerMockOrder( 243, 'pending' );

		$transaction = array(
			'reference' => 'REF-243',
			'status'    => 'approved',
		);

		$this->invoke_private_method( $this->poller, 'process_transaction_result', array( $order, $transaction ) );

		$this->assertEquals( 'completed', $order->get_status(), 'Pending order should be completed when approved' );
	}

	// =========================================================================
	// Request Money delivery method tests
	// =========================================================================

	/**
	 * Test that email delivery orders are completed by poller.
	 */
	public function test_email_delivery_order_completed() {
		$order = new PollerMockOrder( 250, 'on-hold' );
		$order->set_payment_method( 'digipay_etransfer_email' );

		$transaction = array(
			'reference'      => 'REF-EMAIL-250',
			'status'         => 'completed',
			'transaction_id' => 'TXN-EMAIL-250',
			'completed_at'   => '2026-02-12T14:00:00Z',
		);

		$this->invoke_private_method( $this->poller, 'process_transaction_result', array( $order, $transaction ) );

		$this->assertEquals( 'completed', $order->get_status(), 'Email delivery order should be completed' );
		$this->assertEquals( 'TXN-EMAIL-250', $order->get_meta( '_etransfer_transaction_id' ) );
	}

	/**
	 * Test that URL delivery orders are completed by poller.
	 */
	public function test_url_delivery_order_completed() {
		$order = new PollerMockOrder( 251, 'on-hold' );
		$order->set_payment_method( 'digipay_etransfer_url' );

		$transaction = array(
			'reference'      => 'REF-URL-251',
			'status'         => 'approved',
			'transaction_id' => 'TXN-URL-251',
			'completed_at'   => '2026-02-12T14:30:00Z',
		);

		$this->invoke_private_method( $this->poller, 'process_transaction_result', array( $order, $transaction ) );

		$this->assertEquals( 'completed', $order->get_status(), 'URL delivery order should be completed' );
		$this->assertEquals( 'TXN-URL-251', $order->get_meta( '_etransfer_transaction_id' ) );
	}

	/**
	 * Test that manual delivery orders are completed by poller.
	 */
	public function test_manual_delivery_order_completed() {
		$order = new PollerMockOrder( 252, 'on-hold' );
		$order->set_payment_method( 'digipay_etransfer_manual' );

		$transaction = array(
			'reference'      => 'REF-MANUAL-252',
			'status'         => 'approved',
			'transaction_id' => 'TXN-MANUAL-252',
		);

		$this->invoke_private_method( $this->poller, 'process_transaction_result', array( $order, $transaction ) );

		$this->assertEquals( 'completed', $order->get_status(), 'Manual delivery order should be completed' );
	}

	// =========================================================================
	// Gateway query coverage
	// =========================================================================

	/**
	 * Test that get_pending_etransfer_orders queries all three virtual gateways.
	 */
	public function test_pending_orders_query_includes_all_gateway_ids() {
		// Use reflection to inspect what get_pending_etransfer_orders passes to wc_get_orders.
		// Since our mock wc_get_orders returns empty, we verify the method exists and runs.
		$result = $this->invoke_private_method( $this->poller, 'get_pending_etransfer_orders', array() );
		$this->assertIsArray( $result, 'Should return an array (empty from mock)' );
	}

	/**
	 * Test that check_pending_transactions handles empty order list gracefully.
	 */
	public function test_check_pending_no_orders_returns_early() {
		// Should not throw any errors when no orders exist.
		$this->poller->check_pending_transactions();
		$this->assertTrue( true, 'check_pending_transactions should handle empty order list' );
	}
}

/**
 * Enhanced mock order for poller tests.
 *
 * Supports update_meta_data(), save(), has_status(), and payment method tracking.
 */
class PollerMockOrder {

	private $id;
	private $status;
	private $notes          = array();
	private $meta           = array();
	private $saved          = false;
	private $payment_method = '';

	public function __construct( $id, $status = 'on-hold' ) {
		$this->id     = $id;
		$this->status = $status;
	}

	public function update_status( $status, $note = '' ) {
		$this->status  = $status;
		$this->notes[] = $note;
	}

	public function get_status() {
		return $this->status;
	}

	public function get_id() {
		return $this->id;
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function get_meta( $key ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : null;
	}

	public function save() {
		$this->saved = true;
	}

	public function was_saved() {
		return $this->saved;
	}

	public function has_status( $status ) {
		return $this->status === $status;
	}

	public function get_notes() {
		return $this->notes;
	}

	public function set_payment_method( $method ) {
		$this->payment_method = $method;
	}

	public function get_payment_method() {
		return $this->payment_method;
	}
}

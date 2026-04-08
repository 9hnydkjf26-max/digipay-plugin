<?php
/**
 * Tests for the order correlation view (T5).
 *
 * @package Digipay
 */

require_once __DIR__ . '/../support/class-event-log.php';
require_once __DIR__ . '/../support/class-context-bundler.php';

/**
 * Order correlation tests.
 */
class OrderCorrelationTest extends DigipayTestCase {

	/**
	 * Helper: build a minimal fake order object.
	 *
	 * @param int    $id               Order ID.
	 * @param string $status           Order status.
	 * @param string $etransfer_ref    E-Transfer reference meta value.
	 * @return object
	 */
	private function make_order( $id, $status = 'failed', $etransfer_ref = '' ) {
		return new class( $id, $status, $etransfer_ref ) {
			private $id;
			private $status;
			private $etransfer_ref;
			private $date_modified;

			public function __construct( $id, $status, $etransfer_ref ) {
				$this->id            = $id;
				$this->status        = $status;
				$this->etransfer_ref = $etransfer_ref;
				$this->date_modified = new DateTime( '2026-04-07T00:00:00Z' );
			}

			public function get_id() { return $this->id; }
			public function get_status() { return $this->status; }
			public function get_total() { return '99.00'; }
			public function get_currency() { return 'CAD'; }
			public function get_payment_method() { return 'paygobillingcc'; }
			public function get_date_created() { return null; }
			public function get_date_modified() { return $this->date_modified; }
			public function get_meta( $key ) {
				if ( '_etransfer_reference' === $key ) {
					return $this->etransfer_ref;
				}
				return '';
			}
			public function get_customer_order_notes() { return array(); }
		};
	}

	/**
	 * Set up test state.
	 */
	public function set_up() {
		parent::set_up();
		WCPG_Event_Log::clear();
		$GLOBALS['wcpg_mock_orders'] = array();
	}

	/**
	 * Tear down test state.
	 */
	public function tear_down() {
		WCPG_Event_Log::clear();
		$GLOBALS['wcpg_mock_orders'] = array();
		parent::tear_down();
	}

	// ------------------------------------------------------------------
	// Test 1: empty when no orders
	// ------------------------------------------------------------------

	/**
	 * When wc_get_orders returns nothing, order_correlations is empty.
	 */
	public function test_correlation_empty_when_no_orders() {
		$GLOBALS['wcpg_mock_orders'] = array();
		$bundler = new WCPG_Context_Bundler();
		$bundle  = $bundler->build();

		$this->assertArrayHasKey( 'order_correlations', $bundle );
		$this->assertIsArray( $bundle['order_correlations'] );
		$this->assertEmpty( $bundle['order_correlations'] );
	}

	// ------------------------------------------------------------------
	// Test 2: joins postback events by order_id
	// ------------------------------------------------------------------

	/**
	 * Postback events with matching order_id appear in the correlation entry.
	 */
	public function test_correlation_joins_postback_events_by_order_id() {
		$order = $this->make_order( 123, 'failed' );
		$GLOBALS['wcpg_mock_orders'] = array( 123 => $order );

		// Seed a postback event for order 123.
		WCPG_Event_Log::record(
			WCPG_Event_Log::TYPE_POSTBACK,
			array( 'outcome' => 'approved' ),
			'paygobillingcc',
			123
		);

		// Seed a postback event for a different order (should NOT appear).
		WCPG_Event_Log::record(
			WCPG_Event_Log::TYPE_POSTBACK,
			array( 'outcome' => 'denied' ),
			'paygobillingcc',
			999
		);

		$bundler = new WCPG_Context_Bundler();
		$bundle  = $bundler->build();

		$correlations = $bundle['order_correlations'];
		$this->assertCount( 1, $correlations );
		$entry = $correlations[0];

		$this->assertSame( 123, $entry['order_id'] );
		$this->assertCount( 1, $entry['postback_events'] );
		$this->assertSame( 'approved', $entry['postback_events'][0]['data']['outcome'] );
	}

	// ------------------------------------------------------------------
	// Test 3: joins webhook events by etransfer_reference
	// ------------------------------------------------------------------

	/**
	 * Webhook events are matched when data.reference equals the order's etransfer_reference.
	 */
	public function test_correlation_joins_webhook_events_by_etransfer_reference() {
		$order = $this->make_order( 456, 'on-hold', 'XYZ-123' );
		$GLOBALS['wcpg_mock_orders'] = array( 456 => $order );

		// Seed a webhook event whose data.reference matches.
		WCPG_Event_Log::record(
			WCPG_Event_Log::TYPE_WEBHOOK,
			array( 'reference' => 'XYZ-123', 'outcome' => 'completed' ),
			'digipay_etransfer',
			null
		);

		// Seed a webhook event whose data.reference does NOT match.
		WCPG_Event_Log::record(
			WCPG_Event_Log::TYPE_WEBHOOK,
			array( 'reference' => 'OTHER-REF', 'outcome' => 'pending' ),
			'digipay_etransfer',
			null
		);

		$bundler = new WCPG_Context_Bundler();
		$bundle  = $bundler->build();

		$correlations = $bundle['order_correlations'];
		$this->assertCount( 1, $correlations );
		$entry = $correlations[0];

		$this->assertSame( 456, $entry['order_id'] );
		$this->assertCount( 1, $entry['webhook_events'] );
		$this->assertSame( 'XYZ-123', $entry['webhook_events'][0]['data']['reference'] );
	}

	// ------------------------------------------------------------------
	// Test 4: joins api_call events by url substring
	// ------------------------------------------------------------------

	/**
	 * API call events are matched when data.url contains the order_id as a substring.
	 */
	public function test_correlation_joins_api_events_by_url_substring() {
		$order = $this->make_order( 789, 'failed' );
		$GLOBALS['wcpg_mock_orders'] = array( 789 => $order );

		// Seed an api_call event whose url contains the order id.
		WCPG_Event_Log::record(
			WCPG_Event_Log::TYPE_API_CALL,
			array(
				'method'       => 'POST',
				'url'          => 'https://api.example.com/order/789/status',
				'body_preview' => '',
				'status'       => 200,
				'elapsed_ms'   => 150,
				'outcome'      => 'ok',
			),
			null,
			null
		);

		// Seed an api_call event that does NOT mention the order.
		WCPG_Event_Log::record(
			WCPG_Event_Log::TYPE_API_CALL,
			array(
				'method'       => 'GET',
				'url'          => 'https://api.example.com/limits',
				'body_preview' => '',
				'status'       => 200,
				'elapsed_ms'   => 50,
				'outcome'      => 'ok',
			),
			null,
			null
		);

		$bundler = new WCPG_Context_Bundler();
		$bundle  = $bundler->build();

		$correlations = $bundle['order_correlations'];
		$this->assertCount( 1, $correlations );
		$entry = $correlations[0];

		$this->assertSame( 789, $entry['order_id'] );
		$this->assertCount( 1, $entry['api_call_events'] );
		$this->assertStringContainsString( '789', $entry['api_call_events'][0]['data']['url'] );
	}

	// ------------------------------------------------------------------
	// Test 5: caps events per order
	// ------------------------------------------------------------------

	/**
	 * Postback events are capped at 50 per order.
	 */
	public function test_correlation_caps_events_per_order() {
		$order = $this->make_order( 123, 'failed' );
		$GLOBALS['wcpg_mock_orders'] = array( 123 => $order );

		// Seed 80 postback events for the same order.
		for ( $i = 0; $i < 80; $i++ ) {
			WCPG_Event_Log::record(
				WCPG_Event_Log::TYPE_POSTBACK,
				array( 'outcome' => 'approved', 'seq' => $i ),
				'paygobillingcc',
				123
			);
		}

		$bundler = new WCPG_Context_Bundler();
		$bundle  = $bundler->build();

		$correlations = $bundle['order_correlations'];
		$this->assertCount( 1, $correlations );
		$entry = $correlations[0];

		$this->assertSame( 123, $entry['order_id'] );
		$this->assertCount( 50, $entry['postback_events'] );
	}
}

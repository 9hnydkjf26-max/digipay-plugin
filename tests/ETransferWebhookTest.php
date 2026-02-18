<?php
/**
 * Tests for E-Transfer webhook handler.
 *
 * Tests HMAC signature verification, timestamp validation, event deduplication,
 * flexible payload extraction, status mapping, and rate limiting.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for WCPG_ETransfer_Webhook_Handler.
 */
class ETransferWebhookTest extends DigipayTestCase {

	/**
	 * Webhook handler instance.
	 *
	 * @var WCPG_ETransfer_Webhook_Handler
	 */
	private $handler;

	/**
	 * Test webhook secret key.
	 *
	 * @var string
	 */
	private $test_secret = 'test_webhook_secret_key_123';

	/**
	 * Set up test fixtures.
	 */
	protected function set_up() {
		parent::set_up();
		global $wcpg_test_transients, $wcpg_mock_options, $wcpg_mock_orders;
		$wcpg_test_transients = array();
		$wcpg_mock_orders     = array();

		// Configure webhook secret in gateway settings.
		$wcpg_mock_options['woocommerce_digipay_etransfer_settings'] = array(
			'enabled'            => 'yes',
			'webhook_secret_key' => $this->test_secret,
		);

		$this->handler = new WCPG_ETransfer_Webhook_Handler();
	}

	/**
	 * Tear down test fixtures.
	 */
	protected function tear_down() {
		global $wcpg_test_transients, $wcpg_mock_options, $wcpg_mock_orders;
		$wcpg_test_transients = array();
		$wcpg_mock_options    = array();
		$wcpg_mock_orders     = array();
		parent::tear_down();
	}

	/**
	 * Create a signed webhook request.
	 *
	 * @param array  $payload  JSON payload.
	 * @param string $secret   Webhook secret (defaults to test secret).
	 * @param string $event_id Event ID.
	 * @param int    $timestamp Unix timestamp (defaults to now).
	 * @return WP_REST_Request
	 */
	private function create_signed_request( $payload, $secret = null, $event_id = 'evt_test_123', $timestamp = null ) {
		if ( null === $secret ) {
			$secret = $this->test_secret;
		}
		if ( null === $timestamp ) {
			$timestamp = time();
		}

		$body           = json_encode( $payload );
		$signed_content = $timestamp . '.' . $body;
		$signature      = hash_hmac( 'sha512', $signed_content, $secret );

		$request = new WP_REST_Request();
		$request->set_body( $body );
		$request->set_header( 'x_shardnexus_webhook_signature', $signature );
		$request->set_header( 'x_shardnexus_webhook_timestamp', (string) $timestamp );
		$request->set_header( 'x_shardnexus_webhook_event_id', $event_id );

		return $request;
	}

	// ---------------------------------------------------------------
	// Signature Verification Tests
	// ---------------------------------------------------------------

	/**
	 * Test valid HMAC-SHA512 signature passes verification.
	 */
	public function test_verify_signature_valid() {
		$payload   = '{"data":{"reference":"REF123","status":"Approved"}}';
		$timestamp = (string) time();
		$secret    = $this->test_secret;

		$signed_content = $timestamp . '.' . $payload;
		$signature      = hash_hmac( 'sha512', $signed_content, $secret );

		$this->assertTrue( $this->handler->verify_signature( $payload, $timestamp, $signature, $secret ) );
	}

	/**
	 * Test invalid signature fails verification.
	 */
	public function test_verify_signature_invalid() {
		$payload   = '{"data":{"reference":"REF123","status":"Approved"}}';
		$timestamp = (string) time();
		$secret    = $this->test_secret;

		$this->assertFalse( $this->handler->verify_signature( $payload, $timestamp, 'invalid_signature', $secret ) );
	}

	/**
	 * Test wrong secret fails verification.
	 */
	public function test_verify_signature_wrong_secret() {
		$payload   = '{"data":{"reference":"REF123","status":"Approved"}}';
		$timestamp = (string) time();

		$signed_content = $timestamp . '.' . $payload;
		$signature      = hash_hmac( 'sha512', $signed_content, $this->test_secret );

		$this->assertFalse( $this->handler->verify_signature( $payload, $timestamp, $signature, 'wrong_secret' ) );
	}

	/**
	 * Test tampered payload fails verification.
	 */
	public function test_verify_signature_tampered_payload() {
		$original_payload = '{"data":{"reference":"REF123","status":"Approved"}}';
		$tampered_payload = '{"data":{"reference":"REF123","status":"Failed"}}';
		$timestamp        = (string) time();
		$secret           = $this->test_secret;

		$signed_content = $timestamp . '.' . $original_payload;
		$signature      = hash_hmac( 'sha512', $signed_content, $secret );

		$this->assertFalse( $this->handler->verify_signature( $tampered_payload, $timestamp, $signature, $secret ) );
	}

	// ---------------------------------------------------------------
	// Timestamp Validation Tests
	// ---------------------------------------------------------------

	/**
	 * Test current timestamp passes validation.
	 */
	public function test_validate_timestamp_current() {
		$this->assertTrue( $this->handler->validate_timestamp( (string) time() ) );
	}

	/**
	 * Test timestamp within 5-minute window passes.
	 */
	public function test_validate_timestamp_within_window() {
		$this->assertTrue( $this->handler->validate_timestamp( (string) ( time() - 200 ) ) );
	}

	/**
	 * Test stale timestamp (> 5 min) fails validation.
	 */
	public function test_validate_timestamp_stale() {
		$this->assertFalse( $this->handler->validate_timestamp( (string) ( time() - 400 ) ) );
	}

	/**
	 * Test non-numeric timestamp fails validation.
	 */
	public function test_validate_timestamp_non_numeric() {
		$this->assertFalse( $this->handler->validate_timestamp( 'not-a-timestamp' ) );
	}

	/**
	 * Test future timestamp within window passes.
	 */
	public function test_validate_timestamp_future_within_window() {
		$this->assertTrue( $this->handler->validate_timestamp( (string) ( time() + 100 ) ) );
	}

	/**
	 * Test far-future timestamp fails.
	 */
	public function test_validate_timestamp_far_future() {
		$this->assertFalse( $this->handler->validate_timestamp( (string) ( time() + 400 ) ) );
	}

	// ---------------------------------------------------------------
	// Event Deduplication Tests
	// ---------------------------------------------------------------

	/**
	 * Test first event is not a duplicate.
	 */
	public function test_event_first_time_not_duplicate() {
		$this->assertFalse( $this->handler->is_duplicate_event( 'evt_unique_001' ) );
	}

	/**
	 * Test same event ID is detected as duplicate.
	 */
	public function test_event_duplicate_detected() {
		// First call sets the transient.
		$this->handler->is_duplicate_event( 'evt_dup_001' );

		// Second call should detect duplicate.
		$this->assertTrue( $this->handler->is_duplicate_event( 'evt_dup_001' ) );
	}

	/**
	 * Test different event IDs are not duplicates of each other.
	 */
	public function test_different_events_not_duplicate() {
		$this->handler->is_duplicate_event( 'evt_a' );
		$this->assertFalse( $this->handler->is_duplicate_event( 'evt_b' ) );
	}

	// ---------------------------------------------------------------
	// Rate Limiting Tests
	// ---------------------------------------------------------------

	/**
	 * Test rate limit allows first request.
	 */
	public function test_rate_limit_allows_first() {
		$this->assertTrue( $this->handler->check_rate_limit( '10.0.0.1' ) );
	}

	/**
	 * Test rate limit blocks after 60 requests.
	 */
	public function test_rate_limit_blocks_over_60() {
		$ip = '10.0.0.2';
		// Simulate 60 requests.
		$transient_key = 'wcpg_etwrl_' . md5( $ip );
		set_transient( $transient_key, 60, 60 );

		$this->assertFalse( $this->handler->check_rate_limit( $ip ) );
	}

	/**
	 * Test rate limit allows under limit.
	 */
	public function test_rate_limit_allows_under_limit() {
		$ip = '10.0.0.3';
		$transient_key = 'wcpg_etwrl_' . md5( $ip );
		set_transient( $transient_key, 30, 60 );

		$this->assertTrue( $this->handler->check_rate_limit( $ip ) );
	}

	// ---------------------------------------------------------------
	// Flexible Payload Extraction Tests
	// ---------------------------------------------------------------

	/**
	 * Test extract reference from data.reference path.
	 */
	public function test_extract_reference_data_path() {
		$payload = array(
			'data' => array(
				'reference' => 'REF-001',
				'status'    => 'Approved',
			),
		);

		$this->assertEquals( 'REF-001', $this->handler->extract_field( $payload, 'reference' ) );
	}

	/**
	 * Test extract reference from data.transaction.reference path.
	 */
	public function test_extract_reference_transaction_path() {
		$payload = array(
			'data' => array(
				'transaction' => array(
					'reference' => 'REF-002',
					'status'    => 'Approved',
				),
			),
		);

		$this->assertEquals( 'REF-002', $this->handler->extract_field( $payload, 'reference' ) );
	}

	/**
	 * Test extract reference from top-level path.
	 */
	public function test_extract_reference_top_level() {
		$payload = array(
			'reference' => 'REF-003',
			'status'    => 'Approved',
		);

		$this->assertEquals( 'REF-003', $this->handler->extract_field( $payload, 'reference' ) );
	}

	/**
	 * Test extract reference from deeply nested path (recursive).
	 */
	public function test_extract_reference_recursive() {
		$payload = array(
			'event' => array(
				'details' => array(
					'payment' => array(
						'reference' => 'REF-004',
					),
				),
			),
		);

		$this->assertEquals( 'REF-004', $this->handler->extract_field( $payload, 'reference' ) );
	}

	/**
	 * Test extract returns null when field not found.
	 */
	public function test_extract_field_not_found() {
		$payload = array(
			'data' => array(
				'amount' => 100,
			),
		);

		$this->assertNull( $this->handler->extract_field( $payload, 'reference' ) );
	}

	/**
	 * Test extract status from data.status path.
	 */
	public function test_extract_status_data_path() {
		$payload = array(
			'data' => array(
				'reference' => 'REF-005',
				'status'    => 'Failed',
			),
		);

		$this->assertEquals( 'Failed', $this->handler->extract_field( $payload, 'status' ) );
	}

	/**
	 * Test data path takes priority over top-level.
	 */
	public function test_extract_data_path_priority() {
		$payload = array(
			'reference' => 'TOP-LEVEL',
			'data'      => array(
				'reference' => 'DATA-LEVEL',
			),
		);

		$this->assertEquals( 'DATA-LEVEL', $this->handler->extract_field( $payload, 'reference' ) );
	}

	// ---------------------------------------------------------------
	// Webhook Handler Integration Tests
	// ---------------------------------------------------------------

	/**
	 * Test missing headers returns 400.
	 */
	public function test_handle_webhook_missing_headers() {
		$request = new WP_REST_Request();
		$request->set_body( '{}' );

		$response = $this->handler->handle_webhook( $request );

		$this->assertEquals( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['success'] );
		$this->assertEquals( 'Missing required headers', $data['message'] );
	}

	/**
	 * Test stale timestamp returns 400.
	 */
	public function test_handle_webhook_stale_timestamp() {
		$payload   = array( 'data' => array( 'reference' => 'REF-T1' ) );
		$body      = json_encode( $payload );
		$timestamp = time() - 600; // 10 minutes ago
		$signature = hash_hmac( 'sha512', $timestamp . '.' . $body, $this->test_secret );

		$request = new WP_REST_Request();
		$request->set_body( $body );
		$request->set_header( 'x_shardnexus_webhook_signature', $signature );
		$request->set_header( 'x_shardnexus_webhook_timestamp', (string) $timestamp );
		$request->set_header( 'x_shardnexus_webhook_event_id', 'evt_stale' );

		$response = $this->handler->handle_webhook( $request );

		$this->assertEquals( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'Stale timestamp', $data['message'] );
	}

	/**
	 * Test invalid signature returns 401.
	 */
	public function test_handle_webhook_invalid_signature() {
		$request = new WP_REST_Request();
		$request->set_body( '{"data":{"reference":"REF"}}' );
		$request->set_header( 'x_shardnexus_webhook_signature', 'bad_sig' );
		$request->set_header( 'x_shardnexus_webhook_timestamp', (string) time() );
		$request->set_header( 'x_shardnexus_webhook_event_id', 'evt_badsig' );

		$response = $this->handler->handle_webhook( $request );

		$this->assertEquals( 401, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'Invalid signature', $data['message'] );
	}

	/**
	 * Test duplicate event returns 200 (already processed).
	 */
	public function test_handle_webhook_duplicate_event() {
		$payload = array( 'data' => array( 'reference' => 'REF-DUP', 'status' => 'Approved' ) );

		// First request.
		$request1 = $this->create_signed_request( $payload, null, 'evt_dup_test' );
		$this->handler->handle_webhook( $request1 );

		// Second request with same event ID.
		$request2 = $this->create_signed_request( $payload, null, 'evt_dup_test' );
		$response = $this->handler->handle_webhook( $request2 );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'Already processed', $data['message'] );
	}

	/**
	 * Test unmatched reference returns 200 (not 404, to prevent retries).
	 */
	public function test_handle_webhook_unmatched_reference_returns_200() {
		$payload = array( 'data' => array( 'reference' => 'REF-NOMATCH', 'status' => 'Approved' ) );
		$request = $this->create_signed_request( $payload, null, 'evt_nomatch' );

		$response = $this->handler->handle_webhook( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'Order not found for reference', $data['message'] );
	}

	/**
	 * Test no reference in payload returns 200.
	 */
	public function test_handle_webhook_no_reference_returns_200() {
		$payload = array( 'data' => array( 'amount' => 100 ) );
		$request = $this->create_signed_request( $payload, null, 'evt_noref' );

		$response = $this->handler->handle_webhook( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'No reference found', $data['message'] );
	}

	/**
	 * Test webhook with no secret configured returns 500.
	 */
	public function test_handle_webhook_no_secret_configured() {
		global $wcpg_mock_options;
		$wcpg_mock_options['woocommerce_digipay_etransfer_settings'] = array(
			'enabled' => 'yes',
		);

		$request = new WP_REST_Request();
		$request->set_body( '{"data":{"reference":"REF"}}' );
		$request->set_header( 'x_shardnexus_webhook_signature', 'sig' );
		$request->set_header( 'x_shardnexus_webhook_timestamp', (string) time() );
		$request->set_header( 'x_shardnexus_webhook_event_id', 'evt_nosecret' );

		$response = $this->handler->handle_webhook( $request );

		$this->assertEquals( 500, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'Webhook not configured', $data['message'] );
	}

	/**
	 * Test invalid JSON payload returns 400.
	 */
	public function test_handle_webhook_invalid_json() {
		$bad_body  = 'not json at all';
		$timestamp = (string) time();
		$signature = hash_hmac( 'sha512', $timestamp . '.' . $bad_body, $this->test_secret );

		$request = new WP_REST_Request();
		$request->set_body( $bad_body );
		$request->set_header( 'x_shardnexus_webhook_signature', $signature );
		$request->set_header( 'x_shardnexus_webhook_timestamp', $timestamp );
		$request->set_header( 'x_shardnexus_webhook_event_id', 'evt_badjson' );

		$response = $this->handler->handle_webhook( $request );

		$this->assertEquals( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'Invalid JSON payload', $data['message'] );
	}

	/**
	 * Test rate limit exceeded returns 429.
	 */
	public function test_handle_webhook_rate_limited() {
		// Simulate rate limit exceeded for test IP.
		$ip = '0.0.0.0'; // Default when no SERVER vars set.
		$transient_key = 'wcpg_etwrl_' . md5( $ip );
		set_transient( $transient_key, 60, 60 );

		$request = new WP_REST_Request();
		$request->set_body( '{}' );

		$response = $this->handler->handle_webhook( $request );

		$this->assertEquals( 429, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'Rate limit exceeded', $data['message'] );
	}
}

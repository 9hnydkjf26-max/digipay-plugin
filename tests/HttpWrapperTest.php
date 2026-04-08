<?php
/**
 * Tests for wcpg_http_request wrapper and wcpg_redact_url_query helper.
 *
 * @package DigipayMasterPlugin
 * @since 13.3.0
 */

require_once dirname( __DIR__ ) . '/support/class-event-log.php';
require_once dirname( __DIR__ ) . '/support/class-context-bundler.php';

/**
 * Unit tests for the outbound HTTP wrapper.
 */
class HttpWrapperTest extends DigipayTestCase {

	/**
	 * Clear event log and mock HTTP response before each test.
	 */
	protected function set_up() {
		parent::set_up();
		WCPG_Event_Log::clear();
		$GLOBALS['wcpg_mock_http_response'] = null;
	}

	/**
	 * Reset global state after each test.
	 */
	protected function tear_down() {
		WCPG_Event_Log::clear();
		unset( $GLOBALS['wcpg_mock_http_response'] );
		parent::tear_down();
	}

	// ------------------------------------------------------------------
	// wcpg_redact_url_query tests
	// ------------------------------------------------------------------

	/**
	 * Secret query-string params get their values replaced with [REDACTED].
	 */
	public function test_redact_url_query_masks_secret_params() {
		$input  = 'https://example.com/x?api_key=abc&name=foo';
		$output = wcpg_redact_url_query( $input );

		$this->assertStringNotContainsString( 'abc', $output, 'Secret value must be removed' );
		$this->assertStringContainsString( 'api_key', $output, 'Key must remain' );
		$this->assertStringContainsString( 'name=foo', $output, 'Non-secret param must be intact' );
		$this->assertMatchesRegularExpression( '/api_key=.*REDACTED.*/', $output );
	}

	/**
	 * Non-secret params are returned unchanged.
	 */
	public function test_redact_url_query_preserves_non_secret_params() {
		$input  = 'https://example.com/path?site_id=123&env=production';
		$output = wcpg_redact_url_query( $input );

		$this->assertSame( $input, $output, 'URL with no secret params must be unchanged' );
	}

	/**
	 * A URL with no query string is returned unchanged.
	 */
	public function test_redact_url_query_no_query_string() {
		$input  = 'https://example.com/api/v1/endpoint';
		$output = wcpg_redact_url_query( $input );

		$this->assertSame( $input, $output );
	}

	/**
	 * Multiple secret params in the same URL are all redacted.
	 */
	public function test_redact_url_query_masks_multiple_secret_params() {
		$input  = 'https://example.com/?api_key=abc&token=xyz&name=foo';
		$output = wcpg_redact_url_query( $input );

		$this->assertStringNotContainsString( 'abc', $output );
		$this->assertStringNotContainsString( 'xyz', $output );
		$this->assertStringContainsString( 'name=foo', $output );
	}

	// ------------------------------------------------------------------
	// wcpg_http_request tests
	// ------------------------------------------------------------------

	/**
	 * Successful request records a TYPE_API_CALL event with redacted URL.
	 */
	public function test_http_request_records_api_call_event() {
		// Stub a 200 response.
		$GLOBALS['wcpg_mock_http_response'] = array(
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'body'     => '{"success":true}',
			'headers'  => array(),
		);

		wcpg_http_request( 'https://example.com/api?token=secret123' );

		$events = WCPG_Event_Log::recent( 1, WCPG_Event_Log::TYPE_API_CALL );
		$this->assertCount( 1, $events, 'Should have recorded one API_CALL event' );

		$data = $events[0]['data'];
		$this->assertSame( 200, $data['status'] );
		$this->assertStringNotContainsString( 'secret123', $data['url'], 'Token value must be redacted from URL' );
		$this->assertStringContainsString( 'REDACTED', $data['url'], 'URL must show [REDACTED] placeholder' );
		$this->assertSame( 'GET', $data['method'] );
		$this->assertArrayHasKey( 'elapsed_ms', $data );
		$this->assertIsInt( $data['elapsed_ms'] );
	}

	/**
	 * Response body containing an email address is scrubbed in body_preview.
	 */
	public function test_http_request_redacts_body_pii() {
		$GLOBALS['wcpg_mock_http_response'] = array(
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'body'     => '{"email":"jane.doe@example.com","status":"ok"}',
			'headers'  => array(),
		);

		wcpg_http_request( 'https://example.com/api' );

		$events = WCPG_Event_Log::recent( 1, WCPG_Event_Log::TYPE_API_CALL );
		$this->assertCount( 1, $events );

		$preview = $events[0]['data']['body_preview'];
		$this->assertStringNotContainsString( 'jane.doe@example.com', $preview, 'Raw email must not appear in preview' );
		$this->assertStringContainsString( '[EMAIL]', $preview, 'Scrubbed placeholder must appear' );
	}

	/**
	 * WP_Error response is recorded with status=0 and non-empty error message.
	 */
	public function test_http_request_records_wp_error() {
		$GLOBALS['wcpg_mock_http_response'] = new WP_Error( 'http_request_failed', 'Connection timed out' );

		wcpg_http_request( 'https://example.com/api' );

		$events = WCPG_Event_Log::recent( 1, WCPG_Event_Log::TYPE_API_CALL );
		$this->assertCount( 1, $events );

		$data = $events[0]['data'];
		$this->assertSame( 0, $data['status'], 'WP_Error must produce status=0' );
		$this->assertNotEmpty( $data['error'], 'WP_Error message must be recorded' );
		$this->assertStringContainsString( 'timed out', $data['error'] );
	}

	/**
	 * Return value of wcpg_http_request is identical to the raw response.
	 */
	public function test_http_request_returns_raw_response() {
		$mock = array(
			'response' => array( 'code' => 201, 'message' => 'Created' ),
			'body'     => 'created',
			'headers'  => array(),
		);
		$GLOBALS['wcpg_mock_http_response'] = $mock;

		$result = wcpg_http_request( 'https://example.com/api', array( 'method' => 'POST' ) );

		$this->assertSame( $mock, $result, 'Must return the raw wp_remote_request response' );
	}

	/**
	 * Method from args is recorded in the event.
	 */
	public function test_http_request_records_method_from_args() {
		$GLOBALS['wcpg_mock_http_response'] = array(
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'body'     => '',
			'headers'  => array(),
		);

		wcpg_http_request( 'https://example.com/api', array( 'method' => 'POST' ) );

		$events = WCPG_Event_Log::recent( 1, WCPG_Event_Log::TYPE_API_CALL );
		$this->assertSame( 'POST', $events[0]['data']['method'] );
	}
}

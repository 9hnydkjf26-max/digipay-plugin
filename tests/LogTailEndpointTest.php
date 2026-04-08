<?php
/**
 * Tests for WCPG_Log_Tail_Endpoint
 *
 * @package DigipayMasterPlugin
 */

require_once dirname( __DIR__ ) . '/support/class-context-bundler.php';
require_once dirname( __DIR__ ) . '/support/class-log-tail-endpoint.php';

/**
 * Unit tests for the live log tail REST endpoint.
 */
class LogTailEndpointTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Make a fresh endpoint instance.
	 *
	 * @return WCPG_Log_Tail_Endpoint
	 */
	private function make_endpoint() {
		return new WCPG_Log_Tail_Endpoint();
	}

	// ------------------------------------------------------------------
	// Test 1 — Response has expected top-level shape
	// ------------------------------------------------------------------

	/**
	 * handle_request() returns a WP_REST_Response with 'ts' and 'sources'.
	 */
	public function test_handle_request_returns_expected_shape() {
		$endpoint = $this->make_endpoint();
		$request  = new WP_REST_Request();
		$response = $endpoint->handle_request( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'ts', $data );
		$this->assertArrayHasKey( 'sources', $data );
		$this->assertIsArray( $data['sources'] );
		$this->assertCount( 4, $data['sources'] );
	}

	// ------------------------------------------------------------------
	// Test 2 — Each source entry has the 3 required fields
	// ------------------------------------------------------------------

	/**
	 * Every source entry must have 'name', 'file', and 'lines'.
	 */
	public function test_each_source_has_name_file_lines() {
		$endpoint = $this->make_endpoint();
		$request  = new WP_REST_Request();
		$response = $endpoint->handle_request( $request );

		$data = $response->get_data();
		foreach ( $data['sources'] as $source ) {
			$this->assertArrayHasKey( 'name', $source, 'Source entry missing "name"' );
			$this->assertArrayHasKey( 'file', $source, 'Source entry missing "file"' );
			$this->assertArrayHasKey( 'lines', $source, 'Source entry missing "lines"' );
			$this->assertIsArray( $source['lines'], '"lines" should be an array' );
		}
	}

	// ------------------------------------------------------------------
	// Test 3 — Graceful degradation without WC_LOG_DIR
	// ------------------------------------------------------------------

	/**
	 * Without WC_LOG_DIR defined, the endpoint should still return a valid
	 * response with empty lines arrays and null file paths.
	 */
	public function test_handle_request_gracefully_handles_missing_wc_log_dir() {
		// WC_LOG_DIR is not defined in the test environment, so this covers
		// the graceful-degradation path automatically.
		$this->assertFalse( defined( 'WC_LOG_DIR' ), 'WC_LOG_DIR should not be defined in the test bootstrap' );

		$endpoint = $this->make_endpoint();
		$request  = new WP_REST_Request();
		$response = $endpoint->handle_request( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'sources', $data );
		$this->assertCount( 4, $data['sources'] );

		foreach ( $data['sources'] as $source ) {
			$this->assertNull( $source['file'], 'File should be null when WC_LOG_DIR is not defined' );
			$this->assertSame( array(), $source['lines'], 'Lines should be empty when WC_LOG_DIR is not defined' );
		}
	}

	// ------------------------------------------------------------------
	// Test 4 — PII scrubbing (skipped if WC_LOG_DIR already defined)
	// ------------------------------------------------------------------

	/**
	 * Lines from log files should have email addresses scrubbed.
	 *
	 * TODO: This test requires WC_LOG_DIR to be defined at the start of the
	 * test process. Since `define()` is permanent, we cannot dynamically set
	 * WC_LOG_DIR per test. We rely on $GLOBALS['wcpg_test_log_dir'] override
	 * which the endpoint reads in test mode.
	 */
	public function test_lines_are_scrubbed() {
		// Create a temp dir with a fake log file.
		$tmp_dir = sys_get_temp_dir() . '/wcpg_test_logs_' . uniqid();
		mkdir( $tmp_dir, 0755, true );
		$tmp_dir = rtrim( $tmp_dir, '/' ) . '/';

		// Write a fake log file for the first source (digipay-postback).
		$log_file = $tmp_dir . 'digipay-postback-' . gmdate( 'Y-m-d' ) . '.log';
		file_put_contents( $log_file, "Order processed for customer@example.com\n" );

		// Override the log dir for test mode.
		$GLOBALS['wcpg_test_log_dir'] = $tmp_dir;

		try {
			$endpoint = $this->make_endpoint();
			$request  = new WP_REST_Request();
			$response = $endpoint->handle_request( $request );

			$data = $response->get_data();

			// Find the digipay-postback source.
			$postback_source = null;
			foreach ( $data['sources'] as $source ) {
				if ( 'digipay-postback' === $source['name'] ) {
					$postback_source = $source;
					break;
				}
			}

			$this->assertNotNull( $postback_source, 'digipay-postback source not found' );
			$this->assertNotEmpty( $postback_source['lines'], 'Expected log lines from temp file' );

			// The raw email should be scrubbed.
			$joined = implode( "\n", $postback_source['lines'] );
			$this->assertStringNotContainsString( 'customer@example.com', $joined, 'Raw email should be scrubbed' );
			$this->assertStringContainsString( '[EMAIL]', $joined, 'Email should be replaced with [EMAIL]' );
		} finally {
			// Cleanup.
			unset( $GLOBALS['wcpg_test_log_dir'] );
			@unlink( $log_file );
			@rmdir( $tmp_dir );
		}
	}

	// ------------------------------------------------------------------
	// Test 5 — Permission callback requires manage_woocommerce
	// ------------------------------------------------------------------

	/**
	 * The permission callback should return false when current_user_can returns false.
	 *
	 * The bootstrap mocks current_user_can() to always return true. We use
	 * $GLOBALS['wcpg_mock_user_can'] to override it — the bootstrap's mock
	 * must check this global (added in this PR).
	 */
	public function test_permission_callback_requires_manage_woocommerce() {
		$endpoint = $this->make_endpoint();

		// Deny the capability via global override.
		$GLOBALS['wcpg_mock_user_can'] = false;
		try {
			$result = $endpoint->permission_callback();
			$this->assertFalse( $result, 'Permission callback should return false when user lacks manage_woocommerce' );
		} finally {
			unset( $GLOBALS['wcpg_mock_user_can'] );
		}

		// Also verify it returns true when capability is granted (default).
		$result = $endpoint->permission_callback();
		$this->assertTrue( $result, 'Permission callback should return true for capable user' );
	}
}

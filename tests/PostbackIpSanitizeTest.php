<?php
/**
 * Tests for legacy postback IP sanitization.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test postback logging sanitizes IP addresses.
 */
class PostbackIpSanitizeTest extends DigipayTestCase {

	private function get_postback_source() {
		return file_get_contents( dirname( __DIR__ ) . '/paygo_postback.php' );
	}

	public function test_ip_address_is_sanitized_with_filter_var() {
		$source = $this->get_postback_source();

		// The logging function should use filter_var for IP validation.
		$this->assertStringContainsString( 'filter_var', $source, 'paygo_postback.php should use filter_var to sanitize IP addresses' );
		$this->assertStringContainsString( 'FILTER_VALIDATE_IP', $source, 'paygo_postback.php should use FILTER_VALIDATE_IP' );
	}
}

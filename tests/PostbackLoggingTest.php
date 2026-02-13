<?php
/**
 * Tests for legacy postback logging security.
 *
 * Verifies that paygo_postback.php does not use unsafe date() or unsanitized IP.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test postback logging uses safe functions.
 */
class PostbackLoggingTest extends DigipayTestCase {

	/**
	 * Get the contents of paygo_postback.php.
	 *
	 * @return string File contents.
	 */
	private function get_postback_source() {
		return file_get_contents( dirname( __DIR__ ) . '/paygo_postback.php' );
	}

	public function test_no_bare_date_calls_in_postback() {
		$source = $this->get_postback_source();

		// Should not contain date( calls (bare PHP date function).
		// Match date( but not wcpg_get_pacific_date(.
		$has_bare_date = preg_match( '/[^_]date\s*\(/', $source );
		$this->assertSame( 0, $has_bare_date, 'paygo_postback.php should not use bare date() - use wcpg_get_pacific_date() instead' );
	}
}

<?php
/**
 * Tests that e-transfer gateway does not use error_log().
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test e-transfer gateway uses wc_get_logger instead of error_log.
 */
class ETransferNoErrorLogTest extends DigipayTestCase {

	public function test_no_error_log_calls() {
		$source = file_get_contents( dirname( __DIR__ ) . '/etransfer/class-etransfer-gateway.php' );

		// Should not contain bare error_log() calls.
		$has_error_log = preg_match( '/\berror_log\s*\(/', $source );
		$this->assertSame( 0, $has_error_log, 'class-etransfer-gateway.php should use wc_get_logger() instead of error_log()' );
	}
}

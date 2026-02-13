<?php
/**
 * Tests for diagnostics dead code removal.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test that wcpg_display_diagnostics dead code is removed.
 */
class DiagnosticsDeadCodeTest extends DigipayTestCase {

	public function test_no_display_diagnostics_function() {
		$source = file_get_contents( dirname( __DIR__ ) . '/wcpg-diagnostics.php' );

		// The dead wcpg_display_diagnostics function should not exist.
		$this->assertStringNotContainsString(
			'function wcpg_display_diagnostics',
			$source,
			'wcpg_display_diagnostics() is dead code and should be removed'
		);
	}
}

<?php
/**
 * Tests for diagnostics orphaned code cleanup.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test that orphaned wcpg_display_diagnostics body is removed.
 */
class DiagnosticsOrphanedCodeTest extends DigipayTestCase {

	public function test_no_orphaned_display_diagnostics_body() {
		$source = file_get_contents( dirname( __DIR__ ) . '/wcpg-diagnostics.php' );

		// The orphaned HTML body of the removed function should not remain.
		// The orphaned code uses unsanitized SERVER_SOFTWARE; the live function uses sanitize_text_field.
		$this->assertStringNotContainsString(
			"esc_html( \$_SERVER['SERVER_SOFTWARE'] )",
			$source,
			'Orphaned wcpg_display_diagnostics() HTML body should be removed'
		);
	}
}

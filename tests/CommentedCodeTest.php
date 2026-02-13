<?php
/**
 * Tests for commented code removal.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for verifying commented code has been removed.
 */
class CommentedCodeTest extends DigipayTestCase {

    /**
     * Test that main plugin file has no large commented code blocks.
     */
    public function test_no_large_commented_code_blocks() {
        $plugin_file = dirname( __DIR__ ) . '/woocommerce-gateway-paygo.php';
        $content = file_get_contents( $plugin_file );

        // Should not contain commented error_reporting block.
        $this->assertStringNotContainsString( '//error_reporting(E_ALL);', $content, 'Commented error_reporting should be removed' );
    }
}

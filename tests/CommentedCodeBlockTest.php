<?php
/**
 * Tests for large commented code block removal.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for verifying large commented code blocks are removed.
 */
class CommentedCodeBlockTest extends DigipayTestCase {

    /**
     * Test that main plugin file has no commented get_plugin_data block.
     */
    public function test_no_commented_get_plugin_data_block() {
        $plugin_file = dirname( __DIR__ ) . '/woocommerce-gateway-paygo.php';
        $content = file_get_contents( $plugin_file );

        // Should not contain commented get_plugin_data block.
        $this->assertStringNotContainsString( "/*\nif( !function_exists('get_plugin_data')", $content, 'Commented get_plugin_data block should be removed' );
    }
}

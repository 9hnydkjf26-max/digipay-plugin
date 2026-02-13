<?php
/**
 * Tests for error suppression removal in Digipay.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for error suppression removal.
 */
class ErrorSuppressionTest extends DigipayTestCase {

    /**
     * Test that main plugin file does not contain @ error suppression operators.
     */
    public function test_no_error_suppression_in_get_option_calls() {
        $plugin_file = dirname( __DIR__ ) . '/woocommerce-gateway-paygo.php';
        $content = file_get_contents( $plugin_file );

        // Should not contain @$this->get_option pattern.
        $this->assertStringNotContainsString( '@$this->get_option', $content, 'Plugin should not use @ error suppression on get_option calls' );
    }
}

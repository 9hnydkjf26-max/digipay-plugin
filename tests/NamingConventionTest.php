<?php
/**
 * Tests for naming convention standardization.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for verifying naming conventions.
 */
class NamingConventionTest extends DigipayTestCase {

    /**
     * Test that the gateway registration function uses the wcpg_ prefix.
     */
    public function test_add_gateway_function_renamed() {
        $plugin_file = dirname( __DIR__ ) . '/woocommerce-gateway-paygo.php';
        $content = file_get_contents( $plugin_file );

        $this->assertStringContainsString( 'function wcpg_add_gateway', $content, 'wcpg_add_gateway function should exist' );
    }
}

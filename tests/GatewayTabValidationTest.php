<?php
/**
 * Tests for gateway tab whitelist validation.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for gateway tab validation.
 */
class GatewayTabValidationTest extends DigipayTestCase {

    /**
     * Test that gateway tab values are whitelisted in admin_options.
     */
    public function test_gateway_tab_whitelist_in_admin_options() {
        $plugin_file = dirname( __DIR__ ) . '/woocommerce-gateway-paygo.php';
        $content = file_get_contents( $plugin_file );

        // Should contain in_array check for valid tabs.
        $this->assertStringContainsString( "in_array( \$current_tab", $content, 'Gateway tab should be validated against whitelist' );
    }
}

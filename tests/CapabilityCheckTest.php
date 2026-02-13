<?php
/**
 * Tests for capability checks in admin functions.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for capability checks.
 */
class CapabilityCheckTest extends DigipayTestCase {

    /**
     * Test that admin notice function contains capability check.
     */
    public function test_admin_notice_has_capability_check() {
        $plugin_file = dirname( __DIR__ ) . '/woocommerce-gateway-paygo.php';
        $content = file_get_contents( $plugin_file );

        // Extract the wcpg_daily_limit_admin_notice function.
        preg_match( '/function wcpg_daily_limit_admin_notice\(\).*?^\}/ms', $content, $matches );

        $this->assertNotEmpty( $matches, 'Function should exist' );
        $this->assertStringContainsString( 'current_user_can', $matches[0], 'Admin notice function should check user capabilities' );
    }
}

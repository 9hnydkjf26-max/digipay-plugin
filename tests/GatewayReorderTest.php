<?php
/**
 * Tests for digipay_reorder_gateways behavior.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for gateway reordering behavior.
 */
class GatewayReorderTest extends DigipayTestCase {

    /**
     * Test that reorder_gateways puts paygobillingcc at position 0.
     */
    public function test_reorder_gateways_puts_digipay_first() {
        $ordering = array(
            'stripe' => 0,
            'paypal' => 1,
            'paygobillingcc' => 2,
        );

        $result = digipay_reorder_gateways( $ordering );

        $this->assertEquals( 0, $result['paygobillingcc'], 'Digipay should be at position 0' );
    }
}

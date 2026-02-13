<?php
/**
 * Tests for gateway ordering consolidation.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for gateway ordering helper.
 */
class GatewayOrderingTest extends DigipayTestCase {

    /**
     * Test that digipay_reorder_gateways function exists.
     */
    public function test_reorder_gateways_function_exists() {
        $this->assertTrue( function_exists( 'digipay_reorder_gateways' ), 'digipay_reorder_gateways function should exist' );
    }
}

<?php
/**
 * Tests for Digipay constants.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for verifying constants are defined.
 */
class ConstantsTest extends DigipayTestCase {

    /**
     * Test that DIGIPAY_GATEWAY_ID constant is defined.
     */
    public function test_gateway_id_constant_defined() {
        $this->assertTrue( defined( 'DIGIPAY_GATEWAY_ID' ), 'DIGIPAY_GATEWAY_ID constant should be defined' );
    }
}

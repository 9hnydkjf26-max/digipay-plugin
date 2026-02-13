<?php
/**
 * Tests for DIGIPAY_API_URL constant.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for verifying API URL constant.
 */
class ApiUrlConstantTest extends DigipayTestCase {

    /**
     * Test that DIGIPAY_API_URL constant is defined.
     */
    public function test_api_url_constant_defined() {
        $this->assertTrue( defined( 'DIGIPAY_API_URL' ), 'DIGIPAY_API_URL constant should be defined' );
    }
}

<?php
/**
 * Tests for URL escaping in Digipay payment redirect.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for URL escaping functions.
 */
class UrlEscapingTest extends DigipayTestCase {

    /**
     * Test that digipay_build_payment_url function exists.
     */
    public function test_build_payment_url_function_exists() {
        $this->assertTrue( function_exists( 'digipay_build_payment_url' ), 'digipay_build_payment_url function should exist' );
    }
}

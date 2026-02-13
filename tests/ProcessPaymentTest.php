<?php
/**
 * Tests for process_payment method refactoring.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for process_payment helper methods.
 */
class ProcessPaymentTest extends DigipayTestCase {

    /**
     * Test that prepare_payment_params helper function exists.
     */
    public function test_prepare_payment_params_function_exists() {
        $this->assertTrue( function_exists( 'digipay_prepare_payment_params' ), 'digipay_prepare_payment_params function should exist' );
    }
}

<?php
/**
 * Tests for digipay_prepare_payment_params function.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for prepare_payment_params helper.
 */
class PreparePaymentParamsTest extends DigipayTestCase {

    /**
     * Test that prepare_payment_params returns billing_param key.
     */
    public function test_prepare_payment_params_returns_billing_param() {
        $order_data = $this->createMockOrderData();
        $result = digipay_prepare_payment_params( $order_data );

        $this->assertArrayHasKey( 'billing_param', $result, 'Result should contain billing_param key' );
    }
}

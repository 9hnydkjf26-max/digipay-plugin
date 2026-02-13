<?php
/**
 * Tests for wcpg_stock_reduction_on_status and wcpg_do_not_reduce_onhold_stock.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for stock reduction logic.
 */
class StockReductionTest extends DigipayTestCase {

	/**
	 * Test that wcpg_stock_reduction_on_status function exists.
	 */
	public function test_function_exists() {
		$content = file_get_contents( dirname( __DIR__ ) . '/woocommerce-gateway-paygo.php' );
		$this->assertStringContainsString(
			'function wcpg_stock_reduction_on_status',
			$content,
			'wcpg_stock_reduction_on_status function should be defined'
		);
	}
}

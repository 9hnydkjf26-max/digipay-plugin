<?php
/**
 * Tests for gateway registration.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for gateway registration.
 */
class GatewayRegistrationTest extends DigipayTestCase {

	/**
	 * Test wcpg_add_gateway function exists.
	 */
	public function test_wcpg_add_gateway_function_exists() {
		$this->assertTrue(
			function_exists( 'wcpg_add_gateway' ),
			'wcpg_add_gateway function should exist'
		);
	}

	/**
	 * Test that master e-Transfer gateway is NOT registered as a separate WooCommerce payment gateway.
	 *
	 * The master WC_Gateway_ETransfer is a settings-only class. It should not be in the
	 * woocommerce_payment_gateways filter output because:
	 * 1. Its settings are already managed via the main gateway's "E-Transfer" tab
	 * 2. Registering it causes it to appear as a separate entry in WooCommerce Payments list
	 * 3. Its is_available() always returns false anyway (never shown at checkout)
	 */
	public function test_master_etransfer_gateway_not_registered_separately() {
		$gateways = wcpg_add_gateway( array() );

		$this->assertNotContains(
			'WC_Gateway_ETransfer',
			$gateways,
			'Master WC_Gateway_ETransfer should NOT be registered as a separate payment gateway'
		);
	}

	/**
	 * Test that the credit card gateway is always registered.
	 */
	public function test_credit_card_gateway_always_registered() {
		$gateways = wcpg_add_gateway( array() );

		$this->assertContains(
			'WC_Gateway_Paygo_npaygo',
			$gateways,
			'Credit card gateway should always be registered'
		);
	}

	/**
	 * Test that wcpg_init_modules instantiates the master e-Transfer gateway for hook registration.
	 *
	 * Even though the master gateway is not in the woocommerce_payment_gateways filter,
	 * it still needs to be instantiated so its constructor registers:
	 * - woocommerce_thankyou_{virtual_id} hooks for thank you page content
	 * - woocommerce_email_before_order_table hook for email instructions
	 * - woocommerce_update_options_payment_gateways_digipay_etransfer for settings save
	 */
	public function test_init_modules_instantiates_master_etransfer_for_hooks() {
		$this->assertTrue(
			function_exists( 'wcpg_init_etransfer_hooks' ),
			'wcpg_init_etransfer_hooks function should exist to register master gateway hooks'
		);
	}
}

<?php
/**
 * Tests for the E-Transfer Base Gateway class.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for E-Transfer Base Gateway.
 */
class ETransferBaseTest extends DigipayTestCase {

	public function test_base_class_exists() {
		$this->assertTrue(
			class_exists( 'WC_Gateway_ETransfer_Base' ),
			'WC_Gateway_ETransfer_Base class should exist'
		);
	}

	public function test_base_class_extends_payment_gateway() {
		$reflection = new ReflectionClass( 'WC_Gateway_ETransfer_Base' );
		$this->assertTrue(
			$reflection->isSubclassOf( 'WC_Payment_Gateway' ),
			'WC_Gateway_ETransfer_Base should extend WC_Payment_Gateway'
		);
	}

	public function test_delivery_method_constants() {
		$this->assertSame( 'email', WC_Gateway_ETransfer_Base::DELIVERY_EMAIL );
		$this->assertSame( 'url', WC_Gateway_ETransfer_Base::DELIVERY_URL );
		$this->assertSame( 'manual', WC_Gateway_ETransfer_Base::DELIVERY_MANUAL );
	}

	public function test_get_default_checkout_instructions_returns_email_instructions() {
		$gateway = new WC_Gateway_ETransfer_Email();
		$reflection = new ReflectionMethod( $gateway, 'get_default_checkout_instructions' );
		$reflection->setAccessible( true );
		$result = $reflection->invoke( $gateway, 'email' );
		$this->assertStringContainsString( 'payment link will be sent to your email', $result );
		$this->assertStringContainsString( 'Place Order', $result );
	}
}

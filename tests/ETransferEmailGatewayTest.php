<?php
/**
 * Tests for the E-Transfer Email Gateway class.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for E-Transfer Email Gateway.
 */
class ETransferEmailGatewayTest extends DigipayTestCase {

	private $gateway;

	protected function set_up() {
		parent::set_up();
		$this->gateway = new WC_Gateway_ETransfer_Email();
	}

	public function test_email_gateway_class_exists() {
		$this->assertTrue(
			class_exists( 'WC_Gateway_ETransfer_Email' ),
			'WC_Gateway_ETransfer_Email class should exist'
		);
	}

	public function test_email_gateway_extends_base() {
		$this->assertInstanceOf( 'WC_Gateway_ETransfer_Base', $this->gateway );
	}

	public function test_email_gateway_delivery_method() {
		$this->assertSame( 'email', $this->gateway->get_delivery_method() );
	}

	public function test_email_gateway_has_correct_id() {
		$this->assertSame( 'digipay_etransfer_email', $this->gateway->id );
	}

	public function test_email_gateway_unavailable_when_disabled() {
		$this->assertFalse( $this->gateway->is_available() );
	}

	public function test_email_gateway_has_title() {
		$this->assertNotEmpty( $this->gateway->get_title(), 'Email gateway should have a title' );
	}

	public function test_email_gateway_has_icon() {
		$icon = $this->gateway->get_icon();
		$this->assertStringContainsString( '<img', $icon, 'Email gateway icon should contain an img tag' );
	}

	public function test_email_gateway_has_process_payment() {
		$this->assertTrue(
			method_exists( $this->gateway, 'process_payment' ),
			'Email gateway should have process_payment() method'
		);
	}

	public function test_process_payment_returns_failure_for_invalid_order() {
		$result = $this->gateway->process_payment( 99999 );
		$this->assertSame( 'failure', $result['result'] );
	}

	public function test_email_gateway_payment_fields_outputs_instructions() {
		ob_start();
		$this->gateway->payment_fields();
		$output = ob_get_clean();
		$this->assertStringContainsString( 'Place Order', $output, 'Email gateway should show checkout instructions' );
	}

	public function test_email_gateway_payment_fields_shows_email_instructions() {
		ob_start();
		$this->gateway->payment_fields();
		$output = ob_get_clean();
		$this->assertStringContainsString( 'payment link will be sent to your email', $output, 'Email gateway should mention email delivery' );
	}

	public function test_email_gateway_has_fields_enabled() {
		$this->assertTrue( $this->gateway->has_fields, 'Email gateway should have has_fields set to true' );
	}

	public function test_email_gateway_has_description() {
		$this->assertNotEmpty( $this->gateway->description, 'Email gateway should have a description' );
	}
}

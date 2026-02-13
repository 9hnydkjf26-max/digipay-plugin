<?php
/**
 * Tests for the E-Transfer Manual Gateway class.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for E-Transfer Manual Gateway.
 */
class ETransferManualGatewayTest extends DigipayTestCase {

	private $gateway;

	protected function set_up() {
		parent::set_up();
		$this->gateway = new WC_Gateway_ETransfer_Manual();
	}

	public function test_manual_gateway_class_exists() {
		$this->assertTrue(
			class_exists( 'WC_Gateway_ETransfer_Manual' ),
			'WC_Gateway_ETransfer_Manual class should exist'
		);
	}

	public function test_manual_gateway_has_title() {
		$this->assertNotEmpty( $this->gateway->get_title(), 'Manual gateway should have a title' );
	}

	public function test_manual_gateway_has_icon() {
		$icon = $this->gateway->get_icon();
		$this->assertNotEmpty( $icon, 'Manual gateway should have an icon' );
	}

	public function test_manual_gateway_has_process_payment() {
		$this->assertTrue(
			method_exists( $this->gateway, 'process_payment' ),
			'Manual gateway should have process_payment() method'
		);
	}

	public function test_manual_gateway_payment_fields_shows_send_instructions() {
		ob_start();
		$this->gateway->payment_fields();
		$output = ob_get_clean();
		$this->assertStringContainsString( 'e-Transfer instructions', $output, 'Manual gateway should mention e-Transfer instructions' );
	}

	public function test_manual_gateway_has_fields_enabled() {
		$this->assertTrue( $this->gateway->has_fields, 'Manual gateway should have has_fields set to true' );
	}

	public function test_manual_gateway_description_does_not_mention_request_money() {
		ob_start();
		$this->gateway->payment_fields();
		$output = ob_get_clean();
		$this->assertStringNotContainsString( 'Request Money', $output, 'Manual (Send Money) gateway should not mention Request Money' );
	}

	public function test_manual_gateway_has_description() {
		$this->assertNotEmpty( $this->gateway->description, 'Manual gateway should have a description' );
		$this->assertStringNotContainsString( 'Request Money', $this->gateway->description, 'Manual description should not mention Request Money' );
	}

	public function test_manual_gateway_payment_fields_shows_own_description() {
		ob_start();
		$this->gateway->payment_fields();
		$output = ob_get_clean();
		$this->assertStringContainsString( 'Send money', $output, 'Manual gateway payment_fields should show its own description' );
	}
}

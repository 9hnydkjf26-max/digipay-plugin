<?php
/**
 * Tests for the E-Transfer URL Gateway class.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for E-Transfer URL Gateway.
 */
class ETransferURLGatewayTest extends DigipayTestCase {

	private $gateway;

	protected function set_up() {
		parent::set_up();
		$this->gateway = new WC_Gateway_ETransfer_URL();
	}

	public function test_url_gateway_class_exists() {
		$this->assertTrue(
			class_exists( 'WC_Gateway_ETransfer_URL' ),
			'WC_Gateway_ETransfer_URL class should exist'
		);
	}

	public function test_url_gateway_has_title() {
		$this->assertNotEmpty( $this->gateway->get_title(), 'URL gateway should have a title' );
	}

	public function test_url_gateway_has_icon() {
		$icon = $this->gateway->get_icon();
		$this->assertNotEmpty( $icon, 'URL gateway should have an icon' );
	}

	public function test_url_gateway_has_process_payment() {
		$this->assertTrue(
			method_exists( $this->gateway, 'process_payment' ),
			'URL gateway should have process_payment() method'
		);
	}

	public function test_url_gateway_has_fields_enabled() {
		$this->assertTrue( $this->gateway->has_fields, 'URL gateway should have has_fields set to true' );
	}

	public function test_url_gateway_has_description() {
		$this->assertNotEmpty( $this->gateway->description, 'URL gateway should have a description' );
	}
}

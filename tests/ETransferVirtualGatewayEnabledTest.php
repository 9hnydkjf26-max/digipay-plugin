<?php
/**
 * Tests that virtual e-transfer gateways inherit enabled state from master.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

class ETransferVirtualGatewayEnabledTest extends DigipayTestCase {

	protected function set_up() {
		parent::set_up();
		global $wcpg_mock_options;
		$wcpg_mock_options = array();
	}

	protected function tear_down() {
		global $wcpg_mock_options;
		$wcpg_mock_options = array();
		parent::tear_down();
	}

	public function test_email_gateway_enabled_when_master_enabled() {
		global $wcpg_mock_options;
		$wcpg_mock_options['woocommerce_digipay_etransfer_settings'] = array(
			'enabled' => 'yes',
			'title_api' => 'Test E-Transfer',
		);

		$gateway = new WC_Gateway_ETransfer_Email();

		$this->assertSame( 'yes', $gateway->enabled, 'Email gateway should inherit enabled from master' );
		$this->assertTrue( $gateway->is_available(), 'Email gateway should be available when master is enabled' );
	}

	public function test_url_gateway_enabled_when_master_enabled() {
		global $wcpg_mock_options;
		$wcpg_mock_options['woocommerce_digipay_etransfer_settings'] = array(
			'enabled' => 'yes',
		);

		$gateway = new WC_Gateway_ETransfer_URL();

		$this->assertSame( 'yes', $gateway->enabled, 'URL gateway should inherit enabled from master' );
		$this->assertTrue( $gateway->is_available(), 'URL gateway should be available when master is enabled' );
	}

	public function test_manual_gateway_enabled_when_master_enabled() {
		global $wcpg_mock_options;
		$wcpg_mock_options['woocommerce_digipay_etransfer_settings'] = array(
			'enabled' => 'yes',
		);

		$gateway = new WC_Gateway_ETransfer_Manual();

		$this->assertSame( 'yes', $gateway->enabled, 'Manual gateway should inherit enabled from master' );
		$this->assertTrue( $gateway->is_available(), 'Manual gateway should be available when master is enabled' );
	}
}

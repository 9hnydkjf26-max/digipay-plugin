<?php
/**
 * E-Transfer Block Gateway Tests
 *
 * Tests for the E-Transfer WooCommerce Block Checkout integration.
 *
 * @package DigipayMasterPlugin
 * @since 12.7.0
 */

require_once __DIR__ . '/DigipayTestCase.php';

class ETransferBlockTest extends DigipayTestCase {

	private $block_class_path;
	private $js_path;
	private $js_content;
	private $has_blocks;

	protected function set_up() {
		parent::set_up();
		$this->block_class_path = dirname( __DIR__ ) . '/etransfer/class-etransfer-block.php';
		$this->js_path          = dirname( __DIR__ ) . '/etransfer/etransfer-checkout.js';
		$this->has_blocks       = class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' );

		if ( file_exists( $this->js_path ) ) {
			$this->js_content = file_get_contents( $this->js_path );
		}
	}

	private function require_blocks_classes() {
		if ( ! $this->has_blocks ) {
			$this->markTestSkipped( 'WooCommerce Blocks AbstractPaymentMethodType class is not available.' );
		}

		$gateway_path = dirname( __DIR__ ) . '/etransfer/class-etransfer-gateway.php';
		if ( file_exists( $gateway_path ) && ! class_exists( 'WC_Gateway_ETransfer' ) ) {
			require_once $gateway_path;
		}
		if ( ! class_exists( 'WCPG_ETransfer_Gateway_Blocks' ) ) {
			require_once $this->block_class_path;
		}
	}

	public function test_block_class_file_exists() {
		$this->assertFileExists(
			$this->block_class_path,
			'E-Transfer block class file should exist at etransfer/class-etransfer-block.php'
		);
	}

	public function test_block_class_exists() {
		$this->require_blocks_classes();

		$this->assertTrue(
			class_exists( 'WCPG_ETransfer_Gateway_Blocks' ),
			'WCPG_ETransfer_Gateway_Blocks class should be defined'
		);
	}

	public function test_block_name_matches_gateway_id() {
		$this->require_blocks_classes();

		$reflection    = new ReflectionClass( 'WCPG_ETransfer_Gateway_Blocks' );
		$name_property = $reflection->getProperty( 'name' );
		$name_property->setAccessible( true );

		$block_instance = $reflection->newInstanceWithoutConstructor();
		$block_name     = $name_property->getValue( $block_instance );

		$this->assertSame( WC_Gateway_ETransfer::GATEWAY_ID, $block_name );
	}

	public function test_javascript_file_exists() {
		$this->assertFileExists(
			$this->js_path,
			'E-Transfer checkout JavaScript file should exist at etransfer/etransfer-checkout.js'
		);
	}

	public function test_javascript_registers_correct_gateway_names() {
		$this->assertStringContainsString( 'digipay_etransfer_email', $this->js_content );
		$this->assertStringContainsString( 'digipay_etransfer_url', $this->js_content );
		$this->assertStringContainsString( 'digipay_etransfer_manual', $this->js_content );
	}

	public function test_javascript_uses_correct_settings_key() {
		$this->assertStringContainsString( "getSetting( 'digipay_etransfer_data'", $this->js_content );
	}

	public function test_javascript_calls_register_payment_method() {
		$this->assertStringContainsString( 'registerPaymentMethod', $this->js_content );
	}

	public function test_javascript_implements_can_make_payment() {
		$this->assertStringContainsString( 'canMakePayment', $this->js_content );
	}
}

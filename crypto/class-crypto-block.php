<?php
/**
 * WooCommerce Payment Gateway - Crypto Blocks Integration
 *
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Use the real class if available, otherwise fall back to mock.
if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
	abstract class WCPG_Crypto_Gateway_Block_Base extends Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {}
} else {
	abstract class WCPG_Crypto_Gateway_Block_Base extends WCPG_Mock_AbstractPaymentMethodType {}
}

final class WCPG_Crypto_Gateway_Block extends WCPG_Crypto_Gateway_Block_Base {

	private $gateway;
	protected $name = 'wcpg_crypto';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_wcpg_crypto_settings', array() );
		// Reuse the existing gateway instance to avoid registering hooks twice.
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			if ( isset( $gateways['wcpg_crypto'] ) ) {
				$this->gateway = $gateways['wcpg_crypto'];
				return;
			}
		}
		if ( class_exists( 'WCPG_Gateway_Crypto' ) ) {
			$this->gateway = new WCPG_Gateway_Crypto();
		}
	}

	public function is_active() {
		return isset( $this->gateway ) && $this->gateway->is_available();
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'wcpg-crypto-blocks-integration',
			plugin_dir_url( dirname( __FILE__ ) ) . 'crypto/crypto-checkout.js',
			array(
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
				'wp-i18n',
			),
			null,
			true
		);
		return array( 'wcpg-crypto-blocks-integration' );
	}

	public function get_payment_method_data() {
		$title = $this->get_setting( 'title' );
		$description = '';

		if ( isset( $this->gateway ) && is_object( $this->gateway ) ) {
			$description = $this->gateway->description;
		}

		return array(
			'title'       => $title ? $title : 'Pay with Crypto',
			'description' => $description,
			'supports'    => array( 'products' ),
		);
	}
}

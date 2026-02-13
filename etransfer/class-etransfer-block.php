<?php
/**
 * E-Transfer Payment Gateway - Blocks Integration
 *
 * Provides WooCommerce Block Checkout support for the E-Transfer payment gateway.
 *
 * @package DigipayMasterPlugin
 * @since 12.7.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * E-Transfer Gateway Blocks Integration Class
 *
 * Extends the WooCommerce Blocks payment method type to enable
 * E-Transfer payments in the block-based checkout.
 */
final class WCPG_ETransfer_Gateway_Blocks extends AbstractPaymentMethodType {

	/**
	 * Gateway instance.
	 *
	 * @var WC_Gateway_ETransfer
	 */
	private $gateway;

	/**
	 * Payment method name/ID as defined in the gateway.
	 *
	 * @var string
	 */
	protected $name = 'digipay_etransfer';

	/**
	 * Initialize the payment method type.
	 *
	 * Loads gateway settings and creates a gateway instance.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_digipay_etransfer_settings', array() );
		$this->gateway  = new WC_Gateway_ETransfer();
	}

	/**
	 * Check if the payment method is active and available.
	 *
	 * @return bool True if the gateway is enabled, false otherwise.
	 */
	public function is_active() {
		return isset( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
	}

	/**
	 * Register and return the script handles for the payment method.
	 *
	 * @return array Array of script handles.
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'digipay-etransfer-blocks-integration',
			plugin_dir_url( __FILE__ ) . 'etransfer-checkout.js',
			array(
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
				'wp-i18n',
			),
			defined( 'WCPG_VERSION' ) ? WCPG_VERSION : '12.7.0',
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'digipay-etransfer-blocks-integration' );
		}

		return array( 'digipay-etransfer-blocks-integration' );
	}

	/**
	 * Get payment method data to expose to the frontend.
	 *
	 * Returns data used by the JavaScript component to render
	 * the payment method in the block checkout.
	 *
	 * @return array Payment method data.
	 */
	public function get_payment_method_data() {
		// Get delivery method from settings.
		$delivery_method = isset( $this->settings['delivery_method'] )
			? $this->settings['delivery_method']
			: WC_Gateway_ETransfer::DELIVERY_EMAIL;

		// Build delivery method label for display.
		$delivery_labels = array(
			WC_Gateway_ETransfer::DELIVERY_EMAIL  => __( 'Email', 'wc-payment-gateway' ),
			WC_Gateway_ETransfer::DELIVERY_URL    => __( 'URL', 'wc-payment-gateway' ),
			WC_Gateway_ETransfer::DELIVERY_MANUAL => __( 'Manual', 'wc-payment-gateway' ),
		);
		$delivery_label = isset( $delivery_labels[ $delivery_method ] )
			? $delivery_labels[ $delivery_method ]
			: $delivery_labels[ WC_Gateway_ETransfer::DELIVERY_EMAIL ];

		return array(
			'name'            => $this->get_name(),
			'title'           => isset( $this->settings['title'] )
				? $this->settings['title']
				: __( 'Interac e-Transfer', 'wc-payment-gateway' ),
			'description'     => isset( $this->settings['description'] )
				? $this->settings['description']
				: __( 'Pay securely via Interac e-Transfer.', 'wc-payment-gateway' ),
			'delivery_method' => $delivery_method,
			'delivery_label'  => $delivery_label,
			'supports'        => array( 'products' ),
		);
	}
}

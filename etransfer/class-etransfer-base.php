<?php
/**
 * E-Transfer Base Payment Gateway
 *
 * Abstract base class for E-Transfer virtual gateways (Email, URL, Manual).
 * Provides shared functionality for reading master gateway settings and
 * processing payments.
 *
 * @package DigipayMasterPlugin
 * @since 12.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * E-Transfer Base Gateway Class
 *
 * Virtual gateways extend this class to inherit shared settings access
 * and payment processing methods.
 */
abstract class WC_Gateway_ETransfer_Base extends WC_Payment_Gateway {

	/**
	 * Master gateway ID for reading settings.
	 */
	const MASTER_GATEWAY_ID = 'digipay_etransfer';

	/**
	 * Text domain for translations.
	 */
	const TEXT_DOMAIN = 'wc-payment-gateway';

	/**
	 * Delivery method: Email
	 */
	const DELIVERY_EMAIL = 'email';

	/**
	 * Delivery method: URL
	 */
	const DELIVERY_URL = 'url';

	/**
	 * Delivery method: Manual
	 */
	const DELIVERY_MANUAL = 'manual';

	/**
	 * Cached master settings.
	 *
	 * @var array|null
	 */
	protected $master_settings = null;

	/**
	 * Get a setting from the master gateway.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value if not set.
	 * @return mixed Setting value.
	 */
	protected function get_master_setting( $key, $default = '' ) {
		if ( null === $this->master_settings ) {
			$this->master_settings = get_option( 'woocommerce_' . self::MASTER_GATEWAY_ID . '_settings', array() );
		}

		return isset( $this->master_settings[ $key ] ) ? $this->master_settings[ $key ] : $default;
	}

	/**
	 * Get the delivery method this gateway handles.
	 *
	 * @return string Delivery method constant.
	 */
	abstract public function get_delivery_method();

	public function get_icon() {
		$icon_url = plugin_dir_url( __FILE__ ) . 'assets/images/interac-etransfer.png';
		return '<img src="' . esc_url( $icon_url ) . '" alt="' . esc_attr( $this->get_title() ) . '" style="max-height: 24px; width: auto;" />';
	}

	public function process_payment( $order_id ) {
		$master = new WC_Gateway_ETransfer();
		return $master->process_payment_for_delivery( $order_id, $this->get_delivery_method() );
	}

	public function payment_fields() {
		if ( ! empty( $this->description ) ) {
			echo '<p>' . wp_kses_post( $this->description ) . '</p>';
		}

		$delivery_method = $this->get_delivery_method();
		$instructions_key = 'instructions_' . $delivery_method;
		$instructions = $this->get_master_setting( $instructions_key, $this->get_default_checkout_instructions( $delivery_method ) );

		if ( ! empty( $instructions ) ) {
			echo '<div class="wcpg-etransfer-checkout-instructions" style="margin-top: 10px;">';
			echo wp_kses_post( wpautop( $instructions ) );
			echo '</div>';
		}
	}

	/**
	 * Get default checkout instructions for a delivery method.
	 *
	 * Used as fallback when settings have not been saved yet.
	 *
	 * @param string $delivery_method Delivery method.
	 * @return string Default instructions HTML.
	 */
	protected function get_default_checkout_instructions( $delivery_method ) {
		switch ( $delivery_method ) {
			case self::DELIVERY_EMAIL:
				return '<p><strong>Next steps:</strong></p>'
					. '<ol>'
					. '<li>Click <strong>"Place Order"</strong> to confirm your purchase.</li>'
					. '<li>A secure payment link will be sent to your email address.</li>'
					. '<li>Follow the instructions in the email to complete your Interac e-Transfer.</li>'
					. '</ol>'
					. '<p style="color: #666; font-size: 0.9em;"><em>Please note: Orders will be automatically cancelled if payment is not received within the allotted time.</em></p>';
			case self::DELIVERY_URL:
				return '<p><strong>Next steps:</strong></p>'
					. '<ol>'
					. '<li>Click <strong>"Place Order"</strong> to confirm your purchase.</li>'
					. '<li>Ensure your pop-up blocker is disabled for this site.</li>'
					. '<li>You will be redirected to a secure Interac e-Transfer checkout page.</li>'
					. '<li>Follow the on-screen instructions to complete your payment.</li>'
					. '</ol>'
					. '<p style="color: #666; font-size: 0.9em;"><em>Please note: Orders will be automatically cancelled if payment is not received within the allotted time.</em></p>';
			case self::DELIVERY_MANUAL:
				return '<p><strong>Next steps:</strong></p>'
					. '<ol>'
					. '<li>Click <strong>"Place Order"</strong> to confirm your purchase.</li>'
					. '<li>You will receive Interac e-Transfer instructions on the following page.</li>'
					. '<li>Send an Interac e-Transfer using the provided details.</li>'
					. '</ol>'
					. '<p style="color: #666; font-size: 0.9em;"><em>Please note: Orders will be automatically cancelled if payment is not received within the allotted time.</em></p>';
			default:
				return '';
		}
	}
}

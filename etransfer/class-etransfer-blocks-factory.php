<?php
/**
 * E-Transfer Payment Gateway - Blocks Factory
 *
 * Dynamically registers WooCommerce Block Checkout support for enabled E-Transfer delivery methods.
 *
 * @package DigipayMasterPlugin
 * @since 12.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * E-Transfer Blocks Factory Class
 *
 * Factory for registering dynamic block payment methods based on enabled delivery methods.
 */
class WCPG_ETransfer_Blocks_Factory {

	/**
	 * Register enabled E-Transfer payment methods with WooCommerce Blocks.
	 *
	 * @param Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry Payment method registry.
	 */
	public static function register_blocks( $registry ) {
		$settings        = get_option( 'woocommerce_digipay_etransfer_settings', array() );
		$enabled         = isset( $settings['enabled'] ) && 'yes' === $settings['enabled'];
		$delivery_method = isset( $settings['delivery_method'] ) ? $settings['delivery_method'] : 'email';
		$enable_manual   = isset( $settings['enable_manual'] ) && 'yes' === $settings['enable_manual'];

		if ( ! $enabled ) {
			return;
		}

		// Register API method block (Email or URL).
		if ( 'email' === $delivery_method ) {
			$registry->register( new WCPG_ETransfer_Block_Email() );
		} elseif ( 'url' === $delivery_method ) {
			$registry->register( new WCPG_ETransfer_Block_URL() );
		}

		// Register Manual method block if enabled.
		if ( $enable_manual ) {
			$registry->register( new WCPG_ETransfer_Block_Manual() );
		}
	}
}

/**
 * Abstract base class for E-Transfer block payment methods.
 */
abstract class WCPG_ETransfer_Block_Base extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {

	/**
	 * Master gateway settings.
	 *
	 * @var array
	 */
	protected $master_settings = array();

	/**
	 * Initialize the payment method type.
	 */
	public function initialize() {
		$this->master_settings = get_option( 'woocommerce_digipay_etransfer_settings', array() );
	}

	/**
	 * Check if the payment method is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return isset( $this->master_settings['enabled'] ) && 'yes' === $this->master_settings['enabled'];
	}

	/**
	 * Register and return the script handles for the payment method.
	 *
	 * @return array Array of script handles.
	 */
	public function get_payment_method_script_handles() {
		$handle = 'digipay-etransfer-blocks-' . $this->name;

		wp_register_script(
			$handle,
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
			wp_set_script_translations( $handle );
		}

		return array( $handle );
	}

	/**
	 * Get the delivery method for this block.
	 *
	 * @return string
	 */
	abstract protected function get_delivery_method();

	/**
	 * Get payment method data to expose to the frontend.
	 *
	 * @return array Payment method data.
	 */
	/**
	 * Get the per-method description.
	 *
	 * @return string
	 */
	protected function get_method_description() {
		return __( 'Pay securely via Interac e-Transfer.', 'wc-payment-gateway' );
	}

	public function get_payment_method_data() {
		$delivery_method = $this->get_delivery_method();
		$title_key       = 'manual' === $delivery_method ? 'title_manual' : 'title_api';
		$default_title   = 'manual' === $delivery_method
			? __( 'Interac e-Transfer (Send Money)', 'wc-payment-gateway' )
			: __( 'Interac e-Transfer (Request Money)', 'wc-payment-gateway' );

		return array(
			'name'            => $this->name,
			'title'           => isset( $this->master_settings[ $title_key ] )
				? $this->master_settings[ $title_key ]
				: $default_title,
			'description'     => $this->get_method_description(),
			'delivery_method' => $delivery_method,
			'supports'        => array( 'products' ),
		);
	}
}

/**
 * Email delivery method block.
 */
class WCPG_ETransfer_Block_Email extends WCPG_ETransfer_Block_Base {
	protected $name = 'digipay_etransfer_email';

	protected function get_delivery_method() {
		return 'email';
	}

	protected function get_method_description() {
		return isset( $this->master_settings['description_api'] ) && '' !== $this->master_settings['description_api']
			? $this->master_settings['description_api']
			: __( 'Pay securely via Interac e-Transfer. A payment link will be sent to your email.', 'wc-payment-gateway' );
	}

	public function is_active() {
		if ( ! parent::is_active() ) {
			return false;
		}
		$delivery_method = isset( $this->master_settings['delivery_method'] ) 
			? $this->master_settings['delivery_method'] 
			: 'email';
		return 'email' === $delivery_method;
	}
}

/**
 * URL delivery method block.
 */
class WCPG_ETransfer_Block_URL extends WCPG_ETransfer_Block_Base {
	protected $name = 'digipay_etransfer_url';

	protected function get_delivery_method() {
		return 'url';
	}

	protected function get_method_description() {
		return isset( $this->master_settings['description_api'] ) && '' !== $this->master_settings['description_api']
			? $this->master_settings['description_api']
			: __( 'Pay securely via Interac e-Transfer. A pop-up from Interac will appear after checkout.', 'wc-payment-gateway' );
	}

	public function is_active() {
		if ( ! parent::is_active() ) {
			return false;
		}
		$delivery_method = isset( $this->master_settings['delivery_method'] ) 
			? $this->master_settings['delivery_method'] 
			: 'email';
		return 'url' === $delivery_method;
	}
}

/**
 * Manual delivery method block.
 */
class WCPG_ETransfer_Block_Manual extends WCPG_ETransfer_Block_Base {
	protected $name = 'digipay_etransfer_manual';

	protected function get_delivery_method() {
		return 'manual';
	}

	protected function get_method_description() {
		return isset( $this->master_settings['description_manual'] ) && '' !== $this->master_settings['description_manual']
			? $this->master_settings['description_manual']
			: __( 'Pay securely via Interac e-Transfer. Send money using the provided instructions.', 'wc-payment-gateway' );
	}

	public function is_active() {
		if ( ! parent::is_active() ) {
			return false;
		}
		return isset( $this->master_settings['enable_manual'] ) 
			&& 'yes' === $this->master_settings['enable_manual'];
	}
}

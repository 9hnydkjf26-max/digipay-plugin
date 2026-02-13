<?php
/**
 * WooCommerce Payment Gateway - Blocks Integration
 *
 * @version 12.6.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WCPG_Gateway_Blocks extends AbstractPaymentMethodType {

    private $gateway;
    protected $name = DIGIPAY_GATEWAY_ID; // Payment gateway name

    public function initialize() {
        $this->settings = get_option( 'woocommerce_' . DIGIPAY_GATEWAY_ID . '_settings', [] );
        $this->gateway = new WC_Gateway_Paygo_npaygo();
    }

    public function is_active() {
        return $this->gateway->is_available();
    }

    public function get_payment_method_script_handles() {

        wp_register_script(
            'paygobillingcc-blocks-integration',
            plugin_dir_url(__FILE__) . 'checkout.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            null,
            true
        );
        if( function_exists( 'wp_set_script_translations' ) ) {            
            wp_set_script_translations( 'paygobillingcc-blocks-integration');
            
        }
        return [ 'paygobillingcc-blocks-integration' ];
    }

    public function get_payment_method_data() {
            return [
                'name'             => $this->get_name(), // Unique gateway ID
                'title'            => $this->gateway->title, // Display title
                'description'      => $this->gateway->description, // Description shown on selection
               // 'supports'         => ['products'],
                //'icons'            => [], // Optional
            ];
        }





}
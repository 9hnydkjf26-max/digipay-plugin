<?php
/**
 * PHPUnit bootstrap file for Digipay Payment Gateway tests.
 *
 * @package Digipay
 */

// Composer autoloader.
$composer_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( file_exists( $composer_autoload ) ) {
    require_once $composer_autoload;
}

// Load Yoast PHPUnit Polyfills.
if ( class_exists( '\Yoast\PHPUnitPolyfills\Autoload' ) ) {
    // Already loaded by composer autoload.
} else {
    echo "Warning: Yoast PHPUnit Polyfills not found. Run 'composer install' first.\n";
}

// Define test constants (only if not already defined).
if ( ! defined( 'DIGIPAY_TEST_MODE' ) ) {
    define( 'DIGIPAY_TEST_MODE', true );
}
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/wordpress/' );
}
if ( ! defined( 'WPINC' ) ) {
    define( 'WPINC', 'wp-includes' );
}

// Digipay plugin constants.
if ( ! defined( 'DIGIPAY_GATEWAY_ID' ) ) {
    define( 'DIGIPAY_GATEWAY_ID', 'paygobillingcc' );
}
if ( ! defined( 'DIGIPAY_API_URL' ) ) {
    define( 'DIGIPAY_API_URL', 'https://secure.digipay.co/' );
}

// Fingerprint device intelligence constants.
if ( ! defined( 'WCPG_FINGERPRINT_PUBLIC_KEY' ) ) {
    define( 'WCPG_FINGERPRINT_PUBLIC_KEY', 'your_public_api_key_here' );
}
if ( ! defined( 'WCPG_FINGERPRINT_REGION' ) ) {
    define( 'WCPG_FINGERPRINT_REGION', 'us' );
}

if ( ! function_exists( 'digipay_get_pacific_date' ) ) {
    /**
     * Get current date in Pacific timezone.
     *
     * @param string $format Date format string.
     * @return string Formatted date.
     */
    function digipay_get_pacific_date( $format = 'Y-m-d' ) {
        $pacific_tz  = new DateTimeZone( 'America/Los_Angeles' );
        $now_pacific = new DateTime( 'now', $pacific_tz );
        return $now_pacific->format( $format );
    }
}

// Mock WordPress functions for unit testing (without full WP load).
if ( ! function_exists( 'plugin_dir_path' ) ) {
    /**
     * Mock plugin_dir_path function.
     *
     * @param string $file Plugin file path.
     * @return string Directory path.
     */
    function plugin_dir_path( $file ) {
        return trailingslashit( dirname( $file ) );
    }
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
    /**
     * Mock plugin_dir_url function.
     *
     * @param string $file Plugin file path.
     * @return string URL path.
     */
    function plugin_dir_url( $file ) {
        return 'https://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
    }
}

if ( ! function_exists( 'trailingslashit' ) ) {
    /**
     * Mock trailingslashit function.
     *
     * @param string $string String to add trailing slash.
     * @return string String with trailing slash.
     */
    function trailingslashit( $string ) {
        return rtrim( $string, '/\\' ) . '/';
    }
}

if ( ! function_exists( 'esc_url_raw' ) ) {
    /**
     * Mock esc_url_raw function.
     *
     * @param string $url URL to escape.
     * @return string Escaped URL.
     */
    function esc_url_raw( $url ) {
        return filter_var( $url, FILTER_SANITIZE_URL );
    }
}

if ( ! function_exists( 'esc_html' ) ) {
    /**
     * Mock esc_html function.
     *
     * @param string $text Text to escape.
     * @return string Escaped text.
     */
    function esc_html( $text ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_attr' ) ) {
    /**
     * Mock esc_attr function.
     *
     * @param string $text Text to escape.
     * @return string Escaped text for attributes.
     */
    function esc_attr( $text ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( '__' ) ) {
    /**
     * Mock translation function.
     *
     * @param string $text   Text to translate.
     * @param string $domain Text domain.
     * @return string Translated text (unchanged in tests).
     */
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}

if ( ! function_exists( 'absint' ) ) {
    /**
     * Mock absint function.
     *
     * @param mixed $maybeint Value to convert to absolute integer.
     * @return int Absolute integer.
     */
    function absint( $maybeint ) {
        return abs( (int) $maybeint );
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    /**
     * Mock sanitize_text_field function.
     *
     * @param string $str String to sanitize.
     * @return string Sanitized string.
     */
    function sanitize_text_field( $str ) {
        return trim( strip_tags( $str ) );
    }
}

if ( ! function_exists( 'current_user_can' ) ) {
    /**
     * Mock current_user_can function.
     *
     * @param string $capability Capability to check.
     * @return bool Always true in test mode.
     */
    function current_user_can( $capability ) {
        // In tests, default to true unless specifically mocked otherwise.
        return true;
    }
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
    /**
     * Mock wp_safe_redirect function.
     *
     * @param string $location Location to redirect.
     * @param int    $status   HTTP status code.
     */
    function wp_safe_redirect( $location, $status = 302 ) {
        // No-op in tests.
        return true;
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    /**
     * Mock apply_filters function.
     *
     * @param string $hook  Filter hook name.
     * @param mixed  $value Value to filter.
     * @return mixed Filtered value (unchanged in tests).
     */
    function apply_filters( $hook, $value ) {
        return $value;
    }
}

if ( ! function_exists( 'add_filter' ) ) {
    /**
     * Mock add_filter function.
     *
     * @param string   $hook     Hook name.
     * @param callable $callback Callback function.
     * @param int      $priority Priority.
     * @param int      $args     Number of args.
     * @return bool Always true.
     */
    function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
        return true;
    }
}

if ( ! function_exists( 'add_action' ) ) {
    /**
     * Mock add_action function.
     *
     * @param string   $hook     Hook name.
     * @param callable $callback Callback function.
     * @param int      $priority Priority.
     * @param int      $args     Number of args.
     * @return bool Always true.
     */
    function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
        return true;
    }
}

// Mock options store for testing.
global $wcpg_mock_options;
$wcpg_mock_options = array();

if ( ! function_exists( 'get_option' ) ) {
    /**
     * Mock get_option function.
     *
     * @param string $option  Option name.
     * @param mixed  $default Default value.
     * @return mixed Option value or default.
     */
    function get_option( $option, $default = false ) {
        global $wcpg_mock_options;
        if ( isset( $wcpg_mock_options[ $option ] ) ) {
            return $wcpg_mock_options[ $option ];
        }
        return $default;
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    /**
     * Mock wp_json_encode function.
     *
     * @param mixed $data    Data to encode.
     * @param int   $options JSON encode options.
     * @param int   $depth   Maximum depth.
     * @return string|false JSON string or false on failure.
     */
    function wp_json_encode( $data, $options = 0, $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}

if ( ! function_exists( 'register_activation_hook' ) ) {
    /**
     * Mock register_activation_hook function.
     *
     * @param string   $file     Plugin file.
     * @param callable $callback Callback function.
     */
    function register_activation_hook( $file, $callback ) {
        // No-op in tests.
    }
}

if ( ! function_exists( 'update_option' ) ) {
    /**
     * Mock update_option function.
     *
     * @param string $option   Option name.
     * @param mixed  $value    Option value.
     * @param bool   $autoload Whether to autoload.
     * @return bool Always true.
     */
    function update_option( $option, $value, $autoload = true ) {
        return true;
    }
}

// Define the digipay_encrypt function directly for isolated testing.
// This mirrors the implementation in woocommerce-gateway-paygo.php.
if ( ! function_exists( 'digipay_encrypt' ) ) {
    /**
     * Encrypt a string using AES-256-CBC with PBKDF2 key derivation.
     *
     * @param string $string The plaintext string to encrypt.
     * @param string $key    The encryption key.
     * @return string Base64-encoded JSON containing ciphertext, iv, salt, and iterations.
     */
    function digipay_encrypt( $string, $key ) {
        $encrypt_method = 'AES-256-CBC';

        // Use explicit 256-bit key length constant (not extracted from string).
        $key_length_bits = 256;

        $iv_length = openssl_cipher_iv_length( $encrypt_method );
        $iv        = openssl_random_pseudo_bytes( $iv_length );

        $salt       = openssl_random_pseudo_bytes( 256 );
        $iterations = 999;

        // Derive key using PBKDF2: 256 bits = 64 hex characters (256/4).
        $hash_key = hash_pbkdf2( 'sha512', $key, $salt, $iterations, ( $key_length_bits / 4 ) );

        $encrypted_string = openssl_encrypt( $string, $encrypt_method, hex2bin( $hash_key ), OPENSSL_RAW_DATA, $iv );
        $encrypted_string = base64_encode( $encrypted_string );

        unset( $hash_key );

        $output = array(
            'ciphertext' => $encrypted_string,
            'iv'         => bin2hex( $iv ),
            'salt'       => bin2hex( $salt ),
            'iterations' => $iterations,
        );

        unset( $encrypted_string, $iterations, $iv, $iv_length, $salt );

        return base64_encode( wp_json_encode( $output ) );
    }
}

if ( ! function_exists( 'digipay_build_payment_url' ) ) {
    /**
     * Build a properly escaped payment redirect URL.
     *
     * @param string $base_url        The base payment gateway URL.
     * @param string $encrypted_param The encrypted parameter value.
     * @return string Escaped URL safe for redirect.
     */
    function digipay_build_payment_url( $base_url, $encrypted_param ) {
        $url = $base_url . '?param=' . $encrypted_param;
        return esc_url_raw( $url );
    }
}

if ( ! function_exists( 'digipay_prepare_payment_params' ) ) {
    /**
     * Prepare payment parameters from order data.
     *
     * @param array $order_data Order data array.
     * @param array $options    Gateway options.
     * @return array Payment parameters.
     */
    function digipay_prepare_payment_params( $order_data, $options = array() ) {
        return array(
            'billing_param' => '',
        );
    }
}

if ( ! function_exists( 'digipay_reorder_gateways' ) ) {
    /**
     * Reorder gateways to put Digipay first.
     *
     * @param array $ordering Current gateway ordering.
     * @return array New gateway ordering with Digipay first.
     */
    function digipay_reorder_gateways( $ordering ) {
        // Set Digipay to position -1 (before everything else).
        $ordering['paygobillingcc'] = -1;

        // Re-sort and re-index all gateways.
        asort( $ordering );
        $new_ordering = array();
        $position     = 0;
        foreach ( $ordering as $gateway_id => $old_position ) {
            $new_ordering[ $gateway_id ] = $position;
            $position++;
        }

        return $new_ordering;
    }
}

// Mirror wcpg_add_gateway from main plugin for gateway registration testing.
if ( ! function_exists( 'wcpg_add_gateway' ) ) {
	function wcpg_add_gateway( $gateways ) {
		// Add to beginning instead of end.
		array_unshift( $gateways, 'WC_Gateway_Paygo_npaygo' );

		// Note: Master WC_Gateway_ETransfer is NOT registered here.
		// Its settings are managed via the main gateway's "E-Transfer" tab in admin_options().
		// Only virtual gateways (Email, URL, Manual) are registered for checkout.

		// Get E-Transfer settings for dynamic gateway registration.
		$settings        = get_option( 'woocommerce_digipay_etransfer_settings', array() );
		$delivery_method = isset( $settings['delivery_method'] ) ? $settings['delivery_method'] : 'email';
		$enable_manual   = isset( $settings['enable_manual'] ) && 'yes' === $settings['enable_manual'];

		// Add API gateway (Email OR URL, mutually exclusive).
		if ( 'email' === $delivery_method ) {
			$gateways[] = 'WC_Gateway_ETransfer_Email';
		} elseif ( 'url' === $delivery_method ) {
			$gateways[] = 'WC_Gateway_ETransfer_URL';
		}

		// Add Manual gateway if enabled.
		if ( $enable_manual ) {
			$gateways[] = 'WC_Gateway_ETransfer_Manual';
		}

		// Add Crypto gateway if enabled.
		$crypto_settings = get_option( 'woocommerce_wcpg_crypto_settings', array() );
		if ( isset( $crypto_settings['enabled'] ) && 'yes' === $crypto_settings['enabled'] ) {
			$gateways[] = 'WCPG_Gateway_Crypto';
		}

		return $gateways;
	}
}

// Mirror wcpg_init_etransfer_hooks from main plugin.
if ( ! function_exists( 'wcpg_init_etransfer_hooks' ) ) {
	function wcpg_init_etransfer_hooks() {
		if ( class_exists( 'WC_Gateway_ETransfer' ) ) {
			new WC_Gateway_ETransfer();
		}
	}
}

// Mirror wcpg_process_postback from main plugin for integration testing.
if ( ! function_exists( 'wcpg_process_postback' ) ) {
	function wcpg_process_postback( $order_id, $status_post, $transid, $source = 'legacy' ) {
		$postback_key = 'wcpg_pb_' . $order_id . '_' . md5( $transid . $status_post );
		if ( get_transient( $postback_key ) ) {
			return array(
				'success' => true,
				'code'    => 'duplicate',
				'message' => 'Already processed',
			);
		}
		set_transient( $postback_key, true, 5 * MINUTE_IN_SECONDS );

		if ( ! empty( $transid ) ) {
			update_post_meta( $order_id, '_paygo_cc_transaction_id', $transid );
		}
		if ( ! empty( $status_post ) ) {
			$allowed_statuses = array( 'approved', 'denied', 'pending', 'error', 'completed', 'processing' );
			if ( in_array( strtolower( $status_post ), $allowed_statuses, true ) ) {
				update_post_meta( $order_id, '_paygo_cc_transaction_status', $status_post );
			}
		}

		if ( strtolower( $status_post ) === 'denied' ) {
			return array(
				'success' => true,
				'code'    => 'denied',
				'message' => 'Denied status recorded',
			);
		}

		// Reject unrecognized statuses before touching the order.
		$actionable_statuses = array( 'approved', 'completed', 'pending', 'processing', 'error' );
		if ( ! in_array( strtolower( $status_post ), $actionable_statuses, true ) ) {
			return array(
				'success' => false,
				'code'    => 'invalid_status',
				'message' => 'Unrecognized status: ' . $status_post,
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array(
				'success' => false,
				'code'    => 'order_not_found',
				'message' => 'Order not found',
			);
		}

		$status_lower = strtolower( $status_post );
		if ( in_array( $status_lower, array( 'pending', 'processing' ), true ) ) {
			$wc_status = 'on-hold';
			$status_note = sprintf(
				__( 'Payment pending via payment gateway (%s)', 'wc-payment-gateway' ),
				$source
			);
		} elseif ( 'error' === $status_lower ) {
			$wc_status = 'failed';
			$status_note = sprintf(
				__( 'Payment failed via payment gateway (%s)', 'wc-payment-gateway' ),
				$source
			);
		} else {
			$wc_status = 'processing';
			$status_note = sprintf(
				__( 'Payment received via payment gateway (%s)', 'wc-payment-gateway' ),
				$source
			);
		}
		$order->update_status( $wc_status, $status_note );

		return array(
			'success'  => true,
			'code'     => 'ok',
			'message'  => 'Success',
			'order_id' => $order_id,
		);
	}
}

// Load test case base class.
require_once __DIR__ . '/DigipayTestCase.php';

// Mock WC_Payment_Gateway class for testing.
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	/**
	 * Mock WC_Payment_Gateway class for unit testing.
	 */
	class WC_Payment_Gateway {
		public $id;
		public $icon;
		public $has_fields;
		public $method_title;
		public $method_description;
		public $supports = array();
		public $enabled;
		public $title;
		public $description;
		public $settings = array();
		protected $form_fields = array();

		public function __construct() {}

		public function init_settings() {
			$option_key = 'woocommerce_' . $this->id . '_settings';
			$this->settings = get_option( $option_key, array() );
		}

		public function get_option( $key, $default = '' ) {
			if ( isset( $this->settings[ $key ] ) ) {
				return $this->settings[ $key ];
			}
			if ( isset( $this->form_fields[ $key ]['default'] ) ) {
				return $this->form_fields[ $key ]['default'];
			}
			return $default;
		}

		public function is_available() {
			return 'yes' === $this->enabled;
		}

		public function supports( $feature ) {
			return in_array( $feature, $this->supports, true );
		}

		public function process_admin_options() {
			return true;
		}

		public function get_return_url( $order = null ) {
			return 'https://example.com/checkout/order-received/';
		}

		public function init_form_fields() {
			// Override in subclass.
		}

		public function get_title() {
			return $this->title;
		}

		public function get_form_fields() {
			return $this->form_fields;
		}
	}
}

// Load Crypto gateway class for testing (if it exists).
$crypto_gateway_file = dirname( __DIR__ ) . '/crypto/class-crypto-gateway.php';
if ( file_exists( $crypto_gateway_file ) ) {
	require_once $crypto_gateway_file;
}

// Load E-Transfer gateway classes for testing.
require_once dirname( __DIR__ ) . '/etransfer/class-template-loader.php';
require_once dirname( __DIR__ ) . '/etransfer/class-etransfer-gateway.php';
require_once dirname( __DIR__ ) . '/etransfer/class-etransfer-base.php';
require_once dirname( __DIR__ ) . '/etransfer/class-etransfer-email.php';
require_once dirname( __DIR__ ) . '/etransfer/class-etransfer-url.php';
require_once dirname( __DIR__ ) . '/etransfer/class-etransfer-manual.php';
require_once dirname( __DIR__ ) . '/etransfer/class-api-client.php';
require_once dirname( __DIR__ ) . '/etransfer/class-transaction-poller.php';

// Mock WP_Error class for testing if not defined.
if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Mock WP_Error class for unit testing.
	 */
	class WP_Error {
		private $code;
		private $message;
		private $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

// Mock AbstractPaymentMethodType for testing (WooCommerce Blocks).
if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
	// Create the namespace alias
	class WCPG_Mock_AbstractPaymentMethodType {
		protected $name = '';
		protected $settings = array();

		public function get_name() {
			return $this->name;
		}

		public function initialize() {}
		public function is_active() { return true; }
		public function get_payment_method_script_handles() { return array(); }
		public function get_payment_method_data() { return array(); }

		public function get_setting( $key, $default = '' ) {
			return isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : $default;
		}
	}
}

// Load Crypto block class for testing.
$crypto_block_file = dirname( __DIR__ ) . '/crypto/class-crypto-block.php';
if ( file_exists( $crypto_block_file ) ) {
	require_once $crypto_block_file;
}

// Mock WP_REST_Request class for testing.
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $params = array();
		private $json_params = array();
		private $body_params = array();
		private $headers = array();

		public function __construct( $json_params = array() ) {
			$this->json_params = $json_params;
		}

		public function set_header( $key, $value ) {
			$this->headers[ strtolower( $key ) ] = $value;
		}

		public function get_json_params() {
			return $this->json_params;
		}

		public function get_body_params() {
			return $this->body_params;
		}

		public function get_param( $key ) {
			if ( isset( $this->json_params[ $key ] ) ) {
				return $this->json_params[ $key ];
			}
			if ( isset( $this->params[ $key ] ) ) {
				return $this->params[ $key ];
			}
			return null;
		}
	}
}

// Alias for test readability.
if ( ! class_exists( 'WP_REST_Request_Mock' ) ) {
	class WP_REST_Request_Mock extends WP_REST_Request {
		public function get_header( $key ) {
			$key = strtolower( $key );
			return isset( $this->headers[ $key ] ) ? $this->headers[ $key ] : null;
		}
		public function get_body() {
			return json_encode( $this->get_json_params() );
		}
	}
}

// Mock WP_REST_Response class for testing.
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		private $data;
		private $status;

		public function __construct( $data = array(), $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_status() {
			return $this->status;
		}

		public function get_data() {
			return $this->data;
		}
	}
}

// Mock is_wp_error function.
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

// Mock wp_remote_post function.
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		return new WP_Error( 'not_implemented', 'wp_remote_post is not available in tests' );
	}
}

// Mock wp_remote_get function.
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) {
		return new WP_Error( 'not_implemented', 'wp_remote_get is not available in tests' );
	}
}

// Mock wp_remote_retrieve_body function.
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return '';
	}
}

// Mock delete_option function.
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $option ) {
		return true;
	}
}

// Mock rest_url function.
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) {
		return 'https://example.com/wp-json/' . ltrim( $path, '/' );
	}
}

// Mock wc_add_notice function.
if ( ! function_exists( 'wc_add_notice' ) ) {
	function wc_add_notice( $message, $notice_type = 'success' ) {
		// No-op in tests.
	}
}

// Mock wc_reduce_stock_levels function.
if ( ! function_exists( 'wc_reduce_stock_levels' ) ) {
	function wc_reduce_stock_levels( $order_id ) {
		// No-op in tests.
	}
}

// Mock order store for postback tests.
global $wcpg_mock_orders;
$wcpg_mock_orders = array();

// Mock wc_get_order function.
if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $order_id ) {
		global $wcpg_mock_orders;
		if ( isset( $wcpg_mock_orders[ $order_id ] ) ) {
			return $wcpg_mock_orders[ $order_id ];
		}
		return null;
	}
}

// Mock update_post_meta function.
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $meta_key, $meta_value ) {
		return true;
	}
}

// Mock WC() function.
if ( ! function_exists( 'WC' ) ) {
	function WC() {
		static $wc;
		if ( ! isset( $wc ) ) {
			$wc = new stdClass();
			$wc->cart = null;
		}
		return $wc;
	}
}

// Mock wp_kses_post function.
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $data ) {
		return $data;
	}
}

// Mock wpautop function.
if ( ! function_exists( 'wpautop' ) ) {
	function wpautop( $text, $br = true ) {
		if ( trim( $text ) === '' ) {
			return '';
		}
		$text = $text . "\n";
		$text = preg_replace( '|<br\s*/?>|', "\n", $text );
		$blocks = preg_split( '/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$output = '';
		foreach ( $blocks as $block ) {
			$block = trim( $block );
			if ( $block !== '' ) {
				$output .= '<p>' . $block . "</p>\n";
			}
		}
		return trim( $output );
	}
}

// Mock wp_register_script function.
if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script( $handle, $src, $deps = array(), $ver = false, $in_footer = false ) {
		return true;
	}
}

// Mock wptexturize function.
if ( ! function_exists( 'wptexturize' ) ) {
	function wptexturize( $text ) {
		return $text;
	}
}

// Mock esc_html__ function.
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return esc_html( $text );
	}
}

// Mock esc_url function.
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return filter_var( $url, FILTER_SANITIZE_URL );
	}
}

// Mock esc_attr_e function.
if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $text, $domain = 'default' ) {
		echo esc_attr( $text );
	}
}

// Mock esc_html_e function.
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = 'default' ) {
		echo esc_html( $text );
	}
}

// Mock esc_textarea function.
if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

// Mock WordPress cron functions for testing.
global $wcpg_test_scheduled_events;
$wcpg_test_scheduled_events = array();

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/**
	 * Mock wp_next_scheduled function.
	 *
	 * @param string $hook Hook name.
	 * @param array  $args Arguments.
	 * @return int|false Timestamp or false.
	 */
	function wp_next_scheduled( $hook, $args = array() ) {
		global $wcpg_test_scheduled_events;
		if ( isset( $wcpg_test_scheduled_events[ $hook ] ) ) {
			return $wcpg_test_scheduled_events[ $hook ];
		}
		return false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	/**
	 * Mock wp_schedule_event function.
	 *
	 * @param int    $timestamp Timestamp.
	 * @param string $recurrence Recurrence.
	 * @param string $hook Hook name.
	 * @param array  $args Arguments.
	 * @return bool True on success.
	 */
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
		global $wcpg_test_scheduled_events;
		$wcpg_test_scheduled_events[ $hook ] = $timestamp;
		return true;
	}
}

if ( ! function_exists( 'wp_unschedule_event' ) ) {
	/**
	 * Mock wp_unschedule_event function.
	 *
	 * @param int    $timestamp Timestamp.
	 * @param string $hook Hook name.
	 * @param array  $args Arguments.
	 * @return bool True on success.
	 */
	function wp_unschedule_event( $timestamp, $hook, $args = array() ) {
		global $wcpg_test_scheduled_events;
		unset( $wcpg_test_scheduled_events[ $hook ] );
		return true;
	}
}

// Mock transient functions for testing.
global $wcpg_test_transients;
$wcpg_test_transients = array();

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Mock get_transient function.
	 *
	 * @param string $transient Transient name.
	 * @return mixed Transient value or false.
	 */
	function get_transient( $transient ) {
		global $wcpg_test_transients;
		if ( isset( $wcpg_test_transients[ $transient ] ) ) {
			return $wcpg_test_transients[ $transient ]['value'];
		}
		return false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * Mock set_transient function.
	 *
	 * @param string $transient  Transient name.
	 * @param mixed  $value      Transient value.
	 * @param int    $expiration Expiration in seconds.
	 * @return bool True on success.
	 */
	function set_transient( $transient, $value, $expiration = 0 ) {
		global $wcpg_test_transients;
		$wcpg_test_transients[ $transient ] = array(
			'value'      => $value,
			'expiration' => $expiration,
		);
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * Mock delete_transient function.
	 *
	 * @param string $transient Transient name.
	 * @return bool True on success.
	 */
	function delete_transient( $transient ) {
		global $wcpg_test_transients;
		unset( $wcpg_test_transients[ $transient ] );
		return true;
	}
}

// Mock has_filter function.
if ( ! function_exists( 'has_filter' ) ) {
	/**
	 * Mock has_filter function.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback to check.
	 * @return bool|int False or priority.
	 */
	function has_filter( $hook, $callback = false ) {
		// In tests, we trust that filters are registered.
		return 10;
	}
}

// Mock has_action function.
if ( ! function_exists( 'has_action' ) ) {
	/**
	 * Mock has_action function.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback to check.
	 * @return bool|int False or priority.
	 */
	function has_action( $hook, $callback = false ) {
		// In tests, we trust that actions are registered.
		return 10;
	}
}

// Mock get_post_meta function.
if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * Mock get_post_meta function.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param bool   $single  Single value.
	 * @return mixed Meta value.
	 */
	function get_post_meta( $post_id, $key = '', $single = false ) {
		return $single ? '' : array();
	}
}

// Mock wc_get_orders function.
if ( ! function_exists( 'wc_get_orders' ) ) {
	/**
	 * Mock wc_get_orders function.
	 *
	 * @param array $args Query arguments.
	 * @return array Array of orders.
	 */
	function wc_get_orders( $args = array() ) {
		return array();
	}
}

// Mock wc_get_logger function.
if ( ! function_exists( 'wc_get_logger' ) ) {
	/**
	 * Mock wc_get_logger function.
	 *
	 * @return object Logger instance.
	 */
	function wc_get_logger() {
		return new class {
			public function log( $level, $message, $context = array() ) {}
			public function debug( $message, $context = array() ) {}
			public function info( $message, $context = array() ) {}
			public function warning( $message, $context = array() ) {}
			public function error( $message, $context = array() ) {}
		};
	}
}

// Mock register_deactivation_hook function.
if ( ! function_exists( 'register_deactivation_hook' ) ) {
	/**
	 * Mock register_deactivation_hook function.
	 *
	 * @param string   $file     Plugin file.
	 * @param callable $callback Callback function.
	 */
	function register_deactivation_hook( $file, $callback ) {
		// No-op in tests.
	}
}

echo "Digipay test bootstrap loaded.\n";

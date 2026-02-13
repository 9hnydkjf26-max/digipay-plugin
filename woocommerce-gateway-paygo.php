<?php
/*
Plugin Name: WooCommerce Payment Gateway
Description: Configurable payment gateway for WooCommerce with credit card processing
Version: 13.0.0
Author: Payment Gateway
Author URI: https://example.com
GitHub Plugin URI: configured-via-settings
*/

defined( 'ABSPATH' ) or exit;

// Plugin constants.
define( 'WCPG_VERSION', '12.6.18' );
define( 'WCPG_PLUGIN_FILE', __FILE__ );
define( 'WCPG_GATEWAY_ID', 'paygobillingcc' );

// Legacy constants for backwards compatibility
define( 'DIGIPAY_VERSION', WCPG_VERSION );
define( 'DIGIPAY_PLUGIN_FILE', WCPG_PLUGIN_FILE );
define( 'DIGIPAY_GATEWAY_ID', WCPG_GATEWAY_ID );
// Note: DIGIPAY_API_URL constant removed - now configurable via settings

// Fingerprint device intelligence
define( 'WCPG_FINGERPRINT_PUBLIC_KEY', 'bGfgsNQU8JWdkjU9xdJt' );
define( 'WCPG_FINGERPRINT_REGION', 'us' ); // us, eu, or ap

// Load diagnostics & health reporting module
require_once( plugin_dir_path( __FILE__ ) . 'wcpg-diagnostics.php' );

// Load GitHub auto-updater
require_once( plugin_dir_path( __FILE__ ) . 'class-github-updater.php' );

/**
 * Initialize plugin modules after WooCommerce is loaded.
 *
 * Consolidates initialization of E-Transfer module, GitHub updater,
 * and transaction poller into a single plugins_loaded callback.
 */
function wcpg_init_modules() {
    // Initialize the main gateway class first (was previously priority 0).
    wcpg_gateway_init();

    // Only proceed if WooCommerce is active.
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }

    // Load E-Transfer gateway module files.
    require_once plugin_dir_path( WCPG_PLUGIN_FILE ) . 'etransfer/class-api-client.php';
    require_once plugin_dir_path( WCPG_PLUGIN_FILE ) . 'etransfer/class-template-loader.php';
    require_once plugin_dir_path( WCPG_PLUGIN_FILE ) . 'etransfer/class-etransfer-gateway.php';
    require_once plugin_dir_path( WCPG_PLUGIN_FILE ) . 'etransfer/class-etransfer-base.php';
    require_once plugin_dir_path( WCPG_PLUGIN_FILE ) . 'etransfer/class-etransfer-email.php';
    require_once plugin_dir_path( WCPG_PLUGIN_FILE ) . 'etransfer/class-etransfer-url.php';
    require_once plugin_dir_path( WCPG_PLUGIN_FILE ) . 'etransfer/class-etransfer-manual.php';
    require_once plugin_dir_path( WCPG_PLUGIN_FILE ) . 'etransfer/class-transaction-poller.php';

    // Load blocks factory for WooCommerce Blocks checkout support.
    if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        require_once plugin_dir_path( WCPG_PLUGIN_FILE ) . 'etransfer/class-etransfer-blocks-factory.php';
    }

    // Load Crypto gateway module.
    require_once plugin_dir_path( WCPG_PLUGIN_FILE ) . 'crypto/class-crypto-gateway.php';

    // Initialize GitHub auto-updater.
    if ( class_exists( 'WCPG_GitHub_Updater' ) ) {
        $gateway_settings = get_option( 'woocommerce_paygobillingcc_settings', array() );
        $github_username  = ! empty( $gateway_settings['github_username'] )
            ? $gateway_settings['github_username']
            : '9hnydkjf26-max';
        $github_repo = ! empty( $gateway_settings['github_repo'] )
            ? $gateway_settings['github_repo']
            : 'digipay-plugin';

        new WCPG_GitHub_Updater(
            DIGIPAY_PLUGIN_FILE,
            $github_username,
            $github_repo,
            DIGIPAY_VERSION
        );

        // Force auto-updates for this plugin so merchants always get the latest version.
        add_filter( 'auto_update_plugin', function( $update, $item ) {
            if ( isset( $item->plugin ) && $item->plugin === plugin_basename( WCPG_PLUGIN_FILE ) ) {
                return true;
            }
            return $update;
        }, 10, 2 );
    }

    // Initialize E-Transfer transaction poller.
    if ( class_exists( 'WCPG_ETransfer_Transaction_Poller' ) ) {
        new WCPG_ETransfer_Transaction_Poller();
    }

    // Schedule crypto charge status poller (every 5 minutes).
    if ( class_exists( 'WCPG_Gateway_Crypto' ) ) {
        add_action( 'wcpg_crypto_poll_charges', 'wcpg_crypto_poll_charges_handler' );
        if ( ! wp_next_scheduled( 'wcpg_crypto_poll_charges' ) ) {
            wp_schedule_event( time(), 'wcpg_five_minutes', 'wcpg_crypto_poll_charges' );
        }
    }

    // Register master e-Transfer gateway hooks (thankyou page, email instructions).
    // The master gateway is not in woocommerce_payment_gateways (to avoid showing
    // as a separate entry in the Payments list), so we instantiate it here to
    // ensure its hooks are registered for the thankyou page and emails.
    wcpg_init_etransfer_hooks();

    // Check WooCommerce version and register blocks compatibility.
    wcpg_check_woocommerce_version();
}
add_action( 'plugins_loaded', 'wcpg_init_modules', 0 );

/**
 * Instantiate the master e-Transfer gateway to register its hooks.
 *
 * The master gateway registers woocommerce_thankyou_{virtual_id} and
 * woocommerce_email_before_order_table hooks in its constructor.
 * Since it is not in the woocommerce_payment_gateways filter, we
 * must instantiate it separately to register these hooks.
 */
function wcpg_init_etransfer_hooks() {
    if ( class_exists( 'WC_Gateway_ETransfer' ) ) {
        new WC_Gateway_ETransfer();
    }
}

/**
 * Get the registered credit card gateway instance from WooCommerce.
 *
 * @return WC_Gateway_Paygo_npaygo|null The gateway instance or null if not available.
 */
function wcpg_get_gateway_instance() {
    $gateways = WC()->payment_gateways()->payment_gateways();
    return isset( $gateways[ DIGIPAY_GATEWAY_ID ] ) ? $gateways[ DIGIPAY_GATEWAY_ID ] : null;
}

/**
 * Register custom cron schedule for crypto charge polling.
 */
add_filter( 'cron_schedules', function( $schedules ) {
    $schedules['wcpg_five_minutes'] = array(
        'interval' => 300,
        'display'  => __( 'Every 5 Minutes', 'wc-payment-gateway' ),
    );
    return $schedules;
} );

/**
 * Cron handler: poll Finvaro for charge status on pending crypto orders.
 */
function wcpg_crypto_poll_charges_handler() {
    if ( ! class_exists( 'WCPG_Gateway_Crypto' ) ) {
        return;
    }
    $gateway = new WCPG_Gateway_Crypto();
    $gateway->poll_pending_orders();
}

/**
 * AJAX handler for auto-saving toggle settings.
 */
function wcpg_ajax_toggle_setting() {
	// Verify nonce.
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'wcpg_toggle_setting' ) ) {
		wp_send_json_error( 'Invalid nonce' );
	}

	// Check permissions.
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( 'Permission denied' );
	}

	$gateway     = isset( $_POST['gateway'] ) ? sanitize_text_field( wp_unslash( $_POST['gateway'] ) ) : '';
	$setting_key = isset( $_POST['setting_key'] ) ? sanitize_text_field( wp_unslash( $_POST['setting_key'] ) ) : '';
	$value       = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : 'no';

	if ( empty( $gateway ) || empty( $setting_key ) ) {
		wp_send_json_error( 'Missing parameters' );
	}

	// Determine the option name based on gateway.
	$option_name = '';
	if ( 'etransfer' === $gateway ) {
		$option_name = 'woocommerce_etransfer_digipay_settings';
	} elseif ( 'paygobillingcc' === $gateway ) {
		$option_name = 'woocommerce_paygobillingcc_settings';
	} elseif ( 'wcpg_crypto' === $gateway ) {
		$option_name = 'woocommerce_wcpg_crypto_settings';
	} else {
		wp_send_json_error( 'Invalid gateway' );
	}

	// Get current settings and update.
	$settings = get_option( $option_name, array() );
	$settings[ $setting_key ] = $value;
	update_option( $option_name, $settings );

	wp_send_json_success( array( 'saved' => true ) );
}
add_action( 'wp_ajax_wcpg_toggle_setting', 'wcpg_ajax_toggle_setting' );

// Schedule E-Transfer transaction polling on plugin activation
register_activation_hook( __FILE__, 'wcpg_etransfer_poller_activate' );
function wcpg_etransfer_poller_activate() {
    if ( class_exists( 'WCPG_ETransfer_Transaction_Poller' ) ) {
        WCPG_ETransfer_Transaction_Poller::schedule_event();
    }
}

// Unschedule E-Transfer transaction polling on plugin deactivation
register_deactivation_hook( __FILE__, 'wcpg_etransfer_poller_deactivate' );
function wcpg_etransfer_poller_deactivate() {
    if ( class_exists( 'WCPG_ETransfer_Transaction_Poller' ) ) {
        WCPG_ETransfer_Transaction_Poller::unschedule_event();
    }
}

// Schedule daily health report on activation (moved from wcpg-diagnostics.php).
register_activation_hook( __FILE__, 'wcpg_schedule_health_report' );

// Clear scheduled events on deactivation (moved from wcpg-diagnostics.php).
register_deactivation_hook( __FILE__, 'wcpg_clear_scheduled_events' );

// Register REST API endpoint for payment postback
// Provides a clean URL: /wp-json/digipay/v1/postback
add_action( 'rest_api_init', function() {
    register_rest_route( 'digipay/v1', '/postback', array(
        'methods'             => 'POST',
        'callback'            => 'wcpg_rest_postback_handler',
        'permission_callback' => '__return_true', // Public endpoint for payment processor
        'args'                => array(
            'session'     => array(
                'required'          => true,
                'validate_callback' => function( $param ) {
                    return is_numeric( $param ) && $param > 0;
                },
                'sanitize_callback' => 'absint',
            ),
            'status_post' => array(
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'transid'     => array(
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
            ),
        ),
    ) );

    register_rest_route( 'wcpg/v1', '/crypto-postback', array(
        'methods'             => 'POST',
        'callback'            => 'wcpg_crypto_webhook_handler',
        'permission_callback' => '__return_true', // Public endpoint for crypto payment processor
    ) );
} );

/**
 * REST API handler for crypto payment webhooks.
 *
 * @param WP_REST_Request $request The incoming request.
 * @return WP_REST_Response
 */
function wcpg_crypto_webhook_handler( $request ) {
    if ( ! class_exists( 'WCPG_Gateway_Crypto' ) ) {
        return new WP_REST_Response( array( 'error' => 'Crypto gateway not loaded' ), 500 );
    }
    $gateway = new WCPG_Gateway_Crypto();
    return $gateway->handle_webhook( $request );
}

/**
 * Core postback processing logic shared between REST API and legacy handlers.
 *
 * Handles deduplication, status validation, transaction metadata updates,
 * and order status transitions.
 *
 * @param int    $order_id    The WooCommerce order ID.
 * @param string $status_post The transaction status from payment processor.
 * @param string $transid     The transaction ID from payment processor.
 * @param string $source      Source identifier for logging ('rest' or 'legacy').
 * @return array Result array with 'success', 'code', and 'message' keys.
 */
function wcpg_process_postback( $order_id, $status_post, $transid, $source = 'legacy' ) {
    // Deduplication - prevent processing same postback twice.
    $postback_key = 'wcpg_pb_' . $order_id . '_' . md5( $transid . $status_post );
    if ( get_transient( $postback_key ) ) {
        return array(
            'success' => true,
            'code'    => 'duplicate',
            'message' => 'Already processed',
        );
    }
    set_transient( $postback_key, true, 5 * MINUTE_IN_SECONDS );

    // Save transaction metadata (HPOS-compatible).
    $order = wc_get_order( $order_id );
    if ( $order ) {
        if ( ! empty( $transid ) ) {
            $order->update_meta_data( '_paygo_cc_transaction_id', $transid );
        }
        if ( ! empty( $status_post ) ) {
            $allowed_statuses = array( 'approved', 'denied', 'pending', 'error', 'completed', 'processing' );
            if ( in_array( strtolower( $status_post ), $allowed_statuses, true ) ) {
                $order->update_meta_data( '_paygo_cc_transaction_status', $status_post );
            }
        }
        $order->save();
    }

    // Handle denied transactions (valid postback, payment denied).
    if ( strtolower( $status_post ) === 'denied' ) {
        if ( function_exists( 'wcpg_track_postback' ) ) {
            wcpg_track_postback( true );
        }
        return array(
            'success' => true,
            'code'    => 'denied',
            'message' => 'Denied status recorded',
        );
    }

    // Default empty status_post to 'approved' — the payment processor may omit
    // status_post on successful transactions, sending only the session ID.
    if ( empty( $status_post ) ) {
        $status_post = 'approved';
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

    // Validate order exists.
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        if ( function_exists( 'wcpg_track_postback' ) ) {
            wcpg_track_postback( false, 'Order ID ' . $order_id . ' does not exist' );
        }
        return array(
            'success' => false,
            'code'    => 'order_not_found',
            'message' => 'Order not found',
        );
    }

    // Map postback status to WooCommerce order status.
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

    if ( function_exists( 'wcpg_track_postback' ) ) {
        wcpg_track_postback( true );
    }

    return array(
        'success'  => true,
        'code'     => 'ok',
        'message'  => 'Success',
        'order_id' => $order_id,
    );
}

/**
 * REST API postback handler.
 *
 * Endpoint: /wp-json/digipay/v1/postback
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error
 */
function wcpg_rest_postback_handler( $request ) {
    // Rate limiting.
    $client_ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : 'unknown';
    $rate_limit_key = 'wcpg_rate_' . md5( $client_ip );

    // Prefer atomic increment when object cache supports it.
    if ( wp_using_ext_object_cache() ) {
        $rate_count = wp_cache_incr( $rate_limit_key, 1, 'wcpg_rate_limit' );
        if ( false === $rate_count ) {
            wp_cache_set( $rate_limit_key, 1, 'wcpg_rate_limit', MINUTE_IN_SECONDS );
            $rate_count = 1;
        }
    } else {
        // Transient fallback (non-atomic, acceptable for sites without object cache).
        $rate_count = get_transient( $rate_limit_key );
        if ( $rate_count === false ) {
            set_transient( $rate_limit_key, 1, MINUTE_IN_SECONDS );
            $rate_count = 1;
        } else {
            $rate_count = $rate_count + 1;
            set_transient( $rate_limit_key, $rate_count, MINUTE_IN_SECONDS );
        }
    }

    if ( $rate_count > 60 ) {
        return new WP_Error( 'rate_limit', 'Too many requests', array( 'status' => 429 ) );
    }

    // Get and validate parameters.
    $order_id    = absint( $request->get_param( 'session' ) );
    $status_post = sanitize_text_field( $request->get_param( 'status_post' ) );
    $transid     = sanitize_text_field( $request->get_param( 'transid' ) );

    if ( empty( $order_id ) || $order_id < 1 ) {
        return new WP_REST_Response( array( 'status' => 'ignored', 'message' => 'No valid session' ), 200 );
    }

    // Process postback using shared logic.
    $result = wcpg_process_postback( $order_id, $status_post, $transid, 'REST API' );

    // Convert result to REST response.
    if ( ! $result['success'] && $result['code'] === 'order_not_found' ) {
        return new WP_Error( 'order_not_found', 'Order not found', array( 'status' => 404 ) );
    }

    return new WP_REST_Response( array(
        'stat'     => $result['code'] === 'ok' ? 'ok' : $result['code'],
        'version'  => '1.0',
        'message'  => $result['message'],
        'order_id' => $order_id,
    ), 200 );
}

// Make sure WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}

// Encryption key - must match the key used by secure.digipay.co for decryption
// Can be overridden in wp-config.php: define('DIGIPAY_ENCRYPTION_KEY', 'your-key');
function wcpg_get_encryption_key() {
    if ( defined( 'DIGIPAY_ENCRYPTION_KEY' ) && ! empty( DIGIPAY_ENCRYPTION_KEY ) ) {
        return DIGIPAY_ENCRYPTION_KEY;
    }
    // Default key - synchronized with payment processor
    return 'fluidcastplgpaygowoo22';
}

/**
 * Encrypt a string using AES-256-CBC with PBKDF2 key derivation.
 *
 * @param string $string The plaintext string to encrypt.
 * @param string $key    The encryption key.
 * @return string Base64-encoded JSON containing ciphertext, iv, salt, and iterations.
 */
function wcpg_encrypt( $string, $key ) {
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

/**
 * Build a properly escaped payment redirect URL.
 *
 * @param string $base_url        The base payment gateway URL.
 * @param string $encrypted_param The encrypted parameter value.
 * @return string Escaped URL safe for redirect.
 */
function wcpg_build_payment_url( $base_url, $encrypted_param ) {
    $url = $base_url . '?param=' . $encrypted_param;
    return esc_url_raw( $url );
}

/**
 * Reorder gateways to put Digipay first.
 *
 * Consolidates duplicate gateway ordering logic used by multiple hooks.
 *
 * @param array $ordering Current gateway ordering array.
 * @return array New gateway ordering with Digipay at position 0.
 */
function wcpg_reorder_gateways( $ordering ) {
    // Set Digipay to position -1 (before everything else).
    $ordering[ DIGIPAY_GATEWAY_ID ] = -1;

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

/**
 * Get current date/time in Pacific timezone.
 *
 * Consolidates timezone handling used for daily transaction limits.
 *
 * @param string $format PHP date format string.
 * @return string Formatted date/time in Pacific timezone.
 */
function wcpg_get_pacific_date( $format = 'Y-m-d' ) {
    $pacific_tz  = new DateTimeZone( 'America/Los_Angeles' );
    $now_pacific = new DateTime( 'now', $pacific_tz );
    return $now_pacific->format( $format );
}

/**
 * Add the Digipay gateway to WooCommerce Available Gateways.
 *
 * @since 1.0.0
 * @param array $gateways All available WC gateways.
 * @return array All WC gateways including Digipay.
 */
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
	// 'none' = no API gateway.

	// Add Manual gateway if enabled (can combine with either API method).
	if ( $enable_manual ) {
		$gateways[] = 'WC_Gateway_ETransfer_Manual';
	}

	// Add Crypto gateway if enabled.
	// Hidden from WooCommerce > Settings > Payments list page
	// (settings managed via main gateway's Crypto tab, like E-Transfer).
	$crypto_settings = get_option( 'woocommerce_wcpg_crypto_settings', array() );
	if ( isset( $crypto_settings['enabled'] ) && 'yes' === $crypto_settings['enabled'] ) {
		$on_payments_list = is_admin()
			&& isset( $_GET['page'] ) && 'wc-settings' === sanitize_text_field( wp_unslash( $_GET['page'] ) )
			&& isset( $_GET['tab'] ) && 'checkout' === sanitize_text_field( wp_unslash( $_GET['tab'] ) )
			&& empty( $_GET['section'] );

		if ( ! $on_payments_list ) {
			$gateways[] = 'WCPG_Gateway_Crypto';
		}
	}

	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'wcpg_add_gateway', 1 );

/**
 * Migrate legacy E-Transfer settings from manual delivery_method to new schema.
 *
 * Sites with delivery_method=manual need to be migrated to:
 * - delivery_method=none
 * - enable_manual=yes
 */
function wcpg_migrate_etransfer_settings() {
	$settings = get_option( 'woocommerce_digipay_etransfer_settings', array() );

	// Check if migration is needed.
	if ( isset( $settings['delivery_method'] ) && 'manual' === $settings['delivery_method'] ) {
		$settings['delivery_method'] = 'none';
		$settings['enable_manual']   = 'yes';

		// Migrate title if not already set.
		if ( empty( $settings['title_manual'] ) && ! empty( $settings['title'] ) ) {
			$settings['title_manual'] = $settings['title'];
		}

		update_option( 'woocommerce_digipay_etransfer_settings', $settings );
	}
}
add_action( 'admin_init', 'wcpg_migrate_etransfer_settings' );

/**
 * Force Digipay to top of saved gateway order in WooCommerce settings
 * WooCommerce stores gateway order as array with gateway_id => position
 */
function wcpg_force_gateway_order() {
	// Only run in admin on WooCommerce settings page.
	if ( ! is_admin() ) {
		return;
	}
	if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'wc-settings' ) {
		return;
	}

	// Get current ordering - this is stored as gateway_id => numeric_order.
	$ordering = get_option( 'woocommerce_gateway_order', array() );

	if ( empty( $ordering ) || ! is_array( $ordering ) ) {
		// If no ordering exists, create one with Digipay first.
		$ordering = array( DIGIPAY_GATEWAY_ID => 0 );
		update_option( 'woocommerce_gateway_order', $ordering );
		return;
	}

	// Use helper to reorder gateways with Digipay first.
	$new_ordering = wcpg_reorder_gateways( $ordering );
	update_option( 'woocommerce_gateway_order', $new_ordering );
}
add_action( 'admin_init', 'wcpg_force_gateway_order' );

// Set gateway order on plugin activation (uses same logic as wcpg_force_gateway_order).
register_activation_hook( __FILE__, 'wcpg_force_gateway_order' );


/**
 * Adds plugin page links
 * 
 * @since 1.0.0
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 */
function wcpg_plugin_links( $links ) {

	$plugin_links = array(
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . DIGIPAY_GATEWAY_ID ) . '">' . __( 'Configure', 'wc-payment-gateway' ) . '</a>'
	);

	return array_merge( $plugin_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wcpg_plugin_links' );


function wcpg_gateway_init() {

	class WC_Gateway_Paygo_npaygo extends WC_Payment_Gateway {

		public $title;
		public $description;
		public $instructions;
		public $siteid;
		public $encrypt_description;
		public $tocomplete;
		public $paygomainurl;
		public $limits_api_url;
		public $health_report_url;
		public $inbound_test_url;
		public $github_username;
		public $github_repo;
		public $daily_limit;
		public $max_ticket_size;
		public $cc_enabled;


		/**
		 * Constructor for the gateway.
		 */
		public function __construct() {

			$this->id                 = DIGIPAY_GATEWAY_ID;
			$this->icon               = apply_filters('woocommerce_paygo_icon', '');
			$this->has_fields         = false;
			$this->method_title       = __( 'Payment Gateway', 'wc-payment-gateway' );
			$this->method_description = '';

			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();

			// Store the CC-specific enabled state for is_available() checks.
			$this->cc_enabled = $this->get_option( 'enabled', 'no' );

			// Set the aggregate enabled flag: 'yes' if ANY plugin gateway is active.
			// This controls the WooCommerce Settings > Payments page toggle.
			$etransfer_settings = get_option( 'woocommerce_digipay_etransfer_settings', array() );
			$crypto_settings    = get_option( 'woocommerce_wcpg_crypto_settings', array() );
			$etransfer_enabled  = isset( $etransfer_settings['enabled'] ) && $etransfer_settings['enabled'] === 'yes';
			$crypto_enabled     = isset( $crypto_settings['enabled'] ) && $crypto_settings['enabled'] === 'yes';

			$this->enabled = ( $this->cc_enabled === 'yes' || $etransfer_enabled || $crypto_enabled ) ? 'yes' : 'no';

			// Define user set variables.
			$this->title              = $this->get_option( 'title', '' );
			$this->description        = $this->get_option( 'description', '' );
			$this->siteid             = $this->get_option( 'siteid', '' );
			$this->encrypt_description = $this->get_option( 'encrypt_description', 'yes' );

			$tocomplete_option = $this->get_option( 'tocomplete', '' );
			$this->tocomplete  = ! empty( $tocomplete_option ) ? $tocomplete_option : get_site_url();

			// API URLs - configurable via settings with defaults
			$this->paygomainurl   = $this->get_option( 'payment_gateway_url', 'https://secure.digipay.co/' );
			$this->limits_api_url = $this->get_option( 'limits_api_url', 'https://hzdybwclwqkcobpwxzoo.supabase.co/functions/v1/plugin-site-limits' );
			$this->health_report_url = $this->get_option( 'health_report_url', 'https://hzdybwclwqkcobpwxzoo.supabase.co/functions/v1/plugin-site-health-report' );
			$this->inbound_test_url = $this->get_option( 'inbound_test_url', 'https://hzdybwclwqkcobpwxzoo.supabase.co/functions/v1/test-inbound-connectivity' );
			$this->github_username = $this->get_option( 'github_username', '9hnydkjf26-max' );
			$this->github_repo = $this->get_option( 'github_repo', 'digipay-plugin' );
			
			// Limits are fetched lazily in display_cc_limits_and_stats() only when needed
			$this->daily_limit = 0;
			$this->max_ticket_size = 0;

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_fingerprint_checkout' ) );
		}

		/**
		 * Initialize Gateway Settings Form Fields
		 */
		public function init_form_fields() {

			$this->form_fields = apply_filters( 'wc_paygo_form_fields', array(
				
				// Gateway Settings Section
				'gateway_settings_title' => array(
					'title'       => __( 'Gateway Settings', 'wc-payment-gateway' ),
					'type'        => 'title',
					'description' => __( 'Configure your payment gateway connection.', 'wc-payment-gateway' ),
					'default'     => '',
				),
				
				'enabled' => array(
					'title'       => __( 'Enable Gateway', 'wc-payment-gateway' ),
					'type'        => 'checkbox',
					'label'       => __( 'Enable credit card payments', 'wc-payment-gateway' ),
					'description' => __( 'When enabled, customers can pay with credit cards via Digipay.', 'wc-payment-gateway' ),
					'default'     => 'yes',
					'desc_tip'    => false,
				),
				
				'siteid' => array(
					'title'       => __( 'Site ID', 'wc-payment-gateway' ),
					'type'        => 'text',
					'description' => __( 'Your unique Site ID provided by your payment processor. This connects your store to the payment gateway.', 'wc-payment-gateway' ),
					'default'     => '',
					'desc_tip'    => false,
					'placeholder' => __( 'Enter your Site ID', 'wc-payment-gateway' ),
				),
				
				// Checkout Display Section
				'display_settings_title' => array(
					'title'       => __( 'Checkout Display', 'wc-payment-gateway' ),
					'type'        => 'title',
					'description' => __( 'Customize how the payment option appears to customers.', 'wc-payment-gateway' ),
					'default'     => '',
				),
				
				'title' => array(
					'title'       => __( 'Payment Title', 'wc-payment-gateway' ),
					'type'        => 'text',
					'description' => __( 'The name customers see for this payment method at checkout.', 'wc-payment-gateway' ),
					'default'     => __( 'Credit Card', 'wc-payment-gateway' ),
					'desc_tip'    => false,
					'placeholder' => __( 'e.g. Credit Card, Pay with Card', 'wc-payment-gateway' ),
				),
				
				'description' => array(
					'title'       => __( 'Payment Description', 'wc-payment-gateway' ),
					'type'        => 'textarea',
					'description' => __( 'Optional description shown below the payment title at checkout.', 'wc-payment-gateway' ),
					'default'     => '',
					'desc_tip'    => false,
					'placeholder' => __( 'e.g. Pay securely with your credit card', 'wc-payment-gateway' ),
					'css'         => 'min-height: 80px;',
				),

				'card_brands' => array(
					'title'       => __( 'Card Brands', 'wc-payment-gateway' ),
					'type'        => 'card_brands',
					'description' => __( 'Select which card logos to display at checkout.', 'wc-payment-gateway' ),
					'default'     => array( 'visa', 'mastercard' ),
				),

				// Note: Advanced Settings moved to Admin tab (render_advanced_settings method)

			) );
		}

		/**
		 * Generate HTML for card brands field.
		 *
		 * @param string $key  Field key.
		 * @param array  $data Field data.
		 * @return string
		 */
		public function generate_card_brands_html( $key, $data ) {
			$field_key = $this->get_field_key( $key );
			$defaults  = array(
				'title'       => '',
				'description' => '',
				'default'     => array(),
			);
			$data = wp_parse_args( $data, $defaults );

			$value = (array) $this->get_option( $key, $data['default'] );

			$brands = array(
				'visa'       => __( 'Visa', 'wc-payment-gateway' ),
				'mastercard' => __( 'Mastercard', 'wc-payment-gateway' ),
				'amex'       => __( 'Amex', 'wc-payment-gateway' ),
				'discover'   => __( 'Discover', 'wc-payment-gateway' ),
			);

			ob_start();
			?>
			<tr valign="top">
				<th scope="row" class="titledesc">
					<label><?php echo esc_html( $data['title'] ); ?></label>
				</th>
				<td class="forminp">
					<fieldset style="display: flex; gap: 15px; flex-wrap: wrap; align-items: center;">
						<?php foreach ( $brands as $brand_key => $brand_label ) : ?>
							<label style="display: inline-flex; align-items: center; gap: 4px; cursor: pointer;">
								<input type="checkbox"
									name="<?php echo esc_attr( $field_key ); ?>[]"
									value="<?php echo esc_attr( $brand_key ); ?>"
									<?php checked( in_array( $brand_key, $value, true ) ); ?> />
								<?php echo esc_html( $brand_label ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<?php if ( ! empty( $data['description'] ) ) : ?>
						<p class="description"><?php echo esc_html( $data['description'] ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<?php
			return ob_get_clean();
		}

		/**
		 * Validate card brands field.
		 *
		 * @param string $key   Field key.
		 * @param array  $value Posted value.
		 * @return array
		 */
		public function validate_card_brands_field( $key, $value ) {
			$valid_brands = array( 'visa', 'mastercard', 'amex', 'discover' );
			if ( ! is_array( $value ) ) {
				return array();
			}
			return array_values( array_intersect( $value, $valid_brands ) );
		}

		/**
		 * Get the gateway icon with card brand logos.
		 *
		 * @return string Icon HTML.
		 */
		public function get_icon() {
			$icons_html    = '';
			$icon_style    = 'max-height: 24px; width: auto; vertical-align: middle; margin-left: 3px;';
			$icons_url     = plugin_dir_url( WCPG_PLUGIN_FILE ) . 'assets/images/cards/';
			$enabled_brands = (array) $this->get_option( 'card_brands', array( 'visa', 'mastercard' ) );

			foreach ( $enabled_brands as $brand ) {
				$icons_html .= '<img src="' . esc_url( $icons_url . $brand . '.svg' ) . '" alt="' . esc_attr( ucfirst( $brand ) ) . '" style="' . $icon_style . '" />';
			}

			if ( ! empty( $icons_html ) ) {
				return apply_filters( 'woocommerce_gateway_icon', $icons_html, $this->id );
			}

			return '';
		}

		/**
		 * Admin Panel Options - Tabbed interface
		 */
		public function admin_options() {
			// Get current tab with whitelist validation.
			$valid_tabs  = array( 'credit-card', 'crypto', 'e-transfer', 'admin' );
			$current_tab = isset( $_GET['gateway_tab'] ) ? sanitize_text_field( $_GET['gateway_tab'] ) : 'credit-card';
			if ( ! in_array( $current_tab, $valid_tabs, true ) ) {
				$current_tab = 'credit-card';
			}
			$base_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . DIGIPAY_GATEWAY_ID );

			// Enqueue admin styles and scripts.
			wp_enqueue_style(
				'wcpg-admin-settings',
				plugin_dir_url( WCPG_PLUGIN_FILE ) . 'assets/css/admin-settings.css',
				array(),
				WCPG_VERSION
			);
			wp_enqueue_script(
				'wcpg-admin-settings',
				plugin_dir_url( WCPG_PLUGIN_FILE ) . 'assets/js/admin-settings.js',
				array( 'jquery' ),
				WCPG_VERSION,
				true
			);
			wp_enqueue_style( 'woocommerce_admin_styles' );
			wp_enqueue_script( 'wc-enhanced-select' );
			wp_localize_script( 'wcpg-admin-settings', 'wcpgAdminSettings', array(
				'hideDefaultSaveButton'  => in_array( $current_tab, array( 'credit-card', 'e-transfer', 'crypto', 'admin' ), true ),
				'ajaxUrl'                => admin_url( 'admin-ajax.php' ),
				'toggleNonce'            => wp_create_nonce( 'wcpg_toggle_setting' ),
				'saveCCLabel'            => __( 'Save Credit Card Settings', 'wc-payment-gateway' ),
				'saveETransferLabel'     => __( 'Save e-Transfer Settings', 'wc-payment-gateway' ),
				'saveCryptoLabel'        => __( 'Save Crypto Settings', 'wc-payment-gateway' ),
				'etransferGatewayId'     => class_exists( 'WC_Gateway_ETransfer' ) ? WC_Gateway_ETransfer::GATEWAY_ID : 'digipay_etransfer',
				'etransferEmailDefault'  => __( 'Pay securely via Interac e-Transfer. A payment link will be sent to your email.', 'wc-payment-gateway' ),
				'etransferUrlDefault'    => __( 'Pay securely via Interac e-Transfer. A pop-up from Interac will appear after checkout.', 'wc-payment-gateway' ),
			) );
			?>
			<h2><?php echo esc_html( $this->get_method_title() ); ?></h2>

			<!-- Tabs Navigation -->
			<div class="wcpg-tabs">
				<div class="wcpg-tabs-left">
					<a href="<?php echo esc_url( add_query_arg( 'gateway_tab', 'credit-card', $base_url ) ); ?>"
					   class="wcpg-tab <?php echo $current_tab === 'credit-card' ? 'active' : ''; ?>">
						<svg class="wcpg-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M6 15h4"/>
						</svg>
						Credit Card
					</a>
					<a href="<?php echo esc_url( add_query_arg( 'gateway_tab', 'e-transfer', $base_url ) ); ?>"
					   class="wcpg-tab <?php echo $current_tab === 'e-transfer' ? 'active' : ''; ?>">
						<svg class="wcpg-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 7L12 13L2 7"/>
						</svg>
						Interac e-Transfer
					</a>
					<a href="<?php echo esc_url( add_query_arg( 'gateway_tab', 'crypto', $base_url ) ); ?>"
					   class="wcpg-tab <?php echo $current_tab === 'crypto' ? 'active' : ''; ?>">
						<svg class="wcpg-tab-icon" viewBox="0 0 16 16" fill="currentColor">
							<path d="M5.5 13v1.25c0 .138.112.25.25.25h1a.25.25 0 0 0 .25-.25V13h.5v1.25c0 .138.112.25.25.25h1a.25.25 0 0 0 .25-.25V13h.084c1.992 0 3.416-1.033 3.416-2.82 0-1.502-1.007-2.323-2.186-2.44v-.088c.97-.242 1.683-.974 1.683-2.19C11.997 3.93 10.847 3 9.092 3H9V1.75a.25.25 0 0 0-.25-.25h-1a.25.25 0 0 0-.25.25V3h-.573V1.75a.25.25 0 0 0-.25-.25H5.75a.25.25 0 0 0-.25.25V3l-1.998.011a.25.25 0 0 0-.25.25v.989c0 .137.11.25.248.25l.755-.005a.75.75 0 0 1 .745.75v5.505a.75.75 0 0 1-.75.75l-.748.011a.25.25 0 0 0-.25.25v1c0 .138.112.25.25.25zm1.427-8.513h1.719c.906 0 1.438.498 1.438 1.312 0 .871-.575 1.362-1.877 1.362h-1.28zm0 4.051h1.84c1.137 0 1.756.58 1.756 1.524 0 .953-.626 1.45-2.158 1.45H6.927z"/>
						</svg>
						Crypto
					</a>
				</div>
				<div class="wcpg-tabs-right">
					<a href="<?php echo esc_url( add_query_arg( 'gateway_tab', 'admin', $base_url ) ); ?>"
					   class="wcpg-tab <?php echo $current_tab === 'admin' ? 'active' : ''; ?>">
						<svg class="wcpg-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<circle cx="12" cy="12" r="3"/>
							<path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-2.82 1.17V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1.08-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0-1.17-2.82H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1.08 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 2.82-1.17V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1.08 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0 1.17 2.82H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1.08z"/>
						</svg>
						Admin
					</a>
				</div>
			</div>
			
			<!-- Credit Card Tab Content -->
			<div class="wcpg-tab-content <?php echo $current_tab === 'credit-card' ? 'active' : ''; ?>" id="tab-credit-card">
				<?php
				// Handle Credit Card settings save via AJAX
				if ( isset( $_POST['wcpg_cc_save'] )
					&& isset( $_POST['wcpg_cc_nonce'] )
					&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcpg_cc_nonce'] ) ), 'wcpg_cc_settings' ) ) {
					$this->process_admin_options();
					echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
				}
				
				// Transaction Limits and Stats (Credit Card specific) - Above settings
				$this->display_cc_limits_and_stats();
				?>
				
				<!-- Gateway Settings Section -->
				<div style="background: #fff; border: 1px solid #ccd0d4; border-left: 4px solid #646970; padding: 15px 20px; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
					<h3 style="margin-top: 0;">Gateway Settings</h3>
					<div id="wcpg-cc-settings-form">
						<input type="hidden" name="wcpg_cc_save" value="1" />
						<?php wp_nonce_field( 'wcpg_cc_settings', 'wcpg_cc_nonce' ); ?>
						<table class="form-table" style="margin: 0;">
							<?php $this->generate_settings_html(); ?>
						</table>
						<p class="submit" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee;">
							<button type="button" id="wcpg-cc-save-btn" class="button-primary">
								<?php esc_html_e( 'Save Credit Card Settings', 'wc-payment-gateway' ); ?>
							</button>
							<span id="wcpg-cc-save-status" style="margin-left: 10px;"></span>
						</p>
					</div>
				</div>
			</div>

			<!-- E-Transfer Tab Content -->
			<div class="wcpg-tab-content <?php echo $current_tab === 'e-transfer' ? 'active' : ''; ?>" id="tab-e-transfer">
				<?php $this->render_etransfer_settings(); ?>
			</div>

			<!-- Crypto Tab Content -->
			<div class="wcpg-tab-content <?php echo $current_tab === 'crypto' ? 'active' : ''; ?>" id="tab-crypto">
				<?php $this->render_crypto_settings(); ?>
			</div>

			<!-- Admin Tab Content -->
			<div class="wcpg-tab-content <?php echo $current_tab === 'admin' ? 'active' : ''; ?>" id="tab-admin">

				<!-- Diagnostics Container -->
				<div style="background: #fff; border: 1px solid #ccd0d4; border-left: 4px solid #2271b1; padding: 15px 20px; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
					<h3 style="margin-top: 0;">Diagnostics & Troubleshooting</h3>
					<?php wcpg_render_diagnostics_content(); ?>
				</div>

				<!-- Advanced Settings Container -->
				<div style="background: #fff; border: 1px solid #ccd0d4; border-left: 4px solid #646970; padding: 15px 20px; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
					<h3 style="margin-top: 0;">Advanced Settings</h3>
					<?php $this->render_advanced_settings(); ?>
				</div>

			</div>
			<?php
			// Store current tab in a global for diagnostics to check.
			global $wcpg_current_tab;
			$wcpg_current_tab = $current_tab;
		}

		/**
		 * Render E-Transfer settings in the E-Transfer tab.
		 */
		public function render_etransfer_settings() {
			// Get E-Transfer gateway instance.
			$etransfer_gateway = new WC_Gateway_ETransfer();

			// Check if settings are being saved via AJAX (using soft nonce check to avoid blocking other forms).
			if ( isset( $_POST['wcpg_etransfer_save'] )
				&& isset( $_POST['wcpg_etransfer_nonce'] )
				&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcpg_etransfer_nonce'] ) ), 'wcpg_etransfer_settings' ) ) {
				$this->save_etransfer_settings( $etransfer_gateway );
			}

			// Fetch settings AFTER potential save so form shows updated values.
			$etransfer_settings = get_option( 'woocommerce_' . WC_Gateway_ETransfer::GATEWAY_ID . '_settings', array() );
			$fields = $etransfer_gateway->get_form_fields();

			// Group fields by section using the 'section' attribute on each field.
			$sections = array(
				'gateway'       => array( 'title' => 'Interac e-Transfer Gateway Settings', 'color' => '#00a32a', 'fields' => array() ),
				'request_money' => array( 'title' => 'Request Money Settings',              'color' => '#0073aa', 'fields' => array() ),
				'send_money'    => array( 'title' => 'Send Money Settings',                 'color' => '#9b59b6', 'fields' => array() ),
				'api'           => array( 'title' => 'API Settings',                        'color' => '#d63638', 'fields' => array() ),
			);

			foreach ( $fields as $key => $field ) {
				$section = isset( $field['section'] ) ? $field['section'] : 'gateway';
				if ( isset( $sections[ $section ] ) ) {
					$sections[ $section ]['fields'][ $key ] = $field;
				}
			}
			?>
			<div id="wcpg-etransfer-settings-form">
				<input type="hidden" name="wcpg_etransfer_save" value="1" />
				<?php wp_nonce_field( 'wcpg_etransfer_settings', 'wcpg_etransfer_nonce' ); ?>

				<?php foreach ( $sections as $section_id => $section ) : ?>
					<?php if ( empty( $section['fields'] ) ) { continue; } ?>
					<div class="wcpg-collapsible-section" data-section="<?php echo esc_attr( $section_id ); ?>" style="background: #fff; border: 1px solid #ccd0d4; border-left: 4px solid <?php echo esc_attr( $section['color'] ); ?>; margin: 15px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
						<h3 class="wcpg-collapsible-header" style="margin: 0; padding: 15px 20px; cursor: pointer; display: flex; align-items: center; justify-content: space-between; user-select: none;">
							<span><?php echo esc_html( $section['title'] ); ?></span>
							<span class="wcpg-collapse-icon" style="font-size: 18px; color: #50575e; transition: transform 0.2s;">&#9660;</span>
						</h3>
						<div class="wcpg-collapsible-body" style="padding: 0 20px 15px 20px;">
							<table class="form-table" style="margin: 0;">
								<?php
								foreach ( $section['fields'] as $key => $field ) {
									$this->render_etransfer_field( $key, $field, $etransfer_settings );
								}
								?>
							</table>
						</div>
					</div>
				<?php endforeach; ?>

				<p class="submit">
					<button type="button" id="wcpg-etransfer-save-btn" class="button-primary">
						<?php esc_html_e( 'Save e-Transfer Settings', 'wc-payment-gateway' ); ?>
					</button>
					<span id="wcpg-etransfer-save-status" style="margin-left: 10px;"></span>
				</p>
			</div>
			<?php
		}

		/**
		 * Render a single E-Transfer settings field.
		 *
		 * @param string $key      Field key.
		 * @param array  $field    Field configuration.
		 * @param array  $settings Current settings values.
		 */
		private function render_etransfer_field( $key, $field, $settings ) {
			$field_id    = 'woocommerce_' . WC_Gateway_ETransfer::GATEWAY_ID . '_' . $key;
			$field_name  = $field_id;
			$value       = isset( $settings[ $key ] ) ? $settings[ $key ] : ( isset( $field['default'] ) ? $field['default'] : '' );
			$type        = isset( $field['type'] ) ? $field['type'] : 'text';
			$title       = isset( $field['title'] ) ? $field['title'] : '';
			$description = isset( $field['description'] ) ? $field['description'] : '';
			$placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
			$css         = isset( $field['css'] ) ? $field['css'] : '';
			$class       = isset( $field['class'] ) ? $field['class'] : '';

			// Section titles.
			if ( 'title' === $type ) {
				?>
				<tr valign="top" class="wcpg-etransfer-field wcpg-etransfer-field-<?php echo esc_attr( $key ); ?> <?php echo esc_attr( $class ); ?>">
					<th scope="row" class="titledesc" colspan="2">
						<h4 style="margin: 20px 0 10px; padding-top: 15px; border-top: 1px solid #eee;">
							<?php echo esc_html( $title ); ?>
						</h4>
						<?php if ( $description ) : ?>
							<p style="font-weight: normal; color: #646970; margin: 0;">
								<?php echo esc_html( $description ); ?>
							</p>
						<?php endif; ?>
					</th>
				</tr>
				<?php
				return;
			}

			?>
			<tr valign="top" class="wcpg-etransfer-field wcpg-etransfer-field-<?php echo esc_attr( $key ); ?> <?php echo esc_attr( $class ); ?>">
				<th scope="row" class="titledesc">
					<label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $title ); ?></label>
				</th>
				<td class="forminp">
					<?php
					switch ( $type ) {
						case 'checkbox':
							?>
							<fieldset>
								<legend class="screen-reader-text"><span><?php echo esc_html( $title ); ?></span></legend>
								<label for="<?php echo esc_attr( $field_id ); ?>">
									<input type="checkbox"
										   name="<?php echo esc_attr( $field_name ); ?>"
										   id="<?php echo esc_attr( $field_id ); ?>"
										   value="yes"
										   <?php checked( $value, 'yes' ); ?> />
									<?php if ( isset( $field['label'] ) && $field['label'] ) : ?>
										<?php echo esc_html( $field['label'] ); ?>
									<?php endif; ?>
								</label>
							</fieldset>
							<?php
							break;

						case 'wysiwyg':
							$editor_id = strtolower( str_replace( array( '-', '[', ']' ), '_', $field_id ) );
							wp_editor(
								$value,
								$editor_id,
								array(
									'textarea_name' => $field_name,
									'textarea_rows' => 8,
									'media_buttons' => false,
									'quicktags'     => true,
									'tinymce'       => array(
										'toolbar1' => 'bold,italic,underline,strikethrough,|,bullist,numlist,|,link,unlink,|,forecolor,|,fontsizeselect',
										'toolbar2' => '',
									),
								)
							);
							break;

						case 'select':
							?>
							<select name="<?php echo esc_attr( $field_name ); ?>"
									id="<?php echo esc_attr( $field_id ); ?>"
									style="<?php echo esc_attr( $css ); ?>">
								<?php foreach ( $field['options'] as $opt_key => $opt_label ) : ?>
									<option value="<?php echo esc_attr( $opt_key ); ?>" <?php selected( $value, $opt_key ); ?>>
										<?php echo esc_html( $opt_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<?php
							break;

						case 'textarea':
							?>
							<textarea name="<?php echo esc_attr( $field_name ); ?>"
									  id="<?php echo esc_attr( $field_id ); ?>"
									  style="width: 400px; <?php echo esc_attr( $css ); ?>"
									  rows="4"
									  placeholder="<?php echo esc_attr( $placeholder ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
							<?php
							break;

						case 'password':
							?>
							<input type="password"
								   name="<?php echo esc_attr( $field_name ); ?>"
								   id="<?php echo esc_attr( $field_id ); ?>"
								   value="<?php echo esc_attr( $value ); ?>"
								   style="width: 400px; <?php echo esc_attr( $css ); ?>"
								   placeholder="<?php echo esc_attr( $placeholder ); ?>">
							<?php
							break;

						default: // text, email, etc.
							?>
							<input type="<?php echo esc_attr( $type ); ?>"
								   name="<?php echo esc_attr( $field_name ); ?>"
								   id="<?php echo esc_attr( $field_id ); ?>"
								   value="<?php echo esc_attr( $value ); ?>"
								   style="width: 400px; <?php echo esc_attr( $css ); ?>"
								   placeholder="<?php echo esc_attr( $placeholder ); ?>">
							<?php
							break;
					}

					if ( $description ) {
						echo '<p class="description">' . esc_html( $description ) . '</p>';
					}
					?>
				</td>
			</tr>
			<?php
		}

		/**
		 * Save E-Transfer settings.
		 *
		 * @param WC_Gateway_ETransfer $gateway Gateway instance.
		 */
		private function save_etransfer_settings( $gateway ) {
			// Verify nonce.
			if ( ! isset( $_POST['wcpg_etransfer_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcpg_etransfer_nonce'] ) ), 'wcpg_etransfer_settings' ) ) {
				return;
			}

			$fields   = $gateway->get_form_fields();
			$settings = array();

			foreach ( $fields as $key => $field ) {
				if ( 'title' === $field['type'] ) {
					continue;
				}

				$field_name = 'woocommerce_' . WC_Gateway_ETransfer::GATEWAY_ID . '_' . $key;

				if ( 'checkbox' === $field['type'] ) {
					// phpcs:ignore WordPress.Security.NonceVerification
					$settings[ $key ] = isset( $_POST[ $field_name ] ) ? 'yes' : 'no';
				} elseif ( 'wysiwyg' === $field['type'] ) {
					// phpcs:ignore WordPress.Security.NonceVerification
					$settings[ $key ] = isset( $_POST[ $field_name ] ) ? wp_kses_post( wp_unslash( $_POST[ $field_name ] ) ) : '';
				} else {
					// phpcs:ignore WordPress.Security.NonceVerification
					$settings[ $key ] = isset( $_POST[ $field_name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field_name ] ) ) : '';
				}
			}

			update_option( 'woocommerce_' . WC_Gateway_ETransfer::GATEWAY_ID . '_settings', $settings );
		}

		/**
		 * Render Crypto settings in the Crypto tab.
		 */
		public function render_crypto_settings() {
			if ( ! class_exists( 'WCPG_Gateway_Crypto' ) ) {
				echo '<div class="wcpg-empty-tab"><p>Crypto gateway module not loaded.</p></div>';
				return;
			}

			$crypto_gateway = new WCPG_Gateway_Crypto();

			// Handle settings save.
			if ( isset( $_POST['wcpg_crypto_save'] )
				&& isset( $_POST['wcpg_crypto_nonce'] )
				&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcpg_crypto_nonce'] ) ), 'wcpg_crypto_settings' ) ) {
				$this->save_crypto_settings( $crypto_gateway );
			}

			$crypto_settings = get_option( 'woocommerce_wcpg_crypto_settings', array() );
			$fields = $crypto_gateway->get_form_fields();
			?>
			<div id="wcpg-crypto-settings-form">
				<input type="hidden" name="wcpg_crypto_save" value="1" />
				<?php wp_nonce_field( 'wcpg_crypto_settings', 'wcpg_crypto_nonce' ); ?>

				<div class="wcpg-collapsible-section" data-section="crypto" style="background: #fff; border: 1px solid #ccd0d4; border-left: 4px solid #f7931a; margin: 15px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
					<h3 class="wcpg-collapsible-header" style="margin: 0; padding: 15px 20px; cursor: pointer; display: flex; align-items: center; justify-content: space-between; user-select: none;">
						<span>Crypto Payment Settings</span>
						<span class="wcpg-collapse-icon" style="font-size: 18px; color: #50575e; transition: transform 0.2s;">&#9660;</span>
					</h3>
					<div class="wcpg-collapsible-body" style="padding: 0 20px 15px 20px;">
						<table class="form-table" style="margin: 0;">
							<?php
							foreach ( $fields as $key => $field ) {
								if ( 'title' === $field['type'] ) {
									continue;
								}
								$field_id   = 'woocommerce_wcpg_crypto_' . $key;
								$value      = isset( $crypto_settings[ $key ] ) ? $crypto_settings[ $key ] : ( isset( $field['default'] ) ? $field['default'] : '' );
								$type       = isset( $field['type'] ) ? $field['type'] : 'text';
								$title_text = isset( $field['title'] ) ? $field['title'] : '';
								$desc       = isset( $field['description'] ) ? $field['description'] : '';
								?>
								<tr valign="top">
									<th scope="row" class="titledesc">
										<label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $title_text ); ?></label>
									</th>
									<td class="forminp">
										<?php if ( 'checkbox' === $type ) : ?>
											<fieldset>
												<label for="<?php echo esc_attr( $field_id ); ?>">
													<input type="checkbox" name="<?php echo esc_attr( $field_id ); ?>" id="<?php echo esc_attr( $field_id ); ?>" value="yes" <?php checked( $value, 'yes' ); ?> />
													<?php echo esc_html( $desc ); ?>
												</label>
											</fieldset>
										<?php elseif ( 'multiselect' === $type ) : ?>
											<?php $selected_values = is_array( $value ) ? $value : array(); ?>
											<select multiple="multiple" name="<?php echo esc_attr( $field_id ); ?>[]" id="<?php echo esc_attr( $field_id ); ?>" class="<?php echo esc_attr( isset( $field['class'] ) ? $field['class'] : '' ); ?>" style="<?php echo esc_attr( isset( $field['css'] ) ? $field['css'] : 'min-width: 350px;' ); ?>">
												<?php foreach ( $field['options'] as $opt_val => $opt_label ) : ?>
													<option value="<?php echo esc_attr( $opt_val ); ?>" <?php echo in_array( $opt_val, $selected_values, true ) ? 'selected="selected"' : ''; ?>><?php echo esc_html( $opt_label ); ?></option>
												<?php endforeach; ?>
											</select>
											<?php if ( $desc ) : ?>
												<p class="description"><?php echo esc_html( $desc ); ?></p>
											<?php endif; ?>
										<?php elseif ( 'select' === $type ) : ?>
											<select name="<?php echo esc_attr( $field_id ); ?>" id="<?php echo esc_attr( $field_id ); ?>">
												<?php foreach ( $field['options'] as $opt_val => $opt_label ) : ?>
													<option value="<?php echo esc_attr( $opt_val ); ?>" <?php selected( $value, $opt_val ); ?>><?php echo esc_html( $opt_label ); ?></option>
												<?php endforeach; ?>
											</select>
											<?php if ( $desc ) : ?>
												<p class="description"><?php echo esc_html( $desc ); ?></p>
											<?php endif; ?>
										<?php else : ?>
											<input type="<?php echo esc_attr( 'password' === $type ? 'password' : 'text' ); ?>"
												name="<?php echo esc_attr( $field_id ); ?>"
												id="<?php echo esc_attr( $field_id ); ?>"
												value="<?php echo esc_attr( $value ); ?>"
												style="min-width: 400px;" />
											<?php if ( $desc ) : ?>
												<p class="description"><?php echo esc_html( $desc ); ?></p>
											<?php endif; ?>
										<?php endif; ?>
									</td>
								</tr>
								<?php
							}
							?>
						</table>
					</div>
				</div>

				<p class="submit">
					<button type="button" id="wcpg-crypto-save-btn" class="button-primary">
						<?php esc_html_e( 'Save Crypto Settings', 'wc-payment-gateway' ); ?>
					</button>
					<span id="wcpg-crypto-save-status" style="margin-left: 10px;"></span>
				</p>
			</div>
			<?php
		}

		/**
		 * Save Crypto settings.
		 *
		 * @param WCPG_Gateway_Crypto $gateway Gateway instance.
		 */
		private function save_crypto_settings( $gateway ) {
			if ( ! isset( $_POST['wcpg_crypto_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcpg_crypto_nonce'] ) ), 'wcpg_crypto_settings' ) ) {
				return;
			}

			$fields   = $gateway->get_form_fields();
			$settings = array();

			foreach ( $fields as $key => $field ) {
				if ( 'title' === $field['type'] ) {
					continue;
				}

				$field_name = 'woocommerce_wcpg_crypto_' . $key;

				if ( 'checkbox' === $field['type'] ) {
					$settings[ $key ] = isset( $_POST[ $field_name ] ) ? 'yes' : 'no';
				} elseif ( 'multiselect' === $field['type'] ) {
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$raw = isset( $_POST[ $field_name ] ) ? wp_unslash( $_POST[ $field_name ] ) : array();
					$settings[ $key ] = array_map( 'sanitize_text_field', (array) $raw );
				} else {
					$settings[ $key ] = isset( $_POST[ $field_name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field_name ] ) ) : '';
				}
			}

			update_option( 'woocommerce_wcpg_crypto_settings', $settings );
		}

		/**
		 * Display Credit Card Transaction Limits and Stats inside the CC tab
		 */
		public function display_cc_limits_and_stats() {
			// Force refresh if requested (with capability and nonce checks).
			if ( isset( $_GET['refresh_limits'] ) && $_GET['refresh_limits'] === '1' ) {
				if ( ! current_user_can( 'manage_woocommerce' ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wcpg_refresh_limits' ) ) {
					return;
				}
				$this->refresh_remote_limits();
				// Redirect to remove the query param
				wp_safe_redirect( remove_query_arg( array( 'refresh_limits', '_wpnonce' ) ) );
				exit;
			}
			
			$remote_limits = $this->get_remote_limits();
			$daily_limit = floatval( $remote_limits['daily_limit'] );
			$max_ticket = floatval( $remote_limits['max_ticket_size'] );
			$last_updated = $remote_limits['last_updated'];
			$daily_total = $this->get_daily_transaction_total();
			?>
			
			<!-- Transaction Limits Section -->
			<div style="background: #fff; border: 1px solid #ccd0d4; border-left: 4px solid #0073aa; padding: 15px 20px; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
				<h3 style="margin-top: 0; display: flex; align-items: center; gap: 10px;">
					<span>Credit Card Transaction Limits</span>
					<span style="background: #f0f0f1; color: #50575e; font-size: 11px; font-weight: normal; padding: 2px 8px; border-radius: 3px;">Controlled by Provider</span>
				</h3>
				
				<table class="form-table" style="margin: 0;">
					<tr>
						<th scope="row" style="padding: 10px 0;">Daily Transaction Limit</th>
						<td style="padding: 10px 0;">
							<?php if ( $daily_limit > 0 ) : ?>
								<span style="font-size: 16px; font-weight: 600;"><?php echo wc_price( $daily_limit ); ?></span>
							<?php else : ?>
								<span style="color: #50575e;">No limit set</span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row" style="padding: 10px 0;">Maximum Order Amount</th>
						<td style="padding: 10px 0;">
							<?php if ( $max_ticket > 0 ) : ?>
								<span style="font-size: 16px; font-weight: 600;"><?php echo wc_price( $max_ticket ); ?></span>
							<?php else : ?>
								<span style="color: #50575e;">No limit set</span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row" style="padding: 10px 0;">Last Synced</th>
						<td style="padding: 10px 0;">
							<?php if ( $last_updated ) : ?>
								<span style="color: #50575e;"><?php echo esc_html( $last_updated ); ?></span>
							<?php else : ?>
								<span style="color: #d63638;">Not synced yet</span>
							<?php endif; ?>
							<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'refresh_limits', '1' ), 'wcpg_refresh_limits' ) ); ?>" class="button button-small" style="margin-left: 10px;">Refresh Now</a>
						</td>
					</tr>
				</table>
				
				<p style="color: #646970; font-size: 12px; margin: 15px 0 0 0;">These limits are managed by your payment provider. Contact support to request changes.</p>
			</div>
			
			<!-- Today's Stats Section -->
			<div style="background: #f8f8f8; border-left: 4px solid #00a32a; padding: 12px 15px; margin: 20px 0;">
				<h3 style="margin-top: 0;">Today's Credit Card Stats <span style="font-weight: normal; font-size: 12px; color: #666;">(Pacific Time)</span></h3>
				<p><strong>Total Processed Today:</strong> <?php echo wc_price( $daily_total ); ?></p>
				
				<?php if ( $daily_limit > 0 ) : 
					$remaining = max( 0, $daily_limit - $daily_total );
					$percentage = min( 100, ( $daily_total / $daily_limit ) * 100 );
					$bar_color = $percentage >= 100 ? '#dc3232' : ( $percentage >= 90 ? '#ffb900' : '#00a32a' );
				?>
					<p><strong>Remaining Today:</strong> <?php echo wc_price( $remaining ); ?></p>
					<div style="background: #ddd; border-radius: 3px; height: 20px; width: 100%; max-width: 300px;">
						<div style="background: <?php echo $bar_color; ?>; height: 100%; width: <?php echo $percentage; ?>%; border-radius: 3px; transition: width 0.3s;"></div>
					</div>
					<p style="color: #666; font-size: 12px;"><?php echo round( $percentage, 1 ); ?>% of daily limit used</p>
					
					<?php if ( $percentage >= 100 ) : ?>
						<p style="color: #dc3232;"><strong>⚠ Gateway is currently DISABLED (daily limit reached)</strong></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
			<?php
		}


		
		/**
		 * Check if the gateway is available for use.
		 *
		 * @return bool
		 */
		public function is_available() {
			// Check the CC-specific enabled flag (not the aggregate $this->enabled).
			if ( $this->cc_enabled !== 'yes' ) {
				return false;
			}

			// Fetch remote limits (cached via transients in get_remote_limits).
			$remote_limits         = $this->get_remote_limits();
			$this->max_ticket_size = $remote_limits['max_ticket_size'];
			$this->daily_limit     = $remote_limits['daily_limit'];

			// Check max ticket size limit
			if ( $this->max_ticket_size && floatval( $this->max_ticket_size ) > 0 ) {
				$cart_total = $this->get_current_order_total();
				if ( $cart_total > floatval( $this->max_ticket_size ) ) {
					return false;
				}
			}

			// Check daily transaction limit
			if ( $this->daily_limit && floatval( $this->daily_limit ) > 0 ) {
				$daily_total = $this->get_daily_transaction_total();
				if ( $daily_total >= floatval( $this->daily_limit ) ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Get current cart or order total
		 *
		 * @return float
		 */
		private function get_current_order_total() {
			// If we're on checkout and have a cart
			if ( WC()->cart ) {
				return floatval( WC()->cart->get_total( 'edit' ) );
			}
			return 0;
		}

		/**
		 * Get the total transaction amount for today
		 *
		 * @return float
		 */
		public function get_daily_transaction_total() {
			$today = wcpg_get_pacific_date( 'Y-m-d' );
			$transient_key = 'wcpg_daily_total_' . $today;
			
			$daily_total = get_transient( $transient_key );
			
			if ( $daily_total === false ) {
				// Calculate from database if transient expired or doesn't exist
				$daily_total = $this->calculate_daily_total_from_orders();
				// Cache for 5 minutes (frequent recalculation to handle timezone edge cases)
				set_transient( $transient_key, $daily_total, 5 * MINUTE_IN_SECONDS );
			}
			
			return floatval( $daily_total );
		}

		/**
		 * Calculate daily total from completed orders (Pacific Time)
		 *
		 * @return float
		 */
		private function calculate_daily_total_from_orders() {
			// Get today's date range in Pacific time, converted to UTC for database query.
			$pacific_tz = new DateTimeZone( 'America/Los_Angeles' );
			$utc_tz     = new DateTimeZone( 'UTC' );

			$today_start = new DateTime( 'today midnight', $pacific_tz );
			$today_start->setTimezone( $utc_tz );

			$today_end = new DateTime( 'today 23:59:59', $pacific_tz );
			$today_end->setTimezone( $utc_tz );

			// Single query to sum order totals, avoiding N+1 wc_get_order calls.
			global $wpdb;

			if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
				// HPOS: query wc_orders table directly.
				$total = $wpdb->get_var( $wpdb->prepare(
					"SELECT COALESCE(SUM(total_amount), 0)
					 FROM {$wpdb->prefix}wc_orders
					 WHERE payment_method = %s
					   AND status IN ('wc-processing', 'wc-completed')
					   AND date_created_gmt BETWEEN %s AND %s",
					DIGIPAY_GATEWAY_ID,
					$today_start->format( 'Y-m-d H:i:s' ),
					$today_end->format( 'Y-m-d H:i:s' )
				) );
			} else {
				// Legacy: query posts + postmeta.
				$total = $wpdb->get_var( $wpdb->prepare(
					"SELECT COALESCE(SUM(pm.meta_value), 0)
					 FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
					 INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_payment_method'
					 WHERE p.post_type = 'shop_order'
					   AND p.post_status IN ('wc-processing', 'wc-completed')
					   AND pm2.meta_value = %s
					   AND p.post_date_gmt BETWEEN %s AND %s",
					DIGIPAY_GATEWAY_ID,
					$today_start->format( 'Y-m-d H:i:s' ),
					$today_end->format( 'Y-m-d H:i:s' )
				) );
			}

			return floatval( $total );
		}

		/**
		 * Update the daily transaction total (call after successful payment)
		 *
		 * @param float $amount Amount to add to daily total
		 */
		public function update_daily_transaction_total( $amount ) {
			$today = wcpg_get_pacific_date( 'Y-m-d' );
			$transient_key = 'wcpg_daily_total_' . $today;
			
			$current_total = $this->get_daily_transaction_total();
			$new_total = $current_total + floatval( $amount );
			
			set_transient( $transient_key, $new_total, 5 * MINUTE_IN_SECONDS );
		}

		/**
		 * Get remaining daily limit
		 *
		 * @return float|null Returns remaining amount or null if no limit set
		 */
		public function get_remaining_daily_limit() {
			if ( ! $this->daily_limit || floatval( $this->daily_limit ) <= 0 ) {
				return null;
			}
			
			$daily_total = $this->get_daily_transaction_total();
			$remaining = floatval( $this->daily_limit ) - $daily_total;
			
			return max( 0, $remaining );
		}

		/**
		 * Fetch transaction limits from central dashboard (Supabase Edge Function)
		 * Caches the result for 5 minutes to avoid excessive API calls
		 *
		 * @return array Array with 'daily_limit' and 'max_ticket_size'
		 */
		public function get_remote_limits() {
			$site_id = $this->get_option( 'siteid' );

			$default_limits = array(
				'daily_limit'     => 0,
				'max_ticket_size' => 0,
				'last_updated'    => null,
				'status'          => 'unknown',
			);

			if ( empty( $site_id ) ) {
				return $default_limits;
			}

			$site_hash     = md5( $site_id );
			$transient_key = 'wcpg_remote_limits_' . $site_hash;
			$cached_limits = get_transient( $transient_key );

			if ( $cached_limits !== false ) {
				return $cached_limits;
			}

			$response = wp_remote_get(
				add_query_arg( array( 'site_id' => $site_id ), $this->limits_api_url ),
				array(
					'timeout'   => 15,
					'sslverify' => true,
					'headers'   => array( 'Accept' => 'application/json' ),
				)
			);

			$response_code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
			$data          = null;

			if ( $response_code === 200 ) {
				$data = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( json_last_error() !== JSON_ERROR_NONE || empty( $data['success'] ) ) {
					$data = null;
				}
			}

			if ( $data === null ) {
				$fallback = get_option( 'wcpg_last_known_limits_' . $site_hash, $default_limits );
				set_transient( $transient_key, $fallback, MINUTE_IN_SECONDS );
				return $fallback;
			}

			$limits = array(
				'daily_limit'     => floatval( $data['daily_limit'] ?? 0 ),
				'max_ticket_size' => floatval( $data['max_ticket_size'] ?? 0 ),
				'last_updated'    => current_time( 'mysql' ),
				'status'          => $data['status'] ?? 'active',
			);

			set_transient( $transient_key, $limits, 5 * MINUTE_IN_SECONDS );
			update_option( 'wcpg_last_known_limits_' . $site_hash, $limits, false );

			return $limits;
		}

		/**
		 * Force refresh of remote limits (clears cache)
		 */
		public function refresh_remote_limits() {
			$site_id = $this->get_option( 'siteid' );
			if ( ! empty( $site_id ) ) {
				delete_transient( 'wcpg_remote_limits_' . md5( $site_id ) );
			}
			return $this->get_remote_limits();
		}

		/**
		 * Add content to the WC emails.
		 *
		 * @access public
		 * @param WC_Order $order
		 * @param bool $sent_to_admin
		 * @param bool $plain_text
		 */
		public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		
			if ( $this->description && ! $sent_to_admin && $this->id === $order->payment_method && $order->has_status( 'pending' ) ) {
				echo wpautop( wptexturize( $this->description ) ) . PHP_EOL;
			}
		}


	
		/**
		 * Process the payment and return the result
		 *
		 * @param int $order_id
		 * @return array
		 */
		public function process_payment( $order_id ) {
			$encryption_key = wcpg_get_encryption_key();

			$current_user    = wp_get_current_user();
			$customer_orders = wc_get_orders( array(
				'customer_id' => $current_user->ID,
				'post_status' => array( 'processing', 'completed' ),
				'return'      => 'ids',
				'limit'       => -1,
			) );
			$count_orders = count( $customer_orders );

			$order      = wc_get_order( $order_id );
			$order_data = $order->get_data();
			$order_key  = $order->get_order_key();

			if ( $this->encrypt_description === 'yes' ) {
				$paygoitems = $order_id;
			} else {
				$product_names = array();
				foreach ( $order->get_items() as $item ) {
					$product_names[] = $item['name'];
				}
				$paygoitems = implode( '&', $product_names );
			}

			$billing = $order_data['billing'];

			$name_email_param = '';
			if ( $billing['first_name'] !== '' ) { $name_email_param  = '&first_name=' . $billing['first_name']; }
			if ( $billing['last_name'] !== '' )  { $name_email_param .= '&last_name=' . $billing['last_name']; }
			if ( $billing['email'] !== '' )      { $name_email_param .= '&email=' . $billing['email']; }

			$address = $billing['address_1'];
			if ( $billing['address_2'] !== '' ) {
				$address .= ' ' . $billing['address_2'];
			}

			$url_main = $this->paygomainurl . 'order/creditcard/cc_form_enc.php';
			$zipcode  = preg_replace( '/\s+/', '', $billing['postcode'] );

			$pburl      = '&pburl=' . get_option( 'siteurl' ) . '/wp-content/plugins/secure_plugin/paygo_postback.php';
				$return_url = ( $this->tocomplete !== '' ) ? $this->tocomplete : $this->get_return_url( $order );
			$tocomplete = '&tcomplete=' . urlencode( $return_url );

			$billing_param = '&address=' . urlencode( $address )
				. '&city=' . urlencode( $billing['city'] )
				. '&state=' . urlencode( $billing['state'] )
				. '&zip=' . urlencode( $zipcode )
				. '&country=' . urlencode( $billing['country'] );

			$paygoitems_param = $url_main . '?site_id=' . urlencode( $this->siteid )
				. '&trans=' . $count_orders
				. '&charge_amount=' . urlencode( $order->total )
				. '&type=purchase'
				. '&order_description=' . urlencode( $paygoitems )
				. '&order_key=' . $order_key
				. '&woocomerce=1&encrypt=1'
				. '&session=' . urlencode( $order_id )
				. $billing_param
				. $name_email_param
				. $pburl
				. $tocomplete;

			$paygourl = wcpg_build_payment_url( $url_main, wcpg_encrypt( $paygoitems_param, $encryption_key ) );

			$order->update_status( 'pending', __( 'Pending', 'wc-payment-gateway' ) );
			$order->reduce_order_stock();
			WC()->cart->empty_cart();

			return array(
				'result'   => 'success',
				'redirect' => $paygourl,
			);
		}

		/**
		 * Render advanced settings form on the Admin tab with its own Save button.
		 *
		 * This renders the advanced settings fields that were moved from the Credit Card tab.
		 * Uses the same underlying options for backward compatibility.
		 */
		public function render_advanced_settings() {
			// Define advanced settings fields (matches the original form_fields definitions).
			$advanced_fields = array(
				'encrypt_description' => array(
					'title'       => __( 'Encrypt Order Data', 'wc-payment-gateway' ),
					'type'        => 'checkbox',
					'description' => __( 'Encrypt order description sent to payment gateway', 'wc-payment-gateway' ),
					'default'     => 'yes',
					'readonly'    => true,
				),
				'tocomplete' => array(
					'title'       => __( 'Custom Return URL', 'wc-payment-gateway' ),
					'type'        => 'text',
					'description' => __( 'Leave blank to use the default WooCommerce order received page.', 'wc-payment-gateway' ),
					'default'     => '',
					'placeholder' => wc_get_endpoint_url( 'order-received', '', wc_get_checkout_url() ),
				),
				'payment_gateway_url' => array(
					'title'       => __( 'Payment Gateway URL', 'wc-payment-gateway' ),
					'type'        => 'text',
					'description' => __( 'Base URL for the credit card gateway.', 'wc-payment-gateway' ),
					'default'     => 'https://secure.digipay.co/',
					'placeholder' => 'https://secure.digipay.co/',
					'readonly'    => true,
					'show'        => true,
				),
				'limits_api_url' => array(
					'title'       => __( 'Transaction Limits API', 'wc-payment-gateway' ),
					'type'        => 'text',
					'description' => __( 'API endpoint for fetching transaction limits.', 'wc-payment-gateway' ),
					'default'     => 'https://hzdybwclwqkcobpwxzoo.supabase.co/functions/v1/plugin-site-limits',
					'readonly'    => true,
				),
				'health_report_url' => array(
					'title'       => __( 'Health Report API', 'wc-payment-gateway' ),
					'type'        => 'text',
					'description' => __( 'API endpoint for sending health reports.', 'wc-payment-gateway' ),
					'default'     => 'https://hzdybwclwqkcobpwxzoo.supabase.co/functions/v1/plugin-site-health-report',
					'readonly'    => true,
				),
				'inbound_test_url' => array(
					'title'       => __( 'Inbound Test API', 'wc-payment-gateway' ),
					'type'        => 'text',
					'description' => __( 'API endpoint for testing inbound connectivity.', 'wc-payment-gateway' ),
					'default'     => 'https://hzdybwclwqkcobpwxzoo.supabase.co/functions/v1/test-inbound-connectivity',
					'readonly'    => true,
				),
				'github_username' => array(
					'title'       => __( 'GitHub Username', 'wc-payment-gateway' ),
					'type'        => 'text',
					'description' => __( 'GitHub username or organization for plugin updates.', 'wc-payment-gateway' ),
					'default'     => '9hnydkjf26-max',
					'placeholder' => 'username',
					'readonly'    => true,
				),
				'github_repo' => array(
					'title'       => __( 'GitHub Repository', 'wc-payment-gateway' ),
					'type'        => 'text',
					'description' => __( 'GitHub repository name for plugin updates.', 'wc-payment-gateway' ),
					'default'     => 'digipay-plugin',
					'placeholder' => 'repository-name',
					'readonly'    => true,
				),
			);

			// Handle form submission.
			if ( isset( $_POST['wcpg_save_advanced'] ) && isset( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'wcpg_save_advanced' ) ) {
				foreach ( $advanced_fields as $key => $field ) {
					if ( ! empty( $field['readonly'] ) ) {
						continue;
					}
					$option_key = $this->get_field_key( $key );
					if ( 'checkbox' === $field['type'] ) {
						// phpcs:ignore WordPress.Security.NonceVerification.Missing
						$value = isset( $_POST[ $option_key ] ) ? 'yes' : 'no';
					} else {
						// phpcs:ignore WordPress.Security.NonceVerification.Missing
						$value = isset( $_POST[ $option_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $option_key ] ) ) : '';
					}
					// Update in the gateway settings array.
					$this->settings[ $key ] = $value;
				}
				update_option( $this->get_option_key(), apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings ), 'yes' );
				echo '<div class="notice notice-success" style="margin: 10px 0;"><p>' . esc_html__( 'Advanced settings saved.', 'wc-payment-gateway' ) . '</p></div>';
			}

			// Render the form.
			?>
			<form method="post">
				<?php wp_nonce_field( 'wcpg_save_advanced' ); ?>
				<table class="form-table">
					<?php
					foreach ( $advanced_fields as $key => $field ) {
						if ( ! empty( $field['readonly'] ) && empty( $field['show'] ) ) {
							continue;
						}
						$option_key = $this->get_field_key( $key );
						$value      = $this->get_option( $key, $field['default'] ?? '' );
						$field_type = $field['type'] ?? 'text';
						?>
						<tr valign="top">
							<th scope="row" class="titledesc">
								<label for="<?php echo esc_attr( $option_key ); ?>"><?php echo esc_html( $field['title'] ); ?></label>
							</th>
							<td class="forminp">
								<?php if ( 'checkbox' === $field_type ) : ?>
									<fieldset>
										<label for="<?php echo esc_attr( $option_key ); ?>">
											<input type="checkbox"
												   name="<?php echo esc_attr( $option_key ); ?>"
												   id="<?php echo esc_attr( $option_key ); ?>"
												   value="yes"
												   <?php checked( $value, 'yes' ); ?>
												   <?php if ( ! empty( $field['readonly'] ) ) echo 'disabled="disabled"'; ?> />
											<?php echo esc_html( $field['description'] ); ?>
										</label>
									</fieldset>
								<?php else : ?>
									<input type="text"
										   name="<?php echo esc_attr( $option_key ); ?>"
										   id="<?php echo esc_attr( $option_key ); ?>"
										   value="<?php echo esc_attr( $value ); ?>"
										   placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>"
										   style="width: 400px;<?php if ( ! empty( $field['readonly'] ) ) echo ' background-color: #f0f0f0; color: #666;'; ?>"
										   <?php if ( ! empty( $field['readonly'] ) ) echo 'readonly="readonly"'; ?> />
									<?php if ( ! empty( $field['description'] ) ) : ?>
										<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
									<?php endif; ?>
								<?php endif; ?>
							</td>
						</tr>
						<?php
					}
					?>
				</table>
				<p class="submit">
					<button type="submit" name="wcpg_save_advanced" class="button-primary">
						<?php esc_html_e( 'Save Advanced Settings', 'wc-payment-gateway' ); ?>
					</button>
				</p>
			</form>
			<?php
		}

		/**
		 * Enqueue Fingerprint checkout tracking script.
		 */
		public function enqueue_fingerprint_checkout() {
			if ( ! is_checkout() ) {
				return;
			}

			// Skip if API key is placeholder
			if ( 'your_public_api_key_here' === WCPG_FINGERPRINT_PUBLIC_KEY || empty( WCPG_FINGERPRINT_PUBLIC_KEY ) ) {
				return;
			}

			wp_enqueue_script(
				'wcpg-fingerprint-checkout',
				plugin_dir_url( __FILE__ ) . 'assets/js/fingerprint-checkout.js',
				array( 'jquery' ),
				WCPG_VERSION,
				true
			);

			$cartTotal = 0;
			$cartItemCount = 0;
			if ( WC()->cart ) {
				$cartTotal = WC()->cart->get_total( 'edit' );
				$cartItemCount = WC()->cart->get_cart_contents_count();
			}
			wp_localize_script( 'wcpg-fingerprint-checkout', 'wcpgFPConfig', array(
				'key'           => WCPG_FINGERPRINT_PUBLIC_KEY,
				'region'        => WCPG_FINGERPRINT_REGION,
				'siteId'        => $this->get_option( 'siteid' ),
				'siteName'      => get_bloginfo( 'name' ),
				'cartTotal'     => $cartTotal,
				'cartItemCount' => $cartItemCount,
				'currency'      => get_woocommerce_currency(),
			) );
		}

  } // end WC_Gateway_Paygo class
}


/**
 * Update daily transaction total when Digipay order status changes to processing/completed
 */
add_action( 'woocommerce_order_status_changed', 'wcpg_update_daily_total_on_status_change', 10, 4 );
function wcpg_update_daily_total_on_status_change( $order_id, $old_status, $new_status, $order ) {
	// Only process for our gateway
	if ( $order->get_payment_method() !== DIGIPAY_GATEWAY_ID ) {
		return;
	}

	// Only count when moving TO processing or completed FROM a non-counted status
	$counted_statuses = array( 'processing', 'completed' );
	$was_counted = in_array( $old_status, $counted_statuses );
	$is_counted = in_array( $new_status, $counted_statuses );

	// If transitioning into a counted status from a non-counted status
	if ( $is_counted && ! $was_counted ) {
		$gateway = wcpg_get_gateway_instance();
		if ( $gateway ) {
			$gateway->update_daily_transaction_total( $order->get_total() );
		}
	}
}


/**
 * Add admin notice when daily limit is reached
 */
add_action( 'admin_notices', 'wcpg_daily_limit_admin_notice' );
function wcpg_daily_limit_admin_notice() {
	// Check user has permission to view this information.
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	// Only show on WooCommerce settings pages.
	$screen = get_current_screen();
	if ( ! $screen || strpos( $screen->id, 'woocommerce' ) === false ) {
		return;
	}

	$gateway = wcpg_get_gateway_instance();
	if ( ! $gateway ) {
		return;
	}
	$daily_limit = floatval( $gateway->daily_limit );
	
	if ( $daily_limit <= 0 ) {
		return;
	}

	$daily_total = $gateway->get_daily_transaction_total();
	$remaining = $gateway->get_remaining_daily_limit();

	if ( $daily_total >= $daily_limit ) {
		echo '<div class="notice notice-warning"><p><strong>Payment Gateway:</strong> Daily transaction limit of ' . wc_price( $daily_limit ) . ' has been reached. The gateway is currently disabled and will reset at midnight Pacific Time.</p></div>';
	} elseif ( $remaining !== null && $remaining < ( $daily_limit * 0.1 ) ) {
		// Warn when less than 10% remaining
		echo '<div class="notice notice-info"><p><strong>Payment Gateway:</strong> Daily limit is almost reached. ' . wc_price( $remaining ) . ' remaining of ' . wc_price( $daily_limit ) . ' daily limit.</p></div>';
	}
}

/**
* Handle filters for excluding woocommerce statuses from All orders view
*
* @param array $query_vars Query vars.
* @return array
*/
function wcpg_exclude_order_status( $query_vars ) {
	global $typenow;
	$_GET['exclude_status'] = 'wc-pending';

	if ( ! empty( $_GET['post_status'] ) ) {
		return $query_vars;
	}

	if ( ! in_array( $typenow, wc_get_order_types( 'order-meta-boxes' ), true ) ) {
		return $query_vars;
	}

	if ( ! isset( $_GET['exclude_status'] ) || '' === $_GET['exclude_status'] || ! isset( $query_vars['post_status'] ) ) {
		return $query_vars;
	}

	$exclude_status = explode( ',', $_GET['exclude_status'] );
	foreach ( $exclude_status as $value ) {
		$found_key = array_search( $value, $query_vars['post_status'] );
		if ( $found_key !== false ) {
			unset( $query_vars['post_status'][ $found_key ] );
		}
	}

	return $query_vars;
}
add_filter( 'request', 'wcpg_exclude_order_status', 20, 1 );

add_filter( 'woocommerce_can_reduce_order_stock', 'wcpg_do_not_reduce_onhold_stock', 10, 2 );
function wcpg_do_not_reduce_onhold_stock( $reduce_stock, $order ) {
    if ( $order->has_status( 'pending' ) && ( $order->get_payment_method() === 'bacs' || $order->get_payment_method() === DIGIPAY_GATEWAY_ID ) ) {
        $reduce_stock = false;
    }
    return $reduce_stock;
}

add_action( 'woocommerce_order_status_changed', 'wcpg_stock_reduction_on_status', 20, 4 );
function wcpg_stock_reduction_on_status( $order_id, $old_status, $new_status, $order ) {
	if ( ! in_array( $new_status, array( 'processing', 'completed' ), true ) ) {
		return;
	}

	$stock_reduced  = $order->get_meta( '_order_stock_reduced', true );
	$payment_method = $order->get_payment_method();

	if ( empty( $stock_reduced ) && ( $payment_method === 'bacs' || $payment_method === DIGIPAY_GATEWAY_ID ) ) {
		wc_reduce_stock_levels( $order_id );
	}
}


function wcpg_check_woocommerce_version() {
	if ( ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, '8.3', '<' ) ) {
		return;
	}

	function wcpg_declare_blocks_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
	add_action( 'before_woocommerce_init', 'wcpg_declare_blocks_compatibility' );

	add_action( 'woocommerce_blocks_loaded', 'wcpg_register_blocks_payment' );

	function wcpg_register_blocks_payment() {
		if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}

		require_once plugin_dir_path( __FILE__ ) . 'class-block.php';
		require_once plugin_dir_path( __FILE__ ) . 'etransfer/class-etransfer-block.php';
		require_once plugin_dir_path( __FILE__ ) . 'etransfer/class-etransfer-blocks-factory.php';
		require_once plugin_dir_path( __FILE__ ) . 'crypto/class-crypto-block.php';

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
				$payment_method_registry->register( new WCPG_Gateway_Blocks );
				WCPG_ETransfer_Blocks_Factory::register_blocks( $payment_method_registry );
				$payment_method_registry->register( new WCPG_Crypto_Gateway_Block() );
			}
		);
	}
}
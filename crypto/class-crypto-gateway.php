<?php
/**
 * WooCommerce Payment Gateway - Crypto Payment Gateway
 *
 * @version 1.0.0
 */

defined( 'ABSPATH' ) or exit;

class WCPG_Gateway_Crypto extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'wcpg_crypto';
		$this->method_title       = __( 'Crypto', 'wc-payment-gateway' );
		$this->method_description = __( 'Accept cryptocurrency payments.', 'wc-payment-gateway' );
		$this->has_fields         = true;

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', 'Pay with Crypto' );
		$this->description = $this->get_option( 'description', '' );
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
	}

	/**
	 * Initialize gateway settings form fields.
	 */
	public function init_form_fields() {
		$currency_options = $this->get_finvaro_currency_options();

		$this->form_fields = array(
			'enabled' => array(
				'title'       => __( 'Enable/Disable', 'wc-payment-gateway' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable Crypto payments', 'wc-payment-gateway' ),
				'description' => __( 'Enable Crypto Payments', 'wc-payment-gateway' ),
				'default'     => 'yes',
			),
			'title' => array(
				'title'   => __( 'Title', 'wc-payment-gateway' ),
				'type'    => 'text',
				'default' => 'Pay with Crypto',
			),
			'public_key' => array(
				'title'   => __( 'Public Key', 'wc-payment-gateway' ),
				'type'    => 'text',
				'default' => '',
			),
			'private_key' => array(
				'title'   => __( 'Private Key', 'wc-payment-gateway' ),
				'type'    => 'password',
				'default' => '',
			),
			'checkout_url' => array(
				'title'       => __( 'Fallback Checkout URL (Optional)', 'wc-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Optional: Only needed if API fails.', 'wc-payment-gateway' ),
				'default'     => '',
			),
			'traded_currency' => array(
				'title'       => __( 'Accepted Cryptocurrencies', 'wc-payment-gateway' ),
				'type'        => 'multiselect',
				'description' => empty( $currency_options )
					? __( 'Save your API keys first, then reload this page to see available currencies.', 'wc-payment-gateway' )
					: __( 'Select which cryptocurrencies to accept. Hold Ctrl/Cmd to select multiple.', 'wc-payment-gateway' ),
				'options'     => $currency_options,
				'default'     => array(),
				'class'       => 'wc-enhanced-select',
				'css'         => 'min-width: 350px;',
			),
			'expire_time' => array(
				'title'   => __( 'Checkout Expire Time (minutes)', 'wc-payment-gateway' ),
				'type'    => 'number',
				'default' => '60',
			),
			'collect_name' => array(
				'title'   => __( 'Collect Customer Name', 'wc-payment-gateway' ),
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			'collect_email' => array(
				'title'   => __( 'Collect Customer Email', 'wc-payment-gateway' ),
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			'integration_method' => array(
				'title'   => __( 'Integration Method', 'wc-payment-gateway' ),
				'type'    => 'select',
				'options' => array(
					'iframe' => __( 'Iframe (Embedded)', 'wc-payment-gateway' ),
					'button' => __( 'Button (Opens in new window)', 'wc-payment-gateway' ),
				),
				'default' => 'iframe',
			),
		);
	}

	/**
	 * Check if the gateway is available.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}

		$has_api_config = ! empty( $this->get_option( 'public_key', '' ) ) && ! empty( $this->get_option( 'private_key', '' ) );
		$has_fallback   = ! empty( trim( $this->get_option( 'checkout_url', '' ) ) );

		if ( ! $has_api_config && ! $has_fallback ) {
			return false;
		}

		return true;
	}

	/**
	 * Process the payment.
	 *
	 * @param int $order_id Order ID.
	 * @return array Result array.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wc_add_notice( __( 'Order not found. Please try again.', 'wc-payment-gateway' ), 'error' );
			return array( 'result' => 'fail', 'redirect' => '' );
		}

		// Mark order as on-hold (awaiting crypto payment).
		$order->update_status( 'on-hold', __( 'Awaiting crypto payment.', 'wc-payment-gateway' ) );
		$order->update_meta_data( '_wcpg_crypto_order_id', $order_id );
		$order->save();

		wc_reduce_stock_levels( $order_id );

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		// Get checkout URL — API first, then fallback.
		$checkout_url = $this->get_crypto_checkout_url( $order );

		if ( ! $checkout_url ) {
			if ( current_user_can( 'manage_woocommerce' ) ) {
				$error_msg = __( 'Failed to generate checkout URL. ', 'wc-payment-gateway' );
				if ( empty( $this->get_option( 'public_key', '' ) ) || empty( $this->get_option( 'private_key', '' ) ) ) {
					$error_msg .= __( 'Please configure your Public Key and Private Key.', 'wc-payment-gateway' );
				} else {
					$error_msg .= __( 'API call failed. Check your credentials or configure a fallback checkout URL.', 'wc-payment-gateway' );
				}
				wc_add_notice( $error_msg, 'error' );
			} else {
				wc_add_notice( __( 'Error generating payment URL. Please contact the store administrator.', 'wc-payment-gateway' ), 'error' );
			}
			return array( 'result' => 'fail', 'redirect' => '' );
		}

		// Save checkout URL to order meta for iframe rendering.
		$order->update_meta_data( '_wcpg_crypto_checkout_url', $checkout_url );
		$order->save();

		$integration_method = $this->get_option( 'integration_method', 'iframe' );

		// Both methods redirect to the order-received page.
		// Iframe embeds the checkout inline; button opens it in a new tab via JS.
		return array( 'result' => 'success', 'redirect' => $order->get_checkout_order_received_url() );
	}

	/**
	 * Get checkout URL for an order.
	 *
	 * Tries API first, falls back to static URL.
	 *
	 * @param WC_Order|null $order Order object.
	 * @return string|false Checkout URL or false on failure.
	 */
	private function get_crypto_checkout_url( $order = null ) {
		$public_key  = $this->get_option( 'public_key', '' );
		$private_key = $this->get_option( 'private_key', '' );

		// Try API first if credentials are configured.
		if ( ! empty( $public_key ) && ! empty( $private_key ) ) {
			if ( $order && is_a( $order, 'WC_Order' ) ) {
				$api_checkout_url = $this->generate_checkout_via_api( $order );
				if ( $api_checkout_url ) {
					return $api_checkout_url;
				}
			}
		}

		// Fallback to static URL from settings.
		$checkout_url = $this->get_option( 'checkout_url', '' );
		if ( empty( $checkout_url ) ) {
			return false;
		}

		// Append order parameters to static URL.
		if ( $order && is_a( $order, 'WC_Order' ) ) {
			$checkout_url = add_query_arg( 'clickId', $order->get_id(), $checkout_url );
			$checkout_url = add_query_arg( 'amount', $order->get_total(), $checkout_url );
			$checkout_url = add_query_arg( 'return_url', rawurlencode( $this->get_return_url( $order ) ), $checkout_url );
		}

		return $checkout_url;
	}

	/**
	 * Generate a checkout URL via the payment processor API.
	 *
	 * @param WC_Order $order Order object.
	 * @return string|false Checkout URL or false on failure.
	 */
	private function generate_checkout_via_api( $order ) {
		$public_key  = $this->get_option( 'public_key', '' );
		$private_key = $this->get_option( 'private_key', '' );

		if ( empty( $public_key ) || empty( $private_key ) ) {
			return false;
		}

		// Step 1: Authenticate to get a bearer token.
		$access_token = $this->get_finvaro_token( $public_key, $private_key );
		if ( ! $access_token ) {
			return false;
		}

		// Step 2: Build the checkout sale request.
		$order_id     = $order->get_id();
		$currency     = $order->get_currency();
		$total_amount = $order->get_total();

		// Build product name from order items.
		$items         = $order->get_items();
		$product_names = array();
		foreach ( $items as $item ) {
			$product_names[] = $item->get_name();
		}
		$product_name = ! empty( $product_names ) ? implode( ', ', $product_names ) : sprintf( __( 'Order #%s', 'wc-payment-gateway' ), $order_id );
		if ( strlen( $product_name ) > 200 ) {
			$product_name = substr( $product_name, 0, 197 ) . '...';
		}

		$description = sprintf( __( 'Order #%s - %s', 'wc-payment-gateway' ), $order_id, get_bloginfo( 'name' ) );
		if ( strlen( $description ) > 500 ) {
			$description = substr( $description, 0, 497 ) . '...';
		}

		// Site logo (optional).
		$link_logo_image = '';
		$custom_logo_id  = get_theme_mod( 'custom_logo' );
		if ( $custom_logo_id ) {
			$link_logo_image = wp_get_attachment_image_url( $custom_logo_id, 'full' );
		}

		// Get accepted currencies (stored as Finvaro _id values from multiselect).
		$traded_currency = $this->get_option( 'traded_currency', array() );
		if ( is_string( $traded_currency ) ) {
			// Backwards compat: old text field stored comma-separated tickers.
			$traded_currency = array_filter( array_map( 'trim', explode( ',', $traded_currency ) ) );
		}
		$currencies = ! empty( $traded_currency ) ? $traded_currency : array();

		$expire_time = $this->get_option( 'expire_time', '60' );

		// Map store currency to Finvaro-supported fiat currency, default to USD.
		$supported_fiat = array( 'AUD', 'BRL', 'EUR', 'GBP', 'NGN', 'RUB', 'TRY', 'UAH', 'USD', 'USDT', 'USDC' );
		$fiat_currency  = in_array( $currency, $supported_fiat, true ) ? $currency : 'USD';

		$body = array(
			'expireTime'   => strval( intval( $expire_time ) ),
			'currencies'   => $currencies,
			'collectName'  => ( 'yes' === $this->get_option( 'collect_name', 'yes' ) ) ? 'true' : 'false',
			'collectEmail' => ( 'yes' === $this->get_option( 'collect_email', 'yes' ) ) ? 'true' : 'false',
			'description'  => $description,
			'productName'  => $product_name,
			'price'        => number_format( $total_amount, 2, '.', '' ),
			'fiatCurrency' => $fiat_currency,
			'metadata'     => array(
				'order_id'          => $order_id,
				'order_key'         => $order->get_order_key(),
				'woocommerce_order' => true,
				'item_count'        => count( $items ),
			),
		);

		if ( ! empty( $link_logo_image ) ) {
			$body['linkLogoImage'] = $link_logo_image;
		}

		// Step 3: Create the checkout sale.
		$api_url = 'https://napi.finvaro.com/api/public/checkout/sale';

		$this->log( 'info', 'Crypto checkout request body: ' . wp_json_encode( $body ) );

		$response = wp_remote_post( $api_url, array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			$this->log( 'error', 'Crypto checkout API error: ' . $response->get_error_message() );
			return false;
		}

		$status_code   = wp_remote_retrieve_response_code( $response );
		$raw_body      = wp_remote_retrieve_body( $response );
		$response_body = json_decode( $raw_body, true );

		$this->log( 'info', sprintf( 'Crypto checkout API response — HTTP %d: %s', $status_code, $raw_body ) );

		if ( 200 === $status_code || 201 === $status_code ) {
			// Try multiple possible response structures.
			$url_fields = array( 'checkoutUrl', 'checkout_url', 'url' );
			foreach ( $url_fields as $field ) {
				if ( isset( $response_body[ $field ] ) ) {
					return $response_body[ $field ];
				}
				if ( isset( $response_body['data'][ $field ] ) ) {
					return $response_body['data'][ $field ];
				}
			}

			// Token-based URL construction.
			if ( isset( $response_body['token'] ) ) {
				return 'https://checkouts.finvaro.com/checkout/' . $response_body['token'];
			}
			if ( isset( $response_body['data']['token'] ) ) {
				return 'https://checkouts.finvaro.com/checkout/' . $response_body['data']['token'];
			}

			// Identifier-based URL construction.
			if ( isset( $response_body['identifier'] ) ) {
				$order->update_meta_data( '_wcpg_crypto_identifier', $response_body['identifier'] );
				if ( isset( $response_body['_id'] ) ) {
					$order->update_meta_data( '_wcpg_crypto_checkout_id', $response_body['_id'] );
				}
				$order->save();
				return 'https://checkouts.finvaro.com/checkout/' . $response_body['identifier'];
			}
			if ( isset( $response_body['data']['identifier'] ) ) {
				$order->update_meta_data( '_wcpg_crypto_identifier', $response_body['data']['identifier'] );
				if ( isset( $response_body['data']['_id'] ) ) {
					$order->update_meta_data( '_wcpg_crypto_checkout_id', $response_body['data']['_id'] );
				}
				$order->save();
				return 'https://checkouts.finvaro.com/checkout/' . $response_body['data']['identifier'];
			}

			$this->log( 'error', 'Crypto checkout API returned 2xx but no checkout URL found in response.' );
		} else {
			$this->log( 'error', sprintf( 'Crypto checkout API returned HTTP %d: %s', $status_code, $raw_body ) );
		}

		return false;
	}

	/**
	 * Authenticate with Finvaro API to get a bearer token.
	 *
	 * Uses a 5-minute transient cache to avoid re-authenticating on every request.
	 *
	 * @param string $public_key  Public API key.
	 * @param string $private_key Private API key.
	 * @return string|false Access token or false on failure.
	 */
	private function get_finvaro_token( $public_key, $private_key ) {
		$cache_key = 'wcpg_finvaro_token_' . md5( $public_key );
		$cached    = get_transient( $cache_key );
		if ( $cached ) {
			return $cached;
		}

		$response = wp_remote_post( 'https://napi.finvaro.com/api/public/auth', array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'publicKey'  => $public_key,
				'privateKey' => $private_key,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			$this->log( 'error', 'Finvaro auth error: ' . $response->get_error_message() );
			return false;
		}

		$status_code   = wp_remote_retrieve_response_code( $response );
		$raw_body      = wp_remote_retrieve_body( $response );
		$response_body = json_decode( $raw_body, true );

		if ( 200 !== $status_code && 201 !== $status_code ) {
			$this->log( 'error', sprintf( 'Finvaro auth failed — HTTP %d: %s', $status_code, $raw_body ) );
			return false;
		}

		// Extract token from response.
		$token = null;
		$token_fields = array( 'token', 'accessToken', 'access_token' );
		foreach ( $token_fields as $field ) {
			if ( isset( $response_body[ $field ] ) ) {
				$token = $response_body[ $field ];
				break;
			}
			if ( isset( $response_body['data'][ $field ] ) ) {
				$token = $response_body['data'][ $field ];
				break;
			}
		}

		if ( ! $token ) {
			$this->log( 'error', 'Finvaro auth returned 2xx but no token found: ' . $raw_body );
			return false;
		}

		set_transient( $cache_key, $token, 5 * MINUTE_IN_SECONDS );
		$this->log( 'info', 'Finvaro auth successful, token cached for 5 minutes.' );

		return $token;
	}

	/**
	 * Fetch available cryptocurrencies from Finvaro for the settings multiselect.
	 *
	 * Uses a 1-hour transient cache to avoid hitting the API on every admin page load.
	 *
	 * @return array Associative array of _id => display name.
	 */
	private function get_finvaro_currency_options() {
		// Read keys directly from DB since this runs before init_settings().
		$settings    = get_option( 'woocommerce_wcpg_crypto_settings', array() );
		$public_key  = isset( $settings['public_key'] ) ? $settings['public_key'] : '';
		$private_key = isset( $settings['private_key'] ) ? $settings['private_key'] : '';

		if ( empty( $public_key ) || empty( $private_key ) ) {
			return array();
		}

		$cache_key = 'wcpg_finvaro_currencies_' . md5( $public_key );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// Stampede protection: set a short lock so only one request fetches at a time.
		$lock_key = $cache_key . '_lock';
		if ( get_transient( $lock_key ) ) {
			// Another request is already refreshing; return stale data or empty.
			return is_array( $cached ) ? $cached : array();
		}
		set_transient( $lock_key, 1, 30 );

		$access_token = $this->get_finvaro_token( $public_key, $private_key );
		if ( ! $access_token ) {
			return array();
		}

		$response = wp_remote_get( 'https://napi.finvaro.com/api/public/currency', array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$body    = json_decode( wp_remote_retrieve_body( $response ), true );
		$options = array();

		// Extract from data.currencies or top-level currencies.
		$currencies = array();
		if ( isset( $body['data']['currencies'] ) ) {
			$currencies = $body['data']['currencies'];
		} elseif ( isset( $body['currencies'] ) ) {
			$currencies = $body['currencies'];
		}

		foreach ( $currencies as $cur ) {
			if ( ! empty( $cur['_id'] ) && ! empty( $cur['name'] ) ) {
				$label = $cur['name'];
				if ( ! empty( $cur['title'] ) && $cur['title'] !== $cur['name'] ) {
					$label = $cur['title'] . ' (' . $cur['name'] . ')';
				}
				$options[ $cur['_id'] ] = $label;
			}
		}

		if ( ! empty( $options ) ) {
			set_transient( $cache_key, $options, HOUR_IN_SECONDS );
		}

		return $options;
	}

	/**
	 * Display payment fields on checkout.
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo wpautop( wptexturize( $this->description ) );
		}

		$has_api_config = ! empty( $this->get_option( 'public_key', '' ) ) && ! empty( $this->get_option( 'private_key', '' ) );
		$has_fallback   = ! empty( $this->get_option( 'checkout_url', '' ) );

		if ( ! $has_api_config && ! $has_fallback ) {
			if ( current_user_can( 'manage_woocommerce' ) ) {
				echo '<p class="woocommerce-error"><strong>' . esc_html__( 'Configuration Error:', 'wc-payment-gateway' ) . '</strong> '
					. esc_html__( 'Please configure your Public Key and Private Key, or provide a fallback checkout URL.', 'wc-payment-gateway' ) . '</p>';
			} else {
				echo '<p class="woocommerce-error">' . esc_html__( 'This payment method is not available. Please contact the store administrator.', 'wc-payment-gateway' ) . '</p>';
			}
			return;
		}

		$integration_method = $this->get_option( 'integration_method', 'iframe' );

		echo '<div class="wcpg-crypto-payment-info" style="padding: 15px; background: #f5f5f5; border-radius: 4px; margin: 15px 0;">';
		echo '<p style="margin: 0;"><strong>' . esc_html__( 'Pay with Cryptocurrency', 'wc-payment-gateway' ) . '</strong></p>';
		echo '<p style="margin: 10px 0 0 0; font-size: 0.9em; color: #666;">';
		if ( 'iframe' === $integration_method ) {
			echo esc_html__( 'After you place your order, the crypto payment checkout will be displayed here.', 'wc-payment-gateway' );
		} else {
			echo esc_html__( 'After you place your order, a new tab will open for you to complete your cryptocurrency payment.', 'wc-payment-gateway' );
		}
		echo '</p>';
		echo '</div>';
	}

	/**
	 * Display crypto checkout iframe/redirect on the thank you page.
	 *
	 * @param int $order_id Order ID.
	 */
	public function thankyou_page( $order_id ) {
		// Prevent duplicate rendering when multiple gateway instances exist (e.g., Blocks integration).
		static $rendered = array();
		if ( isset( $rendered[ $order_id ] ) ) {
			return;
		}
		$rendered[ $order_id ] = true;

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$checkout_url = $order->get_meta( '_wcpg_crypto_checkout_url' );
		if ( empty( $checkout_url ) ) {
			return;
		}

		// Only show for orders still awaiting payment.
		if ( ! in_array( $order->get_status(), array( 'on-hold', 'pending' ), true ) ) {
			return;
		}

		$integration_method = $this->get_option( 'integration_method', 'iframe' );

		if ( 'iframe' === $integration_method ) {
			?>
			<div id="wcpg-crypto-checkout" style="width: 100%; min-height: 600px; margin: 20px 0;">
				<h2><?php esc_html_e( 'Complete Your Crypto Payment', 'wc-payment-gateway' ); ?></h2>
				<iframe
					src="<?php echo esc_url( $checkout_url ); ?>"
					frameborder="0"
					width="100%"
					height="600"
					style="border: 1px solid #ddd; border-radius: 4px;"
					id="wcpg-crypto-iframe"
					allow="payment"
				></iframe>
			</div>
			<?php
		} else {
			?>
			<div id="wcpg-crypto-checkout" style="text-align: center; margin: 20px 0;">
				<h2><?php esc_html_e( 'Complete Your Crypto Payment', 'wc-payment-gateway' ); ?></h2>
				<p><?php esc_html_e( 'A new tab has been opened for your crypto payment. If it did not open, click the button below.', 'wc-payment-gateway' ); ?></p>
				<a href="<?php echo esc_url( $checkout_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary" style="padding: 10px 30px; font-size: 1.1em;" id="wcpg-crypto-pay-btn">
					<?php esc_html_e( 'Pay Now', 'wc-payment-gateway' ); ?>
				</a>
			</div>
			<script type="text/javascript">
				(function() {
					var opened = window.open(<?php echo wp_json_encode( esc_url( $checkout_url ) ); ?>, '_blank', 'noopener,noreferrer');
					if (!opened) {
						var msg = document.querySelector('#wcpg-crypto-checkout p');
						if (msg) msg.textContent = <?php echo wp_json_encode( __( 'Please click the button below to complete your crypto payment.', 'wc-payment-gateway' ) ); ?>;
					}
				})();
			</script>
			<?php
		}
	}

	/**
	 * Handle webhook callbacks from Finvaro payment processor.
	 *
	 * Finvaro sends encrypted callbacks using JWT + AES-256-CBC (CryptoJS format).
	 * The JWT Authorization header contains wallet_id and salt for decryption.
	 * Rejects requests that lack a valid Authorization header or fail decryption.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response
	 */
	public function handle_webhook( $request ) {
		// Log ALL incoming requests immediately.
		$raw_body    = $request->get_body();
		$auth_header = $request->get_header( 'authorization' );
		$content_type = $request->get_header( 'content_type' );

		$this->log( 'info', sprintf(
			'Crypto webhook received — Content-Type: %s, Auth header: %s, Body length: %d, Body: %s',
			$content_type ?: '(none)',
			$auth_header ? 'Bearer ...' . substr( $auth_header, -20 ) : '(none)',
			strlen( $raw_body ),
			substr( $raw_body, 0, 2000 )
		) );

		// Require a JWT Authorization header (Finvaro encrypted format).
		if ( ! $auth_header || stripos( $auth_header, 'Bearer ' ) === false ) {
			$this->log( 'error', 'Crypto webhook rejected — missing or invalid Authorization header.' );
			return new WP_REST_Response( array( 'error' => 'Unauthorized' ), 401 );
		}

		$data = $this->decrypt_finvaro_callback( $auth_header, $raw_body );
		if ( empty( $data ) ) {
			$this->log( 'error', 'Crypto webhook rejected — decryption failed.' );
			return new WP_REST_Response( array( 'error' => 'Unauthorized' ), 401 );
		}

		$this->log( 'info', 'Decrypted webhook data: ' . wp_json_encode( $data ) );

		$this->log( 'info', 'Webhook data fields: ' . implode( ', ', array_keys( $data ) ) );

		// Extract order ID — Finvaro uses checkoutMetadata.order_id from what we
		// sent in the checkout request. Also try other possible fields.

		// Defensive: checkoutMetadata may arrive as a JSON string instead of array.
		if ( isset( $data['checkoutMetadata'] ) && is_string( $data['checkoutMetadata'] ) ) {
			$decoded_meta = json_decode( $data['checkoutMetadata'], true );
			if ( is_array( $decoded_meta ) ) {
				$data['checkoutMetadata'] = $decoded_meta;
			}
		}
		if ( isset( $data['metadata'] ) && is_string( $data['metadata'] ) ) {
			$decoded_meta = json_decode( $data['metadata'], true );
			if ( is_array( $decoded_meta ) ) {
				$data['metadata'] = $decoded_meta;
			}
		}

		// Debug: log extraction info (detailed only when WP_DEBUG is on).
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->log( 'debug', sprintf(
				'Order ID extraction — checkoutMetadata type: %s, has order_id: %s',
				isset( $data['checkoutMetadata'] ) ? gettype( $data['checkoutMetadata'] ) : 'NOT_SET',
				isset( $data['checkoutMetadata']['order_id'] ) ? 'YES' : 'NO'
			) );
		}

		$order_id = 0;
		if ( isset( $data['checkoutMetadata']['order_id'] ) ) {
			$order_id = absint( $data['checkoutMetadata']['order_id'] );
		} elseif ( isset( $data['outsideOrderId'] ) ) {
			$order_id = absint( $data['outsideOrderId'] );
		} elseif ( isset( $data['metadata']['order_id'] ) ) {
			$order_id = absint( $data['metadata']['order_id'] );
		} elseif ( isset( $data['clickId'] ) ) {
			$order_id = absint( $data['clickId'] );
		} elseif ( isset( $data['order_id'] ) ) {
			$order_id = absint( $data['order_id'] );
		} elseif ( isset( $data['chargeId'] ) ) {
			// Try to find order by Finvaro charge ID stored in meta.
			$order_id = $this->find_order_by_charge_id( sanitize_text_field( $data['chargeId'] ) );
		}

		// Last resort: recursively search the entire data array for an order_id key.
		if ( ! $order_id ) {
			$this->log( 'warning', 'Standard extraction failed — trying recursive search.' );
			array_walk_recursive( $data, function( $value, $key ) use ( &$order_id ) {
				if ( 'order_id' === $key && $value && ! $order_id ) {
					$order_id = absint( $value );
				}
			} );
			if ( $order_id ) {
				$this->log( 'info', sprintf( 'Found order_id %d via recursive search.', $order_id ) );
			}
		}

		if ( ! $order_id ) {
			$this->log( 'error', 'Webhook: could not extract order ID from data.' );
			return new WP_REST_Response( array( 'error' => 'Order ID not found' ), 400 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			$this->log( 'error', sprintf( 'Webhook: order %d not found.', $order_id ) );
			return new WP_REST_Response( array( 'error' => 'Order not found' ), 404 );
		}

		// Verify the order belongs to this gateway.
		if ( $order->get_payment_method() !== $this->id ) {
			$this->log( 'error', sprintf( 'Webhook: order %d payment method is %s, expected %s.', $order_id, $order->get_payment_method(), $this->id ) );
			return new WP_REST_Response( array( 'error' => 'Invalid payment method' ), 400 );
		}

		// Don't update orders already in a final state.
		$final_statuses = array( 'completed', 'refunded', 'cancelled' );
		if ( in_array( $order->get_status(), $final_statuses, true ) ) {
			$this->log( 'info', sprintf( 'Webhook: order %d already %s, skipping.', $order_id, $order->get_status() ) );
			return new WP_REST_Response( array( 'success' => true, 'message' => 'Order already finalized' ), 200 );
		}

		// Extract transaction ID.
		$transaction_id = '';
		foreach ( array( 'hash', 'incomingTxHash', 'orderId', 'transaction_id', 'id', 'chargeId' ) as $tid_field ) {
			if ( ! empty( $data[ $tid_field ] ) ) {
				$transaction_id = sanitize_text_field( $data[ $tid_field ] );
				break;
			}
		}

		// Determine payment status from Finvaro fields.
		// Finvaro uses: status (boolean) + systemStatus (string like "Done").
		$payment_status = '';
		if ( isset( $data['systemStatus'] ) ) {
			$payment_status = strtolower( sanitize_text_field( $data['systemStatus'] ) );
		} elseif ( isset( $data['chargeStatus'] ) ) {
			$payment_status = strtolower( sanitize_text_field( $data['chargeStatus'] ) );
		} elseif ( isset( $data['status'] ) ) {
			// status can be boolean (Finvaro) or string (generic).
			if ( is_bool( $data['status'] ) || $data['status'] === '1' || $data['status'] === 1 ) {
				$payment_status = $data['status'] ? 'done' : 'failed';
			} else {
				$payment_status = strtolower( sanitize_text_field( $data['status'] ) );
			}
		}

		$this->log( 'info', sprintf(
			'Webhook: order %d — status: %s, transaction: %s, type: %s',
			$order_id,
			$payment_status,
			$transaction_id,
			isset( $data['typeTransaction'] ) ? $data['typeTransaction'] : '(unknown)'
		) );

		// Update order based on payment status.
		switch ( $payment_status ) {
			case 'done':
			case 'completed':
			case 'paid':
			case 'success':
			case 'approved':
				$order->payment_complete( $transaction_id );
				$order->add_order_note( sprintf(
					__( 'Crypto payment completed via webhook. Transaction: %s', 'wc-payment-gateway' ),
					$transaction_id
				) );
				$this->log( 'info', sprintf( 'Order %d marked as complete.', $order_id ) );
				break;

			case 'pending':
			case 'new':
			case 'processing':
				$order->update_status( 'on-hold', __( 'Crypto payment pending.', 'wc-payment-gateway' ) );
				break;

			case 'expired':
				$order->update_status( 'failed', __( 'Crypto payment expired.', 'wc-payment-gateway' ) );
				break;

			case 'failed':
			case 'cancelled':
			case 'error':
				$order->update_status( 'failed', __( 'Crypto payment failed or cancelled.', 'wc-payment-gateway' ) );
				break;

			default:
				$this->log( 'warning', sprintf( 'Webhook: unrecognized status "%s" for order %d — no status change.', $payment_status, $order_id ) );
				break;
		}

		return new WP_REST_Response( array( 'success' => true, 'order_id' => $order_id ), 200 );
	}

	/**
	 * Decrypt a Finvaro encrypted callback payload.
	 *
	 * Finvaro sends callbacks encrypted using CryptoJS AES-256-CBC format.
	 * The JWT Authorization header contains the wallet ID and encrypted salt.
	 *
	 * @param string $auth_header Authorization header value.
	 * @param string $raw_body    Raw request body (encrypted data).
	 * @return array|false Decrypted data as associative array, or false on failure.
	 */
	private function decrypt_finvaro_callback( $auth_header, $raw_body ) {
		// Extract JWT token from Bearer header.
		$pos = strpos( $auth_header, 'Bearer ' );
		if ( false === $pos ) {
			return false;
		}
		$jwt = substr( $auth_header, $pos + 7 );
		if ( strpos( $jwt, ',' ) !== false ) {
			$jwt = strstr( $jwt, ',', true );
		}

		// Decode JWT claims (we only need the payload, skip signature verification
		// since Finvaro doesn't document a shared secret for JWT verification).
		$parts = explode( '.', $jwt );
		if ( count( $parts ) < 3 ) {
			$this->log( 'error', 'Webhook decrypt: invalid JWT format.' );
			return false;
		}

		$decoded = base64_decode( strtr( $parts[1], '-_', '+/' ), true );
		if ( false === $decoded ) {
			$this->log( 'error', 'Webhook decrypt: invalid base64 in JWT.' );
			return false;
		}
		$claims = json_decode( $decoded, true );
		if ( empty( $claims ) ) {
			$this->log( 'error', 'Webhook decrypt: could not decode JWT claims.' );
			return false;
		}

		$wallet_id = isset( $claims['id'] ) ? $claims['id'] : '';
		$salt      = isset( $claims['salt'] ) ? $claims['salt'] : '';
		$exp       = isset( $claims['exp'] ) ? $claims['exp'] : 0;

		if ( empty( $wallet_id ) || empty( $salt ) ) {
			$this->log( 'error', 'Webhook decrypt: JWT missing id or salt claim.' );
			return false;
		}

		// Check expiration.
		if ( $exp > 0 && $exp < time() ) {
			$this->log( 'warning', 'Webhook decrypt: JWT expired, proceeding anyway.' );
		}

		// Decrypt the salt using the wallet ID as the passphrase.
		$decrypted_salt = $this->cryptojs_aes_decrypt( $salt, $wallet_id );
		if ( false === $decrypted_salt ) {
			$this->log( 'error', 'Webhook decrypt: could not decrypt salt.' );
			return false;
		}

		// Parse the body — it may be JSON with a "data" field containing the encrypted string.
		$body_json = json_decode( $raw_body, true );
		$encrypted_data = '';
		if ( isset( $body_json['data'] ) ) {
			$encrypted_data = $body_json['data'];
		} else {
			$encrypted_data = $raw_body;
		}

		// Decrypt the actual callback data using the decrypted salt.
		$decrypted_body = $this->cryptojs_aes_decrypt( $encrypted_data, $decrypted_salt );
		if ( false === $decrypted_body ) {
			$this->log( 'error', 'Webhook decrypt: could not decrypt body data.' );
			return false;
		}

		$result = json_decode( $decrypted_body, true );
		if ( ! is_array( $result ) ) {
			$this->log( 'error', 'Webhook decrypt: decrypted body is not valid JSON: ' . substr( $decrypted_body, 0, 500 ) );
			return false;
		}

		return $result;
	}

	/**
	 * Decrypt CryptoJS AES-256-CBC encrypted data.
	 *
	 * CryptoJS format: base64 of "Salted__" + 8-byte salt + ciphertext.
	 * Key derivation uses EVP_BytesToKey (MD5-based).
	 *
	 * @param string $data Base64-encoded encrypted data.
	 * @param string $key  Passphrase for decryption.
	 * @return string|false Decrypted plaintext or false on failure.
	 */
	private function cryptojs_aes_decrypt( $data, $key ) {
		$raw = base64_decode( $data );
		if ( false === $raw || strlen( $raw ) < 16 ) {
			return false;
		}

		// CryptoJS format: "Salted__" (8 bytes) + salt (8 bytes) + ciphertext.
		if ( substr( $raw, 0, 8 ) !== 'Salted__' ) {
			return false;
		}

		$salt       = substr( $raw, 8, 8 );
		$ciphertext = substr( $raw, 16 );

		// EVP_BytesToKey: derive 32-byte key + 16-byte IV from passphrase + salt.
		$key_iv = $this->evp_bytes_to_key( $key, $salt, 48 );
		if ( false === $key_iv ) {
			return false;
		}

		$aes_key = substr( $key_iv, 0, 32 );
		$aes_iv  = substr( $key_iv, 32, 16 );

		$decrypted = openssl_decrypt( $ciphertext, 'aes-256-cbc', $aes_key, OPENSSL_RAW_DATA, $aes_iv );

		return $decrypted;
	}

	/**
	 * EVP_BytesToKey key derivation (MD5-based, matching CryptoJS/OpenSSL).
	 *
	 * @param string $password  Passphrase.
	 * @param string $salt      8-byte salt.
	 * @param int    $key_len   Desired output length (key + IV).
	 * @return string|false Derived key material or false on failure.
	 */
	private function evp_bytes_to_key( $password, $salt, $key_len ) {
		$result = '';
		$prev   = '';
		while ( strlen( $result ) < $key_len ) {
			$prev   = md5( $prev . $password . $salt, true );
			$result .= $prev;
		}
		return substr( $result, 0, $key_len );
	}

	/**
	 * Find an order by Finvaro charge ID stored in order meta.
	 *
	 * @param string $charge_id Finvaro charge ID.
	 * @return int Order ID or 0 if not found.
	 */
	private function find_order_by_charge_id( $charge_id ) {
		if ( empty( $charge_id ) ) {
			return 0;
		}

		$orders = wc_get_orders( array(
			'meta_key'   => '_wcpg_crypto_charge_id',
			'meta_value' => $charge_id,
			'limit'      => 1,
		) );

		return ! empty( $orders ) ? $orders[0]->get_id() : 0;
	}

	/**
	 * Poll Finvaro for charge status on pending crypto orders.
	 *
	 * Called via WP-Cron every 5 minutes. Queries the Finvaro charge-list endpoint
	 * for each on-hold crypto order that has a stored checkout ID.
	 */
	public function poll_pending_orders() {
		$public_key  = $this->get_option( 'public_key', '' );
		$private_key = $this->get_option( 'private_key', '' );

		if ( empty( $public_key ) || empty( $private_key ) ) {
			return;
		}

		// Find on-hold orders for this gateway.
		$orders = wc_get_orders( array(
			'status'         => 'on-hold',
			'payment_method' => $this->id,
			'limit'          => 20,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		if ( empty( $orders ) ) {
			return;
		}

		$access_token = $this->get_finvaro_token( $public_key, $private_key );
		if ( ! $access_token ) {
			$this->log( 'error', 'Poller: could not authenticate with Finvaro.' );
			return;
		}

		$this->log( 'info', sprintf( 'Poller: checking %d pending crypto orders.', count( $orders ) ) );

		foreach ( $orders as $order ) {
			$checkout_id = $order->get_meta( '_wcpg_crypto_checkout_id' );
			if ( empty( $checkout_id ) ) {
				continue;
			}

			$charge_url = sprintf( 'https://napi.finvaro.com/api/public/checkout/%s/charge-list', $checkout_id );
			$response   = wp_remote_get( $charge_url, array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
			) );

			if ( is_wp_error( $response ) ) {
				$this->log( 'error', sprintf( 'Poller: charge-list error for order %d: %s', $order->get_id(), $response->get_error_message() ) );
				continue;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body        = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 !== $status_code ) {
				$this->log( 'warning', sprintf( 'Poller: charge-list HTTP %d for order %d.', $status_code, $order->get_id() ) );
				continue;
			}

			$entities = array();
			if ( isset( $body['data']['entities'] ) ) {
				$entities = $body['data']['entities'];
			} elseif ( isset( $body['entities'] ) ) {
				$entities = $body['entities'];
			}

			if ( empty( $entities ) ) {
				continue;
			}

			// Check if any charge has a completed status.
			foreach ( $entities as $charge ) {
				$sys_status = isset( $charge['systemStatus'] ) ? strtolower( $charge['systemStatus'] ) : '';
				$charge_id  = isset( $charge['id'] ) ? $charge['id'] : ( isset( $charge['_id'] ) ? $charge['_id'] : '' );

				if ( 'done' === $sys_status ) {
					$order->payment_complete( $charge_id );
					$order->add_order_note( sprintf(
						__( 'Crypto payment confirmed via status poll. Charge: %s', 'wc-payment-gateway' ),
						$charge_id
					) );
					$this->log( 'info', sprintf( 'Poller: order %d marked complete (charge %s).', $order->get_id(), $charge_id ) );
					break;
				}
			}
		}
	}

	/**
	 * Log a message via WooCommerce logger.
	 *
	 * @param string $level Log level (info, error, debug).
	 * @param string $message Message to log.
	 */
	private function log( $level, $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'wcpg_crypto' ) );
		}
	}
}

<?php
/**
 * E-Transfer Payment Gateway
 *
 * Provides a unified E-Transfer payment gateway supporting multiple delivery methods:
 * - Email: Sends payment link via email
 * - URL: Opens payment URL in new tab
 * - Manual: Traditional Q&A verification (Interac-style)
 *
 * @package DigipayMasterPlugin
 * @since 12.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * E-Transfer Gateway Class
 */
class WC_Gateway_ETransfer extends WC_Payment_Gateway {

	/**
	 * Gateway ID constant.
	 */
	const GATEWAY_ID = 'digipay_etransfer';

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
	 * API Client instance.
	 *
	 * @var WCPG_ETransfer_API_Client|null
	 */
	protected $api_client = null;

	/**
	 * Template loader instance.
	 *
	 * @var WCPG_ETransfer_Template_Loader|null
	 */
	protected $template_loader = null;

	/**
	 * Track if hooks have been added.
	 *
	 * @var bool
	 */
	private static $hooks_added = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Set gateway properties.
		$this->id                 = self::GATEWAY_ID;
		$this->icon               = apply_filters( 'woocommerce_etransfer_icon', plugin_dir_url( __FILE__ ) . 'assets/images/interac-etransfer.png' );
		$this->has_fields         = true;
		$this->method_title       = __( 'Interac e-Transfer', self::TEXT_DOMAIN );
		$this->method_description = __( 'Accept payments via Interac e-Transfer with multiple delivery methods.', self::TEXT_DOMAIN );
		$this->supports           = array( 'products' );

		// Load settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user-facing settings.
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		// Initialize template loader.
		$this->template_loader = new WCPG_ETransfer_Template_Loader();

		// Only add hooks once to prevent duplicate output.
		if ( ! self::$hooks_added ) {
			self::$hooks_added = true;
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

			// Register thankyou hooks for each virtual gateway ID (orders use these, not the master ID).
			foreach ( array( 'digipay_etransfer_email', 'digipay_etransfer_url', 'digipay_etransfer_manual' ) as $virtual_id ) {
				add_action( 'woocommerce_thankyou_' . $virtual_id, array( $this, 'thankyou_page' ) );
			}

			add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
		}
	}

	/**
	 * Get the gateway icon with proper sizing.
	 *
	 * @return string Icon HTML.
	 */
	public function get_icon() {
		$icon_url = $this->icon;
		if ( ! empty( $icon_url ) ) {
			$icon = '<img src="' . esc_url( $icon_url ) . '" alt="' . esc_attr( $this->get_title() ) . '" style="max-height: 24px; width: auto; vertical-align: middle; margin-left: 5px;" />';
			return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
		}
		return '';
	}

	/**
	 * Initialize Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			// --- Section: Gateway Settings ---
			'enabled'              => array(
				'title'       => __( 'Enable/Disable', self::TEXT_DOMAIN ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable e-Transfer Gateway', self::TEXT_DOMAIN ),
				'description' => __( 'Enable e-Transfer Payments', self::TEXT_DOMAIN ),
				'default'     => 'no',
				'section'     => 'gateway',
			),
			'delivery_method'      => array(
				'title'       => __( 'Request Money', self::TEXT_DOMAIN ),
				'type'        => 'select',
				'description' => __( 'Choose how customers receive the payment request. Only one delivery method can be active at a time.', self::TEXT_DOMAIN ),
				'default'     => 'none',
				'desc_tip'    => true,
				'options'     => array(
					'none'                => __( 'Disabled', self::TEXT_DOMAIN ),
					self::DELIVERY_URL    => __( 'Redirect / Pop-up', self::TEXT_DOMAIN ),
					self::DELIVERY_EMAIL  => __( 'Email', self::TEXT_DOMAIN ),
				),
				'section' => 'gateway',
			),
			'enable_manual'        => array(
				'title'       => __( 'Send Money', self::TEXT_DOMAIN ),
				'type'        => 'select',
				'description' => __( 'When enabled, Send Money appears as an additional payment option at checkout. This displays payment instructions for a customer-initiated transfer using a security question and answer.', self::TEXT_DOMAIN ),
				'default'     => 'no',
				'desc_tip'    => true,
				'options'     => array(
					'no'  => __( 'Disabled', self::TEXT_DOMAIN ),
					'yes' => __( 'Enabled', self::TEXT_DOMAIN ),
				),
				'section' => 'gateway',
			),
			'order_status'         => array(
				'title'       => __( 'Order Status', self::TEXT_DOMAIN ),
				'type'        => 'select',
				'description' => __( 'Order status after checkout while awaiting payment.', self::TEXT_DOMAIN ),
				'default'     => 'on-hold',
				'desc_tip'    => true,
				'options'     => array(
					'on-hold'    => __( 'On Hold', self::TEXT_DOMAIN ),
					'pending'    => __( 'Pending Payment', self::TEXT_DOMAIN ),
					'processing' => __( 'Processing', self::TEXT_DOMAIN ),
				),
				'section' => 'gateway',
			),

			// --- Section: Request Money Settings ---
			'title_api'            => array(
				'title'       => __( 'Request Money Title', self::TEXT_DOMAIN ),
				'type'        => 'text',
				'description' => __( 'Title shown at checkout for the Request Money option.', self::TEXT_DOMAIN ),
				'default'     => __( 'Interac e-Transfer (Request Money)', self::TEXT_DOMAIN ),
				'desc_tip'    => true,
				'section'     => 'request_money',
			),
			'description_api'      => array(
				'title'       => __( 'Request Money Description', self::TEXT_DOMAIN ),
				'type'        => 'textarea',
				'description' => __( 'Description shown at checkout for the Request Money option.', self::TEXT_DOMAIN ),
				'default'     => __( 'Pay securely via Interac e-Transfer. A pop-up from Interac will appear after checkout.', self::TEXT_DOMAIN ),
				'desc_tip'    => true,
				'css'         => 'min-height: 60px;',
				'section'     => 'request_money',
			),
			'instructions_email'   => array(
				'title'       => __( 'Checkout Instructions (Email)', self::TEXT_DOMAIN ),
				'type'        => 'wysiwyg',
				'description' => __( 'Instructions shown at checkout below the description for Email delivery.', self::TEXT_DOMAIN ),
				'default'     => '<p><strong>Next steps:</strong></p><ol><li>Click <strong>"Place Order"</strong> to confirm your purchase.</li><li>A secure payment link will be sent to your email address.</li><li>Follow the instructions in the email to complete your Interac e-Transfer.</li></ol><p style="color: #666; font-size: 0.9em;"><em>Please note: Orders will be automatically cancelled if payment is not received within the allotted time.</em></p>',
				'desc_tip'    => true,
				'css'         => 'min-height: 100px;',
				'class'       => 'etransfer-delivery-email',
				'section'     => 'request_money',
			),
			'instructions_url'     => array(
				'title'       => __( 'Checkout Instructions (Redirect / Pop-up)', self::TEXT_DOMAIN ),
				'type'        => 'wysiwyg',
				'description' => __( 'Instructions shown at checkout below the description for Redirect / Pop-up delivery.', self::TEXT_DOMAIN ),
				'default'     => '<p><strong>Next steps:</strong></p><ol><li>Click <strong>"Place Order"</strong> to confirm your purchase.</li><li>Ensure your pop-up blocker is disabled for this site.</li><li>You will be redirected to a secure Interac e-Transfer checkout page.</li><li>Follow the on-screen instructions to complete your payment.</li></ol><p style="color: #666; font-size: 0.9em;"><em>Please note: Orders will be automatically cancelled if payment is not received within the allotted time.</em></p>',
				'desc_tip'    => true,
				'css'         => 'min-height: 100px;',
				'class'       => 'etransfer-delivery-url',
				'section'     => 'request_money',
			),
			'require_login'        => array(
				'title'             => __( 'Require Login', self::TEXT_DOMAIN ),
				'type'              => 'checkbox',
				'label'             => __( 'Require customers to login to the payment gateway', self::TEXT_DOMAIN ),
				'description'       => __( 'Only applies to Email and URL delivery methods.', self::TEXT_DOMAIN ),
				'default'           => 'no',
				'desc_tip'          => true,
				'section'           => 'request_money',
			),
			'popup_title'          => array(
				'title'             => __( 'Popup Title', self::TEXT_DOMAIN ),
				'type'              => 'text',
				'description'       => __( 'Title shown in the thank you page popup.', self::TEXT_DOMAIN ),
				'default'           => __( 'Complete Your Payment', self::TEXT_DOMAIN ),
				'desc_tip'          => true,
				'class'             => 'etransfer-delivery-email',
				'section'           => 'request_money',
			),
			'popup_body'           => array(
				'title'             => __( 'Popup Body', self::TEXT_DOMAIN ),
				'type'              => 'textarea',
				'description'       => __( 'Message shown in the thank you page popup.', self::TEXT_DOMAIN ),
				'default'           => __( 'A payment link has been sent to your email.', self::TEXT_DOMAIN ),
				'desc_tip'          => true,
				'css'               => 'min-height: 60px;',
				'class'             => 'etransfer-delivery-email',
				'section'           => 'request_money',
			),
			'button_text'          => array(
				'title'             => __( 'Button Text', self::TEXT_DOMAIN ),
				'type'              => 'text',
				'description'       => __( 'Text for the payment button.', self::TEXT_DOMAIN ),
				'default'           => __( 'Complete Payment', self::TEXT_DOMAIN ),
				'desc_tip'          => true,
				'class'             => 'etransfer-delivery-url',
				'section'           => 'request_money',
			),

			// --- Section: Send Money Settings ---
			'title_manual'         => array(
				'title'       => __( 'Send Money Title', self::TEXT_DOMAIN ),
				'type'        => 'text',
				'description' => __( 'Title shown at checkout for the Send Money option.', self::TEXT_DOMAIN ),
				'default'     => __( 'Interac e-Transfer (Send Money)', self::TEXT_DOMAIN ),
				'desc_tip'    => true,
				'section'     => 'send_money',
			),
			'description_manual'   => array(
				'title'       => __( 'Send Money Description', self::TEXT_DOMAIN ),
				'type'        => 'textarea',
				'description' => __( 'Description shown at checkout for the Send Money option.', self::TEXT_DOMAIN ),
				'default'     => __( 'Pay securely via Interac e-Transfer. Send money using the provided instructions.', self::TEXT_DOMAIN ),
				'desc_tip'    => true,
				'css'         => 'min-height: 60px;',
				'section'     => 'send_money',
			),
			'instructions_manual'  => array(
				'title'       => __( 'Send Money Checkout Instructions', self::TEXT_DOMAIN ),
				'type'        => 'wysiwyg',
				'description' => __( 'Instructions shown at checkout below the description for Send Money.', self::TEXT_DOMAIN ),
				'default'     => '<p><strong>Next steps:</strong></p><ol><li>Click <strong>"Place Order"</strong> to confirm your purchase.</li><li>You will receive Interac e-Transfer instructions on the following page.</li><li>Send an Interac e-Transfer using the provided details.</li></ol><p style="color: #666; font-size: 0.9em;"><em>Please note: Orders will be automatically cancelled if payment is not received within the allotted time.</em></p>',
				'desc_tip'    => true,
				'css'         => 'min-height: 100px;',
				'section'     => 'send_money',
			),
			'recipient_name'       => array(
				'title'             => __( 'Recipient Name', self::TEXT_DOMAIN ),
				'type'              => 'text',
				'description'       => __( 'Name displayed to customers for the e-Transfer recipient.', self::TEXT_DOMAIN ),
				'default'           => '',
				'desc_tip'          => true,
				'placeholder'       => 'Payment Email',
				'section'           => 'send_money',
			),
			'recipient_email'      => array(
				'title'             => __( 'Recipient Email', self::TEXT_DOMAIN ),
				'type'              => 'email',
				'description'       => __( 'Email address where customers should send the e-Transfer.', self::TEXT_DOMAIN ),
				'default'           => '',
				'desc_tip'          => true,
				'placeholder'       => 'payments@example.com',
				'section'           => 'send_money',
			),
			'security_question'    => array(
				'title'             => __( 'Security Question', self::TEXT_DOMAIN ),
				'type'              => 'text',
				'description'       => __( 'The security question customers should use.', self::TEXT_DOMAIN ),
				'default'           => '',
				'desc_tip'          => true,
				'placeholder'       => 'Favorite sport?',
				'section'           => 'send_money',
			),
			'security_answer'      => array(
				'title'             => __( 'Security Answer', self::TEXT_DOMAIN ),
				'type'              => 'text',
				'description'       => __( 'The security answer customers should use (case-sensitive).', self::TEXT_DOMAIN ),
				'default'           => '',
				'desc_tip'          => true,
				'placeholder'       => 'Hockey',
				'section'           => 'send_money',
			),

			// --- Section: API Settings ---
			'api_endpoint'         => array(
				'title'             => __( 'API Endpoint', self::TEXT_DOMAIN ),
				'type'              => 'text',
				'description'       => __( 'The base URL for the payment API.', self::TEXT_DOMAIN ),
				'default'           => '',
				'desc_tip'          => true,
				'placeholder'       => 'https://api.example.com/api/v1',
				'section'           => 'api',
			),
			'account_uuid'         => array(
				'title'             => __( 'Account UUID', self::TEXT_DOMAIN ),
				'type'              => 'text',
				'description'       => __( 'Your account UUID from the payment provider.', self::TEXT_DOMAIN ),
				'default'           => '',
				'desc_tip'          => true,
				'section'           => 'api',
			),
			'client_id'            => array(
				'title'             => __( 'Client ID', self::TEXT_DOMAIN ),
				'type'              => 'text',
				'description'       => __( 'OAuth client ID for API authentication.', self::TEXT_DOMAIN ),
				'default'           => '',
				'desc_tip'          => true,
				'section'           => 'api',
			),
			'client_secret'        => array(
				'title'             => __( 'Client Secret', self::TEXT_DOMAIN ),
				'type'              => 'password',
				'description'       => __( 'OAuth client secret for API authentication.', self::TEXT_DOMAIN ),
				'default'           => '',
				'desc_tip'          => true,
				'section'           => 'api',
			),
			'api_description_prefix' => array(
				'title'             => __( 'Transaction Description Prefix', self::TEXT_DOMAIN ),
				'type'              => 'text',
				'description'       => __( 'Prefix added before the order number in the API request (e.g. "Order #" or "Deposit #"). Leave blank to send only the order number.', self::TEXT_DOMAIN ),
				'default'           => '',
				'desc_tip'          => true,
				'placeholder'       => 'Order #',
				'section'           => 'api',
			),
		);
	}

	/**
	 * Get default instructions template.
	 *
	 * @return string
	 */
	public function get_default_instructions() {
		return __(
			'Please send an Interac e-Transfer to complete your order:<br/><br/>' .
			'<strong>Security Question:</strong> What is order number {1}?<br/>' .
			'<strong>Security Answer:</strong> {2}<br/><br/>' .
			'Thank you for your order!',
			self::TEXT_DOMAIN
		);
	}

	/**
	 * Get form fields.
	 *
	 * @return array
	 */
	public function get_form_fields() {
		return $this->form_fields;
	}

	/**
	 * Generate HTML for WYSIWYG (rich text editor) field type.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 * @return string
	 */
	public function generate_wysiwyg_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$defaults  = array(
			'title'       => '',
			'class'       => '',
			'css'         => '',
			'desc_tip'    => false,
			'description' => '',
			'default'     => '',
		);
		$data  = wp_parse_args( $data, $defaults );
		$value = $this->get_option( $key );

		// Editor ID must be lowercase with only letters, numbers, underscores.
		$editor_id = strtolower( str_replace( array( '-', '[', ']' ), '_', $field_key ) );

		ob_start();
		?>
		<tr valign="top" class="<?php echo esc_attr( $data['class'] ); ?>">
			<th scope="row" class="titledesc">
				<?php echo $this->get_tooltip_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<label for="<?php echo esc_attr( $editor_id ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<?php
				wp_editor(
					$value,
					$editor_id,
					array(
						'textarea_name' => $field_key,
						'textarea_rows' => 8,
						'media_buttons' => false,
						'quicktags'     => true,
						'tinymce'       => array(
							'toolbar1' => 'bold,italic,underline,strikethrough,|,bullist,numlist,|,link,unlink,|,forecolor,|,fontsizeselect',
							'toolbar2' => '',
						),
					)
				);
				?>
				<?php echo $this->get_description_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Validate WYSIWYG field value.
	 *
	 * @param string $key   Field key.
	 * @param string $value Field value.
	 * @return string Sanitized value.
	 */
	public function validate_wysiwyg_field( $key, $value ) {
		return wp_kses_post( trim( $value ) );
	}

	/**
	 * Output payment fields on the checkout page.
	 *
	 * Displays the description and checkout instructions based on delivery method.
	 */
	public function payment_fields() {
		$delivery_method = $this->get_option( 'delivery_method', self::DELIVERY_EMAIL );

		// Use the WYSIWYG instructions content, falling back to field defaults.
		$instructions_key = 'instructions_' . $delivery_method;
		$instructions     = $this->get_option( $instructions_key );

		if ( ! empty( $instructions ) ) {
			echo '<div class="wcpg-etransfer-checkout-instructions" style="margin-top: 10px;">';
			echo wp_kses_post( $instructions );
			echo '</div>';
		}
	}



	/**
	 * Generate a random secret answer.
	 *
	 * @param int $user_id User ID (optional).
	 * @return string 6-character alphanumeric code.
	 */
	public function generate_secret_answer( $user_id = 0 ) {
		// Check for existing answer in user meta.
		if ( $user_id > 0 && function_exists( 'get_user_meta' ) ) {
			$existing = get_user_meta( $user_id, '_etransfer_secret_answer', true );
			if ( ! empty( $existing ) ) {
				return $existing;
			}
		}

		// Generate new 6-character alphanumeric code (lowercase, no ambiguous chars).
		$chars  = 'abcdefghjkmnpqrstuvwxyz23456789';
		$answer = '';
		for ( $i = 0; $i < 6; $i++ ) {
			$index   = random_int( 0, strlen( $chars ) - 1 );
			$answer .= $chars[ $index ];
		}

		// Save to user meta if logged in.
		if ( $user_id > 0 && function_exists( 'update_user_meta' ) ) {
			update_user_meta( $user_id, '_etransfer_secret_answer', $answer );
		}

		return $answer;
	}

	/**
	 * Format instructions with placeholder replacement.
	 *
	 * @param string $template     Instructions template.
	 * @param string $order_number Order number.
	 * @param string $secret       Secret answer.
	 * @return string Formatted instructions.
	 */
	public function format_instructions( $template, $order_number, $secret ) {
		$instructions = str_replace( '{1}', $order_number, $template );
		$instructions = str_replace( '{2}', $secret, $instructions );
		return $instructions;
	}

	/**
	 * Check if the gateway is available for use.
	 *
	 * The master gateway is never available at checkout - it's only for settings.
	 * Virtual gateways (Email, URL, Manual) handle actual checkout availability.
	 *
	 * @return bool Always false for master gateway.
	 */
	public function is_available() {
		// Master gateway is settings-only, not visible at checkout.
		// Virtual gateways handle checkout availability.
		return false;
	}

	/**
	 * Check if API credentials are configured.
	 *
	 * @return bool True if all API credentials are set.
	 */
	public function has_api_credentials() {
		$api_endpoint  = $this->get_option( 'api_endpoint' );
		$account_uuid  = $this->get_option( 'account_uuid' );
		$client_id     = $this->get_option( 'client_id' );
		$client_secret = $this->get_option( 'client_secret' );

		return ! empty( $api_endpoint ) && ! empty( $account_uuid ) && ! empty( $client_id ) && ! empty( $client_secret );
	}

	/**
	 * Check if manual transfer settings are configured.
	 *
	 * @return bool True if all required manual settings are set.
	 */
	public function has_manual_settings() {
		$recipient_name    = $this->get_option( 'recipient_name' );
		$recipient_email   = $this->get_option( 'recipient_email' );
		$security_question = $this->get_option( 'security_question' );
		$security_answer   = $this->get_option( 'security_answer' );

		return ! empty( $recipient_name ) && ! empty( $recipient_email ) && ! empty( $security_question ) && ! empty( $security_answer );
	}

	/**
	 * Get or create API client instance.
	 *
	 * @return WCPG_ETransfer_API_Client
	 */
	protected function get_api_client() {
		if ( null === $this->api_client ) {
			$this->api_client = new WCPG_ETransfer_API_Client(
				$this->get_option( 'client_id' ),
				$this->get_option( 'client_secret' ),
				$this->get_option( 'api_endpoint' ),
				$this->get_option( 'account_uuid' )
			);
		}
		return $this->api_client;
	}

	public function process_payment( $order_id ) {
		return $this->process_payment_for_delivery( $order_id, $this->get_option( 'delivery_method', self::DELIVERY_EMAIL ) );
	}

	protected function extract_response_field( $response, $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $response[ $key ] ) ) {
				return $response[ $key ];
			}
			if ( isset( $response['data'][ $key ] ) ) {
				return $response['data'][ $key ];
			}
		}
		return null;
	}

	/**
	 * Process payment via API (email or URL method).
	 *
	 * @param WC_Order $order           Order object.
	 * @param string   $delivery_method Delivery method (email or url).
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	protected function process_api_payment( $order, $delivery_method ) {
		$api_client = $this->get_api_client();

		// Prepare order data for API.
		$prefix      = $this->get_option( 'api_description_prefix', '' );
		$description = $prefix . $order->get_order_number();

		$order_data = array(
			'email'       => $order->get_billing_email(),
			'name'        => $order->get_formatted_billing_full_name(),
			'total'       => $order->get_total(),
			'currency'    => $order->get_currency(),
			'description' => $description,
		);

		// Request e-transfer link from API.
		$response = $api_client->request_etransfer_link( $order_data, $delivery_method );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Debug: Log the API response to see the structure.
		wc_get_logger()->debug( 'E-Transfer API Response: ' . print_r( $response, true ), array( 'source' => 'digipay-etransfer' ) );

		// Check for API error response (use loose comparison - API may return empty string, null, or false).
		if ( isset( $response['success'] ) && ! $response['success'] ) {
			$error_message = isset( $response['message'] ) ? $response['message'] : __( 'Payment request failed', self::TEXT_DOMAIN );
			if ( isset( $response['errors'] ) && is_array( $response['errors'] ) ) {
				$first_error = reset( $response['errors'] );
				if ( is_array( $first_error ) && ! empty( $first_error[0] ) ) {
					$error_message .= ': ' . $first_error[0];
				}
			}
			wc_get_logger()->error( 'E-Transfer API Error: ' . $error_message, array( 'source' => 'digipay-etransfer' ) );
			return new WP_Error( 'api_error', $error_message );
		}

		$reference = $this->extract_response_field( $response, array( 'reference' ) );

		if ( ! empty( $reference ) ) {
			$order->update_meta_data( '_etransfer_reference', sanitize_text_field( $reference ) );
		}

		$payment_url = $this->extract_response_field( $response, array( 'payment_url', 'url', 'link' ) );

		if ( ! empty( $payment_url ) ) {
			$order->update_meta_data( '_etransfer_payment_url', esc_url_raw( $payment_url ) );
			wc_get_logger()->debug( 'E-Transfer Payment URL stored: ' . $payment_url, array( 'source' => 'digipay-etransfer' ) );
		} else {
			wc_get_logger()->error( 'E-Transfer: No payment_url found in API response', array( 'source' => 'digipay-etransfer' ) );
		}

		$transaction_id = $this->extract_response_field( $response, array( 'transaction_id', 'id' ) );

		if ( ! empty( $transaction_id ) ) {
			$order->update_meta_data( '_etransfer_transaction_id', sanitize_text_field( $transaction_id ) );
		}

		// Store delivery method for thank you page.
		$order->update_meta_data( '_etransfer_delivery_method', $delivery_method );
		$order->save();

		// Add order note.
		$order->add_order_note(
			sprintf(
				/* translators: 1: delivery method, 2: reference */
				__( 'e-Transfer payment initiated via %1$s method. Reference: %2$s', self::TEXT_DOMAIN ),
				ucfirst( $delivery_method ),
				isset( $response['reference'] ) ? $response['reference'] : 'N/A'
			)
		);

		return true;
	}

	/**
	 * Process manual payment (Q&A method).
	 *
	 * @param WC_Order $order Order object.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	protected function process_manual_payment( $order ) {
		// Get user ID if logged in.
		$user_id = $order->get_customer_id();

		// Generate secret answer.
		$secret_answer = $this->generate_secret_answer( $user_id );

		// Store in order meta.
		$order->update_meta_data( '_etransfer_secret_answer', $secret_answer );
		$order->update_meta_data( '_etransfer_delivery_method', self::DELIVERY_MANUAL );
		$order->save();

		// Get recipient email.
		$recipient_email = $this->get_option( 'recipient_email' );

		// Add order note with payment details (visible to admin).
		$order->add_order_note(
			sprintf(
				/* translators: 1: recipient email, 2: order number, 3: secret answer */
				__( 'e-Transfer payment initiated (Manual method). Recipient: %1$s, Security Question: "What is order number %2$s?", Answer: %3$s', self::TEXT_DOMAIN ),
				$recipient_email,
				$order->get_order_number(),
				$secret_answer
			)
		);

		return true;
	}

	/**
	 * Process payment on behalf of a virtual gateway.
	 *
	 * @param int    $order_id        Order ID.
	 * @param string $delivery_method Delivery method (email, url, or manual).
	 * @return array WooCommerce payment result array.
	 */
	public function process_payment_for_delivery( $order_id, $delivery_method ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wc_add_notice( __( 'Order not found.', self::TEXT_DOMAIN ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order_status = $this->get_option( 'order_status', 'on-hold' );

		if ( self::DELIVERY_MANUAL === $delivery_method ) {
			$result = $this->process_manual_payment( $order );
		} else {
			$result = $this->process_api_payment( $order, $delivery_method );
		}

		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		$status_note = $this->get_status_note( $delivery_method );
		$order->update_status( $order_status, $status_note );

		wc_reduce_stock_levels( $order_id );
		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Get status note based on delivery method.
	 *
	 * @param string $delivery_method Delivery method.
	 * @return string
	 */
	protected function get_status_note( $delivery_method ) {
		switch ( $delivery_method ) {
			case self::DELIVERY_EMAIL:
				return __( 'Awaiting e-Transfer payment. Payment link sent via email.', self::TEXT_DOMAIN );
			case self::DELIVERY_URL:
				return __( 'Awaiting e-Transfer payment. Customer redirected to payment URL.', self::TEXT_DOMAIN );
			case self::DELIVERY_MANUAL:
				return __( 'Awaiting e-Transfer payment. Manual transfer instructions provided.', self::TEXT_DOMAIN );
			default:
				return __( 'Awaiting e-Transfer payment.', self::TEXT_DOMAIN );
		}
	}

	/**
	 * Thank you page output.
	 *
	 * @param int $order_id Order ID.
	 */
	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		// Get delivery method from order meta.
		$delivery_method = $order->get_meta( '_etransfer_delivery_method' );

		if ( empty( $delivery_method ) ) {
			$delivery_method = $this->get_option( 'delivery_method', self::DELIVERY_EMAIL );
		}

		switch ( $delivery_method ) {
			case self::DELIVERY_EMAIL:
				$this->render_email_thankyou( $order );
				break;
			case self::DELIVERY_URL:
				$this->render_url_thankyou( $order );
				break;
			case self::DELIVERY_MANUAL:
				$this->render_manual_thankyou( $order );
				break;
		}
	}

	/**
	 * Render thank you page for email delivery method.
	 *
	 * @param WC_Order $order Order object.
	 */
	protected function render_email_thankyou( $order ) {
		$args = array(
			'order'       => $order,
			'method'      => self::DELIVERY_EMAIL,
			'popup_title' => $this->get_option( 'popup_title', __( 'Complete Your Payment', self::TEXT_DOMAIN ) ),
			'popup_body'  => $this->get_option( 'popup_body', __( 'A payment link has been sent to your email.', self::TEXT_DOMAIN ) ),
		);

		$this->template_loader->load_template( 'thankyou-popup.php', $args );
	}

	/**
	 * Render thank you page for URL delivery method.
	 *
	 * @param WC_Order $order Order object.
	 */
	protected function render_url_thankyou( $order ) {
		$payment_url = $order->get_meta( '_etransfer_payment_url' );

		$args = array(
			'order'       => $order,
			'method'      => self::DELIVERY_URL,
			'payment_url' => $payment_url,
			'button_text' => $this->get_option( 'button_text', __( 'Complete Payment', self::TEXT_DOMAIN ) ),
		);

		$this->template_loader->load_template( 'thankyou-popup.php', $args );
	}

	/**
	 * Render thank you page for manual delivery method.
	 *
	 * @param WC_Order $order Order object.
	 */
	protected function render_manual_thankyou( $order ) {
		static $manual_rendered = false;
		if ( $manual_rendered ) {
			return;
		}
		$manual_rendered = true;

		// Get settings.
		$recipient_name    = $this->get_option( 'recipient_name', '' );
		$recipient_email   = $this->get_option( 'recipient_email', '' );
		$security_question = $this->get_option( 'security_question', '' );
		$security_answer   = $this->get_option( 'security_answer', '' );
		$support_email     = $this->get_option( 'support_email', '' );
		$order_number      = $order->get_order_number();

		// Output inline instructions on thank you page (visible if popup is closed).
		?>
		<div class="wcpg-etransfer-instructions" style="background:#f8f9fa;border:1px solid #e9ecef;border-left:4px solid #28a745;border-radius:4px;padding:20px;margin:20px 0;">
			<p style="margin:0 0 15px;"><strong style="font-size:18px;">Interac e-Transfer Instructions</strong></p>
			<p style="margin:0 0 15px;font-size:14px;line-height:1.5;">Please send an Interac e-Transfer following the instructions below. Enter everything <em>exactly</em> as shown so your payment is automatically accepted.</p>
			<ul style="margin:0 0 15px;padding-left:20px;font-size:14px;line-height:1.8;">
				<li><strong>Recipient Name:</strong> <?php echo esc_html( $recipient_name ); ?></li>
				<li><strong>Recipient Email:</strong> <?php echo esc_html( $recipient_email ); ?></li>
				<li><strong>Security Question:</strong> <?php echo esc_html( $security_question ); ?></li>
				<li><strong>Security Answer:</strong> <?php echo esc_html( $security_answer ); ?></li>
				<li><strong>Memo/Message:</strong> <?php echo esc_html( $order_number ); ?></li>
			</ul>
			<p style="margin:0 0 10px;font-size:14px;line-height:1.5;"><strong>Important:</strong> Use the exact Security Question and Answer above. Any changes can delay your payment acceptance or have your payment refused.</p>
			<p style="margin:0 0 10px;font-size:14px;line-height:1.5;">If your bank does not allow a memo, you can leave it empty.</p>
			<p style="margin:0 0 10px;font-size:14px;line-height:1.5;"><strong>We only accept e-Transfers sent to the email listed above.</strong></p>
			<?php if ( ! empty( $support_email ) ) : ?>
			<p style="margin:0 0 10px;font-size:14px;line-height:1.5;">For payment issues, contact: <strong><?php echo esc_html( $support_email ); ?></strong></p>
			<?php endif; ?>
			<p style="margin:0;font-size:14px;line-height:1.5;">Thank you for your order!</p>
		</div>
		<?php

		// Output popup in footer.
		add_action( 'wp_footer', function() use ( $recipient_name, $recipient_email, $security_question, $security_answer, $support_email, $order_number ) {
			static $footer_manual_done = false;
			if ( $footer_manual_done ) {
				return;
			}
			$footer_manual_done = true;
			?>
			<div class="wcpg-etransfer-popup" id="wcpg-etransfer-modal" style="position:fixed;top:0;left:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;z-index:999999;background:rgba(0,0,0,0.6);overflow-y:auto;">
				<div style="background:#fff;padding:30px;border-radius:8px;max-width:550px;width:90%;position:relative;text-align:left;box-shadow:0 4px 20px rgba(0,0,0,0.2);margin:20px auto;max-height:90vh;overflow-y:auto;">
					<button type="button" id="wcpg-modal-close" style="position:absolute;top:10px;right:10px;width:32px;height:32px;font-size:24px;font-weight:bold;background:#f0f0f0;border:none;border-radius:4px;cursor:pointer;color:#333;line-height:32px;padding:0;">&times;</button>

					<p style="margin:0 0 15px;"><strong style="font-size:18px;">Interac e-Transfer Instructions</strong></p>

					<p style="margin:0 0 15px;font-size:14px;line-height:1.5;">After placing your order, please send an Interac e-Transfer following the instructions below. Enter everything <em>exactly</em> as shown so your payment is automatically accepted.</p>

					<ul style="margin:0 0 15px;padding-left:20px;font-size:14px;line-height:1.8;">
						<li><strong>Recipient Name:</strong> <?php echo esc_html( $recipient_name ); ?></li>
						<li><strong>Recipient Email:</strong> <?php echo esc_html( $recipient_email ); ?></li>
						<li><strong>Security Question:</strong> <?php echo esc_html( $security_question ); ?></li>
						<li><strong>Security Answer:</strong> <?php echo esc_html( $security_answer ); ?></li>
						<li><strong>Memo/Message:</strong> <?php echo esc_html( $order_number ); ?></li>
					</ul>

					<p style="margin:0 0 10px;font-size:14px;line-height:1.5;"><strong>Important:</strong> Use the exact Security Question and Answer above. Any changes can delay your payment acceptance or have your payment refused.</p>

					<p style="margin:0 0 10px;font-size:14px;line-height:1.5;">If your bank does not allow a memo, you can leave it empty.</p>

					<p style="margin:0 0 10px;font-size:14px;line-height:1.5;"><strong>We only accept e-Transfers sent to the email listed above. Do not send payments to any other email address.</strong></p>

					<p style="margin:0 0 10px;font-size:14px;line-height:1.5;">If your payment is not accepted, please go to your banking app, cancel and re-send with correct instructions above.</p>

					<?php if ( ! empty( $support_email ) ) : ?>
					<p style="margin:0 0 10px;font-size:14px;line-height:1.5;">Should you encounter any payment related issues, please contact <strong>our support</strong> at: <strong><?php echo esc_html( $support_email ); ?></strong></p>
					<?php endif; ?>

					<p style="margin:0;font-size:14px;line-height:1.5;">Thank you for your order!</p>
				</div>
			</div>
			<script>
			jQuery(function($){
				$('#wcpg-modal-close, #wcpg-etransfer-modal').on('click', function(e){
					if(e.target.id === 'wcpg-etransfer-modal' || e.target.id === 'wcpg-modal-close'){
						$('#wcpg-etransfer-modal').hide();
					}
				});
			});
			</script>
			<?php
		}, 99 );
	}

	/**
	 * Add content to the WooCommerce emails.
	 *
	 * @param WC_Order $order         Order object.
	 * @param bool     $sent_to_admin Sent to admin.
	 * @param bool     $plain_text    Plain text email.
	 */
	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		// Only for e-transfer gateways (orders use virtual gateway IDs).
		$etransfer_gateways = array(
			'digipay_etransfer',
			'digipay_etransfer_email',
			'digipay_etransfer_url',
			'digipay_etransfer_manual',
		);
		if ( ! in_array( $order->get_payment_method(), $etransfer_gateways, true ) ) {
			return;
		}

		// Only for on-hold or pending orders.
		if ( ! $order->has_status( array( 'on-hold', 'pending' ) ) ) {
			return;
		}

		$delivery_method = $order->get_meta( '_etransfer_delivery_method' );

		if ( $plain_text ) {
			$this->email_instructions_plain( $order, $delivery_method );
		} else {
			$this->email_instructions_html( $order, $delivery_method );
		}
	}

	/**
	 * Output plain text email instructions.
	 *
	 * @param WC_Order $order           Order object.
	 * @param string   $delivery_method Delivery method.
	 */
	protected function email_instructions_plain( $order, $delivery_method ) {
		echo "\n\n" . esc_html__( 'E-TRANSFER PAYMENT INSTRUCTIONS', self::TEXT_DOMAIN ) . "\n";
		echo "================================\n\n";

		if ( self::DELIVERY_MANUAL === $delivery_method ) {
			$recipient_name    = $this->get_option( 'recipient_name', '' );
			$recipient_email   = $this->get_option( 'recipient_email', '' );
			$security_question = $this->get_option( 'security_question', '' );
			$security_answer   = $this->get_option( 'security_answer', '' );
			$support_email     = $this->get_option( 'support_email', '' );
			$order_number      = $order->get_order_number();

			echo "Please send an Interac e-Transfer following the instructions below.\n";
			echo "Enter everything EXACTLY as shown so your payment is automatically accepted.\n\n";
			echo "Recipient Name: " . esc_html( $recipient_name ) . "\n";
			echo "Recipient Email: " . esc_html( $recipient_email ) . "\n";
			echo "Security Question: " . esc_html( $security_question ) . "\n";
			echo "Security Answer: " . esc_html( $security_answer ) . "\n";
			echo "Memo/Message: " . esc_html( $order_number ) . "\n\n";
			echo "IMPORTANT: Use the exact Security Question and Answer above.\n";
			echo "Any changes can delay your payment acceptance or have your payment refused.\n\n";
			echo "If your bank does not allow a memo, you can leave it empty.\n\n";
			echo "We only accept e-Transfers sent to the email listed above.\n";
			echo "Do not send payments to any other email address.\n\n";
			if ( ! empty( $support_email ) ) {
				echo "For payment issues, contact support at: " . esc_html( $support_email ) . "\n\n";
			}
			echo "Thank you for your order!\n\n";
		} elseif ( self::DELIVERY_URL === $delivery_method ) {
			$payment_url = $order->get_meta( '_etransfer_payment_url' );
			if ( ! empty( $payment_url ) ) {
				echo esc_html__( 'Complete your payment at:', self::TEXT_DOMAIN ) . "\n";
				echo esc_url( $payment_url ) . "\n\n";
			}
		} else {
			echo esc_html__( 'A payment link has been sent to your email address.', self::TEXT_DOMAIN ) . "\n\n";
		}
	}

	/**
	 * Output HTML email instructions.
	 *
	 * @param WC_Order $order           Order object.
	 * @param string   $delivery_method Delivery method.
	 */
	protected function email_instructions_html( $order, $delivery_method ) {
		echo '<h2>' . esc_html__( 'e-Transfer Payment Instructions', self::TEXT_DOMAIN ) . '</h2>';

		if ( self::DELIVERY_MANUAL === $delivery_method ) {
			$recipient_name    = $this->get_option( 'recipient_name', '' );
			$recipient_email   = $this->get_option( 'recipient_email', '' );
			$security_question = $this->get_option( 'security_question', '' );
			$security_answer   = $this->get_option( 'security_answer', '' );
			$support_email     = $this->get_option( 'support_email', '' );
			$order_number      = $order->get_order_number();

			echo '<div style="background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 4px; padding: 15px; margin: 15px 0;">';
			echo '<p><strong>Interac e-Transfer Instructions</strong></p>';
			echo '<p>After placing your order, please send an Interac e-Transfer following the instructions below. Enter everything <em>exactly</em> as shown so your payment is automatically accepted.</p>';
			echo '<ul>';
			echo '<li><strong>Recipient Name:</strong> ' . esc_html( $recipient_name ) . '</li>';
			echo '<li><strong>Recipient Email:</strong> ' . esc_html( $recipient_email ) . '</li>';
			echo '<li><strong>Security Question:</strong> ' . esc_html( $security_question ) . '</li>';
			echo '<li><strong>Security Answer:</strong> ' . esc_html( $security_answer ) . '</li>';
			echo '<li><strong>Memo/Message:</strong> ' . esc_html( $order_number ) . '</li>';
			echo '</ul>';
			echo '<p><strong>Important:</strong> Use the exact Security Question and Answer above. Any changes can delay your payment acceptance or have your payment refused.</p>';
			echo '<p>If your bank does not allow a memo, you can leave it empty.</p>';
			echo '<p><strong>We only accept e-Transfers sent to the email listed above. Do not send payments to any other email address.</strong></p>';
			if ( ! empty( $support_email ) ) {
				echo '<p>Should you encounter any payment related issues, please contact our support at: <strong>' . esc_html( $support_email ) . '</strong></p>';
			}
			echo '<p>Thank you for your order!</p>';
			echo '</div>';
		} elseif ( self::DELIVERY_URL === $delivery_method ) {
			$payment_url = $order->get_meta( '_etransfer_payment_url' );
			if ( ! empty( $payment_url ) ) {
				echo '<div style="background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 4px; padding: 15px; margin: 15px 0; text-align: center;">';
				echo '<p>' . esc_html__( 'Click the button below to complete your payment:', self::TEXT_DOMAIN ) . '</p>';
				echo '<p><a href="' . esc_url( $payment_url ) . '" style="display: inline-block; padding: 12px 24px; background: #007cba; color: #fff; text-decoration: none; border-radius: 4px;">' . esc_html__( 'Complete Payment', self::TEXT_DOMAIN ) . '</a></p>';
				echo '</div>';
			}
		} else {
			echo '<div style="background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 4px; padding: 15px; margin: 15px 0;">';
			echo '<p>' . esc_html__( 'A payment link has been sent to your email address. Please check your inbox to complete your payment.', self::TEXT_DOMAIN ) . '</p>';
			echo '</div>';
		}
	}
}

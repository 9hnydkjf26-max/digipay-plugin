<?php
/**
 * E-Transfer API Client
 *
 * Handles OAuth2 authentication and API communication for E-Transfer payments.
 *
 * @package DigipayMasterPlugin
 * @since 12.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API Client Class for E-Transfer Gateway.
 */
class WCPG_ETransfer_API_Client {

	/**
	 * OAuth client ID.
	 *
	 * @var string
	 */
	private $client_id;

	/**
	 * OAuth client secret.
	 *
	 * @var string
	 */
	private $client_secret;

	/**
	 * API endpoint URL.
	 *
	 * @var string
	 */
	private $api_endpoint;

	/**
	 * Account UUID.
	 *
	 * @var string
	 */
	private $account_uuid;

	/**
	 * OAuth access token.
	 *
	 * @var string|null
	 */
	private $access_token;

	/**
	 * Token expiry timestamp.
	 *
	 * @var int|null
	 */
	private $token_expiry;

	/**
	 * Option name for storing OAuth token.
	 *
	 * @var string
	 */
	private $token_option_name = 'wcpg_etransfer_oauth_token';

	/**
	 * Constructor.
	 *
	 * @param string $client_id     OAuth client ID.
	 * @param string $client_secret OAuth client secret.
	 * @param string $api_endpoint  API endpoint URL.
	 * @param string $account_uuid  Account UUID.
	 */
	public function __construct( $client_id, $client_secret, $api_endpoint, $account_uuid ) {
		$this->client_id     = $client_id;
		$this->client_secret = $client_secret;
		$this->api_endpoint  = rtrim( $api_endpoint, '/' );
		$this->account_uuid  = $account_uuid;
		$this->load_token();
	}

	/**
	 * Load saved token from WordPress transients.
	 */
	private function load_token() {
		if ( ! function_exists( 'get_transient' ) ) {
			return;
		}

		$token_data = get_transient( $this->token_option_name );
		if ( $token_data && is_array( $token_data ) ) {
			$this->access_token = isset( $token_data['access_token'] ) ? $token_data['access_token'] : null;
			$this->token_expiry = isset( $token_data['expiry'] ) ? $token_data['expiry'] : null;
		}
	}

	/**
	 * Save token data to WordPress transients.
	 *
	 * @param string $access_token The access token.
	 * @param int    $expires_in   Expiration time in seconds.
	 */
	private function save_token( $access_token, $expires_in ) {
		$this->access_token = $access_token;
		$this->token_expiry = time() + $expires_in;

		if ( function_exists( 'set_transient' ) ) {
			set_transient(
				$this->token_option_name,
				array(
					'access_token' => $access_token,
					'expiry'       => $this->token_expiry,
				),
				$expires_in
			);
		}
	}

	/**
	 * Check if the current token is valid.
	 *
	 * @return bool
	 */
	private function is_token_valid() {
		if ( empty( $this->access_token ) || empty( $this->token_expiry ) ) {
			return false;
		}
		// Add 60 second buffer for token expiry.
		return $this->token_expiry > ( time() + 60 );
	}

	/**
	 * Get the base URL from the API endpoint.
	 *
	 * @return string Base URL (e.g., https://api.example.com).
	 */
	public function get_base_url() {
		$parsed = parse_url( $this->api_endpoint );
		$base   = $parsed['scheme'] . '://' . $parsed['host'];
		if ( isset( $parsed['port'] ) ) {
			$base .= ':' . $parsed['port'];
		}
		return $base;
	}

	/**
	 * Get a valid access token, requesting a new one if necessary.
	 *
	 * @return string|WP_Error Access token or WP_Error on failure.
	 */
	public function get_access_token() {
		if ( $this->is_token_valid() ) {
			return $this->access_token;
		}

		// Clear any stale cached token before requesting a new one.
		$this->clear_token();

		$base_url = $this->get_base_url();
		$endpoint = $base_url . '/oauth/token';

		wc_get_logger()->info( 'E-Transfer OAuth: Requesting token from ' . $endpoint . ' with client_id: ' . substr( $this->client_id, 0, 8 ) . '...', array( 'source' => 'digipay-etransfer' ) );

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'grant_type'    => 'client_credentials',
						'client_id'     => $this->client_id,
						'client_secret' => $this->client_secret,
						'scope'         => '*',
					)
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			wc_get_logger()->error( 'E-Transfer OAuth: Request failed - ' . $response->get_error_message(), array( 'source' => 'digipay-etransfer' ) );
			return $response;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = json_decode( wp_remote_retrieve_body( $response ), true );

		wc_get_logger()->info( 'E-Transfer OAuth: HTTP ' . $http_code . ' | Response keys: ' . ( is_array( $body ) ? implode( ', ', array_keys( $body ) ) : 'invalid JSON' ), array( 'source' => 'digipay-etransfer' ) );

		if ( ! isset( $body['access_token'] ) || ! isset( $body['expires_in'] ) ) {
			$error_detail = isset( $body['message'] ) ? $body['message'] : ( isset( $body['error'] ) ? $body['error'] : 'No access_token in response' );
			return new WP_Error( 'invalid_oauth_response', __( 'OAuth authentication failed: ', 'wc-payment-gateway' ) . $error_detail );
		}

		$this->save_token( $body['access_token'], $body['expires_in'] );
		return $this->access_token;
	}

	/**
	 * Get common headers for API requests.
	 *
	 * @return array|WP_Error Headers array or WP_Error on failure.
	 */
	private function get_headers() {
		$access_token = $this->get_access_token();

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		return array(
			'Authorization' => 'Bearer ' . $access_token,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		);
	}

	/**
	 * Request e-transfer payment link.
	 *
	 * @param array  $order_data      Order data (email, name, total, order_number).
	 * @param string $delivery_method Delivery method (email or url).
	 * @return array|WP_Error Response from API or WP_Error on failure.
	 */
	public function request_etransfer_link( $order_data, $delivery_method = 'Email', $is_retry = false ) {
		$endpoint = $this->api_endpoint . '/payment-types/interac/e-transfers/request-etransfer-link';

		$headers = $this->get_headers();
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$body = array(
			'account_uuid' => $this->account_uuid,
			'email'        => $order_data['email'],
			'name'         => $order_data['name'],
			'amount'       => (float) $order_data['total'],
			'currency'     => isset( $order_data['currency'] ) ? $order_data['currency'] : 'CAD',
			'description'  => isset( $order_data['description'] ) ? $this->sanitize_description( $order_data['description'] ) : '',
		);

		// Only include delivery_method for Email - omitting it defaults to API mode which returns URL.
		if ( 'email' === strtolower( $delivery_method ) ) {
			$body['delivery_method'] = 'Email';
		}

		wc_get_logger()->info( 'E-Transfer API: POST ' . $endpoint . ' | retry=' . ( $is_retry ? 'yes' : 'no' ), array( 'source' => 'digipay-etransfer' ) );

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$http_code     = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		// If unauthenticated and haven't retried yet, clear token cache and retry once.
		if ( ! $is_retry && ( 401 === $http_code || ( isset( $data['message'] ) && false !== stripos( $data['message'], 'unauthenticated' ) ) ) ) {
			wc_get_logger()->info( 'E-Transfer API: Got Unauthenticated (HTTP ' . $http_code . '), clearing token and retrying...', array( 'source' => 'digipay-etransfer' ) );
			$this->clear_token();
			return $this->request_etransfer_link( $order_data, $delivery_method, true );
		}

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'invalid_response', __( 'Invalid response from payment gateway', 'wc-payment-gateway' ) );
		}

		return $data;
	}

	/**
	 * Sanitize description for API request.
	 *
	 * @param string $description Raw description.
	 * @return string Sanitized description.
	 */
	private function sanitize_description( $description ) {
		// Remove characters not allowed by the API.
		return preg_replace( '/[^a-zA-Z0-9\s.#,$@\']/', ' ', $description );
	}

	/**
	 * Authenticate user with gateway credentials.
	 *
	 * @param string $email    User email.
	 * @param string $password User password.
	 * @return array|WP_Error Response array or WP_Error on failure.
	 */
	public function authenticate_user( $email, $password ) {
		$base_url = $this->get_base_url();
		$endpoint = $base_url . '/api/v1/login';

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'email'    => $email,
						'password' => $password,
					)
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'invalid_response', __( 'Invalid response from login server', 'wc-payment-gateway' ) );
		}

		return $data;
	}

	/**
	 * Clear stored token (for testing or logout).
	 */
	public function clear_token() {
		$this->access_token = null;
		$this->token_expiry = null;

		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( $this->token_option_name );
		}
	}
}

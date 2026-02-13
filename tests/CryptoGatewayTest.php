<?php
/**
 * Tests for WCPG_Gateway_Crypto class.
 *
 * @package Digipay
 */

class CryptoGatewayTest extends DigipayTestCase {

	/**
	 * Test that the crypto gateway class exists and has correct ID.
	 */
	public function test_gateway_has_correct_id() {
		$gateway = new WCPG_Gateway_Crypto();
		$this->assertSame( 'wcpg_crypto', $gateway->id );
	}

	/**
	 * Test constructor sets method_title.
	 */
	public function test_constructor_sets_method_title() {
		$gateway = new WCPG_Gateway_Crypto();
		$this->assertSame( 'Crypto', $gateway->method_title );
	}

	/**
	 * Test constructor sets has_fields to true.
	 */
	public function test_constructor_sets_has_fields() {
		$gateway = new WCPG_Gateway_Crypto();
		$this->assertTrue( $gateway->has_fields );
	}

	/**
	 * Test init_form_fields defines the expected field keys.
	 */
	public function test_init_form_fields_has_expected_keys() {
		$gateway = new WCPG_Gateway_Crypto();
		$gateway->init_form_fields();
		$fields = $gateway->get_form_fields();
		$expected_keys = array( 'enabled', 'title', 'public_key', 'private_key', 'checkout_url', 'traded_currency', 'expire_time', 'collect_name', 'collect_email', 'integration_method' );
		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $fields, "Missing form field: $key" );
		}
	}

	/**
	 * Test is_available returns false when no API keys or fallback URL configured.
	 */
	public function test_is_available_returns_false_without_config() {
		$gateway = new WCPG_Gateway_Crypto();
		$this->assertFalse( $gateway->is_available() );
	}

	/**
	 * Test is_available returns false when enabled but no API keys or fallback URL.
	 */
	public function test_is_available_false_when_enabled_but_no_keys() {
		$gateway = new WCPG_Gateway_Crypto();
		$gateway->enabled = 'yes';
		$this->assertFalse( $gateway->is_available() );
	}

	/**
	 * Test is_available returns true when enabled and API keys are configured.
	 */
	public function test_is_available_true_with_api_keys() {
		$gateway = new WCPG_Gateway_Crypto();
		$gateway->enabled  = 'yes';
		$gateway->settings = array(
			'public_key'  => 'test_pub_key',
			'private_key' => 'test_priv_key',
		);
		$this->assertTrue( $gateway->is_available() );
	}

	/**
	 * Test handle_webhook returns 400 when no order ID in request.
	 */
	public function test_handle_webhook_returns_400_without_order_id() {
		$gateway = new WCPG_Gateway_Crypto();
		$request = new WP_REST_Request_Mock( array() );
		$response = $gateway->handle_webhook( $request );
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Test payment_fields outputs crypto info when configured.
	 */
	public function test_payment_fields_outputs_info_when_configured() {
		$gateway = new WCPG_Gateway_Crypto();
		$gateway->settings = array(
			'public_key'  => 'test_pub_key',
			'private_key' => 'test_priv_key',
		);
		ob_start();
		$gateway->payment_fields();
		$output = ob_get_clean();
		$this->assertStringContainsString( 'Pay with Cryptocurrency', $output );
	}

	/**
	 * Test process_payment returns fail when order not found.
	 */
	public function test_process_payment_fails_when_order_not_found() {
		$gateway = new WCPG_Gateway_Crypto();
		$result = $gateway->process_payment( 99999 );
		$this->assertSame( 'fail', $result['result'] );
	}

	/**
	 * Test that unverified webhook methods are not present (pending Finvaro SDK confirmation).
	 */
	public function test_no_verify_webhook_signature_method() {
		$this->assertFalse( method_exists( 'WCPG_Gateway_Crypto', 'verify_webhook_signature' ) );
	}

	/**
	 * Test crypto block class exists and has correct name.
	 */
	public function test_crypto_block_class_exists_with_correct_name() {
		$block = new WCPG_Crypto_Gateway_Block();
		$this->assertSame( 'wcpg_crypto', $block->get_name() );
	}

	public function test_no_build_checkout_body_method() {
		$this->assertFalse( method_exists( 'WCPG_Gateway_Crypto', 'build_checkout_body' ) );
	}
}

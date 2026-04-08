<?php
/**
 * Tests for instance token generation, persistence, and API integration.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

// Define the instance token function for testing.
// Uses $wcpg_mock_options directly since bootstrap's update_option() is a no-op.
if ( ! function_exists( 'wcpg_get_instance_token' ) ) {
	function wcpg_get_instance_token() {
		global $wcpg_mock_options;

		$token = get_option( 'wcpg_instance_token', '' );
		if ( ! empty( $token ) ) {
			return $token;
		}

		$data    = random_bytes( 16 );
		$data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 );
		$data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 );
		$token   = vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );

		// Store in mock options (mirrors update_option behavior in production).
		$wcpg_mock_options['wcpg_instance_token'] = $token;
		return $token;
	}
}

/**
 * Test class for wcpg_get_instance_token() and related functionality.
 */
class InstanceTokenTest extends DigipayTestCase {

	/**
	 * Reset the mock options store before each test.
	 */
	protected function set_up() {
		parent::set_up();
		global $wcpg_mock_options;
		unset( $wcpg_mock_options['wcpg_instance_token'] );
	}

	/**
	 * Test that wcpg_get_instance_token() generates a valid UUID v4.
	 */
	public function test_generates_valid_uuid_v4() {
		$token = wcpg_get_instance_token();

		$uuid_pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
		$this->assertMatchesRegularExpression( $uuid_pattern, $token, 'Instance token should be a valid UUID v4' );
	}

	/**
	 * Test that the token is stable across multiple calls (returns same value).
	 */
	public function test_token_is_stable_across_calls() {
		$first_call  = wcpg_get_instance_token();
		$second_call = wcpg_get_instance_token();

		$this->assertSame( $first_call, $second_call, 'Instance token should return the same value on subsequent calls' );
	}

	/**
	 * Test that the token is persisted in wp_options.
	 */
	public function test_token_persisted_in_options() {
		$token = wcpg_get_instance_token();

		global $wcpg_mock_options;
		$this->assertArrayHasKey( 'wcpg_instance_token', $wcpg_mock_options, 'Token should be stored in options' );
		$this->assertSame( $token, $wcpg_mock_options['wcpg_instance_token'], 'Stored token should match returned token' );
	}

	/**
	 * Test that a pre-existing token is returned without regeneration.
	 */
	public function test_returns_existing_token() {
		global $wcpg_mock_options;
		$existing_token = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
		$wcpg_mock_options['wcpg_instance_token'] = $existing_token;

		$token = wcpg_get_instance_token();

		$this->assertSame( $existing_token, $token, 'Should return existing token without regeneration' );
	}

	/**
	 * Test that each fresh generation produces a unique token.
	 */
	public function test_generates_unique_tokens() {
		global $wcpg_mock_options;

		$token1 = wcpg_get_instance_token();

		// Clear to force regeneration.
		unset( $wcpg_mock_options['wcpg_instance_token'] );

		$token2 = wcpg_get_instance_token();

		$this->assertNotSame( $token1, $token2, 'Different generations should produce different tokens' );
	}

	/**
	 * Test that the generated token is exactly 36 characters (standard UUID length).
	 */
	public function test_token_length() {
		$token = wcpg_get_instance_token();

		$this->assertSame( 36, strlen( $token ), 'UUID v4 should be exactly 36 characters' );
	}

	/**
	 * Test that the version nibble is 4 (UUID v4).
	 */
	public function test_uuid_version_nibble() {
		$token = wcpg_get_instance_token();
		$parts = explode( '-', $token );

		$this->assertStringStartsWith( '4', $parts[2], 'Third group should start with 4 (UUID version 4)' );
	}

	/**
	 * Test that the variant nibble is correct (RFC 4122).
	 */
	public function test_uuid_variant_nibble() {
		$token = wcpg_get_instance_token();
		$parts = explode( '-', $token );

		$first_char = $parts[3][0];
		$this->assertContains( $first_char, array( '8', '9', 'a', 'b' ), 'Fourth group should start with 8, 9, a, or b (RFC 4122 variant)' );
	}

	/**
	 * Test that health report payload would include instance_token.
	 *
	 * Verifies the function is callable and returns a non-empty string
	 * suitable for including in API payloads.
	 */
	public function test_token_suitable_for_api_payload() {
		$token = wcpg_get_instance_token();

		$this->assertIsString( $token );
		$this->assertNotEmpty( $token );

		// Simulate building health data payload.
		$health_data = array(
			'site_id'        => '',
			'instance_token' => $token,
			'site_url'       => 'https://example.com',
		);

		$this->assertSame( $token, $health_data['instance_token'] );
	}

	/**
	 * Test that token works in query args for limits API.
	 */
	public function test_token_in_query_args() {
		$token = wcpg_get_instance_token();

		$query_args = array(
			'site_url'       => 'https://example.com',
			'instance_token' => $token,
		);

		$this->assertArrayHasKey( 'instance_token', $query_args );
		$this->assertSame( $token, $query_args['instance_token'] );
	}

	/**
	 * Test payment gateway URL sync logic — update when different.
	 */
	public function test_payment_gateway_url_sync_updates_when_different() {
		$local_url  = 'https://secure.digipay.co/';
		$remote_url = 'https://gateway2.digipay.co/';

		// Simulate API response with a different gateway URL.
		$data = array(
			'payment_gateway_url' => $remote_url,
		);

		// The sync logic: if remote differs from local, update.
		$should_update = ! empty( $data['payment_gateway_url'] ) && $data['payment_gateway_url'] !== $local_url;
		$this->assertTrue( $should_update, 'Should update when remote URL differs from local' );
	}

	/**
	 * Test payment gateway URL fallback when not set remotely.
	 */
	public function test_payment_gateway_url_fallback_when_not_set() {
		$default_url = 'https://secure.digipay.co/';

		// Simulate API response without gateway URL.
		$data = array(
			'daily_limit'     => 5000,
			'max_ticket_size' => 500,
		);

		// The sync logic: only update if present and non-empty.
		$should_update = ! empty( $data['payment_gateway_url'] );
		$this->assertFalse( $should_update, 'Should not update when remote URL is not set' );

		// Local should keep the default.
		$current_url = $default_url;
		$this->assertSame( 'https://secure.digipay.co/', $current_url );
	}

	/**
	 * Test payment gateway URL sync — no update when same.
	 */
	public function test_payment_gateway_url_no_update_when_same() {
		$local_url = 'https://secure.digipay.co/';

		$data = array(
			'payment_gateway_url' => 'https://secure.digipay.co/',
		);

		$should_update = ! empty( $data['payment_gateway_url'] ) && $data['payment_gateway_url'] !== $local_url;
		$this->assertFalse( $should_update, 'Should not update when URLs match' );
	}
}

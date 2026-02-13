<?php
/**
 * Tests for API Client token storage using transients.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test that API Client uses transients (not options) for OAuth token storage.
 */
class ApiClientTransientTest extends DigipayTestCase {

	protected function set_up() {
		parent::set_up();
		global $wcpg_test_transients;
		$wcpg_test_transients = array();
	}

	public function test_save_token_stores_access_token() {
		$client = new WCPG_ETransfer_API_Client( 'id', 'secret', 'https://api.example.com/api/v1', 'uuid' );

		// Use reflection to call private save_token method.
		$ref = new ReflectionMethod( $client, 'save_token' );
		$ref->invoke( $client, 'test_access_token', 3600 );

		// Verify the token is stored on the object.
		$token_ref = new ReflectionProperty( $client, 'access_token' );
		$this->assertSame( 'test_access_token', $token_ref->getValue( $client ) );

		$expiry_ref = new ReflectionProperty( $client, 'token_expiry' );
		$this->assertGreaterThan( time(), $expiry_ref->getValue( $client ) );
	}

	public function test_save_token_uses_set_transient_with_ttl() {
		global $wcpg_test_transients;

		$client = new WCPG_ETransfer_API_Client( 'id', 'secret', 'https://api.example.com/api/v1', 'uuid' );

		$ref = new ReflectionMethod( $client, 'save_token' );
		$ref->invoke( $client, 'test_access_token', 3600 );

		// Token should be stored as a transient with TTL matching expires_in.
		$this->assertArrayHasKey( 'wcpg_etransfer_oauth_token', $wcpg_test_transients );
		$stored = $wcpg_test_transients['wcpg_etransfer_oauth_token'];
		$this->assertSame( 'test_access_token', $stored['value']['access_token'] );
		$this->assertSame( 3600, $stored['expiration'] );
	}
}

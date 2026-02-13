<?php
/**
 * Tests for API Client load_token using transients.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test that API Client loads token from transient.
 */
class ApiClientLoadTokenTest extends DigipayTestCase {

	protected function set_up() {
		parent::set_up();
		global $wcpg_test_transients;
		$wcpg_test_transients = array();
	}

	public function test_load_token_uses_get_transient() {
		global $wcpg_test_transients;

		// Pre-populate transient before constructing client.
		$wcpg_test_transients['wcpg_etransfer_oauth_token'] = array(
			'value'      => array(
				'access_token' => 'preloaded_token',
				'expiry'       => time() + 3600,
			),
			'expiration' => 3600,
		);

		$client = new WCPG_ETransfer_API_Client( 'id', 'secret', 'https://api.example.com/api/v1', 'uuid' );

		// Token should have been loaded from transient during construction.
		$ref = new ReflectionProperty( $client, 'access_token' );
		$this->assertSame( 'preloaded_token', $ref->getValue( $client ) );
	}
}

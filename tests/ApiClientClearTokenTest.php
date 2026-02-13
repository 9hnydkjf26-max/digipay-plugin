<?php
/**
 * Tests for API Client clear_token using transients.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test that API Client clears token using delete_transient.
 */
class ApiClientClearTokenTest extends DigipayTestCase {

	protected function set_up() {
		parent::set_up();
		global $wcpg_test_transients;
		$wcpg_test_transients = array();
	}

	public function test_clear_token_uses_delete_transient() {
		global $wcpg_test_transients;

		// Pre-populate transient.
		$wcpg_test_transients['wcpg_etransfer_oauth_token'] = array(
			'value'      => array(
				'access_token' => 'to_be_cleared',
				'expiry'       => time() + 3600,
			),
			'expiration' => 3600,
		);

		$client = new WCPG_ETransfer_API_Client( 'id', 'secret', 'https://api.example.com/api/v1', 'uuid' );
		$client->clear_token();

		$this->assertArrayNotHasKey( 'wcpg_etransfer_oauth_token', $wcpg_test_transients );
	}
}

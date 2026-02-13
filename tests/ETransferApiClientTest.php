<?php
/**
 * Tests for the E-Transfer API Client.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for E-Transfer API Client.
 */
class ETransferApiClientTest extends DigipayTestCase {

	private function create_client( $endpoint = 'https://api.example.com/api/v1' ) {
		return new WCPG_ETransfer_API_Client( 'client_id', 'secret', $endpoint, 'uuid' );
	}

	public function test_api_client_class_exists() {
		$this->assertTrue(
			class_exists( 'WCPG_ETransfer_API_Client' ),
			'WCPG_ETransfer_API_Client class should exist'
		);
	}

	public function test_api_client_instantiation() {
		$this->assertInstanceOf( WCPG_ETransfer_API_Client::class, $this->create_client() );
	}

	public function test_get_base_url() {
		$this->assertSame( 'https://api.example.com', $this->create_client()->get_base_url() );
	}

	public function test_get_base_url_with_port() {
		$client = $this->create_client( 'http://localhost:8000/api/v1' );
		$this->assertSame( 'http://localhost:8000', $client->get_base_url() );
	}
}

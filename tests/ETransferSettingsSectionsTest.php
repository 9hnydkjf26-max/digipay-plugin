<?php
/**
 * Tests for E-Transfer settings section grouping.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for E-Transfer settings sections.
 */
class ETransferSettingsSectionsTest extends DigipayTestCase {

	private $gateway;
	private $fields;

	protected function set_up() {
		parent::set_up();
		$this->gateway = new WC_Gateway_ETransfer();
		$this->fields  = $this->gateway->get_form_fields();
	}

	private $valid_sections = array( 'gateway', 'request_money', 'send_money', 'api' );

	/**
	 * Every form field must have a 'section' key.
	 */
	public function test_all_fields_have_section_attribute() {
		foreach ( $this->fields as $key => $field ) {
			$this->assertArrayHasKey(
				'section',
				$field,
				"Field '{$key}' is missing a 'section' attribute"
			);
		}
	}

	/**
	 * Every section value must be one of the four valid sections.
	 */
	public function test_all_sections_are_valid() {
		foreach ( $this->fields as $key => $field ) {
			$this->assertContains(
				$field['section'],
				$this->valid_sections,
				"Field '{$key}' has invalid section '{$field['section']}'"
			);
		}
	}

	/**
	 * Gateway section contains the correct fields.
	 */
	public function test_gateway_section_fields() {
		$expected = array( 'enabled', 'delivery_method', 'enable_manual', 'order_status' );
		$actual   = array();
		foreach ( $this->fields as $key => $field ) {
			if ( isset( $field['section'] ) && 'gateway' === $field['section'] ) {
				$actual[] = $key;
			}
		}

		foreach ( $expected as $key ) {
			$this->assertContains( $key, $actual, "Field '{$key}' should be in the gateway section" );
		}
	}

	/**
	 * Request Money section contains the correct fields.
	 */
	public function test_request_money_section_fields() {
		$expected = array(
			'title_api', 'description_api', 'instructions_email', 'instructions_url',
			'require_login', 'popup_title', 'popup_body', 'button_text',
		);
		$actual = array();
		foreach ( $this->fields as $key => $field ) {
			if ( isset( $field['section'] ) && 'request_money' === $field['section'] ) {
				$actual[] = $key;
			}
		}

		foreach ( $expected as $key ) {
			$this->assertContains( $key, $actual, "Field '{$key}' should be in the request_money section" );
		}
	}

	/**
	 * Send Money section contains the correct fields.
	 */
	public function test_send_money_section_fields() {
		$expected = array(
			'title_manual', 'description_manual', 'instructions_manual',
			'recipient_name', 'recipient_email', 'security_question', 'security_answer',
		);
		$actual = array();
		foreach ( $this->fields as $key => $field ) {
			if ( isset( $field['section'] ) && 'send_money' === $field['section'] ) {
				$actual[] = $key;
			}
		}

		foreach ( $expected as $key ) {
			$this->assertContains( $key, $actual, "Field '{$key}' should be in the send_money section" );
		}
	}

	/**
	 * API section contains the correct fields.
	 */
	public function test_api_section_fields() {
		$expected = array( 'api_endpoint', 'account_uuid', 'client_id', 'client_secret', 'api_description_prefix' );
		$actual   = array();
		foreach ( $this->fields as $key => $field ) {
			if ( isset( $field['section'] ) && 'api' === $field['section'] ) {
				$actual[] = $key;
			}
		}

		foreach ( $expected as $key ) {
			$this->assertContains( $key, $actual, "Field '{$key}' should be in the api section" );
		}
	}

	/**
	 * No 'title' type fields should remain — section headers are now collapsible containers.
	 */
	public function test_no_title_type_fields() {
		foreach ( $this->fields as $key => $field ) {
			$this->assertNotEquals(
				'title',
				isset( $field['type'] ) ? $field['type'] : '',
				"Field '{$key}' is a 'title' type; these should be removed in favor of collapsible container headers"
			);
		}
	}
}

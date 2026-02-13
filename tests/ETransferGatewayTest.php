<?php
/**
 * Tests for the E-Transfer Gateway.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for E-Transfer Gateway.
 */
class ETransferGatewayTest extends DigipayTestCase {

	private $gateway;
	private $fields;

	protected function set_up() {
		parent::set_up();
		$this->gateway = new WC_Gateway_ETransfer();
		$this->fields  = $this->gateway->get_form_fields();
	}

	public function test_gateway_class_exists() {
		$this->assertTrue(
			class_exists( 'WC_Gateway_ETransfer' ),
			'WC_Gateway_ETransfer class should exist'
		);
	}

	public function test_gateway_has_id_constant() {
		$this->assertTrue(
			defined( 'WC_Gateway_ETransfer::GATEWAY_ID' ),
			'WC_Gateway_ETransfer should have GATEWAY_ID constant'
		);
		$this->assertSame( 'digipay_etransfer', WC_Gateway_ETransfer::GATEWAY_ID );
	}

	public function test_delivery_method_constants() {
		$this->assertSame( 'email', WC_Gateway_ETransfer::DELIVERY_EMAIL );
		$this->assertSame( 'url', WC_Gateway_ETransfer::DELIVERY_URL );
		$this->assertSame( 'manual', WC_Gateway_ETransfer::DELIVERY_MANUAL );
	}

	public function test_form_fields_defined() {
		$this->assertIsArray( $this->fields, 'Form fields should be an array' );
		$this->assertNotEmpty( $this->fields, 'Form fields should not be empty' );
	}

	public function test_required_form_fields_exist() {
		$required_fields = array(
			'enabled',
			'delivery_method',
			'enable_manual',
			'title_api',
			'title_manual',
			'description_api',
			'description_manual',
			'instructions_email',
			'instructions_url',
			'instructions_manual',
			'api_endpoint',
			'account_uuid',
			'client_id',
			'client_secret',
			'api_description_prefix',
			'recipient_email',
			'order_status',
		);

		foreach ( $required_fields as $field ) {
			$this->assertArrayHasKey( $field, $this->fields, "Form field '{$field}' should exist" );
		}
	}

	public function test_delivery_method_options() {
		$this->assertArrayHasKey( 'options', $this->fields['delivery_method'] );
		$options = $this->fields['delivery_method']['options'];

		$this->assertArrayHasKey( 'email', $options, 'Request Money Email option should exist' );
		$this->assertArrayHasKey( 'url', $options, 'Request Money URL option should exist' );
		$this->assertArrayHasKey( 'none', $options, 'Disabled option should exist' );
	}

	public function test_order_status_options() {
		$this->assertArrayHasKey( 'options', $this->fields['order_status'] );
		$options = $this->fields['order_status']['options'];

		$this->assertArrayHasKey( 'on-hold', $options );
		$this->assertArrayHasKey( 'pending', $options );
		$this->assertArrayHasKey( 'processing', $options );
	}

	public function test_generate_secret_answer_format() {
		$answer = $this->gateway->generate_secret_answer();

		$this->assertIsString( $answer );
		$this->assertEquals( 6, strlen( $answer ), 'Secret answer should be 6 characters' );
		$this->assertMatchesRegularExpression( '/^[a-z0-9]+$/', $answer, 'Secret answer should be alphanumeric lowercase' );
	}

	public function test_format_instructions_replaces_placeholders() {
		$result = $this->gateway->format_instructions( 'Order {1} with answer {2}', '12345', 'abc123' );
		$this->assertSame( 'Order 12345 with answer abc123', $result );
	}

	public function test_gateway_extends_wc_payment_gateway() {
		$this->assertInstanceOf( 'WC_Payment_Gateway', $this->gateway );
	}

	public function test_gateway_id_is_set() {
		$this->assertSame( 'digipay_etransfer', $this->gateway->id );
	}

	public function test_gateway_method_title_is_set() {
		$this->assertNotEmpty( $this->gateway->method_title );
	}

	public function test_gateway_supports_products() {
		$this->assertTrue( $this->gateway->supports( 'products' ) );
	}

	public function test_is_available_false_when_api_credentials_missing() {
		$this->gateway->settings['delivery_method'] = 'email';
		$this->gateway->settings['api_endpoint']    = '';
		$this->gateway->settings['account_uuid']    = '';
		$this->gateway->settings['client_id']       = '';
		$this->gateway->settings['client_secret']   = '';
		$this->gateway->settings['enabled']         = 'yes';

		$this->assertFalse( $this->gateway->is_available() );
	}

	public function test_is_available_false_when_manual_email_missing() {
		$this->gateway->settings['delivery_method'] = 'manual';
		$this->gateway->settings['recipient_email'] = '';
		$this->gateway->settings['enabled']         = 'yes';

		$this->assertFalse( $this->gateway->is_available() );
	}

	public function test_get_status_note_contains_delivery_method_name() {
		$method = new ReflectionMethod( $this->gateway, 'get_status_note' );

		foreach ( array( 'email', 'url', 'manual' ) as $delivery_method ) {
			$note = $method->invoke( $this->gateway, $delivery_method );
			$this->assertStringContainsString(
				$delivery_method,
				strtolower( $note ),
				"Status note for '{$delivery_method}' should contain the method name"
			);
		}
	}

	public function test_default_instructions_contain_placeholders() {
		$instructions = $this->gateway->get_default_instructions();

		$this->assertStringContainsString( '{1}', $instructions );
		$this->assertStringContainsString( '{2}', $instructions );
	}

	public function test_display_settings_fields_exist() {
		$display_fields = array(
			'popup_title',
			'popup_body',
			'button_text',
		);

		foreach ( $display_fields as $field ) {
			$this->assertArrayHasKey( $field, $this->fields, "Display field '{$field}' should exist" );
		}
	}

	public function test_required_methods_exist() {
		$required_methods = array(
			'process_payment',
			'thankyou_page',
			'email_instructions',
			'is_available',
			'process_payment_for_delivery',
		);

		foreach ( $required_methods as $method_name ) {
			$this->assertTrue(
				method_exists( $this->gateway, $method_name ),
				"{$method_name} method should exist"
			);
		}
	}

	public function test_enable_manual_field_is_select() {
		$this->assertArrayHasKey( 'enable_manual', $this->fields );
		$this->assertSame( 'select', $this->fields['enable_manual']['type'] );
		$this->assertSame( 'no', $this->fields['enable_manual']['default'] );
		$this->assertArrayHasKey( 'options', $this->fields['enable_manual'] );
		$this->assertArrayHasKey( 'no', $this->fields['enable_manual']['options'] );
		$this->assertArrayHasKey( 'yes', $this->fields['enable_manual']['options'] );
	}

	public function test_per_method_title_fields_exist() {
		$this->assertArrayHasKey( 'title_api', $this->fields, 'Request Money title field should exist' );
		$this->assertArrayHasKey( 'title_manual', $this->fields, 'Send Money title field should exist' );
		$this->assertSame( 'text', $this->fields['title_api']['type'] );
		$this->assertSame( 'text', $this->fields['title_manual']['type'] );
	}

	public function test_master_gateway_is_available_returns_false_even_with_credentials() {
		$this->gateway->enabled                       = 'yes';
		$this->gateway->settings['enabled']           = 'yes';
		$this->gateway->settings['delivery_method']   = 'email';
		$this->gateway->settings['api_endpoint']      = 'https://api.example.com';
		$this->gateway->settings['account_uuid']      = 'test-uuid';
		$this->gateway->settings['client_id']         = 'test-client-id';
		$this->gateway->settings['client_secret']     = 'test-client-secret';

		$this->assertFalse( $this->gateway->is_available(), 'Master gateway should never be available at checkout even with valid credentials' );
	}

	public function test_has_api_credentials_returns_true_when_set() {
		$this->gateway->settings['api_endpoint']    = 'https://api.example.com';
		$this->gateway->settings['account_uuid']    = 'test-uuid';
		$this->gateway->settings['client_id']       = 'test-client-id';
		$this->gateway->settings['client_secret']   = 'test-client-secret';

		$this->assertTrue( $this->gateway->has_api_credentials() );
	}

	public function test_has_api_credentials_returns_false_when_missing() {
		$this->gateway->settings['api_endpoint'] = 'https://api.example.com';

		$this->assertFalse( $this->gateway->has_api_credentials() );
	}

	public function test_has_manual_settings_returns_true_when_set() {
		$this->gateway->settings['recipient_name']     = 'Test Store';
		$this->gateway->settings['recipient_email']    = 'payments@example.com';
		$this->gateway->settings['security_question']  = 'Favorite sport?';
		$this->gateway->settings['security_answer']    = 'Hockey';

		$this->assertTrue( $this->gateway->has_manual_settings() );
	}

	public function test_instructions_fields_are_wysiwyg_type() {
		$this->assertSame( 'wysiwyg', $this->fields['instructions_email']['type'], 'Email instructions should be wysiwyg type' );
		$this->assertSame( 'wysiwyg', $this->fields['instructions_url']['type'], 'URL instructions should be wysiwyg type' );
		$this->assertSame( 'wysiwyg', $this->fields['instructions_manual']['type'], 'Manual instructions should be wysiwyg type' );
	}

	public function test_generate_wysiwyg_html_method_exists() {
		$this->assertTrue(
			method_exists( $this->gateway, 'generate_wysiwyg_html' ),
			'Gateway should have generate_wysiwyg_html method for WYSIWYG field rendering'
		);
	}

	public function test_support_email_not_in_form_fields() {
		$this->assertArrayNotHasKey(
			'support_email',
			$this->fields,
			'support_email field should be removed from form fields'
		);
	}

	public function test_default_delivery_method_is_none() {
		$this->assertSame(
			'none',
			$this->fields['delivery_method']['default'],
			'Default delivery method should be "none" (Disabled)'
		);
	}

	public function test_api_description_prefix_default_is_empty() {
		$this->assertSame(
			'',
			$this->fields['api_description_prefix']['default'],
			'api_description_prefix default should be empty string'
		);
	}

	public function test_api_description_prefix_blank_sends_order_number_only() {
		$this->gateway->settings['api_description_prefix'] = '';

		$order = new class {
			public function get_billing_email() { return 'test@example.com'; }
			public function get_formatted_billing_full_name() { return 'John Doe'; }
			public function get_total() { return '99.99'; }
			public function get_currency() { return 'CAD'; }
			public function get_order_number() { return '12345'; }
			public function update_meta_data( $key, $value ) {}
			public function save() {}
			public function add_order_note( $note ) {}
			public function get_meta( $key ) { return ''; }
		};

		$captured = array();
		$mock_client = new class( $captured ) {
			public $captured;
			public function __construct( &$captured ) { $this->captured = &$captured; }
			public function request_etransfer_link( $order_data, $delivery_method ) {
				$this->captured = $order_data;
				return array( 'success' => true, 'reference' => 'REF123' );
			}
		};

		$ref = new ReflectionProperty( $this->gateway, 'api_client' );
		$ref->setAccessible( true );
		$ref->setValue( $this->gateway, $mock_client );

		$method = new ReflectionMethod( $this->gateway, 'process_api_payment' );
		$method->setAccessible( true );
		$method->invoke( $this->gateway, $order, 'email' );

		$this->assertSame( '12345', $mock_client->captured['description'] );
	}

	public function test_api_description_prefix_prepends_to_order_number() {
		$this->gateway->settings['api_description_prefix'] = 'Order #';

		$order = new class {
			public function get_billing_email() { return 'test@example.com'; }
			public function get_formatted_billing_full_name() { return 'John Doe'; }
			public function get_total() { return '99.99'; }
			public function get_currency() { return 'CAD'; }
			public function get_order_number() { return '12345'; }
			public function update_meta_data( $key, $value ) {}
			public function save() {}
			public function add_order_note( $note ) {}
			public function get_meta( $key ) { return ''; }
		};

		$captured = array();
		$mock_client = new class( $captured ) {
			public $captured;
			public function __construct( &$captured ) { $this->captured = &$captured; }
			public function request_etransfer_link( $order_data, $delivery_method ) {
				$this->captured = $order_data;
				return array( 'success' => true, 'reference' => 'REF123' );
			}
		};

		$ref = new ReflectionProperty( $this->gateway, 'api_client' );
		$ref->setValue( $this->gateway, $mock_client );

		$method = new ReflectionMethod( $this->gateway, 'process_api_payment' );
		$method->invoke( $this->gateway, $order, 'email' );

		$this->assertSame( 'Order #12345', $mock_client->captured['description'] );
	}

	public function test_api_description_prefix_deposit() {
		$this->gateway->settings['api_description_prefix'] = 'Deposit #';

		$order = new class {
			public function get_billing_email() { return 'test@example.com'; }
			public function get_formatted_billing_full_name() { return 'John Doe'; }
			public function get_total() { return '99.99'; }
			public function get_currency() { return 'CAD'; }
			public function get_order_number() { return '12345'; }
			public function update_meta_data( $key, $value ) {}
			public function save() {}
			public function add_order_note( $note ) {}
			public function get_meta( $key ) { return ''; }
		};

		$captured = array();
		$mock_client = new class( $captured ) {
			public $captured;
			public function __construct( &$captured ) { $this->captured = &$captured; }
			public function request_etransfer_link( $order_data, $delivery_method ) {
				$this->captured = $order_data;
				return array( 'success' => true, 'reference' => 'REF123' );
			}
		};

		$ref = new ReflectionProperty( $this->gateway, 'api_client' );
		$ref->setValue( $this->gateway, $mock_client );

		$method = new ReflectionMethod( $this->gateway, 'process_api_payment' );
		$method->invoke( $this->gateway, $order, 'email' );

		$this->assertSame( 'Deposit #12345', $mock_client->captured['description'] );
	}
}

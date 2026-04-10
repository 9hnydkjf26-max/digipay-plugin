<?php
/**
 * Tests for WCPG_Context_Bundler.
 *
 * @package Digipay
 */

require_once __DIR__ . '/../support/class-event-log.php';
require_once __DIR__ . '/../support/class-auto-uploader.php';
require_once __DIR__ . '/../support/class-context-bundler.php';

/**
 * Context bundler tests.
 */
class ContextBundlerTest extends DigipayTestCase {

	/**
	 * Clear instance token before each test so migration logic works fresh.
	 */
	protected function set_up() {
		parent::set_up();
		global $wcpg_mock_options;
		unset( $wcpg_mock_options['wcpg_instance_token'] );
	}

	/**
	 * Reset shared globals and event log between tests to prevent cross-test contamination.
	 */
	protected function tear_down() {
		global $wcpg_mock_orders, $wcpg_mock_options;
		$wcpg_mock_orders  = array();
		$wcpg_mock_options = array();
		if ( class_exists( 'WCPG_Event_Log' ) ) {
			WCPG_Event_Log::clear();
		}
		parent::tear_down();
	}

	/**
	 * Redaction hides secret-shaped keys and preserves value length.
	 */
	public function test_redact_settings_redacts_secret_keys() {
		$in = array(
			'enabled'            => 'yes',
			'webhook_secret_key' => 'super-secret-value',
			'api_key'            => 'abcdef',
			'client_secret'      => '',
			'access_token'       => 'tok',
			'password'           => 'hunter2',
			'credential_x'       => 'x',
			'title'              => 'Credit Card',
		);

		$out = WCPG_Context_Bundler::redact_settings( $in );

		$this->assertSame( 'yes', $out['enabled'] );
		$this->assertSame( 'Credit Card', $out['title'] );
		$this->assertSame( '[REDACTED:length=18]', $out['webhook_secret_key'] );
		$this->assertSame( '[REDACTED:length=6]', $out['api_key'] );
		$this->assertSame( '[REDACTED:length=0]', $out['client_secret'] );
		$this->assertSame( '[REDACTED:length=3]', $out['access_token'] );
		$this->assertSame( '[REDACTED:length=7]', $out['password'] );
		$this->assertSame( '[REDACTED:length=1]', $out['credential_x'] );
	}

	/**
	 * Redaction recurses into nested arrays.
	 */
	public function test_redact_settings_recurses() {
		$in = array(
			'nested' => array(
				'api_secret' => 'zzz',
				'label'      => 'ok',
			),
		);
		$out = WCPG_Context_Bundler::redact_settings( $in );
		$this->assertSame( '[REDACTED:length=3]', $out['nested']['api_secret'] );
		$this->assertSame( 'ok', $out['nested']['label'] );
	}

	/**
	 * PII scrubber masks emails, cards, phones, JWTs.
	 */
	public function test_scrub_pii_masks_all_patterns() {
		$line = 'Order for jane.doe@example.com card 4111 1111 1111 1111 phone +1 415 555 0100 token eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjMifQ.signature_abcdefghij';
		$out  = WCPG_Context_Bundler::scrub_pii( $line );
		$this->assertStringNotContainsString( 'jane.doe@example.com', $out );
		$this->assertStringContainsString( '[EMAIL]', $out );
		$this->assertStringContainsString( '[CARD]', $out );
		$this->assertStringContainsString( '[PHONE]', $out );
		$this->assertStringContainsString( '[JWT]', $out );
	}

	/**
	 * Encryption key status flags the hardcoded fallback as "default".
	 */
	public function test_encryption_key_status_detects_fallback() {
		$bundler = new WCPG_Context_Bundler();
		$status  = $bundler->build_encryption_key_status();
		// In test env, DIGIPAY_ENCRYPTION_KEY is likely undefined => default.
		$this->assertArrayHasKey( 'using_default', $status );
		$this->assertArrayHasKey( 'constant_defined', $status );
		$this->assertArrayHasKey( 'length', $status );
	}

	/**
	 * bundle_meta contains schema_version as integer 1.
	 */
	public function test_bundle_meta_contains_schema_version_integer() {
		$bundler = new WCPG_Context_Bundler();
		$bundle  = $bundler->build();

		$this->assertArrayHasKey( 'schema_version', $bundle['bundle_meta'] );
		$this->assertSame( 1, $bundle['bundle_meta']['schema_version'] );
	}

	/**
	 * encryption_key_status contains a null fingerprint under default state.
	 */
	public function test_encryption_key_status_contains_fingerprint() {
		$this->assertFalse( defined( 'DIGIPAY_ENCRYPTION_KEY' ), 'Precondition: constant must be undefined for this test' );

		$bundler = new WCPG_Context_Bundler();
		$status  = $bundler->build_encryption_key_status();

		$this->assertArrayHasKey( 'encryption_key_fingerprint', $status );
		$this->assertNull( $status['encryption_key_fingerprint'] );
	}

	/**
	 * Fingerprint is null when using_default is true (constant not defined).
	 */
	public function test_encryption_key_fingerprint_null_when_using_default() {
		$this->assertFalse( defined( 'DIGIPAY_ENCRYPTION_KEY' ), 'Precondition: constant must be undefined for this test' );

		$bundler = new WCPG_Context_Bundler();
		$status  = $bundler->build_encryption_key_status();

		$this->assertTrue( $status['using_default'] );
		$this->assertNull( $status['encryption_key_fingerprint'] );
	}

	/**
	 * Build() returns all expected top-level sections.
	 */
	public function test_build_returns_expected_sections() {
		$bundler = new WCPG_Context_Bundler();
		$bundle  = $bundler->build();

		$expected = array(
			'bundle_meta', 'site', 'environment', 'gateways',
			'encryption_key_status', 'diagnostics', 'connectivity_tests',
			'webhook_health', 'recent_failed_orders', 'logs', 'option_snapshots',
		);
		foreach ( $expected as $key ) {
			$this->assertArrayHasKey( $key, $bundle, "Missing section: $key" );
		}
		$this->assertNotEmpty( $bundle['bundle_meta']['bundle_id'] );
		$this->assertNotEmpty( $bundle['bundle_meta']['content_sha256'] );
	}

	/**
	 * Build() includes an 'events' section that is an array.
	 */
	public function test_bundle_contains_events_section() {
		$bundler = new WCPG_Context_Bundler();
		$bundle  = $bundler->build();

		$this->assertArrayHasKey( 'events', $bundle );
		$this->assertIsArray( $bundle['events'] );
	}

	/**
	 * Build() includes a 'settings_changes' section that is an array.
	 */
	public function test_bundle_contains_settings_changes_section() {
		$bundler = new WCPG_Context_Bundler();
		$bundle  = $bundler->build();

		$this->assertArrayHasKey( 'settings_changes', $bundle );
		$this->assertIsArray( $bundle['settings_changes'] );
	}

	/**
	 * Build() includes an 'order_correlations' section that is an array.
	 */
	public function test_bundle_contains_order_correlations_section() {
		$bundler = new WCPG_Context_Bundler();
		$bundle  = $bundler->build();

		$this->assertArrayHasKey( 'order_correlations', $bundle );
		$this->assertIsArray( $bundle['order_correlations'] );
	}

	/**
	 * bundle_meta includes instance_token from the stored option.
	 */
	public function test_bundle_meta_includes_instance_token() {
		update_option( 'wcpg_instance_token', 'abc1234567890def' );
		$bundler = new WCPG_Context_Bundler();
		$bundle  = $bundler->build();
		$this->assertArrayHasKey( 'bundle_meta', $bundle );
		$this->assertArrayHasKey( 'instance_token', $bundle['bundle_meta'] );
		$this->assertSame( 'abc1234567890def', $bundle['bundle_meta']['instance_token'] );
	}

	/**
	 * bundle_meta generates an instance_token when none is stored.
	 */
	public function test_bundle_meta_instance_token_is_generated_if_missing() {
		delete_option( 'wcpg_instance_token' );
		delete_option( 'wcpg_install_uuid' );
		$bundler = new WCPG_Context_Bundler();
		$bundle  = $bundler->build();
		$this->assertArrayHasKey( 'instance_token', $bundle['bundle_meta'] );
		$this->assertNotEmpty( $bundle['bundle_meta']['instance_token'] );
	}

	/**
	 * bundle_meta includes remote_diagnostics_enabled as a boolean.
	 */
	public function test_bundle_meta_includes_remote_diagnostics_enabled_flag() {
		update_option( 'wcpg_remote_diagnostics_enabled', 'yes' );
		$bundler = new WCPG_Context_Bundler();
		$bundle  = $bundler->build();
		$this->assertArrayHasKey( 'remote_diagnostics_enabled', $bundle['bundle_meta'] );
		$this->assertTrue( $bundle['bundle_meta']['remote_diagnostics_enabled'] );

		update_option( 'wcpg_remote_diagnostics_enabled', 'no' );
		$bundle2 = $bundler->build();
		$this->assertFalse( $bundle2['bundle_meta']['remote_diagnostics_enabled'] );
	}
}

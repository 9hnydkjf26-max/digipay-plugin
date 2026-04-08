<?php
/**
 * Tests for WCPG_Context_Bundler.
 *
 * @package Digipay
 */

require_once __DIR__ . '/../support/class-context-bundler.php';

/**
 * Context bundler tests.
 */
class ContextBundlerTest extends DigipayTestCase {

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
	 * encryption_key_status contains a 12-character hex fingerprint.
	 */
	public function test_encryption_key_status_contains_fingerprint() {
		$bundler = new WCPG_Context_Bundler();
		$status  = $bundler->build_encryption_key_status();

		$this->assertArrayHasKey( 'encryption_key_fingerprint', $status );
		$fingerprint = $status['encryption_key_fingerprint'];
		$this->assertIsString( $fingerprint );
		$this->assertSame( 12, strlen( $fingerprint ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{12}$/', $fingerprint );
	}

	/**
	 * Fingerprint is derived from the SHA-256 of the key value.
	 */
	public function test_encryption_key_fingerprint_matches_sha256_prefix() {
		// DIGIPAY_ENCRYPTION_KEY is not defined in test env, so $value = ''.
		$expected_fingerprint = substr( hash( 'sha256', '' ), 0, 12 );

		$bundler = new WCPG_Context_Bundler();
		$status  = $bundler->build_encryption_key_status();

		$this->assertSame( $expected_fingerprint, $status['encryption_key_fingerprint'] );
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
}

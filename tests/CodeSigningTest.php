<?php
/**
 * Tests for Ed25519 code signing used in auto-updates.
 *
 * @package Digipay
 */

class CodeSigningTest extends DigipayTestCase {

	/**
	 * Generate a fresh Ed25519 keypair for testing.
	 *
	 * @return array [ 'public' => string, 'secret' => string ]
	 */
	private function generate_test_keypair() {
		$keypair = sodium_crypto_sign_keypair();
		return [
			'public' => sodium_crypto_sign_publickey( $keypair ),
			'secret' => sodium_crypto_sign_secretkey( $keypair ),
		];
	}

	/**
	 * Ed25519 sign + verify roundtrip works.
	 */
	public function test_sign_and_verify_roundtrip() {
		$keys    = $this->generate_test_keypair();
		$message = 'This is a test ZIP payload content.';

		$signature = sodium_crypto_sign_detached( $message, $keys['secret'] );
		$valid     = sodium_crypto_sign_verify_detached( $signature, $message, $keys['public'] );

		$this->assertTrue( $valid );
	}

	/**
	 * Verification rejects tampered content.
	 */
	public function test_reject_tampered_content() {
		$keys    = $this->generate_test_keypair();
		$message = 'Original content.';

		$signature = sodium_crypto_sign_detached( $message, $keys['secret'] );
		$valid     = sodium_crypto_sign_verify_detached( $signature, 'Tampered content.', $keys['public'] );

		$this->assertFalse( $valid );
	}

	/**
	 * Verification rejects signature from wrong key.
	 */
	public function test_reject_wrong_public_key() {
		$keys       = $this->generate_test_keypair();
		$other_keys = $this->generate_test_keypair();
		$message    = 'Some payload data.';

		$signature = sodium_crypto_sign_detached( $message, $keys['secret'] );
		$valid     = sodium_crypto_sign_verify_detached( $signature, $message, $other_keys['public'] );

		$this->assertFalse( $valid );
	}

	/**
	 * Base64 signature roundtrip preserves the signature.
	 */
	public function test_base64_signature_roundtrip() {
		$keys    = $this->generate_test_keypair();
		$message = 'Test payload for base64 roundtrip.';

		$signature = sodium_crypto_sign_detached( $message, $keys['secret'] );
		$encoded   = base64_encode( $signature );
		$decoded   = base64_decode( $encoded, true );

		$this->assertSame( $signature, $decoded );
		$this->assertTrue( sodium_crypto_sign_verify_detached( $decoded, $message, $keys['public'] ) );
	}

	/**
	 * Ed25519 detached signature is exactly 64 bytes.
	 */
	public function test_signature_is_64_bytes() {
		$keys      = $this->generate_test_keypair();
		$signature = sodium_crypto_sign_detached( 'test', $keys['secret'] );

		$this->assertSame( 64, strlen( $signature ) );
		$this->assertSame( SODIUM_CRYPTO_SIGN_BYTES, strlen( $signature ) );
	}

	/**
	 * The SIGNING_PUBLIC_KEY constant in the updater decodes to a valid 32-byte key.
	 */
	public function test_public_key_constant_is_valid() {
		require_once dirname( __DIR__ ) . '/class-github-updater.php';

		$b64_key    = WCPG_GitHub_Updater::SIGNING_PUBLIC_KEY;
		$public_key = base64_decode( $b64_key, true );

		$this->assertNotFalse( $public_key, 'Public key must be valid base64.' );
		$this->assertSame( SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen( $public_key ),
			'Public key must be exactly ' . SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES . ' bytes.' );
	}
}

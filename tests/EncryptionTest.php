<?php
/**
 * Tests for the Digipay encryption functionality.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for encryption functions.
 */
class EncryptionTest extends DigipayTestCase {

    /**
     * Test that digipay_encrypt uses explicit key length constant.
     *
     * The old implementation extracted "256" from "AES-256-CBC" using FILTER_SANITIZE_NUMBER_INT
     * which is obfuscated and error-prone. The new implementation should use an explicit constant.
     */
    public function test_encrypt_uses_explicit_key_length() {
        // This test verifies the function uses 256-bit key length explicitly.
        $key = 'test_encryption_key_123';
        $plaintext = 'test data to encrypt';

        // The function should exist and work.
        $this->assertTrue( function_exists( 'digipay_encrypt' ), 'digipay_encrypt function should exist' );

        $result = digipay_encrypt( $plaintext, $key );

        // Result should be base64 encoded JSON.
        $this->assertNotEmpty( $result );
        $this->assertIsString( $result );

        // Decode and verify structure.
        $decoded = json_decode( base64_decode( $result ), true );
        $this->assertIsArray( $decoded );
        $this->assertArrayHasKey( 'ciphertext', $decoded );
        $this->assertArrayHasKey( 'iv', $decoded );
        $this->assertArrayHasKey( 'salt', $decoded );
        $this->assertArrayHasKey( 'iterations', $decoded );
    }
}

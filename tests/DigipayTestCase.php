<?php
/**
 * Base test case class for Digipay tests.
 *
 * @package Digipay
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Base test case class providing common test utilities.
 */
abstract class DigipayTestCase extends TestCase {

    /**
     * Test encryption key used across tests.
     *
     * @var string
     */
    protected $test_encryption_key = 'test_encryption_key_123';

    /**
     * Set up test fixtures.
     */
    protected function set_up() {
        parent::set_up();
    }

    /**
     * Tear down test fixtures.
     */
    protected function tear_down() {
        parent::tear_down();
    }

    /**
     * Assert that a string contains valid JSON.
     *
     * @param string $string String to check.
     * @param string $message Optional assertion message.
     */
    protected function assertValidJson( $string, $message = '' ) {
        json_decode( $string );
        $this->assertSame( JSON_ERROR_NONE, json_last_error(), $message );
    }

    /**
     * Assert that a URL is properly escaped.
     *
     * @param string $url     URL to check.
     * @param string $message Optional assertion message.
     */
    protected function assertUrlEscaped( $url, $message = '' ) {
        // Check for common unescaped characters that should be encoded.
        $this->assertStringNotContainsString( '<', $url, $message . ' URL contains unescaped <' );
        $this->assertStringNotContainsString( '>', $url, $message . ' URL contains unescaped >' );
        $this->assertStringNotContainsString( '"', $url, $message . ' URL contains unescaped "' );
        $this->assertStringNotContainsString( ' ', $url, $message . ' URL contains unescaped space' );
    }

    /**
     * Create a mock order data array.
     *
     * @param array $overrides Override default values.
     * @return array Order data array.
     */
    protected function createMockOrderData( $overrides = array() ) {
        $defaults = array(
            'id'      => 12345,
            'total'   => 99.99,
            'billing' => array(
                'first_name' => 'John',
                'last_name'  => 'Doe',
                'email'      => 'john@example.com',
                'address_1'  => '123 Main St',
                'address_2'  => 'Apt 4',
                'city'       => 'Vancouver',
                'state'      => 'BC',
                'postcode'   => 'V6B 1A1',
                'country'    => 'CA',
            ),
        );

        return array_merge( $defaults, $overrides );
    }
}

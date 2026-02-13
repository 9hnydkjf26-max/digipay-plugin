<?php
/**
 * Tests for timezone helper function.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for timezone helper.
 */
class TimezoneHelperTest extends DigipayTestCase {

    /**
     * Test that digipay_get_pacific_date function exists.
     */
    public function test_get_pacific_date_function_exists() {
        $this->assertTrue( function_exists( 'digipay_get_pacific_date' ), 'digipay_get_pacific_date function should exist' );
    }
}

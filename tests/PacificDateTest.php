<?php
/**
 * Tests for digipay_get_pacific_date behavior.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for Pacific date helper behavior.
 */
class PacificDateTest extends DigipayTestCase {

    /**
     * Test that digipay_get_pacific_date returns a valid date string.
     */
    public function test_get_pacific_date_returns_valid_date() {
        $result = digipay_get_pacific_date( 'Y-m-d' );

        // Should match YYYY-MM-DD format.
        $this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $result, 'Should return date in Y-m-d format' );
    }
}

<?php
/**
 * Tests for WCPG_Gateway_Issue_Notices.
 *
 * @package Digipay
 */

require_once __DIR__ . '/../support/class-issue-catalog.php';
require_once __DIR__ . '/../support/class-gateway-issue-notices.php';

/**
 * Gateway issue notices tests.
 */
class GatewayIssueNoticesTest extends DigipayTestCase {

	/**
	 * Clean up options and transients after each test.
	 */
	protected function tear_down() {
		global $wcpg_mock_options, $wcpg_test_transients;
		// Remove gateway options seeded by tests.
		unset(
			$wcpg_mock_options['woocommerce_paygobillingcc_settings'],
			$wcpg_mock_options['woocommerce_digipay_etransfer_settings'],
			$wcpg_mock_options['woocommerce_wcpg_crypto_settings']
		);
		// Remove transients seeded by class.
		unset(
			$wcpg_test_transients[ WCPG_Gateway_Issue_Notices::TRANSIENT_PREFIX . 'paygobillingcc' ],
			$wcpg_test_transients[ WCPG_Gateway_Issue_Notices::TRANSIENT_PREFIX . 'digipay_etransfer' ],
			$wcpg_test_transients[ WCPG_Gateway_Issue_Notices::TRANSIENT_PREFIX . 'wcpg_crypto' ]
		);
		parent::tear_down();
	}

	// ------------------------------------------------------------------
	// Test 1: refresh stashes issues for etransfer when secret missing
	// ------------------------------------------------------------------

	/**
	 * refresh_all stashes WCPG-W-002 under digipay_etransfer when webhook secret is empty.
	 */
	public function test_refresh_stashes_issues_for_etransfer_when_secret_missing() {
		global $wcpg_mock_options;
		$wcpg_mock_options['woocommerce_digipay_etransfer_settings'] = array(
			'enabled'            => 'yes',
			'delivery_method'    => 'email',
			'webhook_secret_key' => '',
		);
		$wcpg_mock_options['woocommerce_paygobillingcc_settings']     = array();
		$wcpg_mock_options['woocommerce_wcpg_crypto_settings']        = array();

		WCPG_Gateway_Issue_Notices::refresh_all();

		$issues = WCPG_Gateway_Issue_Notices::get_issues_for_gateway( 'digipay_etransfer' );
		$this->assertIsArray( $issues );
		$ids = array_column( $issues, 'id' );
		$this->assertContains( 'WCPG-W-002', $ids, 'WCPG-W-002 should be detected for digipay_etransfer' );
	}

	// ------------------------------------------------------------------
	// Test 2: refresh stashes nothing when healthy
	// ------------------------------------------------------------------

	/**
	 * refresh_all stashes empty array when gateway is disabled (no issues).
	 */
	public function test_refresh_stashes_nothing_when_healthy() {
		global $wcpg_mock_options;
		$wcpg_mock_options['woocommerce_digipay_etransfer_settings'] = array(
			'enabled' => 'no',
		);
		$wcpg_mock_options['woocommerce_paygobillingcc_settings']    = array();
		$wcpg_mock_options['woocommerce_wcpg_crypto_settings']       = array();

		WCPG_Gateway_Issue_Notices::refresh_all();

		$issues = WCPG_Gateway_Issue_Notices::get_issues_for_gateway( 'digipay_etransfer' );
		$this->assertIsArray( $issues );
		$this->assertEmpty( $issues, 'No issues expected when gateway is disabled' );
	}

	// ------------------------------------------------------------------
	// Test 3: issues group by ID prefix
	// ------------------------------------------------------------------

	/**
	 * WCPG-W-* and WCPG-E-* issues land under digipay_etransfer; WCPG-C-* under wcpg_crypto.
	 */
	public function test_refresh_groups_by_id_prefix() {
		global $wcpg_mock_options;
		// Trigger WCPG-W-002 (etransfer, no webhook secret) and WCPG-E-001 (etransfer, delivery_method none)
		// and WCPG-C-001 (crypto enabled, no public key).
		$wcpg_mock_options['woocommerce_digipay_etransfer_settings'] = array(
			'enabled'            => 'yes',
			'delivery_method'    => 'none',
			'webhook_secret_key' => '',
		);
		$wcpg_mock_options['woocommerce_wcpg_crypto_settings']        = array(
			'enabled'    => 'yes',
			'public_key' => '',
		);
		$wcpg_mock_options['woocommerce_paygobillingcc_settings']     = array();

		WCPG_Gateway_Issue_Notices::refresh_all();

		$etransfer_issues = WCPG_Gateway_Issue_Notices::get_issues_for_gateway( 'digipay_etransfer' );
		$crypto_issues    = WCPG_Gateway_Issue_Notices::get_issues_for_gateway( 'wcpg_crypto' );
		$cc_issues        = WCPG_Gateway_Issue_Notices::get_issues_for_gateway( 'paygobillingcc' );

		$etransfer_ids = array_column( $etransfer_issues, 'id' );
		$crypto_ids    = array_column( $crypto_issues, 'id' );

		// E and W prefix issues → digipay_etransfer.
		$this->assertContains( 'WCPG-W-002', $etransfer_ids, 'WCPG-W-002 should be under digipay_etransfer' );
		$this->assertContains( 'WCPG-E-001', $etransfer_ids, 'WCPG-E-001 should be under digipay_etransfer' );

		// C prefix issue → wcpg_crypto.
		$this->assertContains( 'WCPG-C-001', $crypto_ids, 'WCPG-C-001 should be under wcpg_crypto' );

		// C issue should NOT appear under etransfer or paygobillingcc.
		$this->assertNotContains( 'WCPG-C-001', $etransfer_ids, 'WCPG-C-001 should not bleed into etransfer' );
		$this->assertIsArray( $cc_issues );
	}

	// ------------------------------------------------------------------
	// Test 4: render_notices outputs expected HTML
	// ------------------------------------------------------------------

	/**
	 * render_notices_for_gateway outputs notice div with ID, title, plain_english, and support link.
	 */
	public function test_render_notices_outputs_expected_html() {
		global $wcpg_test_transients;

		// Seed the transient directly with a fake issue.
		$fake_issue = array(
			array(
				'id'            => 'WCPG-W-002',
				'title'         => 'E-Transfer webhook secret key not configured',
				'plain_english' => 'E-Transfer is turned on but you haven\'t entered a webhook secret.',
				'fix'           => 'Enter the webhook secret in WooCommerce settings.',
				'severity'      => 'warning',
				'config_only'   => true,
			),
		);
		$wcpg_test_transients[ WCPG_Gateway_Issue_Notices::TRANSIENT_PREFIX . 'digipay_etransfer' ] = array(
			'value'      => $fake_issue,
			'expiration' => HOUR_IN_SECONDS,
		);

		ob_start();
		WCPG_Gateway_Issue_Notices::render_notices_for_gateway( 'digipay_etransfer' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice notice-warning inline', $output, 'Should contain notice classes' );
		$this->assertStringContainsString( 'WCPG-W-002', $output, 'Should contain issue ID' );
		$this->assertStringContainsString( 'E-Transfer webhook secret key not configured', $output, 'Should contain issue title' );
		$this->assertStringContainsString( 'haven&#039;t entered a webhook secret', $output, 'Should contain escaped plain_english' );
		$this->assertStringContainsString( 'admin.php?page=wcpg-support', $output, 'Should contain support page URL' );
		$this->assertStringContainsString( 'Get help', $output, 'Should contain Get help link text' );
	}

	// ------------------------------------------------------------------
	// Test 5: render_notices outputs nothing when no issues
	// ------------------------------------------------------------------

	/**
	 * render_notices_for_gateway outputs empty string when no issues are stashed.
	 */
	public function test_render_notices_outputs_nothing_when_no_issues() {
		global $wcpg_test_transients;
		// Ensure the transient is empty (not set).
		unset( $wcpg_test_transients[ WCPG_Gateway_Issue_Notices::TRANSIENT_PREFIX . 'digipay_etransfer' ] );

		ob_start();
		WCPG_Gateway_Issue_Notices::render_notices_for_gateway( 'digipay_etransfer' );
		$output = ob_get_clean();

		$this->assertSame( '', $output, 'Output should be empty when no issues are stashed' );
	}

	// ------------------------------------------------------------------
	// Test 6: transient TTL is HOUR_IN_SECONDS (observable via mock store)
	// ------------------------------------------------------------------

	/**
	 * After refresh_all, the transient expiration stored equals HOUR_IN_SECONDS.
	 */
	public function test_transient_ttl_is_one_hour() {
		global $wcpg_mock_options, $wcpg_test_transients;
		$wcpg_mock_options['woocommerce_digipay_etransfer_settings'] = array( 'enabled' => 'no' );
		$wcpg_mock_options['woocommerce_paygobillingcc_settings']    = array();
		$wcpg_mock_options['woocommerce_wcpg_crypto_settings']       = array();

		WCPG_Gateway_Issue_Notices::refresh_all();

		$key = WCPG_Gateway_Issue_Notices::TRANSIENT_PREFIX . 'digipay_etransfer';
		$this->assertArrayHasKey( $key, $wcpg_test_transients, 'Transient should be set after refresh_all' );
		$this->assertSame(
			HOUR_IN_SECONDS,
			$wcpg_test_transients[ $key ]['expiration'],
			'Transient TTL should equal HOUR_IN_SECONDS'
		);
	}
}

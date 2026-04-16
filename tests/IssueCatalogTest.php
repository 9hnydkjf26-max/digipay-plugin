<?php
/**
 * Tests for WCPG_Issue_Catalog.
 *
 * @package Digipay
 */

require_once __DIR__ . '/../support/class-issue-catalog.php';

/**
 * Issue catalog tests.
 */
class IssueCatalogTest extends DigipayTestCase {

	// ------------------------------------------------------------------
	// Structural tests
	// ------------------------------------------------------------------

	/**
	 * all() returns at least 12 entries.
	 */
	public function test_all_returns_array_of_issues() {
		$issues = WCPG_Issue_Catalog::all();
		$this->assertIsArray( $issues );
		$this->assertGreaterThanOrEqual( 15, count( $issues ) );
	}

	/**
	 * Every issue has the required keys.
	 */
	public function test_each_issue_has_required_fields() {
		$required = array( 'id', 'title', 'plain_english', 'fix', 'severity', 'config_only', 'detector' );
		foreach ( WCPG_Issue_Catalog::all() as $issue ) {
			foreach ( $required as $key ) {
				$this->assertArrayHasKey( $key, $issue, "Issue '{$issue['id']}' missing field '{$key}'" );
			}
		}
	}

	/**
	 * Every severity is one of the 4 SEV_* constants.
	 */
	public function test_each_severity_is_valid_constant() {
		$valid = array(
			WCPG_Issue_Catalog::SEV_INFO,
			WCPG_Issue_Catalog::SEV_WARNING,
			WCPG_Issue_Catalog::SEV_ERROR,
			WCPG_Issue_Catalog::SEV_CRITICAL,
		);
		foreach ( WCPG_Issue_Catalog::all() as $issue ) {
			$this->assertContains(
				$issue['severity'],
				$valid,
				"Issue '{$issue['id']}' has invalid severity '{$issue['severity']}'"
			);
		}
	}

	/**
	 * Every detector is callable.
	 */
	public function test_each_detector_is_callable() {
		foreach ( WCPG_Issue_Catalog::all() as $issue ) {
			$this->assertTrue(
				is_callable( $issue['detector'] ),
				"Issue '{$issue['id']}' detector is not callable"
			);
		}
	}

	/**
	 * No duplicate IDs across the catalog.
	 */
	public function test_each_id_is_unique() {
		$ids  = array_column( WCPG_Issue_Catalog::all(), 'id' );
		$unique = array_unique( $ids );
		$this->assertSame( count( $unique ), count( $ids ), 'Duplicate IDs found: ' . implode( ', ', array_diff_assoc( $ids, $unique ) ) );
	}

	// ------------------------------------------------------------------
	// Clean-bundle test
	// ------------------------------------------------------------------

	/**
	 * A healthy bundle produces no detections.
	 */
	public function test_detect_all_returns_empty_for_clean_bundle() {
		$bundle = $this->build_clean_bundle();
		$detected = WCPG_Issue_Catalog::detect_all( $bundle );
		$this->assertSame( array(), $detected, 'Expected no issues for clean bundle, got: ' . implode( ', ', array_column( $detected, 'id' ) ) );
	}

	// ------------------------------------------------------------------
	// Positive detector tests (one per issue)
	// ------------------------------------------------------------------

	/**
	 * WCPG-P-001: stale postback URL test matcher false negative.
	 */
	public function test_detects_p_001_stale_postback_matcher() {
		$bundle = $this->build_clean_bundle();
		$bundle['connectivity_tests']['postback_url'] = array(
			'success'      => false,
			'body_preview' => 'Response body: order_not_found for session 123',
		);
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );
		$ids     = array_column( $matched, 'id' );
		$this->assertContains( 'WCPG-P-001', $ids );
	}

	/**
	 * WCPG-P-002: postback error rate > 20%.
	 */
	public function test_detects_p_002_postback_error_rate() {
		$bundle = $this->build_clean_bundle();
		$bundle['diagnostics']['postback_stats'] = array(
			'success_count' => 5,
			'error_count'   => 5,
		);
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );
		$ids     = array_column( $matched, 'id' );
		$this->assertContains( 'WCPG-P-002', $ids );
	}

	/**
	 * WCPG-W-001: HMAC failures in webhook health.
	 */
	public function test_detects_w_001_webhook_hmac_failures() {
		$bundle = $this->build_clean_bundle();
		$bundle['webhook_health']['hmac_fail'] = 3;
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );
		$ids     = array_column( $matched, 'id' );
		$this->assertContains( 'WCPG-W-001', $ids );
	}

	/**
	 * WCPG-W-002: e-Transfer enabled but webhook_secret_key empty (redacted length=0).
	 */
	public function test_detects_w_002_etransfer_missing_secret() {
		$bundle = $this->build_clean_bundle();
		$bundle['gateways']['digipay_etransfer'] = array(
			'enabled'            => 'yes',
			'webhook_secret_key' => '[REDACTED:length=0]',
			'delivery_method'    => 'email',
		);
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );
		$ids     = array_column( $matched, 'id' );
		$this->assertContains( 'WCPG-W-002', $ids );
	}

	/**
	 * WCPG-X-001 was removed from the catalog: the hardcoded fallback
	 * encryption key is unavoidable without a wp-config.php edit, and
	 * surfacing it as a warning was noise. Ensure the detector does not fire.
	 */
	public function test_x_001_is_not_in_catalog() {
		$bundle = $this->build_clean_bundle();
		$bundle['encryption_key_status']['using_default'] = true;
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );
		$ids     = array_column( $matched, 'id' );
		$this->assertNotContains( 'WCPG-X-001', $ids );
	}

	/**
	 * WCPG-X-002: LiteSpeed active but REST endpoint not excluded.
	 */
	public function test_detects_x_002_litespeed_rest_not_excluded() {
		$bundle = $this->build_clean_bundle();
		$bundle['environment_detail']['litespeed_rest_excluded'] = false;
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );
		$ids     = array_column( $matched, 'id' );
		$this->assertContains( 'WCPG-X-002', $ids );
	}

	/**
	 * WCPG-X-003: PHP version below 7.4.
	 */
	public function test_detects_x_003_old_php() {
		$bundle = $this->build_clean_bundle();
		$bundle['site']['php_version'] = '7.2.0';
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );
		$ids     = array_column( $matched, 'id' );
		$this->assertContains( 'WCPG-X-003', $ids );
	}

	/**
	 * WCPG-X-004: WordPress version below 6.0.
	 */
	public function test_detects_x_004_old_wordpress() {
		$bundle = $this->build_clean_bundle();
		$bundle['site']['wp_version'] = '5.9';
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );
		$ids     = array_column( $matched, 'id' );
		$this->assertContains( 'WCPG-X-004', $ids );
	}

	/**
	 * WCPG-C-001: crypto gateway enabled but public_key empty.
	 */
	public function test_detects_c_001_crypto_missing_keys() {
		$bundle = $this->build_clean_bundle();
		$bundle['gateways']['wcpg_crypto'] = array(
			'enabled'    => 'yes',
			'public_key' => '[REDACTED:length=0]',
		);
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );
		$ids     = array_column( $matched, 'id' );
		$this->assertContains( 'WCPG-C-001', $ids );
	}

	/**
	 * WCPG-E-001: e-Transfer enabled but delivery_method is 'none'.
	 */
	public function test_detects_e_001_etransfer_no_delivery_method() {
		$bundle = $this->build_clean_bundle();
		$bundle['gateways']['digipay_etransfer'] = array(
			'enabled'            => 'yes',
			'webhook_secret_key' => '[REDACTED:length=8]',
			'delivery_method'    => 'none',
		);
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );
		$ids     = array_column( $matched, 'id' );
		$this->assertContains( 'WCPG-E-001', $ids );
	}

	/**
	 * WCPG-S-001: max ticket size above daily limit.
	 */
	public function test_detects_s_001_max_ticket_above_daily_limit() {
		$bundle = $this->build_clean_bundle();
		$bundle['option_snapshots']['wcpg_postback_stats'] = null;
		$bundle['gateways']['paygobillingcc'] = array(
			'enabled'          => 'yes',
			'max_ticket_size'  => '500',
			'daily_limit'      => '300',
		);
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );
		$ids     = array_column( $matched, 'id' );
		$this->assertContains( 'WCPG-S-001', $ids );
	}

	/**
	 * WCPG-S-002: site has instance_token but no site_id.
	 */
	public function test_detects_s_002_site_not_provisioned() {
		$bundle = $this->build_clean_bundle();
		$bundle['site']['instance_token'] = 'abc-123';
		$bundle['site']['site_id']        = null;
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );
		$ids     = array_column( $matched, 'id' );
		$this->assertContains( 'WCPG-S-002', $ids );
	}

	/**
	 * WCPG-S-002 does NOT fire when site_id is present.
	 */
	public function test_s_002_does_not_fire_when_site_id_present() {
		$bundle = $this->build_clean_bundle();
		$bundle['site']['instance_token'] = 'abc-123';
		$bundle['site']['site_id']        = 'site-456';
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );
		$ids     = array_column( $matched, 'id' );
		$this->assertNotContains( 'WCPG-S-002', $ids );
	}

	/**
	 * WCPG-S-003: postbacks stopped — last success older than 7 days.
	 */
	public function test_detects_s_003_postbacks_dead() {
		$bundle = $this->build_clean_bundle();
		$bundle['diagnostics']['postback_stats'] = array(
			'success_count' => 5,
			'error_count'   => 0,
			'last_success'  => gmdate( 'Y-m-d H:i:s', time() - ( 10 * 86400 ) ),
		);
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );
		$ids     = array_column( $matched, 'id' );
		$this->assertContains( 'WCPG-S-003', $ids );
	}

	/**
	 * WCPG-S-003 does NOT fire when last success is recent.
	 */
	public function test_s_003_does_not_fire_when_postbacks_recent() {
		$bundle = $this->build_clean_bundle();
		$bundle['diagnostics']['postback_stats'] = array(
			'success_count' => 5,
			'error_count'   => 0,
			'last_success'  => gmdate( 'Y-m-d H:i:s', time() - ( 2 * 86400 ) ),
		);
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );
		$ids     = array_column( $matched, 'id' );
		$this->assertNotContains( 'WCPG-S-003', $ids );
	}

	/**
	 * WCPG-S-004: instance token present but no instance_id or site_id — instance
	 * never appeared in the dashboard.
	 */
	public function test_detects_s_004_instance_not_in_dashboard() {
		$bundle = $this->build_clean_bundle();
		$bundle['site']['instance_token'] = '9a5e1f9b-4d53-4db2-9e83-24012f03413e';
		$bundle['site']['instance_id']    = null;
		$bundle['site']['site_id']        = null;
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );
		$ids     = array_column( $matched, 'id' );
		$this->assertContains( 'WCPG-S-004', $ids );
	}

	/**
	 * WCPG-S-004 does NOT fire when instance_id is present (instance IS known
	 * to dashboard — S-002 may fire instead if site_id is null).
	 */
	public function test_s_004_does_not_fire_when_instance_id_present() {
		$bundle = $this->build_clean_bundle();
		$bundle['site']['instance_token'] = '9a5e1f9b-4d53-4db2-9e83-24012f03413e';
		$bundle['site']['instance_id']    = 42;
		$bundle['site']['site_id']        = null;
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );
		$ids     = array_column( $matched, 'id' );
		$this->assertNotContains( 'WCPG-S-004', $ids );
	}

	/**
	 * WCPG-S-005: instance registered, site_id null, and event log contains
	 * "pricing configuration" 404.
	 */
	public function test_detects_s_005_pricing_config_missing() {
		$bundle = $this->build_clean_bundle();
		$bundle['site']['instance_token'] = '9a5e1f9b-4d53-4db2-9e83-24012f03413e';
		$bundle['site']['instance_id']    = 42;
		$bundle['site']['site_id']        = null;
		$bundle['logs']                   = array(
			array( 'message' => 'plugin-site-limits 404: No pricing configuration found for this site' ),
		);
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );
		$ids     = array_column( $matched, 'id' );
		$this->assertContains( 'WCPG-S-005', $ids );
	}

	/**
	 * WCPG-S-005 does NOT fire when site_id is present (pricing config exists).
	 */
	public function test_s_005_does_not_fire_when_site_id_present() {
		$bundle = $this->build_clean_bundle();
		$bundle['site']['instance_token'] = '9a5e1f9b-4d53-4db2-9e83-24012f03413e';
		$bundle['site']['instance_id']    = 42;
		$bundle['site']['site_id']        = 'site_abc123';
		$bundle['logs']                   = array(
			array( 'message' => 'plugin-site-limits 404: No pricing configuration found for this site' ),
		);
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );
		$ids     = array_column( $matched, 'id' );
		$this->assertNotContains( 'WCPG-S-005', $ids );
	}

	/**
	 * WCPG-S-006: CC gateway has siteid, recent CC orders exist, all have empty
	 * paygo_transaction_id — processor rejecting checkout before transaction created.
	 */
	public function test_detects_s_006_cc_checkout_rejected_by_processor() {
		$bundle = $this->build_clean_bundle();
		$bundle['gateways']['paygobillingcc']['siteid'] = '1234';
		$bundle['recent_failed_orders'] = array(
			array(
				'payment_method'       => 'paygobillingcc',
				'paygo_transaction_id' => '',
				'paygo_status'         => '',
			),
			array(
				'payment_method'       => 'paygobillingcc',
				'paygo_transaction_id' => '',
				'paygo_status'         => '',
			),
		);
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );
		$ids     = array_column( $matched, 'id' );
		$this->assertContains( 'WCPG-S-006', $ids );
	}

	/**
	 * WCPG-S-006 does NOT fire when at least one CC order has a paygo_transaction_id
	 * (processor accepted at least one checkout — not a blanket rejection).
	 */
	public function test_s_006_does_not_fire_when_transaction_id_present() {
		$bundle = $this->build_clean_bundle();
		$bundle['gateways']['paygobillingcc']['siteid'] = '1234';
		$bundle['recent_failed_orders'] = array(
			array(
				'payment_method'       => 'paygobillingcc',
				'paygo_transaction_id' => 'TXN-ABC-123',
				'paygo_status'         => 'approved',
			),
		);
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );
		$ids     = array_column( $matched, 'id' );
		$this->assertNotContains( 'WCPG-S-006', $ids );
	}

	/**
	 * WCPG-S-006 does NOT fire when siteid is missing (gateway not configured yet).
	 */
	public function test_s_006_does_not_fire_without_siteid() {
		$bundle = $this->build_clean_bundle();
		// No siteid in paygobillingcc settings.
		$bundle['recent_failed_orders'] = array(
			array(
				'payment_method'       => 'paygobillingcc',
				'paygo_transaction_id' => '',
				'paygo_status'         => '',
			),
		);
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );
		$ids     = array_column( $matched, 'id' );
		$this->assertNotContains( 'WCPG-S-006', $ids );
	}

	/**
	 * WCPG-F-001: postback URL reachable but blocked by firewall (zero successes, some errors).
	 */
	public function test_detects_f_001_postbacks_blocked_by_firewall() {
		$bundle = $this->build_clean_bundle();
		$bundle['connectivity_tests']['postback_url'] = array(
			'success'      => true,
			'body_preview' => 'ok',
		);
		$bundle['diagnostics']['postback_stats'] = array(
			'success_count' => 0,
			'error_count'   => 5,
			'last_success'  => null,
		);
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );
		$ids     = array_column( $matched, 'id' );
		$this->assertContains( 'WCPG-F-001', $ids );
	}

	/**
	 * WCPG-F-001 does NOT fire when postbacks are succeeding.
	 */
	public function test_f_001_does_not_fire_when_postbacks_healthy() {
		$bundle = $this->build_clean_bundle();
		$bundle['connectivity_tests']['postback_url'] = array(
			'success'      => true,
			'body_preview' => 'ok',
		);
		$bundle['diagnostics']['postback_stats'] = array(
			'success_count' => 10,
			'error_count'   => 0,
			'last_success'  => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
		);
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );
		$ids     = array_column( $matched, 'id' );
		$this->assertNotContains( 'WCPG-F-001', $ids );
	}

	/**
	 * WCPG-X-005: outbound IP probe failed (null).
	 */
	public function test_detects_x_005_missing_outbound_ip() {
		$bundle = $this->build_clean_bundle();
		$bundle['site']['outbound_ip'] = null;
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );
		$ids     = array_column( $matched, 'id' );
		$this->assertContains( 'WCPG-X-005', $ids );
	}

	// ------------------------------------------------------------------
	// API contract tests
	// ------------------------------------------------------------------

	/**
	 * detect_config_only() only runs detectors where config_only === true.
	 *
	 * Trigger both a config-only issue (WCPG-W-002) and a non-config-only
	 * issue (WCPG-X-001 — needs encryption_key_status). detect_config_only()
	 * must return the config-only one only.
	 */
	public function test_detect_config_only_only_runs_config_detectors() {
		// Build gateway settings that trigger WCPG-W-002 (config_only=true).
		$gateway_settings = array(
			'digipay_etransfer' => array(
				'enabled'            => 'yes',
				'webhook_secret_key' => '[REDACTED:length=0]',
				'delivery_method'    => 'email',
			),
			'paygobillingcc'    => array(
				'enabled'         => 'yes',
				'max_ticket_size' => '100',
				'daily_limit'     => '500',
			),
			'wcpg_crypto'       => array(
				'enabled'    => 'no',
				'public_key' => '[REDACTED:length=10]',
			),
		);

		$detected = WCPG_Issue_Catalog::detect_config_only( $gateway_settings );
		$ids      = array_column( $detected, 'id' );

		// Should include WCPG-W-002 (config-only, triggered by missing secret).
		$this->assertContains( 'WCPG-W-002', $ids );

		// All returned issues must have config_only === true.
		foreach ( $detected as $issue ) {
			$this->assertTrue( $issue['config_only'], "Issue '{$issue['id']}' is not config_only but was returned by detect_config_only()" );
		}

		// Non-config-only issues must NOT appear regardless of bundle state.
		$non_config_ids = array( 'WCPG-X-002', 'WCPG-X-003', 'WCPG-X-004', 'WCPG-P-001', 'WCPG-P-002', 'WCPG-W-001', 'WCPG-X-005', 'WCPG-S-001', 'WCPG-S-002', 'WCPG-S-003', 'WCPG-S-004', 'WCPG-S-005', 'WCPG-F-001' );
		foreach ( $non_config_ids as $nid ) {
			$this->assertNotContains( $nid, $ids, "Non-config-only issue '{$nid}' should not appear in detect_config_only() output" );
		}
	}

	/**
	 * detect_all() strips the 'detector' callable from matched issue arrays.
	 */
	public function test_detect_all_strips_detector_callable_from_output() {
		$bundle = $this->build_clean_bundle();
		// Trigger at least one issue.
		$bundle['site']['outbound_ip'] = null;
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );
		$this->assertNotEmpty( $matched );
		foreach ( $matched as $issue ) {
			$this->assertArrayNotHasKey( 'detector', $issue, "detect_all() output should not expose 'detector' field" );
		}
	}

	// ------------------------------------------------------------------
	// fixed_in auto-suppress
	// ------------------------------------------------------------------

	protected function tearDown(): void {
		WCPG_Issue_Catalog::$current_version_override = null;
		WCPG_Issue_Catalog::$extra_issues_for_test    = array();
		parent::tearDown();
	}

	public function test_fixed_in_suppresses_issue_when_current_version_is_at_or_above() {
		WCPG_Issue_Catalog::$extra_issues_for_test = array(
			array(
				'id'            => 'WCPG-T-999',
				'title'         => 'Test issue (fixed)',
				'plain_english' => 'Synthetic test entry.',
				'fix'           => 'n/a',
				'severity'      => WCPG_Issue_Catalog::SEV_WARNING,
				'config_only'   => false,
				'introduced_in' => '10.0.0',
				'fixed_in'      => '13.2.0',
				'related_pr'    => 'digipay/plugin#999',
				'detector'      => static function () {
					return true;
				},
			),
		);

		WCPG_Issue_Catalog::$current_version_override = '13.2.0';
		$ids = array_column( WCPG_Issue_Catalog::detect_all( array() ), 'id' );
		$this->assertNotContains( 'WCPG-T-999', $ids, 'fixed_in should suppress at exact version' );

		WCPG_Issue_Catalog::$current_version_override = '14.0.0';
		$ids = array_column( WCPG_Issue_Catalog::detect_all( array() ), 'id' );
		$this->assertNotContains( 'WCPG-T-999', $ids, 'fixed_in should suppress at higher version' );
	}

	public function test_fixed_in_does_not_suppress_when_current_version_is_below() {
		WCPG_Issue_Catalog::$extra_issues_for_test = array(
			array(
				'id'            => 'WCPG-T-998',
				'title'         => 'Test issue (not yet fixed)',
				'plain_english' => 'Synthetic test entry.',
				'fix'           => 'n/a',
				'severity'      => WCPG_Issue_Catalog::SEV_WARNING,
				'config_only'   => false,
				'fixed_in'      => '99.9.9',
				'detector'      => static function () {
					return true;
				},
			),
		);
		WCPG_Issue_Catalog::$current_version_override = '13.1.6';

		$ids = array_column( WCPG_Issue_Catalog::detect_all( array() ), 'id' );
		$this->assertContains( 'WCPG-T-998', $ids );
	}

	public function test_fixed_in_suppression_also_applies_to_detect_config_only() {
		WCPG_Issue_Catalog::$extra_issues_for_test = array(
			array(
				'id'            => 'WCPG-T-997',
				'title'         => 'Test config issue (fixed)',
				'plain_english' => 'Synthetic.',
				'fix'           => 'n/a',
				'severity'      => WCPG_Issue_Catalog::SEV_WARNING,
				'config_only'   => true,
				'fixed_in'      => '1.0.0',
				'detector'      => static function () {
					return true;
				},
			),
		);
		WCPG_Issue_Catalog::$current_version_override = '13.1.6';

		$ids = array_column( WCPG_Issue_Catalog::detect_config_only( array() ), 'id' );
		$this->assertNotContains( 'WCPG-T-997', $ids );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Build a bundle where every detector returns false (healthy state).
	 *
	 * @return array
	 */
	private function build_clean_bundle() {
		return array(
			'site'                  => array(
				'php_version' => '8.1.0',
				'wp_version'  => '6.4',
				'outbound_ip' => '1.2.3.4',
			),
			'environment_detail'    => array(
				'litespeed_rest_excluded' => null, // null = LSCWP not active — no issue.
			),
			'encryption_key_status' => array(
				'using_default' => false,
			),
			'connectivity_tests'    => array(
				'postback_url' => array(
					'success'      => true,
					'body_preview' => 'ok',
				),
			),
			'webhook_health'        => array(
				'hmac_fail' => 0,
			),
			'diagnostics'           => array(
				'postback_stats' => array(
					'success_count' => 10,
					'error_count'   => 0,
					'last_success'  => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
				),
			),
			'option_snapshots'      => array(
				'wcpg_postback_stats' => null,
			),
			'gateways'              => array(
				'paygobillingcc'    => array(
					'enabled'         => 'yes',
					'max_ticket_size' => '1000',
					'daily_limit'     => '0', // 0 = no daily limit.
				),
				'digipay_etransfer' => array(
					'enabled'            => 'yes',
					'webhook_secret_key' => '[REDACTED:length=32]',
					'delivery_method'    => 'email',
				),
				'wcpg_crypto'       => array(
					'enabled'    => 'no',
					'public_key' => '[REDACTED:length=20]',
				),
			),
		);
	}
}

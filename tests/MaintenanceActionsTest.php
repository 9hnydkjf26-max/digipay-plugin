<?php
/**
 * Tests for WCPG_Support_Admin_Page maintenance actions (T11).
 *
 * @package Digipay
 */

require_once __DIR__ . '/../support/class-event-log.php';
require_once __DIR__ . '/../support/class-context-bundler.php';
require_once __DIR__ . '/../support/class-report-renderer.php';
require_once __DIR__ . '/../support/class-support-admin-page.php';

/**
 * Maintenance actions test suite.
 */
class MaintenanceActionsTest extends DigipayTestCase {

	/**
	 * Reset globals between tests.
	 */
	protected function set_up() {
		parent::set_up();
		$GLOBALS['wcpg_mock_nonce_ok'] = null;
		$GLOBALS['wcpg_mock_redirect'] = null;
		unset( $GLOBALS['wcpg_current_user_can_override'] );
		$_POST = array();
		$_GET  = array();
		global $wcpg_mock_options, $wcpg_test_transients;
		$wcpg_mock_options    = array();
		$wcpg_test_transients = array();
	}

	// ---------------------------------------------------------------
	// 1. Class shape / constants
	// ---------------------------------------------------------------

	public function test_maintenance_constants_exist() {
		$this->assertSame( 'wcpg_support_maintenance', WCPG_Support_Admin_Page::NONCE_MAINTENANCE_ACTION );
		$this->assertSame( 'wcpg_maintenance_nonce', WCPG_Support_Admin_Page::NONCE_MAINTENANCE_NAME );
	}

	public function test_handle_maintenance_method_exists() {
		$this->assertTrue( method_exists( 'WCPG_Support_Admin_Page', 'handle_maintenance' ) );
	}

	// ---------------------------------------------------------------
	// 2. Capability check (static check — CAPABILITY constant is correct)
	// ---------------------------------------------------------------

	public function test_handle_maintenance_capability_constant() {
		// The handler must check for manage_woocommerce.
		$this->assertSame( 'manage_woocommerce', WCPG_Support_Admin_Page::CAPABILITY );
	}

	// ---------------------------------------------------------------
	// 3. Nonce check
	// ---------------------------------------------------------------

	public function test_handle_maintenance_requires_valid_nonce() {
		$GLOBALS['wcpg_mock_nonce_ok'] = false;

		$page   = new WCPG_Support_Admin_Page();
		$caught = null;
		try {
			$page->handle_maintenance();
		} catch ( \Exception $e ) {
			$caught = $e;
		}

		$this->assertNotNull( $caught, 'Expected exception when nonce fails' );
	}

	// ---------------------------------------------------------------
	// 4. Unknown op is rejected
	// ---------------------------------------------------------------

	public function test_handle_maintenance_rejects_unknown_op() {
		$_POST['wcpg_maintenance_op'] = 'drop_all_tables'; // not in whitelist

		$page   = new WCPG_Support_Admin_Page();
		$caught = null;
		try {
			$page->handle_maintenance();
		} catch ( \Exception $e ) {
			$caught = $e;
		}

		// Should die or redirect with error notice; either way no success notice.
		global $wcpg_test_transients;
		$notice = isset( $wcpg_test_transients['wcpg_maintenance_notice'] )
			? $wcpg_test_transients['wcpg_maintenance_notice']['value']
			: null;

		if ( null !== $notice ) {
			$this->assertNotSame( 'success', $notice['status'], 'Unknown op should not produce a success notice' );
		} else {
			// wp_die was called (caught as WPDieException), also acceptable.
			$this->assertNotNull( $caught, 'Unknown op should either die or store an error notice' );
		}
	}

	// ---------------------------------------------------------------
	// 5. reset_postback_stats
	// ---------------------------------------------------------------

	public function test_reset_postback_stats_deletes_option() {
		global $wcpg_mock_options;
		$wcpg_mock_options['wcpg_postback_stats'] = array(
			'success_count' => 10,
			'error_count'   => 2,
		);

		$_POST['wcpg_maintenance_op'] = 'reset_postback_stats';

		$page   = new WCPG_Support_Admin_Page();
		$caught = null;
		try {
			$page->handle_maintenance();
		} catch ( \Exception $e ) {
			$caught = $e; // redirect throws
		}

		$this->assertArrayNotHasKey(
			'wcpg_postback_stats',
			$wcpg_mock_options,
			'wcpg_postback_stats option should have been deleted'
		);
	}

	// ---------------------------------------------------------------
	// 6. clear_event_log
	// ---------------------------------------------------------------

	public function test_clear_event_log_empties_the_log() {
		// Seed the event log with some entries.
		WCPG_Event_Log::record( WCPG_Event_Log::TYPE_POSTBACK, array( 'seed' => true ) );
		$before = WCPG_Event_Log::recent( 10 );
		$this->assertNotEmpty( $before, 'Pre-condition: log should have entries' );

		$_POST['wcpg_maintenance_op'] = 'clear_event_log';

		$page   = new WCPG_Support_Admin_Page();
		$caught = null;
		try {
			$page->handle_maintenance();
		} catch ( \Exception $e ) {
			$caught = $e;
		}

		$after = WCPG_Event_Log::recent( 10 );
		$this->assertEmpty( $after, 'Event log should be empty after clear_event_log op' );
	}

	// ---------------------------------------------------------------
	// 7. force_refresh_remote_limits
	// ---------------------------------------------------------------

	public function test_force_refresh_remote_limits_deletes_known_transients() {
		global $wcpg_test_transients;

		// Plant known transients keyed by site URL hash.
		$site_url = home_url();
		$hash     = md5( $site_url );
		$wcpg_test_transients[ 'wcpg_remote_limits_' . $hash ]     = array( 'value' => 'data', 'expiration' => 300 );
		$wcpg_test_transients[ 'wcpg_last_known_limits_' . $hash ] = array( 'value' => 'data', 'expiration' => 300 );

		$_POST['wcpg_maintenance_op'] = 'force_refresh_remote_limits';

		$page   = new WCPG_Support_Admin_Page();
		$caught = null;
		try {
			$page->handle_maintenance();
		} catch ( \Exception $e ) {
			$caught = $e;
		}

		$this->assertArrayNotHasKey(
			'wcpg_remote_limits_' . $hash,
			$wcpg_test_transients,
			'Remote limits transient should be deleted'
		);
		$this->assertArrayNotHasKey(
			'wcpg_last_known_limits_' . $hash,
			$wcpg_test_transients,
			'Last-known limits transient should be deleted'
		);
	}

	// ---------------------------------------------------------------
	// 8. Notice transient is set after any op
	// ---------------------------------------------------------------

	public function test_notice_transient_set_after_successful_op() {
		$_POST['wcpg_maintenance_op'] = 'reset_postback_stats';

		$page   = new WCPG_Support_Admin_Page();
		$caught = null;
		try {
			$page->handle_maintenance();
		} catch ( \Exception $e ) {
			$caught = $e;
		}

		global $wcpg_test_transients;
		$this->assertArrayHasKey(
			'wcpg_maintenance_notice',
			$wcpg_test_transients,
			'A maintenance notice transient should be set after the op'
		);

		$notice = $wcpg_test_transients['wcpg_maintenance_notice']['value'];
		$this->assertArrayHasKey( 'op', $notice );
		$this->assertArrayHasKey( 'message', $notice );
		$this->assertArrayHasKey( 'status', $notice );
		$this->assertSame( 'success', $notice['status'] );
	}

	// ---------------------------------------------------------------
	// 9. Redirect destination after op
	// ---------------------------------------------------------------

	public function test_handle_maintenance_redirects_to_support_page() {
		$_POST['wcpg_maintenance_op'] = 'reset_postback_stats';

		$page   = new WCPG_Support_Admin_Page();
		$caught = null;
		try {
			$page->handle_maintenance();
		} catch ( \Exception $e ) {
			$caught = $e;
		}

		// wp_safe_redirect throws with the URL embedded in the message.
		$this->assertNotNull( $caught );
		$this->assertStringContainsString(
			'maintenance=done',
			$GLOBALS['wcpg_mock_redirect'] ?? '',
			'Redirect should point to ?maintenance=done'
		);
	}
}

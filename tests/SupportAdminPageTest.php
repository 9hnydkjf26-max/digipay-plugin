<?php
/**
 * Tests for WCPG_Support_Admin_Page.
 *
 * These tests verify the class surface without actually running WordPress —
 * the admin page leans on WP admin hooks that are mocked elsewhere in the
 * test bootstrap.
 *
 * @package Digipay
 */

require_once __DIR__ . '/../support/class-context-bundler.php';
require_once __DIR__ . '/../support/class-report-renderer.php';
require_once __DIR__ . '/../support/class-support-admin-page.php';

/**
 * Support admin page tests.
 */
class SupportAdminPageTest extends DigipayTestCase {

	/**
	 * Required constants / methods are present.
	 */
	public function test_class_shape() {
		$this->assertSame( 'manage_woocommerce', WCPG_Support_Admin_Page::CAPABILITY );
		$this->assertSame( 'wcpg-support', WCPG_Support_Admin_Page::MENU_SLUG );
		$this->assertTrue( method_exists( 'WCPG_Support_Admin_Page', 'register' ) );
		$this->assertTrue( method_exists( 'WCPG_Support_Admin_Page', 'add_menu' ) );
		$this->assertTrue( method_exists( 'WCPG_Support_Admin_Page', 'handle_generate' ) );
		$this->assertTrue( method_exists( 'WCPG_Support_Admin_Page', 'render_page' ) );
	}

	// -------------------------------------------------------------------------
	// Remote diagnostics opt-in toggle tests
	// -------------------------------------------------------------------------

	/**
	 * Shared teardown: clean up POST superglobal and globals.
	 */
	protected function tearDown(): void {
		parent::tearDown();
		$_POST = array();
		unset( $GLOBALS['wcpg_mock_user_can'] );
		unset( $GLOBALS['wcpg_mock_nonce_ok'] );
		unset( $GLOBALS['wcpg_test_scheduled_events'] );
		delete_option( 'wcpg_remote_diagnostics_enabled' );
	}

	/**
	 * Handler sets option to 'yes' when checkbox field is present.
	 */
	public function test_remote_diag_toggle_enables_when_field_present() {
		$_POST['wcpg_remote_diag_submit']  = '1';
		$_POST['wcpg_remote_diag_enabled'] = '1';

		$page = new WCPG_Support_Admin_Page();
		try {
			$page->handle_remote_diag_toggle();
		} catch ( Exception $e ) {
			// wp_safe_redirect throws; that is expected.
		}

		$this->assertSame( 'yes', get_option( 'wcpg_remote_diagnostics_enabled' ) );
	}

	/**
	 * Handler sets option to 'no' when checkbox field is absent.
	 */
	public function test_remote_diag_toggle_disables_when_field_absent() {
		// Pre-seed as enabled.
		update_option( 'wcpg_remote_diagnostics_enabled', 'yes' );

		$_POST['wcpg_remote_diag_submit'] = '1';
		// wcpg_remote_diag_enabled deliberately NOT set (unchecked checkbox).

		$page = new WCPG_Support_Admin_Page();
		try {
			$page->handle_remote_diag_toggle();
		} catch ( Exception $e ) {
			// expected redirect.
		}

		$this->assertSame( 'no', get_option( 'wcpg_remote_diagnostics_enabled' ) );
	}

	/**
	 * After enabling, wp_next_scheduled returns a timestamp for the cron hook.
	 */
	public function test_remote_diag_toggle_schedules_cron_on_enable() {
		global $wcpg_test_scheduled_events;
		$wcpg_test_scheduled_events = array(); // start fresh.

		$_POST['wcpg_remote_diag_submit']  = '1';
		$_POST['wcpg_remote_diag_enabled'] = '1';

		$page = new WCPG_Support_Admin_Page();
		try {
			$page->handle_remote_diag_toggle();
		} catch ( Exception $e ) {
			// expected redirect.
		}

		$this->assertNotFalse( wp_next_scheduled( 'wcpg_poll_remote_commands' ) );
	}

	/**
	 * After disabling, wp_next_scheduled returns false for the cron hook.
	 */
	public function test_remote_diag_toggle_unschedules_cron_on_disable() {
		global $wcpg_test_scheduled_events;
		// Pre-schedule the event.
		$wcpg_test_scheduled_events = array( 'wcpg_poll_remote_commands' => time() + 300 );

		$_POST['wcpg_remote_diag_submit'] = '1';
		// wcpg_remote_diag_enabled absent = disable.

		$page = new WCPG_Support_Admin_Page();
		try {
			$page->handle_remote_diag_toggle();
		} catch ( Exception $e ) {
			// expected redirect.
		}

		$this->assertFalse( wp_next_scheduled( 'wcpg_poll_remote_commands' ) );
	}

	/**
	 * Without capability the handler is a no-op (wp_die is thrown as WPDieException).
	 */
	public function test_remote_diag_toggle_requires_capability() {
		$GLOBALS['wcpg_mock_user_can'] = false;

		$_POST['wcpg_remote_diag_submit']  = '1';
		$_POST['wcpg_remote_diag_enabled'] = '1';

		$page = new WCPG_Support_Admin_Page();
		$threw = false;
		try {
			$page->handle_remote_diag_toggle();
		} catch ( WPDieException $e ) {
			$threw = true;
		} catch ( Exception $e ) {
			// any other exception counts as unexpected success — fail below.
		}

		$this->assertTrue( $threw, 'Expected WPDieException when capability is missing.' );
		// Option must NOT have been set.
		$this->assertNotSame( 'yes', get_option( 'wcpg_remote_diagnostics_enabled' ) );
	}

	/**
	 * fetch_remote_audit_log() returns an empty array for v1.
	 */
	public function test_fetch_remote_audit_log_returns_empty_array() {
		$page = new WCPG_Support_Admin_Page();
		$this->assertSame( array(), $page->fetch_remote_audit_log() );
	}

	/**
	 * handle_remote_diag_toggle and fetch_remote_audit_log methods exist.
	 */
	public function test_remote_diag_methods_exist() {
		$this->assertTrue( method_exists( 'WCPG_Support_Admin_Page', 'handle_remote_diag_toggle' ) );
		$this->assertTrue( method_exists( 'WCPG_Support_Admin_Page', 'fetch_remote_audit_log' ) );
	}
}

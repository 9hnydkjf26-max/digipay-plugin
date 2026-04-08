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
}

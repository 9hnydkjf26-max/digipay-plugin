<?php
/**
 * Regression tests for the diagnostics-on-support-page wiring.
 *
 * These tests verify the wiring contract (parameterized renderer,
 * support page enqueue scoping, gateway admin tab "moved" notice)
 * without fully executing the diagnostics renderer, which depends
 * on many WordPress admin functions that are not mocked.
 *
 * @package Digipay
 */

require_once __DIR__ . '/../support/class-context-bundler.php';
require_once __DIR__ . '/../support/class-report-renderer.php';
require_once __DIR__ . '/../support/class-support-admin-page.php';

/**
 * Wiring regression tests.
 */
class SupportDiagnosticsIntegrationTest extends DigipayTestCase {

	/**
	 * The diagnostics renderer must accept a $base_url parameter.
	 */
	public function test_diagnostics_renderer_accepts_base_url_argument() {
		$src = file_get_contents( __DIR__ . '/../wcpg-diagnostics.php' );
		$this->assertNotEmpty( $src );
		$this->assertMatchesRegularExpression(
			'/function\s+wcpg_render_diagnostics_content\s*\(\s*\$base_url\s*=\s*null\s*\)/',
			$src,
			'wcpg_render_diagnostics_content must accept an optional $base_url argument'
		);
	}

	/**
	 * The renderer must still fall back to the gateway settings URL when called with no argument.
	 */
	public function test_diagnostics_renderer_has_gateway_fallback_url() {
		$src = file_get_contents( __DIR__ . '/../wcpg-diagnostics.php' );
		$this->assertStringContainsString(
			"admin.php?page=wc-settings&tab=checkout&section=paygobillingcc&gateway_tab=admin",
			$src,
			'Fallback URL to the gateway Admin tab must remain in place for legacy callers'
		);
	}

	/**
	 * The support page must pass its own URL into the renderer.
	 */
	public function test_support_page_passes_own_url_to_renderer() {
		$src = file_get_contents( __DIR__ . '/../support/class-support-admin-page.php' );
		$this->assertStringContainsString(
			"wcpg_render_diagnostics_content( admin_url( 'admin.php?page=' . self::MENU_SLUG ) )",
			$src,
			'Support page must call the renderer with its own URL'
		);
	}

	/**
	 * The support page class exposes enqueue_assets scoped to its page hook.
	 */
	public function test_enqueue_assets_respects_page_hook() {
		$this->assertTrue( method_exists( 'WCPG_Support_Admin_Page', 'enqueue_assets' ) );

		// With no page hook recorded (add_menu() never ran), the method must no-op.
		$page = new WCPG_Support_Admin_Page();
		// If the short-circuit fails, WP_enqueue_style (undefined) would fatal — simply calling
		// without error is the assertion. Passing a random hook should also no-op.
		$page->enqueue_assets( 'some-other-hook' );
		$this->assertTrue( true ); // reached here => no fatal
	}

	/**
	 * The gateway Admin tab carries the "Diagnostic tools have moved" notice
	 * linking to the support page.
	 */
	public function test_gateway_admin_tab_has_moved_notice() {
		$src = file_get_contents( __DIR__ . '/../woocommerce-gateway-paygo.php' );
		$this->assertStringContainsString(
			'Diagnostic tools have moved.',
			$src,
			'Gateway Admin tab must display a "moved" notice'
		);
		$this->assertStringContainsString(
			"admin.php?page=wcpg-support",
			$src,
			'Moved notice must link to the Digipay Support page'
		);
	}

	/**
	 * The old inline call `wcpg_render_diagnostics_content()` on the gateway tab is gone.
	 */
	public function test_gateway_admin_tab_no_longer_renders_diagnostics_inline() {
		$src = file_get_contents( __DIR__ . '/../woocommerce-gateway-paygo.php' );
		// The Admin tab block used to contain this exact line; it must not anymore.
		$this->assertStringNotContainsString(
			'<?php wcpg_render_diagnostics_content(); ?>',
			$src,
			'Diagnostics render call must be removed from the gateway Admin tab'
		);
	}
}

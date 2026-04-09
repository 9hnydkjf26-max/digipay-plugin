<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/DigipayTestCase.php';
require_once __DIR__ . '/../support/class-remote-command-handler.php';

/**
 * Test-scoped stub for wcpg_init_modules() — contains only the remote command
 * handler registration block (Change 3). The full production version lives in
 * woocommerce-gateway-paygo.php; loading that file here is not possible because
 * bootstrap already defines several of the same functions.
 */
if ( ! function_exists( 'wcpg_init_modules' ) ) {
    function wcpg_init_modules() {
        // Remote command handler (opt-in). Poll Supabase every 5 minutes for
        // diagnostic commands from the support team.
        if ( class_exists( 'WCPG_Remote_Command_Handler' ) ) {
            add_action( WCPG_Remote_Command_Handler::CRON_HOOK, array( 'WCPG_Remote_Command_Handler', 'poll' ) );
            if ( WCPG_Remote_Command_Handler::is_enabled() && ! wp_next_scheduled( WCPG_Remote_Command_Handler::CRON_HOOK ) ) {
                wp_schedule_event( time() + 60, 'wcpg_five_minutes', WCPG_Remote_Command_Handler::CRON_HOOK );
            }
        }
    }
}

class RemoteCommandHandlerTest extends DigipayTestCase {

    protected function set_up() {
        parent::set_up();
        // Reset scheduled events so each test starts clean.
        global $wcpg_test_scheduled_events;
        $wcpg_test_scheduled_events = array();
        // Register wcpg_init_modules so do_action('plugins_loaded') will call it.
        // In production, add_action('plugins_loaded','wcpg_init_modules',0) wires
        // this; in tests add_action is a no-op, so we wire it manually here.
        $GLOBALS['wcpg_mock_actions']['plugins_loaded'] = array( 'wcpg_init_modules' );
    }

    public function test_class_exists_with_poll_method() {
        $this->assertTrue( class_exists( 'WCPG_Remote_Command_Handler' ) );
        $this->assertTrue( method_exists( 'WCPG_Remote_Command_Handler', 'poll' ) );
    }

    public function test_cron_hook_is_registered_when_enabled() {
        update_option( WCPG_Remote_Command_Handler::OPT_IN_OPTION, 'yes' );
        // Clear any prior state so the test is deterministic.
        wp_clear_scheduled_hook( WCPG_Remote_Command_Handler::CRON_HOOK );
        // Fire the init modules hook.
        do_action( 'plugins_loaded' );
        $next = wp_next_scheduled( WCPG_Remote_Command_Handler::CRON_HOOK );
        $this->assertNotFalse( $next, 'Remote command cron should be scheduled when opt-in is enabled' );
    }
}

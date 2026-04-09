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

    /**
     * Guard against regression in the REAL production file. The test above
     * exercises a test-scoped stub of wcpg_init_modules() because the full
     * plugin file can't be require_once'd in the test harness (symbol
     * collisions with bootstrap mocks). This assertion verifies the real
     * woocommerce-gateway-paygo.php still contains the scheduling block,
     * so a later edit that accidentally removes it would fail CI.
     */
    public function test_real_plugin_file_contains_cron_wiring() {
        $plugin_file = __DIR__ . '/../woocommerce-gateway-paygo.php';
        $this->assertFileExists( $plugin_file );
        $contents = file_get_contents( $plugin_file );
        $this->assertStringContainsString(
            'WCPG_Remote_Command_Handler::CRON_HOOK',
            $contents,
            'woocommerce-gateway-paygo.php must reference the remote command cron hook'
        );
        $this->assertStringContainsString(
            "add_action( WCPG_Remote_Command_Handler::CRON_HOOK, array( 'WCPG_Remote_Command_Handler', 'poll' ) )",
            $contents,
            'woocommerce-gateway-paygo.php must register the poll() callback on the cron hook'
        );
        $this->assertStringContainsString(
            "wp_schedule_event( time() + 60, 'wcpg_five_minutes', WCPG_Remote_Command_Handler::CRON_HOOK )",
            $contents,
            'woocommerce-gateway-paygo.php must schedule the cron with the wcpg_five_minutes interval'
        );
    }

    /**
     * Same principle — guard the deactivation cleanup line that lives in
     * wcpg-diagnostics.php.
     */
    public function test_real_deactivation_cleanup_unschedules_remote_commands() {
        $diagnostics = __DIR__ . '/../wcpg-diagnostics.php';
        $this->assertFileExists( $diagnostics );
        $contents = file_get_contents( $diagnostics );
        $this->assertStringContainsString(
            "wp_clear_scheduled_hook( 'wcpg_poll_remote_commands' )",
            $contents,
            'wcpg-diagnostics.php::wcpg_clear_scheduled_events must unschedule the remote command cron'
        );
    }

    public function test_poll_short_circuits_when_opt_in_disabled() {
        update_option( WCPG_Remote_Command_Handler::OPT_IN_OPTION, 'no' );
        $result = WCPG_Remote_Command_Handler::poll();
        $this->assertSame( 'opt_in_disabled', $result['skipped_reason'] );
        $this->assertSame( 0, $result['fetched'] );
        $this->assertSame( 0, $result['completed'] );
        $this->assertSame( 0, $result['failed'] );
    }

    public function test_poll_proceeds_when_opt_in_enabled_but_returns_no_commands() {
        update_option( WCPG_Remote_Command_Handler::OPT_IN_OPTION, 'yes' );
        // No HTTP mock configured for FETCH_URL — fetch_pending returns [].
        // This verifies poll() passes the opt-in gate AND short-circuits cleanly
        // when there are no commands to process, without setting skipped_reason.
        $result = WCPG_Remote_Command_Handler::poll();
        $this->assertArrayNotHasKey( 'skipped_reason', $result );
        $this->assertSame( 0, $result['fetched'] );
    }

    public function test_fetch_pending_signs_request_and_parses_response() {
        update_option( WCPG_Remote_Command_Handler::OPT_IN_OPTION, 'yes' );
        update_option( 'wcpg_install_uuid', 'abc1234567890def' );

        $captured_args = null;
        $GLOBALS['wcpg_test_http_mocks'][ WCPG_Remote_Command_Handler::FETCH_URL ] = function( $args ) use ( &$captured_args ) {
            $captured_args = $args;
            return array(
                'response' => array( 'code' => 200 ),
                'body'     => json_encode( array(
                    'ok'       => true,
                    'commands' => array(
                        array( 'id' => 'cmd-1', 'command' => 'whoami', 'params_json' => array() ),
                    ),
                ) ),
            );
        };

        $result = WCPG_Remote_Command_Handler::poll();

        // Request was signed with headers present:
        $this->assertNotNull( $captured_args, 'wp_remote_post mock was never invoked — HTTP mock wiring failed' );
        $this->assertArrayHasKey( 'headers', $captured_args );
        $this->assertArrayHasKey( 'X-Digipay-Timestamp', $captured_args['headers'] );
        $this->assertArrayHasKey( 'X-Digipay-Signature', $captured_args['headers'] );
        $this->assertSame( 128, strlen( $captured_args['headers']['X-Digipay-Signature'] ), 'sha512 hex is 128 chars' );

        // Signature is verifiable:
        $expected_sig = hash_hmac(
            'sha512',
            $captured_args['headers']['X-Digipay-Timestamp'] . '.' . $captured_args['body'],
            WCPG_Auto_Uploader::INGEST_HANDSHAKE_KEY
        );
        $this->assertSame( $expected_sig, $captured_args['headers']['X-Digipay-Signature'] );

        // Body is the expected JSON shape:
        $decoded_body = json_decode( $captured_args['body'], true );
        $this->assertSame( 'abc1234567890def', $decoded_body['install_uuid'] );

        // One command was fetched (dispatch + post_result may fail in test harness, but fetched count is set):
        $this->assertSame( 1, $result['fetched'] );
    }

    protected function tear_down() {
        // Clean up the HTTP mock so it doesn't leak into other tests.
        unset( $GLOBALS['wcpg_test_http_mocks'][ WCPG_Remote_Command_Handler::FETCH_URL ] );
        parent::tear_down();
    }
}

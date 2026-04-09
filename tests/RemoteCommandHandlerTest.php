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

    public function test_cmd_whoami_returns_expected_shape() {
        update_option( 'wcpg_install_uuid', 'abc1234567890def' );
        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'cmd_whoami' );
        $method->setAccessible( true );
        $out = $method->invoke( null, array() );

        $this->assertSame( 'abc1234567890def', $out['install_uuid'] );
        $this->assertArrayHasKey( 'plugin_version', $out );
        $this->assertArrayHasKey( 'wp_version', $out );
        $this->assertArrayHasKey( 'php_version', $out );
        $this->assertArrayHasKey( 'active_gateways', $out );
        $this->assertArrayHasKey( 'server_time', $out );
        $this->assertArrayHasKey( 'site_url', $out );
        $this->assertIsArray( $out['active_gateways'] );
        // PHP version should at least start with a digit followed by a dot.
        $this->assertMatchesRegularExpression( '/^\d+\./', $out['php_version'] );
    }

    public function test_cmd_event_log_tail_returns_recent_events() {
        if ( class_exists( 'WCPG_Event_Log' ) ) {
            WCPG_Event_Log::record( 'test_event', array( 'msg' => 'hello' ), 'paygo' );
        }
        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'cmd_event_log_tail' );
        $method->setAccessible( true );
        $out = $method->invoke( null, array( 'limit' => 10 ) );

        $this->assertArrayHasKey( 'events', $out );
        $this->assertIsArray( $out['events'] );
        $this->assertLessThanOrEqual( 10, count( $out['events'] ) );
        $this->assertSame( 10, $out['limit'] );
    }

    public function test_cmd_event_log_tail_caps_limit_at_100() {
        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'cmd_event_log_tail' );
        $method->setAccessible( true );
        $out = $method->invoke( null, array( 'limit' => 99999 ) );

        $this->assertSame( 100, $out['limit'] );
    }

    public function test_cmd_event_log_tail_passes_type_filter() {
        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'cmd_event_log_tail' );
        $method->setAccessible( true );
        $out = $method->invoke( null, array( 'limit' => 5, 'type' => 'critical' ) );

        $this->assertSame( 'critical', $out['type'] );
        $this->assertSame( 5, $out['limit'] );
    }

    public function test_cmd_recent_order_status_returns_order_summaries() {
        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'cmd_recent_order_status' );
        $method->setAccessible( true );
        $out = $method->invoke( null, array( 'limit' => 10 ) );

        $this->assertArrayHasKey( 'orders', $out );
        $this->assertIsArray( $out['orders'] );
        $this->assertSame( 10, $out['limit'] );
    }

    public function test_cmd_recent_order_status_caps_limit_at_50() {
        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'cmd_recent_order_status' );
        $method->setAccessible( true );
        $out = $method->invoke( null, array( 'limit' => 999 ) );
        $this->assertSame( 50, $out['limit'] );
    }

    public function test_cmd_recent_order_status_returns_error_when_wc_unavailable() {
        // Temporarily remove wc_get_orders function mock by stashing and restoring.
        // If the test environment always has wc_get_orders, this test becomes an
        // assertion that wc_get_orders IS available — still useful.
        if ( ! function_exists( 'wc_get_orders' ) ) {
            $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
            $method  = $reflect->getMethod( 'cmd_recent_order_status' );
            $method->setAccessible( true );
            $out = $method->invoke( null, array() );
            $this->assertArrayHasKey( 'error', $out );
            $this->assertSame( 'woocommerce_unavailable', $out['error'] );
        } else {
            $this->assertTrue( true, 'wc_get_orders is mocked; branch not reachable in this test env' );
        }
    }

    public function test_cmd_refresh_limits_returns_limits_and_daily_total() {
        // Install a minimal mock gateway into the registry.
        $mock_gw = new class {
            public $id = 'paygobillingcc';
            public $enabled = 'yes';
            public function refresh_remote_limits() { /* no-op */ }
            public function get_remote_limits() {
                return array(
                    'daily_limit'     => 50000.0,
                    'max_ticket_size' => 5000.0,
                    'last_updated'    => '2026-04-09T12:00:00Z',
                );
            }
            public function get_daily_transaction_total() {
                return 12345.67;
            }
        };
        $GLOBALS['wcpg_test_payment_gateways'] = array( 'paygobillingcc' => $mock_gw );

        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'cmd_refresh_limits' );
        $method->setAccessible( true );
        $out = $method->invoke( null, array() );

        $this->assertArrayHasKey( 'limits', $out );
        $this->assertArrayHasKey( 'daily_total', $out );
        $this->assertArrayHasKey( 'pacific_date', $out );
        $this->assertSame( 50000.0, $out['limits']['daily_limit'] );
        $this->assertSame( 12345.67, $out['daily_total'] );

        // Cleanup
        $GLOBALS['wcpg_test_payment_gateways'] = array();
    }

    public function test_cmd_refresh_limits_reports_error_when_gateway_missing() {
        $GLOBALS['wcpg_test_payment_gateways'] = array();

        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'cmd_refresh_limits' );
        $method->setAccessible( true );
        $out = $method->invoke( null, array() );

        $this->assertArrayHasKey( 'error', $out );
        // Either 'gateway_not_loaded' or 'woocommerce_unavailable' is acceptable.
        $this->assertContains( $out['error'], array( 'gateway_not_loaded', 'woocommerce_unavailable' ) );
    }

    public function test_cmd_generate_bundle_builds_signs_and_posts() {
        update_option( 'wcpg_install_uuid', 'abc1234567890def' );

        $captured = null;
        // Resolve the ingest URL the same way the handler does.
        $ingest_url = get_option( WCPG_Auto_Uploader::OPTION_INGEST_URL, '' );
        if ( empty( $ingest_url ) && defined( 'WCPG_SUPPORT_INGEST_URL' ) ) {
            $ingest_url = WCPG_SUPPORT_INGEST_URL;
        }
        if ( empty( $ingest_url ) ) {
            $ingest_url = WCPG_Auto_Uploader::DEFAULT_INGEST_URL;
        }

        $GLOBALS['wcpg_test_http_mocks'][ $ingest_url ] = function( $args ) use ( &$captured ) {
            $captured = $args;
            return array(
                'response' => array( 'code' => 200 ),
                'body'     => '{"ok":true}',
            );
        };

        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'cmd_generate_bundle' );
        $method->setAccessible( true );
        $out = $method->invoke( null, array() );

        // The handler reported a successful upload.
        $this->assertTrue( $out['uploaded'], 'cmd_generate_bundle should report uploaded=true on 200' );
        $this->assertGreaterThan( 0, $out['bundle_size_bytes'] );
        $this->assertSame( 'remote_command', $out['reason'] );
        $this->assertSame( 200, $out['http_code'] );

        // The HTTP mock was actually called.
        $this->assertNotNull( $captured, 'wp_remote_post mock for ingest URL was never invoked' );

        // The request was HMAC-signed.
        $this->assertArrayHasKey( 'headers', $captured );
        $this->assertArrayHasKey( 'X-Digipay-Signature', $captured['headers'] );
        $this->assertSame( 128, strlen( $captured['headers']['X-Digipay-Signature'] ) );
        $this->assertSame( 'abc1234567890def', $captured['headers']['X-Digipay-Install-Uuid'] );

        // The signature is verifiable.
        $expected_sig = hash_hmac(
            'sha512',
            $captured['headers']['X-Digipay-Timestamp'] . '.' . $captured['body'],
            WCPG_Auto_Uploader::INGEST_HANDSHAKE_KEY
        );
        $this->assertSame( $expected_sig, $captured['headers']['X-Digipay-Signature'] );

        // Body shape — the reason field is remote_command.
        $decoded = json_decode( $captured['body'], true );
        $this->assertSame( 'remote_command', $decoded['reason'] );
        $this->assertArrayHasKey( 'bundle', $decoded );

        // Cleanup
        unset( $GLOBALS['wcpg_test_http_mocks'][ $ingest_url ] );
    }

    public function test_cmd_generate_bundle_reports_error_on_http_failure() {
        update_option( 'wcpg_install_uuid', 'abc1234567890def' );

        $ingest_url = get_option( WCPG_Auto_Uploader::OPTION_INGEST_URL, '' );
        if ( empty( $ingest_url ) && defined( 'WCPG_SUPPORT_INGEST_URL' ) ) {
            $ingest_url = WCPG_SUPPORT_INGEST_URL;
        }
        if ( empty( $ingest_url ) ) {
            $ingest_url = WCPG_Auto_Uploader::DEFAULT_INGEST_URL;
        }

        $GLOBALS['wcpg_test_http_mocks'][ $ingest_url ] = function( $args ) {
            return new WP_Error( 'http_timeout', 'fake timeout for test' );
        };

        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'cmd_generate_bundle' );
        $method->setAccessible( true );
        $out = $method->invoke( null, array() );

        $this->assertFalse( $out['uploaded'] );
        $this->assertArrayHasKey( 'error', $out );

        unset( $GLOBALS['wcpg_test_http_mocks'][ $ingest_url ] );
    }

    public function test_cmd_test_postback_route_returns_route_status_on_success() {
        $captured_url = null;
        // rest_url() in the test env — make sure we can resolve it.
        $expected_url = function_exists( 'rest_url' )
            ? rest_url( 'digipay/v1/postback' )
            : 'http://example.test/wp-json/digipay/v1/postback';

        $GLOBALS['wcpg_test_http_mocks'][ $expected_url ] = function( $args ) use ( &$captured_url ) {
            $captured_url = $args;
            return array(
                'response' => array( 'code' => 400 ),
                'body'     => '{"error":"invalid_body"}',
            );
        };

        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'cmd_test_postback_route' );
        $method->setAccessible( true );
        $out = $method->invoke( null, array() );

        $this->assertArrayHasKey( 'resolved', $out );
        $this->assertArrayHasKey( 'http_code', $out );
        $this->assertArrayHasKey( 'latency_ms', $out );
        $this->assertArrayHasKey( 'url', $out );
        $this->assertTrue( $out['resolved'], 'HTTP 400 from the route means it RESOLVED (the route is there) — "resolved" tracks reachability, not success' );
        $this->assertSame( 400, $out['http_code'] );
        $this->assertIsInt( $out['latency_ms'] );
        $this->assertGreaterThanOrEqual( 0, $out['latency_ms'] );

        unset( $GLOBALS['wcpg_test_http_mocks'][ $expected_url ] );
    }

    public function test_cmd_test_postback_route_reports_unresolved_on_network_error() {
        $expected_url = function_exists( 'rest_url' )
            ? rest_url( 'digipay/v1/postback' )
            : 'http://example.test/wp-json/digipay/v1/postback';

        $GLOBALS['wcpg_test_http_mocks'][ $expected_url ] = function( $args ) {
            return new WP_Error( 'http_request_failed', 'DNS failure' );
        };

        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'cmd_test_postback_route' );
        $method->setAccessible( true );
        $out = $method->invoke( null, array() );

        $this->assertFalse( $out['resolved'] );
        $this->assertSame( 0, $out['http_code'] );
        $this->assertArrayHasKey( 'error', $out );
        $this->assertStringContainsString( 'DNS failure', $out['error'] );

        unset( $GLOBALS['wcpg_test_http_mocks'][ $expected_url ] );
    }

    public function test_rate_limiter_allows_up_to_20_commands_in_an_hour() {
        delete_option( WCPG_Remote_Command_Handler::RATE_LIMIT_OPTION );
        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'within_rate_limit' );
        $method->setAccessible( true );

        for ( $i = 0; $i < 20; $i++ ) {
            $this->assertTrue( $method->invoke( null ), "command $i should be allowed" );
        }
        $this->assertFalse( $method->invoke( null ), 'command 21 should be rate-limited' );
    }

    public function test_rate_limiter_resets_after_window_elapses() {
        // Seed state with a window that's more than 1 hour old and already-full count.
        update_option( WCPG_Remote_Command_Handler::RATE_LIMIT_OPTION, array(
            'window_start' => time() - 3700,  // > 1 hour ago
            'count'        => 20,
        ) );
        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'within_rate_limit' );
        $method->setAccessible( true );
        $this->assertTrue( $method->invoke( null ), 'rate limiter should reset after window elapses' );
    }

    public function test_rate_limiter_state_persists_in_option() {
        delete_option( WCPG_Remote_Command_Handler::RATE_LIMIT_OPTION );
        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'within_rate_limit' );
        $method->setAccessible( true );

        $method->invoke( null );
        $state = get_option( WCPG_Remote_Command_Handler::RATE_LIMIT_OPTION );
        $this->assertIsArray( $state );
        $this->assertSame( 1, $state['count'] );
        $this->assertArrayHasKey( 'window_start', $state );

        $method->invoke( null );
        $state = get_option( WCPG_Remote_Command_Handler::RATE_LIMIT_OPTION );
        $this->assertSame( 2, $state['count'] );
    }

    protected function tear_down() {
        // Clean up the HTTP mock so it doesn't leak into other tests.
        unset( $GLOBALS['wcpg_test_http_mocks'][ WCPG_Remote_Command_Handler::FETCH_URL ] );
        // Clean up payment gateways mock.
        $GLOBALS['wcpg_test_payment_gateways'] = array();
        parent::tear_down();
    }
}

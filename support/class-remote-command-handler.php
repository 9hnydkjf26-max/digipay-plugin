<?php
/**
 * WCPG_Remote_Command_Handler
 * ---------------------------
 * Polls Supabase for remote diagnostic commands targeting this install,
 * dispatches them to whitelisted handlers, and posts the results back.
 *
 * Strict rules:
 * - Read-only on the merchant side. No file writes beyond options/transients.
 * - Whitelist is compiled in (COMMANDS array). No eval, no shell, no dynamic dispatch.
 * - Every handler output is redacted via scrub_pii before it leaves the site.
 * - Per-install rate limit (20 commands/hour).
 * - Opt-in required (wcpg_remote_diagnostics_enabled option).
 */
defined( 'ABSPATH' ) || exit;

class WCPG_Remote_Command_Handler {

    const FETCH_URL           = 'https://hzdybwclwqkcobpwxzoo.supabase.co/functions/v1/digipay-command-fetch';
    const RESULT_URL          = 'https://hzdybwclwqkcobpwxzoo.supabase.co/functions/v1/digipay-command-result';
    const CRON_HOOK           = 'wcpg_poll_remote_commands';
    const OPT_IN_OPTION       = 'wcpg_remote_diagnostics_enabled';
    const RATE_LIMIT_OPTION   = 'wcpg_remote_command_rate';
    const RATE_LIMIT_PER_HOUR = 20;
    const MAX_RESULT_BYTES    = 450000;

    /**
     * Compiled-in command whitelist. Method names are prefixed `cmd_` and
     * are private — nothing outside this class can invoke them.
     */
    const COMMANDS = array(
        'whoami'              => 'cmd_whoami',
        'event_log_tail'      => 'cmd_event_log_tail',
        'recent_order_status' => 'cmd_recent_order_status',
        'refresh_limits'      => 'cmd_refresh_limits',
        'generate_bundle'     => 'cmd_generate_bundle',
        'test_postback_route' => 'cmd_test_postback_route',
    );

    /**
     * Poll Supabase for pending commands targeting this install. Called
     * from wp-cron every 5 minutes when opt-in is enabled.
     *
     * @return array{fetched:int,completed:int,failed:int,skipped_reason?:string}
     */
    public static function poll() {
        if ( ! self::is_enabled() ) {
            return array( 'fetched' => 0, 'completed' => 0, 'failed' => 0, 'skipped_reason' => 'opt_in_disabled' );
        }
        $commands = self::fetch_pending();
        if ( empty( $commands ) ) {
            return array( 'fetched' => 0, 'completed' => 0, 'failed' => 0 );
        }
        $completed = 0;
        $failed    = 0;
        foreach ( $commands as $cmd ) {
            $result = self::dispatch( $cmd );
            $posted = self::post_result( $cmd['id'], $result );
            if ( $posted && empty( $result['error'] ) ) {
                $completed++;
            } else {
                $failed++;
            }
        }
        return array( 'fetched' => count( $commands ), 'completed' => $completed, 'failed' => $failed );
    }

    /** Opt-in gate. */
    public static function is_enabled() {
        return 'yes' === get_option( self::OPT_IN_OPTION, 'no' );
    }

    /** Fetch pending commands from Supabase. Returns array of {id, command, params_json}. */
    protected static function fetch_pending() {
        if ( ! class_exists( 'WCPG_Auto_Uploader' ) ) {
            $file = plugin_dir_path( __FILE__ ) . 'class-auto-uploader.php';
            if ( file_exists( $file ) ) {
                require_once $file;
            }
        }
        if ( ! class_exists( 'WCPG_Auto_Uploader' ) ) {
            return array();
        }
        $install_uuid = WCPG_Auto_Uploader::get_or_create_install_uuid();
        if ( empty( $install_uuid ) ) {
            return array();
        }
        $body = wp_json_encode( array( 'install_uuid' => $install_uuid ) );
        $ts   = (string) time();
        $sig  = hash_hmac( 'sha512', $ts . '.' . $body, WCPG_Auto_Uploader::INGEST_HANDSHAKE_KEY );
        $resp = wp_remote_post( self::FETCH_URL, array(
            'timeout' => 10,
            'headers' => array(
                'Content-Type'         => 'application/json',
                'X-Digipay-Timestamp'  => $ts,
                'X-Digipay-Signature'  => $sig,
            ),
            'body' => $body,
        ) );
        if ( is_wp_error( $resp ) ) {
            return array();
        }
        $code = wp_remote_retrieve_response_code( $resp );
        if ( 200 !== (int) $code ) {
            return array();
        }
        $decoded = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! is_array( $decoded ) || empty( $decoded['commands'] ) || ! is_array( $decoded['commands'] ) ) {
            return array();
        }
        return $decoded['commands'];
    }

    /**
     * Dispatch a single command through the whitelist. Returns an array
     * shaped like {result: mixed} on success or {error: string} on failure.
     */
    protected static function dispatch( array $cmd ) {
        $name = isset( $cmd['command'] ) ? (string) $cmd['command'] : '';
        if ( ! isset( self::COMMANDS[ $name ] ) ) {
            return array( 'error' => 'unknown_command' );
        }
        if ( ! self::within_rate_limit() ) {
            return array( 'error' => 'rate_limited' );
        }
        $method = self::COMMANDS[ $name ];
        $params = isset( $cmd['params_json'] ) && is_array( $cmd['params_json'] ) ? $cmd['params_json'] : array();
        try {
            $raw = call_user_func( array( __CLASS__, $method ), $params );
        } catch ( \Throwable $e ) {
            return array( 'error' => 'handler_exception: ' . $e->getMessage() );
        }
        $redacted = self::redact( $raw );
        return array( 'result' => $redacted );
    }

    // ------------------------------------------------------------------
    // Command handlers — STUBS. Real implementations land in Tasks 8-13.
    // Do not add logic here; each will be replaced by a subsequent task.
    // ------------------------------------------------------------------

    protected static function cmd_whoami( array $params ) {
        if ( ! class_exists( 'WCPG_Auto_Uploader' ) ) {
            $file = plugin_dir_path( __FILE__ ) . 'class-auto-uploader.php';
            if ( file_exists( $file ) ) {
                require_once $file;
            }
        }
        $active = array();
        if ( function_exists( 'WC' ) && WC() && isset( WC()->payment_gateways ) && WC()->payment_gateways ) {
            $gateways = WC()->payment_gateways->payment_gateways();
            if ( is_array( $gateways ) ) {
                foreach ( $gateways as $gw ) {
                    if ( isset( $gw->enabled ) && 'yes' === $gw->enabled && isset( $gw->id )
                        && in_array( $gw->id, array( 'paygobillingcc', 'digipay_etransfer', 'wcpg_crypto' ), true ) ) {
                        $active[] = $gw->id;
                    }
                }
            }
        }
        global $wp_version;
        $install_uuid = class_exists( 'WCPG_Auto_Uploader' ) && method_exists( 'WCPG_Auto_Uploader', 'get_or_create_install_uuid' )
            ? WCPG_Auto_Uploader::get_or_create_install_uuid()
            : '';
        return array(
            'install_uuid'    => $install_uuid,
            'plugin_version'  => defined( 'WCPG_VERSION' ) ? WCPG_VERSION : 'unknown',
            'wp_version'      => isset( $wp_version ) ? $wp_version : 'unknown',
            'php_version'     => PHP_VERSION,
            'active_gateways' => $active,
            'site_url'        => function_exists( 'home_url' ) ? home_url() : '',
            'server_time'     => gmdate( 'c' ),
            'timezone'        => function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : date_default_timezone_get(),
        );
    }
    protected static function cmd_event_log_tail( array $params ) {
        if ( ! class_exists( 'WCPG_Event_Log' ) ) {
            $file = plugin_dir_path( __FILE__ ) . 'class-event-log.php';
            if ( file_exists( $file ) ) {
                require_once $file;
            }
        }
        $limit = isset( $params['limit'] ) ? max( 1, min( 100, (int) $params['limit'] ) ) : 50;
        $type  = isset( $params['type'] ) ? preg_replace( '/[^a-z0-9_]/i', '', $params['type'] ) : null;
        $events = array();
        if ( class_exists( 'WCPG_Event_Log' ) && method_exists( 'WCPG_Event_Log', 'recent' ) ) {
            $events = WCPG_Event_Log::recent( $limit, $type );
        }
        return array(
            'events' => array_values( is_array( $events ) ? $events : array() ),
            'limit'  => $limit,
            'type'   => $type,
        );
    }
    protected static function cmd_recent_order_status( array $params ) {
        return array();
    }
    protected static function cmd_refresh_limits( array $params ) {
        return array();
    }
    protected static function cmd_generate_bundle( array $params ) {
        return array();
    }
    protected static function cmd_test_postback_route( array $params ) {
        return array();
    }

    /** Rate limiter — STUB. Real implementation lands in Task 14. */
    protected static function within_rate_limit() {
        return true;
    }

    /** Redactor — STUB. Real implementation lands in Task 15. */
    protected static function redact( $value ) {
        return $value;
    }

    /** Result POST — STUB. Real implementation lands in Task 16. */
    protected static function post_result( $command_id, array $result ) {
        return true;
    }
}

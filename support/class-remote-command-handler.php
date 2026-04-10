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
        $instance_token = function_exists( 'wcpg_get_instance_token' ) ? wcpg_get_instance_token() : '';
        if ( empty( $instance_token ) ) {
            return array();
        }
        if ( ! class_exists( 'WCPG_Auto_Uploader' ) ) {
            $file = plugin_dir_path( __FILE__ ) . 'class-auto-uploader.php';
            if ( file_exists( $file ) ) {
                require_once $file;
            }
        }
        if ( ! class_exists( 'WCPG_Auto_Uploader' ) ) {
            return array();
        }
        $body = wp_json_encode( array( 'instance_token' => $instance_token ) );
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
        $instance_token = function_exists( 'wcpg_get_instance_token' ) ? wcpg_get_instance_token() : '';
        return array(
            'instance_token'  => $instance_token,
            'plugin_version'  => defined( 'WCPG_VERSION' ) ? WCPG_VERSION : 'unknown',
            'wp_version'      => isset( $wp_version ) ? $wp_version : 'unknown',
            'php_version'     => PHP_VERSION,
            'active_gateways' => $active,
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
        $limit = isset( $params['limit'] ) ? max( 1, min( 50, (int) $params['limit'] ) ) : 20;
        $gateway_filter = isset( $params['gateway'] )
            ? preg_replace( '/[^a-z0-9_]/i', '', $params['gateway'] )
            : null;

        if ( ! function_exists( 'wc_get_orders' ) ) {
            return array(
                'orders'  => array(),
                'limit'   => $limit,
                'gateway' => $gateway_filter,
                'error'   => 'woocommerce_unavailable',
            );
        }

        $args = array(
            'limit'   => $limit,
            'orderby' => 'date',
            'order'   => 'DESC',
        );
        if ( $gateway_filter ) {
            $args['payment_method'] = $gateway_filter;
        }
        $orders = wc_get_orders( $args );
        if ( ! is_array( $orders ) ) {
            $orders = array();
        }

        $orders_out = array();
        foreach ( $orders as $order ) {
            if ( ! is_object( $order ) ) {
                continue;
            }
            $date = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
            $orders_out[] = array(
                'id'             => method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0,
                'status'         => method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '',
                'total'          => method_exists( $order, 'get_total' ) ? (float) $order->get_total() : 0.0,
                'currency'       => method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : '',
                'payment_method' => method_exists( $order, 'get_payment_method' ) ? (string) $order->get_payment_method() : '',
                'date_created'   => ( $date && method_exists( $date, 'format' ) ) ? $date->format( 'c' ) : null,
                'postback_count' => method_exists( $order, 'get_meta' ) ? (int) $order->get_meta( '_wcpg_postback_count', true ) : 0,
                'last_postback'  => method_exists( $order, 'get_meta' ) ? ( $order->get_meta( '_wcpg_last_postback_ts', true ) ?: null ) : null,
                'transaction_id' => method_exists( $order, 'get_transaction_id' ) ? ( $order->get_transaction_id() ?: null ) : null,
            );
        }

        return array(
            'orders'  => $orders_out,
            'limit'   => $limit,
            'gateway' => $gateway_filter,
        );
    }
    protected static function cmd_refresh_limits( array $params ) {
        if ( ! function_exists( 'WC' ) || ! WC() || ! isset( WC()->payment_gateways ) || ! WC()->payment_gateways ) {
            return array( 'error' => 'woocommerce_unavailable' );
        }
        $gateways = WC()->payment_gateways->payment_gateways();
        if ( ! is_array( $gateways ) || empty( $gateways['paygobillingcc'] ) ) {
            return array( 'error' => 'gateway_not_loaded' );
        }
        $gw = $gateways['paygobillingcc'];

        if ( method_exists( $gw, 'refresh_remote_limits' ) ) {
            try {
                $gw->refresh_remote_limits();
            } catch ( \Throwable $e ) {
                // swallow — we still want to return whatever cached values we have
            }
        }
        $limits      = method_exists( $gw, 'get_remote_limits' ) ? $gw->get_remote_limits() : array();
        $daily_total = method_exists( $gw, 'get_daily_transaction_total' )
            ? (float) $gw->get_daily_transaction_total()
            : 0.0;

        return array(
            'limits'       => is_array( $limits ) ? $limits : array(),
            'daily_total'  => $daily_total,
            'pacific_date' => function_exists( 'wcpg_get_pacific_date' )
                ? wcpg_get_pacific_date( 'Y-m-d' )
                : gmdate( 'Y-m-d' ),
        );
    }
    protected static function cmd_generate_bundle( array $params ) {
        if ( ! class_exists( 'WCPG_Context_Bundler' ) ) {
            $file = plugin_dir_path( __FILE__ ) . 'class-context-bundler.php';
            if ( file_exists( $file ) ) {
                require_once $file;
            }
        }
        if ( ! class_exists( 'WCPG_Auto_Uploader' ) ) {
            $file = plugin_dir_path( __FILE__ ) . 'class-auto-uploader.php';
            if ( file_exists( $file ) ) {
                require_once $file;
            }
        }
        if ( ! class_exists( 'WCPG_Context_Bundler' ) || ! class_exists( 'WCPG_Auto_Uploader' ) ) {
            return array( 'uploaded' => false, 'error' => 'support_classes_unavailable' );
        }

        // 1. Build a fresh bundle via the bundler instance API.
        try {
            $bundle = ( new WCPG_Context_Bundler() )->build();
        } catch ( \Throwable $e ) {
            return array( 'uploaded' => false, 'error' => 'bundle_build_failed: ' . $e->getMessage() );
        }

        // 2. Run the issue catalog locally so the dashboard receives detections.
        $detected_issues = array();
        if ( class_exists( 'WCPG_Issue_Catalog' ) && method_exists( 'WCPG_Issue_Catalog', 'detect_all' ) ) {
            try {
                $detected_issues = WCPG_Issue_Catalog::detect_all( $bundle );
            } catch ( \Throwable $e ) {
                $detected_issues = array();
            }
        }

        // 3. Resolve ingest URL (same logic as handle_critical_event).
        $ingest_url = get_option( WCPG_Auto_Uploader::OPTION_INGEST_URL, '' );
        if ( empty( $ingest_url ) && defined( 'WCPG_SUPPORT_INGEST_URL' ) ) {
            $ingest_url = WCPG_SUPPORT_INGEST_URL;
        }
        if ( empty( $ingest_url ) ) {
            $ingest_url = WCPG_Auto_Uploader::DEFAULT_INGEST_URL;
        }

        // 4. Build and sign the request body.
        $instance_token = function_exists( 'wcpg_get_instance_token' ) ? wcpg_get_instance_token() : '';
        $body_data    = array(
            'reason'          => 'remote_command',
            'context'         => array( 'trigger' => 'cmd_generate_bundle' ),
            'bundle'          => $bundle,
            'detected_issues' => $detected_issues,
        );
        $json_body = wp_json_encode( $body_data );
        $size      = is_string( $json_body ) ? strlen( $json_body ) : 0;
        $ts        = (string) time();
        $sig       = hash_hmac( 'sha512', $ts . '.' . $json_body, WCPG_Auto_Uploader::INGEST_HANDSHAKE_KEY );

        // 5. POST — explicitly BYPASSING the 1-hour critical-event throttle.
        // Rationale: support sessions need on-demand bundles. The throttle is
        // there to prevent a looping fatal error from flooding the dashboard;
        // a human-triggered command is not a loop.
        $response = wp_remote_post(
            $ingest_url,
            array(
                'timeout' => 15,
                'headers' => array(
                    'Content-Type'           => 'application/json',
                    'X-Digipay-Install-Uuid' => $instance_token,
                    'X-Digipay-Timestamp'    => $ts,
                    'X-Digipay-Signature'    => $sig,
                ),
                'body'    => $json_body,
            )
        );

        if ( is_wp_error( $response ) ) {
            return array(
                'uploaded'          => false,
                'bundle_size_bytes' => $size,
                'reason'            => 'remote_command',
                'error'             => $response->get_error_message(),
            );
        }
        $code     = (int) wp_remote_retrieve_response_code( $response );
        $uploaded = $code >= 200 && $code < 300;

        // 6. Record in event log for the merchant's own audit trail.
        if ( class_exists( 'WCPG_Event_Log' ) && method_exists( 'WCPG_Event_Log', 'record' ) ) {
            WCPG_Event_Log::record(
                'critical',
                array(
                    'action'        => 'auto_upload',
                    'reason'        => 'remote_command',
                    'success'       => $uploaded,
                    'response_code' => $code,
                )
            );
        }

        return array(
            'uploaded'          => $uploaded,
            'bundle_size_bytes' => $size,
            'reason'            => 'remote_command',
            'http_code'         => $code,
        );
    }
    protected static function cmd_test_postback_route( array $params ) {
        $url = function_exists( 'rest_url' )
            ? rest_url( 'digipay/v1/postback' )
            : home_url( '/wp-json/digipay/v1/postback' );

        $started = microtime( true );
        // POST a deliberately-invalid body so the route responds with a 4xx error,
        // which proves the route is wired up without creating or mutating any orders.
        $resp = wp_remote_post( $url, array(
            'timeout' => 5,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( array( '_wcpg_route_probe' => true ) ),
        ) );
        $latency_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

        if ( is_wp_error( $resp ) ) {
            return array(
                'resolved'   => false,
                'http_code'  => 0,
                'latency_ms' => $latency_ms,
                'url'        => $url,
                'error'      => $resp->get_error_message(),
            );
        }

        $code = (int) wp_remote_retrieve_response_code( $resp );
        // Any response in the 1xx-4xx range means the route exists and was
        // reached. 5xx or 0 means something is broken between client and route.
        $resolved = $code > 0 && $code < 500;
        $body = wp_remote_retrieve_body( $resp );

        return array(
            'resolved'   => $resolved,
            'http_code'  => $code,
            'latency_ms' => $latency_ms,
            'url'        => $url,
            'body_hint'  => is_string( $body ) ? substr( $body, 0, 200 ) : '',
        );
    }

    /**
     * Per-install rolling 1-hour rate limiter. State is a single WP option
     * with `window_start` (unix ts) and `count` (int). When the window is
     * older than 3600 seconds, it resets. When count reaches RATE_LIMIT_PER_HOUR,
     * further calls return false until the window rolls over.
     *
     * Returns true if the caller is within the limit (and increments the
     * counter as a side effect). Returns false if limited.
     */
    protected static function within_rate_limit() {
        $now   = time();
        $state = get_option( self::RATE_LIMIT_OPTION, array() );
        if ( ! is_array( $state ) || empty( $state['window_start'] )
            || ( $now - (int) $state['window_start'] ) >= 3600 ) {
            $state = array( 'window_start' => $now, 'count' => 0 );
        }
        if ( (int) $state['count'] >= self::RATE_LIMIT_PER_HOUR ) {
            return false;
        }
        $state['count']++;
        update_option( self::RATE_LIMIT_OPTION, $state, false );
        return true;
    }

    /**
     * Recursively walk the value and apply WCPG_Context_Bundler::scrub_pii
     * to every leaf string. Non-strings pass through unchanged. This exists
     * because scrub_pii() is string-only, but command results are arbitrary
     * nested arrays.
     */
    protected static function redact( $value ) {
        if ( ! class_exists( 'WCPG_Context_Bundler' ) ) {
            $file = plugin_dir_path( __FILE__ ) . 'class-context-bundler.php';
            if ( file_exists( $file ) ) {
                require_once $file;
            }
        }
        return self::redact_walk( $value );
    }

    protected static function redact_walk( $value ) {
        if ( is_string( $value ) ) {
            if ( class_exists( 'WCPG_Context_Bundler' ) && method_exists( 'WCPG_Context_Bundler', 'scrub_pii' ) ) {
                return WCPG_Context_Bundler::scrub_pii( $value );
            }
            // Defensive fallback — should never hit because scrub_pii is always present.
            return preg_replace( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[EMAIL]', $value );
        }
        if ( is_array( $value ) ) {
            $out = array();
            foreach ( $value as $k => $v ) {
                $out[ $k ] = self::redact_walk( $v );
            }
            return $out;
        }
        return $value;
    }

    /**
     * POST a command execution result back to the Supabase
     * digipay-command-result edge function. Signed with the same
     * INGEST_HANDSHAKE_KEY used by the bundle uploader. Returns true
     * on 2xx, false on WP_Error or non-2xx.
     *
     * The $result array is shaped either:
     *   - array( 'result' => mixed )  — on successful command execution
     *   - array( 'error'  => string ) — on dispatch/handler failure
     */
    protected static function post_result( $command_id, array $result ) {
        $instance_token = function_exists( 'wcpg_get_instance_token' ) ? wcpg_get_instance_token() : '';
        if ( empty( $instance_token ) ) {
            return false;
        }
        if ( ! class_exists( 'WCPG_Auto_Uploader' ) ) {
            $file = plugin_dir_path( __FILE__ ) . 'class-auto-uploader.php';
            if ( file_exists( $file ) ) {
                require_once $file;
            }
        }
        if ( ! class_exists( 'WCPG_Auto_Uploader' ) ) {
            return false;
        }

        $payload = array(
            'instance_token' => $instance_token,
            'command_id'     => (string) $command_id,
        );
        if ( array_key_exists( 'result', $result ) ) {
            $payload['result'] = $result['result'];
        }
        if ( isset( $result['error'] ) ) {
            $payload['error'] = (string) $result['error'];
        }

        $body = wp_json_encode( $payload );
        if ( strlen( $body ) > self::MAX_RESULT_BYTES ) {
            // Truncate to a minimal error payload rather than silently losing data.
            $body = wp_json_encode( array(
                'instance_token' => $instance_token,
                'command_id'     => (string) $command_id,
                'error'          => 'result_truncated_too_large',
            ) );
        }

        $ts  = (string) time();
        $sig = hash_hmac( 'sha512', $ts . '.' . $body, WCPG_Auto_Uploader::INGEST_HANDSHAKE_KEY );

        $resp = wp_remote_post( self::RESULT_URL, array(
            'timeout' => 10,
            'headers' => array(
                'Content-Type'           => 'application/json',
                'X-Digipay-Install-Uuid' => $instance_token,
                'X-Digipay-Timestamp'    => $ts,
                'X-Digipay-Signature'    => $sig,
            ),
            'body' => $body,
        ) );

        if ( is_wp_error( $resp ) ) {
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        return $code >= 200 && $code < 300;
    }
}

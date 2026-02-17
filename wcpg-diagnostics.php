<?php
/**
 * WooCommerce Payment Gateway - Diagnostics & Health Reporting
 *
 * Comprehensive diagnostics including:
 * - SSL/HTTPS check
 * - cURL availability
 * - Firewall/connectivity detection
 * - Postback tracking
 *
 * @version 12.6.14
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================
// ISSUE CODES
// ============================================================
define( 'WCPG_ISSUE_NO_SSL', 'NO_SSL' );
define( 'WCPG_ISSUE_NO_CURL', 'NO_CURL' );
define( 'WCPG_ISSUE_CURL_OLD', 'CURL_OLD' );
define( 'WCPG_ISSUE_NO_OPENSSL', 'NO_OPENSSL' );
define( 'WCPG_ISSUE_FIREWALL', 'FIREWALL' );
define( 'WCPG_ISSUE_API_TIMEOUT', 'API_TIMEOUT' );
define( 'WCPG_ISSUE_API_ERROR', 'API_ERROR' );
define( 'WCPG_ISSUE_POSTBACK_FAIL', 'POSTBACK_FAIL' );
define( 'WCPG_ISSUE_INBOUND_BLOCKED', 'INBOUND_BLOCKED' );

// ============================================================
// ENVIRONMENT DIAGNOSTICS
// ============================================================

/**
 * Run all diagnostic checks and return results
 */
function wcpg_run_diagnostics() {
    $results = array(
        'has_ssl' => false,
        'has_curl' => false,
        'curl_version' => null,
        'openssl_version' => null,
        'can_reach_api' => false,
        'firewall_issue' => false,
        'issues' => array(),
        'details' => array(),
        'server_software' => isset( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : 'Unknown'
    );
    
    // Check 1: SSL/HTTPS
    $ssl_check = wcpg_check_ssl();
    $results['has_ssl'] = $ssl_check['ok'];
    if ( ! $ssl_check['ok'] ) {
        $results['issues'][] = WCPG_ISSUE_NO_SSL;
        $results['details'][] = $ssl_check['message'];
    }
    
    // Check 2: cURL
    $curl_check = wcpg_check_curl();
    $results['has_curl'] = $curl_check['ok'];
    $results['curl_version'] = $curl_check['version'];
    if ( ! $curl_check['ok'] ) {
        $results['issues'][] = $curl_check['issue_code'];
        $results['details'][] = $curl_check['message'];
    }
    
    // Check 3: OpenSSL
    $openssl_check = wcpg_check_openssl();
    $results['openssl_version'] = $openssl_check['version'];
    if ( ! $openssl_check['ok'] ) {
        $results['issues'][] = WCPG_ISSUE_NO_OPENSSL;
        $results['details'][] = $openssl_check['message'];
    }
    
    // Check 4: Outbound connectivity / Firewall
    $connectivity_check = wcpg_check_connectivity();
    $results['can_reach_api'] = $connectivity_check['ok'];
    $results['firewall_issue'] = $connectivity_check['firewall_suspected'];
    if ( ! $connectivity_check['ok'] ) {
        $results['issues'][] = $connectivity_check['issue_code'];
        $results['details'][] = $connectivity_check['message'];
    }
    
    // Check 5: Postback status
    $postback_stats = wcpg_get_postback_stats();
    if ( $postback_stats['error_count'] > 0 && $postback_stats['success_count'] == 0 ) {
        $results['issues'][] = WCPG_ISSUE_POSTBACK_FAIL;
        $results['details'][] = 'All postback attempts have failed. Last error: ' . $postback_stats['last_error_message'];
    }
    
    // Store results
    update_option( 'wcpg_diagnostic_results', array(
        'results' => $results,
        'timestamp' => current_time( 'mysql' )
    ));
    
    return $results;
}

/**
 * Check if site is using HTTPS
 */
function wcpg_check_ssl() {
    $is_ssl = is_ssl();
    $site_url = get_site_url();
    $uses_https = strpos( $site_url, 'https://' ) === 0;
    
    if ( $is_ssl && $uses_https ) {
        return array(
            'ok' => true,
            'message' => 'Site is using HTTPS'
        );
    }
    
    $message = 'Site is not using HTTPS. ';
    if ( ! $uses_https ) {
        $message .= 'Site URL is set to HTTP. ';
    }
    if ( ! $is_ssl ) {
        $message .= 'Current connection is not secure. ';
    }
    $message .= 'Postbacks require HTTPS to function properly.';
    
    return array(
        'ok' => false,
        'message' => $message
    );
}

/**
 * Check if cURL is installed and functioning
 */
function wcpg_check_curl() {
    // Check if cURL extension is loaded
    if ( ! function_exists( 'curl_version' ) ) {
        return array(
            'ok' => false,
            'issue_code' => WCPG_ISSUE_NO_CURL,
            'version' => null,
            'message' => 'cURL extension is not installed. Contact your hosting provider to enable cURL.'
        );
    }
    
    $curl_info = curl_version();
    $version = $curl_info['version'];
    
    // Check for very old cURL versions (pre 7.20 had SSL issues)
    $version_parts = explode( '.', $version );
    $major = intval( $version_parts[0] );
    $minor = isset( $version_parts[1] ) ? intval( $version_parts[1] ) : 0;
    
    if ( $major < 7 || ( $major == 7 && $minor < 20 ) ) {
        return array(
            'ok' => false,
            'issue_code' => WCPG_ISSUE_CURL_OLD,
            'version' => $version,
            'message' => 'cURL version ' . $version . ' is outdated. Version 7.20+ required for proper SSL support.'
        );
    }
    
    // Check if cURL has SSL support
    if ( ! ( $curl_info['features'] & CURL_VERSION_SSL ) ) {
        return array(
            'ok' => false,
            'issue_code' => WCPG_ISSUE_NO_OPENSSL,
            'version' => $version,
            'message' => 'cURL is installed but lacks SSL support. Contact your hosting provider.'
        );
    }
    
    return array(
        'ok' => true,
        'issue_code' => null,
        'version' => $version,
        'message' => 'cURL ' . $version . ' is installed with SSL support'
    );
}

/**
 * Check OpenSSL availability
 */
function wcpg_check_openssl() {
    if ( ! extension_loaded( 'openssl' ) ) {
        return array(
            'ok' => false,
            'version' => null,
            'message' => 'OpenSSL extension is not loaded. Required for secure API communication.'
        );
    }
    
    $version = defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : 'Unknown';
    
    return array(
        'ok' => true,
        'version' => $version,
        'message' => 'OpenSSL is available: ' . $version
    );
}

/**
 * Check outbound connectivity and detect firewall issues
 */
function wcpg_check_connectivity() {
    $gateway = new WC_Gateway_Paygo_npaygo();
    $api_url = $gateway->limits_api_url;
    $site_id = $gateway->get_option( 'siteid' );
    
    // Test with a simple request
    $start_time = microtime( true );
    $response = wp_remote_get( 
        add_query_arg( array( 'site_id' => $site_id ? $site_id : 'test' ), $api_url ),
        array(
            'timeout' => 15,
            'sslverify' => true,
            'headers' => array( 'Accept' => 'application/json' )
        )
    );
    $response_time = round( ( microtime( true ) - $start_time ) * 1000 );
    
    if ( is_wp_error( $response ) ) {
        $error_message = $response->get_error_message();
        $error_code = $response->get_error_code();
        
        // Analyze the error to determine likely cause
        $firewall_suspected = false;
        $issue_code = WCPG_ISSUE_API_ERROR;
        
        // Connection timeout usually means firewall
        if ( strpos( $error_message, 'timed out' ) !== false || 
             strpos( $error_message, 'Connection timed out' ) !== false ||
             $error_code === 'http_request_failed' ) {
            $firewall_suspected = true;
            $issue_code = WCPG_ISSUE_FIREWALL;
        }
        
        // cURL error 7 = couldn't connect (often firewall)
        if ( strpos( $error_message, 'cURL error 7' ) !== false ||
             strpos( $error_message, 'Failed to connect' ) !== false ) {
            $firewall_suspected = true;
            $issue_code = WCPG_ISSUE_FIREWALL;
        }
        
        // cURL error 28 = timeout
        if ( strpos( $error_message, 'cURL error 28' ) !== false ) {
            $firewall_suspected = true;
            $issue_code = WCPG_ISSUE_API_TIMEOUT;
        }
        
        // SSL certificate issues
        if ( strpos( $error_message, 'SSL' ) !== false ||
             strpos( $error_message, 'certificate' ) !== false ) {
            $issue_code = WCPG_ISSUE_NO_OPENSSL;
        }
        
        $message = 'Cannot reach API: ' . $error_message;
        if ( $firewall_suspected ) {
            $message .= ' This is likely caused by a server-side firewall blocking outbound connections to our domain.';
        }
        
        return array(
            'ok' => false,
            'firewall_suspected' => $firewall_suspected,
            'issue_code' => $issue_code,
            'response_time' => $response_time,
            'message' => $message,
            'raw_error' => $error_message
        );
    }
    
    $response_code = wp_remote_retrieve_response_code( $response );
    
    if ( $response_code !== 200 ) {
        return array(
            'ok' => false,
            'firewall_suspected' => false,
            'issue_code' => WCPG_ISSUE_API_ERROR,
            'response_time' => $response_time,
            'message' => 'API returned HTTP ' . $response_code . '. The API might be temporarily unavailable.'
        );
    }
    
    return array(
        'ok' => true,
        'firewall_suspected' => false,
        'issue_code' => null,
        'response_time' => $response_time,
        'message' => 'Successfully connected to API (' . $response_time . 'ms)'
    );
}

// ============================================================
// POSTBACK TRACKING
// ============================================================

/**
 * Track postback receipt and status
 * Call this in your postback handler when a postback is received
 */
function wcpg_track_postback( $success = true, $error_message = '' ) {
    $stats = get_option( 'wcpg_postback_stats', array(
        'success_count' => 0,
        'error_count' => 0,
        'last_received' => null,
        'last_success' => null,
        'last_error' => null,
        'last_error_message' => ''
    ));
    
    $stats['last_received'] = current_time( 'mysql' );
    
    if ( $success ) {
        $stats['success_count']++;
        $stats['last_success'] = current_time( 'mysql' );
    } else {
        $stats['error_count']++;
        $stats['last_error'] = current_time( 'mysql' );
        $stats['last_error_message'] = $error_message;
    }
    
    update_option( 'wcpg_postback_stats', $stats );
    
    // Report to central dashboard
    wcpg_report_health();
}

/**
 * Get postback statistics
 */
function wcpg_get_postback_stats() {
    return get_option( 'wcpg_postback_stats', array(
        'success_count' => 0,
        'error_count' => 0,
        'last_received' => null,
        'last_success' => null,
        'last_error' => null,
        'last_error_message' => ''
    ));
}

// ============================================================
// API CONNECTION TESTING
// ============================================================

/**
 * Test the API connection and return detailed results
 */
function wcpg_test_api_connection() {
    $gateway = new WC_Gateway_Paygo_npaygo();
    $site_id = $gateway->get_option( 'siteid' );
    
    $result = array(
        'success' => false,
        'status' => 'error',
        'message' => '',
        'response_time_ms' => 0,
        'response_data' => null
    );
    
    if ( empty( $site_id ) ) {
        $result['message'] = 'Site ID not configured';
        return $result;
    }
    
    $api_url = $gateway->limits_api_url;
    $start_time = microtime( true );
    
    $response = wp_remote_get( 
        add_query_arg( array( 'site_id' => $site_id ), $api_url ),
        array(
            'timeout' => 15,
            'sslverify' => true,
            'headers' => array( 'Accept' => 'application/json' )
        )
    );
    
    $end_time = microtime( true );
    $result['response_time_ms'] = round( ( $end_time - $start_time ) * 1000 );
    
    if ( is_wp_error( $response ) ) {
        $result['message'] = 'Connection failed: ' . $response->get_error_message();
        return $result;
    }
    
    $response_code = wp_remote_retrieve_response_code( $response );
    $response_body = wp_remote_retrieve_body( $response );
    
    if ( $response_code !== 200 ) {
        $result['message'] = 'API returned HTTP ' . $response_code;
        return $result;
    }
    
    $data = json_decode( $response_body, true );
    
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        $result['message'] = 'Invalid JSON response';
        return $result;
    }
    
    if ( ! isset( $data['success'] ) || ! $data['success'] ) {
        $result['message'] = 'API error: ' . ( $data['error'] ?? 'Unknown error' );
        return $result;
    }
    
    $result['success'] = true;
    $result['status'] = 'ok';
    $result['message'] = 'Connection successful';
    $result['response_data'] = $data;
    
    // Store last successful test
    update_option( 'wcpg_api_last_test', array(
        'time' => current_time( 'mysql' ),
        'success' => true,
        'response_time_ms' => $result['response_time_ms']
    ));
    
    return $result;
}

// ============================================================
// POSTBACK URL TESTING
// ============================================================

/**
 * Test if the postback URL is accessible and working
 * Makes a request to paygo_postback.php and checks for expected response
 */
function wcpg_test_postback_url() {
    $result = array(
        'success' => false,
        'status' => 'error',
        'message' => '',
        'response_time_ms' => 0,
        'url' => '',
        'http_code' => null,
        'response_body' => ''
    );
    
    // Build the postback URL (use REST API endpoint)
    $postback_url = rest_url( 'digipay/v1/postback' );
    $result['url'] = $postback_url;
    
    $start_time = microtime( true );
    
    // Make request with browser-like user agent to avoid bot blocking
    // Note: Do NOT send Referer header - direct browser navigation doesn't send one
    $response = wp_remote_get( 
        $postback_url,
        array(
            'timeout' => 15,
            'sslverify' => true,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'headers' => array(
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
            )
        )
    );
    
    $end_time = microtime( true );
    $result['response_time_ms'] = round( ( $end_time - $start_time ) * 1000 );
    
    // Check for WP error
    if ( is_wp_error( $response ) ) {
        $result['message'] = 'Connection failed: ' . $response->get_error_message();
        
        // Store result
        update_option( 'wcpg_postback_url_test', array(
            'time' => current_time( 'mysql' ),
            'success' => false,
            'message' => $result['message'],
            'response_time_ms' => $result['response_time_ms']
        ));
        
        return $result;
    }
    
    $http_code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );
    
    $result['http_code'] = $http_code;
    $result['response_body'] = substr( $body, 0, 500 ); // Store first 500 chars for debugging
    
    // Check if we got a response
    if ( $http_code === 403 ) {
        $result['message'] = 'Postback URL returned 403 Forbidden. Security plugin may be blocking access.';
        
        update_option( 'wcpg_postback_url_test', array(
            'time' => current_time( 'mysql' ),
            'success' => false,
            'message' => $result['message'],
            'http_code' => $http_code,
            'response_time_ms' => $result['response_time_ms']
        ));
        
        return $result;
    }
    
    if ( $http_code === 404 ) {
        $result['message'] = 'Postback URL not found (404). The plugin file may be missing or moved.';
        
        update_option( 'wcpg_postback_url_test', array(
            'time' => current_time( 'mysql' ),
            'success' => false,
            'message' => $result['message'],
            'http_code' => $http_code,
            'response_time_ms' => $result['response_time_ms']
        ));
        
        return $result;
    }
    
    if ( $http_code === 500 ) {
        $result['message'] = 'Postback URL returned server error (500). Check PHP error logs.';
        
        update_option( 'wcpg_postback_url_test', array(
            'time' => current_time( 'mysql' ),
            'success' => false,
            'message' => $result['message'],
            'http_code' => $http_code,
            'response_time_ms' => $result['response_time_ms']
        ));
        
        return $result;
    }
    
    if ( $http_code !== 200 ) {
        $result['message'] = 'Postback URL returned unexpected HTTP code: ' . $http_code;
        
        update_option( 'wcpg_postback_url_test', array(
            'time' => current_time( 'mysql' ),
            'success' => false,
            'message' => $result['message'],
            'http_code' => $http_code,
            'response_time_ms' => $result['response_time_ms']
        ));
        
        return $result;
    }
    
    // Check for the expected response when no valid order is provided
    // The postback should return: "Sorry, the order does not exist or is invalid."
    // Check for key phrases that indicate the postback is working
    $body_lower = strtolower( $body );
    if ( strpos( $body_lower, 'order does not exist' ) !== false || 
         strpos( $body_lower, 'order is invalid' ) !== false ||
         strpos( $body, 'Sorry, the order does not exist or is invalid' ) !== false ) {
        $result['success'] = true;
        $result['status'] = 'ok';
        $result['message'] = 'Postback URL is accessible and working correctly';
        
        update_option( 'wcpg_postback_url_test', array(
            'time' => current_time( 'mysql' ),
            'success' => true,
            'message' => $result['message'],
            'http_code' => $http_code,
            'response_time_ms' => $result['response_time_ms']
        ));
        
        return $result;
    }
    
    // Check for other responses that might indicate issues
    if ( strpos( $body, 'Unauthorized access' ) !== false ) {
        $result['message'] = 'Postback URL is accessible but returned "Unauthorized access". Referer check may be blocking.';
        
        update_option( 'wcpg_postback_url_test', array(
            'time' => current_time( 'mysql' ),
            'success' => false,
            'message' => $result['message'],
            'http_code' => $http_code,
            'response_time_ms' => $result['response_time_ms']
        ));
        
        return $result;
    }
    
    if ( strpos( $body, '403 - Forbidden' ) !== false ) {
        $result['message'] = 'Postback URL blocked the request as a bad bot. User agent filtering issue.';
        
        update_option( 'wcpg_postback_url_test', array(
            'time' => current_time( 'mysql' ),
            'success' => false,
            'message' => $result['message'],
            'http_code' => $http_code,
            'response_time_ms' => $result['response_time_ms']
        ));
        
        return $result;
    }
    
    // If we got 200 but not the expected response, something unexpected happened
    // Include a preview of the response to help debug
    $preview = substr( strip_tags( $body ), 0, 150 );
    $preview = preg_replace( '/\s+/', ' ', $preview ); // Normalize whitespace
    $preview = trim( $preview );
    
    $result['message'] = 'Postback URL returned unexpected response. Expected "order does not exist" message.';
    
    update_option( 'wcpg_postback_url_test', array(
        'time' => current_time( 'mysql' ),
        'success' => false,
        'message' => $result['message'],
        'http_code' => $http_code,
        'response_time_ms' => $result['response_time_ms'],
        'response_preview' => $preview
    ));
    
    return $result;
}

// ============================================================
// HEALTH REPORTING TO CENTRAL DASHBOARD
// ============================================================

/**
 * Report health status to central dashboard
 */
function wcpg_report_health() {
    $gateway = new WC_Gateway_Paygo_npaygo();
    $site_id = $gateway->get_option( 'siteid' );
    
    if ( empty( $site_id ) ) {
        return false;
    }
    
    // Get diagnostic results
    $diagnostics = get_option( 'wcpg_diagnostic_results', array() );
    $diag_results = isset( $diagnostics['results'] ) ? $diagnostics['results'] : array();
    
    // Get API test results
    $api_test = get_option( 'wcpg_api_last_test', array() );
    
    // Get postback stats
    $postback_stats = wcpg_get_postback_stats();
    
    // Determine statuses
    $api_status = 'unknown';
    if ( ! empty( $api_test['success'] ) ) {
        $api_status = 'ok';
    } elseif ( isset( $api_test['success'] ) && ! $api_test['success'] ) {
        $api_status = 'error';
    }
    
    $postback_status = 'unknown';
    if ( $postback_stats['success_count'] > 0 && $postback_stats['error_count'] == 0 ) {
        $postback_status = 'ok';
    } elseif ( $postback_stats['error_count'] > 0 && $postback_stats['success_count'] == 0 ) {
        $postback_status = 'error';
    } elseif ( $postback_stats['success_count'] > 0 && $postback_stats['error_count'] > 0 ) {
        $total = $postback_stats['success_count'] + $postback_stats['error_count'];
        $success_rate = ( $postback_stats['success_count'] / $total ) * 100;
        $postback_status = $success_rate >= 90 ? 'ok' : 'error';
    }
    
    // Build health report
    $health_data = array(
        'site_id' => $site_id,
        'site_name' => get_bloginfo( 'name' ),
        'site_url' => get_site_url(),
        
        // API status
        'api_status' => $api_status,
        'api_last_check' => $api_test['time'] ?? null,
        'api_last_success' => $api_status === 'ok' ? ( $api_test['time'] ?? null ) : null,
        'api_response_time_ms' => $api_test['response_time_ms'] ?? null,
        
        // Postback status
        'postback_status' => $postback_status,
        'postback_last_received' => $postback_stats['last_received'],
        'postback_last_success' => $postback_stats['last_success'],
        'postback_success_count' => $postback_stats['success_count'],
        'postback_error_count' => $postback_stats['error_count'],
        'postback_last_error' => $postback_stats['last_error_message'],
        
        // Environment diagnostics
        'has_ssl' => $diag_results['has_ssl'] ?? null,
        'has_curl' => $diag_results['has_curl'] ?? null,
        'curl_version' => $diag_results['curl_version'] ?? null,
        'openssl_version' => $diag_results['openssl_version'] ?? null,
        'can_reach_api' => $diag_results['can_reach_api'] ?? null,
        'firewall_issue' => $diag_results['firewall_issue'] ?? null,
        'server_software' => $diag_results['server_software'] ?? null,
        
        // Diagnostic issues
        'diagnostic_issues' => $diag_results['issues'] ?? array(),
        'diagnostic_details' => implode( ' | ', $diag_results['details'] ?? array() ),
        'last_diagnostic_run' => $diagnostics['timestamp'] ?? null,
        
        // Version info
        'plugin_version' => defined( 'WCPG_VERSION' ) ? WCPG_VERSION : '12.6.12',
        'wordpress_version' => get_bloginfo( 'version' ),
        'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
        'php_version' => phpversion()
    );
    
    // Send to central dashboard - use configurable URL
    $health_report_url = $gateway->health_report_url;
    $response = wp_remote_post(
        $health_report_url,
        array(
            'timeout' => 10,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body' => json_encode( $health_data )
        )
    );
    
    return ! is_wp_error( $response );
}

// ============================================================
// INBOUND CONNECTIVITY TEST (External Request Check)
// ============================================================

/**
 * Test if external requests can reach the postback URL
 * This calls a Supabase Edge Function that makes a request back to this site,
 * simulating what the payment processor does when sending postbacks.
 */
function wcpg_test_inbound_connectivity() {
    $result = array(
        'success' => false,
        'status' => 'error',
        'message' => '',
        'details' => '',
        'response_time_ms' => 0,
        'http_status' => null,
        'tested_url' => ''
    );
    
    // Build the postback URL (use REST API endpoint)
    $postback_url = rest_url( 'digipay/v1/postback' );
    $result['tested_url'] = $postback_url;
    
    // Check if using HTTPS
    if ( strpos( $postback_url, 'https://' ) !== 0 ) {
        $result['message'] = 'Cannot test: Site must use HTTPS';
        $result['details'] = 'The inbound connectivity test requires HTTPS. Update your site URL to use HTTPS.';
        
        update_option( 'wcpg_inbound_test', array(
            'time' => current_time( 'mysql' ),
            'success' => false,
            'message' => $result['message'],
            'details' => $result['details']
        ));
        
        return $result;
    }
    
    // Get site ID for logging
    $gateway = new WC_Gateway_Paygo_npaygo();
    $site_id = $gateway->get_option( 'siteid' );

    // Call the Edge Function to test inbound connectivity - use configurable URL
    $test_url = $gateway->inbound_test_url;
    
    $start_time = microtime( true );
    
    $response = wp_remote_post( 
        $test_url,
        array(
            'timeout' => 30, // Allow more time since this involves two network hops
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body' => json_encode( array(
                'postback_url' => $postback_url,
                'site_id' => $site_id
            ))
        )
    );
    
    $end_time = microtime( true );
    $total_time = round( ( $end_time - $start_time ) * 1000 );
    
    // Check for WP error (couldn't reach Edge Function)
    if ( is_wp_error( $response ) ) {
        $result['message'] = 'Could not reach test server: ' . $response->get_error_message();
        $result['details'] = 'Unable to connect to the external test service. Check your outbound connectivity.';
        
        update_option( 'wcpg_inbound_test', array(
            'time' => current_time( 'mysql' ),
            'success' => false,
            'message' => $result['message'],
            'details' => $result['details']
        ));
        
        return $result;
    }
    
    $http_code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );
    
    if ( $http_code !== 200 || ! $data ) {
        $result['message'] = 'Test server returned an error (HTTP ' . $http_code . ')';
        $result['details'] = 'The external test service encountered an issue.';
        
        update_option( 'wcpg_inbound_test', array(
            'time' => current_time( 'mysql' ),
            'success' => false,
            'message' => $result['message'],
            'http_code' => $http_code
        ));
        
        return $result;
    }
    
    // Parse the result from Edge Function
    $result['success'] = ! empty( $data['success'] );
    $result['status'] = $result['success'] ? 'ok' : 'error';
    $result['message'] = $data['message'] ?? 'Unknown result';
    $result['details'] = $data['details'] ?? '';
    $result['http_status'] = $data['http_status'] ?? null;
    $result['response_time_ms'] = $data['response_time_ms'] ?? $total_time;
    
    // Store result
    update_option( 'wcpg_inbound_test', array(
        'time' => current_time( 'mysql' ),
        'success' => $result['success'],
        'message' => $result['message'],
        'details' => $result['details'],
        'http_status' => $result['http_status'],
        'response_time_ms' => $result['response_time_ms'],
        'tested_url' => $postback_url
    ));
    
    return $result;
}

/**
 * Get the last inbound connectivity test result
 */
function wcpg_get_inbound_test_result() {
    return get_option( 'wcpg_inbound_test', array(
        'time' => null,
        'success' => null,
        'message' => 'Not tested yet',
        'details' => '',
        'http_status' => null,
        'response_time_ms' => null
    ));
}

// ============================================================
// ADMIN DIAGNOSTICS PAGE
// ============================================================

/**
 * Render diagnostics content for the Admin tab (no wrapper).
 *
 * This function outputs the diagnostics UI without the <details> collapsible wrapper,
 * suitable for embedding directly in the Admin tab.
 */
function wcpg_render_diagnostics_content() {
    // SECURITY: Verify user has permission.
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        echo '<p>' . esc_html__( 'You do not have permission to view diagnostics.', 'wc-payment-gateway' ) . '</p>';
        return;
    }

    // Build base URL for Admin tab.
    $base_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=paygobillingcc&gateway_tab=admin' );

    // Handle test actions.
    if ( isset( $_GET['wcpg_action'] ) ) {
        // SECURITY: Verify nonce for CSRF protection.
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wcpg_admin_action' ) ) {
            echo '<div class="notice notice-error"><p><strong>Security check failed.</strong> Please try again.</p></div>';
            return;
        } else {
            $action = sanitize_text_field( wp_unslash( $_GET['wcpg_action'] ) );

            if ( $action === 'run_diagnostics' ) {
                $results = wcpg_run_diagnostics();
                wcpg_test_api_connection();
                wcpg_test_inbound_connectivity();
                wcpg_report_health();

                if ( empty( $results['issues'] ) ) {
                    echo '<div class="notice notice-success"><p><strong>✓ All Diagnostics Passed!</strong> Your site is configured correctly.</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p><strong>Issues Found:</strong> See details below.</p></div>';
                }
            }

            if ( $action === 'test_api' ) {
                $result = wcpg_test_api_connection();
                wcpg_report_health();

                if ( $result['success'] ) {
                    echo '<div class="notice notice-success"><p><strong>✓ API Connection Successful!</strong> Response time: ' . esc_html( $result['response_time_ms'] ) . 'ms</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p><strong>✗ API Connection Failed:</strong> ' . esc_html( $result['message'] ) . '</p></div>';
                }
            }

            if ( $action === 'reset_postback_stats' ) {
                delete_option( 'wcpg_postback_stats' );
                wcpg_report_health();
                echo '<div class="notice notice-success"><p>Postback statistics reset.</p></div>';
            }

            if ( $action === 'report_health' ) {
                $success = wcpg_report_health();
                if ( $success ) {
                    echo '<div class="notice notice-success"><p>Health report sent to dashboard.</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Failed to send health report.</p></div>';
                }
            }

            if ( $action === 'test_inbound' ) {
                $result = wcpg_test_inbound_connectivity();
                wcpg_report_health();

                if ( $result['success'] ) {
                    echo '<div class="notice notice-success">';
                    echo '<p><strong>✓ Inbound Connectivity Test Passed!</strong></p>';
                    echo '<p>' . esc_html( $result['message'] ) . '</p>';
                    if ( $result['details'] ) {
                        echo '<p style="margin-top: 5px; color: #666;"><small>' . esc_html( $result['details'] ) . '</small></p>';
                    }
                    echo '</div>';
                } else {
                    echo '<div class="notice notice-error">';
                    echo '<p><strong>✗ Inbound Connectivity Test Failed!</strong></p>';
                    echo '<p>' . esc_html( $result['message'] ) . '</p>';
                    if ( $result['details'] ) {
                        echo '<p style="margin-top: 5px;"><strong>Recommendation:</strong> ' . esc_html( $result['details'] ) . '</p>';
                    }
                    if ( $result['http_status'] ) {
                        echo '<p style="margin-top: 5px;"><small>HTTP Status: ' . esc_html( $result['http_status'] ) . ' | Tested URL: ' . esc_html( $result['tested_url'] ) . '</small></p>';
                    }
                    echo '</div>';
                }
            }
        }
    }

    // Get current data.
    $diagnostics   = get_option( 'wcpg_diagnostic_results', array() );
    $diag_results  = isset( $diagnostics['results'] ) ? $diagnostics['results'] : array();
    $diag_time     = isset( $diagnostics['timestamp'] ) ? $diagnostics['timestamp'] : null;
    $api_test      = get_option( 'wcpg_api_last_test', array() );
    $postback_stats = wcpg_get_postback_stats();
    $inbound_test  = wcpg_get_inbound_test_result();

    // Calculate postback success rate.
    $postback_total = $postback_stats['success_count'] + $postback_stats['error_count'];
    $postback_rate  = $postback_total > 0
        ? round( ( $postback_stats['success_count'] / $postback_total ) * 100, 1 )
        : null;

    $has_issues = ! empty( $diag_results['issues'] );

    ?>
    <!-- Quick Actions -->
    <div style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'wcpg_action', 'run_diagnostics', $base_url ), 'wcpg_admin_action' ) ); ?>" class="button button-primary">Run Full Diagnostics</a>
        <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'wcpg_action', 'test_api', $base_url ), 'wcpg_admin_action' ) ); ?>" class="button">Test API</a>
        <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'wcpg_action', 'test_inbound', $base_url ), 'wcpg_admin_action' ) ); ?>" class="button" style="background: #f0f6fc; border-color: #2271b1;">🌐 Test External Access</a>
        <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'wcpg_action', 'report_health', $base_url ), 'wcpg_admin_action' ) ); ?>" class="button">Send Health Report</a>
    </div>

    <?php if ( $diag_time ) : ?>
        <p style="color: #666; font-size: 12px; margin-bottom: 15px;">Last diagnostics run: <?php echo esc_html( $diag_time ); ?></p>
    <?php endif; ?>

    <!-- Issues Section -->
    <?php if ( $has_issues ) : ?>
        <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 4px; padding: 15px; margin-bottom: 20px;">
            <h4 style="margin: 0 0 10px 0; color: #dc3232;">⚠ Issues Detected</h4>
            <?php foreach ( $diag_results['issues'] as $index => $issue_code ) : ?>
                <div style="background: white; border-left: 3px solid #dc3232; padding: 10px 15px; margin-bottom: 10px;">
                    <strong style="color: #dc3232;"><?php echo esc_html( wcpg_get_issue_title( $issue_code ) ); ?></strong>
                    <p style="margin: 5px 0 0 0; color: #666;"><?php echo isset( $diag_results['details'][ $index ] ) ? esc_html( $diag_results['details'][ $index ] ) : ''; ?></p>
                    <p style="margin: 5px 0 0 0; color: #2271b1; font-size: 12px;"><strong>Fix:</strong> <?php echo esc_html( wcpg_get_issue_fix( $issue_code ) ); ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <table class="form-table" style="margin: 0;">
        <!-- SSL Status -->
        <tr>
            <th scope="row" style="padding: 12px 0; width: 180px;">HTTPS / SSL</th>
            <td style="padding: 12px 0;">
                <?php if ( isset( $diag_results['has_ssl'] ) ) : ?>
                    <?php if ( $diag_results['has_ssl'] ) : ?>
                        <span style="color: #00a32a; font-weight: 600;">✓ Enabled</span>
                    <?php else : ?>
                        <span style="color: #dc3232; font-weight: 600;">✗ Not Enabled</span>
                        <br><small style="color: #dc3232;">Postbacks require HTTPS</small>
                    <?php endif; ?>
                <?php else : ?>
                    <span style="color: #dba617;">? Not checked</span>
                <?php endif; ?>
            </td>
        </tr>

        <!-- cURL Status -->
        <tr>
            <th scope="row" style="padding: 12px 0;">cURL Extension</th>
            <td style="padding: 12px 0;">
                <?php if ( isset( $diag_results['has_curl'] ) ) : ?>
                    <?php if ( $diag_results['has_curl'] ) : ?>
                        <span style="color: #00a32a; font-weight: 600;">✓ Installed</span>
                        <?php if ( $diag_results['curl_version'] ) : ?>
                            <span style="color: #666; margin-left: 5px;">(v<?php echo esc_html( $diag_results['curl_version'] ); ?>)</span>
                        <?php endif; ?>
                    <?php else : ?>
                        <span style="color: #dc3232; font-weight: 600;">✗ Not Available</span>
                        <br><small style="color: #dc3232;">Required for API communication</small>
                    <?php endif; ?>
                <?php else : ?>
                    <span style="color: #dba617;">? Not checked</span>
                <?php endif; ?>
            </td>
        </tr>

        <!-- OpenSSL Status -->
        <tr>
            <th scope="row" style="padding: 12px 0;">OpenSSL</th>
            <td style="padding: 12px 0;">
                <?php if ( ! empty( $diag_results['openssl_version'] ) ) : ?>
                    <span style="color: #00a32a; font-weight: 600;">✓ Available</span>
                    <span style="color: #666; margin-left: 5px;">(<?php echo esc_html( $diag_results['openssl_version'] ); ?>)</span>
                <?php elseif ( isset( $diag_results['openssl_version'] ) ) : ?>
                    <span style="color: #dc3232; font-weight: 600;">✗ Not Available</span>
                <?php else : ?>
                    <span style="color: #dba617;">? Not checked</span>
                <?php endif; ?>
            </td>
        </tr>

        <!-- Connectivity / Firewall -->
        <tr>
            <th scope="row" style="padding: 12px 0;">Outbound Connectivity</th>
            <td style="padding: 12px 0;">
                <?php if ( isset( $diag_results['can_reach_api'] ) ) : ?>
                    <?php if ( $diag_results['can_reach_api'] ) : ?>
                        <span style="color: #00a32a; font-weight: 600;">✓ Connected</span>
                    <?php else : ?>
                        <span style="color: #dc3232; font-weight: 600;">✗ Blocked</span>
                        <?php if ( ! empty( $diag_results['firewall_issue'] ) ) : ?>
                            <br><small style="color: #dc3232;"><strong>Firewall Issue Detected:</strong> Your server's firewall is blocking outbound connections.</small>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php else : ?>
                    <span style="color: #dba617;">? Not checked</span>
                <?php endif; ?>
            </td>
        </tr>

        <tr><td colspan="2"><hr style="border: none; border-top: 1px solid #eee; margin: 10px 0;"></td></tr>

        <!-- API Connection Status -->
        <tr>
            <th scope="row" style="padding: 12px 0;">API Connection</th>
            <td style="padding: 12px 0;">
                <?php if ( ! empty( $api_test['success'] ) ) : ?>
                    <span style="color: #00a32a; font-weight: 600;">✓ Working</span>
                    <span style="color: #666; margin-left: 10px;">(<?php echo esc_html( $api_test['response_time_ms'] ); ?>ms)</span>
                <?php elseif ( isset( $api_test['success'] ) && ! $api_test['success'] ) : ?>
                    <span style="color: #dc3232; font-weight: 600;">✗ Error</span>
                <?php else : ?>
                    <span style="color: #dba617; font-weight: 600;">? Not tested</span>
                <?php endif; ?>

                <?php if ( ! empty( $api_test['time'] ) ) : ?>
                    <br><small style="color: #666;">Last tested: <?php echo esc_html( $api_test['time'] ); ?></small>
                <?php endif; ?>
            </td>
        </tr>

        <!-- External Access Test -->
        <tr>
            <th scope="row" style="padding: 12px 0;">External Access</th>
            <td style="padding: 12px 0;">
                <?php if ( $inbound_test['success'] === true ) : ?>
                    <span style="color: #00a32a; font-weight: 600;">✓ Accessible</span>
                    <?php if ( ! empty( $inbound_test['response_time_ms'] ) ) : ?>
                        <span style="color: #666; margin-left: 10px;">(<?php echo esc_html( $inbound_test['response_time_ms'] ); ?>ms)</span>
                    <?php endif; ?>
                    <br><small style="color: #00a32a;"><?php echo esc_html( $inbound_test['message'] ); ?></small>
                <?php elseif ( $inbound_test['success'] === false ) : ?>
                    <span style="color: #dc3232; font-weight: 600;">✗ Blocked</span>
                    <?php if ( ! empty( $inbound_test['http_status'] ) ) : ?>
                        <span style="color: #666; margin-left: 10px;">(HTTP <?php echo esc_html( $inbound_test['http_status'] ); ?>)</span>
                    <?php endif; ?>
                    <br><small style="color: #dc3232;"><?php echo esc_html( $inbound_test['message'] ); ?></small>
                    <?php if ( ! empty( $inbound_test['details'] ) ) : ?>
                        <br><small style="color: #666;"><strong>Fix:</strong> <?php echo esc_html( $inbound_test['details'] ); ?></small>
                    <?php endif; ?>
                <?php else : ?>
                    <span style="color: #dba617; font-weight: 600;">? Not tested</span>
                    <br><small style="color: #666;">Click "🌐 Test External Access" to verify payment processor can reach your site</small>
                <?php endif; ?>

                <?php if ( ! empty( $inbound_test['time'] ) ) : ?>
                    <br><small style="color: #666;">Last tested: <?php echo esc_html( $inbound_test['time'] ); ?></small>
                <?php endif; ?>
            </td>
        </tr>

        <!-- Postback Endpoint URLs -->
        <tr>
            <th scope="row" style="padding: 12px 0;">Postback Endpoints</th>
            <td style="padding: 12px 0;">
                <div style="margin-bottom: 10px;">
                    <strong style="color: #2271b1;">REST API (Recommended):</strong>
                    <br>
                    <code style="font-size: 11px; background: #e7f3ff; padding: 4px 8px; border-radius: 3px; display: inline-block; margin-top: 4px; border: 1px solid #c3daef;"><?php echo esc_html( get_rest_url( null, 'digipay/v1/postback' ) ); ?></code>
                    <br>
                    <small style="color: #666;">Use this endpoint if your server blocks direct PHP file access</small>
                </div>
                <div>
                    <strong style="color: #666;">Legacy (Direct File):</strong>
                    <br>
                    <code style="font-size: 11px; background: #f0f0f1; padding: 4px 8px; border-radius: 3px; display: inline-block; margin-top: 4px;"><?php echo esc_html( get_option( 'siteurl' ) . '/wp-content/plugins/secure_plugin/paygo_postback.php' ); ?></code>
                </div>
            </td>
        </tr>

        <!-- Postback Status -->
        <tr>
            <th scope="row" style="padding: 12px 0;">Postback Status</th>
            <td style="padding: 12px 0;">
                <?php if ( $postback_rate !== null ) : ?>
                    <?php if ( $postback_rate >= 95 ) : ?>
                        <span style="color: #00a32a; font-weight: 600;">✓ Healthy</span>
                    <?php elseif ( $postback_rate >= 80 ) : ?>
                        <span style="color: #dba617; font-weight: 600;">⚠ Degraded</span>
                    <?php else : ?>
                        <span style="color: #dc3232; font-weight: 600;">✗ Failing</span>
                    <?php endif; ?>
                    <span style="color: #666; margin-left: 10px;">(<?php echo esc_html( $postback_rate ); ?>% success rate)</span>
                <?php else : ?>
                    <span style="color: #dba617; font-weight: 600;">? No data yet</span>
                    <br><small style="color: #666;">Stats will appear after first transaction</small>
                <?php endif; ?>

                <br><small style="color: #666;">
                    <span style="color: #00a32a;">✓ <?php echo esc_html( $postback_stats['success_count'] ); ?></span> successful,
                    <span style="color: #dc3232;">✗ <?php echo esc_html( $postback_stats['error_count'] ); ?></span> failed
                    <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'wcpg_action', 'reset_postback_stats', $base_url ), 'wcpg_admin_action' ) ); ?>" style="margin-left: 10px;" onclick="return confirm('Reset postback statistics?');">Reset</a>
                </small>

                <?php if ( $postback_stats['last_received'] ) : ?>
                    <br><small style="color: #666;">Last received: <?php echo esc_html( $postback_stats['last_received'] ); ?></small>
                <?php endif; ?>

                <?php if ( $postback_stats['last_error_message'] ) : ?>
                    <br><small style="color: #dc3232;">Last error: <?php echo esc_html( $postback_stats['last_error_message'] ); ?></small>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <!-- Server Info -->
    <details style="margin-top: 15px;">
        <summary style="cursor: pointer; color: #2271b1;">Server Information</summary>
        <div style="background: #f8f8f8; padding: 10px 15px; margin-top: 10px; border-radius: 4px; font-family: monospace; font-size: 12px;">
            <p style="margin: 5px 0;">PHP Version: <?php echo esc_html( phpversion() ); ?></p>
            <p style="margin: 5px 0;">WordPress: <?php echo esc_html( get_bloginfo( 'version' ) ); ?></p>
            <p style="margin: 5px 0;">WooCommerce: <?php echo defined( 'WC_VERSION' ) ? esc_html( WC_VERSION ) : 'N/A'; ?></p>
            <p style="margin: 5px 0;">Server: <?php echo isset( $_SERVER['SERVER_SOFTWARE'] ) ? esc_html( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : 'Unknown'; ?></p>
            <p style="margin: 5px 0;">Site URL: <?php echo esc_html( get_site_url() ); ?></p>
        </div>
    </details>
    <?php
}

/**
 * REMOVED: wcpg_display_diagnostics() was dead code (early return + 330 lines unreachable).
 * Diagnostics are rendered via wcpg_render_diagnostics_content() in the Admin tab.
 */

/* Orphaned dead code cleaned up. See DiagnosticsOrphanedCodeTest. */

/**
 * Get human-readable issue title
 */
function wcpg_get_issue_title( $issue_code ) {
    $titles = array(
        'NO_SSL' => '🔓 HTTPS Not Enabled',
        'NO_CURL' => '⚙️ cURL Not Installed',
        'CURL_OLD' => '⚙️ cURL Version Outdated',
        'NO_OPENSSL' => '🔐 OpenSSL Not Available',
        'FIREWALL' => '🛡️ Firewall Blocking Connections',
        'API_TIMEOUT' => '⏱️ API Connection Timeout',
        'API_ERROR' => '❌ API Connection Error',
        'POSTBACK_FAIL' => '📬 Postbacks Failing',
        'INBOUND_BLOCKED' => '🚫 External Requests Blocked'
    );
    return isset( $titles[$issue_code] ) ? $titles[$issue_code] : $issue_code;
}

/**
 * Get fix instructions for issue
 */
function wcpg_get_issue_fix( $issue_code ) {
    $fixes = array(
        'NO_SSL' => 'Install an SSL certificate and update your Site URL to use https://. Most hosts offer free SSL via Let\'s Encrypt.',
        'NO_CURL' => 'Contact your hosting provider and ask them to enable the PHP cURL extension.',
        'CURL_OLD' => 'Contact your hosting provider to update cURL to version 7.20 or higher.',
        'NO_OPENSSL' => 'Contact your hosting provider and ask them to enable the PHP OpenSSL extension.',
        'FIREWALL' => 'Contact your hosting provider and ask them to whitelist outbound connections to: hzdybwclwqkcobpwxzoo.supabase.co. If using a security plugin (Wordfence, Sucuri), add this domain to the allowlist.',
        'API_TIMEOUT' => 'This may be a temporary network issue. If it persists, contact your hosting provider about slow outbound connections.',
        'API_ERROR' => 'Check if the API endpoint is accessible. This may be a temporary service issue.',
        'POSTBACK_FAIL' => 'Check that your site has HTTPS enabled and is publicly accessible. Verify no security plugins are blocking incoming requests.',
        'INBOUND_BLOCKED' => 'External requests to your postback URL are being blocked. Check security plugins (Wordfence, Sucuri, etc.), Cloudflare settings, .htaccess rules, or hosting firewall. You may need to whitelist the payment processor IPs or create a bypass rule for the postback URL.'
    );
    return isset( $fixes[$issue_code] ) ? $fixes[$issue_code] : 'Contact your hosting provider for assistance.';
}

// ============================================================
// AUTOMATIC HEALTH REPORTING
// ============================================================

/**
 * Schedule daily health report
 */
function wcpg_schedule_health_report() {
    if ( ! wp_next_scheduled( 'wcpg_daily_health_report' ) ) {
        wp_schedule_event( time(), 'daily', 'wcpg_daily_health_report' );
    }
}

add_action( 'wcpg_daily_health_report', 'wcpg_daily_health_check' );
function wcpg_daily_health_check() {
    // Run full diagnostics
    wcpg_run_diagnostics();
    
    // Test API
    wcpg_test_api_connection();
    
    // Report health
    wcpg_report_health();
}

/**
 * Clean up on deactivation
 */
function wcpg_clear_scheduled_events() {
    wp_clear_scheduled_hook( 'wcpg_daily_health_report' );
}

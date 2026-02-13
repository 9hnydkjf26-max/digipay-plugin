<?php
/**
 * WooCommerce Payment Gateway - Postback Handler
 *
 * Security features:
 * - Rate limiting
 * - Input sanitization
 * - Referrer validation
 * - Debug logging only in WP_DEBUG mode
 * - Protected log directory
 * - Deduplication of postbacks
 *
 * @version 12.6.0
 */

// Set security headers
header( 'X-Content-Type-Options: nosniff' );
header( 'X-Frame-Options: DENY' );
header( 'X-XSS-Protection: 1; mode=block' );
header( 'Content-Security-Policy: default-src \'none\'; frame-ancestors \'none\'' );

// Load WordPress
require_once( dirname( __FILE__ ) . '/../../../wp-load.php' );

// ============================================================
// HELPER: Secure logging function
// ============================================================
function wcpg_secure_log( $message, $log_type = 'postback' ) {
    // Only log if WP_DEBUG is enabled
    if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
        return;
    }
    
    $upload_dir = wp_get_upload_dir();
    $log_dir = $upload_dir['basedir'] . '/wcpg-logs';
    
    // Create log directory with protection if it doesn't exist
    if ( ! file_exists( $log_dir ) ) {
        wp_mkdir_p( $log_dir );
        // Protect directory from direct access
        file_put_contents( $log_dir . '/.htaccess', "Order Deny,Allow\nDeny from all\n" );
        file_put_contents( $log_dir . '/index.php', '<?php // Silence is golden' );
    }
    
    $log_file = $log_dir . '/' . $log_type . '_' . wcpg_get_pacific_date( 'Y-m-d' ) . '.log';
    $timestamp = wcpg_get_pacific_date( 'Y-m-d H:i:s' );
    $raw_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
    $ip = filter_var( $raw_ip, FILTER_VALIDATE_IP ) ? $raw_ip : 'invalid';
    
    file_put_contents( 
        $log_file, 
        "[{$timestamp}] [{$ip}] {$message}\n", 
        FILE_APPEND | LOCK_EX 
    );
}

// ============================================================
// RATE LIMITING
// ============================================================
$client_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
$rate_limit_key = 'wcpg_rate_' . md5( $client_ip );
$rate_count = get_transient( $rate_limit_key );

if ( $rate_count === false ) {
    set_transient( $rate_limit_key, 1, MINUTE_IN_SECONDS );
} elseif ( $rate_count > 60 ) {
    // More than 60 requests per minute from same IP
    wcpg_secure_log( "Rate limit exceeded for IP: {$client_ip}", 'security' );
    http_response_code( 429 );
    die( 'Too many requests. Please try again later.' );
} else {
    set_transient( $rate_limit_key, $rate_count + 1, MINUTE_IN_SECONDS );
}

// ============================================================
// DEBUG LOGGING (Only in WP_DEBUG mode, sanitized)
// ============================================================
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
    // Sanitize request data for logging - redact sensitive fields
    $safe_request = $_REQUEST;
    $sensitive_fields = array( 
        'card_number', 'cvv', 'cc_number', 'card', 'password', 
        'card_exp', 'cc_exp', 'security_code', 'cvc' 
    );
    foreach ( $sensitive_fields as $field ) {
        if ( isset( $safe_request[ $field ] ) ) {
            $safe_request[ $field ] = '[REDACTED]';
        }
    }
    wcpg_secure_log( 'Request: ' . wp_json_encode( $safe_request ), 'postback_debug' );
}

// ============================================================
// INPUT SANITIZATION
// ============================================================
$order_id = isset( $_REQUEST['session'] ) ? absint( $_REQUEST['session'] ) : 0;
$status_post = isset( $_REQUEST['status_post'] ) ? sanitize_text_field( $_REQUEST['status_post'] ) : '';
$transid = isset( $_REQUEST['transid'] ) ? sanitize_text_field( $_REQUEST['transid'] ) : '';

// ============================================================
// EARLY EXIT: If no session/order_id, this isn't a real postback
// ============================================================
if ( empty( $order_id ) || $order_id < 1 ) {
    wcpg_secure_log( 'Ignored: No valid session/order_id', 'postback_debug' );
    exit;
}

// ============================================================
// BOT PROTECTION
// ============================================================
function wcpg_block_bad_bots() {
    $bad_agents = array(
        'BadBot', 'EvilScraper', 'FakeGoogleBot', 'SQLmap', 'nikto', 'nmap'
    );
    // Note: Don't block curl/wget as payment processors may use them.

    $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';

    foreach ( $bad_agents as $bad_agent ) {
        if ( stripos( $user_agent, $bad_agent ) !== false ) {
            wcpg_secure_log( "Blocked bad bot: {$user_agent}", 'security' );
            http_response_code( 403 );
            die( '403 Forbidden' );
        }
    }
}
wcpg_block_bad_bots();

// ============================================================
// REFERRER VALIDATION
// ============================================================
$gateway_settings = get_option( 'woocommerce_paygobillingcc_settings', array() );
$payment_gateway_url = isset( $gateway_settings['payment_gateway_url'] ) && ! empty( $gateway_settings['payment_gateway_url'] )
    ? $gateway_settings['payment_gateway_url']
    : 'https://secure.digipay.co/';

$allowed_referrers = array( rtrim( $payment_gateway_url, '/' ) );
$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : '';

// Only validate if referrer is present (some postbacks may legitimately have no referrer).
if ( ! empty( $referrer ) ) {
    $is_allowed = false;
    foreach ( $allowed_referrers as $allowed ) {
        if ( strpos( $referrer, $allowed ) === 0 ) {
            $is_allowed = true;
            break;
        }
    }
    if ( ! $is_allowed ) {
        wcpg_secure_log( "Blocked unauthorized referrer: {$referrer}", 'security' );
        http_response_code( 403 );
        die( 'Unauthorized access' );
    }
}

// ============================================================
// PROCESS POSTBACK (Uses shared logic from main plugin)
// ============================================================
// Check if shared function exists (loaded from main plugin file).
if ( ! function_exists( 'wcpg_process_postback' ) ) {
    wcpg_secure_log( 'wcpg_process_postback function not available', 'errors' );
    http_response_code( 500 );
    die( 'Internal error' );
}

$result = wcpg_process_postback( $order_id, $status_post, $transid, 'legacy' );

// Log the result.
if ( $result['code'] === 'duplicate' ) {
    wcpg_secure_log( "Duplicate postback ignored for order {$order_id}", 'postback_debug' );
    exit;
}

if ( $result['code'] === 'denied' ) {
    wcpg_secure_log( "Order {$order_id}: Payment denied", 'transactions' );
    exit;
}

if ( ! $result['success'] && $result['code'] === 'order_not_found' ) {
    // Don't track failures for diagnostic tests.
    $is_diagnostic_test = isset( $_SERVER['HTTP_X_WCPG_TEST'] ) && $_SERVER['HTTP_X_WCPG_TEST'] === 'true';
    if ( ! $is_diagnostic_test ) {
        wcpg_secure_log( "Order {$order_id} not found", 'errors' );
    }
    http_response_code( 404 );
    echo '<p style="color: red;">Order not found.</p>';
    exit;
}

wcpg_secure_log( "Order {$order_id}: Status updated to processing (trans: {$transid})", 'transactions' );

// ============================================================
// RETURN SUCCESS RESPONSE
// ============================================================
header( 'Content-Type: application/xml; charset=utf-8' );
?>
<rsp stat="ok" version="1.0">
<message id="100">Success</message>
<order_id><?php echo esc_html( $order_id ); ?></order_id>
</rsp>

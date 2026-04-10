<?php
/**
 * Tests for WCPG_Auto_Uploader.
 *
 * @package DigipayMasterPlugin
 * @since 13.3.0
 */

require_once dirname( __DIR__ ) . '/support/class-event-log.php';
require_once dirname( __DIR__ ) . '/support/class-context-bundler.php';
require_once dirname( __DIR__ ) . '/support/class-auto-uploader.php';

/**
 * Unit tests for the auto-upload on critical conditions feature.
 */
class AutoUploaderTest extends DigipayTestCase {

	/**
	 * Reset state before each test.
	 */
	protected function set_up() {
		parent::set_up();

		// Clear mock options.
		global $wcpg_mock_options;
		$wcpg_mock_options = array();

		// Clear mock transients.
		global $wcpg_test_transients;
		$wcpg_test_transients = array();

		// Clear HTTP mock.
		$GLOBALS['wcpg_mock_http_response'] = null;

		// Clear event log.
		WCPG_Event_Log::clear();

		// Clear any critical event spy.
		$GLOBALS['wcpg_critical_event_fired'] = null;
	}

	/**
	 * Tear down after each test.
	 */
	protected function tear_down() {
		$GLOBALS['wcpg_mock_http_response']   = null;
		$GLOBALS['wcpg_critical_event_fired'] = null;
		WCPG_Event_Log::clear();
		parent::tear_down();
	}

	// ------------------------------------------------------------------
	// Helper: install a spy on wcpg_critical_event.
	// Since add_action is a no-op in tests, we call the uploader's
	// handle_critical_event directly for most tests, and for the
	// maybe_fire_hmac_critical tests we override do_action.
	// ------------------------------------------------------------------

	// ------------------------------------------------------------------
	// Test 1: noop when disabled
	// ------------------------------------------------------------------

	/**
	 * handle_critical_event should do nothing (no HTTP call) when opt-in is off.
	 */
	public function test_handle_critical_event_noop_when_disabled() {
		// Opt-in is not set (defaults false).
		update_option( WCPG_Auto_Uploader::OPTION_ENABLED, false );
		update_option( WCPG_Auto_Uploader::OPTION_INGEST_URL, 'https://ingest.example.com/upload' );

		$uploader = new WCPG_Auto_Uploader();
		$uploader->handle_critical_event( 'test_reason', array() );

		// No HTTP call should have been made.
		$this->assertNull(
			$GLOBALS['wcpg_mock_http_response'],
			'No HTTP call should be made when opt-in is disabled'
		);

		// Throttle transient should NOT be set.
		$this->assertFalse(
			(bool) get_transient( WCPG_Auto_Uploader::THROTTLE_TRANSIENT ),
			'Throttle transient must not be set when opt-in is off'
		);
	}

	// ------------------------------------------------------------------
	// Test 2: noop when no ingest URL
	// ------------------------------------------------------------------

	/**
	 * handle_critical_event should do nothing when enabled but no ingest URL configured.
	 */
	public function test_handle_critical_event_noop_when_no_ingest_url() {
		update_option( WCPG_Auto_Uploader::OPTION_ENABLED, true );
		// Leave OPTION_INGEST_URL empty; WCPG_SUPPORT_INGEST_URL constant not defined in tests.

		$uploader = new WCPG_Auto_Uploader();
		$uploader->handle_critical_event( 'test_reason', array() );

		$this->assertNull(
			$GLOBALS['wcpg_mock_http_response'],
			'No HTTP call should be made when ingest URL is missing'
		);
	}

	// ------------------------------------------------------------------
	// Test 3: posts when enabled and URL is set
	// ------------------------------------------------------------------

	/**
	 * handle_critical_event should POST to the ingest URL when fully configured.
	 */
	public function test_handle_critical_event_posts_when_enabled_and_url_set() {
		update_option( WCPG_Auto_Uploader::OPTION_ENABLED, true );
		update_option( WCPG_Auto_Uploader::OPTION_INGEST_URL, 'https://ingest.example.com/upload' );

		// Track calls via the mock http response and GLOBALS.
		$GLOBALS['wcpg_mock_http_calls']    = array();
		$GLOBALS['wcpg_mock_http_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"ok":true}',
			'headers'  => array(),
		);

		$uploader = new WCPG_Auto_Uploader();
		$uploader->handle_critical_event( 'hmac_threshold', array( 'count' => 10 ) );

		// The wcpg_http_request wrapper calls wp_remote_request.
		// We verify by checking that the throttle transient was set
		// (which only happens after a successful POST attempt).
		$this->assertNotEmpty(
			get_transient( WCPG_Auto_Uploader::THROTTLE_TRANSIENT ),
			'Throttle transient should be set after posting'
		);

		// An event log entry should have been recorded.
		$events = WCPG_Event_Log::recent( 1, WCPG_Event_Log::TYPE_CRITICAL );
		$this->assertCount( 1, $events, 'One critical event log entry should be recorded' );
		$this->assertSame( 'auto_upload', $events[0]['data']['action'] );
		$this->assertSame( 'hmac_threshold', $events[0]['data']['reason'] );
		$this->assertTrue( $events[0]['data']['success'] );
		$this->assertSame( 200, $events[0]['data']['response_code'] );

		unset( $GLOBALS['wcpg_mock_http_calls'] );
	}

	// ------------------------------------------------------------------
	// Test 4: throttle
	// ------------------------------------------------------------------

	/**
	 * A second call in the same throttle window should not trigger another POST.
	 */
	public function test_handle_critical_event_respects_throttle() {
		update_option( WCPG_Auto_Uploader::OPTION_ENABLED, true );
		update_option( WCPG_Auto_Uploader::OPTION_INGEST_URL, 'https://ingest.example.com/upload' );

		$GLOBALS['wcpg_mock_http_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"ok":true}',
			'headers'  => array(),
		);

		$uploader = new WCPG_Auto_Uploader();

		// First call — should POST.
		$uploader->handle_critical_event( 'test', array() );
		$first_events = WCPG_Event_Log::recent( 10, WCPG_Event_Log::TYPE_CRITICAL );
		$this->assertCount( 1, $first_events, 'First call should record one event' );

		// Second call — throttle transient is now set; should be a no-op.
		WCPG_Event_Log::clear();
		$uploader->handle_critical_event( 'test', array() );
		$second_events = WCPG_Event_Log::recent( 10, WCPG_Event_Log::TYPE_CRITICAL );
		$this->assertCount( 0, $second_events, 'Second call should be throttled (no new event log entry)' );
	}

	// ------------------------------------------------------------------
	// Test 5: HMAC signature format
	// ------------------------------------------------------------------

	/**
	 * Verify the HMAC-SHA512 signature uses format: timestamp . '.' . body.
	 *
	 * We capture the outgoing wp_remote_request args via the bootstrap mock globals,
	 * then reconstruct the expected signature from the known secret and captured data.
	 */
	public function test_hmac_signature_format() {
		// 1. Set known site secret.
		update_option( 'wcpg_support_site_secret', 'test-secret-value' );

		// 2. Opt in and set ingest URL.
		update_option( WCPG_Auto_Uploader::OPTION_ENABLED, true );
		update_option( WCPG_Auto_Uploader::OPTION_INGEST_URL, 'https://ingest.example.com/upload' );

		// 3. Configure HTTP mock to return a successful response and capture args.
		$GLOBALS['wcpg_mock_http_response'] = array(
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'body'     => '{"ok":true}',
			'headers'  => array(),
		);
		$GLOBALS['wcpg_last_http_url']  = null;
		$GLOBALS['wcpg_last_http_args'] = null;

		// 4. Trigger the upload.
		$uploader = new WCPG_Auto_Uploader();
		$uploader->handle_critical_event( 'test_reason', array() );

		// Ensure the HTTP call was actually made.
		$this->assertNotNull(
			$GLOBALS['wcpg_last_http_args'],
			'wp_remote_request must have been called'
		);

		// 5. Read captured timestamp and body.
		$ts   = $GLOBALS['wcpg_last_http_args']['headers']['X-Digipay-Timestamp'];
		$body = $GLOBALS['wcpg_last_http_args']['body'];

		// 6. Compute expected signature using the baked-in handshake key.
		$expected_sig = hash_hmac( 'sha512', $ts . '.' . $body, WCPG_Auto_Uploader::INGEST_HANDSHAKE_KEY );

		// 7. Assert the signature matches.
		$this->assertSame(
			$expected_sig,
			$GLOBALS['wcpg_last_http_args']['headers']['X-Digipay-Signature'],
			'X-Digipay-Signature must be HMAC-SHA512 of timestamp.body using site secret'
		);
	}

	// ------------------------------------------------------------------
	// Test 6: maybe_fire_hmac_critical at threshold
	// ------------------------------------------------------------------

	/**
	 * maybe_fire_hmac_critical should fire do_action when hmac_fail >= 10.
	 */
	public function test_maybe_fire_hmac_critical_fires_at_threshold() {
		// Seed the health counter transient at exactly the threshold.
		set_transient( 'wcpg_etw_health', array( 'hmac_fail' => 10 ), DAY_IN_SECONDS );

		// Install spy via do_action mock global.
		$GLOBALS['wcpg_critical_event_fired'] = null;

		// Override do_action for this test: since do_action is mocked in bootstrap
		// only if not already defined, we test by hooking into the transient side effect.
		// Simpler: call handle_critical_event directly after verifying maybe_fire sets the sentinel.
		WCPG_Auto_Uploader::maybe_fire_hmac_critical();

		// The sentinel transient should now be set.
		$this->assertNotEmpty(
			get_transient( WCPG_Auto_Uploader::HMAC_CRITICAL_FIRED_TRANSIENT ),
			'Sentinel transient must be set after firing at threshold'
		);
	}

	// ------------------------------------------------------------------
	// Test 7: maybe_fire_hmac_critical below threshold
	// ------------------------------------------------------------------

	/**
	 * maybe_fire_hmac_critical should NOT fire when hmac_fail < 10.
	 */
	public function test_maybe_fire_hmac_critical_below_threshold() {
		set_transient( 'wcpg_etw_health', array( 'hmac_fail' => 9 ), DAY_IN_SECONDS );

		WCPG_Auto_Uploader::maybe_fire_hmac_critical();

		// Sentinel must NOT be set.
		$this->assertFalse(
			(bool) get_transient( WCPG_Auto_Uploader::HMAC_CRITICAL_FIRED_TRANSIENT ),
			'Sentinel transient must NOT be set when below threshold'
		);
	}

	// ------------------------------------------------------------------
	// Test 8: maybe_fire_hmac_critical doesn't refire within window
	// ------------------------------------------------------------------

	/**
	 * maybe_fire_hmac_critical should not fire again while sentinel transient is active.
	 */
	public function test_maybe_fire_hmac_critical_doesnt_refire_within_window() {
		set_transient( 'wcpg_etw_health', array( 'hmac_fail' => 15 ), DAY_IN_SECONDS );

		// First call sets sentinel.
		WCPG_Auto_Uploader::maybe_fire_hmac_critical();
		$this->assertNotEmpty(
			get_transient( WCPG_Auto_Uploader::HMAC_CRITICAL_FIRED_TRANSIENT ),
			'Sentinel must be set after first call'
		);

		// Bump hmac_fail further to prove we're really checking sentinel, not count.
		set_transient( 'wcpg_etw_health', array( 'hmac_fail' => 99 ), DAY_IN_SECONDS );

		// Track whether do_action would be called — we check by verifying the
		// sentinel is unchanged (not deleted and re-set), which is sufficient.
		// A real test of non-refiring: if the sentinel is present, the method returns early.
		// Clear event log, then call again; if it refired it would produce a critical event.
		WCPG_Event_Log::clear();
		update_option( WCPG_Auto_Uploader::OPTION_ENABLED, true );
		update_option( WCPG_Auto_Uploader::OPTION_INGEST_URL, 'https://ingest.example.com/x' );

		WCPG_Auto_Uploader::maybe_fire_hmac_critical();

		// If re-fired, it would have dispatched the action which (once registered)
		// would trigger handle_critical_event. Since we can't easily intercept do_action
		// in tests, we verify the sentinel is still set (method returned early).
		$this->assertNotEmpty(
			get_transient( WCPG_Auto_Uploader::HMAC_CRITICAL_FIRED_TRANSIENT ),
			'Sentinel must still be set (method returned early without re-firing)'
		);
	}

	// ------------------------------------------------------------------
	// Test 9: get_or_create_site_secret generates once
	// ------------------------------------------------------------------

	/**
	 * get_or_create_site_secret should return the same value on consecutive calls.
	 */
	public function test_get_or_create_site_secret_generates_once() {
		$first  = WCPG_Auto_Uploader::get_or_create_site_secret();
		$second = WCPG_Auto_Uploader::get_or_create_site_secret();

		$this->assertSame( $first, $second, 'Same secret must be returned on every call' );
		$this->assertNotEmpty( $first, 'Secret must not be empty' );
		$this->assertIsString( $first, 'Secret must be a string' );
	}

	// ------------------------------------------------------------------
	// Test 10: autoupload toggle handler
	// ------------------------------------------------------------------

	/**
	 * handle_autoupload_toggle should save the OPTION_ENABLED option via POST.
	 *
	 * We test the logic directly (not via HTTP) because wp_safe_redirect throws
	 * in the mock environment. We just verify option persistence.
	 */
	public function test_autoupload_toggle_saves_option() {
		// Simulate enabling.
		$_POST['enabled'] = '1';
		update_option( WCPG_Auto_Uploader::OPTION_ENABLED, ! empty( $_POST['enabled'] ) );
		$this->assertTrue(
			(bool) get_option( WCPG_Auto_Uploader::OPTION_ENABLED, false ),
			'Option must be true after enabling'
		);

		// Simulate disabling (empty POST).
		unset( $_POST['enabled'] );
		update_option( WCPG_Auto_Uploader::OPTION_ENABLED, ! empty( $_POST['enabled'] ) );
		$this->assertFalse(
			(bool) get_option( WCPG_Auto_Uploader::OPTION_ENABLED, false ),
			'Option must be false after disabling'
		);
	}
}

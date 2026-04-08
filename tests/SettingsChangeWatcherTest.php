<?php
/**
 * Tests for WCPG_Settings_Change_Watcher.
 *
 * @package Digipay
 */

require_once __DIR__ . '/../support/class-event-log.php';
require_once __DIR__ . '/../support/class-context-bundler.php';
require_once __DIR__ . '/../support/class-settings-change-watcher.php';

/**
 * Settings change watcher tests.
 */
class SettingsChangeWatcherTest extends DigipayTestCase {

	/**
	 * Clear the event log after each test.
	 */
	protected function tear_down() {
		WCPG_Event_Log::clear();
		parent::tear_down();
	}

	/**
	 * A changed field produces one settings_change event with correct hashes.
	 */
	public function test_diff_detects_changed_field() {
		WCPG_Settings_Change_Watcher::diff_and_record(
			'paygobillingcc',
			array( 'title' => 'Old' ),
			array( 'title' => 'New' )
		);

		$events = WCPG_Event_Log::recent( 50, WCPG_Event_Log::TYPE_SETTINGS_CHANGE );
		$this->assertCount( 1, $events );

		$event = $events[0];
		$this->assertSame( 'paygobillingcc', $event['gateway'] );
		$this->assertSame( 'title', $event['data']['field'] );
		$this->assertSame( substr( sha1( 'Old' ), 0, 8 ), $event['data']['old_hash'] );
		$this->assertSame( substr( sha1( 'New' ), 0, 8 ), $event['data']['new_hash'] );
	}

	/**
	 * A newly-added field produces an event with old_hash = '(missing)'.
	 */
	public function test_diff_detects_added_field() {
		WCPG_Settings_Change_Watcher::diff_and_record(
			'paygobillingcc',
			array( 'enabled' => 'yes' ),
			array( 'enabled' => 'yes', 'title' => 'New' )
		);

		$events = WCPG_Event_Log::recent( 50, WCPG_Event_Log::TYPE_SETTINGS_CHANGE );
		$this->assertCount( 1, $events );

		$event = $events[0];
		$this->assertSame( 'title', $event['data']['field'] );
		$this->assertSame( '(missing)', $event['data']['old_hash'] );
		$this->assertSame( substr( sha1( 'New' ), 0, 8 ), $event['data']['new_hash'] );
	}

	/**
	 * A removed field produces an event with new_hash = '(missing)'.
	 */
	public function test_diff_detects_removed_field() {
		WCPG_Settings_Change_Watcher::diff_and_record(
			'paygobillingcc',
			array( 'enabled' => 'yes', 'title' => 'Old' ),
			array( 'enabled' => 'yes' )
		);

		$events = WCPG_Event_Log::recent( 50, WCPG_Event_Log::TYPE_SETTINGS_CHANGE );
		$this->assertCount( 1, $events );

		$event = $events[0];
		$this->assertSame( 'title', $event['data']['field'] );
		$this->assertSame( substr( sha1( 'Old' ), 0, 8 ), $event['data']['old_hash'] );
		$this->assertSame( '(missing)', $event['data']['new_hash'] );
	}

	/**
	 * Identical old and new arrays produce no events.
	 */
	public function test_diff_records_nothing_when_unchanged() {
		$settings = array( 'enabled' => 'yes', 'title' => 'Same' );

		WCPG_Settings_Change_Watcher::diff_and_record( 'paygobillingcc', $settings, $settings );

		$events = WCPG_Event_Log::recent( 50, WCPG_Event_Log::TYPE_SETTINGS_CHANGE );
		$this->assertCount( 0, $events );
	}

	/**
	 * Secret fields are still hashed normally — hashes are 8 hex chars and do not
	 * contain the raw secret value.
	 */
	public function test_diff_hashes_secret_fields_normally() {
		WCPG_Settings_Change_Watcher::diff_and_record(
			'digipay_etransfer',
			array( 'webhook_secret_key' => 'sek1' ),
			array( 'webhook_secret_key' => 'sek2' )
		);

		$events = WCPG_Event_Log::recent( 50, WCPG_Event_Log::TYPE_SETTINGS_CHANGE );
		$this->assertCount( 1, $events );

		$event = $events[0];
		$this->assertSame( 'webhook_secret_key', $event['data']['field'] );

		// Both hashes must be exactly 8 hex characters.
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}$/', $event['data']['old_hash'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}$/', $event['data']['new_hash'] );

		// Raw secret values must not appear in either hash.
		$this->assertStringNotContainsString( 'sek1', $event['data']['old_hash'] );
		$this->assertStringNotContainsString( 'sek2', $event['data']['new_hash'] );
	}

	/**
	 * Array field values are json_encoded before hashing.
	 */
	public function test_diff_handles_array_values() {
		WCPG_Settings_Change_Watcher::diff_and_record(
			'wcpg_crypto',
			array( 'card_brands' => array( 'visa' ) ),
			array( 'card_brands' => array( 'visa', 'amex' ) )
		);

		$events = WCPG_Event_Log::recent( 50, WCPG_Event_Log::TYPE_SETTINGS_CHANGE );
		$this->assertCount( 1, $events );

		$event = $events[0];
		$this->assertSame( 'card_brands', $event['data']['field'] );

		// Hashes must differ since the values differ.
		$this->assertNotSame( $event['data']['old_hash'], $event['data']['new_hash'] );

		// Verify old_hash matches the expected SHA-1 of the json-encoded old value.
		$expected_old_hash = substr( sha1( wp_json_encode( array( 'visa' ) ) ), 0, 8 );
		$this->assertSame( $expected_old_hash, $event['data']['old_hash'] );
	}

	/**
	 * was_empty / now_empty flags track empty-to-non-empty transitions.
	 */
	public function test_diff_tracks_was_empty_transitions() {
		WCPG_Settings_Change_Watcher::diff_and_record(
			'digipay_etransfer',
			array( 'webhook_secret_key' => '' ),
			array( 'webhook_secret_key' => 'abc' )
		);

		$events = WCPG_Event_Log::recent( 50, WCPG_Event_Log::TYPE_SETTINGS_CHANGE );
		$this->assertCount( 1, $events );

		$event = $events[0];
		$this->assertTrue( $event['data']['was_empty'] );
		$this->assertFalse( $event['data']['now_empty'] );
	}

	/**
	 * Passing a non-array old_value (e.g. false — WP initial state) should not
	 * throw and should not record any events.
	 */
	public function test_diff_skips_non_array_old_value() {
		WCPG_Settings_Change_Watcher::diff_and_record(
			'paygobillingcc',
			false,
			array( 'title' => 'New' )
		);

		$events = WCPG_Event_Log::recent( 50, WCPG_Event_Log::TYPE_SETTINGS_CHANGE );
		$this->assertCount( 0, $events );
	}

	/**
	 * was_empty / now_empty flags track non-empty-to-empty transitions.
	 */
	public function test_diff_tracks_now_empty_transition() {
		WCPG_Settings_Change_Watcher::diff_and_record(
			'digipay_etransfer',
			array( 'webhook_secret_key' => 'abc' ),
			array( 'webhook_secret_key' => '' )
		);

		$events = WCPG_Event_Log::recent( 50, WCPG_Event_Log::TYPE_SETTINGS_CHANGE );
		$this->assertCount( 1, $events );

		$event = $events[0];
		$this->assertFalse( $event['data']['was_empty'] );
		$this->assertTrue( $event['data']['now_empty'] );
	}

	/**
	 * The gateway field in the recorded event matches the gateway argument.
	 */
	public function test_diff_records_gateway_correctly() {
		WCPG_Settings_Change_Watcher::diff_and_record(
			'wcpg_crypto',
			array( 'enabled' => 'no' ),
			array( 'enabled' => 'yes' )
		);

		$events = WCPG_Event_Log::recent( 50, WCPG_Event_Log::TYPE_SETTINGS_CHANGE );
		$this->assertCount( 1, $events );

		$this->assertSame( 'wcpg_crypto', $events[0]['gateway'] );
	}
}

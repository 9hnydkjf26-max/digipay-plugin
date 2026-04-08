<?php
/**
 * Tests for WCPG_Event_Log ring buffer.
 *
 * @package Digipay
 */

require_once __DIR__ . '/../support/class-event-log.php';

/**
 * Event log tests.
 */
class EventLogTest extends DigipayTestCase {

	/**
	 * Clear the event log after each test to prevent state leakage.
	 */
	protected function tear_down() {
		WCPG_Event_Log::clear();
		parent::tear_down();
	}

	/**
	 * record() appends an entry retrievable via recent().
	 */
	public function test_record_appends_entry() {
		WCPG_Event_Log::record(
			WCPG_Event_Log::TYPE_POSTBACK,
			array( 'outcome' => 'ok' ),
			'paygobillingcc',
			42
		);

		$entries = WCPG_Event_Log::recent( 10 );
		$this->assertCount( 1, $entries );

		$entry = $entries[0];
		$this->assertSame( WCPG_Event_Log::TYPE_POSTBACK, $entry['type'] );
		$this->assertSame( 'paygobillingcc', $entry['gateway'] );
		$this->assertSame( 42, $entry['order_id'] );
		$this->assertSame( array( 'outcome' => 'ok' ), $entry['data'] );
		$this->assertArrayHasKey( 'ts', $entry );
	}

	/**
	 * record() enforces MAX_ENTRIES cap by trimming oldest entries.
	 */
	public function test_record_enforces_cap() {
		// Record MAX_ENTRIES + 5 events.
		$total = WCPG_Event_Log::MAX_ENTRIES + 5;
		for ( $i = 0; $i < $total; $i++ ) {
			WCPG_Event_Log::record(
				WCPG_Event_Log::TYPE_POSTBACK,
				array( 'seq' => $i ),
				'paygobillingcc',
				$i
			);
		}

		$entries = WCPG_Event_Log::recent( WCPG_Event_Log::MAX_ENTRIES + 100 );
		$this->assertCount( WCPG_Event_Log::MAX_ENTRIES, $entries );

		// The last entry recorded should be the most recent (last in array).
		$last = end( $entries );
		$this->assertSame( $total - 1, $last['data']['seq'] );

		// The first 5 (seq 0-4) should have been trimmed.
		$first = reset( $entries );
		$this->assertSame( 5, $first['data']['seq'] );
	}

	/**
	 * recent() filters entries by type when $type is provided.
	 */
	public function test_recent_filters_by_type() {
		WCPG_Event_Log::record( WCPG_Event_Log::TYPE_POSTBACK, array(), 'paygobillingcc', 1 );
		WCPG_Event_Log::record( WCPG_Event_Log::TYPE_WEBHOOK, array(), 'digipay_etransfer', null );
		WCPG_Event_Log::record( WCPG_Event_Log::TYPE_POSTBACK, array(), 'paygobillingcc', 2 );

		$postbacks = WCPG_Event_Log::recent( 100, WCPG_Event_Log::TYPE_POSTBACK );
		$this->assertCount( 2, $postbacks );
		foreach ( $postbacks as $entry ) {
			$this->assertSame( WCPG_Event_Log::TYPE_POSTBACK, $entry['type'] );
		}

		$webhooks = WCPG_Event_Log::recent( 100, WCPG_Event_Log::TYPE_WEBHOOK );
		$this->assertCount( 1, $webhooks );
	}

	/**
	 * clear() removes all entries from the log.
	 */
	public function test_clear_removes_all() {
		WCPG_Event_Log::record( WCPG_Event_Log::TYPE_POSTBACK, array(), null, null );
		WCPG_Event_Log::record( WCPG_Event_Log::TYPE_WEBHOOK, array(), null, null );

		WCPG_Event_Log::clear();

		$entries = WCPG_Event_Log::recent();
		$this->assertSame( array(), $entries );
	}

	/**
	 * record() sets an ISO-8601 timestamp on each entry.
	 */
	public function test_record_sets_iso8601_timestamp() {
		WCPG_Event_Log::record( WCPG_Event_Log::TYPE_CRITICAL, array(), null, null );

		$entries = WCPG_Event_Log::recent( 1 );
		$this->assertCount( 1, $entries );

		$ts = $entries[0]['ts'];
		// ISO-8601 date format: YYYY-MM-DDTHH:MM:SS+00:00 (gmdate('c') uses +00:00).
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
			$ts
		);
	}

	/**
	 * recent() returns an empty array when no log is stored.
	 */
	public function test_recent_returns_empty_when_no_log() {
		$entries = WCPG_Event_Log::recent();
		$this->assertSame( array(), $entries );
	}

	/**
	 * recent() respects the $limit parameter.
	 */
	public function test_recent_respects_limit() {
		for ( $i = 0; $i < 10; $i++ ) {
			WCPG_Event_Log::record( WCPG_Event_Log::TYPE_API_CALL, array( 'seq' => $i ), null, null );
		}

		$recent = WCPG_Event_Log::recent( 3 );
		$this->assertCount( 3, $recent );
		// Should be the last 3 (seq 7, 8, 9).
		$this->assertSame( 9, $recent[2]['data']['seq'] );
		$this->assertSame( 7, $recent[0]['data']['seq'] );
	}

	/**
	 * All event type constants are defined with correct string values.
	 */
	public function test_event_type_constants_defined() {
		$this->assertSame( 'postback', WCPG_Event_Log::TYPE_POSTBACK );
		$this->assertSame( 'webhook', WCPG_Event_Log::TYPE_WEBHOOK );
		$this->assertSame( 'order_transition', WCPG_Event_Log::TYPE_ORDER_TRANSITION );
		$this->assertSame( 'api_call', WCPG_Event_Log::TYPE_API_CALL );
		$this->assertSame( 'limits_refresh', WCPG_Event_Log::TYPE_LIMITS_REFRESH );
		$this->assertSame( 'settings_change', WCPG_Event_Log::TYPE_SETTINGS_CHANGE );
		$this->assertSame( 'critical', WCPG_Event_Log::TYPE_CRITICAL );
	}

	/**
	 * MAX_ENTRIES constant is 500.
	 */
	public function test_max_entries_constant() {
		$this->assertSame( 500, WCPG_Event_Log::MAX_ENTRIES );
	}

	/**
	 * OPTION_KEY constant is correct.
	 */
	public function test_option_key_constant() {
		$this->assertSame( 'wcpg_event_log', WCPG_Event_Log::OPTION_KEY );
	}
}

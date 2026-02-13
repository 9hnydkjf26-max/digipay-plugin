<?php
/**
 * Tests for the E-Transfer Transaction Poller.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for E-Transfer Transaction Poller.
 */
class ETransferPollerTest extends DigipayTestCase {

	private $poller;

	protected function set_up() {
		parent::set_up();
		$this->poller = new WCPG_ETransfer_Transaction_Poller();
	}

	public function test_poller_class_exists() {
		$this->assertTrue(
			class_exists( 'WCPG_ETransfer_Transaction_Poller' ),
			'WCPG_ETransfer_Transaction_Poller class should exist'
		);
	}

	public function test_poller_constants() {
		$this->assertSame( 'wcpg_etransfer_poll_transactions', WCPG_ETransfer_Transaction_Poller::CRON_HOOK );
		$this->assertSame( 'every_five_minutes', WCPG_ETransfer_Transaction_Poller::CRON_INTERVAL );
		$this->assertSame( 50, WCPG_ETransfer_Transaction_Poller::BATCH_SIZE );
		$this->assertSame( 'wcpg_etransfer_processed_', WCPG_ETransfer_Transaction_Poller::TRANSIENT_PREFIX );
		$this->assertSame( 300, WCPG_ETransfer_Transaction_Poller::RECHECK_DELAY );
	}

	public function test_poller_can_be_instantiated() {
		$this->assertInstanceOf( 'WCPG_ETransfer_Transaction_Poller', $this->poller );
	}

	public function test_add_cron_interval() {
		$result = $this->poller->add_cron_interval( array() );

		$this->assertArrayHasKey( 'every_five_minutes', $result );
		$this->assertSame( 300, $result['every_five_minutes']['interval'] );
		$this->assertArrayHasKey( 'display', $result['every_five_minutes'] );
	}

	public function test_cron_interval_filter_registered() {
		$this->assertTrue(
			has_filter( 'cron_schedules', array( $this->poller, 'add_cron_interval' ) ) !== false,
			'add_cron_interval should be registered as a cron_schedules filter'
		);
	}

	public function test_cron_action_registered() {
		$this->assertTrue(
			has_action( WCPG_ETransfer_Transaction_Poller::CRON_HOOK, array( $this->poller, 'check_pending_transactions' ) ) !== false,
			'check_pending_transactions should be registered as a cron action'
		);
	}

	public function test_required_methods_exist() {
		$required_methods = array(
			'schedule_event',
			'unschedule_event',
			'is_scheduled',
			'get_next_scheduled',
			'check_pending_transactions',
			'manual_poll',
		);

		foreach ( $required_methods as $method_name ) {
			$this->assertTrue(
				method_exists( 'WCPG_ETransfer_Transaction_Poller', $method_name ),
				"{$method_name} method should exist"
			);
		}
	}
}

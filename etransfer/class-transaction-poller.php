<?php
/**
 * E-Transfer Transaction Poller
 *
 * Handles cron-based polling of pending E-Transfer transactions
 * to automatically complete orders when payments are received.
 *
 * @package DigipayMasterPlugin
 * @since 12.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transaction Poller Class for E-Transfer Gateway.
 */
class WCPG_ETransfer_Transaction_Poller {

	/**
	 * Cron hook name.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'wcpg_etransfer_poll_transactions';

	/**
	 * Custom cron interval name.
	 *
	 * @var string
	 */
	const CRON_INTERVAL = 'every_five_minutes';

	/**
	 * Transient prefix for tracking recently processed orders.
	 *
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'wcpg_etransfer_processed_';

	/**
	 * How long to wait before re-checking a processed order (in seconds).
	 *
	 * @var int
	 */
	const RECHECK_DELAY = 300; // 5 minutes

	/**
	 * Maximum orders to process per run.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 50;

	/**
	 * Constructor.
	 *
	 * Registers cron hooks and filters.
	 */
	public function __construct() {
		// Add custom cron interval.
		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );

		// Register the cron callback.
		add_action( self::CRON_HOOK, array( $this, 'check_pending_transactions' ) );
	}

	/**
	 * Add custom cron interval (every 5 minutes).
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array Modified cron schedules.
	 */
	public function add_cron_interval( $schedules ) {
		$schedules[ self::CRON_INTERVAL ] = array(
			'interval' => 300, // 5 minutes in seconds.
			'display'  => __( 'Every Five Minutes', 'wc-payment-gateway' ),
		);
		return $schedules;
	}

	/**
	 * Schedule the cron event.
	 *
	 * Called on plugin activation.
	 */
	public static function schedule_event() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_INTERVAL, self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the cron event.
	 *
	 * Called on plugin deactivation.
	 */
	public static function unschedule_event() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Main polling callback.
	 *
	 * Checks pending E-Transfer orders and updates their status
	 * based on transaction results from the API.
	 */
	public function check_pending_transactions() {
		// Get pending orders.
		$orders = $this->get_pending_etransfer_orders();

		if ( empty( $orders ) ) {
			return;
		}

		// Collect references and order mapping.
		$references = array();
		$order_map  = array(); // reference => order_id

		foreach ( $orders as $order_id ) {
			// Skip if we recently processed this order.
			if ( get_transient( self::TRANSIENT_PREFIX . $order_id ) ) {
				continue;
			}

			$reference = get_post_meta( $order_id, '_etransfer_reference', true );
			if ( ! empty( $reference ) ) {
				$references[]           = $reference;
				$order_map[ $reference ] = $order_id;
			}
		}

		if ( empty( $references ) ) {
			return;
		}

		// Get API client.
		$api_client = $this->get_api_client();
		if ( ! $api_client ) {
			return;
		}

		// Fetch transactions from API.
		$transactions = $api_client->get_transactions( $references );

		if ( is_wp_error( $transactions ) ) {
			// Log the error but don't fail silently.
			if ( function_exists( 'wc_get_logger' ) ) {
				$logger = wc_get_logger();
				$logger->error(
					'E-Transfer Poller: Failed to fetch transactions - ' . $transactions->get_error_message(),
					array( 'source' => 'etransfer-poller' )
				);
			}
			return;
		}

		// Process each transaction.
		foreach ( $transactions as $transaction ) {
			$reference = isset( $transaction['reference'] ) ? $transaction['reference'] : '';
			if ( empty( $reference ) || ! isset( $order_map[ $reference ] ) ) {
				continue;
			}

			$order_id = $order_map[ $reference ];
			$order    = wc_get_order( $order_id );

			if ( ! $order ) {
				continue;
			}

			$this->process_transaction_result( $order, $transaction );
		}
	}

	/**
	 * Get orders awaiting E-Transfer payment.
	 *
	 * @return array Array of order IDs.
	 */
	private function get_pending_etransfer_orders() {
		// Query all virtual gateway IDs — orders use these, not the master ID.
		$gateway_ids = array(
			'digipay_etransfer_email',
			'digipay_etransfer_url',
			'digipay_etransfer_manual',
		);

		return wc_get_orders( array(
			'payment_method' => $gateway_ids,
			'status'         => array( 'on-hold', 'pending' ),
			'return'         => 'ids',
			'limit'          => self::BATCH_SIZE,
			'orderby'        => 'date',
			'order'          => 'ASC',
		) );
	}

	/**
	 * Process a single transaction result.
	 *
	 * @param WC_Order $order       The WooCommerce order.
	 * @param array    $transaction Transaction data from API.
	 */
	private function process_transaction_result( $order, $transaction ) {
		$status = isset( $transaction['status'] ) ? strtolower( $transaction['status'] ) : '';

		// Map transaction statuses to order actions.
		switch ( $status ) {
			case 'approved':
			case 'completed':
				// Payment received - complete the order.
				$order->update_status(
					'completed',
					sprintf(
						/* translators: %s: transaction reference */
						__( 'E-Transfer payment received. Transaction reference: %s', 'wc-payment-gateway' ),
						isset( $transaction['reference'] ) ? $transaction['reference'] : ''
					)
				);

				// Store transaction details.
				if ( isset( $transaction['transaction_id'] ) ) {
					$order->update_meta_data( '_etransfer_transaction_id', sanitize_text_field( $transaction['transaction_id'] ) );
				}
				if ( isset( $transaction['completed_at'] ) ) {
					$order->update_meta_data( '_etransfer_completed_at', sanitize_text_field( $transaction['completed_at'] ) );
				}
				$order->save();

				// Mark as processed to avoid re-checking.
				set_transient( self::TRANSIENT_PREFIX . $order->get_id(), true, self::RECHECK_DELAY );

				// Log success.
				if ( function_exists( 'wc_get_logger' ) ) {
					$logger = wc_get_logger();
					$logger->info(
						sprintf( 'E-Transfer Poller: Order #%d marked as completed', $order->get_id() ),
						array( 'source' => 'etransfer-poller' )
					);
				}
				break;

			case 'failed':
			case 'cancelled':
			case 'declined':
			case 'expired':
				// Payment failed - mark order as failed.
				$order->update_status(
					'failed',
					sprintf(
						/* translators: %1$s: transaction status, %2$s: transaction reference */
						__( 'E-Transfer payment %1$s. Transaction reference: %2$s', 'wc-payment-gateway' ),
						$status,
						isset( $transaction['reference'] ) ? $transaction['reference'] : ''
					)
				);

				// Mark as processed.
				set_transient( self::TRANSIENT_PREFIX . $order->get_id(), true, self::RECHECK_DELAY );

				// Log failure.
				if ( function_exists( 'wc_get_logger' ) ) {
					$logger = wc_get_logger();
					$logger->warning(
						sprintf( 'E-Transfer Poller: Order #%d marked as failed (status: %s)', $order->get_id(), $status ),
						array( 'source' => 'etransfer-poller' )
					);
				}
				break;

			case 'pending':
			case 'processing':
			default:
				// Still pending - will check again on next run.
				// Set a shorter transient to avoid immediate re-checking.
				set_transient( self::TRANSIENT_PREFIX . $order->get_id(), true, 60 ); // 1 minute.
				break;
		}
	}

	/**
	 * Get the API client instance.
	 *
	 * @return WCPG_ETransfer_API_Client|null API client or null if not configured.
	 */
	private function get_api_client() {
		$settings = get_option( 'woocommerce_' . WC_Gateway_ETransfer::GATEWAY_ID . '_settings', array() );

		$client_id     = isset( $settings['client_id'] ) ? $settings['client_id'] : '';
		$client_secret = isset( $settings['client_secret'] ) ? $settings['client_secret'] : '';
		$api_endpoint  = isset( $settings['api_endpoint'] ) ? $settings['api_endpoint'] : '';
		$account_uuid  = isset( $settings['account_uuid'] ) ? $settings['account_uuid'] : '';

		// Check if API is configured.
		if ( empty( $client_id ) || empty( $client_secret ) || empty( $api_endpoint ) || empty( $account_uuid ) ) {
			return null;
		}

		return new WCPG_ETransfer_API_Client( $client_id, $client_secret, $api_endpoint, $account_uuid );
	}

	/**
	 * Manually trigger a poll (for admin/testing purposes).
	 *
	 * @return array Results array with counts.
	 */
	public function manual_poll() {
		$orders = $this->get_pending_etransfer_orders();
		$result = array(
			'pending_orders' => count( $orders ),
			'checked'        => 0,
			'completed'      => 0,
			'failed'         => 0,
		);

		if ( empty( $orders ) ) {
			return $result;
		}

		// Clear transients for manual poll to force check all.
		foreach ( $orders as $order_id ) {
			delete_transient( self::TRANSIENT_PREFIX . $order_id );
		}

		// Run the check.
		$this->check_pending_transactions();

		// Count results.
		foreach ( $orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$result['checked']++;
				if ( $order->has_status( 'completed' ) ) {
					$result['completed']++;
				} elseif ( $order->has_status( 'failed' ) ) {
					$result['failed']++;
				}
			}
		}

		return $result;
	}

	/**
	 * Check if the cron event is scheduled.
	 *
	 * @return bool True if scheduled, false otherwise.
	 */
	public static function is_scheduled() {
		return (bool) wp_next_scheduled( self::CRON_HOOK );
	}

	/**
	 * Get the next scheduled run time.
	 *
	 * @return int|false Unix timestamp or false if not scheduled.
	 */
	public static function get_next_scheduled() {
		return wp_next_scheduled( self::CRON_HOOK );
	}
}

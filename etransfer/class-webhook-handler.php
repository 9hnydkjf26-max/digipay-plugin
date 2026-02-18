<?php
/**
 * E-Transfer Webhook Handler
 *
 * Handles incoming webhooks from ShardNexus Merchant Webhooks system
 * for real-time transaction status updates, replacing the cron-based poller.
 *
 * Security layers:
 * 1. HMAC-SHA512 signature verification
 * 2. Timestamp validation (5-min window)
 * 3. Event ID deduplication (5-min transient)
 * 4. Rate limiting (60 req/min per IP)
 *
 * @package DigipayMasterPlugin
 * @since 13.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Webhook Handler Class for E-Transfer Gateway.
 */
class WCPG_ETransfer_Webhook_Handler {

	/**
	 * Signature header name.
	 *
	 * @var string
	 */
	const SIGNATURE_HEADER = 'X-ShardNexus-Webhook-Signature';

	/**
	 * Timestamp header name.
	 *
	 * @var string
	 */
	const TIMESTAMP_HEADER = 'X-ShardNexus-Webhook-Timestamp';

	/**
	 * Event ID header name.
	 *
	 * @var string
	 */
	const EVENT_ID_HEADER = 'X-ShardNexus-Webhook-Event-ID';

	/**
	 * Maximum timestamp age in seconds (5 minutes).
	 *
	 * @var int
	 */
	const TIMESTAMP_TOLERANCE = 300;

	/**
	 * Event deduplication window in seconds (5 minutes).
	 *
	 * @var int
	 */
	const DEDUP_WINDOW = 300;

	/**
	 * Rate limit: max requests per minute per IP.
	 *
	 * @var int
	 */
	const RATE_LIMIT = 60;

	/**
	 * Rate limit window in seconds.
	 *
	 * @var int
	 */
	const RATE_LIMIT_WINDOW = 60;

	/**
	 * Handle incoming webhook request.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_webhook( $request ) {
		$raw_body = $request->get_body();

		// Log that a webhook was received (payload details logged after processing).
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->debug(
				'E-Transfer Webhook: Payload received (' . strlen( $raw_body ) . ' bytes)',
				array( 'source' => 'etransfer-webhook' )
			);
		}

		// Rate limit check.
		$ip = $this->get_client_ip();
		if ( ! $this->check_rate_limit( $ip ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => 'Rate limit exceeded' ),
				429
			);
		}

		// Validate required headers.
		$signature = $request->get_header( 'x_shardnexus_webhook_signature' );
		$timestamp = $request->get_header( 'x_shardnexus_webhook_timestamp' );
		$event_id  = $request->get_header( 'x_shardnexus_webhook_event_id' );

		if ( empty( $signature ) || empty( $timestamp ) || empty( $event_id ) ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning(
					'E-Transfer Webhook: Missing required headers',
					array( 'source' => 'etransfer-webhook' )
				);
			}
			return new WP_REST_Response(
				array( 'success' => false, 'message' => 'Missing required headers' ),
				400
			);
		}

		// Validate timestamp freshness.
		if ( ! $this->validate_timestamp( $timestamp ) ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning(
					'E-Transfer Webhook: Stale timestamp: ' . $timestamp,
					array( 'source' => 'etransfer-webhook' )
				);
			}
			return new WP_REST_Response(
				array( 'success' => false, 'message' => 'Stale timestamp' ),
				400
			);
		}

		// Verify HMAC-SHA512 signature.
		$secret = $this->get_webhook_secret();
		if ( empty( $secret ) ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					'E-Transfer Webhook: No webhook secret configured',
					array( 'source' => 'etransfer-webhook' )
				);
			}
			return new WP_REST_Response(
				array( 'success' => false, 'message' => 'Webhook not configured' ),
				500
			);
		}

		if ( ! $this->verify_signature( $raw_body, $timestamp, $signature, $secret ) ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning(
					'E-Transfer Webhook: Invalid signature',
					array( 'source' => 'etransfer-webhook' )
				);
			}
			return new WP_REST_Response(
				array( 'success' => false, 'message' => 'Invalid signature' ),
				401
			);
		}

		// Check event ID idempotency.
		if ( $this->is_duplicate_event( $event_id ) ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->info(
					'E-Transfer Webhook: Duplicate event ID: ' . $event_id,
					array( 'source' => 'etransfer-webhook' )
				);
			}
			// Return 200 to prevent retries for already-processed events.
			return new WP_REST_Response(
				array( 'success' => true, 'message' => 'Already processed' ),
				200
			);
		}

		// Parse JSON body.
		$payload = json_decode( $raw_body, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					'E-Transfer Webhook: Invalid JSON: ' . json_last_error_msg(),
					array( 'source' => 'etransfer-webhook' )
				);
			}
			return new WP_REST_Response(
				array( 'success' => false, 'message' => 'Invalid JSON payload' ),
				400
			);
		}

		// Extract reference (flexible multi-path).
		$reference = $this->extract_field( $payload, 'reference' );
		if ( empty( $reference ) ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning(
					'E-Transfer Webhook: No reference found in payload',
					array( 'source' => 'etransfer-webhook' )
				);
			}
			// Return 200 — non-200 causes ShardNexus retries.
			return new WP_REST_Response(
				array( 'success' => true, 'message' => 'No reference found' ),
				200
			);
		}

		// Find WooCommerce order by _etransfer_reference meta.
		$order = $this->find_order_by_reference( $reference );
		if ( ! $order ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning(
					'E-Transfer Webhook: No order found for reference: ' . $reference,
					array( 'source' => 'etransfer-webhook' )
				);
			}
			// Return 200 — non-200 causes ShardNexus retries.
			return new WP_REST_Response(
				array( 'success' => true, 'message' => 'Order not found for reference' ),
				200
			);
		}

		// Extract status (flexible multi-path).
		$status = $this->extract_field( $payload, 'status' );
		if ( empty( $status ) ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning(
					'E-Transfer Webhook: No status found in payload for reference: ' . $reference,
					array( 'source' => 'etransfer-webhook' )
				);
			}
			// Save raw payload as order meta for manual review.
			$order->update_meta_data( '_etransfer_webhook_payload', wp_json_encode( $payload ) );
			$order->add_order_note( 'E-Transfer webhook received but no status found. Payload saved for review.' );
			$order->save();

			return new WP_REST_Response(
				array( 'success' => true, 'message' => 'No status in payload' ),
				200
			);
		}

		// Process status update.
		$this->process_status_update( $order, $status, $reference, $event_id );

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info(
				sprintf(
					'E-Transfer Webhook: Processed event %s — Order #%d, status: %s, ref: %s',
					$event_id,
					$order->get_id(),
					$status,
					$reference
				),
				array( 'source' => 'etransfer-webhook' )
			);
		}

		return new WP_REST_Response(
			array( 'success' => true, 'message' => 'Processed' ),
			200
		);
	}

	/**
	 * Validate timestamp freshness.
	 *
	 * @param string $timestamp Unix timestamp from header.
	 * @return bool True if within tolerance window.
	 */
	public function validate_timestamp( $timestamp ) {
		if ( ! is_numeric( $timestamp ) ) {
			return false;
		}

		$now  = time();
		$diff = abs( $now - (int) $timestamp );

		return $diff <= self::TIMESTAMP_TOLERANCE;
	}

	/**
	 * Verify HMAC-SHA512 signature.
	 *
	 * @param string $payload   Raw request body.
	 * @param string $timestamp Timestamp from header.
	 * @param string $signature Signature from header.
	 * @param string $secret    Webhook secret key.
	 * @return bool True if signature is valid.
	 */
	public function verify_signature( $payload, $timestamp, $signature, $secret ) {
		$signed_content  = $timestamp . '.' . $payload;
		$expected        = hash_hmac( 'sha512', $signed_content, $secret );

		return hash_equals( $expected, $signature );
	}

	/**
	 * Check if event ID has already been processed.
	 *
	 * @param string $event_id Event ID from header.
	 * @return bool True if duplicate.
	 */
	public function is_duplicate_event( $event_id ) {
		$transient_key = 'wcpg_etw_' . md5( $event_id );
		if ( get_transient( $transient_key ) ) {
			return true;
		}
		set_transient( $transient_key, true, self::DEDUP_WINDOW );
		return false;
	}

	/**
	 * Check rate limit for IP address.
	 *
	 * @param string $ip Client IP address.
	 * @return bool True if within rate limit.
	 */
	public function check_rate_limit( $ip ) {
		$transient_key = 'wcpg_etwrl_' . md5( $ip );
		$count         = (int) get_transient( $transient_key );

		if ( $count >= self::RATE_LIMIT ) {
			return false;
		}

		set_transient( $transient_key, $count + 1, self::RATE_LIMIT_WINDOW );
		return true;
	}

	/**
	 * Extract a field from the payload using flexible multi-path lookup.
	 *
	 * Checks paths in order:
	 * 1. data.{field}
	 * 2. data.transaction.{field}
	 * 3. Top-level {field}
	 * 4. Recursive search as fallback
	 *
	 * @param array  $payload The decoded JSON payload.
	 * @param string $field   The field name to extract.
	 * @return string|null The field value or null if not found.
	 */
	public function extract_field( $payload, $field ) {
		// Path 1: data.{field}
		if ( isset( $payload['data'][ $field ] ) && '' !== $payload['data'][ $field ] ) {
			return (string) $payload['data'][ $field ];
		}

		// Path 2: data.transaction.{field}
		if ( isset( $payload['data']['transaction'][ $field ] ) && '' !== $payload['data']['transaction'][ $field ] ) {
			return (string) $payload['data']['transaction'][ $field ];
		}

		// Path 3: Top-level {field}
		if ( isset( $payload[ $field ] ) && '' !== $payload[ $field ] ) {
			return (string) $payload[ $field ];
		}

		// Path 4: Recursive search.
		return $this->recursive_find( $payload, $field );
	}

	/**
	 * Recursively search for a field in a nested array.
	 *
	 * @param array  $data  The array to search.
	 * @param string $field The field name to find.
	 * @return string|null The field value or null.
	 */
	private function recursive_find( $data, $field ) {
		if ( ! is_array( $data ) ) {
			return null;
		}

		foreach ( $data as $key => $value ) {
			if ( $key === $field && ! is_array( $value ) && '' !== $value ) {
				return (string) $value;
			}
			if ( is_array( $value ) ) {
				$result = $this->recursive_find( $value, $field );
				if ( null !== $result ) {
					return $result;
				}
			}
		}

		return null;
	}

	/**
	 * Find a WooCommerce order by e-transfer reference.
	 *
	 * @param string $reference The transaction reference.
	 * @return WC_Order|null The order or null if not found.
	 */
	private function find_order_by_reference( $reference ) {
		$orders = wc_get_orders( array(
			'meta_key'   => '_etransfer_reference',
			'meta_value' => sanitize_text_field( $reference ),
			'limit'      => 1,
			'return'     => 'objects',
		) );

		return ! empty( $orders ) ? $orders[0] : null;
	}

	/**
	 * Process the transaction status update.
	 *
	 * @param WC_Order $order     The WooCommerce order.
	 * @param string   $status    Transaction status from webhook.
	 * @param string   $reference Transaction reference.
	 * @param string   $event_id  Webhook event ID.
	 */
	private function process_status_update( $order, $status, $reference, $event_id ) {
		// Update stored transaction status.
		$order->update_meta_data( '_etransfer_transaction_status', sanitize_text_field( $status ) );
		$order->update_meta_data( '_etransfer_webhook_event_id', sanitize_text_field( $event_id ) );

		$status_lower = strtolower( $status );

		switch ( $status_lower ) {
			case 'approved':
			case 'completed':
				$order->payment_complete( $reference );
				$order->add_order_note(
					sprintf(
						/* translators: 1: status, 2: reference */
						__( 'E-Transfer payment %1$s (webhook). Reference: %2$s', 'wc-payment-gateway' ),
						$status,
						$reference
					)
				);
				break;

			case 'failed':
			case 'cancelled':
			case 'declined':
			case 'expired':
				$order->update_status(
					'failed',
					sprintf(
						/* translators: 1: status, 2: reference */
						__( 'E-Transfer payment %1$s (webhook). Reference: %2$s', 'wc-payment-gateway' ),
						$status,
						$reference
					)
				);
				break;

			default:
				$order->add_order_note(
					sprintf(
						/* translators: 1: status, 2: reference */
						__( 'E-Transfer webhook received with status "%1$s". Reference: %2$s', 'wc-payment-gateway' ),
						$status,
						$reference
					)
				);
				break;
		}

		$order->save();
	}

	/**
	 * Get the webhook secret key from e-transfer gateway settings.
	 *
	 * @return string The webhook secret key.
	 */
	private function get_webhook_secret() {
		$settings = get_option( 'woocommerce_' . WC_Gateway_ETransfer::GATEWAY_ID . '_settings', array() );
		return isset( $settings['webhook_secret_key'] ) ? $settings['webhook_secret_key'] : '';
	}

	/**
	 * Get the client IP address.
	 *
	 * @return string Client IP address.
	 */
	private function get_client_ip() {
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			return trim( $ips[0] );
		}
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return '0.0.0.0';
	}
}

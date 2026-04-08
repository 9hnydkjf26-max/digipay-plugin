<?php
/**
 * Digipay Report Renderer
 *
 * Renders a bundle array produced by WCPG_Context_Bundler as human-readable
 * markdown that support staff (or Claude Code) can skim.
 *
 * @package DigipayMasterPlugin
 * @since 13.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a bundle array into markdown.
 */
class WCPG_Report_Renderer {

	/**
	 * Render the bundle as markdown.
	 *
	 * @param array $bundle Bundle array from WCPG_Context_Bundler::build().
	 * @return string
	 */
	public function render( array $bundle ) {
		$out   = array();
		$meta  = isset( $bundle['bundle_meta'] ) ? $bundle['bundle_meta'] : array();

		$out[] = '# Digipay Diagnostic Report';
		$out[] = '';
		$out[] = '- **Bundle ID:** ' . ( $meta['bundle_id'] ?? 'unknown' );
		$out[] = '- **Generated (UTC):** ' . ( $meta['generated_at_utc'] ?? 'unknown' );
		$out[] = '- **Plugin version:** ' . ( $meta['generator_version'] ?? 'unknown' );
		$out[] = '';

		$this->section( $out, 'Site', isset( $bundle['site'] ) ? $bundle['site'] : array() );
		$this->section( $out, 'Environment', isset( $bundle['environment'] ) ? $bundle['environment'] : array() );

		if ( isset( $bundle['environment_detail'] ) && is_array( $bundle['environment_detail'] ) ) {
			$this->render_environment_detail( $out, $bundle['environment_detail'] );
		}

		if ( isset( $bundle['baseline_comparison'] ) && is_array( $bundle['baseline_comparison'] ) ) {
			$this->render_baseline_comparison( $out, $bundle['baseline_comparison'] );
		}

		$this->section( $out, 'Encryption Key Status', isset( $bundle['encryption_key_status'] ) ? $bundle['encryption_key_status'] : array() );

		$out[] = '## Gateways';
		$out[] = '';
		if ( ! empty( $bundle['gateways'] ) && is_array( $bundle['gateways'] ) ) {
			foreach ( $bundle['gateways'] as $id => $settings ) {
				$out[] = '### ' . $id;
				$out[] = '```json';
				$out[] = wp_json_encode( $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				$out[] = '```';
				$out[] = '';
			}
		}

		$this->section( $out, 'Diagnostics', isset( $bundle['diagnostics'] ) ? $bundle['diagnostics'] : array() );
		$this->section( $out, 'Connectivity Tests', isset( $bundle['connectivity_tests'] ) ? $bundle['connectivity_tests'] : array() );
		$this->section( $out, 'E-Transfer Webhook Health (24h)', isset( $bundle['webhook_health'] ) ? $bundle['webhook_health'] : array() );

		$out[] = '## Recent Failed / Stuck Orders (last 14 days)';
		$out[] = '';
		if ( ! empty( $bundle['recent_failed_orders'] ) && is_array( $bundle['recent_failed_orders'] ) ) {
			foreach ( $bundle['recent_failed_orders'] as $order ) {
				$out[] = sprintf(
					'- #%s · %s · %s %s · %s',
					$order['id'] ?? '?',
					$order['status'] ?? '?',
					$order['total'] ?? '?',
					$order['currency'] ?? '',
					$order['payment_method'] ?? '?'
				);
			}
		} else {
			$out[] = '_None._';
		}
		$out[] = '';

		$this->render_order_correlations(
			$out,
			isset( $bundle['order_correlations'] ) ? $bundle['order_correlations'] : array()
		);

		$out[] = '## Logs';
		$out[] = '';
		if ( ! empty( $bundle['logs'] ) && is_array( $bundle['logs'] ) ) {
			foreach ( $bundle['logs'] as $source => $info ) {
				$out[] = '### ' . $source;
				if ( empty( $info['file'] ) ) {
					$out[] = '_No log file found._';
					$out[] = '';
					continue;
				}
				$out[] = '`' . $info['file'] . '` (last ' . count( (array) $info['lines'] ) . ' lines)';
				$out[] = '';
				$out[] = '```';
				foreach ( (array) $info['lines'] as $line ) {
					$out[] = $line;
				}
				$out[] = '```';
				$out[] = '';
			}
		}

		$this->render_events( $out, isset( $bundle['events'] ) ? $bundle['events'] : array() );

		$this->render_settings_changes( $out, isset( $bundle['settings_changes'] ) ? $bundle['settings_changes'] : array() );

		$this->section( $out, 'Option Snapshots', isset( $bundle['option_snapshots'] ) ? $bundle['option_snapshots'] : array() );

		$out[] = '---';
		$out[] = '';
		$out[] = '_SHA-256: ' . ( $meta['content_sha256'] ?? 'unknown' ) . '_';

		return implode( "\n", $out ) . "\n";
	}

	/**
	 * Render the Environment Detail section as a human-readable markdown list.
	 *
	 * @param array $out    Output buffer (passed by reference).
	 * @param array $detail Environment detail array from the bundle.
	 */
	protected function render_environment_detail( array &$out, array $detail ) {
		$out[] = '## Environment Detail';
		$out[] = '';

		$null_dash = function ( $val ) {
			return null === $val ? '—' : $val;
		};

		// Site health critical count.
		$health_count = isset( $detail['site_health_critical_count'] ) ? $detail['site_health_critical_count'] : null;
		$out[] = '- **Site health critical issues:** ' . $null_dash( $health_count );

		// Recent fatal errors count.
		$fatal = isset( $detail['recent_fatal_errors'] ) ? $detail['recent_fatal_errors'] : null;
		if ( null === $fatal ) {
			$fatal_label = '—';
		} else {
			$fatal_label = count( (array) $fatal );
		}
		$out[] = '- **Recent fatal errors:** ' . $fatal_label;

		// Drop-ins.
		$object_cache   = ! empty( $detail['object_cache_dropin'] ) ? 'yes' : 'no';
		$advanced_cache = ! empty( $detail['advanced_cache_dropin'] ) ? 'yes' : 'no';
		$out[] = '- **object-cache.php drop-in:** ' . $object_cache;
		$out[] = '- **advanced-cache.php drop-in:** ' . $advanced_cache;

		// LiteSpeed REST exclusion.
		$ls = isset( $detail['litespeed_rest_excluded'] ) ? $detail['litespeed_rest_excluded'] : null;
		if ( null === $ls ) {
			$ls_label = '— (LSCWP not detected)';
		} elseif ( $ls ) {
			$ls_label = 'present';
		} else {
			$ls_label = 'missing';
		}
		$out[] = '- **LiteSpeed REST exclusion:** ' . $ls_label;

		// Memory.
		$peak  = isset( $detail['php_memory_peak_mb'] ) ? $detail['php_memory_peak_mb'] : '—';
		$limit = isset( $detail['php_memory_limit'] )   ? $detail['php_memory_limit']   : '—';
		$out[] = '- **PHP memory peak:** ' . $peak . ' MB (limit: ' . $limit . ')';

		// Request counter.
		$requests = isset( $detail['requests_last_24h'] ) ? $detail['requests_last_24h'] : 0;
		$out[] = '- **Requests (last 24h):** ' . $requests;

		$out[] = '';

		// If there are recent fatal errors, dump them as a fenced block.
		if ( null !== $fatal && is_array( $fatal ) && count( $fatal ) > 0 ) {
			$out[] = '**Recent fatal errors:**';
			$out[] = '';
			$out[] = '```json';
			$out[] = wp_json_encode( $fatal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			$out[] = '```';
			$out[] = '';
		}
	}

	/**
	 * Render the Events section as a markdown table (most recent first).
	 *
	 * Truncates to the most recent 100 entries in the markdown output;
	 * the JSON bundle retains 200.
	 *
	 * @param array $out    Output buffer (passed by reference).
	 * @param array $events Events array from the bundle.
	 */
	protected function render_events( array &$out, array $events ) {
		$out[] = '## Events';
		$out[] = '';

		if ( empty( $events ) ) {
			$out[] = '_No events recorded yet._';
			$out[] = '';
			return;
		}

		// Most recent first, truncated to 100 rows in markdown.
		$display = array_reverse( $events );
		if ( count( $display ) > 100 ) {
			$display = array_slice( $display, 0, 100 );
		}

		$out[] = '| time | type | gateway | order | outcome |';
		$out[] = '|------|------|---------|-------|---------|';
		foreach ( $display as $entry ) {
			$ts      = isset( $entry['ts'] ) ? $entry['ts'] : '';
			$type    = isset( $entry['type'] ) ? $entry['type'] : '';
			$gateway = isset( $entry['gateway'] ) && null !== $entry['gateway'] ? $entry['gateway'] : '';
			$order   = isset( $entry['order_id'] ) && null !== $entry['order_id'] ? (string) $entry['order_id'] : '';
			$outcome = isset( $entry['data']['outcome'] ) ? $entry['data']['outcome'] : '';
			$out[] = sprintf( '| %s | %s | %s | %s | %s |', $ts, $type, $gateway, $order, $outcome );
		}
		$out[] = '';
	}

	/**
	 * Render the Settings Changes section as a markdown table (most recent first).
	 *
	 * @param array $out     Output buffer (passed by reference).
	 * @param array $changes Settings-change events from the bundle.
	 */
	protected function render_settings_changes( array &$out, array $changes ) {
		$out[] = '## Settings Changes (last 50)';
		$out[] = '';

		if ( empty( $changes ) ) {
			$out[] = '_No recorded settings changes._';
			$out[] = '';
			return;
		}

		// Most recent first.
		$display = array_reverse( $changes );

		$out[] = '| time | gateway | field | old → new | was→now empty |';
		$out[] = '|------|---------|-------|-----------|---------------|';

		foreach ( $display as $entry ) {
			$ts      = isset( $entry['ts'] ) ? $entry['ts'] : '';
			$gateway = isset( $entry['gateway'] ) && null !== $entry['gateway'] ? $entry['gateway'] : '';
			$field   = isset( $entry['data']['field'] ) ? $entry['data']['field'] : '';
			$old_h   = isset( $entry['data']['old_hash'] ) ? $entry['data']['old_hash'] : '';
			$new_h   = isset( $entry['data']['new_hash'] ) ? $entry['data']['new_hash'] : '';
			$was_e   = ! empty( $entry['data']['was_empty'] ) ? 'yes' : 'no';
			$now_e   = ! empty( $entry['data']['now_empty'] ) ? 'yes' : 'no';

			$out[] = sprintf(
				'| %s | %s | %s | %s → %s | %s→%s |',
				$ts,
				$gateway,
				$field,
				$old_h,
				$new_h,
				$was_e,
				$now_e
			);
		}
		$out[] = '';
	}

	/**
	 * Render the Order Correlations section (one subsection per order).
	 *
	 * @param array $out          Output buffer (passed by reference).
	 * @param array $correlations Correlation entries from the bundle.
	 */
	protected function render_order_correlations( array &$out, array $correlations ) {
		$out[] = '## Order Correlations';
		$out[] = '';

		if ( empty( $correlations ) ) {
			$out[] = '_No orders to correlate._';
			$out[] = '';
			return;
		}

		foreach ( $correlations as $entry ) {
			$id = isset( $entry['order_id'] ) ? $entry['order_id'] : '?';
			$out[] = '### Order #' . $id;
			$out[] = '';
			$out[] = sprintf(
				'- **Status:** %s · **Method:** %s · **Total:** %s %s · **Created:** %s',
				isset( $entry['status'] )         ? $entry['status']         : '?',
				isset( $entry['payment_method'] ) ? $entry['payment_method'] : '?',
				isset( $entry['total'] )          ? $entry['total']          : '?',
				isset( $entry['currency'] )       ? $entry['currency']       : '',
				isset( $entry['date_created'] )   ? $entry['date_created']   : '?'
			);
			$out[] = '';

			// Postback events.
			$out[] = '**Postback events:**';
			$out[] = '';
			$events = isset( $entry['postback_events'] ) ? (array) $entry['postback_events'] : array();
			if ( count( $events ) > 20 ) {
				$events = array_slice( $events, -20 );
			}
			if ( empty( $events ) ) {
				$out[] = '_none_';
			} else {
				foreach ( $events as $ev ) {
					$ts      = isset( $ev['ts'] )             ? $ev['ts']              : '';
					$type    = isset( $ev['type'] )           ? $ev['type']            : '';
					$outcome = isset( $ev['data']['outcome'] ) ? $ev['data']['outcome'] : '?';
					$out[]   = '- ' . $ts . ' · ' . $type . ' · outcome=' . $outcome;
				}
			}
			$out[] = '';

			// Webhook events.
			$out[] = '**Webhook events:**';
			$out[] = '';
			$events = isset( $entry['webhook_events'] ) ? (array) $entry['webhook_events'] : array();
			if ( count( $events ) > 20 ) {
				$events = array_slice( $events, -20 );
			}
			if ( empty( $events ) ) {
				$out[] = '_none_';
			} else {
				foreach ( $events as $ev ) {
					$ts      = isset( $ev['ts'] )             ? $ev['ts']              : '';
					$type    = isset( $ev['type'] )           ? $ev['type']            : '';
					$outcome = isset( $ev['data']['outcome'] ) ? $ev['data']['outcome'] : '?';
					$out[]   = '- ' . $ts . ' · ' . $type . ' · outcome=' . $outcome;
				}
			}
			$out[] = '';

			// API calls.
			$out[] = '**API calls mentioning this order:**';
			$out[] = '';
			$events = isset( $entry['api_call_events'] ) ? (array) $entry['api_call_events'] : array();
			if ( count( $events ) > 20 ) {
				$events = array_slice( $events, -20 );
			}
			if ( empty( $events ) ) {
				$out[] = '_none_';
			} else {
				foreach ( $events as $ev ) {
					$ts      = isset( $ev['ts'] )             ? $ev['ts']              : '';
					$type    = isset( $ev['type'] )           ? $ev['type']            : '';
					$outcome = isset( $ev['data']['outcome'] ) ? $ev['data']['outcome'] : '?';
					$out[]   = '- ' . $ts . ' · ' . $type . ' · outcome=' . $outcome;
				}
			}
			$out[] = '';

			// Recent notes.
			$out[] = '**Recent notes:**';
			$out[] = '';
			$notes = isset( $entry['recent_notes'] ) ? (array) $entry['recent_notes'] : array();
			if ( count( $notes ) > 20 ) {
				$notes = array_slice( $notes, 0, 20 );
			}
			if ( empty( $notes ) ) {
				$out[] = '_none_';
			} else {
				foreach ( $notes as $note ) {
					$ts      = isset( $note['ts'] )      ? $note['ts']      : '';
					$content = isset( $note['content'] ) ? $note['content'] : '';
					$out[]   = '- ' . $ts . ': ' . $content;
				}
			}
			$out[] = '';

			// Status history.
			$out[] = '**Status history:**';
			$out[] = '';
			$history = isset( $entry['status_history'] ) ? (array) $entry['status_history'] : array();
			if ( count( $history ) > 20 ) {
				$history = array_slice( $history, -20 );
			}
			if ( empty( $history ) ) {
				$out[] = '_none_';
			} else {
				foreach ( $history as $hs ) {
					$ts     = isset( $hs['ts'] )     ? $hs['ts']     : '';
					$status = isset( $hs['status'] ) ? $hs['status'] : '';
					$out[]  = '- ' . $ts . ': ' . $status;
				}
			}
			$out[] = '';
		}
	}

	/**
	 * Render the "Changes Since Last Healthy Run" baseline comparison section.
	 *
	 * @param array $out        Output buffer (passed by reference).
	 * @param array $comparison Comparison array from WCPG_Baseline::compare().
	 */
	protected function render_baseline_comparison( array &$out, array $comparison ) {
		$out[] = '## Changes Since Last Healthy Run';
		$out[] = '';

		$available    = isset( $comparison['available'] ) ? (bool) $comparison['available'] : false;
		$recorded_at  = isset( $comparison['baseline_recorded_at'] ) ? $comparison['baseline_recorded_at'] : null;

		if ( ! $available || null === $recorded_at ) {
			$out[] = '_No baseline recorded yet. The baseline is captured after the first successful Diagnose My Site run with no issues._';
			$out[] = '';
			return;
		}

		$metric_labels = array(
			'api_response_time_ms'  => 'API response time (ms)',
			'postback_success_rate' => 'Postback success rate',
			'webhook_processed'     => 'Webhook processed (24h)',
			'webhook_hmac_fail'     => 'Webhook HMAC failures (24h)',
			'php_memory_peak_mb'    => 'PHP memory peak (MB)',
		);

		$out[] = '| Metric | Current | Baseline | Δ |';
		$out[] = '|--------|---------|----------|---|';

		foreach ( $metric_labels as $key => $label ) {
			if ( ! isset( $comparison[ $key ] ) ) {
				continue;
			}
			$entry    = $comparison[ $key ];
			$current  = isset( $entry['current'] )   ? $entry['current']   : null;
			$baseline = isset( $entry['baseline'] )  ? $entry['baseline']  : null;
			$delta    = isset( $entry['delta_pct'] ) ? $entry['delta_pct'] : null;

			$current_str  = null !== $current  ? (string) $current  : '—';
			$baseline_str = null !== $baseline ? (string) $baseline : '—';

			if ( null === $delta ) {
				$delta_str = '—';
			} else {
				$delta_str = ( $delta >= 0 ? '+' : '' ) . $delta . '%';
			}

			$out[] = sprintf( '| %s | %s | %s | %s |', $label, $current_str, $baseline_str, $delta_str );
		}

		$out[] = '';
		$out[] = '_Baseline recorded at: ' . $recorded_at . '_';
		$out[] = '';
	}

	/**
	 * Append a generic key/value section as a fenced JSON block.
	 *
	 * @param array  $out   Output buffer (passed by reference).
	 * @param string $title Section title.
	 * @param mixed  $data  Section data.
	 */
	protected function section( array &$out, $title, $data ) {
		$out[] = '## ' . $title;
		$out[] = '';
		$out[] = '```json';
		$out[] = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$out[] = '```';
		$out[] = '';
	}
}

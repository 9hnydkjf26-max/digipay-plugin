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

		$this->section( $out, 'Option Snapshots', isset( $bundle['option_snapshots'] ) ? $bundle['option_snapshots'] : array() );

		$out[] = '---';
		$out[] = '';
		$out[] = '_SHA-256: ' . ( $meta['content_sha256'] ?? 'unknown' ) . '_';

		return implode( "\n", $out ) . "\n";
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

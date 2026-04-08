<?php
/**
 * Gateway Issue Notices
 *
 * After a merchant saves gateway settings, detects config-only issues and
 * stashes them in per-gateway transients. When a gateway settings page is
 * rendered, shows inline warning notices with a link to the Digipay Support
 * admin page.
 *
 * @package DigipayMasterPlugin
 * @since 13.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages contextual gateway settings notices.
 *
 * Instantiated once via wcpg_init_modules().
 */
class WCPG_Gateway_Issue_Notices {

	/**
	 * Transient key prefix. Suffix is the gateway ID.
	 */
	const TRANSIENT_PREFIX = 'wcpg_gateway_issues_';

	/**
	 * Transient TTL: 1 hour.
	 */
	const TRANSIENT_TTL = HOUR_IN_SECONDS;

	/**
	 * Gateway IDs managed by this class.
	 */
	const GATEWAY_IDS = array( 'paygobillingcc', 'digipay_etransfer', 'wcpg_crypto' );

	/**
	 * Register action hooks for all 3 gateway settings saves.
	 *
	 * Priority 20 — runs after T4's settings-change watcher.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'update_option_woocommerce_paygobillingcc_settings', array( $this, 'on_settings_saved' ), 20 );
		add_action( 'update_option_woocommerce_digipay_etransfer_settings', array( $this, 'on_settings_saved' ), 20 );
		add_action( 'update_option_woocommerce_wcpg_crypto_settings', array( $this, 'on_settings_saved' ), 20 );
	}

	/**
	 * Action callback: rebuild issues for all gateways after any one is saved.
	 *
	 * @return void
	 */
	public function on_settings_saved() {
		self::refresh_all();
	}

	/**
	 * Read current settings for all 3 gateways, run config-only detectors,
	 * group by gateway, and stash results in per-gateway transients.
	 *
	 * @return void
	 */
	public static function refresh_all() {
		if ( ! class_exists( 'WCPG_Issue_Catalog' ) ) {
			return;
		}

		// Read current settings for all 3 gateways.
		$all_settings = array(
			'paygobillingcc'   => self::coerce( get_option( 'woocommerce_paygobillingcc_settings', array() ) ),
			'digipay_etransfer' => self::coerce( get_option( 'woocommerce_digipay_etransfer_settings', array() ) ),
			'wcpg_crypto'      => self::coerce( get_option( 'woocommerce_wcpg_crypto_settings', array() ) ),
		);

		// Run all config-only detectors once.
		$detected = WCPG_Issue_Catalog::detect_config_only( $all_settings );

		// Group detected issues by gateway ID using ID-prefix heuristic.
		$by_gateway = array(
			'paygobillingcc'    => array(),
			'digipay_etransfer' => array(),
			'wcpg_crypto'       => array(),
		);

		foreach ( $detected as $issue ) {
			$id     = isset( $issue['id'] ) ? (string) $issue['id'] : '';
			$prefix = self::id_prefix( $id );

			switch ( $prefix ) {
				case 'E':
				case 'W':
					$by_gateway['digipay_etransfer'][] = $issue;
					break;
				case 'C':
					$by_gateway['wcpg_crypto'][] = $issue;
					break;
				case 'P':
					$by_gateway['paygobillingcc'][] = $issue;
					break;
				default:
					// Cross-cutting (X, S, or unknown) — store under all 3.
					$by_gateway['paygobillingcc'][]    = $issue;
					$by_gateway['digipay_etransfer'][] = $issue;
					$by_gateway['wcpg_crypto'][]       = $issue;
					break;
			}
		}

		// Stash results per gateway.
		foreach ( $by_gateway as $gateway_id => $issues ) {
			set_transient( self::TRANSIENT_PREFIX . $gateway_id, $issues, self::TRANSIENT_TTL );
		}
	}

	/**
	 * Return the stashed issues for a given gateway ID.
	 *
	 * Returns an empty array when the transient is absent or invalid.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return array Array of issue arrays.
	 */
	public static function get_issues_for_gateway( $gateway_id ) {
		$result = get_transient( self::TRANSIENT_PREFIX . $gateway_id );
		if ( ! is_array( $result ) ) {
			return array();
		}
		return $result;
	}

	/**
	 * Render inline warning notices for each stashed issue.
	 *
	 * Outputs nothing if no issues are stashed. Each notice links to the
	 * Digipay Support admin page.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return void
	 */
	public static function render_notices_for_gateway( $gateway_id ) {
		$issues = self::get_issues_for_gateway( $gateway_id );

		if ( empty( $issues ) ) {
			return;
		}

		$support_url = esc_url( admin_url( 'admin.php?page=wcpg-support' ) );

		foreach ( $issues as $issue ) {
			$id            = isset( $issue['id'] ) ? esc_html( $issue['id'] ) : '';
			$title         = isset( $issue['title'] ) ? esc_html( $issue['title'] ) : '';
			$plain_english = isset( $issue['plain_english'] ) ? esc_html( $issue['plain_english'] ) : '';

			echo '<div class="notice notice-warning inline">';
			echo '<p>';
			echo '<strong>' . $id . ' ' . $title . '</strong>';
			echo ' &mdash; ';
			echo $plain_english;
			echo ' <a href="' . $support_url . '">Get help &rarr;</a>';
			echo '</p>';
			echo '</div>';
		}
	}

	// ------------------------------------------------------------------
	// Private helpers
	// ------------------------------------------------------------------

	/**
	 * Coerce a value to an array (handles false when option missing).
	 *
	 * @param mixed $value Value from get_option().
	 * @return array
	 */
	private static function coerce( $value ) {
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Extract the single-letter prefix from an issue ID like "WCPG-W-002".
	 *
	 * Returns the letter between the first and second hyphens, or empty string
	 * if the ID doesn't match the expected format.
	 *
	 * @param string $id Issue ID.
	 * @return string Single uppercase letter or empty string.
	 */
	private static function id_prefix( $id ) {
		// IDs follow the pattern WCPG-{letter}-{digits}.
		if ( preg_match( '/^WCPG-([A-Z])-/', $id, $matches ) ) {
			return $matches[1];
		}
		return '';
	}
}

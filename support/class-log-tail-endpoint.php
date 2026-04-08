<?php
/**
 * Digipay Live Log Tail REST Endpoint
 *
 * Provides GET /wp-json/digipay/v1/support/log-tail which returns the last
 * 50 lines from each WooCommerce log source used by this plugin.
 *
 * @package DigipayMasterPlugin
 * @since 13.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST endpoint that serves live log tail data.
 */
class WCPG_Log_Tail_Endpoint {

	/**
	 * Log source handles to include in the tail.
	 *
	 * @var string[]
	 */
	const SOURCES = array(
		'digipay-postback',
		'etransfer-webhook',
		'digipay-etransfer',
		'wcpg_crypto',
	);

	/**
	 * Number of lines to return per source.
	 *
	 * @var int
	 */
	const LINES_PER_SOURCE = 50;

	/**
	 * Register the REST route via rest_api_init.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/**
	 * Register the GET route with WordPress REST API.
	 */
	public function register_route() {
		register_rest_route(
			'digipay/v1',
			'/support/log-tail',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'permission_callback' ),
			)
		);
	}

	/**
	 * Permission callback — requires manage_woocommerce capability.
	 *
	 * @return bool
	 */
	public function permission_callback() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Handle the GET request and return log tail data.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function handle_request( $request ) {
		$sources = array();

		foreach ( self::SOURCES as $source ) {
			$file  = $this->find_log_file( $source );
			$lines = $file ? $this->tail_file( $file, self::LINES_PER_SOURCE ) : array();

			// Scrub PII if the bundler class and method are available.
			if ( class_exists( 'WCPG_Context_Bundler' )
				&& method_exists( 'WCPG_Context_Bundler', 'scrub_pii' ) ) {
				$lines = array_map( array( 'WCPG_Context_Bundler', 'scrub_pii' ), $lines );
			}

			$sources[] = array(
				'name'  => $source,
				'file'  => $file,
				'lines' => array_values( $lines ),
			);
		}

		return new WP_REST_Response(
			array(
				'ts'      => gmdate( 'c' ),
				'sources' => $sources,
			),
			200
		);
	}

	/**
	 * Locate the most recent log file for a WooCommerce log source.
	 *
	 * Duplicates the logic from WCPG_Context_Bundler::find_log_file() to keep
	 * coupling low.  In test mode, $GLOBALS['wcpg_test_log_dir'] overrides
	 * WC_LOG_DIR so tests can inject temp files.
	 *
	 * @param string $source Log source handle.
	 * @return string|null Absolute path or null if not found.
	 */
	protected function find_log_file( $source ) {
		// Allow tests to override the log directory.
		if ( isset( $GLOBALS['wcpg_test_log_dir'] ) ) {
			$log_dir = $GLOBALS['wcpg_test_log_dir'];
		} elseif ( defined( 'WC_LOG_DIR' ) ) {
			$log_dir = WC_LOG_DIR;
		} else {
			return null;
		}

		$pattern = $log_dir . $source . '-*.log';
		$matches = glob( $pattern );
		if ( empty( $matches ) ) {
			return null;
		}

		// Return newest file by modification time.
		usort(
			$matches,
			function ( $a, $b ) {
				return filemtime( $b ) - filemtime( $a );
			}
		);
		return $matches[0];
	}

	/**
	 * Return the last $n lines of a file.
	 *
	 * Duplicates the logic from WCPG_Context_Bundler::tail_file() to keep
	 * coupling low.
	 *
	 * @param string $path File path.
	 * @param int    $n    Number of lines.
	 * @return array
	 */
	protected function tail_file( $path, $n ) {
		if ( ! is_readable( $path ) ) {
			return array();
		}
		$lines = @file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( ! is_array( $lines ) ) {
			return array();
		}
		if ( count( $lines ) > $n ) {
			$lines = array_slice( $lines, -1 * $n );
		}
		return $lines;
	}
}

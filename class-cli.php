<?php
/**
 * Digipay WP-CLI commands.
 *
 * Provides `wp digipay doctor` for support staff and merchants to surface
 * known issues from the issue catalog without opening WP Admin.
 *
 * @package DigipayMasterPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Digipay support commands.
 */
class WCPG_CLI_Command {

	/**
	 * Run the issue catalog against this site and print matched issues.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. One of: table, json, yaml.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * [--severity=<severity>]
	 * : Filter to one severity (info, warning, error, critical).
	 *
	 * ## EXAMPLES
	 *
	 *     wp digipay doctor
	 *     wp digipay doctor --severity=error
	 *     wp digipay doctor --format=json
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Flags.
	 */
	public function doctor( $args, $assoc_args ) {
		if ( ! class_exists( 'WCPG_Context_Bundler' ) || ! class_exists( 'WCPG_Issue_Catalog' ) ) {
			WP_CLI::error( 'Digipay support module not loaded. Is the plugin active?' );
		}

		$bundle  = WCPG_Context_Bundler::build();
		$matched = WCPG_Issue_Catalog::detect_all( $bundle );

		if ( ! empty( $assoc_args['severity'] ) ) {
			$wanted  = strtolower( (string) $assoc_args['severity'] );
			$matched = array_values(
				array_filter(
					$matched,
					static function ( $issue ) use ( $wanted ) {
						return isset( $issue['severity'] ) && $issue['severity'] === $wanted;
					}
				)
			);
		}

		if ( empty( $matched ) ) {
			WP_CLI::success( 'No known issues detected.' );
			return;
		}

		$rows = array();
		foreach ( $matched as $issue ) {
			$rows[] = array(
				'id'       => isset( $issue['id'] ) ? $issue['id'] : '',
				'severity' => isset( $issue['severity'] ) ? $issue['severity'] : '',
				'title'    => isset( $issue['title'] ) ? $issue['title'] : '',
				'fix'      => isset( $issue['fix'] ) ? $issue['fix'] : '',
			);
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'severity', 'title', 'fix' ) );

		// Non-zero exit if any error/critical was surfaced — useful in CI/cron.
		foreach ( $matched as $issue ) {
			$sev = isset( $issue['severity'] ) ? $issue['severity'] : '';
			if ( 'error' === $sev || 'critical' === $sev ) {
				WP_CLI::halt( 1 );
			}
		}
	}
}

WP_CLI::add_command( 'digipay', 'WCPG_CLI_Command' );

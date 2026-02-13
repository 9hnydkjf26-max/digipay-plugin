<?php
/**
 * E-Transfer Template Loader
 *
 * Handles loading template files for E-Transfer gateway.
 *
 * @package DigipayMasterPlugin
 * @since 12.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template Loader Class.
 */
class WCPG_ETransfer_Template_Loader {

	/**
	 * Template directory path.
	 *
	 * @var string
	 */
	private $template_path;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->template_path = dirname( __FILE__ ) . '/templates/';
	}

	/**
	 * Load a template file.
	 *
	 * @param string $template_name Template file name.
	 * @param array  $args          Variables to pass to template.
	 * @param bool   $echo          Whether to echo or return output.
	 * @return string|void Template output if not echoing.
	 */
	public function load_template( $template_name, $args = array(), $echo = true ) {
		// Prevent path traversal — strip directory components and enforce .php extension.
		$template_name = basename( $template_name );
		if ( '.php' !== substr( $template_name, -4 ) ) {
			return '';
		}

		$template_file = $this->template_path . $template_name;

		// Verify resolved path stays within the template directory.
		$real_path = realpath( $template_file );
		$real_dir  = realpath( $this->template_path );
		if ( ! $real_path || ! $real_dir || 0 !== strpos( $real_path, $real_dir ) ) {
			return '';
		}

		if ( ! file_exists( $template_file ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// translators: %s is the template file path.
				error_log( sprintf( 'E-Transfer template not found: %s', $template_file ) );
			}
			return '';
		}

		// Extract args to variables.
		if ( ! empty( $args ) && is_array( $args ) ) {
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			extract( $args );
		}

		if ( $echo ) {
			include $template_file;
		} else {
			ob_start();
			include $template_file;
			return ob_get_clean();
		}
	}

	/**
	 * Get template path.
	 *
	 * @return string
	 */
	public function get_template_path() {
		return $this->template_path;
	}
}

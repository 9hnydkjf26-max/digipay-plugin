<?php
/**
 * Digipay Support Admin Page
 *
 * Registers a submenu under WooCommerce that lets a merchant generate and
 * download a diagnostic bundle they can email to Digipay support.
 *
 * @package DigipayMasterPlugin
 * @since 13.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page controller.
 */
class WCPG_Support_Admin_Page {

	const MENU_SLUG  = 'wcpg-support';
	const NONCE_NAME = 'wcpg_support_nonce';
	const NONCE_ACTION = 'wcpg_support_generate';
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * WordPress page hook suffix returned by add_submenu_page().
	 *
	 * @var string|false
	 */
	protected $page_hook = false;

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_wcpg_support_generate', array( $this, 'handle_generate' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add the submenu page under WooCommerce.
	 */
	public function add_menu() {
		$this->page_hook = add_submenu_page(
			'woocommerce',
			'Digipay Support',
			'Digipay Support',
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue shared admin styles on the support page only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( ! $this->page_hook || $hook !== $this->page_hook ) {
			return;
		}
		if ( function_exists( 'wp_enqueue_style' ) ) {
			wp_enqueue_style( 'woocommerce_admin_styles' );
			wp_enqueue_style(
				'wcpg-admin-settings',
				plugin_dir_url( WCPG_PLUGIN_FILE ) . 'assets/css/admin-settings.css',
				array(),
				defined( 'WCPG_VERSION' ) ? WCPG_VERSION : '1.0.0'
			);
		}
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-payment-gateway' ) );
		}

		$summary = $this->build_summary();
		?>
		<div class="wrap">
			<h1>Digipay Support</h1>
			<p>
				If you are having trouble with Digipay payments, click the button below. We will build a
				diagnostic file that contains your plugin configuration, recent logs, and connectivity test
				results. <strong>Secrets and customer personal information are automatically removed.</strong>
				Once downloaded, email the file to <code>support@digipay.co</code> (or attach it to your
				support ticket).
			</p>

			<h2>Current Status</h2>
			<table class="widefat striped" style="max-width:720px;">
				<tbody>
					<tr><th>Plugin version</th><td><?php echo esc_html( $summary['plugin_version'] ); ?></td></tr>
					<tr><th>Postbacks (stored stats)</th><td><?php echo esc_html( $summary['postback_stats'] ); ?></td></tr>
					<tr><th>E-Transfer webhook (24h)</th><td><?php echo esc_html( $summary['webhook_health'] ); ?></td></tr>
					<tr><th>Last API connectivity test</th><td><?php echo esc_html( $summary['api_last_test'] ); ?></td></tr>
				</tbody>
			</table>

			<h2>Run a Diagnostic</h2>
			<p>
				Use these tools to verify your site is properly configured and reachable. Results are stored
				locally and included in the diagnostic report you can download below.
			</p>
			<div style="background:#fff; border:1px solid #ccd0d4; border-left:4px solid #2271b1; padding:15px 20px; margin:12px 0 24px; box-shadow:0 1px 1px rgba(0,0,0,.04);">
				<?php
				if ( function_exists( 'wcpg_render_diagnostics_content' ) ) {
					wcpg_render_diagnostics_content( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
				} else {
					echo '<p>Diagnostic tools are unavailable. Please reinstall the plugin.</p>';
				}
				?>
			</div>

			<h2>Generate Diagnostic Report</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wcpg_support_generate" />
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<?php submit_button( 'Generate Diagnostic Report', 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the generate POST: build the bundle, stream a zip.
	 */
	public function handle_generate() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wc-payment-gateway' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		if ( ! class_exists( 'WCPG_Context_Bundler' ) ) {
			wp_die( 'Context bundler not available.' );
		}

		$bundler  = new WCPG_Context_Bundler();
		$bundle   = $bundler->build();
		$renderer = class_exists( 'WCPG_Report_Renderer' ) ? new WCPG_Report_Renderer() : null;
		$markdown = $renderer ? $renderer->render( $bundle ) : '';
		$json     = wp_json_encode( $bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		$host     = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'site';
		$host     = preg_replace( '/[^A-Za-z0-9.\-]/', '_', (string) $host );
		$filename = sprintf( 'digipay-diagnostic-%s-%s.zip', $host, gmdate( 'Ymd-His' ) );

		$zip_path = $this->write_zip( $json, $markdown );
		if ( ! $zip_path ) {
			wp_die( 'Failed to build diagnostic report zip.' );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $zip_path ) );
		readfile( $zip_path );
		@unlink( $zip_path );
		exit;
	}

	/**
	 * Build a zip file containing report.json and report.md.
	 *
	 * @param string $json     JSON payload.
	 * @param string $markdown Markdown payload.
	 * @return string|false Temp file path or false on failure.
	 */
	protected function write_zip( $json, $markdown ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}
		$tmp = wp_tempnam( 'wcpg-support' );
		if ( ! $tmp ) {
			return false;
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE ) ) {
			return false;
		}
		$zip->addFromString( 'report.json', (string) $json );
		$zip->addFromString( 'report.md', (string) $markdown );
		$zip->close();
		return $tmp;
	}

	/**
	 * Build a human-friendly summary for the admin page header.
	 *
	 * @return array
	 */
	protected function build_summary() {
		$plugin_version = defined( 'WCPG_VERSION' ) ? WCPG_VERSION : 'unknown';

		$pb_stats = get_option( 'wcpg_postback_stats', array() );
		$pb_txt   = is_array( $pb_stats )
			? 'success=' . (int) ( $pb_stats['success_count'] ?? 0 ) . ' errors=' . (int) ( $pb_stats['error_count'] ?? 0 )
			: 'no data';

		$health = class_exists( 'WCPG_ETransfer_Webhook_Handler' )
			? WCPG_ETransfer_Webhook_Handler::get_health_counters()
			: array();
		$health_txt = empty( $health )
			? 'no events yet'
			: implode( ' ', array_map( function ( $k, $v ) { return $k . '=' . (int) $v; }, array_keys( $health ), $health ) );

		$api = get_option( 'wcpg_api_last_test', array() );
		$api_txt = ( is_array( $api ) && ! empty( $api['time'] ) )
			? $api['time'] . ' (' . ( ! empty( $api['success'] ) ? 'ok' : 'fail' ) . ')'
			: 'never run';

		return array(
			'plugin_version' => $plugin_version,
			'postback_stats' => $pb_txt,
			'webhook_health' => $health_txt,
			'api_last_test'  => $api_txt,
		);
	}
}


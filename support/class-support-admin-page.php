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

	const NONCE_DIAGNOSE_ACTION = 'wcpg_support_diagnose';
	const NONCE_DIAGNOSE_NAME   = 'wcpg_diagnose_nonce';

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
		add_action( 'admin_post_wcpg_support_diagnose', array( $this, 'handle_diagnose' ) );
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
		$tiles   = $this->build_status_tiles();
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

			<h2>Diagnose My Site</h2>
			<p>Run all diagnostic checks at once and get a plain-English summary of any problems.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wcpg_support_diagnose" />
				<?php wp_nonce_field( self::NONCE_DIAGNOSE_ACTION, self::NONCE_DIAGNOSE_NAME ); ?>
				<?php submit_button( 'Diagnose My Site', 'primary', 'submit', false ); ?>
			</form>

			<?php $this->render_diagnose_results(); ?>

			<h2>Current Status</h2>
			<p class="description">Plugin version: <?php echo esc_html( $summary['plugin_version'] ); ?></p>
			<div class="wcpg-status-grid">
				<?php foreach ( $tiles as $tile ) : ?>
				<div class="wcpg-status-tile wcpg-status-tile-<?php echo esc_attr( $tile['status'] ); ?>">
					<div class="wcpg-status-bar"></div>
					<div class="wcpg-status-label"><?php echo esc_html( $tile['label'] ); ?></div>
					<div class="wcpg-status-headline"><?php echo esc_html( $tile['headline'] ); ?></div>
					<div class="wcpg-status-detail"><?php echo esc_html( $tile['detail'] ); ?></div>
				</div>
				<?php endforeach; ?>
			</div>

			<h2>Run a Diagnostic</h2>
			<p>
				Use these tools to verify your site is properly configured and reachable. Results are stored
				locally and included in the diagnostic report you can download below.
			</p>
			<details>
				<summary><strong>Advanced: Run individual tests</strong></summary>
				<div style="background:#fff; border:1px solid #ccd0d4; border-left:4px solid #2271b1; padding:15px 20px; margin:12px 0 24px; box-shadow:0 1px 1px rgba(0,0,0,.04);">
					<?php
					if ( function_exists( 'wcpg_render_diagnostics_content' ) ) {
						wcpg_render_diagnostics_content( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
					} else {
						echo '<p>Diagnostic tools are unavailable. Please reinstall the plugin.</p>';
					}
					?>
				</div>
			</details>

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
	 * Render diagnostic results panel if available.
	 *
	 * Reads the results transient (set by handle_diagnose()), renders a
	 * plain-English summary, then deletes the transient so refreshing does not
	 * re-show stale data.
	 */
	protected function render_diagnose_results() {
		// Only show results when redirected back after running the diagnose action.
		if ( ! isset( $_GET['diagnose'] ) || 'done' !== $_GET['diagnose'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$data = get_transient( 'wcpg_last_diagnose_results' );
		if ( ! is_array( $data ) ) {
			return;
		}

		// Delete immediately so a page refresh does not re-show stale results.
		delete_transient( 'wcpg_last_diagnose_results' );

		$issues    = isset( $data['issues'] ) && is_array( $data['issues'] ) ? $data['issues'] : array();
		$timestamp = isset( $data['timestamp'] ) ? $data['timestamp'] : '';

		echo '<div class="wcpg-diagnose-results" style="margin:16px 0;">';
		echo '<h3>' . esc_html__( 'Diagnostic Results', 'wc-payment-gateway' ) . '</h3>';
		if ( $timestamp ) {
			echo '<p class="description">' . esc_html( sprintf( __( 'Run at: %s', 'wc-payment-gateway' ), $timestamp ) ) . '</p>';
		}

		if ( empty( $issues ) ) {
			echo '<div class="notice notice-success inline"><p><strong>' . esc_html__( 'Your site is healthy ✓', 'wc-payment-gateway' ) . '</strong> ' . esc_html__( 'No issues detected.', 'wc-payment-gateway' ) . '</p></div>';
		} else {
			$count = count( $issues );
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html( sprintf( _n( '%d issue found:', '%d issues found:', $count, 'wc-payment-gateway' ), $count ) ) . '</strong></p></div>';

			foreach ( $issues as $issue ) {
				$id       = isset( $issue['id'] ) ? $issue['id'] : '';
				$title    = isset( $issue['title'] ) ? $issue['title'] : '';
				$plain    = isset( $issue['plain_english'] ) ? $issue['plain_english'] : '';
				$fix      = isset( $issue['fix'] ) ? $issue['fix'] : '';
				$severity = isset( $issue['severity'] ) ? $issue['severity'] : 'info';

				echo '<div class="wcpg-issue-card wcpg-issue-severity-' . esc_attr( $severity ) . '">';
				echo '<span class="wcpg-issue-badge wcpg-issue-severity-' . esc_attr( $severity ) . '">' . esc_html( $id ) . '</span> ';
				echo '<strong>' . esc_html( $title ) . '</strong>';
				if ( $plain ) {
					echo '<p>' . esc_html( $plain ) . '</p>';
				}
				if ( $fix ) {
					echo '<p><em>' . esc_html__( 'Fix:', 'wc-payment-gateway' ) . '</em> ' . esc_html( $fix ) . '</p>';
				}
				echo '</div>';
			}
		}

		echo '</div>';
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
	 * Handle the "Diagnose My Site" POST: run all diagnostics, detect issues, stash results.
	 */
	public function handle_diagnose() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wc-payment-gateway' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_DIAGNOSE_ACTION, self::NONCE_DIAGNOSE_NAME );

		// Run all individual diagnostic functions if available, each in its own try/catch.
		$run = function ( $fn ) {
			if ( function_exists( $fn ) ) {
				try {
					$fn();
				} catch ( \Throwable $e ) {
					error_log( 'WCPG handle_diagnose: ' . $fn . ' threw: ' . $e->getMessage() );
				}
			}
		};
		$run( 'wcpg_run_diagnostics' );
		$run( 'wcpg_test_api_connection' );
		$run( 'wcpg_test_inbound_connectivity' );
		$run( 'wcpg_report_health' );

		// Build the context bundle and detect issues. Any failure here falls back gracefully.
		$bundle      = array();
		$matched     = array();
		$bundle_meta = array();
		try {
			if ( class_exists( 'WCPG_Context_Bundler' ) ) {
				$bundler = new WCPG_Context_Bundler();
				$bundle  = $bundler->build();
			}

			if ( class_exists( 'WCPG_Issue_Catalog' ) ) {
				$matched = WCPG_Issue_Catalog::detect_all( $bundle );
			}

			$bundle_meta = isset( $bundle['bundle_meta'] ) ? $bundle['bundle_meta'] : array();
		} catch ( \Throwable $e ) {
			error_log( 'WCPG handle_diagnose: bundler/issue-detection threw: ' . $e->getMessage() );
			$bundle_meta = array( 'error' => $e->getMessage() );
		}

		set_transient(
			'wcpg_last_diagnose_results',
			array(
				'timestamp'   => gmdate( 'c' ),
				'issues'      => $matched,
				'bundle_meta' => $bundle_meta,
			),
			5 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&diagnose=done' ) );
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
	 * Build 4 status tiles for the Current Status grid.
	 *
	 * Each tile is an array with keys: key, label, status, headline, detail.
	 * Status is one of: green, yellow, red, gray.
	 *
	 * @return array[]
	 */
	protected function build_status_tiles() {
		return array(
			$this->build_postbacks_tile(),
			$this->build_webhook_tile(),
			$this->build_api_tile(),
			$this->build_orders_tile(),
		);
	}

	/**
	 * Build the Postbacks status tile.
	 *
	 * @return array
	 */
	private function build_postbacks_tile() {
		$pb_stats     = get_option( 'wcpg_postback_stats', array() );
		$success      = (int) ( isset( $pb_stats['success_count'] ) ? $pb_stats['success_count'] : 0 );
		$error        = (int) ( isset( $pb_stats['error_count'] ) ? $pb_stats['error_count'] : 0 );
		$total        = $success + $error;

		if ( 0 === $total ) {
			return array(
				'key'      => 'postbacks',
				'label'    => 'Postbacks',
				'status'   => 'gray',
				'headline' => 'Unknown',
				'detail'   => 'No transactions yet',
			);
		}

		$error_rate = $error / $total;

		if ( $error_rate < 0.05 ) {
			return array(
				'key'      => 'postbacks',
				'label'    => 'Postbacks',
				'status'   => 'green',
				'headline' => 'Healthy',
				'detail'   => "{$success}/{$total} successful",
			);
		}

		if ( $error_rate <= 0.20 ) {
			return array(
				'key'      => 'postbacks',
				'label'    => 'Postbacks',
				'status'   => 'yellow',
				'headline' => 'Degraded',
				'detail'   => "{$error}/{$total} failed",
			);
		}

		return array(
			'key'      => 'postbacks',
			'label'    => 'Postbacks',
			'status'   => 'red',
			'headline' => 'Failing',
			'detail'   => "{$error}/{$total} failed — high error rate",
		);
	}

	/**
	 * Build the Webhook status tile.
	 *
	 * @return array
	 */
	private function build_webhook_tile() {
		$counters = class_exists( 'WCPG_ETransfer_Webhook_Handler' )
			? WCPG_ETransfer_Webhook_Handler::get_health_counters()
			: array();

		$total = array_sum( array_map( 'intval', $counters ) );

		if ( 0 === $total ) {
			return array(
				'key'      => 'webhook',
				'label'    => 'E-Transfer Webhook',
				'status'   => 'gray',
				'headline' => 'Unknown',
				'detail'   => 'No webhook events recorded',
			);
		}

		$hmac_fail_count       = (int) ( isset( $counters['hmac_fail'] ) ? $counters['hmac_fail'] : 0 );
		$timestamp_reject_count = (int) ( isset( $counters['timestamp_reject'] ) ? $counters['timestamp_reject'] : 0 );
		$fail_count            = $hmac_fail_count + $timestamp_reject_count;
		$processed             = (int) ( isset( $counters['processed'] ) ? $counters['processed'] : 0 );

		if ( 0 === $fail_count ) {
			return array(
				'key'      => 'webhook',
				'label'    => 'E-Transfer Webhook',
				'status'   => 'green',
				'headline' => 'Healthy',
				'detail'   => "{$processed} processed",
			);
		}

		if ( $fail_count <= 5 ) {
			return array(
				'key'      => 'webhook',
				'label'    => 'E-Transfer Webhook',
				'status'   => 'yellow',
				'headline' => 'Degraded',
				'detail'   => "{$fail_count} signature/timestamp failures",
			);
		}

		return array(
			'key'      => 'webhook',
			'label'    => 'E-Transfer Webhook',
			'status'   => 'red',
			'headline' => 'Failing',
			'detail'   => "{$fail_count} signature/timestamp failures in 24h",
		);
	}

	/**
	 * Build the API status tile.
	 *
	 * @return array
	 */
	private function build_api_tile() {
		$api = get_option( 'wcpg_api_last_test', array() );

		if ( ! is_array( $api ) || empty( $api['time'] ) || ! isset( $api['success'] ) ) {
			return array(
				'key'      => 'api',
				'label'    => 'API Connectivity',
				'status'   => 'gray',
				'headline' => 'Unknown',
				'detail'   => 'No API test run yet',
			);
		}

		$time = $api['time'];

		if ( true === $api['success'] ) {
			$ms = isset( $api['response_time_ms'] ) ? (int) $api['response_time_ms'] : 0;

			if ( $ms < 1000 ) {
				return array(
					'key'      => 'api',
					'label'    => 'API Connectivity',
					'status'   => 'green',
					'headline' => 'Healthy',
					'detail'   => "{$ms}ms response, last test {$time}",
				);
			}

			return array(
				'key'      => 'api',
				'label'    => 'API Connectivity',
				'status'   => 'yellow',
				'headline' => 'Slow',
				'detail'   => "{$ms}ms response (slow), last test {$time}",
			);
		}

		return array(
			'key'      => 'api',
			'label'    => 'API Connectivity',
			'status'   => 'red',
			'headline' => 'Failing',
			'detail'   => "Last test failed at {$time}",
		);
	}

	/**
	 * Build the Orders status tile.
	 *
	 * Counts failed/on-hold/pending orders for the 3 gateway IDs in the last 7 days.
	 * Falls back to 0 if wc_get_orders is unavailable.
	 *
	 * @return array
	 */
	private function build_orders_tile() {
		$count = 0;

		try {
			if ( function_exists( 'wc_get_orders' ) ) {
				$orders = wc_get_orders(
					array(
						'payment_method' => array( 'paygobillingcc', 'digipay_etransfer', 'wcpg_crypto' ),
						'status'         => array( 'wc-failed', 'wc-on-hold', 'wc-pending' ),
						'date_after'     => gmdate( 'Y-m-d', strtotime( '-7 days' ) ),
						'return'         => 'ids',
						'limit'          => 100,
					)
				);
				$count = is_array( $orders ) ? count( $orders ) : 0;
			}
		} catch ( \Throwable $e ) {
			$count = 0;
		}

		if ( 0 === $count ) {
			return array(
				'key'      => 'orders',
				'label'    => 'Recent Orders',
				'status'   => 'green',
				'headline' => 'Healthy',
				'detail'   => 'No stuck orders in the last 7 days',
			);
		}

		if ( $count <= 3 ) {
			return array(
				'key'      => 'orders',
				'label'    => 'Recent Orders',
				'status'   => 'yellow',
				'headline' => 'Some attention needed',
				'detail'   => "{$count} stuck orders in the last 7 days",
			);
		}

		return array(
			'key'      => 'orders',
			'label'    => 'Recent Orders',
			'status'   => 'red',
			'headline' => 'Needs attention',
			'detail'   => "{$count} stuck orders in the last 7 days",
		);
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


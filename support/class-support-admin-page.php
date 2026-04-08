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

	const NONCE_MAINTENANCE_ACTION = 'wcpg_support_maintenance';
	const NONCE_MAINTENANCE_NAME   = 'wcpg_maintenance_nonce';

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
		add_action( 'admin_post_wcpg_support_maintenance', array( $this, 'handle_maintenance' ) );
		add_action( 'admin_post_wcpg_support_autoupload_toggle', array( $this, 'handle_autoupload_toggle' ) );
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
		if ( function_exists( 'wp_enqueue_script' ) ) {
			wp_enqueue_script(
				'wcpg-support-log-tail',
				plugin_dir_url( WCPG_PLUGIN_FILE ) . 'assets/js/support-log-tail.js',
				array(),
				defined( 'WCPG_VERSION' ) ? WCPG_VERSION : '1.0.0',
				true
			);
			wp_localize_script(
				'wcpg-support-log-tail',
				'wcpgLogTail',
				array(
					'restUrl'   => function_exists( 'rest_url' ) ? rest_url( 'digipay/v1/support/log-tail' ) : '/wp-json/digipay/v1/support/log-tail',
					'restNonce' => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '',
				)
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
			<?php $this->render_maintenance_notice(); ?>
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
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wcpg-generate-form">
				<input type="hidden" name="action" value="wcpg_support_generate" />
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<?php submit_button( 'Generate Diagnostic Report', 'primary', 'submit', false ); ?>
			</form>
			<button type="button" class="button" id="wcpg-email-support" style="margin-top:8px;">Email to Digipay Support</button>

			<details style="margin-top:32px;">
				<summary><strong>Auto-Upload on Critical Issues</strong></summary>
				<div style="background:#fff; border:1px solid #ccd0d4; border-left:4px solid #2271b1; padding:15px 20px; margin:12px 0 24px;">
					<p>If enabled, the plugin will automatically send diagnostic bundles to Digipay support when it detects a critical problem (e.g., many webhook signature failures). Digipay uses this for faster triage.</p>
					<?php if ( isset( $_GET['autoupload'] ) && 'saved' === $_GET['autoupload'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<div class="notice notice-success inline"><p><?php esc_html_e( 'Auto-upload setting saved.', 'wc-payment-gateway' ); ?></p></div>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="wcpg_support_autoupload_toggle" />
						<?php wp_nonce_field( 'wcpg_support_autoupload', 'wcpg_autoupload_nonce' ); ?>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked( (bool) get_option( WCPG_Auto_Uploader::OPTION_ENABLED, false ) ); ?> />
							<?php esc_html_e( 'Enable auto-upload', 'wc-payment-gateway' ); ?>
						</label>
						<?php submit_button( 'Save', 'secondary', 'submit', false ); ?>
					</form>
				</div>
			</details>

			<h2>Live Log Tail</h2>
			<details id="wcpg-log-tail-details">
				<summary><strong>Show live log tail (auto-refreshes every 5 seconds)</strong></summary>
				<div id="wcpg-log-tail-output" style="background:#1e1e1e; color:#d4d4d4; padding:12px; font-family:monospace; font-size:11px; max-height:400px; overflow:auto; margin-top:10px; border-radius:4px;">
					<p style="color:#888;">Opening panel to start polling...</p>
				</div>
			</details>

			<details style="margin-top:32px;">
				<summary><strong>Maintenance</strong></summary>
				<div style="background:#fff; border:1px solid #ccd0d4; border-left:4px solid #d63638; padding:15px 20px; margin:12px 0 24px;">
					<p>These actions clear cached data and stats. They cannot be undone. Use only if Digipay support has asked you to.</p>

					<p style="margin:8px 0;">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Are you sure you want to reset postback stats?');" style="display:inline;">
							<input type="hidden" name="action" value="wcpg_support_maintenance" />
							<input type="hidden" name="wcpg_maintenance_op" value="reset_postback_stats" />
							<?php wp_nonce_field( self::NONCE_MAINTENANCE_ACTION, self::NONCE_MAINTENANCE_NAME ); ?>
							<button type="submit" class="button">Reset Postback Stats</button>
						</form>
					</p>

					<p style="margin:8px 0;">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Are you sure you want to clear rate limit transients?');" style="display:inline;">
							<input type="hidden" name="action" value="wcpg_support_maintenance" />
							<input type="hidden" name="wcpg_maintenance_op" value="clear_rate_limit_transients" />
							<?php wp_nonce_field( self::NONCE_MAINTENANCE_ACTION, self::NONCE_MAINTENANCE_NAME ); ?>
							<button type="submit" class="button">Clear Rate Limit Transients</button>
						</form>
					</p>

					<p style="margin:8px 0;">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Are you sure you want to clear the webhook dedup cache?');" style="display:inline;">
							<input type="hidden" name="action" value="wcpg_support_maintenance" />
							<input type="hidden" name="wcpg_maintenance_op" value="clear_webhook_dedup_cache" />
							<?php wp_nonce_field( self::NONCE_MAINTENANCE_ACTION, self::NONCE_MAINTENANCE_NAME ); ?>
							<button type="submit" class="button">Clear Webhook Dedup Cache</button>
						</form>
					</p>

					<p style="margin:8px 0;">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Are you sure you want to clear the event log? This cannot be undone.');" style="display:inline;">
							<input type="hidden" name="action" value="wcpg_support_maintenance" />
							<input type="hidden" name="wcpg_maintenance_op" value="clear_event_log" />
							<?php wp_nonce_field( self::NONCE_MAINTENANCE_ACTION, self::NONCE_MAINTENANCE_NAME ); ?>
							<button type="submit" class="button">Clear Event Log</button>
						</form>
					</p>

					<p style="margin:8px 0;">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Are you sure you want to force-refresh remote limits?');" style="display:inline;">
							<input type="hidden" name="action" value="wcpg_support_maintenance" />
							<input type="hidden" name="wcpg_maintenance_op" value="force_refresh_remote_limits" />
							<?php wp_nonce_field( self::NONCE_MAINTENANCE_ACTION, self::NONCE_MAINTENANCE_NAME ); ?>
							<button type="submit" class="button">Force Refresh Remote Limits</button>
						</form>
					</p>
				</div>
			</details>

			<script>
			(function () {
				var siteUrl  = <?php echo wp_json_encode( home_url() ); ?>;
				var siteHost = <?php echo wp_json_encode( wp_parse_url( home_url(), PHP_URL_HOST ) ?: '' ); ?>;
				var btn      = document.getElementById( 'wcpg-email-support' );
				var form     = document.getElementById( 'wcpg-generate-form' );
				if ( btn && form ) {
					btn.addEventListener( 'click', function () {
						form.submit();
						setTimeout( function () {
							var subject = encodeURIComponent( 'Digipay diagnostic ' + siteHost );
							var body    = encodeURIComponent(
								'Please find attached the diagnostic report.\n\nSite: ' + siteUrl
							);
							window.location.href = 'mailto:support@digipay.co?subject=' + subject + '&body=' + body;
						}, 800 );
					} );
				}
			}());
			</script>
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

		// Record a healthy baseline when zero issues are detected.
		if ( empty( $matched ) && class_exists( 'WCPG_Baseline' ) ) {
			WCPG_Baseline::record( $bundle );
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
	 * Render a notice if the page was redirected back from a maintenance action.
	 */
	protected function render_maintenance_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['maintenance'] ) || 'done' !== $_GET['maintenance'] ) {
			return;
		}

		$notice = get_transient( 'wcpg_maintenance_notice' );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( 'wcpg_maintenance_notice' );

		$status  = isset( $notice['status'] ) ? $notice['status'] : 'success';
		$message = isset( $notice['message'] ) ? $notice['message'] : '';
		$type    = ( 'error' === $status ) ? 'error' : 'success';

		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Handle the "Maintenance" admin-post action.
	 *
	 * Validates capability + nonce, executes the requested maintenance op,
	 * stores a flash notice, and redirects back to the support page.
	 */
	public function handle_maintenance() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to perform this action.', 'wc-payment-gateway' ),
				'',
				array( 'response' => 403 )
			);
		}
		check_admin_referer( self::NONCE_MAINTENANCE_ACTION, self::NONCE_MAINTENANCE_NAME );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
		$op      = isset( $_POST['wcpg_maintenance_op'] ) ? sanitize_text_field( wp_unslash( $_POST['wcpg_maintenance_op'] ) ) : '';
		$allowed = array(
			'reset_postback_stats',
			'clear_rate_limit_transients',
			'clear_webhook_dedup_cache',
			'clear_event_log',
			'force_refresh_remote_limits',
		);

		if ( ! in_array( $op, $allowed, true ) ) {
			wp_die(
				esc_html__( 'Invalid maintenance operation.', 'wc-payment-gateway' ),
				'',
				array( 'response' => 400 )
			);
		}

		$message = $this->execute_maintenance_op( $op );

		set_transient(
			'wcpg_maintenance_notice',
			array(
				'op'      => $op,
				'message' => $message,
				'status'  => 'success',
			),
			60
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&maintenance=done' ) );
		exit;
	}

	/**
	 * Handle the "Auto-Upload Toggle" admin-post action.
	 *
	 * Validates capability + nonce, saves the opt-in flag, and redirects back.
	 */
	public function handle_autoupload_toggle() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to perform this action.', 'wc-payment-gateway' ),
				'',
				array( 'response' => 403 )
			);
		}
		check_admin_referer( 'wcpg_support_autoupload', 'wcpg_autoupload_nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
		$enabled = ! empty( $_POST['enabled'] );
		update_option( WCPG_Auto_Uploader::OPTION_ENABLED, $enabled );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&autoupload=saved' ) );
		exit;
	}

	/**
	 * Execute a single maintenance op and return a human-readable result message.
	 *
	 * @param string $op One of the allowed maintenance op keys.
	 * @return string Result message.
	 */
	protected function execute_maintenance_op( $op ) {
		switch ( $op ) {
			case 'reset_postback_stats':
				delete_option( 'wcpg_postback_stats' );
				return 'Postback statistics have been reset.';

			case 'clear_rate_limit_transients':
				if ( function_exists( 'wp_cache_flush_group' ) ) {
					wp_cache_flush_group( 'wcpg_rate_limit' );
					return 'Rate limit cache group flushed.';
				}
				return 'Rate limit transients will expire within 60 seconds (wp_cache_flush_group not available on this WP version).';

			case 'clear_webhook_dedup_cache':
				if ( function_exists( 'wp_cache_flush_group' ) ) {
					wp_cache_flush_group( 'transient' );
					return 'Webhook dedup cache flushed.';
				}
				return 'Webhook dedup transients will expire within 5 minutes (wp_cache_flush_group not available on this WP version).';

			case 'clear_event_log':
				if ( class_exists( 'WCPG_Event_Log' ) ) {
					WCPG_Event_Log::clear();
					return 'Event log cleared.';
				}
				return 'Event log class not found; nothing to clear.';

			case 'force_refresh_remote_limits':
				$this->delete_remote_limits_transients();
				return 'Remote limits cache cleared. Fresh limits will be fetched on the next request.';

			default:
				return 'Unknown operation.';
		}
	}

	/**
	 * Delete the remote limits transients for all known site ID derivations.
	 *
	 * The transient keys are derived from md5(siteid) or md5(get_site_url()).
	 * We delete both the primary and last-known variants.
	 *
	 * @return void
	 */
	protected function delete_remote_limits_transients() {
		$candidates = array( get_site_url(), home_url() );

		// Also try the siteid stored in gateway settings.
		$settings = get_option( 'woocommerce_paygobillingcc_settings', array() );
		if ( is_array( $settings ) && ! empty( $settings['siteid'] ) ) {
			$candidates[] = $settings['siteid'];
		}

		foreach ( array_unique( $candidates ) as $seed ) {
			$hash = md5( (string) $seed );
			delete_transient( 'wcpg_remote_limits_' . $hash );
			delete_transient( 'wcpg_last_known_limits_' . $hash );
		}
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
		$count   = 0;
		$allowed = array( 'paygobillingcc', 'digipay_etransfer', 'wcpg_crypto' );

		try {
			if ( function_exists( 'wc_get_orders' ) ) {
				$orders = wc_get_orders(
					array(
						'payment_method' => $allowed,
						'status'         => array( 'wc-failed', 'wc-on-hold', 'wc-pending' ),
						'date_after'     => gmdate( 'Y-m-d', strtotime( '-7 days' ) ),
						'return'         => 'objects',
						'limit'          => 100,
					)
				);
				// Post-filter in PHP as a belt-and-braces measure: some WooCommerce
				// versions do not honour the payment_method arg in wc_get_orders().
				foreach ( (array) $orders as $o ) {
					if ( is_object( $o ) && method_exists( $o, 'get_payment_method' )
						&& in_array( $o->get_payment_method(), $allowed, true ) ) {
						$count++;
					}
				}
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


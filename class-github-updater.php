<?php
/**
 * GitHub Plugin Updater
 * 
 * Automatically checks GitHub releases for plugin updates and integrates
 * with WordPress's native update system.
 * 
 * @version 1.0.0
 */

if (!defined('ABSPATH')) exit;

class WCPG_GitHub_Updater {

    const SIGNING_PUBLIC_KEY = 'M6h+YphsS93ZbFN2m/LzmfElgcR4c52DkXJztb1+2z4=';

    private $plugin_file;
    private $plugin_slug;
    private $plugin_basename;
    private $version;
    private $github_username;
    private $github_repo;
    private $github_token;
    private $cache_key;
    private $cache_expiry = 12 * HOUR_IN_SECONDS;
    private $github_response = null;

    /**
     * Initialize the updater
     *
     * @param string $plugin_file    Full path to main plugin file
     * @param string $github_username GitHub username or organization
     * @param string $github_repo    Repository name
     * @param string $version        Current plugin version
     * @param string $github_token   GitHub personal access token (required for private repos)
     */
    public function __construct($plugin_file, $github_username, $github_repo, $version, $github_token = '') {
        $this->plugin_file = $plugin_file;
        $this->plugin_basename = plugin_basename($plugin_file);
        $this->plugin_slug = dirname($this->plugin_basename);
        $this->version = $version;
        $this->github_username = $github_username;
        $this->github_repo = $github_repo;
        $this->github_token = $github_token;
        $this->cache_key = 'wcpg_github_update_' . md5($this->plugin_basename);

        // Hook into WordPress update system
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);
        add_filter('upgrader_pre_download', [$this, 'verify_signed_download'], 10, 3);
        
        // Clear cache on upgrade completion
        add_action('upgrader_process_complete', [$this, 'clear_cache'], 10, 2);
        
        // Add "Check for updates" link on plugins page
        add_filter('plugin_action_links_' . $this->plugin_basename, [$this, 'add_check_update_link']);
        
        // Handle manual update check
        add_action('admin_init', [$this, 'handle_manual_check']);

        // Inject auth header when downloading from GitHub (private repos)
        if (!empty($this->github_token) && $this->github_token !== 'YOUR_TOKEN_HERE') {
            add_filter('http_request_args', [$this, 'inject_download_auth'], 10, 2);
        }
    }

    /**
     * Add "Check for updates" link to plugin actions
     */
    public function add_check_update_link($links) {
        $check_link = '<a href="' . wp_nonce_url(
            admin_url('plugins.php?wcpg_check_update=1'),
            'wcpg_check_update'
        ) . '">Check for updates</a>';
        
        array_unshift($links, $check_link);
        return $links;
    }

    /**
     * Handle manual update check
     */
    public function handle_manual_check() {
        if (!isset($_GET['wcpg_check_update'])) {
            return;
        }
        
        if (!wp_verify_nonce($_GET['_wpnonce'], 'wcpg_check_update')) {
            return;
        }

        if ( ! current_user_can( 'update_plugins' ) ) {
            return;
        }

        // Clear cached data
        delete_transient($this->cache_key);
        
        // Force refresh
        delete_site_transient('update_plugins');
        wp_update_plugins();
        
        // Redirect with message
        wp_redirect(admin_url('plugins.php?wcpg_checked=1'));
        exit;
    }

    /**
     * Get repository info from GitHub API
     */
    private function get_github_release_info() {
        // Check cache first
        $cached = get_transient($this->cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // Fetch latest release from GitHub
        $url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            $this->github_username,
            $this->github_repo
        );

        $headers = [
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
        ];

        if (!empty($this->github_token) && $this->github_token !== 'YOUR_TOKEN_HERE') {
            $headers['Authorization'] = 'Bearer ' . $this->github_token;
        }

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => $headers,
        ]);

        if (is_wp_error($response)) {
            error_log('WCPG Updater: GitHub API error - ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log('WCPG Updater: GitHub API returned ' . $code);
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response));
        
        if (empty($body)) {
            return false;
        }

        // Find the ZIP asset and its .sig counterpart
        $download_url  = '';
        $signature_url = '';
        $zip_name      = '';
        if (!empty($body->assets)) {
            foreach ($body->assets as $asset) {
                if (substr($asset->name, -4) === '.zip') {
                    $download_url = $asset->browser_download_url;
                    $zip_name     = $asset->name;
                }
            }
            if (!empty($zip_name)) {
                $sig_name = $zip_name . '.sig';
                foreach ($body->assets as $asset) {
                    if ($asset->name === $sig_name) {
                        $signature_url = $asset->browser_download_url;
                        break;
                    }
                }
            }
        }

        // Fallback to zipball if no asset found
        if (empty($download_url)) {
            $download_url = $body->zipball_url;
        }

        // Parse version from tag (remove 'v' prefix if present)
        $version = ltrim($body->tag_name, 'v');

        $release_info = (object) [
            'version'       => $version,
            'download_url'  => $download_url,
            'signature_url' => $signature_url,
            'changelog'     => $body->body ?? '',
            'published_at'  => $body->published_at ?? '',
            'html_url'      => $body->html_url ?? '',
        ];

        // Cache for 12 hours
        set_transient($this->cache_key, $release_info, $this->cache_expiry);

        return $release_info;
    }

    /**
     * Check for plugin updates
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release_info = $this->get_github_release_info();

        if (!$release_info) {
            return $transient;
        }

        // Compare versions
        if (version_compare($this->version, $release_info->version, '<')) {
            $transient->response[$this->plugin_basename] = (object) [
                'slug'        => $this->plugin_slug,
                'plugin'      => $this->plugin_basename,
                'new_version' => $release_info->version,
                'package'     => $release_info->download_url,
                'url'         => $release_info->html_url,
                'tested'      => '',
                'requires_php'=> '7.4',
            ];
        } else {
            // Plugin is up to date - add to no_update
            $transient->no_update[$this->plugin_basename] = (object) [
                'slug'        => $this->plugin_slug,
                'plugin'      => $this->plugin_basename,
                'new_version' => $this->version,
                'url'         => '',
                'package'     => '',
            ];
        }

        return $transient;
    }

    /**
     * Provide plugin information for "View Details" popup
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result;
        }

        $release_info = $this->get_github_release_info();

        if (!$release_info) {
            return $result;
        }

        // Parse changelog from markdown
        $changelog = $this->parse_changelog($release_info->changelog);

        return (object) [
            'name'          => 'WooCommerce Payment Gateway',
            'slug'          => $this->plugin_slug,
            'version'       => $release_info->version,
            'author'        => '<a href="https://github.com/' . $this->github_username . '">Payment Gateway</a>',
            'homepage'      => 'https://github.com/' . $this->github_username . '/' . $this->github_repo,
            'download_link' => $release_info->download_url,
            'requires'      => '5.0',
            'tested'        => '6.4',
            'requires_php'  => '7.4',
            'last_updated'  => $release_info->published_at,
            'sections'      => [
                'description' => 'Configurable payment gateway for WooCommerce with automatic updates.',
                'changelog'   => $changelog,
            ],
            'banners'       => [],
        ];
    }

    /**
     * Parse markdown changelog to HTML
     */
    private function parse_changelog($markdown) {
        if (empty($markdown)) {
            return '<p>No changelog available.</p>';
        }

        // Basic markdown to HTML conversion
        $html = esc_html($markdown);
        $html = nl2br($html);
        
        // Convert markdown headers
        $html = preg_replace('/^### (.+)$/m', '<h4>$1</h4>', $html);
        $html = preg_replace('/^## (.+)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^# (.+)$/m', '<h2>$1</h2>', $html);
        
        // Convert markdown lists
        $html = preg_replace('/^- (.+)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.+<\/li>\s*)+/', '<ul>$0</ul>', $html);
        
        // Convert bold/italic
        $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
        $html = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $html);

        return $html;
    }

    /**
     * Verify the Ed25519 signature of a downloaded release ZIP.
     *
     * Hooked to `upgrader_pre_download`. Returns the verified temp file path
     * on success, or WP_Error on failure (which blocks the update).
     *
     * @param bool|WP_Error $reply    Whether to short-circuit. False to proceed.
     * @param string        $package  The package URL being downloaded.
     * @param WP_Upgrader   $upgrader The upgrader instance.
     * @return bool|string|WP_Error   Temp file path on success, WP_Error on failure,
     *                                or false to let WordPress handle unrelated downloads.
     */
    public function verify_signed_download($reply, $package, $upgrader) {
        // Only intercept downloads from our GitHub repo.
        $repo_url = 'github.com/' . $this->github_username . '/' . $this->github_repo . '/';
        if (strpos($package, $repo_url) === false) {
            return $reply;
        }

        // Block zipball URLs — they have no signature.
        if (strpos($package, '/zipball/') !== false) {
            return new WP_Error(
                'wcpg_unsigned_zipball',
                'Update blocked: GitHub zipball downloads are not signed. Upload a signed release asset instead.'
            );
        }

        // Get the cached release info for the signature URL.
        $release_info = $this->get_github_release_info();
        if (!$release_info || empty($release_info->signature_url)) {
            return new WP_Error(
                'wcpg_no_signature',
                'Update blocked: no .sig file found in the GitHub release. Upload the signed .zip.sig alongside the .zip.'
            );
        }

        // Download the ZIP.
        $zip_tmpfile = download_url($package);
        if (is_wp_error($zip_tmpfile)) {
            return $zip_tmpfile;
        }

        // Download the .sig file.
        $sig_tmpfile = download_url($release_info->signature_url);
        if (is_wp_error($sig_tmpfile)) {
            @unlink($zip_tmpfile);
            return new WP_Error(
                'wcpg_sig_download_failed',
                'Update blocked: could not download signature file. ' . $sig_tmpfile->get_error_message()
            );
        }

        // Read and validate signature.
        $sig_b64 = trim(file_get_contents($sig_tmpfile));
        @unlink($sig_tmpfile);

        $signature = base64_decode($sig_b64, true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            @unlink($zip_tmpfile);
            return new WP_Error(
                'wcpg_sig_invalid',
                'Update blocked: signature file is malformed (expected 64-byte Ed25519 signature).'
            );
        }

        // Read the ZIP contents for verification.
        $zip_contents = file_get_contents($zip_tmpfile);

        // Decode the hardcoded public key.
        $public_key = base64_decode(self::SIGNING_PUBLIC_KEY, true);
        if ($public_key === false || strlen($public_key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            @unlink($zip_tmpfile);
            return new WP_Error(
                'wcpg_key_invalid',
                'Update blocked: hardcoded public key is invalid. Contact the plugin developer.'
            );
        }

        // Verify the signature.
        $valid = sodium_crypto_sign_verify_detached($signature, $zip_contents, $public_key);

        if (!$valid) {
            @unlink($zip_tmpfile);
            return new WP_Error(
                'wcpg_sig_mismatch',
                'Update blocked: signature verification failed. The release ZIP may have been tampered with.'
            );
        }

        // Signature valid — return the temp file so WordPress uses it.
        return $zip_tmpfile;
    }

    /**
     * After install - ensure directory name is correct
     */
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;

        // Only process our plugin
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename) {
            return $result;
        }

        // Get the correct install directory
        $proper_destination = WP_PLUGIN_DIR . '/' . $this->plugin_slug;
        
        // If the destination is already correct, we're done
        if ($result['destination'] === $proper_destination . '/') {
            return $result;
        }

        // Remove the existing plugin directory if it exists
        if ($wp_filesystem->exists($proper_destination)) {
            $wp_filesystem->delete($proper_destination, true);
        }

        // Move the new version to the correct location
        $wp_filesystem->move($result['destination'], $proper_destination);
        $result['destination'] = $proper_destination . '/';
        $result['destination_name'] = $this->plugin_slug;

        return $result;
    }

    /**
     * Clear cache after update completes
     */
    public function clear_cache($upgrader, $options) {
        if ($options['action'] === 'update' && $options['type'] === 'plugin') {
            delete_transient($this->cache_key);
        }
    }

    /**
     * Inject Authorization header for GitHub download requests (private repos)
     */
    public function inject_download_auth($args, $url) {
        if (empty($this->github_token)) {
            return $args;
        }

        // Only add auth for GitHub URLs related to this repo
        $github_host = 'github.com/' . $this->github_username . '/' . $this->github_repo . '/';
        $api_host    = 'api.github.com/repos/' . $this->github_username . '/' . $this->github_repo . '/';

        if (strpos($url, $github_host) === false && strpos($url, $api_host) === false) {
            return $args;
        }

        $args['headers']['Authorization'] = 'Bearer ' . $this->github_token;

        return $args;
    }
}

/**
 * Display admin notice after manual update check
 */
add_action('admin_notices', function() {
    if (isset($_GET['wcpg_checked'])) {
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p><strong>Payment Gateway:</strong> Update check complete. ';
        echo 'If an update is available, you\'ll see it below.</p>';
        echo '</div>';
    }
});

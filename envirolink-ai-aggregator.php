<?php
/**
 * Plugin Name: EnviroLink AI News Aggregator
 * Plugin URI: https://envirolink.org
 * Description: Automatically fetches environmental news from RSS feeds, rewrites content using AI, and publishes to WordPress
 * Version: 1.47.0
 * Author: EnviroLink
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ENVIROLINK_VERSION', '1.47.0');
define('ENVIROLINK_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ENVIROLINK_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load Plugin Update Checker library
require ENVIROLINK_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// Initialize update checker (global variable for access in AJAX handlers)
global $envirolink_update_checker;
$envirolink_update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/jknauernever/envirolink-news/',
    __FILE__,
    'envirolink-ai-aggregator'
);

// Set the branch to check for updates (optional, defaults to 'main')
$envirolink_update_checker->setBranch('main');

// Enable GitHub releases for version tracking
$envirolink_update_checker->getVcsApi()->enableReleaseAssets();

/**
 * Main plugin class
 */
class EnviroLink_AI_Aggregator {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Cron hooks
        add_action('envirolink_fetch_feeds', array($this, 'fetch_and_process_feeds'));
        add_action('envirolink_daily_roundup', array($this, 'generate_daily_roundup'));

        // Frontend styling for Unsplash attribution captions
        add_action('wp_head', array($this, 'output_caption_css'));

        // AJAX handlers
        add_action('wp_ajax_envirolink_run_now', array($this, 'ajax_run_now'));
        add_action('wp_ajax_envirolink_run_feed', array($this, 'ajax_run_feed'));
        add_action('wp_ajax_envirolink_get_progress', array($this, 'ajax_get_progress'));
        add_action('wp_ajax_envirolink_get_saved_log', array($this, 'ajax_get_saved_log'));
        add_action('wp_ajax_envirolink_update_feed_images', array($this, 'ajax_update_feed_images'));
        add_action('wp_ajax_envirolink_fix_post_dates', array($this, 'ajax_fix_post_dates'));
        add_action('wp_ajax_envirolink_cleanup_duplicates', array($this, 'ajax_cleanup_duplicates'));
        add_action('wp_ajax_envirolink_check_updates', array($this, 'ajax_check_updates'));
        add_action('wp_ajax_envirolink_generate_roundup', array($this, 'ajax_generate_roundup'));
        add_action('wp_ajax_envirolink_categorize_posts', array($this, 'ajax_categorize_posts'));
        add_action('wp_ajax_envirolink_update_authors', array($this, 'ajax_update_authors'));

        // Ontology management AJAX handlers
        add_action('wp_ajax_envirolink_seed_ontology', array($this, 'ajax_seed_ontology'));
        add_action('wp_ajax_envirolink_clear_ontology', array($this, 'ajax_clear_ontology'));
        add_action('wp_ajax_envirolink_retag_posts', array($this, 'ajax_retag_posts'));

        // Public AJAX endpoint for system cron (no authentication required, uses secret key)
        add_action('wp_ajax_nopriv_envirolink_cron_roundup', array($this, 'ajax_cron_roundup'));

        // Post ordering - randomize within same day
        if (get_option('envirolink_randomize_daily_order', 'no') === 'yes') {
            add_filter('posts_orderby', array($this, 'randomize_daily_order'), 10, 2);
        }

        // Jetpack Social (Publicize) integration
        add_filter('publicize_should_publicize_published_post', array($this, 'enable_jetpack_publicize_for_envirolink'), 10, 2);
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options
        $default_feeds = array(
            array(
                'url' => 'https://news.mongabay.com/feed/',
                'name' => 'Mongabay',
                'enabled' => true,
                'schedule_type' => 'hourly',
                'schedule_times' => 1,
                'last_processed' => 0,
                'include_author' => true,
                'include_pubdate' => true,
                'include_topic_tags' => true,
                'include_locations' => true,
                'use_pexels_images' => false
            )
        );
        
        if (!get_option('envirolink_feeds')) {
            add_option('envirolink_feeds', $default_feeds);
        }
        
        if (!get_option('envirolink_api_key')) {
            add_option('envirolink_api_key', '');
        }

        if (!get_option('envirolink_pexels_api_key')) {
            add_option('envirolink_pexels_api_key', '');
        }

        if (!get_option('envirolink_post_category')) {
            add_option('envirolink_post_category', '');
        }
        
        if (!get_option('envirolink_post_status')) {
            add_option('envirolink_post_status', 'publish');
        }
        
        if (!get_option('envirolink_last_run')) {
            add_option('envirolink_last_run', '');
        }

        if (!get_option('envirolink_update_existing')) {
            add_option('envirolink_update_existing', 'no');
        }

        if (!get_option('envirolink_daily_roundup_enabled')) {
            add_option('envirolink_daily_roundup_enabled', 'no');
        }

        if (!get_option('envirolink_ontology_enabled')) {
            add_option('envirolink_ontology_enabled', 'no');
        }

        // Create ontology database tables
        $this->create_ontology_tables();

        // Schedule cron job (hourly)
        if (!wp_next_scheduled('envirolink_fetch_feeds')) {
            wp_schedule_event(time(), 'hourly', 'envirolink_fetch_feeds');
        }

        // Schedule daily roundup (8am ET)
        if (!wp_next_scheduled('envirolink_daily_roundup')) {
            // Calculate next 8am ET
            $timezone = new DateTimeZone('America/New_York');
            $now = new DateTime('now', $timezone);
            $next_run = new DateTime('today 8:00 AM', $timezone);

            // If it's past 8am today, schedule for tomorrow
            if ($now >= $next_run) {
                $next_run->modify('+1 day');
            }

            wp_schedule_event($next_run->getTimestamp(), 'daily', 'envirolink_daily_roundup');
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Remove feed fetching cron job
        $timestamp = wp_next_scheduled('envirolink_fetch_feeds');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'envirolink_fetch_feeds');
        }

        // Remove daily roundup cron job
        $roundup_timestamp = wp_next_scheduled('envirolink_daily_roundup');
        if ($roundup_timestamp) {
            wp_unschedule_event($roundup_timestamp, 'envirolink_daily_roundup');
        }
    }

    /**
     * Randomize post order within the same day
     * Orders posts by date (newest first), then randomly within each day
     * This prevents clustering of posts by source
     */
    public function randomize_daily_order($orderby, $query) {
        global $wpdb;

        // Safety checks - return original order if anything is wrong
        if (!$query || !$wpdb || !isset($wpdb->posts)) {
            return $orderby;
        }

        // Only affect main query
        if (!$query->is_main_query()) {
            return $orderby;
        }

        // Don't affect admin area
        if (is_admin()) {
            return $orderby;
        }

        // Don't affect feeds
        if (is_feed()) {
            return $orderby;
        }

        // Don't affect robots.txt
        if (function_exists('is_robots') && is_robots()) {
            return $orderby;
        }

        // Don't affect sitemaps (WP 5.5+)
        if (function_exists('is_sitemap') && is_sitemap()) {
            return $orderby;
        }

        // Only apply to standard blog queries (home, archive, category, tag, author, date)
        // Don't apply to: pages, single posts, search, 404, attachments, custom post types
        if (!is_home() && !is_archive() && !is_category() && !is_tag() && !is_author() && !is_date()) {
            return $orderby;
        }

        // Extra safety: Check post_type if it's explicitly set
        if (isset($query->query_vars['post_type'])) {
            $post_type = $query->query_vars['post_type'];
            // If post_type is set and it's not 'post' (or empty array), don't randomize
            if ($post_type !== 'post' && $post_type !== '' && $post_type !== array() && $post_type !== array('post')) {
                return $orderby;
            }
        }

        // Order by date DESC (newest first), then RAND() within same day
        // DATE() extracts just the date portion, ignoring time
        // This groups all posts from the same day together, then randomizes within each group
        return "DATE({$wpdb->posts}.post_date) DESC, RAND()";
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'EnviroLink AI Aggregator',
            'EnviroLink News',
            'manage_options',
            'envirolink-aggregator',
            array($this, 'admin_page'),
            'dashicons-rss',
            30
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('envirolink_settings', 'envirolink_feeds');
        register_setting('envirolink_settings', 'envirolink_api_key');
        register_setting('envirolink_settings', 'envirolink_pexels_api_key');
        register_setting('envirolink_settings', 'envirolink_post_category');
        register_setting('envirolink_settings', 'envirolink_post_status');
        register_setting('envirolink_settings', 'envirolink_update_existing');
        register_setting('envirolink_settings', 'envirolink_randomize_daily_order');
        register_setting('envirolink_settings', 'envirolink_ontology_enabled');
    }
    
    /**
     * Admin page HTML
     */
    public function admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Ensure ontology tables exist (creates them if missing after plugin update)
        if (!get_option('envirolink_ontology_tables_created')) {
            $this->create_ontology_tables();
        }

        // Check for AI metadata generation failure and show warning
        $metadata_failure = get_transient('envirolink_metadata_generation_failed');
        if ($metadata_failure) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>⚠ AI Metadata Generation Failed</strong></p>';
            echo '<p>The last roundup used fallback title/description because AI metadata generation failed.</p>';
            echo '<p><strong>Time:</strong> ' . esc_html($metadata_failure['timestamp']) . '<br>';
            echo '<strong>Error:</strong> ' . esc_html($metadata_failure['error']) . '</p>';
            echo '<p>Check the roundup generation log below for details. This usually means the AI API is unavailable or returned invalid JSON.</p>';
            echo '</div>';
            // Clear the transient after showing it once
            delete_transient('envirolink_metadata_generation_failed');
        }

        // Get search query (used in Articles tab)
        $search_query = isset($_GET['article_search']) ? sanitize_text_field($_GET['article_search']) : '';

        // Save settings
        if (isset($_POST['envirolink_save_settings'])) {
            check_admin_referer('envirolink_settings');

            update_option('envirolink_api_key', sanitize_text_field($_POST['api_key']));
            update_option('envirolink_pexels_api_key', sanitize_text_field($_POST['pexels_api_key']));
            update_option('envirolink_post_category', absint($_POST['post_category']));
            update_option('envirolink_post_status', sanitize_text_field($_POST['post_status']));
            update_option('envirolink_update_existing', isset($_POST['update_existing']) ? 'yes' : 'no');
            update_option('envirolink_randomize_daily_order', isset($_POST['randomize_daily_order']) ? 'yes' : 'no');
            update_option('envirolink_auto_cleanup_duplicates', isset($_POST['auto_cleanup_duplicates']) ? 'yes' : 'no');
            update_option('envirolink_keyword_daily_limit', absint($_POST['keyword_daily_limit']));
            update_option('envirolink_daily_roundup_enabled', isset($_POST['daily_roundup_enabled']) ? 'yes' : 'no');
            update_option('envirolink_roundup_auto_fetch_unsplash', isset($_POST['roundup_auto_fetch_unsplash']) ? 'yes' : 'no');
            update_option('envirolink_unsplash_api_key', sanitize_text_field($_POST['unsplash_api_key']));
            update_option('envirolink_cron_secret_key', sanitize_text_field($_POST['cron_secret_key']));
            update_option('envirolink_ontology_enabled', isset($_POST['ontology_enabled']) ? 'yes' : 'no');

            // Save roundup images collection
            if (isset($_POST['roundup_images'])) {
                $roundup_images = json_decode(stripslashes($_POST['roundup_images']), true);
                if (is_array($roundup_images)) {
                    // Validate all IDs are integers
                    $roundup_images = array_map('absint', $roundup_images);
                    update_option('envirolink_roundup_images', $roundup_images);
                }
            }

            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }
        
        // Add feed
        if (isset($_POST['envirolink_add_feed'])) {
            check_admin_referer('envirolink_add_feed');

            $feeds = get_option('envirolink_feeds', array());
            $feeds[] = array(
                'url' => esc_url_raw($_POST['feed_url']),
                'name' => sanitize_text_field($_POST['feed_name']),
                'enabled' => true,
                'schedule_type' => sanitize_text_field($_POST['schedule_type']),
                'schedule_times' => absint($_POST['schedule_times']),
                'last_processed' => 0,
                'include_author' => isset($_POST['include_author']),
                'include_pubdate' => isset($_POST['include_pubdate']),
                'include_topic_tags' => isset($_POST['include_topic_tags']),
                'include_locations' => isset($_POST['include_locations']),
                'use_pexels_images' => false  // Default to false, can be enabled in Edit Settings
            );
            update_option('envirolink_feeds', $feeds);
            
            echo '<div class="notice notice-success"><p>Feed added!</p></div>';
        }
        
        // Delete feed
        if (isset($_GET['delete_feed'])) {
            check_admin_referer('envirolink_delete_feed_' . $_GET['delete_feed']);
            
            $feeds = get_option('envirolink_feeds', array());
            $index = intval($_GET['delete_feed']);
            if (isset($feeds[$index])) {
                unset($feeds[$index]);
                $feeds = array_values($feeds);
                update_option('envirolink_feeds', $feeds);
                echo '<div class="notice notice-success"><p>Feed deleted!</p></div>';
            }
        }
        
        // Toggle feed
        if (isset($_GET['toggle_feed'])) {
            check_admin_referer('envirolink_toggle_feed_' . $_GET['toggle_feed']);

            $feeds = get_option('envirolink_feeds', array());
            $index = intval($_GET['toggle_feed']);
            if (isset($feeds[$index])) {
                $feeds[$index]['enabled'] = !$feeds[$index]['enabled'];
                update_option('envirolink_feeds', $feeds);
                echo '<div class="notice notice-success"><p>Feed updated!</p></div>';
            }
        }

        // Reschedule cron
        if (isset($_GET['reschedule_cron'])) {
            check_admin_referer('envirolink_reschedule_cron');

            // Clear existing schedule
            $timestamp = wp_next_scheduled('envirolink_fetch_feeds');
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'envirolink_fetch_feeds');
            }

            // Reschedule for next hour
            wp_schedule_event(time(), 'hourly', 'envirolink_fetch_feeds');

            echo '<div class="notice notice-success"><p>Cron rescheduled successfully! Next run in approximately 1 hour.</p></div>';
        }

        // Edit feed settings
        if (isset($_POST['envirolink_edit_feed'])) {
            check_admin_referer('envirolink_edit_feed');

            $feeds = get_option('envirolink_feeds', array());
            $index = intval($_POST['feed_index']);
            if (isset($feeds[$index])) {
                $feeds[$index]['schedule_type'] = sanitize_text_field($_POST['schedule_type']);
                $feeds[$index]['schedule_times'] = absint($_POST['schedule_times']);
                $feeds[$index]['include_author'] = isset($_POST['include_author']);
                $feeds[$index]['include_pubdate'] = isset($_POST['include_pubdate']);
                $feeds[$index]['include_topic_tags'] = isset($_POST['include_topic_tags']);
                $feeds[$index]['include_locations'] = isset($_POST['include_locations']);
                $feeds[$index]['use_pexels_images'] = isset($_POST['use_pexels_images']);
                update_option('envirolink_feeds', $feeds);
                echo '<div class="notice notice-success"><p>Feed settings updated!</p></div>';
            }
        }
        
        $api_key = get_option('envirolink_api_key', '');
        $post_category = get_option('envirolink_post_category', '');
        $post_status = get_option('envirolink_post_status', 'publish');
        $update_existing = get_option('envirolink_update_existing', 'no');
        $feeds = get_option('envirolink_feeds', array());
        $last_run = get_option('envirolink_last_run', '');
        $next_run = wp_next_scheduled('envirolink_fetch_feeds');
        
        ?>
        <div class="wrap">
            <h1>EnviroLink AI News Aggregator</h1>

            <div style="display: flex; gap: 20px; margin-bottom: 20px;">
                <div class="card" style="flex: 1; max-width: 500px;">
                    <h2>System Status <span style="font-size: 12px; color: #666; font-weight: normal;">v<?php echo ENVIROLINK_VERSION; ?></span></h2>
                    <table class="form-table">
                        <tr>
                            <th>Last Run:</th>
                            <td><?php echo $last_run ? date('Y-m-d H:i:s', strtotime($last_run)) : 'Never'; ?></td>
                        </tr>
                        <tr>
                            <th>Next Scheduled Run:</th>
                            <td>
                                <?php
                                if ($next_run) {
                                    echo date('Y-m-d H:i:s', $next_run);
                                    // Check if next run is in the past (cron is broken)
                                    if ($next_run < time()) {
                                        echo '<br><span style="color: red; font-weight: bold;">⚠️ Cron is broken (date is in the past)</span><br>';
                                        echo '<a href="' . wp_nonce_url(admin_url('admin.php?page=envirolink-aggregator&reschedule_cron=1'), 'envirolink_reschedule_cron') . '" class="button button-small" style="margin-top: 5px;">Fix Cron Schedule</a>';
                                    }
                                } else {
                                    echo '<span style="color: red;">Not scheduled</span><br>';
                                    echo '<a href="' . wp_nonce_url(admin_url('admin.php?page=envirolink-aggregator&reschedule_cron=1'), 'envirolink_reschedule_cron') . '" class="button button-small" style="margin-top: 5px;">Schedule Cron</a>';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Feed Processing Schedule:</th>
                            <td>
                                <strong>Twice Daily:</strong> 7:00 AM ET and 4:00 PM ET
                                <p class="description" style="margin-top: 5px;">
                                    Feeds process during 2-hour windows: 6am-8am ET (morning) and 3pm-5pm ET (afternoon).
                                    Manual runs via "Run All Feeds" button work at any time.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th>API Key Status:</th>
                            <td>
                                <?php if (!empty($api_key)): ?>
                                    <span style="color: green;">✓ Configured</span>
                                <?php else: ?>
                                    <span style="color: red;">✗ Not configured</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Active Feeds:</th>
                            <td><?php echo count(array_filter($feeds, function($f) { return $f['enabled']; })); ?></td>
                        </tr>
                        <tr>
                            <th>WordPress Cron:</th>
                            <td>
                                <?php
                                if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
                                    echo '<span style="color: orange;">⚠️ Disabled (using system cron)</span>';
                                    echo '<p class="description" style="margin-top: 5px;">WP-Cron is disabled. Make sure you have a system cron job set up to run wp-cron.php</p>';
                                } else {
                                    echo '<span style="color: green;">✓ Enabled</span>';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Plugin Updates:</th>
                            <td>
                                <div id="update-check-status">
                                    <span style="color: #666;">Click button to check for updates</span>
                                </div>
                                <button type="button" class="button button-small" id="check-updates-btn" style="margin-top: 5px;">Check for Updates</button>
                            </td>
                        </tr>
                    </table>

                    <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd;">
                        <h3 style="margin-top: 0; margin-bottom: 12px; font-size: 14px; color: #23282d;">Feed Processing</h3>
                        <div style="display: flex; gap: 8px; margin-bottom: 15px;">
                            <button type="button" class="button button-primary" id="run-now-btn">
                                <span class="dashicons dashicons-update" style="font-size: 16px; width: 16px; height: 16px; vertical-align: middle; margin-top: 2px;"></span>
                                Run All Feeds
                            </button>
                            <button type="button" class="button button-secondary" id="generate-roundup-btn" title="Generate daily editorial roundup (bypasses 8am schedule)">
                                <span class="dashicons dashicons-media-document" style="font-size: 16px; width: 16px; height: 16px; vertical-align: middle; margin-top: 2px;"></span>
                                Generate Roundup
                            </button>
                        </div>

                        <h3 style="margin-bottom: 12px; font-size: 14px; color: #23282d;">Maintenance Tools</h3>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <button type="button" class="button" id="fix-dates-btn" title="Sync post dates to RSS publication dates">
                                <span class="dashicons dashicons-calendar" style="font-size: 16px; width: 16px; height: 16px; vertical-align: middle; margin-top: 2px;"></span>
                                Fix Post Dates
                            </button>
                            <button type="button" class="button" id="cleanup-duplicates-btn" title="Find and delete duplicate articles">
                                <span class="dashicons dashicons-trash" style="font-size: 16px; width: 16px; height: 16px; vertical-align: middle; margin-top: 2px;"></span>
                                Clean Duplicates
                            </button>
                            <button type="button" class="button" id="categorize-posts-btn" title="Add 'newsfeed' category to all aggregated posts">
                                <span class="dashicons dashicons-tag" style="font-size: 16px; width: 16px; height: 16px; vertical-align: middle; margin-top: 2px;"></span>
                                Categorize Posts
                            </button>
                            <button type="button" class="button" id="update-authors-btn" title="Change all post authors to EnviroLink Editor">
                                <span class="dashicons dashicons-admin-users" style="font-size: 16px; width: 16px; height: 16px; vertical-align: middle; margin-top: 2px;"></span>
                                Update Authors
                            </button>
                        </div>

                        <div id="run-now-status" style="margin-top: 12px;"></div>
                    </div>

                    <!-- Progress Bar -->
                    <div id="envirolink-progress-container" style="display: none; margin-top: 15px;">
                        <div style="margin-bottom: 5px;">
                            <strong id="envirolink-progress-status">Processing...</strong>
                            <span id="envirolink-progress-percent" style="float: right;">0%</span>
                        </div>
                        <div style="width: 100%; height: 25px; background-color: #f0f0f0; border-radius: 3px; overflow: hidden;">
                            <div id="envirolink-progress-bar" style="width: 0%; height: 100%; background-color: #2271b1; transition: width 0.3s ease;"></div>
                        </div>
                        <div id="envirolink-progress-detail" style="margin-top: 5px; font-size: 12px; color: #666;">
                            <span id="envirolink-progress-current">0</span> of <span id="envirolink-progress-total">0</span> articles
                        </div>
                    </div>

                    <!-- Log Viewer -->
                    <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd;">
                        <button type="button" class="button button-small" id="toggle-log-btn">
                            <span class="dashicons dashicons-visibility" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle; margin-top: 2px;"></span>
                            Show Detailed Log
                        </button>
                        <div id="envirolink-log-container" style="display: none; margin-top: 10px; max-height: 300px; overflow-y: auto; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; padding: 12px; font-family: 'Courier New', monospace; font-size: 11px; line-height: 1.6;">
                            <div id="envirolink-log-content"></div>
                        </div>
                    </div>
                </div>

                <div class="card" style="flex: 1; max-width: 450px;">
                    <h2 style="margin-top: 0;">Feed Actions</h2>
                    <p class="description" style="margin-top: -8px; margin-bottom: 15px;">Run individual feeds or update their images</p>
                    <?php if (empty($feeds)): ?>
                        <p style="color: #666; font-style: italic;">No feeds configured yet. Add feeds in the RSS Feeds tab below.</p>
                    <?php else: ?>
                        <div style="max-height: 400px; overflow-y: auto; margin: -12px; padding: 12px;">
                            <?php foreach ($feeds as $index => $feed): ?>
                                <div style="padding: 12px; margin-bottom: 8px; background: #fafafa; border: 1px solid #e0e0e0; border-radius: 4px; display: flex; align-items: center; justify-content: space-between;">
                                    <div style="flex: 1; min-width: 0; margin-right: 10px;">
                                        <div style="font-weight: 600; color: #23282d; margin-bottom: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo esc_attr($feed['name']); ?>">
                                            <?php echo esc_html($feed['name']); ?>
                                        </div>
                                        <?php if (!$feed['enabled']): ?>
                                            <span style="color: #999; font-size: 11px; font-style: italic;">Disabled</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="display: flex; gap: 6px; flex-shrink: 0;">
                                        <button type="button" class="button button-small edit-schedule-btn"
                                                data-index="<?php echo $index; ?>"
                                                data-schedule-type="<?php echo esc_attr(isset($feed['schedule_type']) ? $feed['schedule_type'] : 'hourly'); ?>"
                                                data-schedule-times="<?php echo esc_attr(isset($feed['schedule_times']) ? $feed['schedule_times'] : 1); ?>"
                                                data-include-author="<?php echo (isset($feed['include_author']) && $feed['include_author']) ? '1' : '0'; ?>"
                                                data-include-pubdate="<?php echo (isset($feed['include_pubdate']) && $feed['include_pubdate']) ? '1' : '0'; ?>"
                                                data-include-topic-tags="<?php echo (isset($feed['include_topic_tags']) && $feed['include_topic_tags']) ? '1' : '0'; ?>"
                                                data-include-locations="<?php echo (isset($feed['include_locations']) && $feed['include_locations']) ? '1' : '0'; ?>"
                                                title="Edit feed settings">
                                            <span class="dashicons dashicons-edit" style="font-size: 16px; width: 16px; height: 16px;"></span>
                                        </button>
                                        <button type="button" class="button button-small update-images-btn"
                                                data-index="<?php echo $index; ?>"
                                                data-name="<?php echo esc_attr($feed['name']); ?>"
                                                title="Re-download images (last 20 posts)">
                                            <span class="dashicons dashicons-format-image" style="font-size: 16px; width: 16px; height: 16px;"></span>
                                        </button>
                                        <button type="button" class="button button-small button-primary run-feed-btn"
                                                data-index="<?php echo $index; ?>"
                                                data-name="<?php echo esc_attr($feed['name']); ?>"
                                                title="Process this feed now"
                                                <?php echo !$feed['enabled'] ? 'disabled' : ''; ?>>
                                            <span class="dashicons dashicons-update" style="font-size: 16px; width: 16px; height: 16px;"></span>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <h2 class="nav-tab-wrapper">
                <a href="#settings" class="nav-tab nav-tab-active">Settings</a>
                <a href="#feeds" class="nav-tab">RSS Feeds</a>
                <a href="#articles" class="nav-tab">Articles</a>
                <a href="#ontology" class="nav-tab">Ontology</a>
            </h2>

            <div id="settings-tab" class="tab-content">
                <form method="post" action="">
                    <?php wp_nonce_field('envirolink_settings'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="api_key">Anthropic API Key</label>
                            </th>
                            <td>
                                <input type="password" id="api_key" name="api_key"
                                       value="<?php echo esc_attr($api_key); ?>"
                                       class="regular-text" />
                                <p class="description">
                                    Get your API key from <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="pexels_api_key">Pexels API Key</label>
                            </th>
                            <td>
                                <input type="password" id="pexels_api_key" name="pexels_api_key"
                                       value="<?php echo esc_attr(get_option('envirolink_pexels_api_key', '')); ?>"
                                       class="regular-text" />
                                <p class="description">
                                    Get your free API key from <a href="https://www.pexels.com/api/" target="_blank">pexels.com/api</a><br>
                                    Used to search for relevant images when enabled per-feed
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="post_category">Default Category</label>
                            </th>
                            <td>
                                <?php
                                wp_dropdown_categories(array(
                                    'name' => 'post_category',
                                    'id' => 'post_category',
                                    'selected' => $post_category,
                                    'show_option_none' => 'Uncategorized',
                                    'option_none_value' => '0',
                                    'hide_empty' => false
                                ));
                                ?>
                                <p class="description">Category for aggregated posts</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="post_status">Post Status</label>
                            </th>
                            <td>
                                <select name="post_status" id="post_status">
                                    <option value="publish" <?php selected($post_status, 'publish'); ?>>Publish</option>
                                    <option value="draft" <?php selected($post_status, 'draft'); ?>>Draft</option>
                                </select>
                                <p class="description">Status for new posts</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                Update Existing Posts
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" name="update_existing" id="update_existing"
                                           <?php checked($update_existing, 'yes'); ?> />
                                    Update existing posts instead of skipping duplicates
                                </label>
                                <p class="description">When enabled, if an article already exists (same source URL), it will be updated with new AI-rewritten content instead of being skipped</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                Randomize Daily Order
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" name="randomize_daily_order" id="randomize_daily_order"
                                           <?php checked(get_option('envirolink_randomize_daily_order', 'no'), 'yes'); ?> />
                                    Randomize order of posts within the same day
                                </label>
                                <p class="description">When enabled, posts from the same day will appear in random order instead of being clustered by source. Prevents all Guardian posts appearing together, then all Mongabay posts, etc. Does not modify timestamps.</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                Auto-Cleanup Duplicates
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" name="auto_cleanup_duplicates" id="auto_cleanup_duplicates"
                                           <?php checked(get_option('envirolink_auto_cleanup_duplicates', 'yes'), 'yes'); ?> />
                                    Automatically clean up duplicates after feed import
                                </label>
                                <p class="description">When enabled, runs the duplicate cleanup process automatically after each feed import. This catches any duplicates that slip through the initial detection (URL normalization, title similarity, same images). Uses the same proven logic as the "Clean Duplicates" button. <strong>Recommended: Leave enabled.</strong></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                Keyword Daily Limit
                            </th>
                            <td>
                                <input type="number"
                                       name="keyword_daily_limit"
                                       id="keyword_daily_limit"
                                       value="<?php echo esc_attr(get_option('envirolink_keyword_daily_limit', 2)); ?>"
                                       min="1"
                                       max="10"
                                       style="width: 80px;" />
                                <label for="keyword_daily_limit"> articles per topic per day</label>
                                <p class="description">Prevents redundant coverage of the same event. Before publishing an article, the plugin extracts keywords from the RSS title and checks if similar articles were already published today (same calendar date). If the daily limit is reached, the article is skipped. The counter resets at midnight. This prevents multiple outlets covering the same story (e.g., 6 different "COP30 ends" articles). <strong>Recommended: 2-3 articles per topic.</strong> Set higher (5-10) if you want comprehensive multi-source coverage of major events.</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                Environmental Ontology Filtering
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ontology_enabled" id="ontology_enabled"
                                           <?php checked(get_option('envirolink_ontology_enabled', 'no'), 'yes'); ?> />
                                    Enable ontology-based tag filtering
                                </label>
                                <p class="description">
                                    When enabled, RSS feed tags are filtered through a curated environmental news taxonomy based on IPTC Media Topics and UN Sustainable Development Goals. Only tags matching topics in the ontology will be applied to articles. This eliminates junk tags like "World News", "Homepage", "Breaking" and ensures consistent, relevant tagging. <strong>Requires ontology database to be seeded first</strong> - visit the <a href="#" onclick="$('.nav-tab').removeClass('nav-tab-active'); $('.nav-tab[href=\'#ontology\']').addClass('nav-tab-active'); $('.tab-content').hide(); $('#ontology-tab').show(); return false;">Ontology tab</a> to set up.
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                Daily News Roundup
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" name="daily_roundup_enabled" id="daily_roundup_enabled"
                                           <?php checked(get_option('envirolink_daily_roundup_enabled', 'no'), 'yes'); ?> />
                                    Enable daily editorial roundup post
                                </label>
                                <p class="description">When enabled, an AI-generated editorial roundup of the past 24 hours' environmental news will be published automatically at 8:00 AM ET daily. The post will have a balanced, humanistic tone and appear as original content titled "Daily Environmental News Roundup by the EnviroLink Team - [Date]". The aggregator will run first to ensure all recent articles are included.</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                Roundup Featured Images
                            </th>
                            <td>
                                <label style="display: block; margin-bottom: 15px;">
                                    <input type="checkbox" name="roundup_auto_fetch_unsplash" id="roundup_auto_fetch_unsplash"
                                           <?php checked(get_option('envirolink_roundup_auto_fetch_unsplash', 'no'), 'yes'); ?> />
                                    <strong>Auto-fetch from Unsplash</strong> - Automatically get high-quality environmental images from Unsplash (free stock photos)
                                </label>

                                <div id="unsplash_api_key_wrapper" style="margin-bottom: 15px; padding: 15px; background: #f0f0f1; border-radius: 4px; <?php echo get_option('envirolink_roundup_auto_fetch_unsplash', 'no') === 'no' ? 'display: none;' : ''; ?>">
                                    <label style="display: block; margin-bottom: 8px;">
                                        <strong>Unsplash API Access Key</strong> (required for auto-fetch):
                                    </label>
                                    <input type="text"
                                           name="unsplash_api_key"
                                           value="<?php echo esc_attr(get_option('envirolink_unsplash_api_key', '')); ?>"
                                           class="regular-text"
                                           placeholder="Enter your Unsplash Access Key"
                                           style="width: 100%; max-width: 500px;" />
                                    <p class="description" style="margin-top: 8px;">
                                        Get a free API key at <a href="https://unsplash.com/developers" target="_blank">unsplash.com/developers</a><br>
                                        1. Create a free account<br>
                                        2. Create a new application (name it "EnviroLink News")<br>
                                        3. Copy the "Access Key" and paste it here
                                    </p>
                                </div>

                                <p class="description" style="margin-bottom: 15px;">
                                    <strong>Option 1:</strong> Auto-fetch from Unsplash (keywords: environment, climate, nature, earth) - requires API key above<br>
                                    <strong>Option 2:</strong> Upload your own collection below and randomly select from it<br>
                                    <strong>Option 3:</strong> Enable both - uses Unsplash if your collection is empty
                                </p>

                                <div style="border-top: 1px solid #ddd; padding-top: 15px; margin-top: 15px;">
                                    <p class="description" style="margin-bottom: 15px;">
                                        <strong>Manual Collection:</strong> Upload multiple images to create a collection. The plugin will randomly select one for each daily roundup post.<br>
                                        <strong>Attribution:</strong> Add photo credits by editing each image in Media Library → Caption field (e.g., "Photo by John Doe")
                                    </p>

                                <?php
                                $roundup_images = get_option('envirolink_roundup_images', array());
                                if (!empty($roundup_images)) {
                                    echo '<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 15px; margin-bottom: 15px;">';
                                    foreach ($roundup_images as $image_id) {
                                        $image_url = wp_get_attachment_image_url($image_id, 'thumbnail');
                                        if ($image_url) {
                                            echo '<div style="position: relative; border: 1px solid #ddd; border-radius: 4px; overflow: hidden;">';
                                            echo '<img src="' . esc_url($image_url) . '" style="width: 100%; height: 150px; object-fit: cover; display: block;">';
                                            echo '<button type="button" class="envirolink-remove-image" data-image-id="' . $image_id . '" style="position: absolute; top: 5px; right: 5px; background: #dc3232; color: white; border: none; border-radius: 3px; padding: 5px 10px; cursor: pointer; font-size: 12px;">Remove</button>';
                                            echo '</div>';
                                        }
                                    }
                                    echo '</div>';
                                }
                                ?>

                                <button type="button" id="envirolink_add_roundup_image" class="button">
                                    <?php echo empty($roundup_images) ? 'Add Images to Collection' : 'Add More Images'; ?>
                                </button>

                                <p class="description" style="margin-top: 10px;">
                                    <strong>Tip:</strong> Upload high-quality environmental images (nature, earth, climate themes). Currently <?php echo count($roundup_images); ?> image(s) in collection.
                                </p>

                                <input type="hidden" name="roundup_images" id="roundup_images" value="<?php echo esc_attr(json_encode($roundup_images)); ?>">
                                </div>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                System Cron Integration
                            </th>
                            <td>
                                <label style="display: block; margin-bottom: 8px;">
                                    <strong>Secret Key for System Cron:</strong>
                                </label>
                                <input type="text"
                                       name="cron_secret_key"
                                       value="<?php echo esc_attr(get_option('envirolink_cron_secret_key', '')); ?>"
                                       placeholder="Generate a random secret key"
                                       style="width: 100%; max-width: 500px; font-family: monospace;">
                                <p class="description">
                                    <strong style="color: #d63638;">⚠️ WARNING:</strong> Only use system cron if you have disabled WordPress cron (<code>DISABLE_WP_CRON</code>). <strong>Do NOT run both</strong> WordPress cron and system cron simultaneously, as this will create duplicate roundup posts.<br><br>
                                    If you're using system cron instead of WordPress cron, add this to your crontab to run the roundup at 8am MT:<br>
                                    <code style="display: block; margin-top: 8px; padding: 8px; background: #f0f0f1; font-size: 12px; overflow-x: auto;">
                                    0 8 * * * curl -s "<?php echo admin_url('admin-ajax.php'); ?>?action=envirolink_cron_roundup&key=YOUR_SECRET_KEY" > /dev/null 2>&1
                                    </code>
                                    <strong>Important:</strong> Replace <code>YOUR_SECRET_KEY</code> with the value above. This runs feeds first, then generates the roundup.
                                </p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <input type="submit" name="envirolink_save_settings"
                               class="button button-primary" value="Save Settings" />
                    </p>
                </form>
            </div>
            
            <div id="feeds-tab" class="tab-content" style="display: none;">
                <h3>Add New Feed</h3>
                <form method="post" action="" style="background: #fff; padding: 20px; border: 1px solid #ccc; max-width: 600px;">
                    <?php wp_nonce_field('envirolink_add_feed'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="feed_name">Feed Name</label>
                            </th>
                            <td>
                                <input type="text" id="feed_name" name="feed_name" 
                                       class="regular-text" required />
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="feed_url">RSS Feed URL</label>
                            </th>
                            <td>
                                <input type="url" id="feed_url" name="feed_url"
                                       class="regular-text" required
                                       placeholder="https://example.com/feed" />
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="schedule_times">Import Frequency</label>
                            </th>
                            <td>
                                <input type="number" id="schedule_times" name="schedule_times"
                                       value="1" min="1" max="24" style="width: 60px;" required />
                                times per
                                <select name="schedule_type" id="schedule_type" required>
                                    <option value="hourly">Hour</option>
                                    <option value="daily" selected>Day</option>
                                    <option value="weekly">Week</option>
                                    <option value="monthly">Month</option>
                                </select>
                                <p class="description">How often to import articles from this feed</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                Metadata to Include
                            </th>
                            <td>
                                <fieldset>
                                    <label>
                                        <input type="checkbox" name="include_author" value="1" checked />
                                        Author (dc:creator)
                                    </label><br/>
                                    <label>
                                        <input type="checkbox" name="include_pubdate" value="1" checked />
                                        Publication Date (pubDate)
                                    </label><br/>
                                    <label>
                                        <input type="checkbox" name="include_topic_tags" value="1" checked />
                                        Topic Tags
                                    </label><br/>
                                    <label>
                                        <input type="checkbox" name="include_locations" value="1" checked />
                                        Locations
                                    </label>
                                </fieldset>
                                <p class="description">Select which metadata fields to extract and store from the RSS feed</p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <input type="submit" name="envirolink_add_feed"
                               class="button button-primary" value="Add Feed" />
                    </p>
                </form>
                
                <h3 style="margin-top: 30px;">Current Feeds</h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Name</th>
                            <th>URL</th>
                            <th>Schedule</th>
                            <th>Last Processed</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($feeds)): ?>
                            <tr>
                                <td colspan="6">No feeds configured</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($feeds as $index => $feed): ?>
                                <?php
                                // Ensure backward compatibility with old feeds
                                $schedule_type = isset($feed['schedule_type']) ? $feed['schedule_type'] : 'hourly';
                                $schedule_times = isset($feed['schedule_times']) ? $feed['schedule_times'] : 1;
                                $last_processed = isset($feed['last_processed']) ? $feed['last_processed'] : 0;
                                $include_author = isset($feed['include_author']) ? $feed['include_author'] : true;
                                $include_pubdate = isset($feed['include_pubdate']) ? $feed['include_pubdate'] : true;
                                $include_topic_tags = isset($feed['include_topic_tags']) ? $feed['include_topic_tags'] : true;
                                $include_locations = isset($feed['include_locations']) ? $feed['include_locations'] : true;
                                $use_pexels_images = isset($feed['use_pexels_images']) ? $feed['use_pexels_images'] : false;

                                $schedule_label = $schedule_type === 'hourly' ? 'hour' :
                                                 ($schedule_type === 'daily' ? 'day' :
                                                 ($schedule_type === 'weekly' ? 'week' : 'month'));
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($feed['enabled']): ?>
                                            <span style="color: green;">● Active</span>
                                        <?php else: ?>
                                            <span style="color: red;">○ Disabled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($feed['name']); ?></td>
                                    <td>
                                        <a href="<?php echo esc_url($feed['url']); ?>" target="_blank">
                                            <?php echo esc_html($feed['url']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?php echo esc_html($schedule_times); ?> / <?php echo esc_html($schedule_label); ?>
                                    </td>
                                    <td>
                                        <?php
                                        if ($last_processed) {
                                            echo date('Y-m-d H:i', $last_processed);
                                        } else {
                                            echo 'Never';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <button type="button" class="button button-small edit-schedule-btn"
                                                data-index="<?php echo $index; ?>"
                                                data-schedule-type="<?php echo esc_attr($schedule_type); ?>"
                                                data-schedule-times="<?php echo esc_attr($schedule_times); ?>"
                                                data-include-author="<?php echo $include_author ? '1' : '0'; ?>"
                                                data-include-pubdate="<?php echo $include_pubdate ? '1' : '0'; ?>"
                                                data-include-topic-tags="<?php echo $include_topic_tags ? '1' : '0'; ?>"
                                                data-include-locations="<?php echo $include_locations ? '1' : '0'; ?>"
                                                data-use-pexels-images="<?php echo $use_pexels_images ? '1' : '0'; ?>">
                                            Edit Settings
                                        </button>

                                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=envirolink-aggregator&toggle_feed=' . $index), 'envirolink_toggle_feed_' . $index); ?>"
                                           class="button button-small">
                                            <?php echo $feed['enabled'] ? 'Disable' : 'Enable'; ?>
                                        </a>

                                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=envirolink-aggregator&delete_feed=' . $index), 'envirolink_delete_feed_' . $index); ?>"
                                           class="button button-small"
                                           onclick="return confirm('Are you sure you want to delete this feed?');">
                                            Delete
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- Edit Feed Settings Modal -->
                <div id="edit-schedule-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999;">
                    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 5px; min-width: 500px; max-height: 80vh; overflow-y: auto;">
                        <h2>Edit Feed Settings</h2>
                        <form method="post" action="" id="edit-schedule-form">
                            <?php wp_nonce_field('envirolink_edit_feed'); ?>
                            <input type="hidden" name="feed_index" id="edit-feed-index" value="" />

                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="edit-schedule-times">Import Frequency</label>
                                    </th>
                                    <td>
                                        <input type="number" id="edit-schedule-times" name="schedule_times"
                                               value="1" min="1" max="24" style="width: 60px;" required />
                                        times per
                                        <select name="schedule_type" id="edit-schedule-type" required>
                                            <option value="hourly">Hour</option>
                                            <option value="daily">Day</option>
                                            <option value="weekly">Week</option>
                                            <option value="monthly">Month</option>
                                        </select>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        Metadata to Include
                                    </th>
                                    <td>
                                        <fieldset>
                                            <label>
                                                <input type="checkbox" name="include_author" id="edit-include-author" value="1" />
                                                Author (dc:creator)
                                            </label><br/>
                                            <label>
                                                <input type="checkbox" name="include_pubdate" id="edit-include-pubdate" value="1" />
                                                Publication Date (pubDate)
                                            </label><br/>
                                            <label>
                                                <input type="checkbox" name="include_topic_tags" id="edit-include-topic-tags" value="1" />
                                                Topic Tags
                                            </label><br/>
                                            <label>
                                                <input type="checkbox" name="include_locations" id="edit-include-locations" value="1" />
                                                Locations
                                            </label>
                                        </fieldset>
                                        <p class="description">Select which metadata fields to extract and store</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        Image Settings
                                    </th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="use_pexels_images" id="edit-use-pexels-images" value="1" />
                                            Use Pexels images instead of RSS images
                                        </label>
                                        <p class="description">
                                            When enabled, searches Pexels for relevant images using article keywords instead of using images from the RSS feed.<br>
                                            <strong>Requires Pexels API key</strong> (configure in Settings tab)
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <p>
                                <input type="submit" name="envirolink_edit_feed" class="button button-primary" value="Save Settings" />
                                <button type="button" class="button" id="cancel-edit-schedule">Cancel</button>
                            </p>
                        </form>
                    </div>
                </div>
            </div>

            <div id="articles-tab" class="tab-content" style="display: none;">
                <?php
                // Query all EnviroLink posts
                $args = array(
                    'post_type' => 'post',
                    'posts_per_page' => -1,
                    'meta_key' => 'envirolink_source_url',
                    'meta_compare' => 'EXISTS',
                    'post_status' => array('publish', 'draft', 'pending', 'private')
                );

                // Add search if provided
                if (!empty($search_query)) {
                    $args['s'] = $search_query;
                }

                // Apply same ordering as frontend
                if (get_option('envirolink_randomize_daily_order', 'no') === 'yes') {
                    $args['orderby'] = 'date';
                    $args['order'] = 'DESC';
                    // Note: Random ordering within same day is handled by frontend filter
                } else {
                    $args['orderby'] = 'date';
                    $args['order'] = 'DESC';
                }

                $articles = get_posts($args);
                $total_count = count($articles);
                ?>

                <div style="margin-bottom: 20px;">
                    <h3>All Articles (<?php echo $total_count; ?> total)</h3>

                    <!-- Search Form -->
                    <form method="get" action="" style="margin-bottom: 15px;">
                        <input type="hidden" name="page" value="envirolink-aggregator" />
                        <input type="text" name="article_search"
                               value="<?php echo esc_attr($search_query); ?>"
                               placeholder="Search by title..."
                               style="width: 300px; padding: 5px;" />
                        <button type="submit" class="button">Search</button>
                        <?php if (!empty($search_query)): ?>
                            <a href="<?php echo admin_url('admin.php?page=envirolink-aggregator'); ?>"
                               class="button" onclick="$('.nav-tab').removeClass('nav-tab-active'); $('[href=\'#articles\']').addClass('nav-tab-active'); $('.tab-content').hide(); $('#articles-tab').show(); return true;">
                                Clear Search
                            </a>
                        <?php endif; ?>
                    </form>
                </div>

                <?php if (empty($articles)): ?>
                    <p>No articles found.</p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">
                        <thead>
                            <tr>
                                <th style="width: 40px;">Image</th>
                                <th style="width: 120px;">Date</th>
                                <th>Headline</th>
                                <th style="width: 150px;">Source</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($articles as $article):
                                $source_name = get_post_meta($article->ID, 'envirolink_source_name', true);
                                $pubdate = get_post_meta($article->ID, 'envirolink_pubdate', true);
                                $has_thumbnail = has_post_thumbnail($article->ID);
                                $article_url = get_permalink($article->ID);

                                // Use pubdate if available, otherwise use post_date
                                $display_date = $pubdate ? date('M j, Y', strtotime($pubdate)) : date('M j, Y', strtotime($article->post_date));
                            ?>
                                <tr>
                                    <td style="text-align: center;">
                                        <?php if ($has_thumbnail): ?>
                                            <span style="font-size: 20px;" title="Has featured image">🖼️</span>
                                        <?php else: ?>
                                            <span style="color: #ccc;" title="No featured image">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($display_date); ?></td>
                                    <td>
                                        <a href="<?php echo esc_url($article_url); ?>"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           style="font-weight: 500;">
                                            <?php echo esc_html($article->post_title); ?>
                                        </a>
                                        <div class="row-actions" style="font-size: 12px; color: #666;">
                                            <span>
                                                <a href="<?php echo get_edit_post_link($article->ID); ?>">Edit</a>
                                            </span>
                                        </div>
                                    </td>
                                    <td><?php echo esc_html($source_name ?: '—'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Ontology Tab -->
            <div id="ontology-tab" class="tab-content" style="display: none;">
                <h3>Environmental News Ontology</h3>
                <p class="description">
                    Manage your standardized environmental taxonomy based on IPTC Media Topics and UN Sustainable Development Goals.
                    The ontology filters RSS feed tags to ensure only relevant environmental topics are applied to articles.
                </p>

                <?php
                $ontology_seeded = get_option('envirolink_ontology_seeded', false);
                $ontology_enabled = get_option('envirolink_ontology_enabled', 'no');
                $seed_date = get_option('envirolink_ontology_seed_date', '');
                $topics = $this->get_all_ontology_topics();
                ?>

                <div class="card" style="max-width: none; margin-top: 20px;">
                    <h3>Ontology Status</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Database Status</th>
                            <td>
                                <?php if ($ontology_seeded): ?>
                                    <span style="color: green;">✓ Seeded (<?php echo count($topics); ?> topics)</span>
                                    <?php if ($seed_date): ?>
                                        <br><small>Last seeded: <?php echo esc_html($seed_date); ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: orange;">⚠ Not seeded</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Filtering Status</th>
                            <td>
                                <?php if ($ontology_enabled === 'yes'): ?>
                                    <span style="color: green;">✓ Enabled</span> - RSS tags are filtered through ontology
                                <?php else: ?>
                                    <span style="color: gray;">○ Disabled</span> - Raw RSS tags are used (legacy mode)
                                <?php endif; ?>
                                <p class="description">
                                    Enable ontology filtering in the Settings tab to start using curated tags.
                                </p>
                            </td>
                        </tr>
                    </table>

                    <h3>Actions</h3>
                    <p>
                        <button type="button" id="seed-ontology-btn" class="button button-primary"
                                <?php echo $ontology_seeded ? 'disabled' : ''; ?>>
                            Seed Ontology Database
                        </button>
                        <span class="description">
                            Populate database with 40+ curated environmental topics from IPTC and UN SDGs.
                        </span>
                    </p>

                    <p>
                        <button type="button" id="clear-ontology-btn" class="button button-secondary"
                                <?php echo !$ontology_seeded ? 'disabled' : ''; ?>>
                            Clear Ontology Database
                        </button>
                        <span class="description">
                            Remove all ontology data (required before re-seeding).
                        </span>
                    </p>

                    <p>
                        <button type="button" id="retag-posts-btn" class="button button-secondary"
                                <?php echo (!$ontology_seeded || $ontology_enabled !== 'yes') ? 'disabled' : ''; ?>>
                            Re-tag All Existing Posts
                        </button>
                        <span class="description">
                            Apply ontology filtering to all existing articles and roundups. Articles get filtered RSS tags, roundups inherit tags from included articles (may take a while).
                        </span>
                    </p>

                    <div id="ontology-action-result" style="margin-top: 15px;"></div>
                </div>

                <?php if ($ontology_seeded && !empty($topics)): ?>
                    <div class="card" style="max-width: none; margin-top: 20px;">
                        <h3>Topic Taxonomy (<?php echo count($topics); ?> topics)</h3>
                        <p class="description">
                            This is the complete environmental news taxonomy. Topics are organized hierarchically and mapped to UN SDGs.
                        </p>

                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th style="width: 40px;">Level</th>
                                    <th style="width: 100px;">IPTC Code</th>
                                    <th>Topic</th>
                                    <th style="width: 200px;">UN SDGs</th>
                                    <th style="width: 80px;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                foreach ($topics as $topic):
                                    $topic_details = $this->get_topic_details($topic->id);
                                    $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $topic->level);
                                    $sdg_badges = '';
                                    if (!empty($topic_details->sdgs)) {
                                        $sdg_numbers = array_column($topic_details->sdgs, 'sdg_number');
                                        $sdg_badges = implode(', ', array_map(function($num) {
                                            return 'SDG ' . $num;
                                        }, $sdg_numbers));
                                    }
                                ?>
                                    <tr>
                                        <td><?php echo esc_html($topic->level); ?></td>
                                        <td><code><?php echo esc_html($topic->iptc_code); ?></code></td>
                                        <td>
                                            <?php echo $indent; ?>
                                            <strong><?php echo esc_html($topic->label); ?></strong>
                                            <?php if ($topic->definition): ?>
                                                <br><?php echo $indent; ?>
                                                <small style="color: #666;"><?php echo esc_html($topic->definition); ?></small>
                                            <?php endif; ?>
                                            <?php if (!empty($topic_details->aliases)): ?>
                                                <br><?php echo $indent; ?>
                                                <small style="color: #888;">
                                                    Aliases: <?php echo esc_html(implode(', ', array_column($topic_details->aliases, 'alias'))); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td><small><?php echo esc_html($sdg_badges); ?></small></td>
                                        <td>
                                            <span style="color: <?php echo $topic->status === 'active' ? 'green' : 'gray'; ?>;">
                                                <?php echo esc_html(ucfirst($topic->status)); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Tab switching
            $('.nav-tab').click(function(e) {
                e.preventDefault();
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $('.tab-content').hide();
                $($(this).attr('href') + '-tab').show();
            });

            // Auto-switch to Articles tab if searching
            <?php if (!empty($search_query)): ?>
                $('.nav-tab').removeClass('nav-tab-active');
                $('.nav-tab[href="#articles"]').addClass('nav-tab-active');
                $('.tab-content').hide();
                $('#articles-tab').show();
            <?php endif; ?>

            // Toggle Unsplash API key field visibility
            $('#roundup_auto_fetch_unsplash').change(function() {
                if ($(this).is(':checked')) {
                    $('#unsplash_api_key_wrapper').slideDown();
                } else {
                    $('#unsplash_api_key_wrapper').slideUp();
                }
            });

            // Load saved log from last run on page load
            function loadSavedLog() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: { action: 'envirolink_get_saved_log' },
                    success: function(response) {
                        if (response.success && response.data.log && response.data.log.length > 0) {
                            var logHtml = response.data.log.join('<br>');
                            $('#envirolink-log-content').html(logHtml);
                        }
                    }
                });
            }

            // Load saved log when page loads
            loadSavedLog();

            // Progress polling
            var progressInterval = null;

            function startProgressPolling() {
                if (progressInterval) clearInterval(progressInterval);

                $('#envirolink-progress-container').show();
                $('#envirolink-log-content').html(''); // Clear previous logs

                progressInterval = setInterval(function() {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: { action: 'envirolink_get_progress' },
                        success: function(response) {
                            if (response.success && response.data.active) {
                                var data = response.data;
                                $('#envirolink-progress-bar').css('width', data.percent + '%');
                                $('#envirolink-progress-percent').text(data.percent + '%');
                                $('#envirolink-progress-status').text(data.status);
                                $('#envirolink-progress-current').text(data.current);
                                $('#envirolink-progress-total').text(data.total);

                                // Update log
                                if (data.log && data.log.length > 0) {
                                    var logHtml = data.log.join('<br>');
                                    $('#envirolink-log-content').html(logHtml);

                                    // Auto-scroll to bottom if log is visible
                                    if ($('#envirolink-log-container').is(':visible')) {
                                        var logContainer = $('#envirolink-log-container')[0];
                                        logContainer.scrollTop = logContainer.scrollHeight;
                                    }
                                }
                            } else {
                                stopProgressPolling();
                            }
                        }
                    });
                }, 500); // Poll every 500ms
            }

            function stopProgressPolling() {
                if (progressInterval) {
                    clearInterval(progressInterval);
                    progressInterval = null;
                }
                setTimeout(function() {
                    $('#envirolink-progress-container').fadeOut();
                    // Don't reload saved log - keep the current log visible
                    // The log is already displayed and should persist
                }, 2000); // Hide after 2 seconds
            }

            // Run now button (all feeds)
            $('#run-now-btn').click(function() {
                var btn = $(this);
                var status = $('#run-now-status');

                btn.prop('disabled', true);
                status.html('');
                startProgressPolling();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: { action: 'envirolink_run_now' },
                    success: function(response) {
                        stopProgressPolling();
                        if (response.success) {
                            status.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                        } else {
                            status.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                        }
                        btn.prop('disabled', false);
                    },
                    error: function() {
                        stopProgressPolling();
                        status.html('<span style="color: red;">✗ Error occurred</span>');
                        btn.prop('disabled', false);
                    }
                });
            });

            // Run single feed button
            $('.run-feed-btn').click(function() {
                var btn = $(this);
                var feedIndex = btn.data('index');
                var feedName = btn.data('name');
                var icon = btn.find('.dashicons');

                btn.prop('disabled', true);
                icon.addClass('dashicons-update-spin');
                $('#run-now-status').html('');
                startProgressPolling();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'envirolink_run_feed',
                        feed_index: feedIndex
                    },
                    success: function(response) {
                        stopProgressPolling();
                        icon.removeClass('dashicons-update-spin');
                        if (response.success) {
                            // Show success feedback
                            btn.css('background-color', '#46b450');
                            setTimeout(function() {
                                btn.css('background-color', '');
                                btn.prop('disabled', false);
                            }, 2000);

                            // Update the main status display
                            $('#run-now-status').html('<span style="color: green;">✓ ' + feedName + ': ' + response.data.message + '</span>');
                        } else {
                            btn.prop('disabled', false);
                            $('#run-now-status').html('<span style="color: red;">✗ ' + feedName + ': ' + response.data.message + '</span>');
                        }
                    },
                    error: function() {
                        stopProgressPolling();
                        icon.removeClass('dashicons-update-spin');
                        btn.prop('disabled', false);
                        $('#run-now-status').html('<span style="color: red;">✗ Error updating ' + feedName + '</span>');
                    }
                });
            });

            // Update images button
            $('.update-images-btn').click(function() {
                var btn = $(this);
                var feedIndex = btn.data('index');
                var feedName = btn.data('name');
                var icon = btn.find('.dashicons');

                if (!confirm('This will re-download all images for "' + feedName + '" posts using high-resolution settings.\n\nThis will NOT run AI or change any content - only update images.\n\nContinue?')) {
                    return;
                }

                btn.prop('disabled', true);
                icon.addClass('dashicons-update-spin');
                $('#run-now-status').html('');
                startProgressPolling();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'envirolink_update_feed_images',
                        feed_index: feedIndex
                    },
                    success: function(response) {
                        stopProgressPolling();
                        icon.removeClass('dashicons-update-spin');
                        if (response.success) {
                            // Show success feedback
                            btn.css('background-color', '#27ae60');
                            setTimeout(function() {
                                btn.css('background-color', '#9b59b6');
                                btn.prop('disabled', false);
                            }, 2000);

                            // Update the main status display
                            $('#run-now-status').html('<span style="color: green;">✓ ' + feedName + ' images: ' + response.data.message + '</span>');
                        } else {
                            btn.prop('disabled', false);
                            $('#run-now-status').html('<span style="color: red;">✗ ' + feedName + ' images: ' + response.data.message + '</span>');
                        }
                    },
                    error: function() {
                        stopProgressPolling();
                        icon.removeClass('dashicons-update-spin');
                        btn.prop('disabled', false);
                        $('#run-now-status').html('<span style="color: red;">✗ Error updating images for ' + feedName + '</span>');
                    }
                });
            });

            // Edit feed settings modal
            $('.edit-schedule-btn').click(function() {
                var index = $(this).data('index');
                var scheduleType = $(this).data('schedule-type');
                var scheduleTimes = $(this).data('schedule-times');
                var includeAuthor = $(this).data('include-author') == '1';
                var includePubdate = $(this).data('include-pubdate') == '1';
                var includeTopicTags = $(this).data('include-topic-tags') == '1';
                var includeLocations = $(this).data('include-locations') == '1';
                var usePexelsImages = $(this).data('use-pexels-images') == '1';

                $('#edit-feed-index').val(index);
                $('#edit-schedule-type').val(scheduleType);
                $('#edit-schedule-times').val(scheduleTimes);
                $('#edit-include-author').prop('checked', includeAuthor);
                $('#edit-include-pubdate').prop('checked', includePubdate);
                $('#edit-include-topic-tags').prop('checked', includeTopicTags);
                $('#edit-include-locations').prop('checked', includeLocations);
                $('#edit-use-pexels-images').prop('checked', usePexelsImages);

                $('#edit-schedule-modal').fadeIn();
            });

            $('#cancel-edit-schedule').click(function() {
                $('#edit-schedule-modal').fadeOut();
            });

            // Close modal on background click
            $('#edit-schedule-modal').click(function(e) {
                if (e.target === this) {
                    $(this).fadeOut();
                }
            });

            // Toggle log viewer
            $('#toggle-log-btn').click(function() {
                var btn = $(this);
                var logContainer = $('#envirolink-log-container');

                if (logContainer.is(':visible')) {
                    logContainer.slideUp();
                    btn.html('<span class="dashicons dashicons-visibility" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle; margin-top: 2px;"></span> Show Detailed Log');
                } else {
                    logContainer.slideDown();
                    btn.html('<span class="dashicons dashicons-hidden" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle; margin-top: 2px;"></span> Hide Detailed Log');
                    // Auto-scroll to bottom
                    setTimeout(function() {
                        logContainer[0].scrollTop = logContainer[0].scrollHeight;
                    }, 100);
                }
            });

            // Fix post dates button
            $('#fix-dates-btn').click(function() {
                if (!confirm('This will sync all post dates to match their RSS publication dates.\n\nThis will fix the post ordering on your homepage.\n\nContinue?')) {
                    return;
                }

                var btn = $(this);
                var status = $('#run-now-status');

                btn.prop('disabled', true);
                status.html('');
                startProgressPolling();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: { action: 'envirolink_fix_post_dates' },
                    success: function(response) {
                        stopProgressPolling();
                        if (response.success) {
                            status.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                            // Show reload prompt
                            if (confirm(response.data.message + '\n\nReload the page to see the new order?')) {
                                location.reload();
                            }
                        } else {
                            status.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                        }
                        btn.prop('disabled', false);
                    },
                    error: function() {
                        stopProgressPolling();
                        status.html('<span style="color: red;">✗ Error occurred</span>');
                        btn.prop('disabled', false);
                    }
                });
            });

            // Cleanup duplicates button
            $('#cleanup-duplicates-btn').click(function() {
                if (!confirm('This will scan for duplicate articles and DELETE older versions.\n\nThe newest version of each duplicate will be kept.\n\nThis action CANNOT be undone!\n\nContinue?')) {
                    return;
                }

                var btn = $(this);
                var status = $('#run-now-status');

                btn.prop('disabled', true);
                status.html('');
                startProgressPolling();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: { action: 'envirolink_cleanup_duplicates' },
                    success: function(response) {
                        stopProgressPolling();
                        if (response.success) {
                            status.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                            // Show reload prompt if duplicates were deleted
                            if (response.data.message.indexOf('deleted') > -1) {
                                if (confirm(response.data.message + '\n\nReload the page?')) {
                                    location.reload();
                                }
                            }
                        } else {
                            status.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                        }
                        btn.prop('disabled', false);
                    },
                    error: function() {
                        stopProgressPolling();
                        status.html('<span style="color: red;">✗ Error occurred</span>');
                        btn.prop('disabled', false);
                    }
                });
            });

            // Categorize posts button
            $('#categorize-posts-btn').click(function() {
                if (!confirm('This will add the "newsfeed" category to all posts that were aggregated from RSS feeds.\n\nExisting categories will be preserved.\n\nContinue?')) {
                    return;
                }

                var btn = $(this);
                var status = $('#run-now-status');

                btn.prop('disabled', true);
                status.html('');
                startProgressPolling();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: { action: 'envirolink_categorize_posts' },
                    success: function(response) {
                        stopProgressPolling();
                        if (response.success) {
                            status.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                        } else {
                            status.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                        }
                        btn.prop('disabled', false);
                    },
                    error: function() {
                        stopProgressPolling();
                        status.html('<span style="color: red;">✗ Error occurred</span>');
                        btn.prop('disabled', false);
                    }
                });
            });

            // Update authors button
            $('#update-authors-btn').click(function() {
                if (!confirm('This will change the author of all EnviroLink posts to "EnviroLink Editor".\n\nThis will create the EnviroLink Editor user if it doesn\'t exist.\n\nContinue?')) {
                    return;
                }

                var btn = $(this);
                var status = $('#run-now-status');

                btn.prop('disabled', true);
                status.html('');
                startProgressPolling();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: { action: 'envirolink_update_authors' },
                    success: function(response) {
                        stopProgressPolling();
                        if (response.success) {
                            status.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                        } else {
                            status.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                        }
                        btn.prop('disabled', false);
                    },
                    error: function() {
                        stopProgressPolling();
                        status.html('<span style="color: red;">✗ Error occurred</span>');
                        btn.prop('disabled', false);
                    }
                });
            });

            // Generate Roundup Now button
            $('#generate-roundup-btn').click(function() {
                if (!confirm('Generate daily editorial roundup now?\n\nThis will:\n1. Run the feed aggregator to get latest articles\n2. Gather posts from past 24 hours\n3. Generate AI editorial content\n4. Auto-publish the roundup post\n\nThis may take 1-2 minutes.\n\nContinue?')) {
                    return;
                }

                var btn = $(this);
                var status = $('#run-now-status');

                btn.prop('disabled', true).text('Generating...');
                status.html('');
                startProgressPolling();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: { action: 'envirolink_generate_roundup' },
                    timeout: 180000, // 3 minutes timeout
                    success: function(response) {
                        stopProgressPolling();
                        if (response.success) {
                            status.html(
                                '<span style="color: green;">✓ ' + response.data.message + '</span><br>' +
                                '<a href="' + response.data.post_url + '" target="_blank" class="button" style="margin-top: 10px;">View Roundup Post</a>'
                            );
                        } else {
                            status.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                        }
                        btn.prop('disabled', false).text('Generate Roundup Now');
                    },
                    error: function() {
                        stopProgressPolling();
                        status.html('<span style="color: red;">✗ Error generating roundup. Check error logs for details.</span>');
                        btn.prop('disabled', false).text('Generate Roundup Now');
                    }
                });
            });

            // Check for updates button
            $('#check-updates-btn').click(function() {
                var btn = $(this);
                var statusDiv = $('#update-check-status');

                btn.prop('disabled', true).text('Checking...');
                statusDiv.html('<span style="color: #666;">Checking for updates...</span>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: { action: 'envirolink_check_updates' },
                    success: function(response) {
                        btn.prop('disabled', false).text('Check for Updates');

                        if (response.success) {
                            if (response.data.update_available) {
                                // Update available - redirect to WordPress updates page
                                statusDiv.html(
                                    '<span style="color: #2271b1; font-weight: bold;">✓ ' + response.data.message + '</span><br>' +
                                    '<a href="' + response.data.update_url + '" class="button button-primary" style="margin-top: 10px;">Update Now</a> ' +
                                    '<a href="https://github.com/jknauernever/envirolink-news/releases/tag/v' + response.data.new_version + '" target="_blank" class="button" style="margin-top: 10px;">View Release Notes</a>'
                                );
                            } else {
                                // Up to date
                                statusDiv.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                            }
                        } else {
                            statusDiv.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Check for Updates');
                        statusDiv.html('<span style="color: red;">✗ Error checking for updates</span>');
                    }
                });
            });

            // WordPress Media Uploader for Roundup Images
            var roundupMediaFrame;
            var roundupImages = <?php echo json_encode(get_option('envirolink_roundup_images', array())); ?>;

            $('#envirolink_add_roundup_image').click(function(e) {
                e.preventDefault();

                // If media frame already exists, reopen it
                if (roundupMediaFrame) {
                    roundupMediaFrame.open();
                    return;
                }

                // Create new media frame
                roundupMediaFrame = wp.media({
                    title: 'Select Roundup Featured Images',
                    button: {
                        text: 'Add to Collection'
                    },
                    multiple: true  // Allow multiple selection
                });

                // When images are selected
                roundupMediaFrame.on('select', function() {
                    var selection = roundupMediaFrame.state().get('selection');

                    selection.each(function(attachment) {
                        attachment = attachment.toJSON();
                        if (roundupImages.indexOf(attachment.id) === -1) {
                            roundupImages.push(attachment.id);
                        }
                    });

                    // Update hidden input
                    $('#roundup_images').val(JSON.stringify(roundupImages));

                    // Reload page to show new images (WordPress way)
                    $('form').first().submit();
                });

                // Open media frame
                roundupMediaFrame.open();
            });

            // Remove image from collection
            $(document).on('click', '.envirolink-remove-image', function(e) {
                e.preventDefault();
                var imageId = parseInt($(this).data('image-id'));
                var index = roundupImages.indexOf(imageId);

                if (index > -1) {
                    roundupImages.splice(index, 1);
                    $('#roundup_images').val(JSON.stringify(roundupImages));

                    // Remove visual element
                    $(this).closest('div').fadeOut(300, function() {
                        $(this).remove();
                        // Update count
                        var count = roundupImages.length;
                        $('.description strong').text('Tip:');
                        $('.description').last().html('<strong>Tip:</strong> Upload high-quality environmental images (nature, earth, climate themes). Currently ' + count + ' image(s) in collection.');
                    });
                }
            });

            // Ontology Management Buttons
            $('#seed-ontology-btn').click(function() {
                if (!confirm('This will populate the database with 40+ environmental topics from IPTC and UN SDGs. Continue?')) {
                    return;
                }

                var $btn = $(this);
                var $result = $('#ontology-action-result');

                $btn.prop('disabled', true).text('Seeding...');
                $result.html('<div class="notice notice-info"><p>Seeding ontology database...</p></div>');

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'envirolink_seed_ontology',
                        nonce: '<?php echo wp_create_nonce('envirolink_admin_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $result.html('<div class="notice notice-success"><p><strong>Success!</strong> ' +
                                response.data.message + ' (' + response.data.topics_count + ' topics)</p></div>');
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        } else {
                            $result.html('<div class="notice notice-error"><p><strong>Error:</strong> ' +
                                (response.data.message || 'Failed to seed ontology') + '</p></div>');
                            $btn.prop('disabled', false).text('Seed Ontology Database');
                        }
                    },
                    error: function() {
                        $result.html('<div class="notice notice-error"><p><strong>Error:</strong> AJAX request failed</p></div>');
                        $btn.prop('disabled', false).text('Seed Ontology Database');
                    }
                });
            });

            $('#clear-ontology-btn').click(function() {
                if (!confirm('WARNING: This will delete ALL ontology data. Are you sure?')) {
                    return;
                }

                var $btn = $(this);
                var $result = $('#ontology-action-result');

                $btn.prop('disabled', true).text('Clearing...');
                $result.html('<div class="notice notice-info"><p>Clearing ontology database...</p></div>');

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'envirolink_clear_ontology',
                        nonce: '<?php echo wp_create_nonce('envirolink_admin_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $result.html('<div class="notice notice-success"><p><strong>Success!</strong> ' +
                                response.data.message + '</p></div>');
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        } else {
                            $result.html('<div class="notice notice-error"><p><strong>Error:</strong> ' +
                                (response.data.message || 'Failed to clear ontology') + '</p></div>');
                            $btn.prop('disabled', false).text('Clear Ontology Database');
                        }
                    },
                    error: function() {
                        $result.html('<div class="notice notice-error"><p><strong>Error:</strong> AJAX request failed</p></div>');
                        $btn.prop('disabled', false).text('Clear Ontology Database');
                    }
                });
            });

            $('#retag-posts-btn').click(function() {
                if (!confirm('This will re-tag all EnviroLink posts using the ontology. This may take a while. Continue?')) {
                    return;
                }

                var $btn = $(this);
                var $result = $('#ontology-action-result');

                $btn.prop('disabled', true).text('Re-tagging...');
                $result.html('<div class="notice notice-info"><p>Re-tagging all posts with ontology filters...</p></div>');

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'envirolink_retag_posts',
                        nonce: '<?php echo wp_create_nonce('envirolink_admin_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            var message = 'Updated ' + response.data.updated + ' articles';
                            if (response.data.roundups_updated) {
                                message += ', ' + response.data.roundups_updated + ' roundups';
                            }
                            message += ', skipped ' + response.data.skipped + ' (Total: ' + response.data.total + ')';

                            $result.html('<div class="notice notice-success"><p><strong>Success!</strong> ' + message + '</p></div>');
                            $btn.prop('disabled', false).text('Re-tag All Existing Posts');
                        } else {
                            $result.html('<div class="notice notice-error"><p><strong>Error:</strong> ' +
                                (response.data.message || 'Failed to re-tag posts') + '</p></div>');
                            $btn.prop('disabled', false).text('Re-tag All Existing Posts');
                        }
                    },
                    error: function() {
                        $result.html('<div class="notice notice-error"><p><strong>Error:</strong> AJAX request failed</p></div>');
                        $btn.prop('disabled', false).text('Re-tag All Existing Posts');
                    }
                });
            });
        });
        </script>
        
        <style>
        .tab-content {
            background: #fff;
            padding: 20px;
            border: 1px solid #ccc;
            border-top: none;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .dashicons-update-spin {
            animation: spin 1s linear infinite;
            display: inline-block;
        }
        </style>
        <?php
    }
    
    /**
     * AJAX: Run aggregator now
     */
    public function ajax_run_now() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        try {
            $result = $this->fetch_and_process_feeds(true); // Pass true for manual run

            if ($result['success']) {
                wp_send_json_success(array('message' => $result['message']));
            } else {
                wp_send_json_error(array('message' => $result['message']));
            }
        } catch (Exception $e) {
            wp_send_json_error(array('message' => 'Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine()));
        } catch (Error $e) {
            wp_send_json_error(array('message' => 'Fatal Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine()));
        }
    }

    /**
     * AJAX: Run aggregator for a single feed
     */
    public function ajax_run_feed() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        $feed_index = isset($_POST['feed_index']) ? intval($_POST['feed_index']) : -1;

        if ($feed_index < 0) {
            wp_send_json_error(array('message' => 'Invalid feed index'));
            return;
        }

        try {
            $result = $this->fetch_and_process_feeds(true, $feed_index); // Pass true for manual run and feed index

            if ($result['success']) {
                wp_send_json_success(array('message' => $result['message']));
            } else {
                wp_send_json_error(array('message' => $result['message']));
            }
        } catch (Exception $e) {
            wp_send_json_error(array('message' => 'Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine()));
        } catch (Error $e) {
            wp_send_json_error(array('message' => 'Fatal Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine()));
        }
    }

    /**
     * AJAX: Update all images for a specific feed
     */
    public function ajax_update_feed_images() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        $feed_index = isset($_POST['feed_index']) ? intval($_POST['feed_index']) : -1;

        if ($feed_index < 0) {
            wp_send_json_error(array('message' => 'Invalid feed index'));
            return;
        }

        try {
            $result = $this->update_feed_images($feed_index);

            if ($result['success']) {
                wp_send_json_success(array('message' => $result['message']));
            } else {
                wp_send_json_error(array('message' => $result['message']));
            }
        } catch (Exception $e) {
            wp_send_json_error(array('message' => 'Error: ' . $e->getMessage()));
        } catch (Error $e) {
            wp_send_json_error(array('message' => 'Fatal Error: ' . $e->getMessage()));
        }
    }

    /**
     * AJAX: Get current progress
     */
    public function ajax_get_progress() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        $progress = get_transient('envirolink_progress');
        if ($progress === false) {
            // No progress data
            wp_send_json_success(array('active' => false));
        } else {
            wp_send_json_success(array_merge(array('active' => true), $progress));
        }
    }

    /**
     * AJAX: Get saved log from last run
     */
    public function ajax_get_saved_log() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        $saved_log = get_option('envirolink_last_run_log', array());
        wp_send_json_success(array('log' => $saved_log));
    }

    /**
     * AJAX: Fix post dates to match RSS publication dates
     */
    public function ajax_fix_post_dates() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        try {
            $result = $this->fix_post_dates();

            if ($result['success']) {
                wp_send_json_success(array('message' => $result['message']));
            } else {
                wp_send_json_error(array('message' => $result['message']));
            }
        } catch (Exception $e) {
            wp_send_json_error(array('message' => 'Error: ' . $e->getMessage()));
        } catch (Error $e) {
            wp_send_json_error(array('message' => 'Fatal Error: ' . $e->getMessage()));
        }
    }

    /**
     * AJAX: Cleanup duplicate articles
     */
    public function ajax_cleanup_duplicates() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        try {
            $result = $this->cleanup_duplicates();

            if ($result['success']) {
                wp_send_json_success(array('message' => $result['message']));
            } else {
                wp_send_json_error(array('message' => $result['message']));
            }
        } catch (Exception $e) {
            wp_send_json_error(array('message' => 'Error: ' . $e->getMessage()));
        } catch (Error $e) {
            wp_send_json_error(array('message' => 'Fatal Error: ' . $e->getMessage()));
        }
    }

    /**
     * AJAX: Categorize all aggregated posts
     */
    public function ajax_categorize_posts() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        try {
            $result = $this->categorize_posts();

            if ($result['success']) {
                wp_send_json_success(array('message' => $result['message']));
            } else {
                wp_send_json_error(array('message' => $result['message']));
            }
        } catch (Exception $e) {
            wp_send_json_error(array('message' => 'Error: ' . $e->getMessage()));
        } catch (Error $e) {
            wp_send_json_error(array('message' => 'Fatal Error: ' . $e->getMessage()));
        }
    }

    /**
     * AJAX: Update all post authors to EnviroLink Editor
     */
    public function ajax_update_authors() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        try {
            $result = $this->update_all_authors();

            if ($result['success']) {
                wp_send_json_success(array('message' => $result['message']));
            } else {
                wp_send_json_error(array('message' => $result['message']));
            }
        } catch (Exception $e) {
            wp_send_json_error(array('message' => 'Error: ' . $e->getMessage()));
        } catch (Error $e) {
            wp_send_json_error(array('message' => 'Fatal Error: ' . $e->getMessage()));
        }
    }

    /**
     * AJAX: Check for plugin updates
     */
    public function ajax_check_updates() {
        global $envirolink_update_checker;

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        try {
            if (!$envirolink_update_checker) {
                wp_send_json_error(array('message' => 'Update checker not initialized'));
                return;
            }

            // Clear cache to force fresh check from GitHub (if method exists)
            if (method_exists($envirolink_update_checker, 'resetUpdateCache')) {
                $envirolink_update_checker->resetUpdateCache();
            }

            // Manually check for updates
            $update = $envirolink_update_checker->checkForUpdates();

            if ($update !== null && version_compare($update->version, ENVIROLINK_VERSION, '>')) {
                // Update available
                $message = sprintf(
                    'Update available: v%s → v%s',
                    ENVIROLINK_VERSION,
                    $update->version
                );
                wp_send_json_success(array(
                    'message' => $message,
                    'update_available' => true,
                    'current_version' => ENVIROLINK_VERSION,
                    'new_version' => $update->version,
                    'update_url' => admin_url('plugins.php')
                ));
            } else {
                // No update available
                wp_send_json_success(array(
                    'message' => 'Plugin is up to date (v' . ENVIROLINK_VERSION . ')',
                    'update_available' => false,
                    'current_version' => ENVIROLINK_VERSION
                ));
            }
        } catch (Exception $e) {
            wp_send_json_error(array('message' => 'Error checking for updates: ' . $e->getMessage()));
        } catch (Error $e) {
            wp_send_json_error(array('message' => 'Fatal error checking for updates: ' . $e->getMessage()));
        }
    }

    /**
     * AJAX: Generate daily roundup now (manual trigger)
     */
    public function ajax_generate_roundup() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        try {
            // Call the generate_daily_roundup function with manual_run = true to bypass enabled check
            $this->generate_daily_roundup(true);

            // Check if it succeeded by looking for the most recent roundup post
            $recent_roundup = get_posts(array(
                'post_type' => 'post',
                'posts_per_page' => 1,
                'meta_query' => array(
                    array(
                        'key' => 'envirolink_is_roundup',
                        'value' => 'yes',
                        'compare' => '='
                    )
                ),
                'orderby' => 'date',
                'order' => 'DESC'
            ));

            if (!empty($recent_roundup)) {
                $roundup_post = $recent_roundup[0];
                $article_count = get_post_meta($roundup_post->ID, 'envirolink_roundup_article_count', true);
                $post_url = get_permalink($roundup_post->ID);

                wp_send_json_success(array(
                    'message' => 'Daily roundup generated successfully! Included ' . $article_count . ' articles.',
                    'post_url' => $post_url,
                    'post_id' => $roundup_post->ID
                ));
            } else {
                wp_send_json_error(array('message' => 'Roundup generation completed but no post was created. Check error logs for details.'));
            }
        } catch (Exception $e) {
            wp_send_json_error(array('message' => 'Error generating roundup: ' . $e->getMessage()));
        }
    }

    /**
     * AJAX: Generate roundup via system cron (public endpoint with secret key)
     * This allows system cron to trigger roundup generation without WordPress CRON
     */
    public function ajax_cron_roundup() {
        // Verify secret key
        $provided_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        $secret_key = get_option('envirolink_cron_secret_key', '');

        if (empty($secret_key)) {
            error_log('EnviroLink: Cron roundup failed - secret key not configured');
            wp_send_json_error(array('message' => 'Secret key not configured'));
            return;
        }

        if ($provided_key !== $secret_key) {
            error_log('EnviroLink: Cron roundup failed - invalid secret key');
            wp_send_json_error(array('message' => 'Invalid secret key'));
            return;
        }

        // Check if enabled
        if (get_option('envirolink_daily_roundup_enabled', 'no') !== 'yes') {
            error_log('EnviroLink: Cron roundup skipped - feature disabled');
            wp_send_json_error(array('message' => 'Daily roundup is disabled'));
            return;
        }

        // Generate the roundup (manual_run = false so it runs feeds first)
        try {
            $this->generate_daily_roundup(false);

            // Check if it succeeded
            $recent_roundup = get_posts(array(
                'post_type' => 'post',
                'posts_per_page' => 1,
                'meta_query' => array(
                    array(
                        'key' => 'envirolink_is_roundup',
                        'value' => 'yes',
                        'compare' => '='
                    )
                ),
                'orderby' => 'date',
                'order' => 'DESC'
            ));

            if (!empty($recent_roundup)) {
                $roundup_post = $recent_roundup[0];
                $article_count = get_post_meta($roundup_post->ID, 'envirolink_roundup_article_count', true);
                $post_url = get_permalink($roundup_post->ID);

                error_log('EnviroLink: Cron roundup succeeded (Post ID: ' . $roundup_post->ID . ')');
                wp_send_json_success(array(
                    'message' => 'Daily roundup generated successfully! Included ' . $article_count . ' articles.',
                    'post_url' => $post_url,
                    'post_id' => $roundup_post->ID
                ));
            } else {
                error_log('EnviroLink: Cron roundup completed but no post created');
                wp_send_json_error(array('message' => 'Roundup generation completed but no post was created'));
            }
        } catch (Exception $e) {
            error_log('EnviroLink: Cron roundup exception: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Error generating roundup: ' . $e->getMessage()));
        }
    }

    /**
     * Cleanup duplicate articles
     * Uses multiple strategies to find duplicates:
     * 1. Same source URL (exact duplicates from RSS)
     * 2. Identical titles
     * 3. Very similar titles (85%+ similarity)
     */
    private function cleanup_duplicates() {
        global $wpdb;

        $this->clear_progress();
        $this->update_progress(array(
            'status' => 'running',
            'message' => 'Scanning for duplicate articles...',
            'percent' => 0
        ));

        // Get all EnviroLink posts (including scheduled posts!)
        $args = array(
            'post_type' => 'post',
            'posts_per_page' => -1,
            'meta_key' => 'envirolink_source_url',
            'meta_compare' => 'EXISTS',
            'post_status' => 'any' // Check ALL statuses: publish, future, draft, pending, private
        );

        $posts = get_posts($args);
        $total_posts = count($posts);

        $this->log_message('Found ' . $total_posts . ' EnviroLink posts to check');
        $this->log_message('Using smart duplicate detection: source URL + fuzzy title matching');

        $duplicates_found = 0;
        $deleted_count = 0;
        $processed_ids = array(); // Track which posts we've already processed

        // Strategy 1: Group by source URL (exact duplicates from same RSS article)
        $this->log_message('');
        $this->log_message('Step 1: Checking for posts with identical source URLs...');
        $source_url_groups = array();

        foreach ($posts as $post) {
            if (in_array($post->ID, $processed_ids)) {
                continue; // Already processed
            }

            $source_url = get_post_meta($post->ID, 'envirolink_source_url', true);
            if (empty($source_url)) {
                continue;
            }

            if (!isset($source_url_groups[$source_url])) {
                $source_url_groups[$source_url] = array();
            }
            $source_url_groups[$source_url][] = $post;
        }

        foreach ($source_url_groups as $url => $group) {
            if (count($group) > 1) {
                $duplicates_found++;

                // Sort by post date (newest first)
                usort($group, function($a, $b) {
                    return strtotime($b->post_date) - strtotime($a->post_date);
                });

                $keep = $group[0];
                $this->log_message('Found ' . count($group) . ' posts with same source URL');
                $this->log_message('  → Keeping: "' . $keep->post_title . '" (ID ' . $keep->ID . ', ' . $keep->post_date . ')');

                // Mark keeper as processed
                $processed_ids[] = $keep->ID;

                // Delete the rest
                for ($i = 1; $i < count($group); $i++) {
                    $delete = $group[$i];
                    $this->log_message('  → Deleting: "' . $delete->post_title . '" (ID ' . $delete->ID . ')');

                    $result = wp_delete_post($delete->ID, true);
                    if ($result) {
                        $deleted_count++;
                        $processed_ids[] = $delete->ID;
                    } else {
                        $this->log_message('    ✗ Failed to delete post ID ' . $delete->ID);
                    }
                }
            }
        }

        // Strategy 2 & 3: Check for similar titles among remaining posts
        $this->log_message('');
        $this->log_message('Step 2: Checking for posts with similar titles...');

        $remaining_posts = array();
        foreach ($posts as $post) {
            if (!in_array($post->ID, $processed_ids)) {
                $remaining_posts[] = $post;
            }
        }

        $this->log_message('Checking ' . count($remaining_posts) . ' remaining posts for title similarity');

        // Compare each post with every other post
        for ($i = 0; $i < count($remaining_posts); $i++) {
            $post_a = $remaining_posts[$i];

            if (in_array($post_a->ID, $processed_ids)) {
                continue;
            }

            $duplicates = array($post_a);
            $title_a = strtolower(trim($post_a->post_title));

            for ($j = $i + 1; $j < count($remaining_posts); $j++) {
                $post_b = $remaining_posts[$j];

                if (in_array($post_b->ID, $processed_ids)) {
                    continue;
                }

                $title_b = strtolower(trim($post_b->post_title));

                // Check if titles are similar
                $similarity = $this->calculate_title_similarity($title_a, $title_b);

                if ($similarity >= 85) { // 85% or more similar
                    $duplicates[] = $post_b;
                    $this->log_message('Found similar titles (' . round($similarity) . '% match):');
                    $this->log_message('  • "' . $post_a->post_title . '"');
                    $this->log_message('  • "' . $post_b->post_title . '"');
                }
            }

            // If we found duplicates, keep the newest
            if (count($duplicates) > 1) {
                $duplicates_found++;

                // Sort by post date (newest first)
                usort($duplicates, function($a, $b) {
                    return strtotime($b->post_date) - strtotime($a->post_date);
                });

                $keep = $duplicates[0];
                $this->log_message('  → Keeping: "' . $keep->post_title . '" (ID ' . $keep->ID . ')');
                $processed_ids[] = $keep->ID;

                // Delete the rest
                for ($k = 1; $k < count($duplicates); $k++) {
                    $delete = $duplicates[$k];
                    $this->log_message('  → Deleting: "' . $delete->post_title . '" (ID ' . $delete->ID . ')');

                    $result = wp_delete_post($delete->ID, true);
                    if ($result) {
                        $deleted_count++;
                        $processed_ids[] = $delete->ID;
                    } else {
                        $this->log_message('    ✗ Failed to delete post ID ' . $delete->ID);
                    }
                }
            }
        }

        $this->update_progress(array(
            'status' => 'complete',
            'message' => 'Cleanup complete',
            'percent' => 100
        ));

        if ($deleted_count > 0) {
            $message = "Cleanup complete! Found {$duplicates_found} sets of duplicates and deleted {$deleted_count} duplicate posts.";
            $this->log_message('');
            $this->log_message($message);
        } else {
            $message = "No duplicates found. All articles are unique!";
            $this->log_message($message);
        }

        $this->clear_progress();

        return array(
            'success' => true,
            'message' => $message
        );
    }

    /**
     * Categorize all aggregated posts
     * Adds "newsfeed" category to all RSS aggregated posts
     * Adds "Featured" category to all daily roundup posts
     */
    private function categorize_posts() {
        $this->clear_progress();
        $this->log_message('Starting bulk categorization...');

        // Get or create "newsfeed" category
        $newsfeed_cat = get_category_by_slug('newsfeed');
        if (!$newsfeed_cat) {
            $this->log_message('Creating "newsfeed" category...');
            $newsfeed_id = wp_insert_term('newsfeed', 'category', array(
                'slug' => 'newsfeed',
                'description' => 'News articles aggregated from RSS feeds'
            ));

            if (is_wp_error($newsfeed_id)) {
                $this->log_message('Error creating newsfeed category: ' . $newsfeed_id->get_error_message());
                return array('success' => false, 'message' => 'Failed to create newsfeed category');
            }
            $newsfeed_id = $newsfeed_id['term_id'];
            $this->log_message('✓ Created "newsfeed" category (ID: ' . $newsfeed_id . ')');
        } else {
            $newsfeed_id = $newsfeed_cat->term_id;
            $this->log_message('✓ Found existing "newsfeed" category (ID: ' . $newsfeed_id . ')');
        }

        // Get or create "Featured" category
        $featured_cat = get_category_by_slug('featured');
        if (!$featured_cat) {
            $this->log_message('Creating "Featured" category...');
            $featured_id = wp_insert_term('Featured', 'category', array(
                'slug' => 'featured',
                'description' => 'Featured daily editorial roundups'
            ));

            if (is_wp_error($featured_id)) {
                $this->log_message('Error creating Featured category: ' . $featured_id->get_error_message());
                return array('success' => false, 'message' => 'Failed to create Featured category');
            }
            $featured_id = $featured_id['term_id'];
            $this->log_message('✓ Created "Featured" category (ID: ' . $featured_id . ')');
        } else {
            $featured_id = $featured_cat->term_id;
            $this->log_message('✓ Found existing "Featured" category (ID: ' . $featured_id . ')');
        }

        // Get all EnviroLink posts (both RSS aggregated AND roundups)
        // Strategy: Get RSS posts + roundup posts separately, then merge

        // Get RSS-aggregated posts (including scheduled posts!)
        $rss_posts = get_posts(array(
            'post_type' => 'post',
            'posts_per_page' => -1,
            'meta_key' => 'envirolink_source_url',
            'meta_compare' => 'EXISTS',
            'post_status' => 'any' // Check ALL statuses: publish, future, draft, pending, private
        ));

        // Get roundup posts (with metadata, including scheduled!)
        $roundup_posts_meta = get_posts(array(
            'post_type' => 'post',
            'posts_per_page' => -1,
            'meta_key' => 'envirolink_is_roundup',
            'meta_value' => 'yes',
            'meta_compare' => '=',
            'post_status' => 'any' // Check ALL statuses: publish, future, draft, pending, private
        ));

        // Get roundup posts (by title pattern - for older posts without metadata, including scheduled!)
        $roundup_posts_title = get_posts(array(
            'post_type' => 'post',
            'posts_per_page' => -1,
            's' => 'Daily Environmental News Roundup',
            'post_status' => 'any' // Check ALL statuses: publish, future, draft, pending, private
        ));

        // Merge all posts and remove duplicates
        $all_post_ids = array();
        $posts = array();

        foreach (array_merge($rss_posts, $roundup_posts_meta, $roundup_posts_title) as $post) {
            if (!in_array($post->ID, $all_post_ids)) {
                $all_post_ids[] = $post->ID;
                $posts[] = $post;
            }
        }

        $total_posts = count($posts);

        if ($total_posts == 0) {
            $this->log_message('No EnviroLink posts found');
            return array('success' => true, 'message' => 'No posts to categorize');
        }

        $this->log_message('Found ' . $total_posts . ' posts to categorize (' . count($rss_posts) . ' RSS, ' . (count($roundup_posts_meta) + count($roundup_posts_title)) . ' roundups)');

        $newsfeed_count = 0;
        $featured_count = 0;
        $skipped_count = 0;

        foreach ($posts as $index => $post) {
            $progress_percent = floor((($index + 1) / $total_posts) * 100);
            $this->update_progress(array(
                'percent' => $progress_percent,
                'current' => $index + 1,
                'total' => $total_posts,
                'status' => 'Categorizing posts...'
            ));

            $this->log_message('Processing: ' . $post->post_title);

            // Check if this is a daily roundup (by metadata OR title pattern)
            $is_roundup = get_post_meta($post->ID, 'envirolink_is_roundup', true) === 'yes';

            // Fallback: Detect roundups by title pattern (for older posts without metadata)
            if (!$is_roundup && stripos($post->post_title, 'Daily Environmental News Roundup') !== false) {
                $is_roundup = true;
                $this->log_message('  → Detected as roundup by title pattern');
            }

            // Get current categories
            $current_cats = wp_get_post_categories($post->ID);

            // Determine which categories to add
            $cats_to_add = array();
            $added_labels = array();

            if ($is_roundup) {
                // Daily roundups get "Featured" category ONLY
                if (!in_array($featured_id, $current_cats)) {
                    $cats_to_add[] = $featured_id;
                    $added_labels[] = 'Featured';
                }
            } else {
                // RSS-aggregated posts get "newsfeed" category ONLY
                if (!in_array($newsfeed_id, $current_cats)) {
                    $cats_to_add[] = $newsfeed_id;
                    $added_labels[] = 'newsfeed';
                }
            }

            // Get WordPress default "Uncategorized" category ID (usually 1)
            $uncategorized_id = get_option('default_category');

            // Check if we need to update this post
            $has_uncategorized = in_array($uncategorized_id, $current_cats);
            $needs_update = !empty($cats_to_add) || $has_uncategorized;

            if (!$needs_update) {
                $this->log_message('  → Already categorized, skipping');
                $skipped_count++;
                continue;
            }

            // Merge with existing categories (preserve them), but remove "Uncategorized"
            $new_cats = array_unique(array_merge($current_cats, $cats_to_add));

            // Remove "Uncategorized" if present
            $new_cats = array_diff($new_cats, array($uncategorized_id));

            // Ensure we have at least one category (shouldn't happen, but safety check)
            if (empty($new_cats)) {
                $this->log_message('  → ⚠ Warning: No categories remain after processing, skipping');
                $skipped_count++;
                continue;
            }

            // Update the post categories
            $result = wp_set_post_categories($post->ID, $new_cats);

            if (is_wp_error($result)) {
                $this->log_message('  → ✗ Failed to update categories');
            } else {
                $actions = array();
                if (!empty($added_labels)) {
                    $actions[] = 'Added: ' . implode(', ', $added_labels);
                }
                if ($has_uncategorized) {
                    $actions[] = 'Removed: Uncategorized';
                }

                $this->log_message('  → ✓ ' . implode(' | ', $actions));

                if (in_array($newsfeed_id, $cats_to_add)) {
                    $newsfeed_count++;
                }
                if (in_array($featured_id, $cats_to_add)) {
                    $featured_count++;
                }
            }
        }

        $message_parts = array();
        if ($newsfeed_count > 0) {
            $message_parts[] = "$newsfeed_count posts categorized as 'newsfeed'";
        }
        if ($featured_count > 0) {
            $message_parts[] = "$featured_count roundups categorized as 'Featured'";
        }
        if ($skipped_count > 0) {
            $message_parts[] = "$skipped_count already categorized";
        }

        $message = 'Complete! ' . implode(', ', $message_parts);
        $this->log_message('');
        $this->log_message($message);

        $this->clear_progress();

        return array(
            'success' => true,
            'message' => $message
        );
    }

    /**
     * Update all EnviroLink post authors to "EnviroLink Editor"
     * Creates the user if it doesn't exist
     */
    private function update_all_authors() {
        $this->clear_progress();
        $this->log_message('Starting bulk author update...');

        // Get or create "EnviroLink Editor" user
        // Try multiple variations to find existing user
        $editor_user = get_user_by('login', 'envirolink_editor');
        if (!$editor_user) {
            $editor_user = get_user_by('login', 'EnviroLink Editor');
        }
        if (!$editor_user) {
            $editor_user = get_user_by('email', 'jknauer+editor@gmail.com');
        }

        if (!$editor_user) {
            $this->log_message('EnviroLink Editor user not found, creating...');

            // Create the user
            $user_id = wp_create_user(
                'envirolink_editor',
                wp_generate_password(24, true, true),
                'editor@envirolink.org'
            );

            if (is_wp_error($user_id)) {
                $this->log_message('✗ Failed to create user: ' . $user_id->get_error_message());
                return array('success' => false, 'message' => 'Failed to create EnviroLink Editor user');
            }

            // Set display name and role
            wp_update_user(array(
                'ID' => $user_id,
                'display_name' => 'EnviroLink Editor',
                'first_name' => 'EnviroLink',
                'last_name' => 'Editor',
                'role' => 'editor'
            ));

            $editor_user_id = $user_id;
            $this->log_message('✓ Created EnviroLink Editor user (ID: ' . $editor_user_id . ')');
        } else {
            $editor_user_id = $editor_user->ID;
            $this->log_message('✓ Found existing EnviroLink Editor user (ID: ' . $editor_user_id . ')');
        }

        // Get all EnviroLink posts (RSS + roundups, including scheduled!)
        $rss_posts = get_posts(array(
            'post_type' => 'post',
            'posts_per_page' => -1,
            'meta_key' => 'envirolink_source_url',
            'meta_compare' => 'EXISTS',
            'post_status' => 'any' // Check ALL statuses: publish, future, draft, pending, private
        ));

        $roundup_posts_meta = get_posts(array(
            'post_type' => 'post',
            'posts_per_page' => -1,
            'meta_key' => 'envirolink_is_roundup',
            'meta_value' => 'yes',
            'meta_compare' => '=',
            'post_status' => 'any' // Check ALL statuses: publish, future, draft, pending, private
        ));

        $roundup_posts_title = get_posts(array(
            'post_type' => 'post',
            'posts_per_page' => -1,
            's' => 'Daily Environmental News Roundup',
            'post_status' => 'any' // Check ALL statuses: publish, future, draft, pending, private
        ));

        // Merge all posts and remove duplicates
        $all_post_ids = array();
        $posts = array();

        foreach (array_merge($rss_posts, $roundup_posts_meta, $roundup_posts_title) as $post) {
            if (!in_array($post->ID, $all_post_ids)) {
                $all_post_ids[] = $post->ID;
                $posts[] = $post;
            }
        }

        $total_posts = count($posts);

        if ($total_posts == 0) {
            $this->log_message('No EnviroLink posts found');
            return array('success' => true, 'message' => 'No posts to update');
        }

        $this->log_message('Found ' . $total_posts . ' posts to update');

        $updated_count = 0;
        $skipped_count = 0;

        foreach ($posts as $index => $post) {
            $progress_percent = floor((($index + 1) / $total_posts) * 100);
            $this->update_progress(array(
                'percent' => $progress_percent,
                'current' => $index + 1,
                'total' => $total_posts,
                'status' => 'Updating authors...'
            ));

            $this->log_message('Processing: ' . $post->post_title);

            // Check if already has correct author
            if ($post->post_author == $editor_user_id) {
                $this->log_message('  → Already has EnviroLink Editor, skipping');
                $skipped_count++;
                continue;
            }

            // Update the author
            $result = wp_update_post(array(
                'ID' => $post->ID,
                'post_author' => $editor_user_id
            ));

            if (is_wp_error($result)) {
                $this->log_message('  → ✗ Failed to update author');
            } else {
                $this->log_message('  → ✓ Changed author to EnviroLink Editor');
                $updated_count++;
            }
        }

        $message = "Complete! Updated $updated_count posts";
        if ($skipped_count > 0) {
            $message .= ", skipped $skipped_count already correct";
        }

        $this->log_message('');
        $this->log_message($message);

        $this->clear_progress();

        return array(
            'success' => true,
            'message' => $message
        );
    }

    /**
     * Get or create EnviroLink Editor user ID
     * Returns the user ID for new posts
     */
    private function get_envirolink_editor_id() {
        // Try multiple variations to find existing user
        $editor_user = get_user_by('login', 'envirolink_editor');
        if (!$editor_user) {
            $editor_user = get_user_by('login', 'EnviroLink Editor');
        }
        if (!$editor_user) {
            $editor_user = get_user_by('email', 'jknauer+editor@gmail.com');
        }

        if ($editor_user) {
            return $editor_user->ID;
        }

        // Create the user if it doesn't exist
        $user_id = wp_create_user(
            'envirolink_editor',
            wp_generate_password(24, true, true),
            'editor@envirolink.org'
        );

        if (is_wp_error($user_id)) {
            error_log('EnviroLink: Failed to create editor user: ' . $user_id->get_error_message());
            return 1; // Fallback to admin
        }

        // Set display name and role
        wp_update_user(array(
            'ID' => $user_id,
            'display_name' => 'EnviroLink Editor',
            'first_name' => 'EnviroLink',
            'last_name' => 'Editor',
            'role' => 'editor'
        ));

        return $user_id;
    }

    /**
     * Calculate similarity between two titles
     * Returns percentage (0-100) of how similar they are
     */
    private function calculate_title_similarity($title_a, $title_b) {
        // Use PHP's similar_text function which calculates similarity
        similar_text($title_a, $title_b, $percent);
        return $percent;
    }

    /**
     * Extract significant keywords from title for daily limiting
     * Returns array of 2-3 most important keywords
     */
    private function extract_keywords_from_title($title) {
        // Common stop words to ignore
        $stop_words = array(
            'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
            'of', 'as', 'by', 'with', 'from', 'is', 'are', 'was', 'were', 'be',
            'been', 'has', 'have', 'had', 'will', 'would', 'could', 'should',
            'may', 'might', 'can', 'about', 'after', 'over', 'their', 'its',
            'this', 'that', 'these', 'those', 'it', 'new', 'amid', 'says', 'amid'
        );

        // Convert to lowercase and split into words
        $title_lower = strtolower(trim($title));

        // Remove special characters but keep alphanumeric and spaces
        $title_clean = preg_replace('/[^a-z0-9\s]/', ' ', $title_lower);

        // Split into words
        $words = preg_split('/\s+/', $title_clean);

        // Filter out stop words and short words
        $keywords = array();
        foreach ($words as $word) {
            $word = trim($word);
            // Keep words that are:
            // - Not stop words
            // - At least 3 characters long
            // - Not purely numeric
            if (!in_array($word, $stop_words) &&
                strlen($word) >= 3 &&
                !is_numeric($word)) {
                $keywords[] = $word;
            }
        }

        // Remove duplicates and take first 3 most significant
        $keywords = array_unique($keywords);
        $keywords = array_slice($keywords, 0, 3);

        return $keywords;
    }

    /**
     * Check if we've already published enough articles with these keywords today
     * Returns true if limit reached, false if we can still publish
     */
    private function check_keyword_daily_limit($keywords, $current_article_title) {
        if (empty($keywords)) {
            return false; // No keywords to check, allow the article
        }

        // Get the daily limit from settings (default: 2)
        $daily_limit = get_option('envirolink_keyword_daily_limit', 2);

        // Check posts from today (same calendar date)
        $today_start = date('Y-m-d 00:00:00');

        $recent_posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'any', // Check all statuses
            'date_query' => array(
                array(
                    'after' => $today_start,
                    'inclusive' => true,
                ),
            ),
            'meta_key' => 'envirolink_source_url',
            'meta_compare' => 'EXISTS',
            'posts_per_page' => 100,
            'fields' => 'ids', // Only get IDs for efficiency
        ));

        if (empty($recent_posts)) {
            return false; // No recent posts, allow this one
        }

        // Count posts containing these keywords
        $matches = 0;
        $matched_titles = array();

        foreach ($recent_posts as $post_id) {
            $post_title = get_the_title($post_id);
            $post_title_lower = strtolower($post_title);

            // Check if post title contains any of our keywords
            $keyword_matches = 0;
            foreach ($keywords as $keyword) {
                if (strpos($post_title_lower, $keyword) !== false) {
                    $keyword_matches++;
                }
            }

            // If at least 2 keywords match, consider it the same topic
            if ($keyword_matches >= 2 || (count($keywords) <= 2 && $keyword_matches >= 1)) {
                $matches++;
                $matched_titles[] = $post_title;

                // Stop counting if we've already hit the limit
                if ($matches >= $daily_limit) {
                    break;
                }
            }
        }

        // Log the results
        if ($matches >= $daily_limit) {
            $this->log_message('→ KEYWORD LIMIT REACHED: Already published ' . $matches . ' articles about [' . implode(', ', $keywords) . '] today');
            $this->log_message('   Recent similar articles:');
            foreach (array_slice($matched_titles, 0, 3) as $title) {
                $this->log_message('   • "' . $title . '"');
            }
            $this->log_message('   Skipping: "' . $current_article_title . '" to avoid redundancy');
            return true; // Limit reached, skip this article
        }

        return false; // Under the limit, allow this article
    }

    /**
     * Update progress status
     */
    private function update_progress($data) {
        // Keep existing log if not provided
        $current = get_transient('envirolink_progress');
        if ($current && isset($current['log']) && !isset($data['log'])) {
            $data['log'] = $current['log'];
        }
        set_transient('envirolink_progress', $data, 300); // 5 minute expiration
    }

    /**
     * Add a log message to progress
     */
    private function log_message($message) {
        $progress = get_transient('envirolink_progress');
        if ($progress === false) {
            $progress = array('log' => array());
        }
        if (!isset($progress['log'])) {
            $progress['log'] = array();
        }
        $progress['log'][] = '[' . wp_date('H:i:s') . '] ' . $message;

        // Keep only last 100 messages to avoid memory issues
        if (count($progress['log']) > 100) {
            $progress['log'] = array_slice($progress['log'], -100);
        }

        set_transient('envirolink_progress', $progress, 300);
    }

    /**
     * Clear progress status (but save the log for later viewing)
     */
    private function clear_progress() {
        // Save the final log before clearing
        $progress = get_transient('envirolink_progress');
        if ($progress && isset($progress['log'])) {
            update_option('envirolink_last_run_log', $progress['log']);
        }
        delete_transient('envirolink_progress');
    }

    /**
     * Emergency log save - called on shutdown to preserve logs even if script dies
     */
    public function emergency_save_log() {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
            // Fatal error occurred - save what we have
            $progress = get_transient('envirolink_progress');
            if ($progress && isset($progress['log'])) {
                $progress['log'][] = '[' . wp_date('H:i:s') . '] ✗ FATAL ERROR: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'];
                update_option('envirolink_last_run_log', $progress['log']);
            }
        }
    }

    /**
     * Optimize title for SEO
     * - Removes excessive punctuation
     * - Ensures proper capitalization
     * - No truncation (displays full headline)
     */
    private function optimize_title_for_seo($title) {
        // Remove excessive punctuation
        $title = preg_replace('/[!]{2,}/', '!', $title);
        $title = preg_replace('/[?]{2,}/', '?', $title);

        // Capitalize first letter of each sentence
        $title = ucfirst(strtolower($title));
        $title = preg_replace_callback('/([.!?]\s+)([a-z])/', function($matches) {
            return $matches[1] . strtoupper($matches[2]);
        }, $title);

        // No truncation - display full headlines
        // (Previously truncated at 70 chars, but user wants full headlines displayed)

        return $title;
    }

    /**
     * Normalize URL for duplicate detection
     * Handles http/https, www, trailing slashes, and query parameter variations
     */
    private function normalize_url($url) {
        if (empty($url)) {
            return '';
        }

        // Parse URL
        $parsed = parse_url(strtolower(trim($url)));

        if (!$parsed || !isset($parsed['host'])) {
            return $url; // Return original if can't parse
        }

        // Remove www prefix
        $host = preg_replace('/^www\./', '', $parsed['host']);

        // Get path without trailing slash
        $path = isset($parsed['path']) ? rtrim($parsed['path'], '/') : '';

        // Sort query parameters for consistent comparison
        $query = '';
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $params);
            ksort($params);
            $query = http_build_query($params);
        }

        // Rebuild URL without protocol (to ignore http vs https differences)
        $normalized = $host . $path;
        if ($query) {
            $normalized .= '?' . $query;
        }

        return $normalized;
    }

    /**
     * Check if a feed is due for processing based on its schedule
     */
    private function is_feed_due($feed) {
        // Ensure backward compatibility
        $schedule_type = isset($feed['schedule_type']) ? $feed['schedule_type'] : 'hourly';
        $schedule_times = isset($feed['schedule_times']) ? $feed['schedule_times'] : 1;
        $last_processed = isset($feed['last_processed']) ? $feed['last_processed'] : 0;

        // If never processed, it's due
        if ($last_processed == 0) {
            return true;
        }

        $now = time();
        $time_since_last = $now - $last_processed;

        // Calculate the interval in seconds
        switch ($schedule_type) {
            case 'hourly':
                $interval = 3600 / $schedule_times;  // seconds per processing
                break;
            case 'daily':
                $interval = 86400 / $schedule_times; // 24 hours in seconds
                break;
            case 'weekly':
                $interval = 604800 / $schedule_times; // 7 days in seconds
                break;
            case 'monthly':
                $interval = 2592000 / $schedule_times; // ~30 days in seconds
                break;
            default:
                $interval = 3600; // default to hourly
        }

        return $time_since_last >= $interval;
    }

    /**
     * Update images for all existing posts from a specific feed
     * @param int $feed_index The feed index to update
     */
    private function update_feed_images($feed_index) {
        // Increase resource limits
        @ini_set('max_execution_time', 300); // 5 minutes
        @ini_set('memory_limit', '256M');
        @set_time_limit(300);

        // Register shutdown handler to save log even if script dies
        register_shutdown_function(array($this, 'emergency_save_log'));

        $start_time = time();
        $max_execution_time = 280; // Stop 20 seconds before timeout

        $feeds = get_option('envirolink_feeds', array());

        if (!isset($feeds[$feed_index])) {
            return array('success' => false, 'message' => 'Feed not found');
        }

        $feed = $feeds[$feed_index];
        $this->log_message('Starting image update for ' . $feed['name']);

        // Query only the 20 most recent posts from this feed
        // This prevents resource exhaustion and focuses on current content
        $args = array(
            'post_type' => 'post',
            'posts_per_page' => 20,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => array(
                array(
                    'key' => 'envirolink_source_name',
                    'value' => $feed['name'],
                    'compare' => '='
                )
            )
        );

        $posts = get_posts($args);
        $total_posts = count($posts);

        if ($total_posts == 0) {
            $this->log_message('No posts found for this feed');
            return array('success' => true, 'message' => 'No posts found for this feed');
        }

        $this->log_message('Found ' . $total_posts . ' posts to update (limited to 20 most recent)');

        $updated_count = 0;
        $skipped_count = 0;
        $failed_count = 0;
        $image_cache = array(); // Track downloaded images to avoid duplicates

        foreach ($posts as $index => $post) {
            // Check for timeout AND memory limits
            $elapsed_time = time() - $start_time;
            $memory_usage = memory_get_usage(true) / 1024 / 1024; // MB

            if ($elapsed_time > $max_execution_time) {
                $this->log_message('⚠ Timeout protection: Stopping to prevent script death (elapsed: ' . $elapsed_time . 's)');
                $this->log_message('Processed ' . ($index + 1) . ' of ' . $total_posts . ' posts');
                $message = "Partial update: $updated_count images updated, $skipped_count skipped, $failed_count failed (timeout after " . ($index + 1) . " posts)";
                $this->clear_progress(); // Save log
                return array('success' => true, 'message' => $message);
            }

            if ($memory_usage > 200) { // Stop if using >200MB
                $this->log_message('⚠ Memory protection: Stopping to prevent exhaustion (' . round($memory_usage) . 'MB used)');
                $this->log_message('Processed ' . ($index + 1) . ' of ' . $total_posts . ' posts');
                $message = "Partial update: $updated_count images updated, $skipped_count skipped, $failed_count failed (memory limit after " . ($index + 1) . " posts)";
                $this->clear_progress(); // Save log
                return array('success' => true, 'message' => $message);
            }

            $progress_percent = floor((($index + 1) / $total_posts) * 100);
            $this->update_progress(array(
                'percent' => $progress_percent,
                'current' => $index + 1,
                'total' => $total_posts,
                'status' => 'Updating images for ' . $feed['name'] . '... (' . round($memory_usage) . 'MB, ' . $elapsed_time . 's)'
            ));

            $this->log_message('Processing: ' . $post->post_title . ' [Memory: ' . round($memory_usage) . 'MB, Time: ' . $elapsed_time . 's]');

            // Get source URL
            $source_url = get_post_meta($post->ID, 'envirolink_source_url', true);
            if (empty($source_url)) {
                $this->log_message('  → Skipped: No source URL found');
                $skipped_count++;
                continue;
            }

            $image_url = null;

            // Strategy 1: Check if there's already an image URL we can enhance
            // BUT skip WordPress-hosted images (we need to go back to the original source)
            $current_thumbnail_id = get_post_thumbnail_id($post->ID);
            if ($current_thumbnail_id) {
                $current_image_url = wp_get_attachment_url($current_thumbnail_id);
                if ($current_image_url) {
                    // Check if this is a WordPress-hosted image (already uploaded)
                    // Compare hosts (protocol-agnostic) to handle http/https differences
                    $site_host = parse_url(get_site_url(), PHP_URL_HOST);
                    $image_host = parse_url($current_image_url, PHP_URL_HOST);

                    if ($site_host === $image_host) {
                        $this->log_message('  → Existing image is WordPress-hosted (' . $image_host . '), will fetch from original source');
                        // Don't use this URL - go to Strategy 2 to get original from RSS
                    } else {
                        // It's an external URL (CDN), we can try to enhance it
                        $this->log_message('  → Found existing CDN image (' . $image_host . '), enhancing quality...');
                        $image_url = $this->enhance_image_quality($current_image_url);
                    }
                }
            }

            // Strategy 2: Try to extract from RSS feed item (more reliable than scraping)
            if (!$image_url) {
                $this->log_message('  → Fetching from RSS feed...');

                // Fetch the feed
                $rss = fetch_feed($feed['url']);
                if (!is_wp_error($rss)) {
                    $items = $rss->get_items(0, 50); // Get more items to find match

                    // Find the matching RSS item by URL
                    foreach ($items as $item) {
                        $item_link = $item->get_permalink();
                        if ($item_link === $source_url) {
                            $this->log_message('  → Found matching RSS item');
                            $image_url = $this->extract_feed_image($item);
                            break;
                        }
                    }
                } else {
                    $this->log_message('  → Failed to fetch RSS feed: ' . $rss->get_error_message());
                }
            }

            // Strategy 3: Fall back to scraping article page
            if (!$image_url) {
                $this->log_message('  → Scraping article page: ' . $source_url);
                $image_url = $this->extract_image_from_url($source_url);
            }

            // Set/update the featured image with duplicate detection
            if ($image_url) {
                // Check if we've already downloaded this exact image URL
                if (isset($image_cache[$image_url])) {
                    // Reuse the already-uploaded media library ID
                    $this->log_message('  → Reusing previously downloaded image (ID: ' . $image_cache[$image_url] . ')');
                    $result = set_post_thumbnail($post->ID, $image_cache[$image_url]);
                    if ($result) {
                        $this->log_message('  → ✓ Image reused successfully (saved bandwidth & time)');
                        $updated_count++;
                    } else {
                        $this->log_message('  → ✗ Failed to set cached image as featured image');
                        $failed_count++;
                    }
                } else {
                    // Download new image
                    $attachment_id = $this->set_featured_image_from_url($image_url, $post->ID);
                    if ($attachment_id) {
                        $this->log_message('  → ✓ Image updated successfully');
                        // Cache the attachment ID for future reuse
                        $image_cache[$image_url] = $attachment_id;
                        $updated_count++;
                    } else {
                        $this->log_message('  → ✗ Failed to set featured image');
                        $failed_count++;
                    }
                }
            } else {
                $this->log_message('  → ✗ No image found via any method');
                $failed_count++;
            }

            // Periodically save progress to database (every 5 posts) to avoid data loss on crash
            if (($index + 1) % 5 == 0) {
                $progress = get_transient('envirolink_progress');
                if ($progress && isset($progress['log'])) {
                    update_option('envirolink_last_run_log', $progress['log']);
                    $this->log_message('→ Checkpoint: Progress saved to database');
                }
            }
        }

        $message = "Updated $updated_count images";
        if ($skipped_count > 0) $message .= ", skipped $skipped_count";
        if ($failed_count > 0) $message .= ", failed $failed_count";

        $this->log_message('Complete: ' . $message);

        return array(
            'success' => true,
            'message' => $message
        );
    }

    /**
     * Fix post dates to match RSS publication dates
     * Syncs all EnviroLink posts' post_date to their stored envirolink_pubdate metadata
     */
    private function fix_post_dates() {
        $this->log_message('Starting post date synchronization...');

        // Get all EnviroLink posts (including scheduled posts!)
        $args = array(
            'post_type' => 'post',
            'posts_per_page' => -1,
            'meta_key' => 'envirolink_source_url',
            'meta_compare' => 'EXISTS',
            'post_status' => 'any' // Check ALL statuses: publish, future, draft, pending, private
        );

        $posts = get_posts($args);
        $total_posts = count($posts);

        if ($total_posts == 0) {
            $this->log_message('No EnviroLink posts found');
            return array('success' => true, 'message' => 'No posts to update');
        }

        $this->log_message('Found ' . $total_posts . ' posts to check');

        $fixed_count = 0;
        $skipped_count = 0;
        $error_count = 0;

        foreach ($posts as $index => $post) {
            $progress_percent = floor((($index + 1) / $total_posts) * 100);
            $this->update_progress(array(
                'percent' => $progress_percent,
                'current' => $index + 1,
                'total' => $total_posts,
                'status' => 'Syncing post dates...'
            ));

            $this->log_message('Checking: ' . $post->post_title);

            // Get stored RSS publication date
            $rss_pubdate = get_post_meta($post->ID, 'envirolink_pubdate', true);

            if (empty($rss_pubdate)) {
                $this->log_message('  → No RSS pubdate stored, skipping');
                $skipped_count++;
                continue;
            }

            // Parse dates for comparison (compare just the date part, not time)
            $current_post_date = get_the_date('Y-m-d', $post->ID);
            $rss_date = date('Y-m-d', strtotime($rss_pubdate));

            if ($current_post_date === $rss_date) {
                $this->log_message('  → Already correct (' . $current_post_date . ')');
                $skipped_count++;
                continue;
            }

            // Need to fix the date
            $this->log_message('  → Fixing: ' . $current_post_date . ' → ' . $rss_date);

            // Convert RSS pubdate to WordPress format
            $timestamp = strtotime($rss_pubdate);
            if ($timestamp === false) {
                $this->log_message('  → ✗ Invalid date format');
                $error_count++;
                continue;
            }

            $new_post_date = date('Y-m-d H:i:s', $timestamp);
            $new_post_date_gmt = get_gmt_from_date($new_post_date);

            // Update the post
            $result = wp_update_post(array(
                'ID' => $post->ID,
                'post_date' => $new_post_date,
                'post_date_gmt' => $new_post_date_gmt
            ), true);

            if (is_wp_error($result)) {
                $this->log_message('  → ✗ Error: ' . $result->get_error_message());
                $error_count++;
            } else {
                $this->log_message('  → ✓ Fixed successfully');
                $fixed_count++;
            }
        }

        $message = "Fixed {$fixed_count} post dates";
        if ($skipped_count > 0) $message .= ", skipped {$skipped_count} (already correct)";
        if ($error_count > 0) $message .= ", {$error_count} errors";

        $this->log_message('Complete: ' . $message);

        return array(
            'success' => true,
            'message' => $message
        );
    }

    /**
     * Main function: Fetch and process feeds
     * @param bool $manual_run Whether this is a manual run (bypasses schedule checks)
     * @param int $specific_feed_index Process only this feed (null = all feeds)
     */
    public function fetch_and_process_feeds($manual_run = false, $specific_feed_index = null) {
        // CRITICAL: Prevent concurrent executions that cause duplicates
        // Use PID-based liveness check instead of timeout-based lock
        $lock_key = 'envirolink_processing_lock';
        $lock_data = get_transient($lock_key);

        if ($lock_data) {
            // Lock exists - check if the process is actually still alive
            $run_type = $manual_run ? 'manual run' : 'CRON run';
            $lock_age = time() - $lock_data['start_time'];
            $lock_pid = isset($lock_data['pid']) ? $lock_data['pid'] : null;
            $last_heartbeat = isset($lock_data['last_heartbeat']) ? $lock_data['last_heartbeat'] : $lock_data['start_time'];
            $heartbeat_age = time() - $last_heartbeat;

            if ($lock_pid && $this->is_process_alive($lock_pid)) {
                // Process is ALIVE - respect the lock (no matter how long it's been running)
                error_log('EnviroLink: Skipping ' . $run_type . ' - PID ' . $lock_pid . ' is still alive and processing');
                error_log('EnviroLink: Lock age: ' . $lock_age . 's, Last heartbeat: ' . $heartbeat_age . 's ago');

                return array(
                    'success' => false,
                    'message' => 'Another instance is actively running (PID: ' . $lock_pid . ', running for ' . $lock_age . 's). Please wait and try again.'
                );
            } else {
                // Process is DEAD or missing - this is a stale lock, clear it
                if ($lock_pid) {
                    error_log('EnviroLink: Clearing stale lock (PID ' . $lock_pid . ' is dead/missing after ' . $lock_age . 's)');
                } else {
                    error_log('EnviroLink: Clearing corrupted lock (no PID found)');
                }
                delete_transient($lock_key);
                // Continue to acquire new lock below
            }
        }

        // Acquire new lock with heartbeat tracking
        // Set generous timeout (30 minutes) as fallback - PID check is primary safety
        $lock_data = array(
            'start_time' => time(),
            'last_heartbeat' => time(),
            'pid' => getmypid(),
            'type' => $manual_run ? 'manual' : 'cron'
        );
        set_transient($lock_key, $lock_data, 1800); // 30 minutes fallback timeout

        error_log('EnviroLink: Lock acquired (PID: ' . $lock_data['pid'] . ', Type: ' . $lock_data['type'] . ')');

        // SCHEDULED TIME CHECK: Only run feeds at 7am ET and 4pm ET (automatic runs only)
        if (!$manual_run) {
            // Get current time in Eastern Time
            $eastern_tz = new DateTimeZone('America/New_York');
            $now = new DateTime('now', $eastern_tz);
            $current_hour = (int)$now->format('G'); // 0-23 hour format

            // Define allowed time windows (with 30-minute tolerance on either side)
            $morning_start = 6;   // 6:00 AM ET
            $morning_end = 8;     // 8:00 AM ET (so 6am-8am = 2 hour window centered on 7am)
            $afternoon_start = 15; // 3:00 PM ET
            $afternoon_end = 17;   // 5:00 PM ET (so 3pm-5pm = 2 hour window centered on 4pm)

            $is_morning_window = ($current_hour >= $morning_start && $current_hour < $morning_end);
            $is_afternoon_window = ($current_hour >= $afternoon_start && $current_hour < $afternoon_end);

            if (!$is_morning_window && !$is_afternoon_window) {
                // Not in a scheduled window - skip processing
                $current_time_str = $now->format('g:i A T');
                error_log('EnviroLink: Skipping automatic feed run - outside scheduled windows (current: ' . $current_time_str . ')');
                error_log('EnviroLink: Scheduled times: 7:00 AM ET (6am-8am window) and 4:00 PM ET (3pm-5pm window)');

                delete_transient($lock_key); // Release lock
                return array(
                    'success' => false,
                    'message' => 'Skipped - outside scheduled time windows (7am ET and 4pm ET). Current time: ' . $current_time_str
                );
            }

            // Log which window we're in
            $window_name = $is_morning_window ? 'morning (7am ET)' : 'afternoon (4pm ET)';
            error_log('EnviroLink: Running in ' . $window_name . ' scheduled window');
        }

        // Increase resource limits for long-running feed processing
        // Prevents timeouts when processing multiple articles with AI + image scraping
        @ini_set('max_execution_time', 300); // 5 minutes
        @ini_set('memory_limit', '256M');     // 256MB RAM
        @set_time_limit(300);

        $api_key = get_option('envirolink_api_key');
        $feeds = get_option('envirolink_feeds', array());
        $post_category = get_option('envirolink_post_category');
        $post_status = get_option('envirolink_post_status', 'publish');
        $update_existing = get_option('envirolink_update_existing', 'no');

        if (empty($api_key)) {
            delete_transient($lock_key); // Release lock
            return array('success' => false, 'message' => 'API key not configured');
        }

        $total_processed = 0;
        $total_created = 0;
        $total_updated = 0;
        $total_skipped = 0;
        $failed_feeds = array();

        // Calculate total feeds to process
        $feeds_to_process = array();
        foreach ($feeds as $index => $feed) {
            if ($specific_feed_index !== null && $index !== $specific_feed_index) {
                continue;
            }
            if (!$feed['enabled']) {
                continue;
            }
            if (!$manual_run && !$this->is_feed_due($feed)) {
                continue;
            }
            $feeds_to_process[] = array('index' => $index, 'feed' => $feed);
        }

        // Initialize progress
        $this->update_progress(array(
            'percent' => 0,
            'current' => 0,
            'total' => 0,
            'status' => 'Counting articles...',
            'log' => array('Started processing')
        ));

        // First pass: count total articles
        $total_articles = 0;
        $feed_article_counts = array();

        foreach ($feeds_to_process as $feed_data) {
            $feed = $feed_data['feed'];
            add_filter('http_request_args', array($this, 'custom_http_request_args'), 10, 2);
            $rss = fetch_feed($feed['url']);
            remove_filter('http_request_args', array($this, 'custom_http_request_args'), 10);

            if (!is_wp_error($rss)) {
                $count = $rss->get_item_quantity(10);
                $total_articles += $count;
                $feed_article_counts[$feed_data['index']] = $count;
                $this->log_message('Found ' . $count . ' articles in ' . $feed['name']);
            }
        }

        if ($total_articles == 0) {
            $this->log_message('No articles to process');
            $this->clear_progress();
            delete_transient('envirolink_processing_lock'); // Release lock
            return array('success' => true, 'message' => 'No articles to process');
        }

        $articles_processed = 0;

        foreach ($feeds_to_process as $feed_data) {
            $index = $feed_data['index'];
            $feed = $feed_data['feed'];

            $this->log_message('Processing feed: ' . $feed['name']);

            // Set custom User-Agent to avoid being blocked
            add_filter('http_request_args', array($this, 'custom_http_request_args'), 10, 2);

            // Fetch RSS feed
            $rss = fetch_feed($feed['url']);

            // Remove filter after fetch
            remove_filter('http_request_args', array($this, 'custom_http_request_args'), 10);

            if (is_wp_error($rss)) {
                $failed_feeds[] = $feed['name'] . ' (' . $rss->get_error_message() . ')';
                continue;
            }
            
            $max_items = $rss->get_item_quantity(10);
            $items = $rss->get_items(0, $max_items);
            
            foreach ($items as $item) {
                $total_processed++;
                $articles_processed++;

                // Update lock heartbeat every 5 articles to keep lock fresh during long runs
                if ($articles_processed % 5 == 0) {
                    $this->update_lock_heartbeat();
                }

                // Update progress
                $percent = floor(($articles_processed / $total_articles) * 100);
                $this->update_progress(array(
                    'percent' => $percent,
                    'current' => $articles_processed,
                    'total' => $total_articles,
                    'status' => 'Processing ' . esc_html($feed['name']) . ' (' . $articles_processed . '/' . $total_articles . ')'
                ));

                // Check if article already exists
                $original_link = $item->get_permalink();
                $original_title = $item->get_title();

                // CRITICAL: Log with microsecond timestamp to detect race conditions
                $timestamp = wp_date('H:i:s') . '.' . substr(microtime(), 2, 3);
                $this->log_message('[' . $timestamp . '] Checking article: ' . $original_title);
                $this->log_message('→ Source URL: ' . $original_link);

                // Use normalized URL for better duplicate detection
                $normalized_link = $this->normalize_url($original_link);
                $this->log_message('→ Normalized URL: ' . $normalized_link);

                // First try exact match (backward compatibility)
                // CRITICAL: Must include ALL post statuses (publish, future, draft, pending)
                // to catch scheduled posts that would otherwise be missed
                $existing = get_posts(array(
                    'post_type' => 'post',
                    'post_status' => 'any', // Check ALL statuses: publish, future, draft, pending
                    'meta_key' => 'envirolink_source_url',
                    'meta_value' => $original_link,
                    'posts_per_page' => 1
                ));

                if (!empty($existing)) {
                    $this->log_message('→ Found exact URL match (Post ID: ' . $existing[0]->ID . ')');
                } else {
                    $this->log_message('→ No exact match, checking normalized URLs...');
                    // Try to find posts with similar normalized URLs
                    // CRITICAL: Use ID ordering (not date) to get recently-ADDED posts
                    // Posts may have old publication dates but were recently added to WordPress
                    $all_posts = get_posts(array(
                        'post_type' => 'post',
                        'post_status' => 'any', // Check ALL statuses: publish, future, draft, pending
                        'meta_key' => 'envirolink_source_url',
                        'meta_compare' => 'EXISTS',
                        'posts_per_page' => 500, // Check last 500 posts added to WordPress
                        'orderby' => 'ID', // Order by when added, not publication date
                        'order' => 'DESC'
                    ));

                    $this->log_message('→ Checking ' . count($all_posts) . ' recent posts for normalized match...');

                    foreach ($all_posts as $post) {
                        $existing_url = get_post_meta($post->ID, 'envirolink_source_url', true);
                        $existing_normalized = $this->normalize_url($existing_url);
                        if ($existing_normalized === $normalized_link) {
                            $existing = array($post);
                            $this->log_message('→ ✓ Found duplicate via URL normalization (Post ID: ' . $post->ID . ')');
                            $this->log_message('   Existing URL: ' . $existing_url);
                            break;
                        }
                    }

                    if (empty($existing)) {
                        $this->log_message('→ No URL match found');

                        // PHASE 2: Check for same image URL across ALL sources (CRITICAL - SIMPLE CHECK!)
                        // If same image = likely the same article (even from different sources)
                        $this->log_message('→ Checking for same image URL across all sources...');
                        $current_image_url = $this->extract_feed_image($item);

                        if ($current_image_url) {
                            $normalized_image_url = $this->normalize_url($current_image_url);
                            $this->log_message('   Image URL: ' . $current_image_url);

                            foreach ($all_posts as $post) {
                                // Check ALL posts, regardless of source
                                // (different sources often share the same AP/Reuters/Getty images)
                                $post_thumbnail_id = get_post_thumbnail_id($post->ID);
                                if ($post_thumbnail_id) {
                                    $post_image_url = wp_get_attachment_url($post_thumbnail_id);
                                    if ($post_image_url) {
                                        $normalized_post_image = $this->normalize_url($post_image_url);

                                        if ($normalized_image_url === $normalized_post_image) {
                                            $post_source = get_post_meta($post->ID, 'envirolink_source_name', true);
                                            $existing = array($post);
                                            $this->log_message('→ ✓ Found duplicate via SAME IMAGE across sources!');
                                            $this->log_message('   Existing: "' . $post->post_title . '" (ID: ' . $post->ID . ') from ' . $post_source);
                                            $this->log_message('   New: "' . $original_title . '" from ' . $feed['name']);
                                            $this->log_message('   Same image = SAME ARTICLE (different sources covering same story)');
                                            break;
                                        }
                                    }
                                }
                            }
                        }

                        // PHASE 3: Check for similar titles (catches same article with different URL)
                        if (empty($existing)) {
                            $this->log_message('→ Checking for similar titles...');
                            $original_title_lower = strtolower(trim($original_title));

                            foreach ($all_posts as $post) {
                                $existing_title_lower = strtolower(trim($post->post_title));
                                $similarity = $this->calculate_title_similarity($original_title_lower, $existing_title_lower);

                                if ($similarity >= 80) { // 80% or more similar = likely duplicate
                                    $existing = array($post);
                                    $this->log_message('→ ✓ Found duplicate via title similarity (' . round($similarity) . '% match)');
                                    $this->log_message('   Existing: "' . $post->post_title . '" (ID: ' . $post->ID . ')');
                                    $this->log_message('   New: "' . $original_title . '"');
                                    break;
                                }
                            }
                        }

                        if (empty($existing)) {
                            $this->log_message('→ No duplicate found - will create new post');
                        }
                    }
                }

                $is_update = false;
                $existing_post_id = null;

                if (!empty($existing)) {
                    if ($update_existing === 'yes') {
                        // Update mode: we'll update this post
                        $is_update = true;
                        $existing_post_id = $existing[0]->ID;
                        $this->log_message('→ Update mode: Will check for changes');
                    } else {
                        // Skip mode: skip this article
                        $this->log_message('→ SKIPPING: Article already exists (Post ID: ' . $existing[0]->ID . ')');
                        $total_skipped++;
                        continue;
                    }
                } else {
                    $this->log_message('→ NEW POST: Processing new article');

                    // Check keyword-based daily limiting (prevent redundant coverage of same events)
                    $keywords = $this->extract_keywords_from_title($original_title);
                    if (!empty($keywords)) {
                        $this->log_message('→ Extracted keywords: [' . implode(', ', $keywords) . ']');

                        if ($this->check_keyword_daily_limit($keywords, $original_title)) {
                            // Limit reached, skip this article
                            $total_skipped++;
                            continue;
                        }
                    }
                }

                // Get original content
                $original_description = $item->get_description();
                $original_content = $item->get_content();

                // Combine description and content
                $original_text = trim($original_description . "\n\n" . strip_tags($original_content));

                // Generate content hash for change detection
                $content_hash = md5($original_title . '|' . $original_text);

                // If updating existing post, check if content has actually changed
                // AND check if post is missing a featured image
                $needs_ai_update = true;
                $needs_image_only = false;

                if ($is_update) {
                    $existing_hash = get_post_meta($existing_post_id, 'envirolink_content_hash', true);
                    $has_featured_image = has_post_thumbnail($existing_post_id);
                    $content_changed = ($existing_hash !== $content_hash);

                    if (!$has_featured_image && !$content_changed) {
                        // Post exists, no image, but content unchanged
                        // Skip AI, just try to get image
                        $needs_ai_update = false;
                        $needs_image_only = true;
                        $total_skipped++;
                        $this->log_message('→ No changes detected, but missing image - will try to fetch image only');
                    } elseif ($has_featured_image && !$content_changed) {
                        // Post exists with image and content unchanged - skip entirely
                        $total_skipped++;
                        $this->log_message('→ No changes detected, skipped AI processing');
                        continue;
                    } else {
                        // Content changed - do full AI update
                        $this->log_message('→ Changes detected, sending to AI...');
                    }
                } else {
                    $this->log_message('→ Sending to AI...');
                }

                // Rewrite using AI (unless we only need image)
                $rewritten = null;
                if ($needs_ai_update) {
                    $rewritten = $this->rewrite_with_ai($original_title, $original_text, $api_key);

                    if (!$rewritten) {
                        continue;
                    }
                }

                // Extract image from feed
                $image_url = $this->extract_feed_image($item);

                // If no image in RSS feed, try fetching from article page
                if (!$image_url && !empty($original_link)) {
                    $this->log_message('  → No image in RSS, fetching from article page...');
                    $image_url = $this->extract_image_from_url($original_link);
                }

                // Check if this feed is configured to use Pexels images
                $use_pexels = isset($feed['use_pexels_images']) && $feed['use_pexels_images'];

                if ($use_pexels) {
                    $this->log_message('→ Feed configured to use Pexels images, searching...');
                    $pexels_data = $this->fetch_pexels_image($rewritten['title']);

                    if ($pexels_data) {
                        $this->log_message('→ ✓ Found Pexels image, will use instead of RSS image');
                        $image_url = $pexels_data['url'];
                    } else {
                        $this->log_message('→ ✗ Pexels search failed, falling back to RSS image (if any)');
                    }
                }

                // Extract publication date (always needed for post_date)
                $original_pubdate = $item->get_date('c'); // Get in ISO 8601 format

                // Extract metadata from feed
                $feed_metadata = $this->extract_feed_metadata($item, $feed);

                // Create or update WordPress post
                if ($is_update) {
                    if ($needs_image_only) {
                        // Image-only update: don't update post content, just try to add image
                        $post_id = $existing_post_id;

                        if ($image_url) {
                            $this->log_message('→ Attempting to add missing featured image...');
                            $success = $this->set_featured_image_from_url($image_url, $post_id);
                            if ($success) {
                                $this->log_message('→ Successfully added featured image');
                            } else {
                                $this->log_message('→ Failed to add featured image');
                            }
                        } else {
                            $this->log_message('→ No image available to add');
                        }

                        // Don't count as updated since content didn't change
                        continue;
                    } else {
                        // Full content update
                        // Optimize title for SEO
                        $optimized_title = $this->optimize_title_for_seo($rewritten['title']);
                        
                        $post_data = array(
                            'ID' => $existing_post_id,
                            'post_title' => $optimized_title,
                            'post_content' => $rewritten['content']
                        );

                        $post_id = wp_update_post($post_data);

                        if ($post_id) {
                            $this->log_message('→ Updated post successfully');
                            // Update metadata
                            update_post_meta($post_id, 'envirolink_source_name', $feed['name']);
                            update_post_meta($post_id, 'envirolink_original_title', $original_title);
                            update_post_meta($post_id, 'envirolink_last_updated', current_time('mysql'));
                            update_post_meta($post_id, 'envirolink_content_hash', $content_hash);

                            // Store feed metadata
                            if (isset($feed_metadata['author'])) {
                                update_post_meta($post_id, 'envirolink_author', $feed_metadata['author']);
                            }
                            if (isset($feed_metadata['pubdate'])) {
                                update_post_meta($post_id, 'envirolink_pubdate', $feed_metadata['pubdate']);
                            }
                            if (isset($feed_metadata['topic_tags'])) {
                                update_post_meta($post_id, 'envirolink_topic_tags', $feed_metadata['topic_tags']);
                            }
                            if (isset($feed_metadata['locations'])) {
                                update_post_meta($post_id, 'envirolink_locations', $feed_metadata['locations']);
                            }

                            // Convert topic tags to WordPress tags
                            if (isset($feed_metadata['topic_tags'])) {
                                // Check if ontology filtering is enabled
                                if (get_option('envirolink_ontology_enabled', 'no') === 'yes') {
                                    // Filter tags through ontology
                                    $filtered_tags = $this->filter_tags_with_ontology($feed_metadata['topic_tags']);
                                    if (!empty($filtered_tags)) {
                                        wp_set_post_tags($post_id, $filtered_tags, false);
                                    }
                                } else {
                                    // Use raw RSS tags (legacy behavior)
                                    $tag_array = array_map('trim', explode(',', $feed_metadata['topic_tags']));
                                    wp_set_post_tags($post_id, $tag_array, false);
                                }
                            }

                            // Update featured image if found
                            if ($image_url) {
                                $this->set_featured_image_from_url($image_url, $post_id);
                            }

                            $total_updated++;
                        }
                    }
                } else {
                    // Create new post using original publication date
                    // Optimize title for SEO (remove excessive punctuation, proper capitalization)
                    $optimized_title = $this->optimize_title_for_seo($rewritten['title']);

                    // IMPORTANT: Create as 'draft' first, then transition to desired status
                    // This triggers Jetpack Social's publish hooks properly
                    $post_data = array(
                        'post_title' => $optimized_title,
                        'post_content' => $rewritten['content'],
                        'post_status' => 'draft', // Always start as draft
                        'post_type' => 'post',
                        'post_author' => $this->get_envirolink_editor_id()
                    );

                    // Use original RSS publication date if available
                    // CRITICAL FIX: If RSS date is in the future, use current time instead
                    // This prevents posts from being "Scheduled" instead of "Published"
                    if (!empty($original_pubdate)) {
                        $timestamp = strtotime($original_pubdate);
                        $now = time();

                        if ($timestamp !== false) {
                            // Check if date is in the future
                            if ($timestamp <= $now) {
                                // Past or present - use RSS date
                                $pub_date = date('Y-m-d H:i:s', $timestamp);
                            } else {
                                // Future date - use current time instead to publish immediately
                                $pub_date = current_time('mysql');
                                $this->log_message('→ RSS date is in future, using current time to publish immediately');
                            }
                            $post_data['post_date'] = $pub_date;
                            $post_data['post_date_gmt'] = get_gmt_from_date($pub_date);
                        }
                    }

                    // Set categories: ONLY "newsfeed" (no configured default category)
                    // User request: "All posts that aren't Daily Roundups should be Newsfeed"
                    $categories = array();

                    // Get or create "newsfeed" category
                    $newsfeed_cat = get_category_by_slug('newsfeed');
                    if (!$newsfeed_cat) {
                        $newsfeed_id = wp_insert_term('newsfeed', 'category', array(
                            'slug' => 'newsfeed',
                            'description' => 'News articles aggregated from RSS feeds'
                        ));
                        if (!is_wp_error($newsfeed_id)) {
                            $categories[] = $newsfeed_id['term_id'];
                        }
                    } else {
                        $categories[] = $newsfeed_cat->term_id;
                    }

                    if (!empty($categories)) {
                        $post_data['post_category'] = $categories;
                    }

                    // CRITICAL RACE CONDITION CHECK: One final check RIGHT before post creation
                    // This catches cases where another process passed the duplicate check but
                    // created the post while we were processing AI/images
                    $this->log_message('→ Final safety check before post creation...');
                    $final_check = get_posts(array(
                        'post_type' => 'post',
                        'post_status' => 'any', // Check ALL statuses: publish, future, draft, pending
                        'meta_query' => array(
                            array(
                                'key' => 'envirolink_source_url',
                                'value' => $original_link,
                                'compare' => '='
                            )
                        ),
                        'posts_per_page' => 1
                    ));

                    if (!empty($final_check)) {
                        $this->log_message('→ ⚠ RACE CONDITION DETECTED! Post was created by another process during AI processing.');
                        $this->log_message('   Existing Post ID: ' . $final_check[0]->ID . ' - "' . $final_check[0]->post_title . '"');
                        $this->log_message('   SKIPPING creation to prevent duplicate.');
                        $total_skipped++;
                        continue; // Skip this article
                    }

                    // CRITICAL FIX: Use meta_input to store metadata ATOMICALLY with post creation
                    // This eliminates the race condition where post exists but metadata doesn't
                    $post_data['meta_input'] = array(
                        'envirolink_source_url' => $original_link,
                        'envirolink_source_name' => $feed['name'],
                        'envirolink_original_title' => $original_title,
                        'envirolink_content_hash' => $content_hash
                    );

                    // Add feed metadata to meta_input if present
                    if (isset($feed_metadata['author'])) {
                        $post_data['meta_input']['envirolink_author'] = $feed_metadata['author'];
                    }
                    if (isset($feed_metadata['pubdate'])) {
                        $post_data['meta_input']['envirolink_pubdate'] = $feed_metadata['pubdate'];
                    }
                    if (isset($feed_metadata['topic_tags'])) {
                        $post_data['meta_input']['envirolink_topic_tags'] = $feed_metadata['topic_tags'];
                    }
                    if (isset($feed_metadata['locations'])) {
                        $post_data['meta_input']['envirolink_locations'] = $feed_metadata['locations'];
                    }

                    $post_id = wp_insert_post($post_data);

                    if ($post_id) {
                        $this->log_message('→ Created new post successfully (Post ID: ' . $post_id . ')');
                        $this->log_message('→ Stored source URL atomically for duplicate detection: ' . $original_link);

                        // Convert topic tags to WordPress tags
                        if (isset($feed_metadata['topic_tags'])) {
                            // Check if ontology filtering is enabled
                            if (get_option('envirolink_ontology_enabled', 'no') === 'yes') {
                                // Filter tags through ontology
                                $filtered_tags = $this->filter_tags_with_ontology($feed_metadata['topic_tags']);
                                if (!empty($filtered_tags)) {
                                    wp_set_post_tags($post_id, $filtered_tags, false);
                                    $this->log_message('    → Applied ' . count($filtered_tags) . ' ontology-filtered tags: ' . implode(', ', $filtered_tags));
                                } else {
                                    $this->log_message('    → No ontology matches for RSS tags, post left untagged');
                                }
                            } else {
                                // Use raw RSS tags (legacy behavior)
                                $tag_array = array_map('trim', explode(',', $feed_metadata['topic_tags']));
                                wp_set_post_tags($post_id, $tag_array, false);
                            }
                        }

                        // Set featured image if found
                        $image_set_successfully = false;
                        if ($image_url) {
                            $attachment_id = $this->set_featured_image_from_url($image_url, $post_id);
                            if ($attachment_id) {
                                $image_set_successfully = true;
                                $this->log_message('    ✓ Featured image set successfully (ID: ' . $attachment_id . ')');
                            } else {
                                $this->log_message('    ⚠ WARNING: Failed to set featured image - post will publish without image');
                                $this->log_message('    ⚠ Facebook/social shares may appear without image');
                            }
                        }

                        // Set AIOSEO schema markup for better search appearance
                        if (function_exists('aioseo')) {
                            update_post_meta($post_id, '_aioseo_schema_type', 'Article');
                            update_post_meta($post_id, '_aioseo_schema_article_type', 'NewsArticle');
                        }

                        // Transition to desired post status (triggers Jetpack Social hooks)
                        // Only transition if status should be 'publish', otherwise leave as draft
                        if ($post_status === 'publish') {
                            // CRITICAL FIX: Ensure Open Graph metadata is fresh before Jetpack shares
                            // Clear WordPress object cache to force regeneration of post metadata
                            wp_cache_delete($post_id, 'post_meta');
                            wp_cache_delete($post_id, 'posts');
                            clean_post_cache($post_id);

                            // CRITICAL: Always use current time when publishing to prevent "Scheduled" status
                            // WordPress auto-marks posts as 'future' if post_date is ahead of server time
                            // This can happen due to timezone differences in RSS feeds
                            $current_time = current_time('mysql');
                            wp_update_post(array(
                                'ID' => $post_id,
                                'post_status' => 'publish',
                                'post_date' => $current_time,
                                'post_date_gmt' => get_gmt_from_date($current_time)
                            ));

                            if ($image_set_successfully) {
                                $this->log_message('→ Published post with featured image (triggers Jetpack Social sharing)');
                            } else {
                                $this->log_message('→ Published post WITHOUT featured image (triggers Jetpack Social sharing)');
                            }
                        } else {
                            $this->log_message('→ Post saved as draft (per settings)');
                        }

                        $total_created++;
                    }
                }
            }

            // Update last processed time for this feed
            $feeds[$index]['last_processed'] = time();
        }

        // Save updated feeds with last_processed times
        update_option('envirolink_feeds', $feeds);
        
        update_option('envirolink_last_run', current_time('mysql'));

        $message = "Processed {$total_processed} articles";
        if ($total_created > 0) {
            $message .= ", created {$total_created} new posts";
        }
        if ($total_updated > 0) {
            $message .= ", updated {$total_updated} existing posts";
        }
        if ($total_skipped > 0) {
            $message .= ", skipped {$total_skipped} unchanged articles (saved AI costs)";
        }
        if (!empty($failed_feeds)) {
            $message .= ". Warning: Failed to fetch " . count($failed_feeds) . " feed(s): " . implode('; ', $failed_feeds);
        }

        // Run automatic duplicate cleanup if enabled
        if (get_option('envirolink_auto_cleanup_duplicates', 'yes') === 'yes') {
            $this->log_message('');
            $this->log_message('=== Running Automatic Duplicate Cleanup ===');
            $this->log_message('Scanning for any duplicates that slipped through...');

            $cleanup_result = $this->cleanup_duplicates();

            if ($cleanup_result['success']) {
                $this->log_message('✓ Auto-cleanup complete: ' . $cleanup_result['message']);

                // Add cleanup results to main message if any duplicates were found
                if (strpos($cleanup_result['message'], 'Deleted') !== false) {
                    $message .= '. ' . $cleanup_result['message'];
                }
            } else {
                $this->log_message('✗ Auto-cleanup failed: ' . $cleanup_result['message']);
            }
        } else {
            $this->log_message('');
            $this->log_message('(Automatic duplicate cleanup is disabled)');
        }

        // Clear progress tracking
        $this->clear_progress();

        // Release the processing lock
        delete_transient('envirolink_processing_lock');
        $lock_duration = time() - $lock_data['start_time'];
        error_log('EnviroLink: Lock released (PID: ' . $lock_data['pid'] . ', Duration: ' . $lock_duration . 's)');

        return array(
            'success' => true,
            'message' => $message
        );
    }

    /**
     * Check if a process ID is still alive
     * Cross-platform: works on Unix/Linux and Windows
     *
     * @param int $pid Process ID to check
     * @return bool True if process is running, false if dead/doesn't exist
     */
    private function is_process_alive($pid) {
        if (empty($pid) || !is_numeric($pid)) {
            return false;
        }

        // Unix/Linux/Mac: Use posix_kill with signal 0 (check only, doesn't actually kill)
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        // Windows fallback: Use shell command
        if (stripos(PHP_OS, 'WIN') === 0) {
            $output = array();
            exec("tasklist /FI \"PID eq $pid\" /NH 2>NUL", $output);
            return count($output) > 0 && strpos($output[0], (string)$pid) !== false;
        }

        // Generic Unix fallback: Check if /proc/PID exists (Linux)
        if (file_exists('/proc/' . $pid)) {
            return true;
        }

        // Last resort: Try ps command
        $output = array();
        exec("ps -p $pid 2>/dev/null", $output);
        return count($output) > 1; // More than just header line
    }

    /**
     * Update lock heartbeat to prevent stale lock detection
     * Call this periodically during long-running operations
     */
    private function update_lock_heartbeat() {
        $lock_key = 'envirolink_processing_lock';
        $lock_data = get_transient($lock_key);

        if ($lock_data && isset($lock_data['pid']) && $lock_data['pid'] == getmypid()) {
            // Only update if we own the lock
            $lock_data['last_heartbeat'] = time();
            set_transient($lock_key, $lock_data, 1800); // Extend to 30 minutes
            error_log('EnviroLink: Lock heartbeat updated (PID: ' . $lock_data['pid'] . ')');
        }
    }

    /**
     * Custom User-Agent for RSS feed requests
     * Helps avoid being blocked by feed providers
     */
    public function custom_http_request_args($args, $url) {
        $args['user-agent'] = 'EnviroLink News Aggregator/1.2 (+https://envirolink.org; WordPress/' . get_bloginfo('version') . ')';
        $args['timeout'] = 15; // Increase timeout to 15 seconds
        return $args;
    }

    /**
     * Extract and download image from RSS feed item
     * Tries multiple strategies to find images from different RSS formats
     */
    private function extract_feed_image($item) {
        $strategies = array();

        // Strategy 1: Media RSS namespace (media:content, media:thumbnail)
        $media_content = $item->get_item_tags('http://search.yahoo.com/mrss/', 'content');
        if ($media_content && isset($media_content[0]['attribs']['']['url'])) {
            $url = $media_content[0]['attribs']['']['url'];
            if ($this->is_valid_image_url($url)) {
                $strategies[] = 'media:content';
                $this->log_message('  → Found image via media:content');
                return $this->enhance_image_quality($url);
            }
        }

        $media_thumbnail = $item->get_item_tags('http://search.yahoo.com/mrss/', 'thumbnail');
        if ($media_thumbnail && isset($media_thumbnail[0]['attribs']['']['url'])) {
            $url = $media_thumbnail[0]['attribs']['']['url'];
            if ($this->is_valid_image_url($url)) {
                $strategies[] = 'media:thumbnail';
                $this->log_message('  → Found image via media:thumbnail');
                return $this->enhance_image_quality($url);
            }
        }

        // Strategy 2: Enclosure (Mongabay style)
        $enclosure = $item->get_enclosure();
        if ($enclosure && $enclosure->get_thumbnail()) {
            $url = $enclosure->get_thumbnail();
            if ($this->is_valid_image_url($url)) {
                $strategies[] = 'enclosure thumbnail';
                $this->log_message('  → Found image via enclosure thumbnail');
                return $this->enhance_image_quality($url);
            }
        }
        if ($enclosure && $enclosure->get_link()) {
            $url = $enclosure->get_link();
            if ($this->is_valid_image_url($url)) {
                $strategies[] = 'enclosure link';
                $this->log_message('  → Found image via enclosure link');
                return $this->enhance_image_quality($url);
            }
        }

        // Strategy 3: Parse content for <img> tags with various attributes
        $content = $item->get_content();
        if ($content) {
            $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5);

            // Try standard src
            if (preg_match('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches)) {
                if ($this->is_valid_image_url($matches[1])) {
                    $strategies[] = 'content img src';
                    $this->log_message('  → Found image in content via img src');
                    return $this->enhance_image_quality($matches[1]);
                }
            }

            // Try data-src (lazy loading)
            if (preg_match('/<img[^>]+data-src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches)) {
                if ($this->is_valid_image_url($matches[1])) {
                    $strategies[] = 'content img data-src';
                    $this->log_message('  → Found image in content via data-src');
                    return $this->enhance_image_quality($matches[1]);
                }
            }

            // Try srcset (get first URL from srcset)
            if (preg_match('/<img[^>]+srcset=["\']([^"\']+)["\'][^>]*>/i', $content, $matches)) {
                $srcset = $matches[1];
                // Extract first URL from srcset (format: "url 1x, url 2x")
                if (preg_match('/([^\s,]+)/', $srcset, $url_match)) {
                    if ($this->is_valid_image_url($url_match[1])) {
                        $strategies[] = 'content img srcset';
                        $this->log_message('  → Found image in content via srcset');
                        return $this->enhance_image_quality($url_match[1]);
                    }
                }
            }
        }

        // Strategy 4: Parse description for images
        $description = $item->get_description();
        if ($description) {
            $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5);

            if (preg_match('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $description, $matches)) {
                if ($this->is_valid_image_url($matches[1])) {
                    $strategies[] = 'description img src';
                    $this->log_message('  → Found image in description');
                    return $this->enhance_image_quality($matches[1]);
                }
            }
        }

        $this->log_message('  → No image found (tried: enclosure, media tags, content, description)');
        return null;
    }

    /**
     * Extract image from article URL by fetching the page
     * Looks for Open Graph, Twitter Card, and other meta images
     */
    private function extract_image_from_url($url) {
        // Fetch the article page
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'user-agent' => 'EnviroLink News Aggregator/1.5 (+https://envirolink.org; WordPress/' . get_bloginfo('version') . ')',
            'headers' => array(
                'Accept' => 'text/html'
            )
        ));

        if (is_wp_error($response)) {
            $this->log_message('    ✗ Failed to fetch article page: ' . $response->get_error_message());
            return null;
        }

        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            return null;
        }

        // Strategy 1: Open Graph image (og:image)
        if (preg_match('/<meta\s+property=["\']og:image["\']\s+content=["\']([^"\']+)["\']/i', $html, $matches)) {
            $img_url = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
            if ($this->is_valid_image_url($img_url)) {
                $this->log_message('    ✓ Found via Open Graph (og:image)');
                return $this->enhance_image_quality($img_url);
            }
        }

        // Also try reversed attribute order
        if (preg_match('/<meta\s+content=["\']([^"\']+)["\']\s+property=["\']og:image["\']/i', $html, $matches)) {
            $img_url = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
            if ($this->is_valid_image_url($img_url)) {
                $this->log_message('    ✓ Found via Open Graph (og:image)');
                return $this->enhance_image_quality($img_url);
            }
        }

        // Strategy 2: Twitter Card image
        if (preg_match('/<meta\s+name=["\']twitter:image["\']\s+content=["\']([^"\']+)["\']/i', $html, $matches)) {
            $img_url = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
            if ($this->is_valid_image_url($img_url)) {
                $this->log_message('    ✓ Found via Twitter Card');
                return $this->enhance_image_quality($img_url);
            }
        }

        // Also try reversed attribute order
        if (preg_match('/<meta\s+content=["\']([^"\']+)["\']\s+name=["\']twitter:image["\']/i', $html, $matches)) {
            $img_url = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
            if ($this->is_valid_image_url($img_url)) {
                $this->log_message('    ✓ Found via Twitter Card');
                return $this->enhance_image_quality($img_url);
            }
        }

        // Strategy 3: Look for first large image in article
        // Find all img tags and get the first one that looks substantial
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            foreach ($matches[1] as $img_url) {
                $img_url = html_entity_decode($img_url, ENT_QUOTES | ENT_HTML5);

                // Skip small images (icons, logos, social media buttons)
                if (preg_match('/(icon|logo|avatar|social|button|share|pixel|1x1)/i', $img_url)) {
                    continue;
                }

                if ($this->is_valid_image_url($img_url)) {
                    $this->log_message('    ✓ Found first substantial image in article');
                    return $this->enhance_image_quality($img_url);
                }
            }
        }

        $this->log_message('    ✗ No images found on article page');
        return null;
    }

    /**
     * Enhance image URL quality for known news sites
     * Specifically handles Guardian images which use query parameters for size/quality
     */
    private function enhance_image_quality($img_url) {
        // Guardian images: Increase width and quality parameters
        if (strpos($img_url, 'i.guim.co.uk') !== false || strpos($img_url, 'theguardian.com') !== false) {
            $parsed = parse_url($img_url);

            // Parse existing query parameters
            $query_params = array();
            if (isset($parsed['query'])) {
                parse_str($parsed['query'], $query_params);
            }

            // Check the actual width being requested
            $current_width = isset($query_params['width']) ? intval($query_params['width']) : 0;

            // Guardian URLs have format: /media/[id]/[crop]/[variant]/[size].jpg
            // Example: /master/3073.jpg means full master image is 3073px wide
            // If RSS gives us width=140 but path shows /master/2000.jpg, we want the 2000px version!

            // Extract the actual image size from the path (if master variant)
            $path_size = 0;
            if (preg_match('/\/master\/(\d+)\.jpg/i', $parsed['path'], $matches)) {
                $path_size = intval($matches[1]);
            }

            // If URL has signature (authenticated), check width
            if (isset($query_params['s'])) {
                if ($current_width >= 500) {
                    // Signature + good width: Preserve as-is
                    $this->log_message('    → Guardian URL has signature and good width (' . $current_width . 'px), preserving');
                    return $img_url;
                } else {
                    // Signature + small width: Can't modify without breaking auth
                    // Return null to trigger article scraping fallback (gets better 1200px Open Graph images)
                    $this->log_message('    → Guardian URL has signature but small width (' . $current_width . 'px)');
                    $this->log_message('    → Cannot modify signed URL, returning null to trigger article scraping');
                    return null;
                }
            }

            // No signature: Safe to enhance
            // If current width is small (< 500px) but we have a large master size available
            if ($current_width < 500 && $path_size >= 500) {
                $this->log_message('    → Guardian URL has small width (' . $current_width . 'px) but master is ' . $path_size . 'px');
                $this->log_message('    → No signature detected, requesting full master size');

                // Request the full master size (or 1920px max for reasonable file size)
                $query_params['width'] = min($path_size, 1920);
                $query_params['quality'] = 85;
                $query_params['auto'] = 'format';
                $query_params['fit'] = 'bounds';

                // Rebuild URL
                $base_url = $parsed['scheme'] . '://' . $parsed['host'] . $parsed['path'];
                $enhanced_url = $base_url . '?' . http_build_query($query_params);

                $this->log_message('    → Enhanced to width=' . $query_params['width'] . 'px, quality=85');
                return $enhanced_url;
            }

            // No signature - safe to enhance parameters
            // Set high quality parameters
            $query_params['width'] = 1920;  // High resolution width
            $query_params['quality'] = 85;   // High quality (Guardian max is typically 85)
            $query_params['auto'] = 'format';
            $query_params['fit'] = 'bounds'; // Maintain aspect ratio

            // Rebuild URL
            $base_url = $parsed['scheme'] . '://' . $parsed['host'] . $parsed['path'];
            $enhanced_url = $base_url . '?' . http_build_query($query_params);

            $this->log_message('    → Enhanced Guardian image quality: width=1920, quality=85');
            return $enhanced_url;
        }

        // BBC images: Upgrade width in URL path
        // BBC formats:
        // 1. https://ichef.bbci.co.uk/news/[WIDTH]/...
        // 2. https://ichef.bbci.co.uk/ace/standard/[WIDTH]/...
        // 3. https://ichef.bbci.co.uk/ace/ws/[WIDTH]/...
        // 4. https://ichef.bbci.co.uk/images/ic/[WIDTHxHEIGHT]/...
        // Widths: 240, 320, 480, 640, 800, 976, 1024 (max)
        if (strpos($img_url, 'ichef.bbci.co.uk') !== false) {
            $parsed = parse_url($img_url);

            // Pattern 1-3: /news/WIDTH/ or /ace/standard/WIDTH/ or /ace/ws/WIDTH/
            if (preg_match('#/(news|ace/standard|ace/ws)/(\d+)/#', $parsed['path'], $matches)) {
                $current_width = intval($matches[2]);
                $path_prefix = $matches[1]; // 'news' or 'ace/standard' or 'ace/ws'

                if ($current_width < 1024) {
                    $this->log_message('    → BBC URL has width ' . $current_width . 'px, upgrading to 1024px');

                    // Replace the width in the path with 1024
                    $new_path = preg_replace(
                        '#/(news|ace/standard|ace/ws)/\d+/#',
                        '/' . $path_prefix . '/1024/',
                        $parsed['path']
                    );

                    // Rebuild URL
                    $enhanced_url = $parsed['scheme'] . '://' . $parsed['host'] . $new_path;
                    if (isset($parsed['query'])) {
                        $enhanced_url .= '?' . $parsed['query'];
                    }

                    $this->log_message('    → Enhanced BBC image to 1024px');
                    return $enhanced_url;
                } else {
                    $this->log_message('    → BBC URL already at maximum width (' . $current_width . 'px)');
                    return $img_url;
                }
            }

            // Pattern 4: /images/ic/WIDTHxHEIGHT/ (e.g., 240x135)
            if (preg_match('#/images/ic/(\d+)x(\d+)/#', $parsed['path'], $matches)) {
                $current_width = intval($matches[1]);
                $current_height = intval($matches[2]);

                if ($current_width < 1024) {
                    // Calculate proportional height maintaining aspect ratio
                    $aspect_ratio = $current_height / $current_width;
                    $new_width = 1024;
                    $new_height = intval($new_width * $aspect_ratio);

                    $this->log_message('    → BBC URL has dimensions ' . $current_width . 'x' . $current_height . ', upgrading to ' . $new_width . 'x' . $new_height);

                    // Replace dimensions in path
                    $new_path = preg_replace(
                        '#/images/ic/\d+x\d+/#',
                        '/images/ic/' . $new_width . 'x' . $new_height . '/',
                        $parsed['path']
                    );

                    // Rebuild URL
                    $enhanced_url = $parsed['scheme'] . '://' . $parsed['host'] . $new_path;
                    if (isset($parsed['query'])) {
                        $enhanced_url .= '?' . $parsed['query'];
                    }

                    $this->log_message('    → Enhanced BBC image dimensions');
                    return $enhanced_url;
                } else {
                    $this->log_message('    → BBC URL already at high resolution (' . $current_width . 'x' . $current_height . ')');
                    return $img_url;
                }
            }

            // If we couldn't parse the width, return as-is
            $this->log_message('    → BBC URL detected but couldn\'t parse width pattern');
            return $img_url;
        }

        // For other sites, check for common quality parameters
        if (preg_match('/[?&](w|width|size)=\d+/i', $img_url)) {
            // Try to increase width parameter
            $img_url = preg_replace('/([?&])(w|width|size)=\d+/i', '$1$2=1920', $img_url);
            $this->log_message('    → Enhanced image width to 1920px');
        }

        return $img_url;
    }

    /**
     * Validate if a URL is a valid image URL
     */
    private function is_valid_image_url($url) {
        if (empty($url)) {
            return false;
        }

        // Check if it's a valid URL
        if (!filter_var($url, FILTER_VALIDATE_URL) && !preg_match('/^\/\//', $url)) {
            return false;
        }

        // Convert protocol-relative URLs
        if (preg_match('/^\/\//', $url)) {
            $url = 'https:' . $url;
        }

        // Check if it has an image extension or is from a known image service
        if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg)($|\?)/i', $url)) {
            return true;
        }

        // Check for known image hosting patterns
        if (preg_match('/(images|img|media|uploads|wp-content|cdn)/i', $url)) {
            return true;
        }

        return false;
    }

    /**
     * Extract metadata from RSS feed item
     */
    private function extract_feed_metadata($item, $feed) {
        $metadata = array();

        // Extract author (dc:creator)
        if (!empty($feed['include_author'])) {
            $author_tags = $item->get_item_tags('http://purl.org/dc/elements/1.1/', 'creator');
            if ($author_tags && isset($author_tags[0]['data'])) {
                $metadata['author'] = $author_tags[0]['data'];
            }
        }

        // Extract publication date (pubDate)
        if (!empty($feed['include_pubdate'])) {
            $pubdate = $item->get_date('c'); // Get in ISO 8601 format
            if ($pubdate) {
                $metadata['pubdate'] = $pubdate;
            }
        }

        // Extract topic tags
        if (!empty($feed['include_topic_tags'])) {
            // Try multiple possible tag fields
            $topic_tags = null;

            // Method 1: Look for custom topic-tags field
            $tags = $item->get_item_tags('', 'topic-tags');
            if ($tags && isset($tags[0]['data'])) {
                $topic_tags = $tags[0]['data'];
            }

            // Method 2: Try categories
            if (!$topic_tags) {
                $categories = $item->get_categories();
                if ($categories) {
                    $tag_list = array();
                    foreach ($categories as $category) {
                        $tag_list[] = $category->get_label();
                    }
                    $topic_tags = implode(', ', $tag_list);
                }
            }

            if ($topic_tags) {
                $metadata['topic_tags'] = $topic_tags;
            }
        }

        // Extract locations
        if (!empty($feed['include_locations'])) {
            $locations = $item->get_item_tags('', 'locations');
            if ($locations && isset($locations[0]['data'])) {
                $metadata['locations'] = $locations[0]['data'];
            }
        }

        return $metadata;
    }

    /**
     * Download image and set as featured image for post
     * @return int|false Attachment ID on success, false on failure
     */
    private function set_featured_image_from_url($image_url, $post_id) {
        if (empty($image_url)) {
            $this->log_message('    ✗ Image URL is empty');
            return false;
        }

        $this->log_message('    → Downloading image from: ' . $image_url);

        // Download image with error handling
        try {
            $tmp = download_url($image_url);
        } catch (Exception $e) {
            $this->log_message('    ✗ Exception during download: ' . $e->getMessage());
            return false;
        }

        if (is_wp_error($tmp)) {
            $this->log_message('    ✗ Failed to download image: ' . $tmp->get_error_message());
            return false;
        }

        $this->log_message('    → Downloaded to temp file: ' . $tmp);

        // Parse URL to get clean filename without query parameters
        // This fixes issues with URLs like "image.jpg?quality=75&strip=all"
        $parsed_url = parse_url($image_url);
        $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        $clean_filename = basename($path); // Get filename WITHOUT query string

        // Fallback if parsing fails
        if (empty($clean_filename)) {
            $clean_filename = 'image-' . time() . '.jpg';
        }

        // CRITICAL: Ensure filename has an extension
        // WordPress requires proper file extensions to determine MIME type
        $file_extension = pathinfo($clean_filename, PATHINFO_EXTENSION);
        if (empty($file_extension)) {
            // No extension found - add .jpg (most common for web images)
            $clean_filename .= '.jpg';
            $this->log_message('    → Added .jpg extension to filename');
        }

        $this->log_message('    → Using filename: ' . $clean_filename);

        // Get file info with clean filename (no query parameters)
        $file_array = array(
            'name' => $clean_filename,
            'tmp_name' => $tmp
        );

        // Upload to media library with error handling
        $this->log_message('    → Starting media_handle_sideload()...');
        try {
            // Require WordPress media functions
            if (!function_exists('media_handle_sideload')) {
                require_once(ABSPATH . 'wp-admin/includes/media.php');
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/image.php');
            }

            $attachment_id = media_handle_sideload($file_array, $post_id);
            $this->log_message('    → media_handle_sideload() completed');
        } catch (Exception $e) {
            $this->log_message('    ✗ Exception during media upload: ' . $e->getMessage());
            @unlink($tmp);
            return false;
        } catch (Error $e) {
            $this->log_message('    ✗ Fatal error during media upload: ' . $e->getMessage());
            @unlink($tmp);
            return false;
        }

        // Cleanup temp file
        @unlink($tmp);

        if (is_wp_error($attachment_id)) {
            $this->log_message('    ✗ Failed to upload to media library: ' . $attachment_id->get_error_message());
            return false;
        }

        $this->log_message('    → Uploaded to media library (ID: ' . $attachment_id . ')');

        // Add alt text for SEO and accessibility
        $post_title = get_the_title($post_id);
        $alt_text = !empty($post_title) ? $post_title : 'Environmental news image';
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
        $this->log_message('    → Added alt text: ' . $alt_text);

        // Set as featured image
        $result = set_post_thumbnail($post_id, $attachment_id);

        if ($result) {
            $this->log_message('    ✓ Set as featured image successfully');
            return $attachment_id; // Return attachment ID for caching
        } else {
            $this->log_message('    ✗ Failed to set as featured image (set_post_thumbnail returned false)');
            return false;
        }
    }

    /**
     * Ensure Jetpack Social (Publicize) works with EnviroLink posts
     *
     * This filter ensures Jetpack Publicize shares EnviroLink posts. We create posts
     * as 'draft' first, then use wp_update_post() to transition to 'publish', which
     * triggers Jetpack's normal publish hooks. This filter ensures Jetpack accepts them.
     */
    public function enable_jetpack_publicize_for_envirolink($should_publicize, $post) {
        if (!$post) {
            return $should_publicize;
        }

        // Check if this is an EnviroLink post (has our metadata)
        $is_envirolink_post = get_post_meta($post->ID, 'envirolink_source_url', true)
                            || get_post_meta($post->ID, 'envirolink_is_roundup', true);

        // If it's an EnviroLink post, always allow Publicize
        if ($is_envirolink_post) {
            return true;
        }

        return $should_publicize;
    }

    /**
     * Rewrite content using Anthropic API
     */
    private function rewrite_with_ai($title, $content, $api_key) {
        $prompt = "You are a news editor for EnviroLink.org, an environmental news aggregation website. Your task is to rewrite the following environmental news article in a clear, engaging style while maintaining factual accuracy.

Original Title: {$title}

Original Content:
{$content}

Please provide:
1. A new, compelling headline (no length limit - use whatever length needed for clarity)
2. A rewritten article summary/content (2-4 paragraphs, around 200-300 words)

Keep the core facts and maintain journalistic integrity. Make it informative and accessible to a general audience interested in environmental issues.

Format your response as:
TITLE: [new headline]
CONTENT: [rewritten content]";
        
        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'timeout' => 60,
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01'
            ),
            'body' => json_encode(array(
                'model' => 'claude-sonnet-4-20250514',
                'max_tokens' => 1024,
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => $prompt
                    )
                )
            ))
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($body['content'][0]['text'])) {
            return false;
        }
        
        $text = $body['content'][0]['text'];
        
        // Parse response
        if (preg_match('/TITLE:\s*(.+?)(?:\n|$)/s', $text, $title_match) &&
            preg_match('/CONTENT:\s*(.+)$/s', $text, $content_match)) {
            
            return array(
                'title' => trim($title_match[1]),
                'content' => trim($content_match[1])
            );
        }
        
        return false;
    }

    /**
     * Generate daily editorial roundup
     * Called by CRON at 8am ET daily
     * @param bool $manual_run If true, bypasses the enabled check (for manual testing)
     */
    public function generate_daily_roundup($manual_run = false) {
        // Check if feature is enabled (skip check for manual runs)
        if (!$manual_run && get_option('envirolink_daily_roundup_enabled', 'no') !== 'yes') {
            error_log('EnviroLink: Daily roundup skipped - feature is disabled');
            return;
        }

        // Check if roundup already exists for today (only for automatic runs)
        if (!$manual_run) {
            $today_title = 'Daily Environmental News Roundup by the EnviroLink Team - ' . wp_date('F j, Y');
            $existing_roundup = get_posts(array(
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'title' => $today_title,
                'meta_query' => array(
                    array(
                        'key' => 'envirolink_is_roundup',
                        'value' => 'yes',
                        'compare' => '='
                    )
                )
            ));

            if (!empty($existing_roundup)) {
                error_log('EnviroLink: Daily roundup already exists for today - skipping duplicate creation');
                return;
            }
        }

        // Check for active generation lock to prevent race conditions (only for automatic runs)
        if (!$manual_run) {
            $lock_key = 'envirolink_roundup_generation_lock';
            if (get_transient($lock_key)) {
                error_log('EnviroLink: Daily roundup generation already in progress - skipping to prevent duplicates');
                return;
            }
            // Set lock for 5 minutes (300 seconds) - will auto-expire if process crashes
            set_transient($lock_key, time(), 300);
        }

        // Clear previous progress and start logging
        $this->clear_progress();
        $this->log_message('=== DAILY ROUNDUP GENERATION ===');
        $this->log_message('Starting daily roundup generation' . ($manual_run ? ' (manual run)' : ''));
        $this->log_message('NOTE: Feed aggregator is NOT run - using existing articles from past 24 hours');
        $this->update_progress(array(
            'status' => 'running',
            'message' => 'Generating daily roundup...',
            'percent' => 0
        ));

        // Step 1: Get the most recent articles (by when they were ADDED to WordPress, not publication date)
        $this->log_message('');
        $this->log_message('STEP 1: Collecting recent articles for roundup...');
        $this->update_progress(array('percent' => 10, 'message' => 'Selecting articles...'));

        $articles = get_posts(array(
            'post_type' => 'post',
            'posts_per_page' => 30, // Get last 30 articles added to WordPress
            'meta_query' => array(
                array(
                    'key' => 'envirolink_source_url',
                    'compare' => 'EXISTS'
                )
            ),
            'orderby' => 'ID', // Order by ID = order by when added to WordPress
            'order' => 'DESC'
        ));

        if (empty($articles)) {
            $this->log_message('✗ No EnviroLink articles found - cannot create roundup');
            $this->update_progress(array(
                'status' => 'error',
                'message' => 'No articles available',
                'percent' => 100
            ));
            // Clear generation lock on error
            if (!$manual_run) {
                delete_transient('envirolink_roundup_generation_lock');
            }
            return;
        }

        $this->log_message('✓ Found ' . count($articles) . ' recent articles for roundup');

        // Step 2: Prepare article summaries for AI
        $this->log_message('');
        $this->log_message('STEP 2: Preparing article summaries...');
        $this->update_progress(array('percent' => 30, 'message' => 'Preparing summaries...'));

        $article_summaries = array();
        foreach ($articles as $article) {
            $source_name = get_post_meta($article->ID, 'envirolink_source_name', true);
            $original_title = get_post_meta($article->ID, 'envirolink_original_title', true);

            $article_summaries[] = array(
                'title' => $article->post_title,
                'original_title' => $original_title,
                'source' => $source_name,
                'excerpt' => wp_trim_words($article->post_content, 50, '...'),
                'url' => get_permalink($article->ID)
            );
        }

        $this->log_message('✓ Prepared ' . count($article_summaries) . ' article summaries');

        // Step 3: Generate editorial content with AI
        $this->log_message('');
        $this->log_message('STEP 3: Generating roundup content with Claude AI...');
        $this->update_progress(array('percent' => 50, 'message' => 'Generating content with AI...'));

        $api_key = get_option('envirolink_api_key');
        if (empty($api_key)) {
            $this->log_message('✗ Cannot generate roundup - Anthropic API key not configured');
            $this->update_progress(array(
                'status' => 'error',
                'message' => 'API key missing',
                'percent' => 100
            ));
            // Clear generation lock on error
            if (!$manual_run) {
                delete_transient('envirolink_roundup_generation_lock');
            }
            return;
        }

        $roundup_content = $this->generate_roundup_with_ai($article_summaries, $api_key);

        if (!$roundup_content) {
            $this->log_message('✗ AI failed to generate roundup content');
            $this->update_progress(array(
                'status' => 'error',
                'message' => 'AI generation failed',
                'percent' => 100
            ));
            // Clear generation lock on error
            if (!$manual_run) {
                delete_transient('envirolink_roundup_generation_lock');
            }
            return;
        }

        $this->log_message('✓ AI generated roundup content successfully');

        // Step 4: Create the roundup post
        $this->log_message('');
        $this->log_message('STEP 4: Creating roundup post...');
        $this->update_progress(array('percent' => 70, 'message' => 'Creating roundup post...'));

        $post_category = get_option('envirolink_post_category');
        // Use WordPress timezone for dates (respects Settings → General → Timezone)
        $today_date = wp_date('F j, Y'); // e.g., "November 3, 2025"
        $month_day = wp_date('M j'); // e.g., "Nov 8"
        $date_string = wp_date('D, M j Y'); // e.g., "Sun, Nov 9 2025" for AI prompt

        // STEP 4a: Generate editorial metadata with AI (headline, dek, image_alt)
        $this->log_message('→ Attempting AI editorial metadata generation...');
        $ai_metadata = $this->generate_roundup_metadata_with_ai($article_summaries, $api_key, $date_string);

        // STEP 4b: Process AI metadata or fallback to simple format
        if ($ai_metadata && isset($ai_metadata['headline']) && isset($ai_metadata['dek'])) {
            // SUCCESS: Use AI-generated editorial metadata
            $this->log_message('✓ Using AI-generated editorial metadata');

            $post_title = $ai_metadata['headline'];
            $dek = $ai_metadata['dek']; // This becomes post_excerpt
            $image_alt_text = isset($ai_metadata['image_alt']) ? $ai_metadata['image_alt'] : 'Environmental news imagery';

            // Derive SEO fields from AI-generated headline/dek (Hybrid Option B)
            // SEO Title: Shorten headline if needed, optimize for 60 chars
            $seo_title = strlen($post_title) <= 60 ? $post_title : substr($post_title, 0, 57) . '...';

            // Meta Description: Use dek (already 35-55 words), trim to 160 chars max
            $meta_description = strlen($dek) <= 160 ? $dek : substr($dek, 0, 157) . '...';

            // OG Title: Use SEO title
            $og_title = $seo_title;

        } else {
            // FALLBACK: AI generation failed, use v1.36.0 format
            $this->log_message('⚠ AI metadata generation failed, using fallback format');
            $this->log_message('⚠ WARNING: This roundup will use generic title/description');

            // Store failure for admin alert
            set_transient('envirolink_metadata_generation_failed', array(
                'timestamp' => current_time('mysql'),
                'error' => 'AI metadata generation returned false or incomplete data'
            ), DAY_IN_SECONDS);

            $article_count = count($articles);
            $top_article = $articles[0];
            $top_title = $top_article->post_title;

            // Extract first sentence from top article content for description teaser
            $top_content = strip_tags($top_article->post_content);
            $sentences = preg_split('/(?<=[.!?])\s+/', $top_content, 2);
            $first_sentence = !empty($sentences[0]) ? $sentences[0] : wp_trim_words($top_content, 15, '...');

            // Create dynamic title with top story highlight
            $post_title = 'Environmental News Roundup: ' . $top_title;

            // Create engaging meta description with preview, count, and CTA
            $other_count = $article_count - 1; // Subtract the top story
            $dek = $first_sentence . ' Plus: ' . $other_count . ' more stories on climate action, wildlife conservation, and green energy. Read the full roundup →';

            // Trim to 155 characters for optimal SEO display in search results
            if (strlen($dek) > 155) {
                $dek = substr($dek, 0, 152) . '...';
            }

            $meta_description = $dek;
            $seo_title = strlen($post_title) <= 60 ? $post_title : substr($post_title, 0, 57) . '...';
            $og_title = $seo_title;
            $image_alt_text = 'Environmental news and nature photography';
        }

        // IMPORTANT: Create as 'draft' first, then transition to 'publish'
        // This triggers Jetpack Social's publish hooks properly
        $post_data = array(
            'post_title' => $post_title,
            'post_content' => $roundup_content,
            'post_status' => 'draft', // Always start as draft
            'post_type' => 'post',
            'post_author' => $this->get_envirolink_editor_id(),
            'post_excerpt' => $dek
        );

        // Set categories: configured category + "Featured" (NOT newsfeed - roundups are editorial, not RSS)
        $categories = array();
        if ($post_category) {
            $categories[] = $post_category;
        }

        // Get or create "Featured" category for roundups
        $featured_cat = get_category_by_slug('featured');
        if (!$featured_cat) {
            $featured_id = wp_insert_term('Featured', 'category', array(
                'slug' => 'featured',
                'description' => 'Featured daily editorial roundups'
            ));
            if (!is_wp_error($featured_id)) {
                $categories[] = $featured_id['term_id'];
            }
        } else {
            $categories[] = $featured_cat->term_id;
        }

        if (!empty($categories)) {
            $post_data['post_category'] = $categories;
        }

        $post_id = wp_insert_post($post_data);

        if ($post_id) {
            // Mark as roundup post IMMEDIATELY to prevent race condition duplicates
            update_post_meta($post_id, 'envirolink_is_roundup', 'yes');

            $this->log_message('✓ Created roundup post (ID: ' . $post_id . ')');

            // Add additional metadata
            update_post_meta($post_id, 'envirolink_roundup_date', current_time('mysql'));
            update_post_meta($post_id, 'envirolink_roundup_article_count', count($articles));

            // Apply ontology-based tags from included articles
            if (get_option('envirolink_ontology_enabled', 'no') === 'yes') {
                $this->log_message('Applying ontology tags from included articles...');
                $all_tags = array();

                // Collect tags from all articles in the roundup
                foreach ($articles as $article) {
                    $article_tags = wp_get_post_tags($article->ID, array('fields' => 'names'));
                    if (!empty($article_tags)) {
                        $all_tags = array_merge($all_tags, $article_tags);
                    }
                }

                // Get unique tags
                $all_tags = array_unique($all_tags);

                if (!empty($all_tags)) {
                    // Apply tags to roundup
                    wp_set_post_tags($post_id, $all_tags, false);
                    $this->log_message('→ Applied ' . count($all_tags) . ' ontology tags: ' . implode(', ', $all_tags));
                } else {
                    $this->log_message('→ No tags found on included articles');
                }
            }

            // Set AIOSEO meta for better SEO (using AI-derived fields)
            if (function_exists('aioseo')) {
                update_post_meta($post_id, '_aioseo_title', $seo_title); // SEO title (≤60 chars)
                update_post_meta($post_id, '_aioseo_description', $meta_description); // Meta description
                update_post_meta($post_id, '_aioseo_og_title', $og_title); // Open Graph title
                update_post_meta($post_id, '_aioseo_og_description', $meta_description); // OG description
                update_post_meta($post_id, '_aioseo_og_article_section', 'Environment');
                update_post_meta($post_id, '_aioseo_og_article_tags', 'environmental news,climate change,conservation,sustainability');
                update_post_meta($post_id, '_aioseo_schema_type', 'Article');
                update_post_meta($post_id, '_aioseo_schema_article_type', 'NewsArticle');
            }

            // Set featured image - try multiple strategies
            $this->log_message('');
            $this->log_message('STEP 5: Setting featured image...');
            $this->update_progress(array('percent' => 90, 'message' => 'Adding featured image...'));

            $image_id = false;
            $unsplash_succeeded = false; // Track if Unsplash actually worked (not just enabled)
            $auto_fetch_unsplash = get_option('envirolink_roundup_auto_fetch_unsplash', 'no') === 'yes';
            $roundup_images = get_option('envirolink_roundup_images', array());

            // STRATEGY 1: Pexels (if enabled)
            if ($auto_fetch_unsplash) {
                $this->log_message('[PEXELS] Attempting to fetch from Pexels...');
                // Pass roundup headline to get relevant image (instead of random generic photo)
                $pexels_data = $this->fetch_pexels_image($post_title);

                if ($pexels_data) {
                    // Download and upload the image to WordPress media library (SAME AS FEED IMAGES)
                    $this->log_message('[PEXELS] Downloading image: ' . $pexels_data['url']);
                    $image_id = $this->set_featured_image_from_url($pexels_data['url'], $post_id);

                    if ($image_id) {
                        // Update attachment metadata with Pexels attribution
                        $caption = sprintf(
                            'Photo by <a href="%s" target="_blank" rel="noopener">%s</a> on <a href="%s" target="_blank" rel="noopener">Pexels</a>',
                            esc_url($pexels_data['photo_link']),
                            esc_html($pexels_data['photographer_name']),
                            esc_url($pexels_data['pexels_link'])
                        );

                        wp_update_post(array(
                            'ID' => $image_id,
                            'post_excerpt' => $caption
                        ));

                        // Store attribution data on the POST
                        update_post_meta($post_id, '_pexels_attribution', array(
                            'photographer_name' => $pexels_data['photographer_name'],
                            'photo_link' => $pexels_data['photo_link'],
                            'pexels_link' => $pexels_data['pexels_link']
                        ));

                        $unsplash_succeeded = true; // Mark success (variable name kept for backward compat)
                        $this->log_message('[PEXELS] ✓ Downloaded and set as featured image (ID: ' . $image_id . ')');
                    } else {
                        $this->log_message('[PEXELS] ✗ Failed to download/upload Pexels image');
                    }
                } else {
                    $this->log_message('[PEXELS] ✗ Pexels fetch failed (check error logs for details)');
                }
            } else {
                $this->log_message('[PEXELS] Auto-fetch is disabled');
            }

            // STRATEGY 2: Manual collection (if Unsplash disabled or failed)
            if (!$image_id && !empty($roundup_images)) {
                $this->log_message('[MANUAL] Trying manual collection (' . count($roundup_images) . ' images available)');
                $image_id = $roundup_images[array_rand($roundup_images)];

                if (wp_get_attachment_url($image_id)) {
                    $this->log_message('[MANUAL] ✓ Using collection image (ID: ' . $image_id . ')');
                } else {
                    $this->log_message('[MANUAL] ✗ Selected image ID ' . $image_id . ' does not exist');
                    $image_id = false;
                }
            } else if (!$image_id) {
                $this->log_message('[MANUAL] Collection is empty (no images uploaded)');
            }

            // STRATEGY 3: Use featured image from first article in roundup (fallback)
            if (!$image_id && !empty($articles)) {
                $this->log_message('[FALLBACK] Trying to use image from first article...');
                $first_article_thumbnail = get_post_thumbnail_id($articles[0]->ID);
                if ($first_article_thumbnail) {
                    $image_id = $first_article_thumbnail;
                    $this->log_message('[FALLBACK] ✓ Using image from first article (ID: ' . $image_id . ')');
                } else {
                    $this->log_message('[FALLBACK] ✗ First article has no featured image');
                }
            }

            // Set the featured image if we have one (from manual or fallback strategies)
            // Note: Unsplash strategy already sets featured image via set_featured_image_from_url()
            if ($image_id && !$unsplash_succeeded) {
                // Manual or fallback strategy - need to set the thumbnail
                set_post_thumbnail($post_id, $image_id);
                // Add AI-generated alt text to the image
                update_post_meta($image_id, '_wp_attachment_image_alt', $image_alt_text);
                $this->log_message('✓ Set featured image (ID: ' . $image_id . ') for roundup');
                $this->log_message('   Alt text: ' . $image_alt_text);
            } else if ($image_id && $unsplash_succeeded) {
                // Unsplash succeeded and already set the image, just update alt text
                update_post_meta($image_id, '_wp_attachment_image_alt', $image_alt_text);
                $this->log_message('✓ Updated Unsplash image alt text: ' . $image_alt_text);
            } else if (!$image_id) {
                $this->log_message('✗ WARNING: No image available from any strategy');
                $this->log_message('   To fix: Enable Unsplash auto-fetch OR upload images to manual collection');
            }

            // Transition roundup from draft to publish (triggers Jetpack Social hooks)
            wp_update_post(array(
                'ID' => $post_id,
                'post_status' => 'publish'
            ));
            $this->log_message('✓ Published roundup (triggers Jetpack Social sharing)');

            $this->log_message('');
            $this->log_message('=== ROUNDUP COMPLETE ===');
            $this->log_message('✓ Daily roundup published successfully!');
            $this->log_message('   Post ID: ' . $post_id);
            $this->log_message('   Title: ' . get_the_title($post_id));
            $this->log_message('   URL: ' . get_permalink($post_id));
            $this->log_message('   Articles included: ' . count($articles));

            $this->update_progress(array(
                'status' => 'complete',
                'message' => 'Roundup generated successfully!',
                'percent' => 100
            ));

            // Clear generation lock on success
            if (!$manual_run) {
                delete_transient('envirolink_roundup_generation_lock');
            }
        } else {
            $this->log_message('✗ Failed to create roundup post');
            $this->update_progress(array(
                'status' => 'error',
                'message' => 'Failed to create post',
                'percent' => 100
            ));

            // Clear generation lock on error
            if (!$manual_run) {
                delete_transient('envirolink_roundup_generation_lock');
            }
        }
    }

    /**
     * Generate editorial roundup content using AI
     */
    private function generate_roundup_with_ai($articles, $api_key) {
        // Build the article list for the prompt
        $articles_text = '';
        foreach ($articles as $i => $article) {
            $num = $i + 1;
            $articles_text .= "\n{$num}. {$article['title']}";
            $articles_text .= "\n   Source: {$article['source']}";
            $articles_text .= "\n   Summary: {$article['excerpt']}\n";
        }

        $prompt = "EnviroLink Daily Editorial Roundup – NPR Earth Briefing Style

You are writing the daily editorial roundup for EnviroLink.org, a respected environmental news website known for trustworthy, accessible coverage.

Your role is to create a cohesive, humanistic, and lightly interpretive piece that connects the day's environmental stories and helps readers understand what's happening, why it matters, and what it means for people and the planet.

Here are today's environmental news articles:
{$articles_text}

✍️ Your Task

Write a 400–600 word editorial-style roundup that:

Opens with a clear framing or insight (1–2 sentences) capturing the day's central thread or tension — what theme ties these stories together?

Example: \"This week, the climate conversation shifted from goals to ground games — how to make change real in the places we live.\"

Connects stories by theme, not by conflict.

Draw out the systems or patterns: policy shifts, economic drivers, community responses, environmental signals.

Interprets significance: Explain why these developments matter — what do they reveal about the state of climate action, biodiversity, or human adaptation?

Includes human-scale moments — a quote, community story, or tangible impact if present in the sources.

Example: \"For families along the coast, that means more nights spent watching tides creep closer to their doorsteps.\"

Maintains a warm, explanatory tone that invites curiosity.

Write like an informed journalist guiding readers through complex terrain, not a detached observer.

Ends with reflection or a forward look — what to watch next, what questions remain, or how today's news fits into longer trends.

🧭 Style and Tone

Voice: Calm, compassionate, informed — like an NPR correspondent connecting dots in a story rather than listing headlines.

Perspective: Observational and interpretive, not opinionated.

Balance: Acknowledge complexity without manufacturing \"two sides.\" If genuine disagreement appears in sources, attribute it clearly and briefly.

Language: Favor verbs of change — revealed, accelerated, signaled, deepened, challenged.

Sentence flow: Mix short, clear sentences with occasional longer, reflective ones to create rhythm.

🧩 Formatting

Format your response as:
CONTENT: [your editorial roundup]

Do not include a title.

🕊️ Extra cue phrases the model should naturally use

\"Across the stories today, a common thread emerges…\"

\"The day's coverage points to growing momentum around…\"

\"It's a reminder that progress and pressure often arrive together.\"

\"Behind the numbers are real communities adapting in real time.\"

\"As the week unfolds, all eyes will be on…\"";

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'timeout' => 90, // Longer timeout for editorial content
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01'
            ),
            'body' => json_encode(array(
                'model' => 'claude-sonnet-4-20250514',
                'max_tokens' => 2048, // More tokens for longer editorial
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => $prompt
                    )
                )
            ))
        ));

        if (is_wp_error($response)) {
            error_log('EnviroLink: AI API error: ' . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($body['content'][0]['text'])) {
            error_log('EnviroLink: AI response missing content');
            return false;
        }

        $text = $body['content'][0]['text'];

        // Parse response
        if (preg_match('/CONTENT:\s*(.+)$/s', $text, $content_match)) {
            return trim($content_match[1]);
        }

        // If parsing fails, return the raw content
        return $text;
    }

    /**
     * Generate editorial metadata (headline, dek, image_alt) for roundup using AI
     * Returns array with metadata or false on failure
     */
    private function generate_roundup_metadata_with_ai($articles, $api_key, $date_string) {
        // Take top 3-5 stories for metadata generation
        $top_stories = array_slice($articles, 0, min(5, count($articles)));

        // Build story list (NO source attribution - appears human-written)
        $stories_text = '';
        foreach ($top_stories as $i => $story) {
            $num = $i + 1;
            $stories_text .= "\n{$num}. {$story['title']}";
            $stories_text .= "\n   {$story['excerpt']}\n";
        }

        $prompt = "You are an editor-bot for EnviroLink (envirolink.org). Your job is to generate clickable, SEO-optimized headlines and summaries (\"deks\") for today's environmental news roundup.

CRITICAL REQUIREMENT - MULTI-STORY HEADLINES:
Your headline MUST reference AT LEAST 2 different stories from the list below. Single-story headlines are FORBIDDEN and will be rejected. The reader must immediately understand this is a roundup of multiple environmental stories, not coverage of one story.

Examples of GOOD multi-story headlines:
✓ \"Amazon Fires Surge, UK Blocks New Drilling — Today's Environmental Briefing for {$date_string}\"
✓ \"Coral Bleaching Accelerates While Solar Adoption Hits Record — Today's Environmental Briefing for {$date_string}\"
✓ \"EPA Tightens Air Rules, Australian Floods Displace Thousands — Today's Environmental Briefing for {$date_string}\"

Examples of BAD single-story headlines (FORBIDDEN):
✗ \"UK Planning Reforms Favor Developers Over Ecologists — Today's Environmental Briefing for {$date_string}\"
✗ \"New Climate Report Shows Alarming Warming Trends — Today's Environmental Briefing for {$date_string}\"
✗ \"Biden Administration Announces Major Conservation Initiative — Today's Environmental Briefing for {$date_string}\"

Editorial requirements:
- MANDATORY: Include 2-3 distinct stories in the headline (use commas, semicolons, or \"while/as\" to separate)
- Lead with the most vivid/visual story first (fires, floods, wildlife > policy, reports)
- Front-load high-value keywords within the first 8-10 words
- ALWAYS end with: \"— Today's Environmental Briefing for {$date_string}\"
- Use active voice, specific nouns/verbs, and clear geography/entities
- No character limit - full date must always be displayed
- No clickbait, no vague \"environmental news roundup\" as the only hook

Style notes:
- US headline case; no smart quotes; no emojis
- Prefer concrete problem words: fires, floods, drilling, plastic, oil spill, toxic, wildfire, smoke, emissions, heatwave, drought, bleaching
- Connect stories with: comma + conjunction, semicolons, or \"while/as\" for contrast

Safety & accuracy:
- Don't overstate (\"millions\" → only if clearly indicated in the story)
- Avoid \"world's worst/biggest\" unless explicitly stated
- Geolocate (country/state/city) when present in stories

Today's top environmental stories:
{$stories_text}

Generate JSON output with these exact fields:
{{
  \"headline\": \"MUST include 2-3 stories separated by punctuation, MUST end with full date '— Today's Environmental Briefing for {$date_string}' - no character limit\",
  \"dek\": \"35-55 words mentioning 2-3 distinct story hooks with specific details (can reference additional stories beyond headline)\",
  \"image_alt\": \"≤120 characters, plain-English description for lead story image\"
}}

IMPORTANT: Respond ONLY with valid JSON. No markdown, no explanation, just the JSON object.";

        $this->log_message('→ Generating editorial metadata with AI...');

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'timeout' => 60,
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01'
            ),
            'body' => json_encode(array(
                'model' => 'claude-sonnet-4-20250514',
                'max_tokens' => 1024,
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => $prompt
                    )
                )
            ))
        ));

        if (is_wp_error($response)) {
            $this->log_message('✗ AI metadata generation failed: ' . $response->get_error_message());
            error_log('EnviroLink: AI metadata generation error: ' . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($body['content'][0]['text'])) {
            $this->log_message('✗ AI response missing content');
            error_log('EnviroLink: AI metadata response missing content');
            return false;
        }

        $text = $body['content'][0]['text'];

        // Parse JSON response
        // Remove markdown code blocks if present
        $text = preg_replace('/```json\s*|\s*```/', '', $text);
        $text = trim($text);

        $metadata = json_decode($text, true);

        if (!$metadata || !isset($metadata['headline']) || !isset($metadata['dek']) || !isset($metadata['image_alt'])) {
            $this->log_message('✗ AI returned invalid JSON or missing required fields');
            $this->log_message('   Raw response: ' . substr($text, 0, 200));
            error_log('EnviroLink: AI metadata JSON parsing failed. Response: ' . $text);
            return false;
        }

        // No character limit - user wants full date always displayed
        // Log headline length for monitoring purposes only
        $headline_length = strlen($metadata['headline']);
        $this->log_message('ℹ Headline length: ' . $headline_length . ' characters');

        if (strlen($metadata['image_alt']) > 120) {
            $this->log_message('⚠ Image alt too long (' . strlen($metadata['image_alt']) . ' chars), truncating...');
            $metadata['image_alt'] = substr($metadata['image_alt'], 0, 117) . '...';
        }

        // Word count validation for dek (should be 35-55 words)
        $dek_word_count = str_word_count($metadata['dek']);
        if ($dek_word_count < 35 || $dek_word_count > 55) {
            $this->log_message('⚠ Dek word count (' . $dek_word_count . ') outside 35-55 range');
            // Don't reject, but log the warning
        }

        $this->log_message('✓ AI generated editorial metadata:');
        $this->log_message('   Headline: ' . $metadata['headline']);
        $this->log_message('   Dek: ' . substr($metadata['dek'], 0, 80) . '...');

        return $metadata;
    }

    /**
     * Fetch environmental image from Pexels API
     * Returns array with image data or false
     */
    private function fetch_pexels_image($headline = null) {
        // Get Pexels API key
        $api_key = get_option('envirolink_pexels_api_key', '');

        if (empty($api_key)) {
            error_log('EnviroLink: [PEXELS] ✗ API key not configured. Please add your Pexels API Key in Settings.');
            return false;
        }

        // Extract keywords from headline for targeted image search
        $query = null;
        if ($headline) {
            $query = $this->extract_image_keywords($headline);
            if ($query) {
                error_log('EnviroLink: [PEXELS] Extracted keywords from headline: ' . $query);

                // Try fetching with specific keywords first
                $result = $this->fetch_from_pexels_api($api_key, $query);
                if ($result) {
                    return $result;
                }

                // If specific search failed, log and fall through to generic
                error_log('EnviroLink: [PEXELS] ⚠ Specific keyword search failed, trying generic nature photo...');
            }
        }

        // Fallback: Random generic nature keywords
        $generic_keywords = array(
            'nature landscape',
            'forest trees',
            'mountain wilderness',
            'ocean water',
            'wildlife animal',
            'green plants',
            'sunset sky',
            'river stream'
        );

        $query = $generic_keywords[array_rand($generic_keywords)];
        error_log('EnviroLink: [PEXELS] Using fallback generic query: ' . $query);

        return $this->fetch_from_pexels_api($api_key, $query);
    }

    /**
     * Fetch environmental image from Unsplash API (DEPRECATED - use Pexels)
     * Kept for backward compatibility
     */
    private function fetch_unsplash_image($headline = null) {
        // Get Unsplash API key
        $api_key = get_option('envirolink_unsplash_api_key', '');

        if (empty($api_key)) {
            error_log('EnviroLink: [UNSPLASH] ✗ API key not configured. Please add your Unsplash Access Key in Settings.');
            return false;
        }

        // Extract keywords from headline for targeted image search
        $query = null;
        if ($headline) {
            $query = $this->extract_image_keywords($headline);
            if ($query) {
                error_log('EnviroLink: [UNSPLASH] Extracted keywords from headline: ' . $query);

                // Try fetching with specific keywords first
                $result = $this->fetch_from_unsplash_api($api_key, $query);
                if ($result) {
                    return $result;
                }

                // If specific search failed, log and fall through to generic
                error_log('EnviroLink: [UNSPLASH] ⚠ Specific keyword search failed, trying generic nature photo...');
            }
        }

        // Fallback: Random generic nature keywords
        $generic_keywords = array(
            'nature landscape',
            'forest trees',
            'mountain wilderness',
            'ocean water',
            'wildlife animal',
            'green plants',
            'sunset sky',
            'river stream'
        );

        $query = $generic_keywords[array_rand($generic_keywords)];
        error_log('EnviroLink: [UNSPLASH] Using fallback generic query: ' . $query);

        return $this->fetch_from_unsplash_api($api_key, $query);
    }

    /**
     * Extract relevant keywords from headline for Unsplash image search
     * Returns comma-separated keywords or null
     */
    private function extract_image_keywords($headline) {
        // Remove common words and extract meaningful keywords
        $headline = strtolower($headline);

        // Remove date patterns, punctuation, and common filler words
        $headline = preg_replace('/—.*$/', '', $headline); // Remove everything after em-dash (usually date)
        $headline = preg_replace('/\[.*?\]/', '', $headline); // Remove bracketed text
        $headline = preg_replace('/[^\w\s]/', ' ', $headline); // Remove punctuation

        // Common words to ignore
        $stop_words = array(
            'today', 'todays', 'environmental', 'briefing', 'news', 'roundup', 'update', 'updates',
            'daily', 'weekly', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with',
            'by', 'from', 'as', 'is', 'are', 'was', 'were', 'been', 'be', 'have', 'has', 'had',
            'more', 'most', 'plus', 'stories', 'story'
        );

        // Visual/photogenic keywords to prioritize
        $visual_keywords = array(
            'fire', 'fires', 'wildfire', 'wildfires', 'smoke', 'flames',
            'flood', 'flooding', 'water', 'ocean', 'sea', 'river', 'lake',
            'forest', 'tree', 'trees', 'jungle', 'rainforest', 'amazon',
            'mountain', 'mountains', 'glacier', 'ice', 'snow',
            'wildlife', 'animal', 'animals', 'bird', 'birds', 'fish', 'whale', 'dolphin', 'bear', 'elephant',
            'coral', 'reef', 'drought', 'desert', 'storm', 'hurricane', 'tornado',
            'pollution', 'plastic', 'oil', 'spill', 'waste', 'toxic',
            'solar', 'wind', 'energy', 'turbine', 'panel', 'panels',
            'city', 'urban', 'protest', 'demonstration',
            'farm', 'agriculture', 'crop', 'crops', 'field', 'fields'
        );

        // Split into words
        $words = preg_split('/\s+/', $headline);
        $keywords = array();

        // Extract visual keywords first (priority)
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) > 2 && !in_array($word, $stop_words) && in_array($word, $visual_keywords)) {
                $keywords[] = $word;
                if (count($keywords) >= 2) {
                    break; // Max 2 keywords for better results
                }
            }
        }

        // If no visual keywords, take any meaningful words
        if (empty($keywords)) {
            foreach ($words as $word) {
                $word = trim($word);
                if (strlen($word) > 3 && !in_array($word, $stop_words)) {
                    $keywords[] = $word;
                    if (count($keywords) >= 2) {
                        break;
                    }
                }
            }
        }

        if (empty($keywords)) {
            return null;
        }

        // Return comma-separated keywords
        return implode(' ', $keywords);
    }

    /**
     * Fetch image from Unsplash API with given query
     * Returns image data array or false
     */
    private function fetch_from_unsplash_api($api_key, $query) {
        // Add negative keywords to filter out weapons and military imagery
        // User request: "I do not want images associated with weapons of war to be shown"
        // Unsplash API supports negative keywords with minus sign prefix (e.g., "nature -gun -weapon")
        $weapon_exclusions = array(
            'gun', 'guns', 'weapon', 'weapons', 'cannon', 'cannons',
            'tank', 'tanks', 'missile', 'missiles', 'military', 'war',
            'soldier', 'soldiers', 'army', 'rifle', 'pistol', 'firearm',
            'ammunition', 'combat', 'battle', 'naval', 'warship'
        );

        foreach ($weapon_exclusions as $exclude) {
            $query .= ' -' . $exclude;
        }

        error_log('EnviroLink: [UNSPLASH] Fetching image with query: ' . $query);

        // Fetch from Unsplash API
        $response = wp_remote_get('https://api.unsplash.com/photos/random?' . http_build_query(array(
            'query' => $query,
            'orientation' => 'landscape',
            'content_filter' => 'high'
        )), array(
            'timeout' => 15,
            'headers' => array(
                'Accept-Version' => 'v1',
                'Authorization' => 'Client-ID ' . $api_key
            )
        ));

        if (is_wp_error($response)) {
            error_log('EnviroLink: [UNSPLASH] ✗ API error: ' . $response->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        // Check for API errors
        if ($status_code !== 200) {
            $error_msg = isset($body['errors']) ? implode(', ', $body['errors']) : 'Unknown error';
            error_log('EnviroLink: [UNSPLASH] ✗ API returned status ' . $status_code . ': ' . $error_msg);
            if ($status_code === 401) {
                error_log('EnviroLink: [UNSPLASH] ✗ Authorization failed. Check if your API key is correct.');
            }
            return false;
        }

        if (!isset($body['urls']['regular']) || !isset($body['id'])) {
            error_log('EnviroLink: [UNSPLASH] ✗ Response missing required data');
            return false;
        }

        // SECONDARY FILTER: Check image description/tags for weapon-related content
        // Even if API filtering worked, double-check the returned image metadata
        $description = isset($body['description']) ? strtolower($body['description']) : '';
        $alt_description = isset($body['alt_description']) ? strtolower($body['alt_description']) : '';
        $combined_text = $description . ' ' . $alt_description;

        $weapon_keywords = array('gun', 'cannon', 'weapon', 'tank', 'missile', 'military', 'war', 'soldier', 'army', 'rifle', 'pistol', 'firearm', 'ammunition');
        foreach ($weapon_keywords as $keyword) {
            if (strpos($combined_text, $keyword) !== false) {
                error_log('EnviroLink: [UNSPLASH] ✗ Image rejected - contains weapon-related keyword: "' . $keyword . '" in description');
                return false; // Reject this image and try again
            }
        }

        // Extract all required data
        $photo_id = $body['id'];
        $image_url = $body['urls']['regular'];
        $photographer_name = isset($body['user']['name']) ? $body['user']['name'] : 'Unknown';
        $photographer_username = isset($body['user']['username']) ? $body['user']['username'] : '';
        $photo_link = isset($body['links']['html']) ? $body['links']['html'] . '?utm_source=envirolink_news&utm_medium=referral' : '';
        $download_location = isset($body['links']['download_location']) ? $body['links']['download_location'] : '';
        $width = isset($body['width']) ? intval($body['width']) : 1920;
        $height = isset($body['height']) ? intval($body['height']) : 1280;

        error_log('EnviroLink: [UNSPLASH] ✓ Found image by ' . $photographer_name . ' (ID: ' . $photo_id . ') - weapon filter passed');

        // COMPLIANCE REQUIREMENT #2: Trigger download endpoint
        // This is REQUIRED by Unsplash API guidelines
        if ($download_location) {
            wp_remote_get($download_location, array(
                'timeout' => 10,
                'headers' => array(
                    'Authorization' => 'Client-ID ' . $api_key
                )
            ));
            error_log('EnviroLink: [UNSPLASH] ✓ Triggered download endpoint for tracking');
        }

        // Return image data instead of downloading
        // We'll hotlink the image (COMPLIANCE REQUIREMENT #1)
        return array(
            'url' => $image_url,
            'photo_id' => $photo_id,
            'photographer_name' => $photographer_name,
            'photographer_username' => $photographer_username,
            'photo_link' => $photo_link,
            'unsplash_link' => 'https://unsplash.com/?utm_source=envirolink_news&utm_medium=referral',
            'width' => $width,
            'height' => $height
        );
    }

    /**
     * Fetch image from Pexels API
     * @param string $api_key Pexels API key
     * @param string $query Search query (keywords)
     * Returns image data array or false
     */
    private function fetch_from_pexels_api($api_key, $query) {
        error_log('EnviroLink: [PEXELS] Fetching image with query: ' . $query);

        // Fetch from Pexels API
        $response = wp_remote_get('https://api.pexels.com/v1/search?' . http_build_query(array(
            'query' => $query,
            'orientation' => 'landscape',
            'per_page' => 1  // Get 1 random result
        )), array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => $api_key
            )
        ));

        if (is_wp_error($response)) {
            error_log('EnviroLink: [PEXELS] ✗ API error: ' . $response->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        // Check for API errors
        if ($status_code !== 200) {
            $error_msg = isset($body['error']) ? $body['error'] : 'Unknown error';
            error_log('EnviroLink: [PEXELS] ✗ API returned status ' . $status_code . ': ' . $error_msg);
            if ($status_code === 401) {
                error_log('EnviroLink: [PEXELS] ✗ Authorization failed. Check if your API key is correct.');
            }
            return false;
        }

        if (!isset($body['photos']) || empty($body['photos'])) {
            error_log('EnviroLink: [PEXELS] ✗ No images found for query: ' . $query);
            return false;
        }

        // Get first result
        $photo = $body['photos'][0];

        if (!isset($photo['src']['large']) || !isset($photo['id'])) {
            error_log('EnviroLink: [PEXELS] ✗ Response missing required data');
            return false;
        }

        // Extract all required data
        $photo_id = $photo['id'];
        $image_url = $photo['src']['large'];  // Use 'large' size (good quality, not huge)
        $photographer_name = isset($photo['photographer']) ? $photo['photographer'] : 'Unknown';
        $photo_link = isset($photo['url']) ? $photo['url'] : '';
        $width = isset($photo['width']) ? intval($photo['width']) : 1920;
        $height = isset($photo['height']) ? intval($photo['height']) : 1280;
        $alt = isset($photo['alt']) ? $photo['alt'] : '';

        error_log('EnviroLink: [PEXELS] ✓ Found image by ' . $photographer_name . ' (ID: ' . $photo_id . ')');

        // Return image data
        return array(
            'url' => $image_url,
            'photo_id' => $photo_id,
            'photographer_name' => $photographer_name,
            'photo_link' => $photo_link,
            'pexels_link' => 'https://www.pexels.com',
            'width' => $width,
            'height' => $height,
            'alt' => $alt
        );
    }

    /**
     * Create WordPress attachment from external Unsplash URL (hotlink)
     * Stores image metadata for attribution display
     * COMPLIANCE REQUIREMENT #3: Displays photographer attribution via caption
     */
    private function create_unsplash_attachment($image_data) {
        // Create attribution caption (REQUIRED by Unsplash API Guidelines)
        // Format: "Photo by [Photographer Name] on Unsplash" with links
        $caption = sprintf(
            'Photo by <a href="%s" target="_blank" rel="noopener">%s</a> on <a href="%s" target="_blank" rel="noopener">Unsplash</a>',
            esc_url($image_data['photo_link']),
            esc_html($image_data['photographer_name']),
            esc_url($image_data['unsplash_link'])
        );

        // Create attachment post (without uploading file - hotlink only)
        $attachment = array(
            'post_title' => 'Photo by ' . $image_data['photographer_name'] . ' on Unsplash',
            'post_excerpt' => $caption, // Caption field in WordPress
            'post_content' => '',
            'post_status' => 'inherit',
            'post_mime_type' => 'image/jpeg',
            'guid' => $image_data['url']
        );

        $attachment_id = wp_insert_attachment($attachment);

        if ($attachment_id) {
            // CRITICAL: Store URL in FIFU's custom field for external image display
            update_post_meta($attachment_id, 'fifu_image_url', $image_data['url']);

            // CRITICAL: Set attachment metadata with image dimensions
            // WordPress needs this to properly display images via the_post_thumbnail()
            $attachment_metadata = array(
                'width' => $image_data['width'],
                'height' => $image_data['height'],
                'file' => $image_data['url'],
                'sizes' => array(
                    'thumbnail' => array(
                        'file' => $image_data['url'],
                        'width' => min(150, $image_data['width']),
                        'height' => min(150, $image_data['height']),
                        'mime-type' => 'image/jpeg'
                    ),
                    'medium' => array(
                        'file' => $image_data['url'],
                        'width' => min(300, $image_data['width']),
                        'height' => min(300, $image_data['height']),
                        'mime-type' => 'image/jpeg'
                    ),
                    'large' => array(
                        'file' => $image_data['url'],
                        'width' => min(1024, $image_data['width']),
                        'height' => min(1024, $image_data['height']),
                        'mime-type' => 'image/jpeg'
                    ),
                    'full' => array(
                        'file' => $image_data['url'],
                        'width' => $image_data['width'],
                        'height' => $image_data['height'],
                        'mime-type' => 'image/jpeg'
                    )
                )
            );
            update_post_meta($attachment_id, '_wp_attachment_metadata', $attachment_metadata);

            // Store Unsplash attribution data in metadata
            update_post_meta($attachment_id, '_unsplash_photo_id', $image_data['photo_id']);
            update_post_meta($attachment_id, '_unsplash_photographer_name', $image_data['photographer_name']);
            update_post_meta($attachment_id, '_unsplash_photographer_username', $image_data['photographer_username']);
            update_post_meta($attachment_id, '_unsplash_photo_link', $image_data['photo_link']);
            update_post_meta($attachment_id, '_unsplash_link', $image_data['unsplash_link']);
            update_post_meta($attachment_id, '_wp_attached_file', $image_data['url']); // Store external URL
            update_post_meta($attachment_id, '_wp_attachment_image_alt', 'Environmental news and nature photography from Unsplash');

            error_log('EnviroLink: [UNSPLASH] ✓ Created attachment (ID: ' . $attachment_id . ') with dimensions ' . $image_data['width'] . 'x' . $image_data['height']);
            return $attachment_id;
        }

        error_log('EnviroLink: [UNSPLASH] ✗ Failed to create attachment');
        return false;
    }

    // ============================================================================
    // ONTOLOGY MANAGEMENT SYSTEM
    // ============================================================================

    /**
     * Create database tables for environmental news ontology
     * Tables: topics, topic_sdg_mapping, topic_aliases
     */
    private function create_ontology_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Table 1: envirolink_topics - Core taxonomy
        $table_topics = $wpdb->prefix . 'envirolink_topics';
        $sql_topics = "CREATE TABLE IF NOT EXISTS $table_topics (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            iptc_code varchar(20) DEFAULT NULL,
            label varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            definition text DEFAULT NULL,
            parent_id bigint(20) UNSIGNED DEFAULT NULL,
            level int(11) DEFAULT 0,
            source enum('iptc','custom') DEFAULT 'iptc',
            status enum('active','inactive','retired') DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY iptc_code (iptc_code),
            KEY parent_id (parent_id),
            KEY status (status),
            KEY source (source)
        ) $charset_collate;";

        // Table 2: envirolink_topic_sdg_mapping - Many-to-many SDG relationships
        $table_sdg = $wpdb->prefix . 'envirolink_topic_sdg_mapping';
        $sql_sdg = "CREATE TABLE IF NOT EXISTS $table_sdg (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            topic_id bigint(20) UNSIGNED NOT NULL,
            sdg_number int(11) NOT NULL,
            sdg_name varchar(255) NOT NULL,
            relevance enum('primary','secondary') DEFAULT 'primary',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY topic_id (topic_id),
            KEY sdg_number (sdg_number),
            UNIQUE KEY topic_sdg (topic_id, sdg_number)
        ) $charset_collate;";

        // Table 3: envirolink_topic_aliases - Alternative names/variations for matching
        $table_aliases = $wpdb->prefix . 'envirolink_topic_aliases';
        $sql_aliases = "CREATE TABLE IF NOT EXISTS $table_aliases (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            topic_id bigint(20) UNSIGNED NOT NULL,
            alias varchar(255) NOT NULL,
            alias_type enum('synonym','plural','abbreviation','related') DEFAULT 'synonym',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY topic_id (topic_id),
            KEY alias (alias)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_topics);
        dbDelta($sql_sdg);
        dbDelta($sql_aliases);

        // Mark that tables have been created
        update_option('envirolink_ontology_tables_created', true);
    }

    /**
     * Fetch IPTC Media Topics from official API
     * Returns array of topic data or WP_Error on failure
     */
    private function fetch_iptc_topic($medtop_code) {
        $url = 'https://cv.iptc.org/newscodes/mediatopic/' . $medtop_code;

        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'Accept' => 'application/ld+json'
            )
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', 'Failed to parse IPTC response');
        }

        return $data;
    }

    /**
     * Seed ontology database with curated environmental topics from IPTC and custom additions
     * This is manually curated to include only relevant environmental topics
     */
    public function seed_ontology_database() {
        global $wpdb;

        $table_topics = $wpdb->prefix . 'envirolink_topics';
        $table_sdg = $wpdb->prefix . 'envirolink_topic_sdg_mapping';
        $table_aliases = $wpdb->prefix . 'envirolink_topic_aliases';

        // Verify tables exist first
        $tables_exist = $wpdb->get_var("SHOW TABLES LIKE '$table_topics'");
        if (!$tables_exist) {
            return array('success' => false, 'message' => 'Ontology tables do not exist. Database creation may have failed.');
        }

        // Check if already seeded
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_topics");
        if ($wpdb->last_error) {
            return array('success' => false, 'message' => 'Database error: ' . $wpdb->last_error);
        }
        if ($count > 0) {
            return array('success' => false, 'message' => 'Database already seeded. Clear first if re-seeding.');
        }

        // CURATED ENVIRONMENTAL TOPICS
        // Structure: [iptc_code, label, definition, parent_iptc_code, sdgs[], aliases[]]
        $topics = array(
            // Level 0: Root
            array('06000000', 'Environment', 'The protection, damage, and condition of the ecosystem of the planet Earth and its surroundings', null, array(13, 14, 15), array('environmental', 'ecology', 'ecological')),

            // Level 1: Main Categories
            array('20000418', 'Climate Change', 'Extreme changes in climate, including rising global temperature, greenhouse gases and ways to reduce emissions', '06000000', array(13, 7, 11), array('climate crisis', 'global warming', 'climate action')),

            array('20000420', 'Conservation', 'Preservation of the natural world, such as wilderness areas, flora and fauna', '06000000', array(14, 15), array('nature conservation', 'wildlife conservation', 'preservation')),

            array('20000424', 'Environmental Pollution', 'The contamination of natural resources by harmful substances', '06000000', array(3, 6, 12, 14, 15), array('pollution', 'contamination', 'environmental contamination')),

            array('20000430', 'Natural Resources', 'Materials and energy sources found in nature that humans use', '06000000', array(7, 12, 15), array('natural resource management', 'resource conservation')),

            array('20000441', 'Renewable Energy', 'Energy from sources that naturally replenish', '06000000', array(7, 13), array('clean energy', 'green energy', 'sustainable energy')),

            // Level 2: Climate Change subcategories
            array('20001374', 'Carbon Emissions', 'Release of carbon dioxide and other greenhouse gases into the atmosphere', '20000418', array(13, 9), array('CO2 emissions', 'greenhouse gas emissions', 'carbon footprint')),

            array('20001375', 'Climate Adaptation', 'Adjustments in response to actual or expected climate change effects', '20000418', array(13, 11), array('climate resilience', 'adaptation strategies')),

            array('20001376', 'Climate Mitigation', 'Actions to reduce greenhouse gas emissions and limit climate change', '20000418', array(13, 7), array('emissions reduction', 'climate solutions')),

            // Level 2: Conservation subcategories
            array('20000421', 'Endangered Species', 'Species at risk of extinction', '20000420', array(14, 15), array('threatened species', 'at-risk species', 'extinction risk')),

            array('20000422', 'Wildlife Protection', 'Efforts to protect wild animals and their habitats', '20000420', array(15), array('wildlife preservation', 'animal conservation')),

            array('20001377', 'Biodiversity', 'Variety of life forms in an ecosystem or on Earth', '20000420', array(14, 15), array('biological diversity', 'ecosystem diversity', 'species diversity')),

            array('20001378', 'Habitat Protection', 'Preservation of natural environments where species live', '20000420', array(15), array('habitat conservation', 'ecosystem protection')),

            array('20001379', 'Deforestation', 'Clearing or removal of forests', '20000420', array(13, 15), array('forest loss', 'forest clearing', 'tree removal')),

            // Level 2: Pollution subcategories
            array('20000425', 'Air Pollution', 'Contamination of the atmosphere by harmful substances', '20000424', array(3, 11, 13), array('air quality', 'atmospheric pollution', 'smog')),

            array('20000426', 'Water Pollution', 'Contamination of water bodies by pollutants', '20000424', array(6, 14), array('water contamination', 'aquatic pollution')),

            array('20000427', 'Soil Pollution', 'Contamination of soil by harmful substances', '20000424', array(2, 15), array('soil contamination', 'land pollution')),

            array('20000428', 'Noise Pollution', 'Harmful or annoying levels of noise', '20000424', array(11), array('sound pollution', 'acoustic pollution')),

            array('20000429', 'Light Pollution', 'Excessive artificial light in the environment', '20000424', array(11), array('sky glow', 'light trespass')),

            array('20001380', 'Plastic Pollution', 'Accumulation of plastic products in the environment', '20000424', array(12, 14), array('plastic waste', 'microplastics', 'ocean plastic')),

            // Level 2: Natural Resources subcategories
            array('20001381', 'Water Resources', 'Sources of water that are useful or potentially useful', '20000430', array(6), array('freshwater', 'water supply', 'water sources')),

            array('20001382', 'Forest Resources', 'Forests as a source of materials and ecological services', '20000430', array(15), array('forestry', 'timber resources')),

            array('20001383', 'Mineral Resources', 'Naturally occurring minerals that can be extracted', '20000430', array(12), array('mining', 'mineral extraction')),

            array('20001384', 'Ocean Resources', 'Marine resources including fisheries and minerals', '20000430', array(14), array('marine resources', 'fisheries', 'ocean mining')),

            // Level 2: Renewable Energy subcategories
            array('20001385', 'Solar Energy', 'Energy from the sun converted to thermal or electric energy', '20000441', array(7, 13), array('solar power', 'photovoltaic', 'solar panels')),

            array('20001386', 'Wind Energy', 'Energy generated from wind using turbines', '20000441', array(7, 13), array('wind power', 'wind turbines', 'wind farms')),

            array('20001387', 'Hydroelectric Energy', 'Electricity generated from flowing water', '20000441', array(7, 13), array('hydropower', 'hydro energy', 'water power')),

            array('20001388', 'Geothermal Energy', 'Heat energy from within the Earth', '20000441', array(7, 13), array('geothermal power', 'earth heat')),

            array('20001389', 'Biomass Energy', 'Energy from organic materials', '20000441', array(7, 13), array('bioenergy', 'biofuel', 'organic energy')),

            // Additional important topics
            array('20001390', 'Sustainability', 'Meeting present needs without compromising future generations', '06000000', array(12, 17), array('sustainable development', 'sustainability practices')),

            array('20001391', 'Environmental Justice', 'Fair treatment of all people regarding environmental policies', '06000000', array(10, 16), array('environmental equity', 'environmental rights')),

            array('20001392', 'Circular Economy', 'Economic system aimed at eliminating waste and continual use of resources', '20001390', array(12, 9), array('zero waste', 'closed-loop economy')),

            array('20001393', 'Green Technology', 'Technology designed to reduce environmental impact', '20001390', array(9, 12, 13), array('cleantech', 'environmental technology', 'eco-technology')),

            array('20001394', 'Environmental Policy', 'Government policies related to environmental protection', '06000000', array(13, 16, 17), array('environmental regulation', 'environmental law')),

            array('20001395', 'Ecosystem Services', 'Benefits humans receive from ecosystems', '06000000', array(15), array('ecological services', 'natural capital')),

            // Natural Disasters (from disaster section 03000000)
            array('20000151', 'Natural Disasters', 'Destructive incidents caused by nature', '06000000', array(13, 11), array('natural hazards', 'disasters')),

            array('20000152', 'Drought', 'Severe lack of water over a period of time', '20000151', array(2, 13), array('water shortage', 'arid conditions')),

            array('20000154', 'Flood', 'Overflow of water in normally dry areas', '20000151', array(11, 13), array('flooding', 'inundation')),

            array('20001396', 'Wildfire', 'Uncontrolled fire in wilderness areas', '20000151', array(13, 15), array('forest fire', 'bushfire', 'wildland fire')),

            array('20001397', 'Hurricane', 'Severe tropical storm with strong winds', '20000151', array(11, 13), array('typhoon', 'tropical cyclone', 'cyclone')),

            array('20001398', 'Sea Level Rise', 'Increase in ocean levels due to climate change', '20000418', array(13, 14), array('rising sea levels', 'coastal flooding')),
        );

        // Insert topics and build parent-child map
        $inserted_ids = array();
        $parent_map = array();
        $insert_errors = array();

        foreach ($topics as $topic_data) {
            list($iptc_code, $label, $definition, $parent_iptc, $sdgs, $aliases) = $topic_data;

            $slug = sanitize_title($label);
            $level = ($parent_iptc === null) ? 0 : 1; // Will calculate actual level after all inserts

            $result = $wpdb->insert($table_topics, array(
                'iptc_code' => $iptc_code,
                'label' => $label,
                'slug' => $slug,
                'definition' => $definition,
                'parent_id' => null, // Will update in second pass
                'level' => $level,
                'source' => 'iptc',
                'status' => 'active'
            ));

            if ($result === false || $wpdb->last_error) {
                $insert_errors[] = "Failed to insert '$label': " . $wpdb->last_error;
                continue;
            }

            $topic_id = $wpdb->insert_id;
            if (!$topic_id) {
                $insert_errors[] = "Failed to get insert ID for '$label'";
                continue;
            }

            $inserted_ids[$iptc_code] = $topic_id;

            if ($parent_iptc !== null) {
                $parent_map[$iptc_code] = $parent_iptc;
            }

            // Insert SDG mappings
            foreach ($sdgs as $sdg_number) {
                $sdg_name = $this->get_sdg_name($sdg_number);
                $relevance = (count($sdgs) === 1 || $sdg_number === $sdgs[0]) ? 'primary' : 'secondary';

                $wpdb->insert($table_sdg, array(
                    'topic_id' => $topic_id,
                    'sdg_number' => $sdg_number,
                    'sdg_name' => $sdg_name,
                    'relevance' => $relevance
                ));
            }

            // Insert aliases
            foreach ($aliases as $alias) {
                $wpdb->insert($table_aliases, array(
                    'topic_id' => $topic_id,
                    'alias' => $alias,
                    'alias_type' => 'synonym'
                ));
            }
        }

        // Second pass: Update parent_id and calculate levels
        foreach ($parent_map as $child_iptc => $parent_iptc) {
            if (isset($inserted_ids[$child_iptc]) && isset($inserted_ids[$parent_iptc])) {
                $child_id = $inserted_ids[$child_iptc];
                $parent_id = $inserted_ids[$parent_iptc];

                // Get parent level
                $parent_level = $wpdb->get_var($wpdb->prepare(
                    "SELECT level FROM $table_topics WHERE id = %d",
                    $parent_id
                ));

                // Update child
                $wpdb->update(
                    $table_topics,
                    array(
                        'parent_id' => $parent_id,
                        'level' => $parent_level + 1
                    ),
                    array('id' => $child_id)
                );
            }
        }

        // Check if any topics were actually inserted
        if (empty($inserted_ids)) {
            $error_msg = 'Failed to insert any topics.';
            if (!empty($insert_errors)) {
                $error_msg .= ' Errors: ' . implode('; ', array_slice($insert_errors, 0, 3));
            }
            return array('success' => false, 'message' => $error_msg);
        }

        update_option('envirolink_ontology_seeded', true);
        update_option('envirolink_ontology_seed_date', current_time('mysql'));

        $message = 'Ontology database seeded successfully with ' . count($inserted_ids) . ' topics';
        if (!empty($insert_errors)) {
            $message .= ' (' . count($insert_errors) . ' errors occurred)';
        }

        return array(
            'success' => true,
            'message' => $message,
            'topics_count' => count($inserted_ids),
            'errors' => $insert_errors
        );
    }

    /**
     * Get UN SDG name from number
     */
    private function get_sdg_name($number) {
        $sdgs = array(
            1 => 'No Poverty',
            2 => 'Zero Hunger',
            3 => 'Good Health and Well-Being',
            4 => 'Quality Education',
            5 => 'Gender Equality',
            6 => 'Clean Water and Sanitation',
            7 => 'Affordable and Clean Energy',
            8 => 'Decent Work and Economic Growth',
            9 => 'Industry, Innovation and Infrastructure',
            10 => 'Reduced Inequalities',
            11 => 'Sustainable Cities and Communities',
            12 => 'Responsible Consumption and Production',
            13 => 'Climate Action',
            14 => 'Life Below Water',
            15 => 'Life on Land',
            16 => 'Peace, Justice and Strong Institutions',
            17 => 'Partnerships for the Goals'
        );

        return isset($sdgs[$number]) ? $sdgs[$number] : 'Unknown SDG';
    }

    /**
     * Filter RSS tags against ontology
     * Returns array of matching topic labels
     */
    public function filter_tags_with_ontology($rss_tags) {
        global $wpdb;

        if (empty($rss_tags) || !is_string($rss_tags)) {
            return array();
        }

        // Split tags
        $input_tags = array_map('trim', explode(',', $rss_tags));
        $matched_topics = array();

        $table_topics = $wpdb->prefix . 'envirolink_topics';
        $table_aliases = $wpdb->prefix . 'envirolink_topic_aliases';

        foreach ($input_tags as $tag) {
            $tag_lower = strtolower($tag);

            // Try exact match on label or slug
            $topic = $wpdb->get_row($wpdb->prepare(
                "SELECT id, label FROM $table_topics
                WHERE status = 'active'
                AND (LOWER(label) = %s OR LOWER(slug) = %s)
                LIMIT 1",
                $tag_lower,
                $tag_lower
            ));

            if ($topic) {
                $matched_topics[$topic->id] = $topic->label;
                continue;
            }

            // Try alias match
            $alias_match = $wpdb->get_row($wpdb->prepare(
                "SELECT t.id, t.label
                FROM $table_aliases a
                JOIN $table_topics t ON a.topic_id = t.id
                WHERE LOWER(a.alias) = %s
                AND t.status = 'active'
                LIMIT 1",
                $tag_lower
            ));

            if ($alias_match) {
                $matched_topics[$alias_match->id] = $alias_match->label;
                continue;
            }

            // Try fuzzy match (LIKE with wildcards)
            $fuzzy_topic = $wpdb->get_row($wpdb->prepare(
                "SELECT id, label FROM $table_topics
                WHERE status = 'active'
                AND (LOWER(label) LIKE %s OR LOWER(slug) LIKE %s)
                LIMIT 1",
                '%' . $wpdb->esc_like($tag_lower) . '%',
                '%' . $wpdb->esc_like($tag_lower) . '%'
            ));

            if ($fuzzy_topic) {
                $matched_topics[$fuzzy_topic->id] = $fuzzy_topic->label;
            }
        }

        return array_values(array_unique($matched_topics));
    }

    /**
     * Get all active topics for admin display
     */
    public function get_all_ontology_topics() {
        global $wpdb;

        $table_topics = $wpdb->prefix . 'envirolink_topics';

        $topics = $wpdb->get_results(
            "SELECT * FROM $table_topics
            ORDER BY level ASC, label ASC"
        );

        return $topics;
    }

    /**
     * Get topic with SDG mappings and aliases
     */
    public function get_topic_details($topic_id) {
        global $wpdb;

        $table_topics = $wpdb->prefix . 'envirolink_topics';
        $table_sdg = $wpdb->prefix . 'envirolink_topic_sdg_mapping';
        $table_aliases = $wpdb->prefix . 'envirolink_topic_aliases';

        $topic = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_topics WHERE id = %d",
            $topic_id
        ));

        if (!$topic) {
            return null;
        }

        // Get SDG mappings
        $topic->sdgs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_sdg WHERE topic_id = %d ORDER BY sdg_number",
            $topic_id
        ));

        // Get aliases
        $topic->aliases = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_aliases WHERE topic_id = %d",
            $topic_id
        ));

        return $topic;
    }

    /**
     * Clear all ontology data (for re-seeding)
     */
    public function clear_ontology_database() {
        global $wpdb;

        $table_topics = $wpdb->prefix . 'envirolink_topics';
        $table_sdg = $wpdb->prefix . 'envirolink_topic_sdg_mapping';
        $table_aliases = $wpdb->prefix . 'envirolink_topic_aliases';

        $wpdb->query("TRUNCATE TABLE $table_aliases");
        $wpdb->query("TRUNCATE TABLE $table_sdg");
        $wpdb->query("TRUNCATE TABLE $table_topics");

        delete_option('envirolink_ontology_seeded');
        delete_option('envirolink_ontology_seed_date');

        return array('success' => true, 'message' => 'Ontology database cleared successfully');
    }

    /**
     * Bulk re-tag all posts using ontology
     */
    public function retag_all_posts_with_ontology() {
        // Get RSS-aggregated posts
        $rss_posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'meta_key' => 'envirolink_source_url',
            'meta_compare' => 'EXISTS'
        ));

        // Get roundup posts
        $roundup_posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'meta_key' => 'envirolink_is_roundup',
            'meta_value' => 'yes',
            'meta_compare' => '='
        ));

        $updated = 0;
        $skipped = 0;
        $roundups_updated = 0;

        // Process RSS-aggregated posts
        foreach ($rss_posts as $post) {
            $rss_tags = get_post_meta($post->ID, 'envirolink_topic_tags', true);

            if (empty($rss_tags)) {
                $skipped++;
                continue;
            }

            // Filter through ontology
            $filtered_tags = $this->filter_tags_with_ontology($rss_tags);

            if (!empty($filtered_tags)) {
                wp_set_post_tags($post->ID, $filtered_tags, false);
                $updated++;
            } else {
                // No matches, clear tags
                wp_set_post_tags($post->ID, array(), false);
                $skipped++;
            }
        }

        // Process roundup posts - collect tags from their articles
        foreach ($roundup_posts as $roundup) {
            // Get articles from roundup content (they're linked in the post)
            // Alternative: Get recent articles (approximation)
            $articles = get_posts(array(
                'post_type' => 'post',
                'posts_per_page' => 30,
                'meta_key' => 'envirolink_source_url',
                'meta_compare' => 'EXISTS',
                'orderby' => 'ID',
                'order' => 'DESC',
                'date_query' => array(
                    array(
                        'before' => $roundup->post_date,
                        'inclusive' => true
                    )
                )
            ));

            $all_tags = array();
            foreach ($articles as $article) {
                $article_tags = wp_get_post_tags($article->ID, array('fields' => 'names'));
                if (!empty($article_tags)) {
                    $all_tags = array_merge($all_tags, $article_tags);
                }
            }

            $all_tags = array_unique($all_tags);

            if (!empty($all_tags)) {
                wp_set_post_tags($roundup->ID, $all_tags, false);
                $roundups_updated++;
            } else {
                wp_set_post_tags($roundup->ID, array(), false);
                $skipped++;
            }
        }

        return array(
            'success' => true,
            'updated' => $updated,
            'roundups_updated' => $roundups_updated,
            'skipped' => $skipped,
            'total' => count($rss_posts) + count($roundup_posts)
        );
    }

    /**
     * AJAX: Seed ontology database
     */
    public function ajax_seed_ontology() {
        check_ajax_referer('envirolink_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $result = $this->seed_ontology_database();
        wp_send_json_success($result);
    }

    /**
     * AJAX: Clear ontology database
     */
    public function ajax_clear_ontology() {
        check_ajax_referer('envirolink_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $result = $this->clear_ontology_database();
        wp_send_json_success($result);
    }

    /**
     * AJAX: Re-tag all posts using ontology
     */
    public function ajax_retag_posts() {
        check_ajax_referer('envirolink_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $result = $this->retag_all_posts_with_ontology();
        wp_send_json_success($result);
    }

    // ============================================================================
    // END ONTOLOGY MANAGEMENT SYSTEM
    // ============================================================================

    /**
     * Output CSS for image captions (Unsplash attribution)
     * Makes captions small and styled properly
     */
    public function output_caption_css() {
        ?>
        <style type="text/css">
            /* Unsplash attribution caption styling - Small and subtle */
            .wp-caption-text,
            .wp-element-caption,
            figcaption {
                font-size: 11px !important;
                color: #666 !important;
                line-height: 1.4 !important;
                margin-top: 8px !important;
                font-style: italic;
            }

            .wp-caption-text a,
            .wp-element-caption a,
            figcaption a {
                color: #666 !important;
                text-decoration: none;
            }

            .wp-caption-text a:hover,
            .wp-element-caption a:hover,
            figcaption a:hover {
                color: #333 !important;
                text-decoration: underline;
            }
        </style>
        <?php
    }
}

// Initialize plugin
EnviroLink_AI_Aggregator::get_instance();

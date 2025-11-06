<?php
/**
 * Plugin Name: EnviroLink AI News Aggregator
 * Plugin URI: https://envirolink.org
 * Description: Automatically fetches environmental news from RSS feeds, rewrites content using AI, and publishes to WordPress
 * Version: 1.31.0
 * Author: EnviroLink
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ENVIROLINK_VERSION', '1.31.0');
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

        // Post ordering - randomize within same day
        if (get_option('envirolink_randomize_daily_order', 'no') === 'yes') {
            add_filter('posts_orderby', array($this, 'randomize_daily_order'), 10, 2);
        }
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
                'include_locations' => true
            )
        );
        
        if (!get_option('envirolink_feeds')) {
            add_option('envirolink_feeds', $default_feeds);
        }
        
        if (!get_option('envirolink_api_key')) {
            add_option('envirolink_api_key', '');
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
        register_setting('envirolink_settings', 'envirolink_post_category');
        register_setting('envirolink_settings', 'envirolink_post_status');
        register_setting('envirolink_settings', 'envirolink_update_existing');
        register_setting('envirolink_settings', 'envirolink_randomize_daily_order');
    }
    
    /**
     * Admin page HTML
     */
    public function admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Get search query (used in Articles tab)
        $search_query = isset($_GET['article_search']) ? sanitize_text_field($_GET['article_search']) : '';

        // Save settings
        if (isset($_POST['envirolink_save_settings'])) {
            check_admin_referer('envirolink_settings');

            update_option('envirolink_api_key', sanitize_text_field($_POST['api_key']));
            update_option('envirolink_post_category', absint($_POST['post_category']));
            update_option('envirolink_post_status', sanitize_text_field($_POST['post_status']));
            update_option('envirolink_update_existing', isset($_POST['update_existing']) ? 'yes' : 'no');
            update_option('envirolink_randomize_daily_order', isset($_POST['randomize_daily_order']) ? 'yes' : 'no');
            update_option('envirolink_auto_cleanup_duplicates', isset($_POST['auto_cleanup_duplicates']) ? 'yes' : 'no');
            update_option('envirolink_daily_roundup_enabled', isset($_POST['daily_roundup_enabled']) ? 'yes' : 'no');
            update_option('envirolink_roundup_auto_fetch_unsplash', isset($_POST['roundup_auto_fetch_unsplash']) ? 'yes' : 'no');
            update_option('envirolink_unsplash_api_key', sanitize_text_field($_POST['unsplash_api_key']));

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
                'include_locations' => isset($_POST['include_locations'])
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
                                                data-include-locations="<?php echo $include_locations ? '1' : '0'; ?>">
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

                $('#edit-feed-index').val(index);
                $('#edit-schedule-type').val(scheduleType);
                $('#edit-schedule-times').val(scheduleTimes);
                $('#edit-include-author').prop('checked', includeAuthor);
                $('#edit-include-pubdate').prop('checked', includePubdate);
                $('#edit-include-topic-tags').prop('checked', includeTopicTags);
                $('#edit-include-locations').prop('checked', includeLocations);

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

        // Get all EnviroLink posts
        $args = array(
            'post_type' => 'post',
            'posts_per_page' => -1,
            'meta_key' => 'envirolink_source_url',
            'meta_compare' => 'EXISTS',
            'post_status' => array('publish', 'draft', 'pending', 'private')
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

        // Get RSS-aggregated posts
        $rss_posts = get_posts(array(
            'post_type' => 'post',
            'posts_per_page' => -1,
            'meta_key' => 'envirolink_source_url',
            'meta_compare' => 'EXISTS',
            'post_status' => array('publish', 'draft', 'pending', 'private')
        ));

        // Get roundup posts (with metadata)
        $roundup_posts_meta = get_posts(array(
            'post_type' => 'post',
            'posts_per_page' => -1,
            'meta_key' => 'envirolink_is_roundup',
            'meta_value' => 'yes',
            'meta_compare' => '=',
            'post_status' => array('publish', 'draft', 'pending', 'private')
        ));

        // Get roundup posts (by title pattern - for older posts without metadata)
        $roundup_posts_title = get_posts(array(
            'post_type' => 'post',
            'posts_per_page' => -1,
            's' => 'Daily Environmental News Roundup',
            'post_status' => array('publish', 'draft', 'pending', 'private')
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
        $editor_user = get_user_by('login', 'envirolink_editor');

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

        // Get all EnviroLink posts (RSS + roundups)
        $rss_posts = get_posts(array(
            'post_type' => 'post',
            'posts_per_page' => -1,
            'meta_key' => 'envirolink_source_url',
            'meta_compare' => 'EXISTS',
            'post_status' => array('publish', 'draft', 'pending', 'private')
        ));

        $roundup_posts_meta = get_posts(array(
            'post_type' => 'post',
            'posts_per_page' => -1,
            'meta_key' => 'envirolink_is_roundup',
            'meta_value' => 'yes',
            'meta_compare' => '=',
            'post_status' => array('publish', 'draft', 'pending', 'private')
        ));

        $roundup_posts_title = get_posts(array(
            'post_type' => 'post',
            'posts_per_page' => -1,
            's' => 'Daily Environmental News Roundup',
            'post_status' => array('publish', 'draft', 'pending', 'private')
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
        $editor_user = get_user_by('login', 'envirolink_editor');

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
        $progress['log'][] = '[' . date('H:i:s') . '] ' . $message;

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
                $progress['log'][] = '[' . date('H:i:s') . '] ✗ FATAL ERROR: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'];
                update_option('envirolink_last_run_log', $progress['log']);
            }
        }
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

        // Get all EnviroLink posts
        $args = array(
            'post_type' => 'post',
            'posts_per_page' => -1,
            'meta_key' => 'envirolink_source_url',
            'meta_compare' => 'EXISTS',
            'post_status' => array('publish', 'draft', 'pending', 'private')
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
        // Check if another instance is already running
        $lock_key = 'envirolink_processing_lock';
        $lock_data = get_transient($lock_key);

        if ($lock_data) {
            // Another instance is running - skip this execution (applies to ALL runs)
            $run_type = $manual_run ? 'manual run' : 'CRON run';
            $lock_age = time() - $lock_data['start_time'];
            $lock_pid = isset($lock_data['pid']) ? $lock_data['pid'] : 'unknown';

            error_log('EnviroLink: Skipping ' . $run_type . ' - another instance is already processing');
            error_log('EnviroLink: Lock held by PID ' . $lock_pid . ' for ' . $lock_age . ' seconds');

            return array(
                'success' => false,
                'message' => 'Another instance is already running (PID: ' . $lock_pid . ', ' . $lock_age . 's). Please wait and try again.'
            );
        }

        // Set lock for 2 minutes (120 seconds) - shorter timeout prevents long waits if process crashes
        // Store start time and process ID for debugging
        $lock_data = array(
            'start_time' => time(),
            'pid' => getmypid(),
            'type' => $manual_run ? 'manual' : 'cron'
        );
        set_transient($lock_key, $lock_data, 120);

        error_log('EnviroLink: Lock acquired (PID: ' . $lock_data['pid'] . ', Type: ' . $lock_data['type'] . ')');

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
                $timestamp = date('H:i:s') . '.' . substr(microtime(), 2, 3);
                $this->log_message('[' . $timestamp . '] Checking article: ' . $original_title);
                $this->log_message('→ Source URL: ' . $original_link);

                // Use normalized URL for better duplicate detection
                $normalized_link = $this->normalize_url($original_link);
                $this->log_message('→ Normalized URL: ' . $normalized_link);

                // First try exact match (backward compatibility)
                $existing = get_posts(array(
                    'post_type' => 'post',
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
                        $post_data = array(
                            'ID' => $existing_post_id,
                            'post_title' => $rewritten['title'],
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
                                $tag_array = array_map('trim', explode(',', $feed_metadata['topic_tags']));
                                wp_set_post_tags($post_id, $tag_array, false);
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
                    $post_data = array(
                        'post_title' => $rewritten['title'],
                        'post_content' => $rewritten['content'],
                        'post_status' => $post_status,
                        'post_type' => 'post',
                        'post_author' => $this->get_envirolink_editor_id()
                    );

                    // Use original RSS publication date if available
                    if (!empty($original_pubdate)) {
                        $timestamp = strtotime($original_pubdate);
                        if ($timestamp !== false) {
                            $pub_date = date('Y-m-d H:i:s', $timestamp);
                            $post_data['post_date'] = $pub_date;
                            $post_data['post_date_gmt'] = get_gmt_from_date($pub_date);
                        }
                    }

                    // Set categories: configured category + "newsfeed" category
                    $categories = array();
                    if ($post_category) {
                        $categories[] = $post_category;
                    }

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

                    $post_id = wp_insert_post($post_data);

                    if ($post_id) {
                        $this->log_message('→ Created new post successfully (Post ID: ' . $post_id . ')');
                        // Store metadata
                        update_post_meta($post_id, 'envirolink_source_url', $original_link);
                        update_post_meta($post_id, 'envirolink_source_name', $feed['name']);
                        update_post_meta($post_id, 'envirolink_original_title', $original_title);
                        update_post_meta($post_id, 'envirolink_content_hash', $content_hash);
                        $this->log_message('→ Stored source URL for duplicate detection: ' . $original_link);

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
                            $tag_array = array_map('trim', explode(',', $feed_metadata['topic_tags']));
                            wp_set_post_tags($post_id, $tag_array, false);
                        }

                        // Set featured image if found
                        if ($image_url) {
                            $this->set_featured_image_from_url($image_url, $post_id);
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
     * Rewrite content using Anthropic API
     */
    private function rewrite_with_ai($title, $content, $api_key) {
        $prompt = "You are a news editor for EnviroLink.org, an environmental news aggregation website. Your task is to rewrite the following environmental news article in a clear, engaging style while maintaining factual accuracy.

Original Title: {$title}

Original Content:
{$content}

Please provide:
1. A new, compelling headline (max 80 characters)
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
            $today_title = 'Daily Environmental News Roundup by the EnviroLink Team – ' . date('F j, Y');
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
            return;
        }

        $this->log_message('✓ AI generated roundup content successfully');

        // Step 4: Create the roundup post
        $this->log_message('');
        $this->log_message('STEP 4: Creating roundup post...');
        $this->update_progress(array('percent' => 70, 'message' => 'Creating roundup post...'));

        $post_category = get_option('envirolink_post_category');
        $today_date = date('F j, Y'); // e.g., "November 3, 2025"

        $post_data = array(
            'post_title' => 'Daily Environmental News Roundup by the EnviroLink Team - ' . $today_date,
            'post_content' => $roundup_content,
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => $this->get_envirolink_editor_id()
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
            $this->log_message('✓ Created roundup post (ID: ' . $post_id . ')');

            // Mark this as a roundup post (not from RSS)
            update_post_meta($post_id, 'envirolink_is_roundup', 'yes');
            update_post_meta($post_id, 'envirolink_roundup_date', current_time('mysql'));
            update_post_meta($post_id, 'envirolink_roundup_article_count', count($articles));

            // Set featured image - try multiple strategies
            $this->log_message('');
            $this->log_message('STEP 5: Setting featured image...');
            $this->update_progress(array('percent' => 90, 'message' => 'Adding featured image...'));

            $image_id = false;
            $auto_fetch_unsplash = get_option('envirolink_roundup_auto_fetch_unsplash', 'no') === 'yes';
            $roundup_images = get_option('envirolink_roundup_images', array());

            // STRATEGY 1: Unsplash (if enabled) - COMPLIANT WITH API GUIDELINES
            if ($auto_fetch_unsplash) {
                $this->log_message('[UNSPLASH] Attempting to fetch from Unsplash...');
                $unsplash_data = $this->fetch_unsplash_image();

                if ($unsplash_data) {
                    // Download and upload the image to WordPress media library (SAME AS FEED IMAGES)
                    $this->log_message('[UNSPLASH] Downloading image: ' . $unsplash_data['url']);
                    $image_id = $this->set_featured_image_from_url($unsplash_data['url'], $post_id);

                    if ($image_id) {
                        // Update attachment metadata with Unsplash attribution
                        $caption = sprintf(
                            'Photo by <a href="%s" target="_blank" rel="noopener">%s</a> on <a href="%s" target="_blank" rel="noopener">Unsplash</a>',
                            esc_url($unsplash_data['photo_link']),
                            esc_html($unsplash_data['photographer_name']),
                            esc_url($unsplash_data['unsplash_link'])
                        );

                        wp_update_post(array(
                            'ID' => $image_id,
                            'post_excerpt' => $caption
                        ));

                        // Store attribution data on the POST
                        update_post_meta($post_id, '_unsplash_attribution', array(
                            'photographer_name' => $unsplash_data['photographer_name'],
                            'photographer_username' => $unsplash_data['photographer_username'],
                            'photo_link' => $unsplash_data['photo_link'],
                            'unsplash_link' => $unsplash_data['unsplash_link']
                        ));

                        $this->log_message('[UNSPLASH] ✓ Downloaded and set as featured image (ID: ' . $image_id . ')');
                    } else {
                        $this->log_message('[UNSPLASH] ✗ Failed to download/upload Unsplash image');
                    }
                } else {
                    $this->log_message('[UNSPLASH] ✗ Unsplash fetch failed (check error logs for details)');
                }
            } else {
                $this->log_message('[UNSPLASH] Auto-fetch is disabled');
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
            if ($image_id && !$auto_fetch_unsplash) {
                set_post_thumbnail($post_id, $image_id);
                $this->log_message('✓ Set featured image (ID: ' . $image_id . ') for roundup');
            } else if (!$image_id) {
                $this->log_message('✗ WARNING: No image available from any strategy');
                $this->log_message('   To fix: Enable Unsplash auto-fetch OR upload images to manual collection');
            }

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
        } else {
            $this->log_message('✗ Failed to create roundup post');
            $this->update_progress(array(
                'status' => 'error',
                'message' => 'Failed to create post',
                'percent' => 100
            ));
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

        $prompt = "You are writing the daily editorial roundup for EnviroLink.org, a respected environmental news website. Your role is to create a cohesive, humanistic editorial piece that connects the day's environmental news stories.

Here are today's environmental news articles:
{$articles_text}

Your task is to write a balanced, editorial-style roundup (400-600 words) that:

1. Opens with a brief, engaging introduction (1-2 sentences)
2. Connects the stories thematically rather than listing them separately
3. Highlights key developments, trends, or patterns across the stories
4. Maintains a balanced tone - not overly enthusiastic or pessimistic
5. Writes in a warm, humanistic voice that sounds like passionate environmental journalists
6. Avoids hyperbole and maintains journalistic credibility
7. Ends with a brief reflection or forward-looking thought

Writing guidelines:
- Use \"we\" when appropriate to create connection with readers
- Be informative but accessible
- Focus on what matters and why
- Connect global issues to human impact where relevant
- Acknowledge complexity - the environmental movement includes both challenges and progress

Format your response as:
CONTENT: [your editorial roundup]

Do NOT include a title - just the content.";

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
     * Fetch environmental image from Unsplash API
     * COMPLIANT with Unsplash API Guidelines for production approval:
     * 1. Hotlinks image (doesn't download to server)
     * 2. Triggers download endpoint for tracking
     * 3. Stores attribution data for display
     *
     * Returns array with image data or false
     */
    private function fetch_unsplash_image() {
        // Get Unsplash API key
        $api_key = get_option('envirolink_unsplash_api_key', '');

        if (empty($api_key)) {
            error_log('EnviroLink: [UNSPLASH] ✗ API key not configured. Please add your Unsplash Access Key in Settings.');
            return false;
        }

        // Random environmental keywords for variety
        $keywords = array(
            'nature environment',
            'climate earth',
            'forest conservation',
            'ocean wildlife',
            'sustainable planet',
            'green nature',
            'environmental landscape'
        );

        $query = $keywords[array_rand($keywords)];

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

        // Extract all required data
        $photo_id = $body['id'];
        $image_url = $body['urls']['regular'];
        $photographer_name = isset($body['user']['name']) ? $body['user']['name'] : 'Unknown';
        $photographer_username = isset($body['user']['username']) ? $body['user']['username'] : '';
        $photo_link = isset($body['links']['html']) ? $body['links']['html'] . '?utm_source=envirolink_news&utm_medium=referral' : '';
        $download_location = isset($body['links']['download_location']) ? $body['links']['download_location'] : '';
        $width = isset($body['width']) ? intval($body['width']) : 1920;
        $height = isset($body['height']) ? intval($body['height']) : 1280;

        error_log('EnviroLink: [UNSPLASH] ✓ Found image by ' . $photographer_name . ' (ID: ' . $photo_id . ')');

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
            update_post_meta($attachment_id, '_wp_attachment_image_alt', 'Environmental photography');

            error_log('EnviroLink: [UNSPLASH] ✓ Created attachment (ID: ' . $attachment_id . ') with dimensions ' . $image_data['width'] . 'x' . $image_data['height']);
            return $attachment_id;
        }

        error_log('EnviroLink: [UNSPLASH] ✗ Failed to create attachment');
        return false;
    }

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

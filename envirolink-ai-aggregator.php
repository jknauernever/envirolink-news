<?php
/**
 * Plugin Name: EnviroLink AI News Aggregator
 * Plugin URI: https://envirolink.org
 * Description: Automatically fetches environmental news from RSS feeds, rewrites content using AI, and publishes to WordPress
 * Version: 1.9.4
 * Author: EnviroLink
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ENVIROLINK_VERSION', '1.9.4');
define('ENVIROLINK_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ENVIROLINK_PLUGIN_URL', plugin_dir_url(__FILE__));

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
        
        // AJAX handlers
        add_action('wp_ajax_envirolink_run_now', array($this, 'ajax_run_now'));
        add_action('wp_ajax_envirolink_run_feed', array($this, 'ajax_run_feed'));
        add_action('wp_ajax_envirolink_get_progress', array($this, 'ajax_get_progress'));
        add_action('wp_ajax_envirolink_get_saved_log', array($this, 'ajax_get_saved_log'));
        add_action('wp_ajax_envirolink_update_feed_images', array($this, 'ajax_update_feed_images'));
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
        
        // Schedule cron job (hourly)
        if (!wp_next_scheduled('envirolink_fetch_feeds')) {
            wp_schedule_event(time(), 'hourly', 'envirolink_fetch_feeds');
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Remove cron job
        $timestamp = wp_next_scheduled('envirolink_fetch_feeds');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'envirolink_fetch_feeds');
        }
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
    }
    
    /**
     * Admin page HTML
     */
    public function admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Save settings
        if (isset($_POST['envirolink_save_settings'])) {
            check_admin_referer('envirolink_settings');

            update_option('envirolink_api_key', sanitize_text_field($_POST['api_key']));
            update_option('envirolink_post_category', absint($_POST['post_category']));
            update_option('envirolink_post_status', sanitize_text_field($_POST['post_status']));
            update_option('envirolink_update_existing', isset($_POST['update_existing']) ? 'yes' : 'no');

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
                            <td><?php echo $next_run ? date('Y-m-d H:i:s', $next_run) : 'Not scheduled'; ?></td>
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
                    </table>

                    <p>
                        <button type="button" class="button button-primary" id="run-now-btn">Run All Feeds Now</button>
                        <span id="run-now-status" style="margin-left: 10px;"></span>
                    </p>

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

                    <!-- Log Viewer (always visible) -->
                    <div style="margin-top: 15px;">
                        <button type="button" class="button button-small" id="toggle-log-btn">Show Detailed Log</button>
                    </div>
                    <div id="envirolink-log-container" style="display: none; margin-top: 10px; max-height: 300px; overflow-y: auto; background: #f9f9f9; border: 1px solid #ddd; padding: 10px; font-family: monospace; font-size: 11px; line-height: 1.5;">
                        <div id="envirolink-log-content"></div>
                    </div>
                </div>

                <div class="card" style="flex: 1; max-width: 400px;">
                    <h2>Quick Actions</h2>
                    <?php if (empty($feeds)): ?>
                        <p style="color: #666;">No feeds configured yet.</p>
                    <?php else: ?>
                        <div style="max-height: 300px; overflow-y: auto;">
                            <?php foreach ($feeds as $index => $feed): ?>
                                <div style="padding: 10px; border-bottom: 1px solid #ddd; display: flex; align-items: center; justify-content: space-between;">
                                    <div style="flex: 1;">
                                        <strong><?php echo esc_html($feed['name']); ?></strong>
                                        <?php if (!$feed['enabled']): ?>
                                            <span style="color: #999; font-size: 11px;">(disabled)</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="display: flex; gap: 5px;">
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
                                                title="Re-download all images for this feed (high-res)"
                                                style="background-color: #9b59b6; color: white; border-color: #8e44ad;">
                                            <span class="dashicons dashicons-format-image" style="font-size: 16px; width: 16px; height: 16px;"></span>
                                        </button>
                                        <button type="button" class="button button-small button-primary run-feed-btn"
                                                data-index="<?php echo $index; ?>"
                                                data-name="<?php echo esc_attr($feed['name']); ?>"
                                                title="Update this feed now"
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
                    btn.text('Show Detailed Log');
                } else {
                    logContainer.slideDown();
                    btn.text('Hide Detailed Log');
                    // Auto-scroll to bottom
                    setTimeout(function() {
                        logContainer[0].scrollTop = logContainer[0].scrollHeight;
                    }, 100);
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
        $feeds = get_option('envirolink_feeds', array());

        if (!isset($feeds[$feed_index])) {
            return array('success' => false, 'message' => 'Feed not found');
        }

        $feed = $feeds[$feed_index];
        $this->log_message('Starting image update for ' . $feed['name']);

        // Query all posts from this feed
        $args = array(
            'post_type' => 'post',
            'posts_per_page' => -1,
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

        $this->log_message('Found ' . $total_posts . ' posts to update');

        $updated_count = 0;
        $skipped_count = 0;
        $failed_count = 0;

        foreach ($posts as $index => $post) {
            $progress_percent = floor((($index + 1) / $total_posts) * 100);
            $this->update_progress(array(
                'percent' => $progress_percent,
                'current' => $index + 1,
                'total' => $total_posts,
                'status' => 'Updating images for ' . $feed['name'] . '...'
            ));

            $this->log_message('Processing: ' . $post->post_title);

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

            // Set/update the featured image
            if ($image_url) {
                $success = $this->set_featured_image_from_url($image_url, $post->ID);
                if ($success) {
                    $this->log_message('  → ✓ Image updated successfully');
                    $updated_count++;
                } else {
                    $this->log_message('  → ✗ Failed to set featured image');
                    $failed_count++;
                }
            } else {
                $this->log_message('  → ✗ No image found via any method');
                $failed_count++;
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
     * Main function: Fetch and process feeds
     * @param bool $manual_run Whether this is a manual run (bypasses schedule checks)
     * @param int $specific_feed_index Process only this feed (null = all feeds)
     */
    public function fetch_and_process_feeds($manual_run = false, $specific_feed_index = null) {
        $api_key = get_option('envirolink_api_key');
        $feeds = get_option('envirolink_feeds', array());
        $post_category = get_option('envirolink_post_category');
        $post_status = get_option('envirolink_post_status', 'publish');
        $update_existing = get_option('envirolink_update_existing', 'no');

        if (empty($api_key)) {
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

                $existing = get_posts(array(
                    'post_type' => 'post',
                    'meta_key' => 'envirolink_source_url',
                    'meta_value' => $original_link,
                    'posts_per_page' => 1
                ));

                $is_update = false;
                $existing_post_id = null;

                if (!empty($existing)) {
                    if ($update_existing === 'yes') {
                        // Update mode: we'll update this post
                        $is_update = true;
                        $existing_post_id = $existing[0]->ID;
                        $this->log_message('Checking: ' . $original_title . ' (exists, checking for changes)');
                    } else {
                        // Skip mode: skip this article
                        $this->log_message('Skipped: ' . $original_title . ' (already exists)');
                        continue;
                    }
                } else {
                    $this->log_message('Processing: ' . $original_title);
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
                        'post_author' => 1
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

                    if ($post_category) {
                        $post_data['post_category'] = array($post_category);
                    }

                    $post_id = wp_insert_post($post_data);

                    if ($post_id) {
                        $this->log_message('→ Created new post successfully');
                        // Store metadata
                        update_post_meta($post_id, 'envirolink_source_url', $original_link);
                        update_post_meta($post_id, 'envirolink_source_name', $feed['name']);
                        update_post_meta($post_id, 'envirolink_original_title', $original_title);
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

        // Clear progress tracking
        $this->clear_progress();

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
     */
    private function set_featured_image_from_url($image_url, $post_id) {
        if (empty($image_url)) {
            $this->log_message('    ✗ Image URL is empty');
            return false;
        }

        $this->log_message('    → Downloading image from: ' . $image_url);

        // Download image
        $tmp = download_url($image_url);

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

        // Upload to media library
        $attachment_id = media_handle_sideload($file_array, $post_id);

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
        } else {
            $this->log_message('    ✗ Failed to set as featured image (set_post_thumbnail returned false)');
        }

        return $result;
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
}

// Initialize plugin
EnviroLink_AI_Aggregator::get_instance();

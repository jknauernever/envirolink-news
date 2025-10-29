<?php
/**
 * Plugin Name: EnviroLink AI News Aggregator
 * Plugin URI: https://envirolink.org
 * Description: Automatically fetches environmental news from RSS feeds, rewrites content using AI, and publishes to WordPress
 * Version: 1.1.0
 * Author: EnviroLink
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ENVIROLINK_VERSION', '1.1.0');
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
            
            // Run now button (all feeds)
            $('#run-now-btn').click(function() {
                var btn = $(this);
                var status = $('#run-now-status');

                btn.prop('disabled', true);
                status.html('<span style="color: blue;">Running...</span>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'envirolink_run_now'
                    },
                    success: function(response) {
                        if (response.success) {
                            status.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                        } else {
                            status.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                        }
                        btn.prop('disabled', false);
                    },
                    error: function() {
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

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'envirolink_run_feed',
                        feed_index: feedIndex
                    },
                    success: function(response) {
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
                        icon.removeClass('dashicons-update-spin');
                        btn.prop('disabled', false);
                        $('#run-now-status').html('<span style="color: red;">✗ Error updating ' + feedName + '</span>');
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

        $result = $this->fetch_and_process_feeds(true); // Pass true for manual run

        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error(array('message' => $result['message']));
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

        $result = $this->fetch_and_process_feeds(true, $feed_index); // Pass true for manual run and feed index

        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
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

        foreach ($feeds as $index => $feed) {
            // If specific feed requested, skip all others
            if ($specific_feed_index !== null && $index !== $specific_feed_index) {
                continue;
            }

            if (!$feed['enabled']) {
                continue;
            }

            // Check if feed is due for processing (skip check for manual runs)
            if (!$manual_run && !$this->is_feed_due($feed)) {
                continue;
            }
            
            // Fetch RSS feed
            $rss = fetch_feed($feed['url']);
            
            if (is_wp_error($rss)) {
                continue;
            }
            
            $max_items = $rss->get_item_quantity(10);
            $items = $rss->get_items(0, $max_items);
            
            foreach ($items as $item) {
                $total_processed++;
                
                // Check if article already exists
                $original_link = $item->get_permalink();
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
                    } else {
                        // Skip mode: skip this article
                        continue;
                    }
                }

                // Get original content
                $original_title = $item->get_title();
                $original_description = $item->get_description();
                $original_content = $item->get_content();
                
                // Combine description and content
                $original_text = trim($original_description . "\n\n" . strip_tags($original_content));
                
                // Rewrite using AI
                $rewritten = $this->rewrite_with_ai($original_title, $original_text, $api_key);
                
                if (!$rewritten) {
                    continue;
                }
                
                // Extract image from feed
                $image_url = $this->extract_feed_image($item);

                // Extract metadata from feed
                $feed_metadata = $this->extract_feed_metadata($item, $feed);

                // Create or update WordPress post
                if ($is_update) {
                    // Update existing post
                    $post_data = array(
                        'ID' => $existing_post_id,
                        'post_title' => $rewritten['title'],
                        'post_content' => $rewritten['content']
                    );

                    $post_id = wp_update_post($post_data);

                    if ($post_id) {
                        // Update metadata
                        update_post_meta($post_id, 'envirolink_source_name', $feed['name']);
                        update_post_meta($post_id, 'envirolink_original_title', $original_title);
                        update_post_meta($post_id, 'envirolink_last_updated', current_time('mysql'));

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
                } else {
                    // Create new post with randomized time within today
                    $random_time = $this->get_random_time_today();

                    $post_data = array(
                        'post_title' => $rewritten['title'],
                        'post_content' => $rewritten['content'],
                        'post_status' => $post_status,
                        'post_type' => 'post',
                        'post_author' => 1,
                        'post_date' => $random_time,
                        'post_date_gmt' => get_gmt_from_date($random_time)
                    );

                    if ($post_category) {
                        $post_data['post_category'] = array($post_category);
                    }

                    $post_id = wp_insert_post($post_data);

                    if ($post_id) {
                        // Store metadata
                        update_post_meta($post_id, 'envirolink_source_url', $original_link);
                        update_post_meta($post_id, 'envirolink_source_name', $feed['name']);
                        update_post_meta($post_id, 'envirolink_original_title', $original_title);

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

        return array(
            'success' => true,
            'message' => $message
        );
    }
    
    /**
     * Generate a random datetime within today (00:00:00 to 23:59:59)
     * Uses WordPress timezone settings
     */
    private function get_random_time_today() {
        // Get current date in WordPress timezone
        $today = current_time('Y-m-d');

        // Create timestamps for start and end of today
        $today_start = strtotime($today . ' 00:00:00');
        $today_end = strtotime($today . ' 23:59:59');

        // Generate random timestamp within today
        $random_timestamp = rand($today_start, $today_end);

        // Format as MySQL datetime in local timezone
        return date('Y-m-d H:i:s', $random_timestamp);
    }

    /**
     * Extract and download image from RSS feed item
     */
    private function extract_feed_image($item) {
        // Try to get enclosure (common in RSS feeds for featured images)
        $enclosure = $item->get_enclosure();
        if ($enclosure && $enclosure->get_thumbnail()) {
            return $enclosure->get_thumbnail();
        }
        if ($enclosure && $enclosure->get_link()) {
            $link = $enclosure->get_link();
            // Check if it's an image
            if (preg_match('/\.(jpg|jpeg|png|gif|webp)($|\?)/i', $link)) {
                return $link;
            }
        }

        // Try to extract first image from content
        $content = $item->get_content();
        if ($content) {
            // Look for img tags and extract src attribute
            // Handle both quoted and unquoted attributes, and query parameters
            if (preg_match('/<img[^>]+src=(["\']?)([^"\'>\s]+)\1[^>]*>/i', $content, $matches)) {
                $image_url = $matches[2];
                // Decode HTML entities (like &amp;)
                $image_url = html_entity_decode($image_url, ENT_QUOTES | ENT_HTML5);
                // Verify it looks like an image URL
                if (preg_match('/\.(jpg|jpeg|png|gif|webp)($|\?)/i', $image_url) ||
                    strpos($image_url, 'grist.org') !== false) {
                    return $image_url;
                }
            }
        }

        // Try description
        $description = $item->get_description();
        if ($description) {
            if (preg_match('/<img[^>]+src=(["\']?)([^"\'>\s]+)\1[^>]*>/i', $description, $matches)) {
                $image_url = $matches[2];
                $image_url = html_entity_decode($image_url, ENT_QUOTES | ENT_HTML5);
                if (preg_match('/\.(jpg|jpeg|png|gif|webp)($|\?)/i', $image_url) ||
                    strpos($image_url, 'grist.org') !== false) {
                    return $image_url;
                }
            }
        }

        return null;
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
            return false;
        }

        // Download image
        $tmp = download_url($image_url);

        if (is_wp_error($tmp)) {
            return false;
        }

        // Get file info
        $file_array = array(
            'name' => basename($image_url),
            'tmp_name' => $tmp
        );

        // Upload to media library
        $attachment_id = media_handle_sideload($file_array, $post_id);

        // Cleanup temp file
        @unlink($tmp);

        if (is_wp_error($attachment_id)) {
            return false;
        }

        // Set as featured image
        set_post_thumbnail($post_id, $attachment_id);

        return true;
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

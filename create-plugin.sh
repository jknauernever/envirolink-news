#!/bin/bash

# EnviroLink AI News Aggregator - Installation Script
# Run this script to create all plugin files

echo "Creating EnviroLink AI News Aggregator plugin files..."

# Create main plugin file
cat > envirolink-ai-aggregator.php << 'MAINPHP'
<?php
/**
 * Plugin Name: EnviroLink AI News Aggregator
 * Plugin URI: https://envirolink.org
 * Description: Automatically fetches environmental news from RSS feeds, rewrites content using AI, and publishes to WordPress
 * Version: 1.0.0
 * Author: EnviroLink
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ENVIROLINK_VERSION', '1.0.0');
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
                'enabled' => true
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
            
            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }
        
        // Add feed
        if (isset($_POST['envirolink_add_feed'])) {
            check_admin_referer('envirolink_add_feed');
            
            $feeds = get_option('envirolink_feeds', array());
            $feeds[] = array(
                'url' => esc_url_raw($_POST['feed_url']),
                'name' => sanitize_text_field($_POST['feed_name']),
                'enabled' => true
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
        
        $api_key = get_option('envirolink_api_key', '');
        $post_category = get_option('envirolink_post_category', '');
        $post_status = get_option('envirolink_post_status', 'publish');
        $feeds = get_option('envirolink_feeds', array());
        $last_run = get_option('envirolink_last_run', '');
        $next_run = wp_next_scheduled('envirolink_fetch_feeds');
        
        ?>
        <div class="wrap">
            <h1>EnviroLink AI News Aggregator</h1>
            
            <div class="card" style="max-width: 800px;">
                <h2>System Status</h2>
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
                    <button type="button" class="button button-primary" id="run-now-btn">Run Aggregator Now</button>
                    <span id="run-now-status" style="margin-left: 10px;"></span>
                </p>
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
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($feeds)): ?>
                            <tr>
                                <td colspan="4">No feeds configured</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($feeds as $index => $feed): ?>
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
            
            // Run now button
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
        });
        </script>
        
        <style>
        .tab-content {
            background: #fff;
            padding: 20px;
            border: 1px solid #ccc;
            border-top: none;
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
        
        $result = $this->fetch_and_process_feeds();
        
        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
    
    /**
     * Main function: Fetch and process feeds
     */
    public function fetch_and_process_feeds() {
        $api_key = get_option('envirolink_api_key');
        $feeds = get_option('envirolink_feeds', array());
        $post_category = get_option('envirolink_post_category');
        $post_status = get_option('envirolink_post_status', 'publish');
        
        if (empty($api_key)) {
            return array('success' => false, 'message' => 'API key not configured');
        }
        
        $total_processed = 0;
        $total_created = 0;
        
        foreach ($feeds as $feed) {
            if (!$feed['enabled']) {
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
                
                if (!empty($existing)) {
                    continue;
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
                
                // Create WordPress post
                $post_data = array(
                    'post_title' => $rewritten['title'],
                    'post_content' => $rewritten['content'],
                    'post_status' => $post_status,
                    'post_type' => 'post',
                    'post_author' => 1
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
                    
                    $total_created++;
                }
            }
        }
        
        update_option('envirolink_last_run', current_time('mysql'));
        
        return array(
            'success' => true,
            'message' => "Processed {$total_processed} articles, created {$total_created} new posts"
        );
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
MAINPHP

echo "✓ Created envirolink-ai-aggregator.php"

# Create README
cat > README.md << 'README'
# EnviroLink AI News Aggregator

WordPress plugin that automatically fetches, rewrites, and publishes environmental news.

## Installation

1. Upload to WordPress: Plugins → Add New → Upload Plugin
2. Activate the plugin
3. Go to EnviroLink News in admin sidebar
4. Enter your Anthropic API key
5. Click "Run Aggregator Now" to test

## Features

- Automatic RSS feed fetching
- AI-powered content rewriting (Claude Sonnet 4)
- Hourly automated updates
- Duplicate detection
- Configurable feeds through admin UI
- Manual trigger option

## Configuration

Go to EnviroLink News → Settings:
- Enter your Anthropic API key
- Choose default category
- Set post status (publish/draft)

Go to RSS Feeds tab:
- Add/remove news sources
- Enable/disable feeds
- Pre-configured with Mongabay

## Suggested Feeds

- Mongabay: https://news.mongabay.com/feed/
- The Guardian: https://www.theguardian.com/environment/rss
- Grist: https://grist.org/feed/
- EcoWatch: https://www.ecowatch.com/feed
README

echo "✓ Created README.md"

# Create .gitignore
cat > .gitignore << 'GITIGNORE'
*.log
.DS_Store
*.zip
.env
GITIGNORE

echo "✓ Created .gitignore"

# Create ZIP file
echo ""
echo "Creating ZIP file for WordPress upload..."
zip -q -r envirolink-ai-aggregator.zip . -x "*.sh" "*.zip"

echo "✓ Created envirolink-ai-aggregator.zip"
echo ""
echo "============================================"
echo "✓ All files created successfully!"
echo "============================================"
echo ""
echo "To install:"
echo "1. Upload envirolink-ai-aggregator.zip to WordPress"
echo "2. Go to Plugins → Add New → Upload Plugin"
echo "3. Activate and configure your API key"
echo ""
echo "Or for development:"
echo "  git init"
echo "  git add ."
echo "  git commit -m 'Initial commit'"
echo ""

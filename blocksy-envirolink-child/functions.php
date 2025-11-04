<?php
/**
 * Blocksy EnviroLink Child Theme Functions
 *
 * This file contains:
 * 1. Theme setup and style enqueuing
 * 2. Custom homepage CSS enqueuing
 * 3. EnviroLink metadata display (single posts)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// ============================================
// PARENT THEME CHECK
// ============================================

/**
 * Check if Blocksy parent theme is installed and active
 */
function blocksy_envirolink_check_parent_theme() {
    if (!function_exists('wp_get_theme')) {
        return;
    }

    $theme = wp_get_theme();

    // Check if this is a child theme and if parent exists
    if ($theme->parent() && $theme->get_template() === 'blocksy') {
        // Check if parent theme directory exists
        $parent_theme = $theme->parent();
        if (!$parent_theme->errors()) {
            return; // All good!
        }
    }

    // Parent theme not found - show admin notice
    add_action('admin_notices', 'blocksy_envirolink_parent_theme_notice');
}
add_action('after_setup_theme', 'blocksy_envirolink_check_parent_theme');

/**
 * Display admin notice if parent theme is missing
 */
function blocksy_envirolink_parent_theme_notice() {
    ?>
    <div class="notice notice-error">
        <p><strong>Blocksy EnviroLink Child Theme:</strong> This is a child theme that requires the <strong>Blocksy</strong> parent theme to be installed.</p>
        <p>
            <a href="<?php echo admin_url('theme-install.php?search=blocksy'); ?>" class="button button-primary">Install Blocksy Theme</a>
            or
            <a href="<?php echo admin_url('themes.php'); ?>" class="button">Return to Themes</a>
        </p>
    </div>
    <?php
}

// ============================================
// CUSTOMIZER SETTINGS
// ============================================

/**
 * Add customizer options for EnviroLink features
 */
function envirolink_customize_register($wp_customize) {
    // Add EnviroLink Settings Section
    $wp_customize->add_section('envirolink_settings', array(
        'title'    => 'EnviroLink Display Options',
        'priority' => 30,
    ));

    // Show/Hide Source Labels
    $wp_customize->add_setting('envirolink_show_source', array(
        'default'           => true,
        'sanitize_callback' => 'wp_validate_boolean',
        'transport'         => 'refresh',
    ));

    $wp_customize->add_control('envirolink_show_source', array(
        'label'    => 'Show Article Source Labels',
        'description' => 'Display source name (e.g., "Mongabay", "The Guardian") before article titles',
        'section'  => 'envirolink_settings',
        'type'     => 'checkbox',
    ));
}
add_action('customize_register', 'envirolink_customize_register');

// ============================================
// THEME SETUP
// ============================================

/**
 * Enqueue parent and child theme styles
 */
function blocksy_envirolink_enqueue_styles() {
    // Parent theme style
    wp_enqueue_style('blocksy-parent-style', get_template_directory_uri() . '/style.css');

    // Child theme style
    wp_enqueue_style('blocksy-envirolink-child-style',
        get_stylesheet_uri(),
        array('blocksy-parent-style'),
        wp_get_theme()->get('Version')
    );
}
add_action('wp_enqueue_scripts', 'blocksy_envirolink_enqueue_styles');


// ============================================
// CUSTOM HOMEPAGE STYLES
// ============================================

/**
 * Enqueue custom homepage CSS
 * Only loads on the front page for performance
 */
function envirolink_enqueue_homepage_styles() {
    if (is_front_page()) {
        wp_enqueue_style(
            'envirolink-homepage',
            get_stylesheet_directory_uri() . '/envirolink-homepage.css',
            array(),
            '1.0.0'
        );
    }
}
add_action('wp_enqueue_scripts', 'envirolink_enqueue_homepage_styles');


// ============================================
// ENVIROLINK METADATA DISPLAY (Single Posts)
// ============================================

/**
 * Add full metadata box after content on single posts
 * Displays: Source, Author, Publication Date, Topics, Locations
 */
function envirolink_single_metadata() {
    // Only show on single posts
    if (!is_single()) {
        return;
    }

    $post_id = get_the_ID();

    // Get all metadata
    $source_name = get_post_meta($post_id, 'envirolink_source_name', true);
    $source_url = get_post_meta($post_id, 'envirolink_source_url', true);
    $author = get_post_meta($post_id, 'envirolink_author', true);
    $pubdate = get_post_meta($post_id, 'envirolink_pubdate', true);
    $tags = get_post_meta($post_id, 'envirolink_topic_tags', true);
    $locations = get_post_meta($post_id, 'envirolink_locations', true);

    // Only display if this is an aggregated post
    if (!$source_name) {
        return;
    }

    ?>
    <div class="envirolink-single-meta">
        <div class="envirolink-meta-header">
            <svg class="envirolink-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
            </svg>
            <h4>Article Source</h4>
        </div>

        <div class="envirolink-meta-content">
            <?php if ($source_name && $source_url): ?>
            <div class="envirolink-meta-item envirolink-meta-source">
                <span class="meta-label">Source:</span>
                <span class="meta-value">
                    <a href="<?php echo esc_url($source_url); ?>" target="_blank" rel="noopener noreferrer">
                        <?php echo esc_html($source_name); ?>
                        <svg class="external-link-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                            <polyline points="15 3 21 3 21 9"></polyline>
                            <line x1="10" y1="14" x2="21" y2="3"></line>
                        </svg>
                    </a>
                </span>
            </div>
            <?php endif; ?>

            <?php if ($author): ?>
            <div class="envirolink-meta-item">
                <span class="meta-label">Original Author:</span>
                <span class="meta-value"><?php echo esc_html($author); ?></span>
            </div>
            <?php endif; ?>

            <?php if ($pubdate): ?>
            <div class="envirolink-meta-item">
                <span class="meta-label">Originally Published:</span>
                <span class="meta-value"><?php echo esc_html(date('F j, Y', strtotime($pubdate))); ?></span>
            </div>
            <?php endif; ?>

            <?php if ($locations): ?>
            <div class="envirolink-meta-item">
                <span class="meta-label">Locations:</span>
                <span class="meta-value"><?php echo esc_html($locations); ?></span>
            </div>
            <?php endif; ?>

            <?php if ($tags): ?>
            <div class="envirolink-meta-item envirolink-meta-tags">
                <span class="meta-label">Topics:</span>
                <div class="meta-value">
                    <?php
                    $tag_array = array_map('trim', explode(',', $tags));
                    foreach ($tag_array as $tag) {
                        // Get WordPress tag object for link
                        $tag_obj = get_term_by('name', $tag, 'post_tag');
                        if ($tag_obj) {
                            $tag_link = get_tag_link($tag_obj->term_id);
                            echo '<a href="' . esc_url($tag_link) . '" class="envirolink-topic-tag">' . esc_html($tag) . '</a>';
                        } else {
                            // Fallback if tag doesn't exist
                            echo '<span class="envirolink-topic-tag">' . esc_html($tag) . '</span>';
                        }
                    }
                    ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($source_url): ?>
            <div class="envirolink-meta-action">
                <a href="<?php echo esc_url($source_url); ?>" target="_blank" rel="noopener noreferrer" class="envirolink-read-original">
                    Read Original Article →
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <style>
    /* EnviroLink Single Post Metadata Styles */
    .envirolink-single-meta {
        margin: 30px 0;
        padding: 25px;
        background: #f8f9fa;
        border-left: 4px solid #2563eb;
        border-radius: 4px;
    }

    .envirolink-meta-header {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 1px solid #dee2e6;
    }

    .envirolink-meta-header h4 {
        margin: 0;
        font-size: 16px;
        font-weight: 600;
        color: #2563eb;
    }

    .envirolink-icon {
        color: #2563eb;
    }

    .envirolink-meta-content {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .envirolink-meta-item {
        display: flex;
        gap: 10px;
        font-size: 14px;
    }

    .meta-label {
        font-weight: 600;
        color: #495057;
        min-width: 150px;
    }

    .meta-value {
        color: #212529;
    }

    .meta-value a {
        color: #2563eb;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .meta-value a:hover {
        text-decoration: underline;
    }

    .external-link-icon {
        width: 12px;
        height: 12px;
    }

    .envirolink-topic-tag {
        display: inline-block;
        background: white;
        padding: 4px 12px;
        margin: 2px;
        border-radius: 12px;
        font-size: 13px;
        color: #495057;
        text-decoration: none;
        border: 1px solid #dee2e6;
    }

    .envirolink-topic-tag:hover {
        background: #e9ecef;
        border-color: #2563eb;
    }

    .envirolink-meta-tags .meta-value {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
    }

    .envirolink-meta-action {
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px solid #dee2e6;
    }

    .envirolink-read-original {
        display: inline-block;
        padding: 10px 20px;
        background: #2563eb;
        color: white !important;
        text-decoration: none;
        border-radius: 4px;
        font-weight: 600;
        font-size: 14px;
        transition: background 0.2s;
    }

    .envirolink-read-original:hover {
        background: #1d4ed8;
        text-decoration: none;
    }

    @media (max-width: 768px) {
        .envirolink-meta-item {
            flex-direction: column;
            gap: 4px;
        }

        .meta-label {
            min-width: auto;
        }
    }
    </style>
    <?php
}

// Hook into Blocksy's single post - after content
add_action('blocksy:single:content:bottom', 'envirolink_single_metadata', 10);

// Fallback: If Blocksy hook doesn't work, use WordPress filter
// Uncomment the code below if metadata doesn't appear on single posts
/*
function envirolink_add_to_content($content) {
    if (is_single()) {
        ob_start();
        envirolink_single_metadata();
        $metadata = ob_get_clean();
        return $content . $metadata;
    }
    return $content;
}
add_filter('the_content', 'envirolink_add_to_content', 20);
*/

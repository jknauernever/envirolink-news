<?php
/**
 * EnviroLink Metadata Display for Blocksy Child Theme
 *
 * INSTRUCTIONS:
 * 1. Copy this entire code
 * 2. Paste it at the END of your child theme's functions.php file
 * 3. Location: wp-content/themes/blocksy-child/functions.php
 */

// ============================================
// PART 1: Display on Listing Pages (Homepage/Archives)
// ============================================
// DISABLED: Metadata removed from listing pages per user request
// To re-enable, uncomment the code below

/*
/**
 * Add compact metadata to post cards on listing pages
 *\/
function envirolink_listing_metadata() {
    // Only show on listing pages (not single posts)
    if (is_single()) {
        return;
    }

    $post_id = get_the_ID();

    // Get metadata
    $source_name = get_post_meta($post_id, 'envirolink_source_name', true);
    $author = get_post_meta($post_id, 'envirolink_author', true);
    $pubdate = get_post_meta($post_id, 'envirolink_pubdate', true);
    $tags = get_post_meta($post_id, 'envirolink_topic_tags', true);

    // Only display if this is an aggregated post
    if (!$source_name) {
        return;
    }

    // Build metadata line
    $metadata_parts = array();

    if ($source_name) {
        $metadata_parts[] = '<span class="envirolink-source">' . esc_html($source_name) . '</span>';
    }

    if ($author) {
        $metadata_parts[] = '<span class="envirolink-author">' . esc_html($author) . '</span>';
    }

    if ($pubdate) {
        $formatted_date = date('M j, Y', strtotime($pubdate));
        $metadata_parts[] = '<span class="envirolink-date">' . esc_html($formatted_date) . '</span>';
    }

    if ($tags) {
        // Get first tag only for listing view
        $tag_array = array_map('trim', explode(',', $tags));
        $first_tag = $tag_array[0];

        // Get WordPress tag object for link
        $tag_obj = get_term_by('name', $first_tag, 'post_tag');
        if ($tag_obj) {
            $tag_link = get_tag_link($tag_obj->term_id);
            $metadata_parts[] = '<a href="' . esc_url($tag_link) . '" class="envirolink-tag">' . esc_html($first_tag) . '</a>';
        } else {
            // Fallback if tag doesn't exist yet
            $metadata_parts[] = '<span class="envirolink-tag">' . esc_html($first_tag) . '</span>';
        }
    }

    // Output metadata
    if (!empty($metadata_parts)) {
        echo '<div class="envirolink-listing-meta">';
        echo implode(' <span class="envirolink-separator">•</span> ', $metadata_parts);
        echo '</div>';
    }
}

// Hook into Blocksy's post card - try multiple hooks for compatibility
add_action('blocksy:loop:card:end', 'envirolink_listing_metadata', 10);
add_action('blocksy:posts-loop:after:excerpt', 'envirolink_listing_metadata', 10);
*/


// ============================================
// PART 2: Display on Single Post Pages
// ============================================

/**
 * Add minimal source attribution after content on single posts
 */
function envirolink_single_metadata() {
    // Only show on single posts
    if (!is_single()) {
        return;
    }

    $post_id = get_the_ID();

    // Get source metadata
    $source_name = get_post_meta($post_id, 'envirolink_source_name', true);
    $source_url = get_post_meta($post_id, 'envirolink_source_url', true);

    // Only display if this is an aggregated post
    if (!$source_name) {
        return;
    }

    ?>
    <p class="envirolink-source-attribution">
        This article was written based on the source article from
        <?php if ($source_url): ?>
            <a href="<?php echo esc_url($source_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($source_name); ?></a>
        <?php else: ?>
            <?php echo esc_html($source_name); ?>
        <?php endif; ?>
    </p>
    <?php
}

// Hook into Blocksy's single post - after content
add_action('blocksy:single:content:bottom', 'envirolink_single_metadata', 10);


// ============================================
// PART 4: Enqueue Custom Homepage Styles
// ============================================

/**
 * Enqueue custom homepage CSS
 */
function envirolink_enqueue_homepage_styles() {
    // Only load on the homepage
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
// PART 3: Alternative Hook (if above doesn't work)
// ============================================

/**
 * If Blocksy hooks don't work, use WordPress filter instead
 * UNCOMMENT the lines below if the hooks above don't display anything
 */

/*
function envirolink_add_to_content($content) {
    if (is_single()) {
        // Capture single post metadata
        ob_start();
        envirolink_single_metadata();
        $metadata = ob_get_clean();

        // Add after content
        return $content . $metadata;
    }

    return $content;
}
add_filter('the_content', 'envirolink_add_to_content', 20);
*/

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
 * Add full metadata box after content on single posts
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
    <?php
}

// Hook into Blocksy's single post - after content
add_action('blocksy:single:content:bottom', 'envirolink_single_metadata', 10);


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

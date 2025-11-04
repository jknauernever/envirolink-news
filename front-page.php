<?php
/**
 * Custom Homepage Template for EnviroLink
 * File: front-page.php
 *
 * Upload this to: wp-content/themes/blocksy-child/
 *
 * Layout:
 * - Featured Daily Roundup (left, large image)
 * - Recent Headlines (right sidebar)
 * - News Grid (below, full width)
 */

get_header();
?>

<div class="envirolink-homepage">

    <!-- Hero Section: Daily Roundup + Recent Headlines -->
    <div class="envirolink-hero-section">
        <div class="envirolink-container">
            <div class="envirolink-hero-grid">

                <!-- LEFT: Featured Daily Roundup -->
                <div class="envirolink-hero-featured">
                    <?php
                    // Query for the most recent daily roundup
                    $roundup_query = new WP_Query(array(
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

                    if ($roundup_query->have_posts()) :
                        while ($roundup_query->have_posts()) : $roundup_query->the_post();
                    ?>
                        <article class="envirolink-roundup-card">
                            <?php if (has_post_thumbnail()) : ?>
                                <div class="envirolink-roundup-image">
                                    <a href="<?php the_permalink(); ?>">
                                        <?php the_post_thumbnail('large'); ?>
                                    </a>
                                    <div class="envirolink-roundup-badge">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                            <polyline points="14 2 14 8 20 8"></polyline>
                                            <line x1="16" y1="13" x2="8" y2="13"></line>
                                            <line x1="16" y1="17" x2="8" y2="17"></line>
                                            <polyline points="10 9 9 9 8 9"></polyline>
                                        </svg>
                                        Daily Roundup
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="envirolink-roundup-content">
                                <div class="envirolink-roundup-meta">
                                    <span class="envirolink-category">HOT STORIES</span>
                                    <span class="envirolink-date"><?php echo get_the_date('F j, Y'); ?></span>
                                </div>

                                <h2 class="envirolink-roundup-title">
                                    <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                                </h2>

                                <div class="envirolink-roundup-excerpt">
                                    <?php echo wp_trim_words(get_the_excerpt(), 35, '...'); ?>
                                </div>

                                <div class="envirolink-roundup-footer">
                                    <?php
                                    $article_count = get_post_meta(get_the_ID(), 'envirolink_roundup_article_count', true);
                                    if ($article_count) {
                                        echo '<span class="envirolink-article-count">' . $article_count . ' stories covered</span>';
                                    }
                                    ?>
                                    <span class="envirolink-author">By EnviroLink Team</span>
                                </div>
                            </div>
                        </article>
                    <?php
                        endwhile;
                        wp_reset_postdata();
                    endif;
                    ?>
                </div>

                <!-- RIGHT: Recent Headlines -->
                <div class="envirolink-hero-sidebar">
                    <div class="envirolink-sidebar-header">
                        <h3>Latest News</h3>
                    </div>

                    <?php
                    // Query for recent posts (excluding roundups)
                    $recent_query = new WP_Query(array(
                        'post_type' => 'post',
                        'posts_per_page' => 6,
                        'meta_query' => array(
                            'relation' => 'OR',
                            array(
                                'key' => 'envirolink_is_roundup',
                                'compare' => 'NOT EXISTS'
                            ),
                            array(
                                'key' => 'envirolink_is_roundup',
                                'value' => 'yes',
                                'compare' => '!='
                            )
                        ),
                        'orderby' => 'date',
                        'order' => 'DESC'
                    ));

                    if ($recent_query->have_posts()) :
                        echo '<div class="envirolink-headlines-list">';
                        while ($recent_query->have_posts()) : $recent_query->the_post();
                            $source_name = get_post_meta(get_the_ID(), 'envirolink_source_name', true);
                    ?>
                        <article class="envirolink-headline-item">
                            <h4 class="envirolink-headline-title">
                                <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                            </h4>
                            <div class="envirolink-headline-meta">
                                <?php if ($source_name) : ?>
                                    <span class="envirolink-source"><?php echo esc_html($source_name); ?></span>
                                    <span class="envirolink-separator">•</span>
                                <?php endif; ?>
                                <span class="envirolink-date"><?php echo get_the_date('M j, Y'); ?></span>
                            </div>
                        </article>
                    <?php
                        endwhile;
                        echo '</div>';
                        wp_reset_postdata();
                    endif;
                    ?>
                </div>

            </div>
        </div>
    </div>

    <!-- News Grid Section -->
    <div class="envirolink-grid-section">
        <div class="envirolink-container">

            <!-- Section Headers with Categories -->
            <div class="envirolink-section-tabs">
                <a href="#" class="envirolink-tab active" data-category="all">All Stories</a>
                <a href="<?php echo get_category_link(get_cat_ID('Politics')); ?>" class="envirolink-tab">Politics</a>
                <a href="<?php echo get_category_link(get_cat_ID('Hollywood')); ?>" class="envirolink-tab">Climate</a>
                <a href="<?php echo get_category_link(get_cat_ID('Finance')); ?>" class="envirolink-tab">Conservation</a>
            </div>

            <!-- News Grid -->
            <?php
            // Query for grid articles (excluding roundups and the recent 6)
            $grid_query = new WP_Query(array(
                'post_type' => 'post',
                'posts_per_page' => 12,
                'offset' => 6, // Skip the 6 we already showed in sidebar
                'meta_query' => array(
                    'relation' => 'OR',
                    array(
                        'key' => 'envirolink_is_roundup',
                        'compare' => 'NOT EXISTS'
                    ),
                    array(
                        'key' => 'envirolink_is_roundup',
                        'value' => 'yes',
                        'compare' => '!='
                    )
                ),
                'orderby' => 'date',
                'order' => 'DESC'
            ));

            if ($grid_query->have_posts()) :
                echo '<div class="envirolink-news-grid">';
                while ($grid_query->have_posts()) : $grid_query->the_post();
                    $source_name = get_post_meta(get_the_ID(), 'envirolink_source_name', true);
            ?>
                <article class="envirolink-grid-item">
                    <?php if (has_post_thumbnail()) : ?>
                        <div class="envirolink-grid-image">
                            <a href="<?php the_permalink(); ?>">
                                <?php the_post_thumbnail('medium_large'); ?>
                            </a>
                        </div>
                    <?php endif; ?>

                    <div class="envirolink-grid-content">
                        <div class="envirolink-grid-meta">
                            <?php if ($source_name) : ?>
                                <span class="envirolink-source"><?php echo esc_html($source_name); ?></span>
                            <?php endif; ?>
                        </div>

                        <h3 class="envirolink-grid-title">
                            <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                        </h3>

                        <div class="envirolink-grid-excerpt">
                            <?php echo wp_trim_words(get_the_excerpt(), 20, '...'); ?>
                        </div>

                        <div class="envirolink-grid-footer">
                            <span class="envirolink-date"><?php echo get_the_date('M j, Y'); ?></span>
                        </div>
                    </div>
                </article>
            <?php
                endwhile;
                echo '</div>';
                wp_reset_postdata();
            endif;
            ?>

        </div>
    </div>

</div>

<?php
get_footer();

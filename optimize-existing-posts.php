<?php
/**
 * EnviroLink Post Optimization Script
 *
 * ONE-TIME USE: Optimizes all existing posts with v1.34.0 SEO improvements
 *
 * INSTRUCTIONS:
 * 1. Upload this file to your WordPress root directory (same level as wp-config.php)
 * 2. Visit: https://envirolink.org/optimize-existing-posts.php?key=ENVIROLINK_2025_OPTIMIZE
 * 3. Click "Preview Changes" first to see what will be modified
 * 4. Click "Apply Changes" to actually optimize posts
 * 5. DELETE this file when complete for security
 */

// Security: Require secret key
$required_key = 'ENVIROLINK_2025_OPTIMIZE';
$provided_key = isset($_GET['key']) ? $_GET['key'] : '';

if ($provided_key !== $required_key) {
    die('Access denied. Correct URL format: optimize-existing-posts.php?key=ENVIROLINK_2025_OPTIMIZE');
}

// Load WordPress
require_once('wp-load.php');

// Check if user is admin (additional security)
if (!current_user_can('manage_options')) {
    die('Error: You must be logged into WordPress as an administrator to run this script.');
}

// Get action
$action = isset($_GET['action']) ? $_GET['action'] : 'preview';
$dry_run = ($action !== 'apply');

?>
<!DOCTYPE html>
<html>
<head>
    <title>EnviroLink Post Optimization</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            max-width: 1200px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2563eb;
            border-bottom: 3px solid #2563eb;
            padding-bottom: 10px;
        }
        .stats {
            background: #f0f9ff;
            border-left: 4px solid #2563eb;
            padding: 15px;
            margin: 20px 0;
        }
        .warning {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            margin: 20px 0;
        }
        .success {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            padding: 15px;
            margin: 20px 0;
        }
        .log {
            background: #1f2937;
            color: #10b981;
            padding: 20px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 13px;
            max-height: 600px;
            overflow-y: auto;
            margin: 20px 0;
        }
        .log-item {
            margin: 5px 0;
            padding: 3px 0;
        }
        .log-item.change {
            color: #fbbf24;
        }
        .log-item.skip {
            color: #9ca3af;
        }
        .log-item.error {
            color: #ef4444;
        }
        .buttons {
            margin: 30px 0;
            display: flex;
            gap: 15px;
        }
        .button {
            padding: 12px 24px;
            font-size: 16px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-weight: 600;
        }
        .button-primary {
            background: #2563eb;
            color: white;
        }
        .button-primary:hover {
            background: #1d4ed8;
        }
        .button-secondary {
            background: #6b7280;
            color: white;
        }
        .button-secondary:hover {
            background: #4b5563;
        }
        .button-success {
            background: #10b981;
            color: white;
        }
        .button-success:hover {
            background: #059669;
        }
        .progress {
            background: #e5e7eb;
            height: 30px;
            border-radius: 15px;
            overflow: hidden;
            margin: 20px 0;
        }
        .progress-bar {
            background: linear-gradient(90deg, #2563eb, #10b981);
            height: 100%;
            transition: width 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        th {
            background: #f9fafb;
            font-weight: 600;
        }
        .old-value {
            color: #ef4444;
            text-decoration: line-through;
        }
        .new-value {
            color: #10b981;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🌍 EnviroLink Post Optimization</h1>

        <?php if ($dry_run): ?>
            <div class="warning">
                <strong>⚠️ PREVIEW MODE</strong><br>
                This is a dry run. No changes will be made to your posts. Review the changes below, then click "Apply Changes" to actually optimize your posts.
            </div>
        <?php else: ?>
            <div class="success">
                <strong>✅ APPLYING CHANGES</strong><br>
                Posts are being optimized with SEO improvements from v1.34.0...
            </div>
        <?php endif; ?>

        <?php
        // Start optimization
        $start_time = microtime(true);

        // Get all posts with envirolink metadata
        $args = array(
            'post_type' => 'post',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => 'envirolink_source_url',
                    'compare' => 'EXISTS'
                )
            ),
            'orderby' => 'ID',
            'order' => 'ASC'
        );

        $posts = get_posts($args);
        $total_posts = count($posts);

        echo '<div class="stats">';
        echo '<strong>📊 Found ' . number_format($total_posts) . ' EnviroLink posts to process</strong>';
        echo '</div>';

        // Statistics
        $stats = array(
            'processed' => 0,
            'titles_optimized' => 0,
            'schema_added' => 0,
            'meta_added' => 0,
            'roundups_updated' => 0,
            'skipped' => 0,
            'errors' => 0
        );

        echo '<div class="progress">';
        echo '<div class="progress-bar" style="width: 0%">0%</div>';
        echo '</div>';

        echo '<div class="log">';
        echo '<div class="log-item">Starting optimization of ' . number_format($total_posts) . ' posts...</div>';
        echo '<div class="log-item">Mode: ' . ($dry_run ? 'PREVIEW (no changes will be saved)' : 'APPLY (changes will be saved)') . '</div>';
        echo '<div class="log-item">---</div>';

        // Process each post
        foreach ($posts as $index => $post) {
            $post_id = $post->ID;
            $stats['processed']++;
            $changes = array();

            // Check if it's a roundup
            $is_roundup = get_post_meta($post_id, 'envirolink_is_roundup', true) === 'yes';

            // 1. Optimize title
            $current_title = $post->post_title;
            $optimized_title = optimize_title_for_seo($current_title, $is_roundup);

            if ($optimized_title !== $current_title) {
                $changes[] = 'Title optimized';
                $stats['titles_optimized']++;

                if (!$dry_run) {
                    wp_update_post(array(
                        'ID' => $post_id,
                        'post_title' => $optimized_title
                    ));
                }
            }

            // 2. Add AIOSEO schema markup if not present
            $existing_schema = get_post_meta($post_id, '_aioseo_schema_article_type', true);
            if (empty($existing_schema)) {
                $changes[] = 'Schema markup added';
                $stats['schema_added']++;

                if (!$dry_run) {
                    update_post_meta($post_id, '_aioseo_schema_type', 'Article');
                    update_post_meta($post_id, '_aioseo_schema_article_type', 'NewsArticle');
                }
            }

            // 3. Add meta description if not present (for roundups)
            if ($is_roundup) {
                $existing_desc = get_post_meta($post_id, '_aioseo_description', true);
                if (empty($existing_desc)) {
                    $post_date = get_the_date('M j, Y', $post_id);
                    $meta_description = 'Today\'s top environmental stories: climate action, wildlife conservation, renewable energy, and sustainability news from around the world. Updated ' . $post_date . '.';

                    $changes[] = 'Meta description added';
                    $stats['meta_added']++;

                    if (!$dry_run) {
                        update_post_meta($post_id, '_aioseo_description', $meta_description);
                        update_post_meta($post_id, '_aioseo_og_article_section', 'Environment');
                        update_post_meta($post_id, '_aioseo_og_article_tags', 'environmental news,climate change,conservation,sustainability');

                        // Update excerpt
                        wp_update_post(array(
                            'ID' => $post_id,
                            'post_excerpt' => $meta_description
                        ));
                    }
                }

                $stats['roundups_updated']++;
            }

            // Log changes
            if (!empty($changes)) {
                echo '<div class="log-item change">';
                echo '✓ Post #' . $post_id . ' (' . esc_html(substr($current_title, 0, 60)) . '...): ' . implode(', ', $changes);
                echo '</div>';
            } else {
                $stats['skipped']++;
                if ($index % 50 === 0) { // Only show every 50th skipped post to reduce noise
                    echo '<div class="log-item skip">';
                    echo '○ Post #' . $post_id . ': Already optimized';
                    echo '</div>';
                }
            }

            // Update progress every 10 posts
            if ($index % 10 === 0 || $index === $total_posts - 1) {
                $percent = round((($index + 1) / $total_posts) * 100);
                echo '<script>
                    document.querySelector(".progress-bar").style.width = "' . $percent . '%";
                    document.querySelector(".progress-bar").textContent = "' . $percent . '%";
                </script>';
                flush();
                ob_flush();
            }
        }

        $end_time = microtime(true);
        $duration = round($end_time - $start_time, 2);

        echo '<div class="log-item">---</div>';
        echo '<div class="log-item">✅ Optimization complete! Processed ' . number_format($stats['processed']) . ' posts in ' . $duration . ' seconds</div>';
        echo '</div>';

        // Summary
        echo '<div class="stats">';
        echo '<h3>📈 Summary</h3>';
        echo '<table>';
        echo '<tr><th>Metric</th><th>Count</th></tr>';
        echo '<tr><td>Total Posts Processed</td><td><strong>' . number_format($stats['processed']) . '</strong></td></tr>';
        echo '<tr><td>Titles Optimized</td><td>' . number_format($stats['titles_optimized']) . '</td></tr>';
        echo '<tr><td>Schema Markup Added</td><td>' . number_format($stats['schema_added']) . '</td></tr>';
        echo '<tr><td>Meta Descriptions Added</td><td>' . number_format($stats['meta_added']) . '</td></tr>';
        echo '<tr><td>Roundups Updated</td><td>' . number_format($stats['roundups_updated']) . '</td></tr>';
        echo '<tr><td>Already Optimized (Skipped)</td><td>' . number_format($stats['skipped']) . '</td></tr>';
        echo '<tr><td>Processing Time</td><td>' . $duration . ' seconds</td></tr>';
        echo '</table>';
        echo '</div>';

        // Action buttons
        echo '<div class="buttons">';
        if ($dry_run) {
            echo '<a href="?key=' . urlencode($required_key) . '&action=apply" class="button button-success">';
            echo '✅ Apply Changes (Optimize All Posts)';
            echo '</a>';
            echo '<a href="?key=' . urlencode($required_key) . '&action=preview" class="button button-secondary">';
            echo '🔄 Run Preview Again';
            echo '</a>';
        } else {
            echo '<div class="success">';
            echo '<strong>✅ COMPLETE!</strong><br>';
            echo 'All posts have been optimized. You can now delete this file (optimize-existing-posts.php) from your server.';
            echo '</div>';
        }
        echo '</div>';

        /**
         * Optimize title for SEO
         * Same logic as v1.34.0 plugin
         */
        function optimize_title_for_seo($title, $is_roundup = false) {
            // For roundups, use the new format
            if ($is_roundup && stripos($title, 'Daily Environmental News Roundup') !== false) {
                // Extract date from old format
                if (preg_match('/([A-Z][a-z]+ \d+, \d{4})/', $title, $matches)) {
                    $date = $matches[1];
                    $date_obj = DateTime::createFromFormat('F j, Y', $date);
                    if ($date_obj) {
                        $month_day = $date_obj->format('M j');
                        return 'Environmental News Today: Climate, Wildlife & Conservation Updates [' . $month_day . ']';
                    }
                }
            }

            // Remove excessive punctuation
            $title = preg_replace('/[!]{2,}/', '!', $title);
            $title = preg_replace('/[?]{2,}/', '?', $title);

            // Capitalize first letter of each sentence
            $title = ucfirst($title);
            $title = preg_replace_callback('/([.!?]\s+)([a-z])/', function($matches) {
                return $matches[1] . strtoupper($matches[2]);
            }, $title);

            // Truncate if too long (Google displays ~60 chars in title)
            if (strlen($title) > 70) {
                $title = substr($title, 0, 67) . '...';
            }

            return $title;
        }
        ?>

    </div>
</body>
</html>

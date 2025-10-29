<?php
/**
 * Diagnostic script to check EnviroLink post dates
 *
 * Upload this to your WordPress root directory and access via browser:
 * https://www.envirolink.org/check-post-dates.php
 *
 * This will show you:
 * - How WordPress is currently ordering posts
 * - What dates are stored in each post
 * - Whether RSS pubdates are stored in metadata
 */

// Load WordPress
require_once(__DIR__ . '/wp-load.php');

// Security check
if (!current_user_can('manage_options')) {
    die('Unauthorized - you must be logged in as an admin');
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>EnviroLink Post Date Diagnostic</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #4CAF50; color: white; }
        tr:nth-child(even) { background-color: #f2f2f2; }
        .warning { color: red; font-weight: bold; }
        .good { color: green; }
        h1 { color: #333; }
        .section { margin: 30px 0; }
    </style>
</head>
<body>
    <h1>EnviroLink Post Date Diagnostic</h1>

    <div class="section">
        <h2>Current WordPress Query (How Homepage Orders Posts)</h2>
        <?php
        // Get posts as WordPress would show them on homepage
        $args = array(
            'post_type' => 'post',
            'posts_per_page' => 20,
            'meta_key' => 'envirolink_source_url',
            'meta_compare' => 'EXISTS'
        );

        $query = new WP_Query($args);

        echo "<p><strong>Query details:</strong></p>";
        echo "<pre>";
        echo "Orderby: " . ($query->get('orderby') ?: 'date (default)') . "\n";
        echo "Order: " . ($query->get('order') ?: 'DESC (default)') . "\n";
        echo "Posts found: " . $query->found_posts . "\n";
        echo "</pre>";
        ?>

        <table>
            <tr>
                <th>Display Order</th>
                <th>Post Title</th>
                <th>Post Date<br>(WordPress)</th>
                <th>Post Modified</th>
                <th>RSS PubDate<br>(metadata)</th>
                <th>Source</th>
                <th>Issue?</th>
            </tr>
            <?php
            $order = 1;
            if ($query->have_posts()) {
                while ($query->have_posts()) {
                    $query->the_post();
                    $post_id = get_the_ID();

                    $post_date = get_the_date('Y-m-d H:i:s');
                    $post_modified = get_the_modified_date('Y-m-d H:i:s');
                    $rss_pubdate = get_post_meta($post_id, 'envirolink_pubdate', true);
                    $source_name = get_post_meta($post_id, 'envirolink_source_name', true);

                    // Check for issues
                    $issue = '';
                    if (empty($rss_pubdate)) {
                        $issue = '<span class="warning">No RSS pubdate stored</span>';
                    } elseif (strtotime($post_date) != strtotime($rss_pubdate)) {
                        $issue = '<span class="warning">Post date ≠ RSS pubdate</span>';
                    }

                    if (empty($issue)) {
                        $issue = '<span class="good">✓ OK</span>';
                    }

                    echo "<tr>";
                    echo "<td>{$order}</td>";
                    echo "<td>" . esc_html(get_the_title()) . "</td>";
                    echo "<td>{$post_date}</td>";
                    echo "<td>{$post_modified}</td>";
                    echo "<td>" . ($rss_pubdate ? date('Y-m-d H:i:s', strtotime($rss_pubdate)) : '<span class="warning">None</span>') . "</td>";
                    echo "<td>" . esc_html($source_name) . "</td>";
                    echo "<td>{$issue}</td>";
                    echo "</tr>";

                    $order++;
                }
            }
            wp_reset_postdata();
            ?>
        </table>
    </div>

    <div class="section">
        <h2>Recommendation</h2>
        <?php
        // Count posts with issues
        $posts_without_rss_date = 0;
        $posts_with_mismatched_dates = 0;

        $all_posts = get_posts(array(
            'post_type' => 'post',
            'posts_per_page' => -1,
            'meta_key' => 'envirolink_source_url',
            'meta_compare' => 'EXISTS'
        ));

        foreach ($all_posts as $post) {
            $rss_pubdate = get_post_meta($post->ID, 'envirolink_pubdate', true);
            if (empty($rss_pubdate)) {
                $posts_without_rss_date++;
            } elseif (date('Y-m-d', strtotime($post->post_date)) != date('Y-m-d', strtotime($rss_pubdate))) {
                $posts_with_mismatched_dates++;
            }
        }

        echo "<p><strong>Found {$posts_without_rss_date} posts</strong> without RSS publication dates stored.</p>";
        echo "<p><strong>Found {$posts_with_mismatched_dates} posts</strong> where WordPress post_date doesn't match RSS pubdate.</p>";

        if ($posts_without_rss_date > 0 || $posts_with_mismatched_dates > 0) {
            echo '<p class="warning">⚠️ ISSUE DETECTED: Some posts have incorrect dates.</p>';
            echo '<p><strong>Solution:</strong> Ask Claude to create a script to fix all post dates by using the stored RSS pubdates.</p>';
        } else {
            echo '<p class="good">✓ All posts have correct dates!</p>';
            echo '<p>The ordering issue may be caused by your theme or a plugin. Check your homepage query settings.</p>';
        }
        ?>
    </div>

    <div class="section">
        <h2>Theme Query Check</h2>
        <p>Your theme might be using a custom query that orders posts differently.</p>
        <p><strong>To fix this:</strong> Go to <code>Appearance → Customize → Homepage Settings</code> and ensure "Your latest posts" is selected, or check your theme's blog settings.</p>
    </div>

    <p><em>After reviewing this diagnostic, you can delete this file for security.</em></p>
</body>
</html>

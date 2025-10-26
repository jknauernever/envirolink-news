# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

EnviroLink AI News Aggregator is a WordPress plugin that automates environmental news aggregation using Anthropic's Claude AI. The plugin fetches articles from RSS feeds, rewrites them using AI, and publishes them as WordPress posts.

## Architecture

### Single-File Plugin Structure

This is a monolithic WordPress plugin contained entirely in `envirolink-ai-aggregator.php`. The plugin uses a singleton pattern with the main class `EnviroLink_AI_Aggregator`.

**Key architectural components:**

1. **WordPress Options API** - All configuration stored in wp_options:
   - `envirolink_feeds`: Array of RSS feed objects with:
     - `url`: Feed URL
     - `name`: Display name
     - `enabled`: Boolean active status
     - `schedule_type`: 'hourly', 'daily', 'weekly', or 'monthly'
     - `schedule_times`: Number of times to process per period (1-24)
     - `last_processed`: Unix timestamp of last processing
     - `include_author`: Boolean - extract author (dc:creator)
     - `include_pubdate`: Boolean - extract publication date
     - `include_topic_tags`: Boolean - extract topic tags
     - `include_locations`: Boolean - extract locations
   - `envirolink_api_key`: Anthropic API key (stored as password)
   - `envirolink_post_category`: Default category ID for posts
   - `envirolink_post_status`: 'publish' or 'draft'
   - `envirolink_update_existing`: 'yes' or 'no' - update existing posts vs skip
   - `envirolink_last_run`: MySQL datetime of last execution

2. **WordPress Cron** - Automated processing:
   - Hook: `envirolink_fetch_feeds`
   - Schedule: Hourly (set during activation)
   - Processes each feed based on its individual schedule
   - Deactivation clears the scheduled event

3. **Post Metadata** - Duplicate detection, attribution, and RSS metadata:
   - `envirolink_source_url`: Original article URL (used for duplicate detection)
   - `envirolink_source_name`: Feed name
   - `envirolink_original_title`: Original article title
   - `envirolink_last_updated`: MySQL datetime of last update (only for updated posts)
   - `envirolink_author`: Original author from RSS feed (dc:creator) - if enabled
   - `envirolink_pubdate`: Original publication date from RSS - if enabled
   - `envirolink_topic_tags`: Comma-separated topic tags from RSS - if enabled
   - `envirolink_locations`: Geographic locations from RSS - if enabled

4. **Admin Interface** - Tab-based UI:
   - System Status dashboard
   - Settings tab (API key, category, post status)
   - RSS Feeds tab (add/remove/toggle feeds)

### Data Flow

1. **Feed Fetching** (`fetch_and_process_feeds` method):
   - Iterates through enabled feeds
   - Checks if each feed is due for processing via `is_feed_due()` method
   - Calculates next processing time based on schedule_type and schedule_times
   - Uses WordPress `fetch_feed()` to get RSS items
   - Limits to 10 items per feed
   - Updates `last_processed` timestamp after processing

2. **Per-Feed Scheduling** (`is_feed_due` method):
   - Compares current time with last_processed timestamp
   - Calculates interval: period_in_seconds / schedule_times
   - Examples:
     - "2 times per day" = process every 12 hours
     - "3 times per week" = process every 56 hours
     - "1 time per month" = process every ~30 days

3. **Duplicate Detection & Update Logic**:
   - Queries existing posts by `envirolink_source_url` meta key
   - If duplicate found:
     - With `update_existing=no`: Skips the article
     - With `update_existing=yes`: Updates the existing post with new AI content
   - Tracks separately: created vs updated post counts

4. **Image Extraction** (`extract_feed_image` method):
   - Attempts to extract featured image from RSS feed item
   - Checks in order: enclosure thumbnail, enclosure link, content images, description images
   - Downloads image and uploads to WordPress media library
   - Sets as featured image (post thumbnail)

5. **Metadata Extraction** (`extract_feed_metadata` method):
   - Per-feed configurable extraction of RSS metadata
   - Author: Extracts from `dc:creator` namespace
   - Publication Date: Gets from RSS `pubDate` field
   - Topic Tags: Tries custom `topic-tags` field first, falls back to categories
   - Locations: Extracts from custom `locations` field
   - Gracefully handles missing fields (no errors if field doesn't exist)
   - Only extracts fields that are enabled in feed configuration

6. **AI Processing** (`rewrite_with_ai` method):
   - Sends original title + content to Claude API
   - Model: `claude-sonnet-4-20250514`
   - Max tokens: 1024
   - Expects structured response with "TITLE:" and "CONTENT:" markers
   - Parses response using regex

7. **Post Creation/Update**:
   - Creates new WordPress post OR updates existing one
   - Post author hardcoded to user ID 1 (new posts only)
   - Respects configured category and status (new posts only)
   - Stores metadata for tracking (source URL, feed name, original title)
   - Stores extracted RSS metadata (author, pubdate, tags, locations) if enabled
   - Downloads and sets featured image if found in feed
   - All metadata accessible via `get_post_meta()` for theme display

## Development Commands

### Create Plugin ZIP for WordPress Upload
```bash
zip -r ../envirolink-ai-aggregator.zip . -x "*.git*" "*.sh" "*.zip"
```

### Version Control Setup
```bash
git init
git add .
git commit -m "Initial commit: EnviroLink AI News Aggregator v1.0"
```

## Plugin Installation in WordPress

The plugin is designed to be uploaded via WordPress admin:
1. Plugins → Add New Plugin → Upload Plugin
2. Upload the ZIP file
3. Activate
4. Configure in "EnviroLink News" menu

## Important Technical Details

### Anthropic API Integration

- API endpoint: `https://api.anthropic.com/v1/messages`
- Version header: `anthropic-version: 2023-06-01`
- Timeout: 60 seconds
- Response parsing relies on specific format markers in AI output

### WordPress Security

- All admin actions require `manage_options` capability
- Nonces used for all state-changing operations
- URLs sanitized with `esc_url_raw()`
- Text sanitized with `sanitize_text_field()`

### AJAX Implementation

- Single AJAX endpoint: `wp_ajax_envirolink_run_now`
- Triggers immediate feed processing
- Returns JSON with success/message

### Content Processing

- Combines RSS item description and content
- Strips HTML tags from content before sending to AI
- AI rewrites to 200-300 words (2-4 paragraphs)
- Title limited to 80 characters

## Common Modifications

### Changing Cron Frequency
The global cron runs hourly. Individual feed schedules are managed per-feed via the admin UI. To change the global cron frequency, modify the activation hook to use different WordPress cron schedule (e.g., 'twicedaily', 'daily'). Note: Even with less frequent global cron, feeds will only process when their individual schedules are due.

### Adjusting Per-Feed Schedules
Each feed has configurable schedule settings accessible via "Edit Schedule" button in the admin:
- `schedule_type`: hourly, daily, weekly, or monthly
- `schedule_times`: Number of times to process per period (1-24)
- Schedule logic in `is_feed_due()` method calculates intervals dynamically

### Adjusting Articles Per Feed
Change `get_item_quantity(10)` value in `fetch_and_process_feeds` method.

### Modifying AI Prompt
Edit the prompt string in `rewrite_with_ai` method to adjust tone, length, or format.

### Displaying RSS Metadata in Theme
RSS metadata is stored as post meta and can be displayed in WordPress themes:

```php
<?php
// Get metadata
$author = get_post_meta(get_the_ID(), 'envirolink_author', true);
$pubdate = get_post_meta(get_the_ID(), 'envirolink_pubdate', true);
$tags = get_post_meta(get_the_ID(), 'envirolink_topic_tags', true);
$locations = get_post_meta(get_the_ID(), 'envirolink_locations', true);

// Display
if ($author) {
    echo '<p>Original Author: ' . esc_html($author) . '</p>';
}
if ($pubdate) {
    echo '<p>Published: ' . esc_html(date('F j, Y', strtotime($pubdate))) . '</p>';
}
if ($tags) {
    echo '<p>Topics: ' . esc_html($tags) . '</p>';
}
if ($locations) {
    echo '<p>Locations: ' . esc_html($locations) . '</p>';
}
?>
```

### Changing Claude Model
Update the 'model' parameter in the API request body.

## Testing the Plugin

**Manual trigger:** Click "Run Aggregator Now" button in admin dashboard

**Check execution:**
- View "Last Run" timestamp in System Status
- Check WordPress Posts for new entries
- Verify post metadata exists

## Potential Issues

### Cron Not Running
Some WordPress hosts disable WP cron. The plugin requires WordPress cron to be functional for automated updates. Manual trigger always works via AJAX.

### API Response Parsing
The `rewrite_with_ai` method uses regex to parse "TITLE:" and "CONTENT:" from Claude's response. If Claude changes response format, parsing will fail silently and no post will be created.

### Post Author ID
Posts are created with author ID 1 (hardcoded). This assumes user 1 exists in the WordPress installation.

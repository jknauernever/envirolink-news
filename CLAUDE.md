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

## Project Structure

```
envirolink-news/
├── envirolink-ai-aggregator.php    # Main plugin file (monolithic architecture)
├── blocksy-child-functions.php     # Theme functions for metadata display
├── create-plugin.sh                # Build script for plugin ZIP
├── README.md                       # User-facing documentation
├── CLAUDE.md                       # This file - developer guidance
├── DEPLOYMENT.md                   # GitHub Actions deployment setup
└── INSTALLATION-GUIDE.md           # Theme integration guide
```

## Development Commands

### Create Plugin ZIP for WordPress Upload
```bash
zip -r ../envirolink-ai-aggregator.zip . -x "*.git*" "*.sh" "*.zip"
```

Or use the provided script:
```bash
./create-plugin.sh
```

### Testing Locally
The plugin has no automated tests. Manual testing workflow:
1. Make code changes to `envirolink-ai-aggregator.php`
2. Use Git to commit changes
3. GitHub Actions auto-deploys to production (if configured)
4. Test via WordPress admin: EnviroLink News → "Run Aggregator Now"
5. Check WordPress Posts for new entries
6. Review logs in admin panel (toggle "Show Detailed Log")

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
Update the 'model' parameter in the API request body in `rewrite_with_ai` method (line ~2100).

## Recent Version History

**v1.37.0** (2025-11-09) - AI editorial metadata generation for professional roundups
- AI generates professional editorial metadata (headline, dek, image_alt)
- Multi-story hooks instead of single-story focus
- Front-loaded keywords for better SEO and click-through rates
- Date cadence included: "Today's Environmental Briefing — Nov 9, 2025"
- NO source attribution - appears human-written by EnviroLink
- Hybrid approach: AI generates headline/dek, PHP derives SEO fields
- Robust fallback system with admin alerts and logging
- Cost: ~$0.02-0.03 per roundup (minimal)
- Code changes: Lines 4804-4926 (new AI method), Lines 4525-4584 (integration), Lines 271-283 (admin alert)

**v1.36.0** (2025-11-08) - Dynamic roundup titles with top story preview
- Changed from generic template to engaging content preview format
- Title now highlights top story: "Environmental News Roundup: [Top Article Title]"
- Description shows first sentence of top article + article count
- Added clear CTA: "Read the full roundup →"
- Much more clickable and human-readable while maintaining SEO value
- Code changes: Lines 4524-4544

**v1.35.0** (2025-11-08) - Add automatic alt text to all images for SEO/accessibility
- Added automatic alt text generation for all featured images
- RSS feed images get alt text based on post title (improves SEO and accessibility)
- Unsplash images get descriptive environmental alt text
- Fixes AIOSEO warning: "Some images on the page have no alt attribute"
- All future images automatically have proper alt text for search engines
- Code changes: Lines 4282-4286 (RSS images), Line 4965 (Unsplash images)

**v1.12.2** (2025-11-01) - Fix "Check for Updates" button error
- Fixed "Update checker not initialized" error when clicking Check for Updates button
- Changed update checker from local variable to global variable (`$envirolink_update_checker`)
- AJAX handler now properly accesses the update checker instance
- Manual update check now works correctly

**v1.12.1** (2025-11-01) - CRITICAL FIX: Plugin Update Checker loading error
- Fixed fatal error: `Failed opening required 'plugin-update-checker/plugin-update-checker.php'`
- Corrected ZIP file structure: plugin-update-checker now properly included as subdirectory
- Issue was packaging bug in v1.12.0, not code issue
- Plugin now activates and loads correctly

**v1.12.0** (2025-10-31) - Add manual "Check for Updates" button (BROKEN - packaging issue)
- New "Plugin Updates" row in System Status with "Check for Updates" button
- Manually triggers Plugin Update Checker to check GitHub for new releases
- Displays update status: "Up to date" or "Update available" with version info
- Provides "Go to Updates" button if update available
- Complements automatic update checks with on-demand functionality
- ISSUE: ZIP structure was incorrect, caused fatal error on activation (fixed in v1.12.1)

**v1.11.1** (2025-10-29) - CRITICAL FIX: Prevent frontend crashes from randomize_daily_order
- v1.11.0 filter was too broad and caused site crashes
- `is_sitemap()` doesn't exist in WordPress < 5.5 - added function_exists() checks
- Weak post_type checking affected pages, custom post types, widgets
- Now explicitly whitelists query types: home, archive, category, tag, author, date only
- Added safety checks for $query, $wpdb existence
- Much more defensive filtering prevents crashes

**v1.11.0** (2025-10-29) - Add "Randomize Daily Order" setting (BROKEN - caused crashes)
- New checkbox in Settings: "Randomize order of posts within the same day"
- Prevents posts from same day being grouped by source
- Fixed in v1.11.1

**v1.10.2** (2025-10-29) - Fix BBC image enhancement to support all URL patterns
- v1.10.1 only handled 2 of 4 BBC URL patterns, causing "couldn't parse width pattern" errors
- Added support for `/ace/standard/WIDTH/` pattern
- Added support for `/images/ic/WIDTHxHEIGHT/` pattern (maintains aspect ratio)
- All 4 BBC CDN patterns now working: `/news/`, `/ace/standard/`, `/ace/ws/`, `/images/ic/`

**v1.10.1** (2025-10-29) - Add BBC image quality enhancement (INCOMPLETE)
- Initial BBC image enhancement added but only handled 2 of 4 URL patterns
- Fixed in v1.10.2

**v1.10.0** (2025-10-29) - Add "Fix Post Order" button for date synchronization
- Orange button in admin panel syncs all post dates to match RSS publication dates
- Fixes homepage ordering issues where posts appeared out of chronological order
- Real-time progress tracking with detailed logging
- Compares post_date with stored envirolink_pubdate metadata
- Only updates posts with mismatched dates

**v1.9.4** (2025-10-29) - Fix Guardian signed URL authentication errors
- When RSS thumbnails have signatures + small width, return null to trigger article scraping
- Article scraping gets 1200px Open Graph images (pre-signed, authenticated)
- Prevents "Unauthorized" errors from modifying signed URLs

**v1.9.3** (2025-10-29) - Fix HTTP/HTTPS mismatch in WordPress image detection
- v1.9.2 fix failed due to protocol differences (http vs https)
- Changed to protocol-agnostic hostname comparison using `parse_url()`
- Now correctly identifies WordPress-hosted images regardless of protocol

**v1.9.2** (2025-10-29) - CRITICAL FIX: Stop re-downloading WordPress thumbnails (BROKEN)
- Attempted to fix bug where image updater would re-download from own server
- Bug: Used string matching with full URLs, failed on http/https mismatch
- Fixed in v1.9.3

**v1.9.1** - Fix log overwrite bug
- Purple button log now persists properly after completion

**v1.9.0** - Fix blurry Guardian images
- Enhanced image quality detection and URL parameter upgrading
- Detects and upgrades low-res thumbnails to high-res versions

## Image Processing Architecture

The plugin implements sophisticated image extraction with multiple fallback strategies:

### Image Sources (Priority Order)
1. **RSS Media Tags**: `media:content`, `media:thumbnail` (Yahoo Media RSS namespace)
2. **Enclosures**: RSS enclosure thumbnail/link
3. **Content Parsing**: Extract `<img>` tags from RSS content/description
4. **Web Scraping**: Fetch article page and extract Open Graph/Twitter Card images

### Quality Enhancement
- **Guardian Images**: Detects Guardian CDN URLs (`i.guim.co.uk`) and handles authentication
  - **Signed + Good Width (≥500px)**: Preserve as-is (authenticated, already good quality)
  - **Signed + Small Width (<500px)**: Return null to trigger article scraping fallback (v1.9.4)
    - Article scraping retrieves 1200px Open Graph images (pre-signed, authenticated)
    - Avoids "Unauthorized" errors from modifying signed URLs
  - **Unsigned + Small Width**: Safe to enhance, extracts master dimensions and upgrades to 1920px
  - **Unsigned + Any Width**: Safe to enhance quality parameters
- **BBC Images**: Detects BBC CDN URLs (`ichef.bbci.co.uk`) and upgrades width in path (v1.10.2)
  - **Four URL patterns supported:**
    1. `/news/WIDTH/` → `/news/1024/`
    2. `/ace/standard/WIDTH/` → `/ace/standard/1024/`
    3. `/ace/ws/WIDTH/` → `/ace/ws/1024/`
    4. `/images/ic/WIDTHxHEIGHT/` → `/images/ic/1024x[calculated]/` (maintains aspect ratio)
  - Replaces any width < 1024 with 1024 (BBC's maximum resolution)
  - Simpler than Guardian - no authentication, width is in URL path not query params
- **Generic URLs**: Attempts to upgrade width/quality query parameters
- See `enhance_image_quality()` method (line ~2005)

### Update Images Feature (Purple Button)
- Re-downloads all images for a specific feed's posts
- Does NOT run AI or change content
- Three strategies:
  1. Enhance existing CDN URLs
  2. Fetch from RSS feed (most reliable)
  3. Scrape article page
- See `update_feed_images()` method (line ~1140) and `ajax_update_feed_images()` (line ~994)

## Progress Tracking & Logging

### Real-time Progress Display
- JavaScript polls `envirolink_get_progress` AJAX endpoint every 500ms
- Shows progress bar, percentage, current/total articles
- Displays detailed log messages with timestamps
- See JavaScript in admin page (lines ~654-912)

### Logging System
- `log_message()`: Adds timestamped entries to progress transient
- `update_progress()`: Updates progress bar state
- `clear_progress()`: Saves log to wp_options for persistence
- Logs preserved after completion for review (purple button log fix in v1.9.1)
- Transient expires after 5 minutes (300 seconds)

## Testing the Plugin

**Manual trigger:** Click "Run Aggregator Now" button in admin dashboard

**Check execution:**
- View "Last Run" timestamp in System Status
- Check WordPress Posts for new entries
- Verify post metadata exists

## Theme Integration (Blocksy Child Theme)

The `blocksy-child-functions.php` file contains WordPress hooks for displaying metadata in the Blocksy theme. This is separate from the plugin and must be manually added to the child theme's functions.php.

### Metadata Display Locations
1. **Listing Pages** (homepage, archives): Compact format showing source, author, date, first tag
   - Hook: `blocksy:loop:card:end` or `blocksy:posts-loop:after:excerpt`
2. **Single Posts**: Full attribution box with all metadata and "Read Original" link
   - Hook: `blocksy:single:content:bottom`

### Available Post Metadata Fields
- `envirolink_source_url`: Original article URL
- `envirolink_source_name`: Feed name
- `envirolink_original_title`: Original article title
- `envirolink_last_updated`: MySQL datetime (for updated posts)
- `envirolink_author`: Original author from RSS
- `envirolink_pubdate`: Original publication date
- `envirolink_topic_tags`: Comma-separated tags (also converted to WP tags)
- `envirolink_locations`: Geographic locations
- `envirolink_content_hash`: MD5 hash for change detection

### Styling
CSS for metadata display should be added via Appearance → Customize → Additional CSS. See `INSTALLATION-GUIDE.md` for details.

## Deployment

### GitHub Actions Auto-Deployment
- Repository has GitHub Actions workflow for SFTP deployment
- On push to `main` branch, uploads plugin files to WordPress server
- Requires secrets: `SFTP_HOST`, `SFTP_USERNAME`, `SFTP_PASSWORD`, `SFTP_PORT`, `SFTP_PATH`
- See `DEPLOYMENT.md` for setup instructions
- Excludes: Git files, shell scripts, ZIP files

### Manual Deployment
1. Create ZIP: `zip -r envirolink-ai-aggregator.zip . -x "*.git*" "*.sh" "*.zip"`
2. Upload via WordPress admin: Plugins → Add New → Upload Plugin
3. Or SFTP directly to `/wp-content/plugins/envirolink-ai-aggregator/`

## Potential Issues

### Cron Not Running
Some WordPress hosts disable WP cron. The plugin requires WordPress cron to be functional for automated updates. Manual trigger always works via AJAX.

### API Response Parsing
The `rewrite_with_ai` method uses regex to parse "TITLE:" and "CONTENT:" from Claude's response. If Claude changes response format, parsing will fail silently and no post will be created.

### Post Author ID
Posts are created with author ID 1 (hardcoded). This assumes user 1 exists in the WordPress installation.

### Image Download Failures
- Guardian images with signatures must preserve query parameters
- WordPress media library requires valid file extensions
- Image URLs are parsed to remove query strings for clean filenames (line ~2029)
- Failed downloads are logged but don't block post creation

### Memory/Performance
- Processing 50+ articles per feed can hit PHP memory limits
- Each AI rewrite makes an API call (no batching)
- Image downloads are synchronous
- Consider reducing `get_item_quantity(10)` if timeout issues occur

### Duplicate Detection Edge Cases
- Relies on exact URL match via `envirolink_source_url` meta
- URL changes (http→https, trailing slash) may create duplicates
- Hash-based change detection requires `envirolink_content_hash` field
- "Update existing" mode compares content hash to avoid unnecessary AI calls

### Post Ordering Issues

**Incorrect Dates:**
- WordPress orders posts by `post_date` by default
- Plugin sets `post_date` from RSS `pubDate` field when creating new posts
- If RSS doesn't provide pubdate, or older posts were created before this feature, posts may have incorrect dates
- **Solution**: Use the orange "Fix Post Order" button in admin to sync all post dates
- The fix_post_dates() method compares WordPress post_date with stored envirolink_pubdate metadata
- Only updates posts where dates don't match (skips already-correct posts)

**Source Clustering (v1.11.0):**
- Posts from the same feed processed together have similar timestamps
- This causes clustering by source (all Guardian posts together, then all Mongabay, etc.)
- **Solution**: Enable "Randomize Daily Order" checkbox in Settings
- Modifies WordPress query to: `ORDER BY DATE(post_date) DESC, RAND()`
- Keeps newest days first, but randomizes posts within each day
- Does not modify timestamps in database
- Only affects frontend display (not admin, RSS feeds, sitemaps)
- Implemented via `posts_orderby` filter in randomize_daily_order() method (line ~127)

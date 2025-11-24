# EnviroLink AI News Aggregator

Automated WordPress plugin that fetches environmental news from RSS feeds, rewrites content using Anthropic's Claude AI, and publishes to your WordPress site.

## Features

✅ **RSS Feed Management** - Add, remove, enable/disable news sources through admin interface
✅ **AI-Powered Rewriting** - Uses Claude to create unique, engaging summaries
✅ **Professional Ontology Tagging** - IPTC Media Topics + UN SDG-based taxonomy
✅ **Automatic Publishing** - Creates WordPress posts automatically
✅ **Hourly Updates** - Runs automatically via WordPress cron
✅ **Duplicate Detection** - Prevents re-importing the same articles
✅ **Daily Roundups** - AI-generated editorial summaries with Pexels imagery
✅ **Manual Trigger** - Run the aggregator on-demand from admin panel
✅ **Source Attribution** - Stores original source URL and title as post metadata  

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- Anthropic API key ([Get one here](https://console.anthropic.com/))
- Active internet connection for API calls

## Installation

### Step 1: Prepare the Plugin

**Create a ZIP file for WordPress upload:**

```bash
cd envirolink-ai-aggregator
zip -r ../envirolink-ai-aggregator.zip .
```

### Step 2: Install in WordPress

**Upload via WordPress Dashboard:**

1. Log into your WordPress admin panel at `https://envirolink.org/wp-admin`
2. Go to **Plugins → Add New Plugin**
3. Click **Upload Plugin** button at the top
4. Click **Choose File** and select `envirolink-ai-aggregator.zip`
5. Click **Install Now**
6. Click **Activate Plugin**

### Step 3: Configure the Plugin

1. In WordPress admin, find **EnviroLink News** in the left sidebar
2. Click it to open the settings page

#### Enter Your API Key

1. Go to the **Settings** tab
2. Paste your Anthropic API key into the "Anthropic API Key" field
3. Select a default category for posts (optional)
4. Choose post status (**Publish** for automatic posting or **Draft** to review first)
5. Click **Save Settings**

#### Manage RSS Feeds

1. Go to the **RSS Feeds** tab
2. The Mongabay feed is pre-configured
3. To add more feeds:
   - Enter a **Feed Name** (e.g., "The Guardian")
   - Enter the **RSS Feed URL** (e.g., `https://www.theguardian.com/environment/rss`)
   - Click **Add Feed**

**Suggested Environmental RSS Feeds:**
- Mongabay: `https://news.mongabay.com/feed/` (pre-configured)
- The Guardian Environment: `https://www.theguardian.com/environment/rss`
- Grist: `https://grist.org/feed/`
- EcoWatch: `https://www.ecowatch.com/feed`
- Yale Environment 360: `https://e360.yale.edu/feed`
- Carbon Brief: `https://www.carbonbrief.org/feed/`

### Step 4: Test It!

1. Return to the main **EnviroLink News** page
2. Verify the **System Status** shows:
   - ✓ API Key: Configured
   - Active Feeds: 1 or more
3. Click **Run Aggregator Now** button
4. Wait 30-60 seconds for processing
5. Go to **Posts** in WordPress to see your new articles!

## How It Works

### Automatic Hourly Process

Every hour, the plugin automatically:

1. **Fetches** up to 10 recent articles from each enabled RSS feed
2. **Checks** if each article has already been imported (prevents duplicates)
3. **Sends** the original content to Claude AI
4. **Receives** a rewritten headline and summary
5. **Creates** a new WordPress post
6. **Stores** the original source URL as metadata

### AI Rewriting

Claude AI is prompted to:
- Create a compelling new headline (max 80 characters)
- Write a 200-300 word summary (2-4 paragraphs)
- Maintain all factual accuracy
- Use clear, engaging language for general audiences
- Preserve journalistic integrity

## Environmental News Ontology

### What Is It?

The plugin includes a professional taxonomy system based on:
- **IPTC Media Topics** - International news industry standards
- **UN Sustainable Development Goals** - 17 global goals for environmental impact

This ensures your articles have clean, consistent, professional tags instead of junk tags like "Homepage", "Breaking News", or "World".

### The 41-Topic Taxonomy

**Level 0:** Environment

**Level 1 (Main Categories):**
- Climate Change
- Conservation
- Environmental Pollution
- Natural Resources
- Renewable Energy
- Sustainability
- Environmental Justice
- Natural Disasters

**Level 2 (Subcategories):**
- Climate: Carbon Emissions, Climate Adaptation, Climate Mitigation, Sea Level Rise
- Conservation: Endangered Species, Wildlife Protection, Biodiversity, Habitat Protection, Deforestation
- Pollution: Air, Water, Soil, Noise, Light, Plastic
- Resources: Water, Forest, Mineral, Ocean
- Renewables: Solar, Wind, Hydroelectric, Geothermal, Biomass
- Plus: Circular Economy, Green Technology, Environmental Policy, Ecosystem Services
- Disasters: Drought, Flood, Wildfire, Hurricane

### Setup (One-Time)

1. Go to **EnviroLink News → Ontology** tab
2. Click **"Seed Ontology Database"** button
3. Wait for confirmation: "✓ Seeded (41 topics)"
4. Go to **Settings** tab
5. Check **"Enable ontology-based tag filtering"**
6. Click **Save Settings**

### Re-tag Existing Posts

After enabling ontology:

1. Go to **Ontology** tab
2. Click **"Re-tag All Existing Posts"** button
3. Wait for completion: "Updated X articles, Y roundups, skipped Z"

This cleans up ALL past articles and roundups with filtered tags.

### How It Works

**For Articles:**
- RSS feeds provide tags (e.g., "climate change, world news, homepage, breaking")
- Ontology filters them (keeps: "Climate Change", removes: "world news, homepage, breaking")
- Only ontology-matched tags are applied

**For Roundups:**
- Automatically inherits tags from the 30 articles included
- Shows diverse topic coverage

**Matching Logic:**
1. **Exact match** - "Climate Change" → Climate Change ✓
2. **Alias match** - "global warming" → Climate Change ✓ (via alias)
3. **Fuzzy match** - "renewable" → Renewable Energy ✓ (partial match)

### Benefits

✅ No more junk tags ("World News", "Homepage", "Featured")
✅ Consistent terminology across all feeds
✅ UN SDG-aligned for impact reporting
✅ Better SEO with focused keywords
✅ Professional appearance
✅ Tag archives actually work (no clutter)

## Managing Your News Aggregator

### System Status Dashboard

The main page shows:
- **Last Run**: When the aggregator last processed feeds
- **Next Scheduled Run**: When it will run next (hourly)
- **API Key Status**: Whether your key is configured
- **Active Feeds**: How many feeds are enabled

### Feed Management

**Enable/Disable Feeds:**
- Click **Disable** to temporarily stop importing from a feed
- Click **Enable** to reactivate it

**Delete Feeds:**
- Click **Delete** and confirm
- This won't delete posts already created

### Manual Processing

Click **Run Aggregator Now** to:
- Process feeds immediately (don't wait for hourly cron)
- Test your configuration
- Fetch latest articles after adding new feeds

## Configuration Options

### Post Settings

**Default Category:**
- Assigns all imported posts to a specific category
- Create categories first in **Posts → Categories**

**Post Status:**
- **Publish**: Posts go live immediately
- **Draft**: Posts are saved as drafts for you to review

### Customizing the Cron Schedule

Default: Runs every hour

To change frequency, you'll need to modify WordPress cron. Example for every 30 minutes - add to your theme's `functions.php`:

```php
add_filter('cron_schedules', function($schedules) {
    $schedules['thirty_minutes'] = array(
        'interval' => 1800,
        'display' => 'Every 30 Minutes'
    );
    return $schedules;
});
```

Then you'd need to modify the plugin's activation hook to use `'thirty_minutes'` instead of `'hourly'`.

## Displaying Posts on Your Homepage

The plugin creates regular WordPress posts. To display them on your homepage:

### Option 1: Use Your Theme

Most WordPress themes automatically show recent posts on the homepage. Check:
- **Settings → Reading** 
- Make sure "Your homepage displays" is set to "Your latest posts"

### Option 2: Use a Category

1. Create a category called "News" (or similar)
2. Set it as the default category in plugin settings
3. Use your theme's category template to display these posts

### Option 3: Use a Custom Query

Add to your theme template:

```php
<?php
$args = array(
    'posts_per_page' => 10,
    'meta_key' => 'envirolink_source_url',
    'meta_compare' => 'EXISTS'
);
$query = new WP_Query($args);
if ($query->have_posts()) :
    while ($query->have_posts()) : $query->the_post();
        // Display post
        the_title();
        the_excerpt();
    endwhile;
endif;
wp_reset_postdata();
?>
```

## Troubleshooting

### No posts are being created

**Check these:**

1. **API Key**: Make sure it's entered correctly in Settings (starts with "sk-ant-")
2. **Feeds Enabled**: At least one feed must have a green dot (enabled)
3. **Feed URLs**: Make sure they're valid RSS feeds
4. **WordPress Cron**: Some hosts disable WP cron. Check with your hosting provider
5. **Errors**: Check WordPress debug logs or PHP error logs

### Posts aren't appearing on homepage

**Solutions:**

1. Go to **Settings → Reading** and check homepage settings
2. Make sure your theme is configured to show posts
3. Check if posts are in Draft status (change to Publish in plugin settings)

### "API key not configured" error

- Double-check you've saved the API key in the Settings tab
- The key should start with `sk-ant-`
- Try copying and pasting it again

### Duplicate articles

This shouldn't happen! The plugin stores each article's source URL and checks before importing. If you see duplicates:
- Each post has metadata `envirolink_source_url` 
- The plugin compares this before creating new posts

### Need more help?

1. Check WordPress and PHP error logs
2. Click "Run Aggregator Now" and watch for error messages
3. Verify your API key has credits at console.anthropic.com

## Costs

### API Usage

Using Claude Sonnet 4:
- ~$3 per million input tokens
- ~$15 per million output tokens

### Estimated Monthly Costs

**Conservative estimate** (5 active feeds, hourly updates):
- ~10 articles/feed/hour = 50 articles/hour
- ~1,200 articles/day
- ~36,000 articles/month
- ~2,000 tokens per article
- **~$50-150/month**

**Cost saving tips:**
- Use fewer feeds
- Reduce cron frequency to every 3-6 hours
- Set lower article limits per feed

## Security

- API keys stored securely in WordPress database
- All URLs sanitized before storage
- WordPress nonces protect all admin actions
- Only administrators can access settings
- No external dependencies except Anthropic API

## Development & Version Control

### Initialize Git Repository

```bash
cd envirolink-ai-aggregator
git init
git add .
git commit -m "Initial commit: EnviroLink AI News Aggregator v1.0"
```

### File Structure

```
envirolink-ai-aggregator/
├── envirolink-ai-aggregator.php  # Main plugin (all functionality)
├── README.md                      # This documentation
└── .gitignore                     # Git ignore file
```

### Future Enhancements

Potential features to add:
- Image extraction from original articles
- Multi-language support
- Custom post types for aggregated content
- Advanced filtering and tagging
- Email notifications for new posts
- Analytics and reporting

## Credits

- **Built for**: EnviroLink.org
- **Powered by**: Anthropic Claude AI
- **License**: GPL v2 or later

## Version History

**v1.47.0** - Ontology Tagging for Roundups (2025-11-23)
- Daily roundups now inherit ontology tags from included articles
- Re-tag tool processes both articles and roundups
- Separate counts in results: "X articles, Y roundups"

**v1.46.0** - Environmental News Ontology System (2025-11-23)
- 41-topic professional taxonomy (IPTC Media Topics + UN SDGs)
- Intelligent tag filtering (exact → alias → fuzzy matching)
- New Ontology admin tab with full CRUD interface
- Automatic integration with article tagging
- 200+ aliases for smart matching
- Bulk re-tagging tool for existing posts

**v1.45.0** - Keyword Daily Limiting (2025-11-23)
- Prevents redundant coverage of same events
- Configurable limit per topic per day (default: 2 articles)
- Calendar-day based counter (resets at midnight)
- Smart keyword extraction from article titles

**v1.43.0** - Per-Feed Pexels Integration (2025-11-13)
- Per-feed option to use Pexels instead of RSS images
- Intelligent keyword extraction for image search
- Daily roundups switched to Pexels
- Pexels API key configuration in Settings

**v1.42.0** - Enterprise PID-Based Locking (2025-11-13)
- Eliminates duplicate posts via process liveness check
- Heartbeat mechanism for long-running imports
- Atomic metadata storage
- Extended timeout handling

**v1.38.0** - Scheduled Feed Processing (2025-11-09)
- 7am ET and 4pm ET processing windows
- Manual triggers bypass time restrictions
- Better resource management

**v1.37.0** - AI Editorial Metadata Generation (2025-11-09)
- Professional headlines and descriptions for roundups
- Multi-story hooks for better engagement
- SEO-optimized titles and meta descriptions
- Date cadence included in titles

**v1.0.0** - Initial Release
- RSS feed management
- AI-powered content rewriting
- Hourly automated updates
- Duplicate detection
- Admin dashboard with manual triggers

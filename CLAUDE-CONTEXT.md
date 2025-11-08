# CLAUDE CONTEXT - Quick Start Guide for Future Sessions

**Last Updated:** 2025-11-08 (v1.35.0)
**Purpose:** This file helps future Claude Code instances quickly understand the project status, deployment workflows, and recent changes.

---

## 🚀 QUICK PROJECT OVERVIEW

**Project:** EnviroLink AI News Aggregator - WordPress Plugin + Child Theme
**Location:** `/Users/jknauer/Projects/envirolink-news`
**Live Site:** https://envirolink.org
**GitHub:** https://github.com/jknauernever/envirolink-news

**What it does:**
- Automatically fetches environmental news from RSS feeds
- Rewrites articles using Anthropic's Claude AI
- Publishes to WordPress with metadata and featured images
- Generates daily AI editorial roundup posts at 8am ET

---

## 📁 PROJECT STRUCTURE

```
envirolink-news/
├── envirolink-ai-aggregator.php     # Main plugin file (monolithic)
├── create-plugin.sh                 # Build script for plugin ZIP
│
├── blocksy-child-functions.php      # ACTIVE theme functions
├── blocksy-child-styles.css         # ACTIVE theme styles
├── front-page.php                   # ACTIVE homepage template
│
├── CLAUDE.md                        # Developer documentation (read this!)
├── CLAUDE-CONTEXT.md                # This file - session context
├── README.md                        # User documentation
├── DEPLOYMENT.md                    # GitHub Actions setup
├── INSTALLATION-GUIDE.md            # Theme integration guide
└── HOMEPAGE-INSTALLATION.md         # Homepage setup guide
```

**Important Files to Know:**
- **CLAUDE.md** - Detailed technical documentation (READ THIS FIRST for deep dives)
- **This file** - Quick context for resuming work
- **envirolink-ai-aggregator.php** - All plugin logic (single 4600+ line file)
- **blocksy-child-functions.php** - Theme customizations (metadata display, hooks)
- **front-page.php** - Custom homepage layout (daily roundup + news grid)

**Theme:** Using Blocksy child theme with standalone files (blocksy-child-*) deployed directly to server at `/wp-content/themes/blocksy-child/`

---

## 🔄 DEPLOYMENT & UPDATE WORKFLOW

### **Plugin Updates (Critical to Understand)**

The plugin uses **GitHub Releases** for automatic updates:

1. **Make code changes** to `envirolink-ai-aggregator.php`
2. **Bump version** in two places:
   - Line 6: `* Version: X.Y.Z`
   - Line 17: `define('ENVIROLINK_VERSION', 'X.Y.Z');`
3. **Commit changes** to Git
4. **Push to GitHub:** `git push origin main`
5. **Create GitHub Release:** `gh release create vX.Y.Z --title "..." --notes "..."`
   - The plugin checks GitHub for new releases via Plugin Update Checker library
   - Users see updates in WordPress admin → Plugins
   - They click "Update Now" to get the latest version

**Testing Updates:**
- Go to WordPress admin → EnviroLink News → System Status
- Click "Check for Updates" button
- Should show latest GitHub release version

### **GitHub Actions Auto-Deployment**

On push to `main` branch, GitHub Actions automatically:
- Uploads plugin files to production server via SFTP
- Excludes: Git files, shell scripts, ZIP files
- Location: `/wp-content/plugins/envirolink-ai-aggregator/`
- See: `.github/workflows/deploy.yml`

**Secrets Required:**
- `SFTP_HOST`, `SFTP_USERNAME`, `SFTP_PASSWORD`, `SFTP_PORT`, `SFTP_PATH`

---

## ⚙️ CURRENT PRODUCTION CONFIGURATION

### **WordPress Environment**
- **WP-Cron:** DISABLED (`DISABLE_WP_CRON = true`)
- **Using:** System cron instead of WordPress cron
- **Hosting:** (unknown - likely managed WordPress)

### **System Cron Jobs (IMPORTANT!)**

**Current active cron:**
```bash
0 * * * * curl -s https://envirolink.org/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

**What this does:**
- Runs WordPress's internal task scheduler hourly
- Executes all scheduled WordPress tasks, including:
  - Feed aggregation (hourly via `envirolink_fetch_feeds` hook)
  - Daily roundup generation (8am ET via `envirolink_daily_roundup` hook)
  - Other WordPress scheduled tasks

**⚠️ IMPORTANT - Cron Configuration Rule:**
- Use **EITHER** wp-cron.php (above) **OR** the custom AJAX endpoint
- **NEVER use both** simultaneously (causes duplicate roundup posts)
- The custom AJAX endpoint (`ajax_cron_roundup`) was added as an *alternative*, not a supplement

**Custom AJAX Endpoint (currently NOT in use):**
```bash
# This is NOT active - keep it that way!
# 0 8 * * * curl -s "https://www.envirolink.org/wp-admin/admin-ajax.php?action=envirolink_cron_roundup&key=SECRET"
```

---

## 🐛 RECENT CRITICAL FIXES

### **v1.33.0 (Nov 8, 2025) - Duplicate Roundup Fix**

**Problem:** Multiple daily roundup posts created on same day

**Root Causes:**
1. Title character mismatch (en-dash vs hyphen in duplicate detection)
2. Dual cron jobs running simultaneously (now fixed via cron cleanup)
3. Race condition window between post creation and meta flag

**Solutions Implemented:**
1. Fixed title character on line 4335 (now uses hyphen like line 4457)
2. Added transient-based mutex lock (`envirolink_roundup_generation_lock`)
   - Prevents simultaneous executions
   - 5-minute auto-expire
   - Cleared on all success/error paths
3. Moved `envirolink_is_roundup` meta to immediately after `wp_insert_post()`
4. Added warning in admin UI about dual cron usage

**Protection Layers:**
- Title-based duplicate detection (working correctly now)
- Meta-based duplicate detection (timing improved)
- Transient lock (prevents concurrent execution)
- User education (admin warning)

### **v1.32.0 (Nov 6, 2025) - System Cron Support**

Added custom AJAX endpoint for hosts with WP-CRON disabled:
- Endpoint: `admin-ajax.php?action=envirolink_cron_roundup&key=SECRET`
- Secured with secret key
- Alternative to WordPress cron (not supplement!)
- See lines 2024-2086

---

## 🎯 COMMON TASKS

### **Running Tests**
No automated tests. Manual testing:
1. Make changes to `envirolink-ai-aggregator.php`
2. Click "Run Aggregator Now" in admin
3. Check WordPress Posts for new entries
4. Verify metadata in WordPress admin

### **Generating Roundup Manually**
1. WordPress admin → EnviroLink News
2. Click purple "Generate Roundup Now" button
3. Watch progress bar and log
4. Verify post created with featured image

### **Checking Feed Processing**
1. System Status shows: Last Run timestamp
2. Each feed has individual schedule (hourly/daily/weekly/monthly)
3. Feeds only process when due based on `last_processed` timestamp

### **Creating Plugin ZIP**
```bash
./create-plugin.sh
# or
zip -r ../envirolink-ai-aggregator.zip . -x "*.git*" "*.sh" "*.zip"
```

### **Theme Deployment**

**Active Theme:** Blocksy child theme (blocksy-child)

**Theme Files:**
- `blocksy-child-functions.php` - WordPress hooks, metadata display
- `blocksy-child-styles.css` - Theme styling
- `front-page.php` - Custom homepage template

**Deployment:**
Theme files are deployed manually to `/wp-content/themes/blocksy-child/` on the server. Updates are not automated like the plugin - changes must be uploaded manually via SFTP or WordPress admin.

---

## 🔑 KEY TECHNICAL DETAILS

### **Architecture**
- **Single-file plugin:** All logic in `envirolink-ai-aggregator.php` (4600+ lines)
- **Singleton pattern:** Main class `EnviroLink_AI_Aggregator`
- **WordPress Options API:** All settings stored in `wp_options` table
- **Custom post meta:** Duplicate detection, attribution, RSS metadata

### **Data Flow**
1. System cron triggers wp-cron.php hourly
2. WordPress checks scheduled tasks
3. Feed processing: `fetch_and_process_feeds()` checks each feed's schedule
4. Per-feed scheduling: Based on `schedule_type` and `schedule_times`
5. Article processing: Fetch → AI rewrite → Image download → Post creation
6. Duplicate detection: Check `envirolink_source_url` meta
7. Update or skip based on `update_existing` setting

### **Daily Roundup Flow**
1. Triggered at 8am ET via `envirolink_daily_roundup` WordPress cron hook
2. Checks for existing roundup (title + meta)
3. Checks for generation lock (transient)
4. Gathers last 30 articles from WordPress
5. Generates AI editorial content
6. Creates post with "Featured" category
7. Downloads featured image (Unsplash or manual collection)
8. Clears lock on completion/error

### **Image Processing**
- Multiple fallback strategies (RSS → enclosures → content parsing → web scraping)
- Quality enhancement for Guardian & BBC images
- Guardian: Handles authenticated signed URLs (v1.9.4)
- BBC: Upgrades width in URL path (v1.10.2)
- Purple "Update Images" button: Re-downloads without running AI

### **Progress Tracking**
- Real-time via AJAX polling (500ms intervals)
- Transient storage: `envirolink_progress_OPERATION`
- Logs persist after completion for review
- JavaScript updates progress bar + detailed log display

---

## ⚠️ KNOWN ISSUES & GOTCHAS

### **WordPress CRON**
- **Required:** Host must have EITHER WP-CRON enabled OR system cron configured
- Check System Status dashboard for current state
- If disabled, wp-cron.php system cron MUST be configured

### **Duplicate Prevention**
- Now has 4 layers of protection (v1.33.0)
- Manual runs bypass duplicate checks and locks
- Automatic runs enforce all protections

### **Image Downloads**
- Guardian signed URLs must preserve query parameters
- Images without extensions get `.jpg` appended
- Failed downloads don't block post creation
- WordPress media library requires valid extensions

### **Post Ordering**
- "Fix Post Order" button syncs WordPress dates to RSS pubdates
- "Randomize Daily Order" prevents source clustering (v1.11.0)
- Only affects frontend display, not admin/RSS/sitemaps

### **API Limits**
- Claude API: No batching (one call per article)
- Unsplash API: Requires approved access key
- Processing 50+ articles can hit PHP memory limits

### **Character Encoding**
- CRITICAL: Title must use hyphen (-), not en-dash (–)
- This was the root cause of v1.33.0 bug
- Always use ASCII hyphen in title strings

---

## 📊 VERSION HISTORY (Recent)

| Version | Date | Description |
|---------|------|-------------|
| v1.35.0 | 2025-11-08 | Add automatic alt text to all images for SEO/accessibility |
| v1.34.0 | 2025-11-08 | SEO optimization: optimized titles, meta descriptions, schema markup for all content |
| v1.33.0 | 2025-11-08 | **CRITICAL:** Fix duplicate roundup posts (4 protection layers) |
| v1.32.0 | 2025-11-06 | Add system cron support for hosts with WP-CRON disabled |
| v1.31.2 | 2025-11-06 | Find existing EnviroLink Editor user |
| v1.31.1 | 2025-11-06 | Fix Unsplash image uploads (missing file extension) |
| v1.31.0 | 2025-11-06 | **CRITICAL:** Remove feed aggregator from roundup generation |
| v1.30.0 | 2025-11-06 | Fix Unsplash images by downloading instead of hotlinking |

See git log for full history.

---

## 🧪 DEBUGGING TIPS

### **Check Logs**
```bash
# WordPress debug log
tail -f /wp-content/debug.log

# Look for EnviroLink entries
grep "EnviroLink:" /wp-content/debug.log
```

### **Progress Tracking Issues**
- Check transient: `envirolink_progress_*`
- JavaScript polls every 500ms
- Auto-expires after 5 minutes
- Logs saved to wp_options on completion

### **Cron Not Running**
1. Check System Status → WordPress Cron status
2. Verify system cron is configured: `crontab -l`
3. Check error logs for failures
4. Test manual trigger: "Run Aggregator Now" button

### **Duplicate Posts**
1. Check for dual cron jobs (should only have ONE)
2. Verify v1.33.0 or later installed
3. Check error_log for lock messages
4. Look for transient: `envirolink_roundup_generation_lock`

### **Images Not Showing**
1. Check if URL has authentication (Guardian)
2. Verify image downloaded to media library
3. Check for file extension in filename
4. Test "Update Images" feature (purple button)

---

## 💡 TIPS FOR FUTURE CLAUDE SESSIONS

### **Before Making Changes**
1. Read CLAUDE.md for detailed architecture
2. Check this file for recent changes/fixes
3. Look at git log: `git log --oneline -20`
4. Review System Status in WordPress admin

### **When Adding Features**
1. Update version in TWO places (header + constant)
2. Add to CLAUDE.md changelog section
3. Test manually before committing
4. Create GitHub release for auto-update to work

### **When Debugging**
1. Use progress tracking system (don't add separate logging)
2. Check error_log for "EnviroLink:" entries
3. Test with "Run Aggregator Now" first
4. Verify on production carefully

### **Common User Requests**
- **"Roundups not generating"** → Check cron configuration
- **"Duplicate posts"** → Verify single cron job, check v1.33.0+
- **"Images broken"** → Use "Update Images" button, check CDN URLs
- **"Wrong post dates"** → Use "Fix Post Order" button
- **"Plugin update not showing"** → Create GitHub release

---

## 🚨 EMERGENCY CONTACTS

**If things break:**
1. Check WordPress error logs first
2. Review recent commits: `git log`
3. Revert if needed: `git revert HEAD`
4. Plugin can be deactivated safely (won't delete data)
5. WordPress admin always accessible (plugin is optional)

**Safe rollback:**
```bash
git revert HEAD
git push origin main
gh release create vX.Y.Z  # Create release with reverted code
```

---

## ✅ VERIFICATION CHECKLIST

**After resuming work, verify:**
- [ ] Git repo is clean: `git status`
- [ ] On main branch: `git branch`
- [ ] Pulled latest: `git pull`
- [ ] Current version matches GitHub release
- [ ] Read recent commits: `git log -5`
- [ ] Understand recent changes (check this file)

**Before committing:**
- [ ] Version bumped (if adding features/fixes)
- [ ] Tested manually in WordPress admin
- [ ] Reviewed changes: `git diff`
- [ ] Updated CLAUDE.md if architecture changed
- [ ] Updated this file if workflow changed

**After pushing:**
- [ ] Create GitHub release for plugin updates
- [ ] Verify update shows in WordPress admin
- [ ] Test update process works
- [ ] Monitor error logs for issues

---

## 📚 ADDITIONAL RESOURCES

- **Main Documentation:** CLAUDE.md (comprehensive technical guide)
- **User Guide:** README.md
- **Theme Setup:** INSTALLATION-GUIDE.md, HOMEPAGE-INSTALLATION.md
- **Deployment:** DEPLOYMENT.md (GitHub Actions)
- **Git Repo:** https://github.com/jknauernever/envirolink-news
- **Live Site:** https://envirolink.org

---

**Remember:** This is a production WordPress plugin serving a live environmental news site. Always test carefully, commit incrementally, and create detailed commit messages for future reference.

🌍 **Mission:** Automate environmental news aggregation to help EnviroLink inform the world about our planet's critical issues.

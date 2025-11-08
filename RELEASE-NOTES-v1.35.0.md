# Release Notes: v1.35.0

**Release Date:** November 8, 2025
**Type:** SEO Enhancement
**Priority:** Medium

---

## 🎯 What's New

### Automatic Alt Text for All Images

This release adds automatic alt text generation for all featured images, addressing the AIOSEO warning about missing image alt attributes and improving both SEO and accessibility.

---

## ✅ Features Added

### 1. Automatic Alt Text for RSS Feed Images
**Location:** `envirolink-ai-aggregator.php:4282-4286`

- All images from RSS feeds now automatically receive alt text
- Alt text is based on the post title for relevance
- Fallback to "Environmental news image" if title is unavailable
- Applied to all new posts going forward

**Code changes:**
```php
// Add alt text for SEO and accessibility
$post_title = get_the_title($post_id);
$alt_text = !empty($post_title) ? $post_title : 'Environmental news image';
update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
```

### 2. Enhanced Alt Text for Unsplash Images
**Location:** `envirolink-ai-aggregator.php:4965`

- Improved alt text for Unsplash stock photos
- Changed from generic "Environmental photography" to more descriptive text
- Now uses: "Environmental news and nature photography from Unsplash"

---

## 🐛 Issues Fixed

### AIOSEO Warning Resolved
- ✅ Fixes: "Some images on the page have no alt attribute (29)"
- All future images will automatically have proper alt text
- Improves SEO signals for image search
- Enhances accessibility for screen readers

---

## 📈 SEO Impact

### Improved Search Engine Optimization
- **Image Search Rankings:** Better visibility in Google Image Search
- **Accessibility:** Screen readers can now describe images to visually impaired users
- **On-Page SEO:** Stronger semantic signals for search engines
- **AIOSEO Compliance:** Resolves critical SEO warning

### Expected Results
- Better rankings in image search results
- Improved overall page SEO score
- Enhanced user experience for accessibility
- Cleaner AIOSEO audit reports

---

## 🔄 Upgrade Instructions

### For Existing Users

1. **WordPress Admin → Plugins → Installed Plugins**
2. Find "EnviroLink AI News Aggregator"
3. Click "Check for Updates" (if not already shown)
4. Click "Update Now"
5. **Done!** All future images will automatically have alt text

### For New Installations

1. Download `envirolink-ai-aggregator.zip` from [GitHub Releases](https://github.com/jknauernever/envirolink-news/releases/tag/v1.35.0)
2. **WordPress Admin → Plugins → Add New → Upload Plugin**
3. Upload the ZIP file
4. Click "Install Now"
5. Activate the plugin

---

## 🧪 Testing

### How to Verify Alt Text is Working

1. Click **"Run All Feeds"** button in EnviroLink admin
2. Let it process and create new articles
3. View any newly created post
4. Right-click on the featured image → "Inspect Element"
5. Look for `alt="[Post Title]"` attribute in the `<img>` tag
6. ✅ Alt text should match the post title

### Expected Behavior

**Before v1.35.0:**
- Images had no alt attribute
- AIOSEO showed warning: "Some images on the page have no alt attribute"

**After v1.35.0:**
- All new images have descriptive alt text
- AIOSEO warning count decreases as new posts are created
- Screen readers can describe images

---

## 📝 Technical Details

### Files Modified
- `envirolink-ai-aggregator.php` - Main plugin file

### Code Changes
- Added alt text generation in `set_featured_image_from_url()` method
- Enhanced Unsplash alt text in `create_unsplash_attachment()` method
- Both changes use WordPress standard `_wp_attachment_image_alt` meta key

### Backward Compatibility
- ✅ Fully backward compatible with previous versions
- No database migrations required
- Existing images unchanged (alt text added to new images only)
- No changes to plugin settings or configuration

---

## 🔗 Related Documentation

- **CLAUDE.md** - Updated with v1.35.0 version history
- **PROJECT-STATUS.md** - Phase 2 marked as completed with alt text fix
- **SEO-IMPROVEMENTS.md** - Technical SEO implementation details
- **GROWTH-STRATEGY.md** - Overall 6-month growth plan

---

## 🚀 What's Next?

### Recommended Actions After Update

1. **Run All Feeds** to generate new articles with alt text
2. **Monitor AIOSEO warnings** - should decrease over time
3. **Optional:** Install Autoptimize plugin for CSS minification (low priority)
4. **Focus on Phase 3:** Social media distribution (high priority!)

### Upcoming Features (Roadmap)

**Phase 3: Social Media Distribution (Next Priority)**
- Automated social posting to Twitter/LinkedIn/Facebook
- Reddit integration for environmental communities
- Expected impact: +500-1,000 visitors/month

**Phase 4: Email List Building**
- Daily/weekly newsletter setup
- Email signup forms
- Automated roundup distribution
- Goal: 1,000+ subscribers by Month 6

---

## 📊 Version Comparison

| Feature | v1.34.0 | v1.35.0 |
|---------|---------|---------|
| SEO-optimized titles | ✅ | ✅ |
| Meta descriptions | ✅ | ✅ |
| Schema markup | ✅ | ✅ |
| Image alt text | ❌ | ✅ |
| AIOSEO compliance | Partial | Better |

---

## 💬 Feedback

Found a bug or have a feature request?
- **GitHub Issues:** https://github.com/jknauernever/envirolink-news/issues
- **Email:** support@envirolink.org

---

## 🎉 Summary

v1.35.0 is a focused SEO enhancement that ensures all images have proper alt text for search engines and accessibility. This is a quick win that addresses a critical AIOSEO warning with minimal code changes.

**Install today and improve your SEO score!** 🌍

---

*Release created on November 8, 2025*
*Last updated: November 8, 2025*

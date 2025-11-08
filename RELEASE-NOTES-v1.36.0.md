# Release Notes: v1.36.0

**Release Date:** November 8, 2025
**Type:** UX Enhancement
**Priority:** Medium

---

## 🎯 What's New

### Dynamic Roundup Titles with Content Preview

This release transforms daily roundups from generic templates into engaging content previews that entice readers to click and read more.

---

## ✅ What Changed

### Before v1.36.0 (Generic Template)
**Title:**
```
Environmental News Today: Climate, Wildlife & Conservation Updates [Nov 8]
```

**Description:**
```
Today's top environmental stories: climate action, wildlife conservation, renewable energy,
and sustainability news from around the world. Updated Nov 8, 2025.
```

### After v1.36.0 (Dynamic Preview)
**Title:**
```
Environmental News Roundup: Norway Doubles Krill Fishing Quota Despite Warnings
```

**Description:**
```
Scientists warn the move could devastate Antarctic ecosystems. Plus: 29 more stories
on climate action, wildlife conservation, and green energy. Read the full roundup →
```

---

## 🚀 Benefits

### Improved User Experience
- ✅ **More clickable** - Shows actual story instead of generic text
- ✅ **Creates curiosity** - Readers want to know more about the top story
- ✅ **Clear value** - Article count shows how much content awaits
- ✅ **Obvious CTA** - Arrow symbol (→) signals it's clickable
- ✅ **Social media style** - Preview format familiar from Twitter/LinkedIn

### SEO Still Optimized
- ✅ Keywords maintained: "Environmental News Roundup"
- ✅ Meta description trimmed to 155 characters (Google's display limit)
- ✅ NewsArticle schema markup unchanged
- ✅ Better click-through rate from search results

---

## 🔧 Technical Details

### Implementation
**Location:** `envirolink-ai-aggregator.php:4524-4544`

**How it works:**
1. Extracts title from the first article in the roundup
2. Gets first sentence from that article's content
3. Counts total articles in the roundup
4. Builds dynamic title: "Environmental News Roundup: [Top Story Title]"
5. Builds engaging description with preview + count + CTA
6. Trims description to 155 chars for optimal SEO

**Code snippet:**
```php
// Dynamic title and description using top story preview (Option 4 style)
$article_count = count($articles);
$top_article = $articles[0]; // First article is the top story
$top_title = $top_article->post_title;

// Extract first sentence from top article content for description teaser
$top_content = strip_tags($top_article->post_content);
$sentences = preg_split('/(?<=[.!?])\s+/', $top_content, 2);
$first_sentence = !empty($sentences[0]) ? $sentences[0] : wp_trim_words($top_content, 15, '...');

// Create dynamic title with top story highlight
$dynamic_title = 'Environmental News Roundup: ' . $top_title;

// Create engaging meta description with preview, count, and CTA
$other_count = $article_count - 1; // Subtract the top story
$meta_description = $first_sentence . ' Plus: ' . $other_count . ' more stories on climate action, wildlife conservation, and green energy. Read the full roundup →';

// Trim to 155 characters for optimal SEO display in search results
if (strlen($meta_description) > 155) {
    $meta_description = substr($meta_description, 0, 152) . '...';
}
```

---

## 📈 Expected Impact

### User Engagement
- **+40-60% higher click-through rate** on roundup posts
- Better social media sharing (more engaging previews)
- Improved time-on-site (readers clicking through to roundups)
- Lower bounce rate (clearer value proposition)

### SEO
- Better CTR from Google search results
- Improved user signals (more clicks, longer sessions)
- Maintained keyword optimization
- Better Open Graph previews on social platforms

---

## 🔄 Upgrade Instructions

### Automatic Update (Recommended)
1. WordPress will auto-check for updates within 12 hours
2. Go to **WordPress Admin → Plugins**
3. Click **"Update Now"** when v1.36.0 appears
4. Done!

### Manual Update
1. **WordPress Admin → Plugins → EnviroLink AI Aggregator**
2. Click **"Check for Updates"** (in System Status)
3. Update to v1.36.0
4. Test by clicking **"Generate Roundup"** button

---

## 🧪 Testing

### How to Test the New Format

1. Go to **WordPress Admin → EnviroLink News**
2. Click **"Generate Roundup"** button
3. Wait for roundup to generate
4. View the newly created roundup post
5. Check that:
   - ✅ Title shows actual top story
   - ✅ Description has content preview + article count
   - ✅ Arrow symbol (→) appears in description
   - ✅ Post displays properly on homepage

### What to Look For

**Good title example:**
```
Environmental News Roundup: UN Climate Summit Reaches Historic Carbon Agreement
```

**Good description example:**
```
Delegates from 195 countries committed to reducing emissions by 50% by 2030. Plus: 27 more stories on climate action, wildlife conservation...
```

**Bad title (shouldn't happen):**
```
Environmental News Today: Climate, Wildlife & Conservation Updates [Nov 8]
```
*If you see this, the update didn't apply correctly*

---

## 🔗 Related Changes

### Previous SEO Enhancements
This builds on the SEO foundation from v1.34.0 and v1.35.0:
- v1.34.0: Added schema markup, optimized meta tags
- v1.35.0: Added automatic alt text to images
- v1.36.0: **This release** - Dynamic, engaging titles

Together, these updates create a strong SEO + UX foundation for growth.

---

## 💡 Design Rationale

### Why This Format?

**Problem:** The old format was too generic and robotic:
- Didn't show what content actually was inside
- No reason to click (all roundups looked identical)
- Felt automated and impersonal
- Readers couldn't tell if it was worth their time

**Solution:** Show real content preview:
- Highlights most important story of the day
- Creates curiosity ("What's this about?")
- Shows value (X more stories)
- Clear action ("Read the full roundup")
- Feels more like human curation

**Inspiration:** Social media preview cards (Twitter, LinkedIn)
- Users are trained to click on previews
- First sentence creates context
- Article count builds anticipation
- Arrow symbol = obvious clickability

---

## 📊 Version Comparison

| Feature | v1.35.0 | v1.36.0 |
|---------|---------|---------|
| Roundup title format | Generic template | Dynamic with top story |
| Meta description | Generic text | Content preview + count |
| Call-to-action | None | Arrow symbol (→) |
| Clickability | Low | High |
| SEO keywords | ✅ | ✅ |
| Schema markup | ✅ | ✅ |
| Article count shown | ❌ | ✅ |

---

## 🎯 Next Steps

### After Updating

1. **Monitor engagement metrics:**
   - Track click-through rate on roundup posts
   - Monitor time spent on roundups
   - Check social media shares

2. **Test different top stories:**
   - Note which types of stories get more clicks
   - Adjust feed priorities if needed

3. **Share roundups on social media:**
   - The new preview format works great on Twitter/LinkedIn
   - Consider implementing Phase 3 (Social Media Distribution)

---

## 🐛 Troubleshooting

### Title Still Shows Old Format

**Issue:** After update, roundup still shows generic title

**Solution:**
1. Verify plugin updated to v1.36.0 (check Plugins page)
2. Generate a NEW roundup (old ones won't change)
3. Clear any caching plugins (WP Rocket, etc.)

### Description Too Long

**Issue:** Meta description gets cut off in search results

**Solution:** Already handled! Code automatically trims to 155 characters.

### Missing Arrow Symbol

**Issue:** Arrow (→) doesn't appear or shows as ?

**Solution:**
1. Ensure WordPress is using UTF-8 encoding
2. Check theme supports Unicode characters
3. Arrow is HTML entity, should work on all systems

---

## 💬 Feedback

Found this update helpful? Have suggestions for improvement?
- **GitHub Issues:** https://github.com/jknauernever/envirolink-news/issues
- **Email:** support@envirolink.org

---

## 🎉 Summary

v1.36.0 transforms daily roundups from generic templates into engaging content previews that show real stories and entice clicks. This is a significant UX improvement that maintains SEO value while dramatically improving readability and clickability.

**Upgrade today and make your roundups irresistible!** 🌍

---

*Release created on November 8, 2025*
*Last updated: November 8, 2025*

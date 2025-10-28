# EnviroLink Metadata Display - Installation Guide

This guide will help you add metadata display to both listing pages and single post pages in your Blocksy theme.

---

## What You'll Get

### On Listing Pages (Homepage, Archives):
- **Compact metadata line** showing: Source • Author • Date • First Topic
- Appears below the excerpt on each post card
- Clean, minimal design that doesn't clutter the layout

### On Single Post Pages:
- **Full attribution box** at the bottom of content showing:
  - Source name with link to original article
  - Original author
  - Original publication date
  - All topic tags
  - Locations
  - "Read Original Article" button
- Professional card design with icon

---

## Installation Steps

### Step 1: Add PHP Code to Child Theme Functions

1. **Access your child theme's functions.php:**
   - Go to: **Appearance → Theme File Editor**
   - Select "Blocksy Child" from the theme dropdown (top right)
   - Click on **functions.php** in the right sidebar

2. **Scroll to the very bottom** of the functions.php file

3. **Open the file:** `blocksy-child-functions.php` (in this project folder)

4. **Copy ALL the code** from that file

5. **Paste it at the END** of your child theme's functions.php

6. **Click "Update File"**

⚠️ **IMPORTANT:** If you get an error when updating:
- Click "Back"
- Check for syntax errors (missing `?>` or extra characters)
- Make sure you pasted at the very end

---

### Step 2: Add CSS Styling

1. **Go to:** **Appearance → Customize**

2. **Click:** **Additional CSS** (usually at the bottom of the menu)

3. **Open the file:** `blocksy-child-styles.css` (in this project folder)

4. **Copy ALL the CSS** from that file

5. **Paste it** into the Additional CSS editor

6. **Click "Publish"**

---

### Step 3: Test the Display

1. **Go to your homepage or blog archive page**
   - You should see metadata below the excerpt on EnviroLink posts
   - Format: "Mongabay • John Smith • Mar 15, 2024 • Climate"

2. **Click on an EnviroLink post** to view single page
   - Scroll to the bottom of the article content
   - You should see the full attribution box

3. **If nothing shows:**
   - Make sure you're viewing a post created BY the EnviroLink plugin
   - Check that the post has metadata (see troubleshooting below)

---

## Troubleshooting

### Problem: Nothing displays on listing pages

**Possible causes:**
1. Blocksy hook isn't working on your version
2. Posts don't have metadata

**Solution A - Try Alternative Hook:**

In your functions.php, find this section:
```php
// PART 3: Alternative Hook (if above doesn't work)
```

UNCOMMENT the code below it by removing `/*` and `*/` around the function.

**Solution B - Check Blocksy version:**
- Make sure you have Blocksy 2.0+ installed
- Update Blocksy if needed

---

### Problem: Nothing displays on single post pages

**Possible cause:** The post doesn't have EnviroLink metadata

**Check if post has metadata:**

Add this TEMPORARY code to your functions.php:
```php
function envirolink_debug() {
    if (!is_single()) return;

    $post_id = get_the_ID();
    $source = get_post_meta($post_id, 'envirolink_source_name', true);

    echo '<div style="background: yellow; padding: 20px; border: 3px solid red;">';
    echo '<h3>DEBUG:</h3>';
    echo '<p>Post ID: ' . $post_id . '</p>';
    echo '<p>Source: ' . ($source ? $source : 'NOT FOUND') . '</p>';
    echo '</div>';
}
add_action('wp_footer', 'envirolink_debug');
```

View a post. If you see "NOT FOUND", the post has no metadata.

**Why no metadata?**
- Post was created manually (not by plugin)
- Post was created before metadata feature was added
- Metadata extraction is disabled in plugin feed settings

**Fix:** Go to **EnviroLink News → RSS Feeds → Edit Settings** for your feed and make sure metadata checkboxes are enabled.

---

### Problem: Metadata displays but looks unstyled

**Cause:** CSS wasn't added or isn't loading

**Fix:**
1. Go to Appearance → Customize → Additional CSS
2. Make sure the CSS is there
3. Try adding `!important` to key styles if needed
4. Clear browser cache (Ctrl+Shift+R or Cmd+Shift+R)
5. Clear WordPress cache if you use a caching plugin

---

### Problem: Metadata shows on ALL posts (not just EnviroLink ones)

**This shouldn't happen** - the code checks for `envirolink_source_name` before displaying.

**If it does happen:**
- Check that you pasted the code correctly
- Make sure the `if (!$source_name) return;` lines are present

---

## Customization

### Change Colors

In the CSS file, find and replace:
- `#2c7a3e` - Main green color (change to your brand color)
- `#f9fafb` - Background color
- `#e5e7eb` - Border color

### Change Position on Single Posts

In functions.php, find this line:
```php
add_action('blocksy:single:content:bottom', 'envirolink_single_metadata', 10);
```

Replace `content:bottom` with:
- `content:top` - Display at top of content
- `title:after` - Display after title
- `featured-image:after` - Display after featured image

### Hide Metadata on Mobile

Add to your CSS:
```css
@media (max-width: 768px) {
    .envirolink-listing-meta {
        display: none;
    }
}
```

### Change Listing Format

In functions.php, find the `envirolink_listing_metadata()` function.

Current format: `Source • Author • Date • Tag`

To show only source and date:
```php
$metadata_parts = array();

if ($source_name) {
    $metadata_parts[] = '<span class="envirolink-source">' . esc_html($source_name) . '</span>';
}

if ($pubdate) {
    $formatted_date = date('M j, Y', strtotime($pubdate));
    $metadata_parts[] = '<span class="envirolink-date">' . esc_html($formatted_date) . '</span>';
}
```

### Show Only First 2 Tags on Single Posts

In the single post metadata function, find:
```php
foreach ($tag_array as $tag) {
```

Replace with:
```php
foreach (array_slice($tag_array, 0, 2) as $tag) {
```

---

## Preview

### Listing Page Example:
```
[Post Title]
[Excerpt text...]
Mongabay • John Smith • Mar 15, 2024 • Climate Change
```

### Single Page Example:
```
┌─────────────────────────────────────┐
│ 🔗 Article Source                   │
├─────────────────────────────────────┤
│ Source: Mongabay ↗                  │
│ Original Author: John Smith         │
│ Originally Published: March 15, 2024│
│ Topics: [Climate] [Deforestation]   │
│ Locations: Amazon, Brazil           │
│                                     │
│ [Read Original Article →]          │
└─────────────────────────────────────┘
```

---

## Testing Checklist

- [ ] Code added to functions.php without errors
- [ ] CSS added to Additional CSS
- [ ] Homepage shows metadata on EnviroLink posts
- [ ] Category/archive pages show metadata
- [ ] Single post page shows full attribution box
- [ ] "Read Original Article" button works
- [ ] Metadata doesn't show on regular posts
- [ ] Styling matches your site design
- [ ] Mobile responsive (check on phone)

---

## Need Help?

If you're stuck:

1. **Check WordPress debug log** for PHP errors
2. **Verify post has metadata** using the debug code above
3. **Try the alternative hook** in functions.php
4. **Check Blocksy version** - needs 2.0+
5. **Ensure child theme is active**

Common mistakes:
- ❌ Pasting code in wrong functions.php (main theme instead of child)
- ❌ Missing closing PHP tag `?>`
- ❌ Testing on manually created posts (no metadata)
- ❌ Metadata extraction disabled in plugin settings
- ❌ CSS not saved/published in Customizer

---

## File Reference

**Files in this project:**
- `blocksy-child-functions.php` - PHP code for functions.php
- `blocksy-child-styles.css` - CSS code for styling
- `INSTALLATION-GUIDE.md` - This file

**WordPress files to edit:**
- `wp-content/themes/blocksy-child/functions.php` - Add PHP here
- Appearance → Customize → Additional CSS - Add CSS here

---

## Success Indicators

✅ You're done when:
1. Homepage shows "Source • Author • Date" on aggregated posts
2. Single posts show full attribution box at bottom
3. "Read Original Article" button links to source
4. Styling looks professional and matches your theme
5. No PHP errors or console warnings

Ready to test? Follow the steps above and let me know if you hit any issues!

# EnviroLink Custom Homepage Installation Guide

This guide will help you install the custom homepage layout featuring:
- **Hero Section**: Daily Roundup with large image (left)
- **Headlines Sidebar**: Recent news headlines (right)
- **News Grid**: Article grid with images below

---

## Files Included

1. **front-page.php** - Custom homepage template
2. **envirolink-homepage.css** - Stylesheet for the custom layout
3. **blocksy-child-functions.php** - Updated child theme functions (includes CSS enqueue)

---

## Installation Steps

### Step 1: Access Your WordPress Files

You'll need to upload files to your Blocksy child theme directory:
- Path: `/wp-content/themes/blocksy-child/`

**Access via:**
- **cPanel File Manager**, or
- **FTP** (FileZilla, Cyberduck, etc.)

---

### Step 2: Upload the Files

Upload these files to `/wp-content/themes/blocksy-child/`:

1. **front-page.php**
2. **envirolink-homepage.css**
3. **blocksy-child-functions.php** (replace existing file - backup first!)

**File Structure:**
```
wp-content/
└── themes/
    └── blocksy-child/
        ├── front-page.php          ← NEW
        ├── envirolink-homepage.css ← NEW
        ├── functions.php           ← May already exist
        └── style.css               ← May already exist
```

---

### Step 3: Update functions.php

**Option A: Replace Entire File** (if you haven't customized it)
- Upload the `blocksy-child-functions.php` file
- Rename it to `functions.php` (remove `-child` suffix)

**Option B: Add Code Manually** (if you have existing customizations)
1. Open your existing `functions.php`
2. Scroll to the bottom
3. Add this code:

```php
// ============================================
// Enqueue Custom Homepage Styles
// ============================================

function envirolink_enqueue_homepage_styles() {
    // Only load on the homepage
    if (is_front_page()) {
        wp_enqueue_style(
            'envirolink-homepage',
            get_stylesheet_directory_uri() . '/envirolink-homepage.css',
            array(),
            '1.0.0'
        );
    }
}
add_action('wp_enqueue_scripts', 'envirolink_enqueue_homepage_styles');
```

---

### Step 4: Set Your Homepage

1. Go to: **Settings → Reading**
2. Under "Your homepage displays":
   - Select **"A static page"**
   - Choose any page as "Homepage" (doesn't matter - the template will override it)
3. Click **Save Changes**

**Note**: The `front-page.php` template automatically takes over when you set a static homepage.

---

### Step 5: Clear Cache

Clear all caches:
1. **WordPress Cache**: If using a caching plugin (W3 Total Cache, WP Super Cache, etc.)
2. **Blocksy Theme Cache**: Go to **Appearance → Customize** and refresh
3. **Browser Cache**: Hard refresh (Ctrl+Shift+R on Windows, Cmd+Shift+R on Mac)

---

### Step 6: Verify It Works

Visit your homepage: `https://envirolink.org`

You should see:
- ✅ Daily Roundup featured prominently on the left with large image
- ✅ Recent headlines sidebar on the right
- ✅ News grid below with image + headline cards
- ✅ "Daily Roundup" badge on the featured article

---

## Customization

### Change Number of Articles

Edit `front-page.php`:

**Recent Headlines** (currently 6):
```php
Line 73: 'posts_per_page' => 6,
```

**News Grid** (currently 12):
```php
Line 163: 'posts_per_page' => 12,
```

---

### Change Grid Columns

Edit `envirolink-homepage.css`:

**Desktop** (currently 4 columns):
```css
Line 221:
.envirolink-news-grid {
    grid-template-columns: repeat(4, 1fr);  /* Change 4 to 3 or 5 */
}
```

**Tablet** (currently 3 columns):
```css
Line 227:
    grid-template-columns: repeat(3, 1fr);  /* Change 3 to 2 */
```

---

### Change Colors

Edit `envirolink-homepage.css`:

**Primary Blue** (#2563eb):
```css
Lines 74, 91, 149, 244, 278, 356
```

**Red Category Tag** (#dc2626):
```css
Line 87
```

Replace all instances with your brand colors.

---

### Update Category Tabs

Edit `front-page.php` (Lines 154-157):

```php
<a href="#" class="envirolink-tab active">All Stories</a>
<a href="<?php echo get_category_link(get_cat_ID('Climate')); ?>">Climate</a>
<a href="<?php echo get_category_link(get_cat_ID('Conservation')); ?>">Conservation</a>
<a href="<?php echo get_category_link(get_cat_ID('Wildlife')); ?>">Wildlife</a>
```

**Replace** 'Climate', 'Conservation', 'Wildlife' with your actual WordPress category names.

---

## Troubleshooting

### Homepage Doesn't Show Custom Layout

**Check:**
1. Did you upload `front-page.php` to the child theme directory?
2. Did you set a static homepage in Settings → Reading?
3. Is the child theme active? (Appearance → Themes)
4. Clear all caches

**Verify file location:**
```
/wp-content/themes/blocksy-child/front-page.php  ✅ Correct
/wp-content/themes/blocksy/front-page.php        ❌ Wrong (parent theme)
```

---

### Styles Not Loading

**Check:**
1. Did you add the CSS enqueue code to `functions.php`?
2. Is `envirolink-homepage.css` in the child theme directory?
3. View page source (right-click → View Source) and search for "envirolink-homepage"
   - Should see: `<link href=".../envirolink-homepage.css"`
   - If missing, the CSS isn't being enqueued

---

### Daily Roundup Not Showing

**Check:**
1. Do you have at least one daily roundup post? (Run "Generate Roundup Now" in plugin)
2. Does the post have a featured image set?
3. Check browser console (F12) for JavaScript errors

---

### Grid Items Look Broken

**Check:**
1. Do your posts have featured images?
2. Clear browser cache
3. Try viewing in Incognito/Private mode
4. Check if Blocksy theme CSS is conflicting

**Add this to Additional CSS** if needed:
```css
.envirolink-grid-item * {
    box-sizing: border-box;
}
```

---

## Advanced: Alternative Installation (Copy/Paste CSS)

If you can't upload files, you can paste the CSS directly:

1. Go to: **Appearance → Customize → Additional CSS**
2. Copy ALL content from `envirolink-homepage.css`
3. Paste into Additional CSS box
4. Click **Publish**

**Note**: You still need to upload `front-page.php` to the child theme directory.

---

## Need Help?

If you encounter issues:

1. **Check File Permissions**: Files should be 644, directories 755
2. **Enable Debug Mode**: Add to `wp-config.php`:
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   ```
3. **Check Error Log**: Look in `/wp-content/debug.log`

---

## File Checklist

After installation, verify these files exist:

- [ ] `/wp-content/themes/blocksy-child/front-page.php`
- [ ] `/wp-content/themes/blocksy-child/envirolink-homepage.css`
- [ ] `/wp-content/themes/blocksy-child/functions.php` (with enqueue code)
- [ ] Settings → Reading: "A static page" selected
- [ ] At least one daily roundup post exists
- [ ] All caches cleared

---

## Preview

Once installed, your homepage will have this structure:

```
┌─────────────────────────────────────────────────────────────┐
│                         HEADER                               │
├─────────────────────────────────────┬───────────────────────┤
│                                     │                       │
│  [Large Daily Roundup Image]        │  Latest News          │
│                                     │  • Headline 1         │
│  HOT STORIES • Nov 4, 2025          │  • Headline 2         │
│                                     │  • Headline 3         │
│  Daily Environmental News Roundup   │  • Headline 4         │
│  by the EnviroLink Team             │  • Headline 5         │
│                                     │  • Headline 6         │
│  Lorem ipsum dolor sit amet...      │                       │
│                                     │                       │
│  15 stories covered | EnviroLink    │                       │
│                                     │                       │
├─────────────────────────────────────┴───────────────────────┤
│  All Stories  Climate  Conservation  Wildlife               │
├──────────┬──────────┬──────────┬──────────┬──────────┬─────┤
│ [Image]  │ [Image]  │ [Image]  │ [Image]  │ [Image]  │ ... │
│ Title 1  │ Title 2  │ Title 3  │ Title 4  │ Title 5  │     │
│ Excerpt  │ Excerpt  │ Excerpt  │ Excerpt  │ Excerpt  │     │
│ Source   │ Source   │ Source   │ Source   │ Source   │     │
├──────────┴──────────┴──────────┴──────────┴──────────┴─────┤
│                         FOOTER                               │
└─────────────────────────────────────────────────────────────┘
```

---

**Installation Complete!** 🎉

Your homepage should now have a professional, news-magazine style layout.

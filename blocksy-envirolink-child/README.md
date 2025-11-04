# Blocksy EnviroLink Child Theme

A custom WordPress child theme for EnviroLink featuring a professional news magazine homepage layout with daily roundup.

## Features

✨ **Custom Homepage Layout**
- Featured Daily Roundup with large image
- Recent headlines sidebar
- Responsive news grid with 4 columns

📰 **EnviroLink Integration**
- Displays metadata on single posts (source, author, date, topics)
- Automatic daily roundup detection
- Smart post filtering (excludes roundups from regular grids)

🎨 **Professional Design**
- Clean, modern news magazine aesthetic
- Fully responsive (desktop, tablet, mobile)
- Card-based layout with hover effects
- WordPress standard colors and typography

## Installation

### Step 1: Upload Theme

1. Download `blocksy-envirolink-child.zip`
2. Go to: **WordPress Admin → Appearance → Themes → Add New**
3. Click **Upload Theme**
4. Choose the ZIP file
5. Click **Install Now**
6. Click **Activate**

### Step 2: Verify Parent Theme

**Important:** This requires the **Blocksy** parent theme to be installed.

If you don't have Blocksy:
1. Go to: **Appearance → Themes → Add New**
2. Search for "Blocksy"
3. Install and activate Blocksy first
4. Then activate this child theme

### Step 3: Set Static Homepage

1. Go to: **Settings → Reading**
2. Under "Your homepage displays":
   - Select **"A static page"**
   - Homepage: Choose any page (or leave as default)
3. Click **Save Changes**

**Note:** The custom homepage template will automatically take over.

### Step 4: Clear Caches

1. Clear WordPress cache (if using a caching plugin)
2. Clear browser cache (Ctrl+Shift+R or Cmd+Shift+R)
3. Go to: **Appearance → Customize** and refresh

### Step 5: Verify

Visit your homepage: `https://envirolink.org`

You should see:
- ✅ Daily Roundup featured prominently on the left
- ✅ Recent headlines on the right
- ✅ News grid below with images

## Files Included

```
blocksy-envirolink-child/
├── style.css                   # Theme header and base styles
├── functions.php               # Theme functions and metadata display
├── front-page.php             # Custom homepage template
├── envirolink-homepage.css    # Homepage styling
└── README.md                  # This file
```

## Customization

### Change Number of Articles

Edit **`front-page.php`**:

**Recent Headlines** (currently 6):
```php
Line 73: 'posts_per_page' => 6,
```

**News Grid** (currently 12):
```php
Line 163: 'posts_per_page' => 12,
```

### Change Grid Columns

Edit **`envirolink-homepage.css`**:

```css
Line 221:
.envirolink-news-grid {
    grid-template-columns: repeat(4, 1fr);  /* Change 4 to 3 or 5 */
}
```

### Change Colors

Edit **`envirolink-homepage.css`**:

Primary blue (`#2563eb`) appears on:
- Lines 74, 91, 149, 244, 278, 356

Replace with your brand color.

### Update Category Tabs

Edit **`front-page.php`** (Lines 154-157):

```php
<a href="#" class="envirolink-tab active">All Stories</a>
<a href="<?php echo get_category_link(get_cat_ID('Climate')); ?>">Climate</a>
<a href="<?php echo get_category_link(get_cat_ID('Conservation')); ?>">Conservation</a>
```

Replace with your actual WordPress category names.

### Add Custom CSS

Go to: **Appearance → Customize → Additional CSS**

Add your custom styles there (they won't be overwritten by theme updates).

## Troubleshooting

### Homepage Shows Default Theme Layout

**Check:**
1. Is this child theme **activated**? (Appearance → Themes)
2. Did you set a static homepage? (Settings → Reading)
3. Is the Blocksy parent theme installed?
4. Clear all caches

### Daily Roundup Not Showing

**Check:**
1. Do you have at least one daily roundup post?
   - Go to plugin admin: **EnviroLink News**
   - Click **"Generate Roundup Now"**
2. Does the roundup post have a featured image?
3. Is the post published (not draft)?

### Styles Look Broken

**Try:**
1. Hard refresh: Ctrl+Shift+R (Windows) or Cmd+Shift+R (Mac)
2. Clear browser cache completely
3. Disable other plugins temporarily to check for conflicts
4. Check browser console (F12) for errors

### Metadata Not Showing on Single Posts

The metadata box appears automatically on single posts that were created by the EnviroLink plugin.

**Check:**
1. View a post that was aggregated by the plugin (has `envirolink_source_url` meta)
2. If still not showing, uncomment the fallback code in `functions.php` (lines 248-256)

## Requirements

- **WordPress**: 5.0 or higher
- **PHP**: 7.4 or higher
- **Parent Theme**: Blocksy (free version works fine)
- **Plugin**: EnviroLink AI News Aggregator

## Support

For issues or questions:
1. Check the troubleshooting section above
2. Review `HOMEPAGE-INSTALLATION.md` for detailed setup
3. Check WordPress error logs: `/wp-content/debug.log`

## Changelog

### Version 1.0.0
- Initial release
- Custom homepage with daily roundup featured section
- Recent headlines sidebar
- Responsive news grid
- EnviroLink metadata display on single posts
- Full mobile responsive design

## Credits

**Theme**: Blocksy EnviroLink Child Theme
**Author**: EnviroLink
**License**: GPL v2 or later
**Parent Theme**: Blocksy by CreativeThemes

## License

This theme is licensed under the GPL v2 or later.

> This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.

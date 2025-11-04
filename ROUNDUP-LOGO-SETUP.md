# EnviroLink Daily Roundup Logo Setup

The EnviroLink Daily Roundup posts now automatically use the EnviroLink logo as the featured image.

## Quick Setup

### Step 1: Prepare the Logo Image

1. Save the EnviroLink logo as: **`envirolink-roundup-logo.png`**
2. Recommended size: At least 1200x630px (optimal for social sharing)
3. Format: PNG with transparent background (preferred) or JPG

### Step 2: Upload to Plugin Directory

Upload the logo file to your WordPress plugin directory:

**Path:** `/wp-content/plugins/envirolink-ai-aggregator/envirolink-roundup-logo.png`

**Via cPanel File Manager:**
1. Log into cPanel
2. Navigate to: File Manager → public_html → wp-content → plugins → envirolink-ai-aggregator
3. Click **Upload**
4. Upload your `envirolink-roundup-logo.png` file

**Via FTP:**
1. Connect to your server via FTP
2. Navigate to: `/wp-content/plugins/envirolink-ai-aggregator/`
3. Upload `envirolink-roundup-logo.png`

### Step 3: Test

1. Go to: **WordPress Admin → EnviroLink News**
2. Click **"Generate Roundup Now"** (purple button)
3. Check the created post - it should have the EnviroLink logo as featured image

---

## How It Works

**Automatic Process:**
1. When a daily roundup is generated, the plugin looks for `envirolink-roundup-logo.png`
2. If found, it uploads the logo to WordPress Media Library (one-time)
3. Sets it as the featured image for the roundup post
4. Reuses the same media library image for all future roundups

**Smart Caching:**
- Logo is only uploaded to media library ONCE
- Attachment ID is stored in `wp_options` as `envirolink_roundup_logo_id`
- All future roundups reuse the same attachment (no duplicates)

---

## Troubleshooting

### Logo Not Showing on Roundup Posts

**Check WordPress error log:**
```
/wp-content/debug.log
```

**Look for:**
```
EnviroLink: Roundup logo not found at: /path/to/plugin/envirolink-roundup-logo.png
EnviroLink: Please upload envirolink-roundup-logo.png to the plugin directory
```

**Solutions:**
1. Verify file name is exactly: `envirolink-roundup-logo.png` (case-sensitive)
2. Verify file is in correct directory: `/wp-content/plugins/envirolink-ai-aggregator/`
3. Check file permissions: Should be 644

### Logo Uploaded But Not Showing

**Check if logo exists in Media Library:**
1. WordPress Admin → **Media → Library**
2. Search for: "EnviroLink Daily Roundup Logo"
3. If found, note the attachment ID

**Manually set the logo ID:**
```php
// In WordPress admin, go to: Tools → Site Health → Info → Constants
// Or use a plugin like "Code Snippets" to run:
update_option('envirolink_roundup_logo_id', 12345); // Replace 12345 with actual attachment ID
```

### Want to Change the Logo

**Option 1: Replace the file**
1. Delete existing: `envirolink-roundup-logo.png`
2. Upload new logo with same filename
3. Run this in WordPress (Tools → Site Health → Info or via plugin):
   ```php
   delete_option('envirolink_roundup_logo_id');
   ```
4. Generate a new roundup - it will upload the new logo

**Option 2: Upload directly to Media Library**
1. Upload your new logo via: **Media → Add New**
2. Note the attachment ID (visible in URL when editing: `post=12345`)
3. Set it as the roundup logo:
   ```php
   update_option('envirolink_roundup_logo_id', 12345); // Your attachment ID
   ```

---

## Manual Override

If you want to use a different image for a specific roundup post:

1. Edit the roundup post in WordPress
2. Remove the current featured image
3. Set a new featured image
4. Update the post

The plugin won't override manually-set featured images.

---

## File Location Summary

```
wp-content/
└── plugins/
    └── envirolink-ai-aggregator/
        ├── envirolink-ai-aggregator.php
        ├── envirolink-roundup-logo.png  ← ADD THIS FILE HERE
        ├── plugin-update-checker/
        └── README.md
```

---

## Advanced: Change Logo Filename

If you want to use a different filename (e.g., `logo.jpg`):

Edit `envirolink-ai-aggregator.php` line ~3444:
```php
// Change this:
$logo_path = ENVIROLINK_PLUGIN_DIR . 'envirolink-roundup-logo.png';

// To this (example):
$logo_path = ENVIROLINK_PLUGIN_DIR . 'logo.jpg';
```

Also update line ~3458 to match:
```php
'name' => 'logo.jpg',
```

---

## Questions?

If the logo still isn't working:
1. Enable WordPress debug logging: `define('WP_DEBUG_LOG', true);` in `wp-config.php`
2. Generate a roundup
3. Check `/wp-content/debug.log` for error messages
4. Look for lines starting with "EnviroLink:"

The log will show exactly what's happening with the logo upload process.

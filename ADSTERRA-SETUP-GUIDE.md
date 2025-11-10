# Adsterra Ad Setup Guide for EnviroLink.org
**Created:** November 10, 2025  
**Purpose:** Step-by-step instructions for implementing Adsterra ads using Advanced Ads plugin

---

## Prerequisites Checklist

- [ ] Advanced Ads plugin installed and activated
- [ ] Advanced Ads Pro (if you have it) activated
- [ ] Adsterra account created and approved
- [ ] 3 Adsterra ad codes generated (header, sidebar, native)

---

## Step 1: Get Your Adsterra Ad Codes

### Where to Find Them:
1. Log into your Adsterra dashboard: https://publishers.adsterra.com/
2. Navigate to **"Sites & Zones"** or **"Add Zone"**
3. Select your EnviroLink.org site
4. Create 3 zones:

### Recommended Ad Formats:

**Header Ad:**
- Format: Social Bar or Banner
- Size: 728x90 (Leaderboard) or Responsive
- Type: Display Banner

**Sidebar Ad:**
- Format: Banner
- Size: 300x250 (Medium Rectangle) or 300x600 (Half Page)
- Type: Display Banner

**Native Ad:**
- Format: Native Banner or In-Page Push
- Size: Responsive or 300x250
- Type: Native/In-Content

### Copy Each Ad Code:
You'll receive JavaScript code that looks like:
```html
<script type="text/javascript">
	atOptions = {
		'key' : 'YOUR_UNIQUE_KEY_HERE',
		'format' : 'iframe',
		'height' : 250,
		'width' : 300,
		'params' : {}
	};
	document.write('<scr' + 'ipt type="text/javascript" src="//www.topcreativeformat.com/YOUR_CODE_HERE/invoke.js"></scr' + 'ipt>');
</script>
```

**Save each code snippet - you'll need all 3!**

---

## Step 2: Create Ads in Advanced Ads

### 2.1: Access Advanced Ads

1. In WordPress admin, go to **Advanced Ads > Ads**
2. Click **"New Ad"** button (top of page)

### 2.2: Create Header Ad

**Ad Name:**
```
Adsterra Header - Homepage
```

**Ad Type:**
- Select **"Plain Text and Code"**
- Click **"Next"**

**Ad Parameters - Code:**
- Paste your Adsterra HEADER ad code into the text area
- Click **"Next"**

**Layout / Output Options:**
- **Position:** Center
- **Margin:** 
  - Top: 10px
  - Bottom: 20px
  - Leave Left/Right as 0
- **Container Classes:** `adsterra-header-ad`

**Display Conditions:**
- Scroll down to **"Display Conditions"**
- Click **"+ New condition"**
- Select **"Post Types"** (or "Page Types" if available)
- Check **ONLY "Front Page"** or **"Homepage"**
- Uncheck all other options (Posts, Pages, etc.)

**Save the Ad:**
- Click **"Publish"** button

---

### 2.3: Create Sidebar Ad

**Ad Name:**
```
Adsterra Sidebar - Homepage
```

**Repeat the same process as Header ad, but:**

**Layout / Output Options:**
- **Position:** Leave default or select "None"
- **Margin:**
  - Top: 0
  - Bottom: 20px
  - Leave Left/Right as 0
- **Container Classes:** `adsterra-sidebar-ad`

**Display Conditions:**
- Same as Header: Homepage/Front Page ONLY

**Save the Ad**

---

### 2.4: Create Native Ad

**Ad Name:**
```
Adsterra Native - Homepage
```

**Repeat the same process:**

**Layout / Output Options:**
- **Position:** Center
- **Margin:**
  - Top: 15px
  - Bottom: 15px
  - Leave Left/Right as 0
- **Container Classes:** `adsterra-native-ad`

**Display Conditions:**
- Same as others: Homepage/Front Page ONLY

**Save the Ad**

---

## Step 3: Create Placements

### 3.1: Header Placement

1. Go to **Advanced Ads > Placements**
2. Click **"New Placement"** button

**Placement Settings:**
- **Name:** `Header Ad`
- **Type:** Select **"Header Code"** (or "Before Content" if Header Code not available)
- **Choose an Ad:** Select **"Adsterra Header - Homepage"** from dropdown

**Options (if available):**
- Priority: 10 (default)
- Click **"Save New Placement"**

---

### 3.2: Sidebar Placement

**Placement Settings:**
- **Name:** `Sidebar Ad`
- **Type:** Select **"Sidebar Widget"**
- **Choose an Ad:** Select **"Adsterra Sidebar - Homepage"** from dropdown

**After saving, you need to add the widget:**
1. Go to **Appearance > Widgets**
2. Find **"Advanced Ads"** widget
3. Drag it to your **Primary Sidebar** (or main sidebar area)
4. In widget settings:
   - **Title:** Leave blank (or "Advertisement")
   - **Placement:** Select **"Sidebar Ad"**
   - Click **Save**

---

### 3.3: Native/Content Placement

**Placement Settings:**
- **Name:** `Native Content Ad`
- **Type:** Select **"Before Content"** or **"After Content"** (your choice)
  - *Before Content* = Ad appears at top of content area
  - *After Content* = Ad appears at bottom of content area
- **Choose an Ad:** Select **"Adsterra Native - Homepage"** from dropdown

**Save the Placement**

---

## Step 4: Verify Ad Setup

### 4.1: Check All Ads Are Created

Go to **Advanced Ads > Ads** and verify you see:
- ✓ Adsterra Header - Homepage
- ✓ Adsterra Sidebar - Homepage  
- ✓ Adsterra Native - Homepage

### 4.2: Check All Placements Are Active

Go to **Advanced Ads > Placements** and verify:
- ✓ Header Ad (Header Code) → Adsterra Header - Homepage
- ✓ Sidebar Ad (Widget) → Adsterra Sidebar - Homepage
- ✓ Native Content Ad (Before/After Content) → Adsterra Native - Homepage

---

## Step 5: Test in Incognito Mode

### Why Incognito?
- Bypasses cache
- Simulates new visitor
- Shows real ad experience
- Avoids ad blockers you might have

### Testing Process:

1. **Open Incognito/Private Window**
   - Chrome: Ctrl+Shift+N (Windows) or Cmd+Shift+N (Mac)
   - Firefox: Ctrl+Shift+P (Windows) or Cmd+Shift+P (Mac)
   - Safari: Cmd+Shift+N

2. **Visit Homepage**
   - Go to: https://envirolink.org
   - DO NOT visit www.envirolink.org if you're using non-www

3. **Check for Ads**
   - **Header Area:** Should see ad below header/navigation
   - **Sidebar:** Should see ad in right sidebar
   - **Content Area:** Should see ad before or after main content

4. **What You Should See:**
   - Ad placeholders with Adsterra code
   - Blank spaces (ads need 24-48 hours to populate)
   - OR actual ads if Adsterra has already filled them

### If You DON'T See Ads:

**Check these common issues:**
- Clear all caches (WordPress cache, browser cache)
- Make sure you're on the HOMEPAGE (not a post or category page)
- Check Display Conditions are set to Homepage only
- Verify Placements are assigned to correct ads
- Check browser console for JavaScript errors (F12 key)

---

## Step 6: Monitor Performance

### First 24-48 Hours:

**What's Normal:**
- Ads may not display immediately (blank spaces = normal)
- Adsterra needs time to populate ads to your zones
- Low impressions initially while system learns

### Check Adsterra Dashboard:

1. Log into Adsterra Publishers: https://publishers.adsterra.com/
2. Go to **"Statistics"** or **"Reports"**
3. Look for:
   - **Impressions:** Should start increasing within 24 hours
   - **CPM Rates:** Will stabilize after 48-72 hours
   - **Revenue:** May start small, will grow over time

### What to Expect:

**Day 1-2:**
- 0-50 impressions (low traffic, system learning)
- Ads may be blank or low-CPM filler

**Day 3-7:**
- Impressions should match your visitor count
- CPM rates stabilize (typically $0.50-$4.00 for environmental content)
- Should see consistent ad fill

**Week 2+:**
- Optimize based on performance data
- Adjust placements if needed
- Monitor revenue trends

---

## Troubleshooting Guide

### Ads Not Showing on Homepage:

**Check Display Conditions:**
1. Edit each ad in Advanced Ads
2. Scroll to Display Conditions
3. Verify ONLY "Front Page" or "Homepage" is selected
4. Save again

**Check Placement Assignment:**
1. Go to Advanced Ads > Placements
2. Verify each placement has correct ad selected
3. Re-save placements

### Ads Showing on Wrong Pages:

**Solution:** Display conditions need refinement
1. Edit the ad
2. Go to Display Conditions
3. Make sure ONLY homepage options are checked
4. If posts are checked, uncheck them

### Ads Not in Correct Positions:

**Header Ad Wrong Location:**
- Try different placement type (Header Code vs. Before Content)
- Some themes handle header differently

**Sidebar Ad Not Visible:**
- Check if homepage HAS a sidebar
- Some homepage designs remove sidebars
- May need to adjust theme layout

**Native Ad Overlapping Content:**
- Adjust margins in Layout / Output options
- Increase top/bottom margins to 20-30px

### No Impressions in Adsterra Dashboard:

**After 48 Hours:**
1. Verify ads are visible on live site (incognito mode)
2. Check that ad codes are correct in Advanced Ads
3. Look for JavaScript errors in browser console
4. Contact Adsterra support if codes are correct but no tracking

---

## Next Steps After Setup

### Week 1: Monitor Baseline Performance
- Check Adsterra dashboard daily
- Note average CPM rates
- Track impression vs. visitor ratio (should be ~1:1 or higher)

### Week 2: Optimize Placement
- If header ad performs poorly: Try before content instead
- If sidebar is hidden on mobile: Consider mobile-specific ads
- If native ad has low CTR: Try different position

### Month 1: Analyze Revenue
- Calculate: Total Revenue ÷ Total Visitors = Revenue per Visitor
- Compare to industry benchmarks
- Consider adding more ad zones if performing well

### As Traffic Grows:
- Request CPM increases from Adsterra
- Test different ad formats
- Consider premium ad networks (Media.net, Ezoic) when you hit 10K+ monthly visitors

---

## Important Notes

⚠️ **Ad Blocker Impact:**
- 25-40% of users may have ad blockers
- Your actual impressions will be lower than total visitors
- This is normal and expected

⚠️ **Mobile vs. Desktop:**
- Check ad display on both mobile and desktop
- Mobile may show different ad formats
- CPM rates typically lower on mobile

⚠️ **Don't Click Your Own Ads:**
- Adsterra will ban you for click fraud
- Use incognito to VIEW ads, never to click them
- Don't ask friends/family to click

⚠️ **Initial Revenue Will Be Low:**
- With 2,600 monthly visitors = ~86 visitors/day
- Minus ad blockers (30%) = ~60 ad impressions/day
- At $2 CPM average = $0.12/day = $3.60/month initially
- Focus on GROWING TRAFFIC for significant revenue

---

## Success Metrics

### Good Signs:
✓ All 3 ads visible on homepage (incognito mode)
✓ Impressions in Adsterra = ~70% of your daily visitors
✓ CPM rates above $1.00 consistently
✓ No error messages in browser console
✓ Page load time still under 3 seconds

### Red Flags:
✗ Ads showing on posts/pages (not just homepage)
✗ Zero impressions after 48 hours
✗ Multiple ads stacked on top of each other
✗ Page load time over 5 seconds
✗ Adsterra dashboard shows "Invalid traffic"

---

## Support Resources

**Advanced Ads Documentation:**
- Manual: https://wpadvancedads.com/manual/
- Display Conditions: https://wpadvancedads.com/manual/display-conditions/
- Placements: https://wpadvancedads.com/manual/placements/

**Adsterra Support:**
- Help Center: https://adsterra.com/help/
- Email: publishers@adsterra.com
- Telegram: @Adsterra_Community

**EnviroLink Project:**
- Check PROJECT-STATUS.md for overall progress
- Check GROWTH-STRATEGY.md for long-term goals

---

## Completion Checklist

- [ ] All 3 Adsterra ad codes obtained
- [ ] 3 ads created in Advanced Ads
- [ ] Display conditions set to homepage only
- [ ] 3 placements created and assigned
- [ ] Ads tested in incognito mode
- [ ] Ads visible on live homepage
- [ ] Adsterra dashboard tracking impressions
- [ ] No JavaScript errors in console
- [ ] Page loads in under 3 seconds
- [ ] Document completion in PROJECT-STATUS.md

---

**When complete, proceed to monitoring phase and traffic growth strategies!**

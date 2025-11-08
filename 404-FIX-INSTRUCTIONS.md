# Google Analytics Bot Filter Setup

## Step 1: Exclude Known Bots (Built-in)
1. Go to: Admin (gear icon, bottom left)
2. Click on "Data Settings" → "Data Streams"
3. Click on your web stream (www.envirolink.org)
4. Scroll down to "Enhanced measurement" 
5. Toggle ON "Exclude known bots and spiders"

## Step 2: Create Custom Filter for China Bot Traffic
1. Go to: Admin → Data Settings → Data Filters
2. Click "Create Filter"
3. Filter Name: "Exclude China Bot Traffic"
4. Filter Type: "Exclude"
5. Select "Country" equals "China"
6. Save

## Step 3: Create Segment for Real Traffic Only
1. In your reports, click "+ Add comparison"
2. Create custom segment:
   - Name: "Real Users (No Bots)"
   - Conditions:
     - Country does NOT equal "China"
     - Session duration > 0 seconds
     - OR Page path does NOT contain "/404.html"
3. Save and apply

## Step 4: Set Up Server-Level Bot Blocking

Add to your `.htaccess` file (WordPress root):

```apache
# Block known bad bots
SetEnvIfNoCase User-Agent "^$" bad_bot
SetEnvIfNoCase User-Agent "masscan" bad_bot
SetEnvIfNoCase User-Agent "zgrab" bad_bot  
SetEnvIfNoCase User-Agent "python" bad_bot
SetEnvIfNoCase User-Agent "curl" bad_bot
SetEnvIfNoCase User-Agent "scanner" bad_bot

# Block by IP range (common bot networks)
# Uncomment if you want to block entire China IP ranges
# Deny from 1.0.1.0/24

<Limit GET POST>
  Order Allow,Deny
  Allow from all
  Deny from env=bad_bot
</Limit>
```

## Step 5: WordPress Security Plugin
Install "Wordfence Security" (free):
1. WordPress Admin → Plugins → Add New
2. Search "Wordfence"
3. Install and Activate
4. Go to Wordfence → Firewall → Enable Extended Protection
5. Enable "Rate Limiting" to block bot floods

This will auto-block malicious bots and reduce 404 traffic by 80-90%.

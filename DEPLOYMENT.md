# Automated Deployment Setup

This guide will help you set up automated deployment from GitHub to your WordPress server.

## How It Works

When you (or Claude) push changes to GitHub, a GitHub Action automatically:
1. Detects the push to the `main` branch
2. Connects to your WordPress server via SFTP
3. Uploads the plugin files to the correct directory
4. Changes appear on your live site instantly!

## What You Need From Your Hosting Provider

You need **SFTP credentials** for your WordPress server. Contact your hosting provider (or check your hosting control panel) for:

1. **SFTP Host** - The server address (e.g., `envirolink.org` or `sftp.yourhost.com`)
2. **SFTP Username** - Your SFTP/FTP username
3. **SFTP Password** - Your SFTP/FTP password
4. **SFTP Port** - Usually `21` or `22` (ask your host if unsure)
5. **SFTP Path** - Path to your plugin directory (e.g., `/wp-content/plugins/envirolink-ai-aggregator/`)

### Finding the Plugin Path

If you're not sure of the exact path, it's typically one of these:
- `/wp-content/plugins/envirolink-ai-aggregator/`
- `/public_html/wp-content/plugins/envirolink-ai-aggregator/`
- `/home/username/public_html/wp-content/plugins/envirolink-ai-aggregator/`

Your hosting provider can tell you the exact path.

## Setup Instructions

Once you have your SFTP credentials, follow these steps:

### Step 1: Go to GitHub Secrets

1. Go to your repository: https://github.com/jknauernever/envirolink-news
2. Click **Settings** (top menu)
3. In the left sidebar, click **Secrets and variables** → **Actions**
4. Click the **New repository secret** button

### Step 2: Add Each Secret

Add these 5 secrets one by one (click "New repository secret" for each):

**Secret 1:**
- Name: `SFTP_HOST`
- Value: Your server address (e.g., `envirolink.org`)

**Secret 2:**
- Name: `SFTP_USERNAME`
- Value: Your SFTP username

**Secret 3:**
- Name: `SFTP_PASSWORD`
- Value: Your SFTP password

**Secret 4:**
- Name: `SFTP_PORT`
- Value: `21` (or `22` if your host uses that)

**Secret 5:**
- Name: `SFTP_PATH`
- Value: `/wp-content/plugins/envirolink-ai-aggregator/`
  - **Important:** Must start with `/` and end with `/`
  - Adjust based on your actual path

### Step 3: Test It!

Once all secrets are added:
1. I'll make a small test change and push to GitHub
2. Go to https://github.com/jknauernever/envirolink-news/actions
3. You'll see a workflow running
4. If successful, changes will appear on your WordPress site!

## Troubleshooting

### Deployment Failed?

Check the error in GitHub Actions:
1. Go to https://github.com/jknauernever/envirolink-news/actions
2. Click on the failed workflow
3. Look at the error message

**Common Issues:**

- **"Connection refused"** - Check `SFTP_HOST` and `SFTP_PORT`
- **"Authentication failed"** - Check `SFTP_USERNAME` and `SFTP_PASSWORD`
- **"Directory not found"** - Check `SFTP_PATH` is correct

### Need Help?

Just let me know what error message you see, and I'll help troubleshoot!

## Security Notes

- Your SFTP credentials are stored securely in GitHub Secrets
- They're encrypted and never visible in logs or code
- Only the GitHub Actions workflow can access them
- They're never committed to the repository

## What Gets Deployed

The workflow uploads:
- `envirolink-ai-aggregator.php` (main plugin file)
- `README.md`
- `CLAUDE.md`
- `.gitignore`

It excludes:
- Git files
- GitHub Actions files
- Shell scripts
- System files like `.DS_Store`

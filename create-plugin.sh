#!/bin/bash

# EnviroLink AI News Aggregator - Package Script
# Creates a properly structured ZIP file for WordPress plugin updates

echo "Creating EnviroLink AI News Aggregator plugin ZIP..."
echo ""

# Remove old ZIP if exists
rm -f envirolink-ai-aggregator.zip

# Create temporary directory with correct name
TEMP_DIR="../temp-plugin-build"
PLUGIN_DIR="$TEMP_DIR/envirolink-ai-aggregator"

rm -rf "$TEMP_DIR"
mkdir -p "$PLUGIN_DIR"

# Copy plugin files
echo "Copying plugin files..."
cp -r envirolink-ai-aggregator.php "$PLUGIN_DIR/"
cp -r plugin-update-checker/ "$PLUGIN_DIR/"
cp README.md "$PLUGIN_DIR/"
cp CLAUDE.md "$PLUGIN_DIR/"
cp INSTALLATION-GUIDE.md "$PLUGIN_DIR/"

# Create ZIP from temp directory
echo "Creating ZIP archive..."
cd "$TEMP_DIR"
zip -r envirolink-ai-aggregator.zip envirolink-ai-aggregator/ -x "*.git*" "*.DS_Store" > /dev/null

# Move ZIP back to project directory
mv envirolink-ai-aggregator.zip ../envirolink-news/

# Clean up
cd ../envirolink-news
rm -rf "$TEMP_DIR"

echo ""
echo "✓ Created envirolink-ai-aggregator.zip"
echo ""
echo "ZIP structure:"
echo "  envirolink-ai-aggregator.zip"
echo "  └── envirolink-ai-aggregator/"
echo "      ├── envirolink-ai-aggregator.php"
echo "      ├── plugin-update-checker/"
echo "      ├── README.md"
echo "      ├── CLAUDE.md"
echo "      └── INSTALLATION-GUIDE.md"
echo ""
echo "Ready for WordPress upload or GitHub release!"

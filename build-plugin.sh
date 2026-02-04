#!/bin/bash

# WP-to-CF Plugin Builder
# 将插件打包成 ZIP 文件，放在 build 目录

set -e

echo "========================================="
echo "WP-to-CF Plugin Builder"
echo "========================================="
echo ""

# 获取脚本所在目录
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$SCRIPT_DIR"

# 定义变量
PLUGIN_NAME="wp-to-cf"
VERSION="1.2.5-alpha2"
BUILD_DIR="build"
TEMP_DIR="$BUILD_DIR/temp"
ZIP_NAME="${PLUGIN_NAME}-${VERSION}.zip"

# 清理旧的构建
echo "🧹 Cleaning old builds..."
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"
mkdir -p "$TEMP_DIR/$PLUGIN_NAME"

# 复制文件到临时目录
echo "📦 Copying plugin files..."

# 复制主要文件
cp -r includes "$TEMP_DIR/$PLUGIN_NAME/"
cp -r admin "$TEMP_DIR/$PLUGIN_NAME/"
cp wp-to-cf.php "$TEMP_DIR/$PLUGIN_NAME/"
cp index.php "$TEMP_DIR/$PLUGIN_NAME/"
cp uninstall.php "$TEMP_DIR/$PLUGIN_NAME/"

# 复制文档文件（如果存在）
[ -f README.md ] && cp README.md "$TEMP_DIR/$PLUGIN_NAME/"
[ -f LICENSE.txt ] && cp LICENSE.txt "$TEMP_DIR/$PLUGIN_NAME/"

# 排除不需要的文件
echo "🗑️  Removing unnecessary files..."
find "$TEMP_DIR" -name ".DS_Store" -delete
find "$TEMP_DIR" -name "*.log" -delete
find "$TEMP_DIR" -name "test-*.php" -delete
find "$TEMP_DIR" -name ".git*" -delete

# 创建 ZIP 包
echo "📦 Creating ZIP package..."
cd "$TEMP_DIR"
zip -r "../$ZIP_NAME" "$PLUGIN_NAME" -q

# 清理临时目录
cd "$SCRIPT_DIR"
rm -rf "$TEMP_DIR"

# 显示结果
echo ""
echo "✅ Build completed successfully!"
echo ""
echo "📦 Package: $BUILD_DIR/$ZIP_NAME"
echo "📊 Size: $(du -h "$BUILD_DIR/$ZIP_NAME" | cut -f1)"
echo ""
echo "========================================="
echo "Installation Instructions:"
echo "========================================="
echo "1. Upload $ZIP_NAME to WordPress"
echo "2. Go to Plugins > Add New > Upload Plugin"
echo "3. Select the ZIP file and click Install Now"
echo "4. Activate the plugin"
echo ""
echo "Or manually extract to wp-content/plugins/"
echo "========================================="

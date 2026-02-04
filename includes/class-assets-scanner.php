<?php
/**
 * 资产扫描器类
 * 
 * 负责扫描 WordPress 主题和插件目录中的静态资产文件
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_Assets_Scanner
 * 
 * 扫描和管理静态资产文件
 */
class WP_to_CF_Assets_Scanner
{
    /**
     * 支持的资产文件扩展名
     */
    private const SUPPORTED_EXTENSIONS = [
        'css',
        'js',
        'jpg',
        'jpeg',
        'png',
        'gif',
        'svg',
        'webp',
        'woff',
        'woff2',
        'ttf',
        'eot',
        'ico',
    ];

    /**
     * 忽略的文件扩展名
     */
    private const IGNORED_EXTENSIONS = [
        'php',
        'txt',
        'map',
        'md',
        'json',
        'lock',
        'log',
    ];

    /**
     * 忽略的目录名
     */
    private const IGNORED_DIRECTORIES = [
        'node_modules',
        'vendor',
        '.git',
        '.svn',
        'tests',
        'test',
    ];

    /**
     * 扫描主题和插件目录中的资产文件
     *
     * @return array 资产文件数组，格式：['relative_path' => 'absolute_path']
     */
    public function scan_assets(): array
    {
        $assets = [];

        WP_to_CF_Logger::info('Starting assets scan');

        // 扫描主题目录
        $themes_dir = WP_CONTENT_DIR . '/themes';
        if (is_dir($themes_dir)) {
            $theme_assets = $this->scan_directory($themes_dir, 'wp-content/themes');
            $assets = array_merge($assets, $theme_assets);
            
            WP_to_CF_Logger::info('Scanned themes directory', [
                'path' => $themes_dir,
                'assets_found' => count($theme_assets),
            ]);
        }

        // 扫描插件目录
        $plugins_dir = WP_CONTENT_DIR . '/plugins';
        if (is_dir($plugins_dir)) {
            $plugin_assets = $this->scan_directory($plugins_dir, 'wp-content/plugins');
            $assets = array_merge($assets, $plugin_assets);
            
            WP_to_CF_Logger::info('Scanned plugins directory', [
                'path' => $plugins_dir,
                'assets_found' => count($plugin_assets),
            ]);
        }

        WP_to_CF_Logger::info('Assets scan completed', [
            'total_assets' => count($assets),
        ]);

        return $assets;
    }

    /**
     * 递归扫描目录
     *
     * @param string $directory 要扫描的目录（绝对路径）
     * @param string $base_path 基础路径（用于生成相对路径）
     * @return array 资产文件数组
     */
    private function scan_directory(string $directory, string $base_path): array
    {
        $assets = [];

        if (!is_dir($directory) || !is_readable($directory)) {
            return $assets;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                // 跳过目录
                if ($file->isDir()) {
                    continue;
                }

                // 检查是否在忽略的目录中
                $file_path = $file->getPathname();
                if ($this->is_ignored_directory($file_path)) {
                    continue;
                }

                // 检查文件扩展名
                $extension = strtolower($file->getExtension());
                
                if (!in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
                    continue;
                }

                if (in_array($extension, self::IGNORED_EXTENSIONS, true)) {
                    continue;
                }

                // 生成相对路径
                $absolute_path = $file->getRealPath();
                $relative_path = $this->get_relative_path($absolute_path, $base_path);

                if ($relative_path) {
                    $assets[$relative_path] = $absolute_path;
                }
            }
        } catch (Exception $e) {
            WP_to_CF_Logger::error('Error scanning directory', [
                'directory' => $directory,
                'error' => $e->getMessage(),
            ]);
        }

        return $assets;
    }

    /**
     * 检查路径是否在忽略的目录中
     *
     * @param string $path 文件路径
     * @return bool 是否应该忽略
     */
    private function is_ignored_directory(string $path): bool
    {
        foreach (self::IGNORED_DIRECTORIES as $ignored_dir) {
            if (strpos($path, DIRECTORY_SEPARATOR . $ignored_dir . DIRECTORY_SEPARATOR) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取相对路径
     *
     * @param string $absolute_path 绝对路径
     * @param string $base_path     基础路径
     * @return string|false 相对路径，失败返回 false
     */
    private function get_relative_path(string $absolute_path, string $base_path): string|false
    {
        // 标准化路径分隔符
        $absolute_path = str_replace('\\', '/', $absolute_path);
        $wp_content_dir = str_replace('\\', '/', WP_CONTENT_DIR);

        // 查找 wp-content 在路径中的位置
        $pos = strpos($absolute_path, $wp_content_dir);
        
        if ($pos === false) {
            return false;
        }

        // 提取相对于 wp-content 的路径
        $relative = substr($absolute_path, $pos + strlen($wp_content_dir) + 1);
        
        // 确保使用正斜杠
        $relative = str_replace('\\', '/', $relative);

        return $relative;
    }

    /**
     * 计算文件的 MD5 哈希
     *
     * @param string $file_path 文件路径
     * @return string|false MD5 哈希，失败返回 false
     */
    public function calculate_file_hash(string $file_path): string|false
    {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return false;
        }

        return md5_file($file_path);
    }

    /**
     * 批量计算文件哈希
     *
     * @param array $assets 资产文件数组，格式：['relative_path' => 'absolute_path']
     * @return array 哈希数组，格式：['relative_path' => 'md5_hash']
     */
    public function calculate_assets_hashes(array $assets): array
    {
        $hashes = [];

        foreach ($assets as $relative_path => $absolute_path) {
            $hash = $this->calculate_file_hash($absolute_path);
            
            if ($hash !== false) {
                $hashes[$relative_path] = $hash;
            } else {
                WP_to_CF_Logger::warning('Failed to calculate hash for asset', [
                    'relative_path' => $relative_path,
                    'absolute_path' => $absolute_path,
                ]);
            }
        }

        return $hashes;
    }

    /**
     * 获取文件内容
     *
     * @param string $file_path 文件路径
     * @return string|false 文件内容，失败返回 false
     */
    public function get_file_content(string $file_path): string|false
    {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return false;
        }

        return file_get_contents($file_path);
    }
}

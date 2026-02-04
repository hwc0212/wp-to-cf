<?php
/**
 * 资源本地化类
 * 
 * 负责下载远程 CSS、JS 和字体文件到本地
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_Asset_Localizer
 * 
 * 将远程资源（Google Fonts、CDN 资源）下载到本地
 */
class WP_to_CF_Asset_Localizer
{
    /**
     * 本地资源存储目录（相对于 wp-content/uploads）
     */
    private const ASSETS_DIR = 'wptocf-assets';

    /**
     * 资源过期时间（秒）
     */
    private const ASSET_EXPIRY = 30 * 24 * 60 * 60; // 30 天

    /**
     * 需要本地化的 CDN 域名列表
     */
    private array $cdn_domains = [
        'fonts.googleapis.com',
        'fonts.gstatic.com',
        'cdnjs.cloudflare.com',
        'cdn.jsdelivr.net',
        'unpkg.com',
        'ajax.googleapis.com',
        'code.jquery.com',
        'maxcdn.bootstrapcdn.com',
        'stackpath.bootstrapcdn.com',
    ];

    /**
     * 本地化统计信息
     */
    private array $stats = [
        'total_found' => 0,
        'downloaded' => 0,
        'failed' => 0,
        'skipped' => 0,
        'total_size' => 0,
    ];

    /**
     * 本地化 HTML 中的远程资源
     *
     * @param string $html 原始 HTML
     * @return string 本地化后的 HTML
     */
    public function localize_assets(string $html): string
    {
        WP_to_CF_Logger::info('Starting asset localization');

        // 确保资源目录存在
        $this->ensure_assets_directory();

        // 重置统计信息
        $this->stats = [
            'total_found' => 0,
            'downloaded' => 0,
            'failed' => 0,
            'skipped' => 0,
            'total_size' => 0,
        ];

        // 1. 本地化 CSS 文件
        $html = $this->localize_css_files($html);

        // 2. 本地化 JS 文件
        $html = $this->localize_js_files($html);

        // 3. 本地化 Google Fonts
        $html = $this->localize_google_fonts($html);

        WP_to_CF_Logger::info('Asset localization completed', $this->stats);

        return $html;
    }

    /**
     * 确保资源目录存在并可写
     *
     * @return void
     */
    private function ensure_assets_directory(): void
    {
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'] . '/' . self::ASSETS_DIR;

        // 创建主目录
        if (!file_exists($base_dir)) {
            if (!wp_mkdir_p($base_dir)) {
                WP_to_CF_Logger::error('Failed to create assets directory', [
                    'dir' => $base_dir,
                ]);
                return;
            }
            WP_to_CF_Logger::info('Created assets directory', [
                'dir' => $base_dir,
            ]);
        }

        // 创建子目录
        $subdirs = ['css', 'js', 'fonts'];
        foreach ($subdirs as $subdir) {
            $subdir_path = $base_dir . '/' . $subdir;
            if (!file_exists($subdir_path)) {
                if (!wp_mkdir_p($subdir_path)) {
                    WP_to_CF_Logger::error('Failed to create subdirectory', [
                        'dir' => $subdir_path,
                    ]);
                } else {
                    WP_to_CF_Logger::info('Created subdirectory', [
                        'dir' => $subdir_path,
                    ]);
                }
            }
        }

        // 检查写入权限
        if (!is_writable($base_dir)) {
            WP_to_CF_Logger::error('Assets directory is not writable', [
                'dir' => $base_dir,
                'permissions' => substr(sprintf('%o', fileperms($base_dir)), -4),
            ]);
        } else {
            WP_to_CF_Logger::info('Assets directory is writable', [
                'dir' => $base_dir,
            ]);
        }
    }

    /**
     * 本地化 CSS 文件
     *
     * @param string $html HTML 内容
     * @return string 处理后的 HTML
     */
    private function localize_css_files(string $html): string
    {
        // 匹配 <link rel="stylesheet" href="...">
        $pattern = '/<link\s+([^>]*\s+)?rel=["\']stylesheet["\']([^>]*\s+)?href=["\'](https?:\/\/[^"\']+\.css[^"\']*)["\']([^>]*)>/i';

        $html = preg_replace_callback($pattern, function ($matches) {
            $full_tag = $matches[0];
            $remote_url = $matches[3];

            // 检查是否是需要本地化的 CDN
            if (!$this->should_localize_url($remote_url)) {
                $this->stats['skipped']++;
                return $full_tag;
            }

            $this->stats['total_found']++;

            // 下载并获取本地 URL
            $local_url = $this->download_asset($remote_url, 'css');

            if ($local_url === false) {
                $this->stats['failed']++;
                WP_to_CF_Logger::warning('Failed to localize CSS, keeping original URL', [
                    'url' => $remote_url,
                ]);
                return $full_tag;
            }

            $this->stats['downloaded']++;

            // 替换 URL
            return str_replace($remote_url, $local_url, $full_tag);
        }, $html);

        return $html;
    }

    /**
     * 本地化 JS 文件
     *
     * @param string $html HTML 内容
     * @return string 处理后的 HTML
     */
    private function localize_js_files(string $html): string
    {
        // 匹配 <script src="...">
        $pattern = '/<script\s+([^>]*\s+)?src=["\'](https?:\/\/[^"\']+\.js[^"\']*)["\']([^>]*)>/i';

        $html = preg_replace_callback($pattern, function ($matches) {
            $full_tag = $matches[0];
            $remote_url = $matches[2];

            // 检查是否是需要本地化的 CDN
            if (!$this->should_localize_url($remote_url)) {
                $this->stats['skipped']++;
                return $full_tag;
            }

            $this->stats['total_found']++;

            // 下载并获取本地 URL
            $local_url = $this->download_asset($remote_url, 'js');

            if ($local_url === false) {
                $this->stats['failed']++;
                WP_to_CF_Logger::warning('Failed to localize JS, keeping original URL', [
                    'url' => $remote_url,
                ]);
                return $full_tag;
            }

            $this->stats['downloaded']++;

            // 替换 URL
            return str_replace($remote_url, $local_url, $full_tag);
        }, $html);

        return $html;
    }

    /**
     * 本地化 Google Fonts
     *
     * @param string $html HTML 内容
     * @return string 处理后的 HTML
     */
    private function localize_google_fonts(string $html): string
    {
        // 匹配 Google Fonts CSS 链接
        $pattern = '/<link\s+([^>]*\s+)?href=["\'](https?:\/\/fonts\.googleapis\.com\/css[^"\']*)["\']([^>]*)>/i';

        $html = preg_replace_callback($pattern, function ($matches) {
            $full_tag = $matches[0];
            $remote_url = $matches[2];

            $this->stats['total_found']++;

            // 下载 Google Fonts CSS
            $local_url = $this->download_google_fonts_css($remote_url);

            if ($local_url === false) {
                $this->stats['failed']++;
                WP_to_CF_Logger::warning('Failed to localize Google Fonts, keeping original URL', [
                    'url' => $remote_url,
                ]);
                return $full_tag;
            }

            $this->stats['downloaded']++;

            // 替换 URL
            return str_replace($remote_url, $local_url, $full_tag);
        }, $html);

        return $html;
    }

    /**
     * 下载 Google Fonts CSS 并本地化字体文件
     *
     * @param string $css_url Google Fonts CSS URL
     * @return string|false 本地 CSS URL，失败返回 false
     */
    private function download_google_fonts_css(string $css_url): string|false
    {
        // 下载 CSS 文件
        $response = wp_remote_get($css_url, [
            'timeout' => 15,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);

        if (is_wp_error($response)) {
            WP_to_CF_Logger::error('Failed to download Google Fonts CSS', [
                'url' => $css_url,
                'error' => $response->get_error_message(),
            ]);
            return false;
        }

        $css_content = wp_remote_retrieve_body($response);

        if (empty($css_content)) {
            return false;
        }

        // 下载 CSS 中引用的字体文件
        $css_content = preg_replace_callback(
            '/url\((https?:\/\/[^)]+)\)/',
            function ($matches) {
                $font_url = trim($matches[1], '\'"');
                $local_font_url = $this->download_asset($font_url, 'fonts');

                if ($local_font_url === false) {
                    return $matches[0]; // 保持原样
                }

                return "url({$local_font_url})";
            },
            $css_content
        );

        // 保存本地化的 CSS 文件
        $local_css_path = $this->get_local_path($css_url, 'css');
        $local_css_url = $this->get_local_url($css_url, 'css');

        if ($this->save_asset($local_css_path, $css_content)) {
            return $local_css_url;
        }

        return false;
    }

    /**
     * 下载远程资源到本地
     *
     * @param string $remote_url 远程 URL
     * @param string $type       资源类型（css/js/fonts）
     * @return string|false 本地 URL，失败返回 false
     */
    private function download_asset(string $remote_url, string $type): string|false
    {
        // 获取本地路径和 URL
        $local_path = $this->get_local_path($remote_url, $type);
        $local_url = $this->get_local_url($remote_url, $type);

        // 如果文件已存在且未过期，直接返回
        if (file_exists($local_path)) {
            $file_age = time() - filemtime($local_path);
            if ($file_age < self::ASSET_EXPIRY) {
                WP_to_CF_Logger::info('Using cached asset', [
                    'url' => $remote_url,
                    'local_path' => $local_path,
                    'age_days' => round($file_age / 86400, 1),
                ]);
                return $local_url;
            }
        }

        // 下载文件
        $response = wp_remote_get($remote_url, [
            'timeout' => 30,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);

        if (is_wp_error($response)) {
            WP_to_CF_Logger::error('Failed to download asset', [
                'url' => $remote_url,
                'error' => $response->get_error_message(),
            ]);
            return false;
        }

        $content = wp_remote_retrieve_body($response);

        if (empty($content)) {
            WP_to_CF_Logger::error('Downloaded asset is empty', [
                'url' => $remote_url,
            ]);
            return false;
        }

        // 保存文件
        if ($this->save_asset($local_path, $content)) {
            $this->stats['total_size'] += strlen($content);

            WP_to_CF_Logger::info('Asset downloaded successfully', [
                'url' => $remote_url,
                'local_path' => $local_path,
                'size' => strlen($content),
            ]);

            return $local_url;
        }

        return false;
    }

    /**
     * 保存资源到本地
     *
     * @param string $local_path 本地路径
     * @param string $content    文件内容
     * @return bool 是否成功
     */
    private function save_asset(string $local_path, string $content): bool
    {
        // 确保目录存在
        $dir = dirname($local_path);
        if (!file_exists($dir)) {
            if (!wp_mkdir_p($dir)) {
                WP_to_CF_Logger::error('Failed to create directory', [
                    'dir' => $dir,
                ]);
                return false;
            }
        }

        // 保存文件
        $result = file_put_contents($local_path, $content);

        if ($result === false) {
            WP_to_CF_Logger::error('Failed to save asset', [
                'path' => $local_path,
            ]);
            return false;
        }

        return true;
    }

    /**
     * 获取本地文件路径
     *
     * @param string $remote_url 远程 URL
     * @param string $type       资源类型
     * @return string 本地文件路径
     */
    private function get_local_path(string $remote_url, string $type): string
    {
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'] . '/' . self::ASSETS_DIR . '/' . $type;

        // 生成唯一文件名（使用 URL 的 MD5）
        $hash = md5($remote_url);
        $extension = pathinfo(parse_url($remote_url, PHP_URL_PATH), PATHINFO_EXTENSION);

        // 如果没有扩展名，使用类型作为扩展名
        if (empty($extension)) {
            $extension = $type;
        }

        return $base_dir . '/' . $hash . '.' . $extension;
    }

    /**
     * 获取本地文件 URL
     *
     * @param string $remote_url 远程 URL
     * @param string $type       资源类型
     * @return string 本地文件 URL
     */
    private function get_local_url(string $remote_url, string $type): string
    {
        $upload_dir = wp_upload_dir();
        $base_url = $upload_dir['baseurl'] . '/' . self::ASSETS_DIR . '/' . $type;

        // 生成唯一文件名（使用 URL 的 MD5）
        $hash = md5($remote_url);
        $extension = pathinfo(parse_url($remote_url, PHP_URL_PATH), PATHINFO_EXTENSION);

        // 如果没有扩展名，使用类型作为扩展名
        if (empty($extension)) {
            $extension = $type;
        }

        return $base_url . '/' . $hash . '.' . $extension;
    }

    /**
     * 检查 URL 是否需要本地化
     *
     * @param string $url URL
     * @return bool 是否需要本地化
     */
    private function should_localize_url(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (empty($host)) {
            return false;
        }

        // 检查是否在 CDN 列表中
        foreach ($this->cdn_domains as $cdn_domain) {
            if (stripos($host, $cdn_domain) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 清理过期的本地资源
     *
     * @param int $expiry_days 过期天数，默认 30 天
     * @return array 清理统计信息
     */
    public function cleanup_old_assets(int $expiry_days = 30): array
    {
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'] . '/' . self::ASSETS_DIR;

        $stats = [
            'deleted_files' => 0,
            'deleted_size' => 0,
            'errors' => 0,
        ];

        if (!file_exists($base_dir)) {
            return $stats;
        }

        $expiry_time = time() - ($expiry_days * 24 * 60 * 60);

        // 递归遍历目录
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                if ($file->getMTime() < $expiry_time) {
                    $file_size = $file->getSize();

                    if (unlink($file->getPathname())) {
                        $stats['deleted_files']++;
                        $stats['deleted_size'] += $file_size;
                    } else {
                        $stats['errors']++;
                    }
                }
            }
        }

        WP_to_CF_Logger::info('Cleaned up old assets', $stats);

        return $stats;
    }

    /**
     * 获取本地化统计信息
     *
     * @return array 统计信息
     */
    public function get_localization_stats(): array
    {
        return $this->stats;
    }

    /**
     * 添加自定义 CDN 域名
     *
     * @param string $domain CDN 域名
     * @return void
     */
    public function add_cdn_domain(string $domain): void
    {
        if (!in_array($domain, $this->cdn_domains)) {
            $this->cdn_domains[] = $domain;
        }
    }

    /**
     * 获取 CDN 域名列表
     *
     * @return array CDN 域名列表
     */
    public function get_cdn_domains(): array
    {
        return $this->cdn_domains;
    }
}

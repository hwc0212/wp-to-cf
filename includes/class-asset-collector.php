<?php
/**
 * 资产采集器类
 * 
 * 自动提取并下载 HTML 中引用的图片、CSS 和 JS 文件
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_Asset_Collector
 * 
 * 采集并本地化静态资产
 */
class WP_to_CF_Asset_Collector
{
    /**
     * 缓存目录
     *
     * @var string
     */
    private string $cache_dir;
    
    /**
     * 内网脱敏器实例
     *
     * @var WP_to_CF_Intranet_Sanitizer|null
     */
    private ?WP_to_CF_Intranet_Sanitizer $sanitizer = null;
    
    /**
     * 采集统计
     *
     * @var array
     */
    private array $stats = [
        'images' => 0,
        'css' => 0,
        'js' => 0,
        'failed' => 0,
        'total_size' => 0,
        'duplicates_skipped' => 0,
    ];
    
    /**
     * 文件内容哈希映射表 [hash => 本地路径]
     * 用于去重：相同内容的文件只保存一份
     *
     * @var array
     */
    private array $content_hash_map = [];
    
    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->cache_dir = WP_CONTENT_DIR . '/wptocf-cache';
        
        // 确保缓存目录存在
        if (!file_exists($this->cache_dir)) {
            wp_mkdir_p($this->cache_dir);
            wp_mkdir_p($this->cache_dir . '/images');
            wp_mkdir_p($this->cache_dir . '/css');
            wp_mkdir_p($this->cache_dir . '/js');
            wp_mkdir_p($this->cache_dir . '/fonts');
        }
        
        // 初始化脱敏器（用于清理下载的 CSS/JS 文件）
        $production_domain = get_option('wptocf_production_domain', '');
        $cloudflare_domain = get_option('wptocf_cloudflare_domain', '');
        
        if (!empty($production_domain) && !empty($cloudflare_domain)) {
            $this->sanitizer = new WP_to_CF_Intranet_Sanitizer($production_domain, $cloudflare_domain);
        }
    }
    
    /**
     * 采集 HTML 中的所有资产
     *
     * @param string $html     HTML 内容
     * @param string $base_url 基础 URL（用于解析相对路径）
     * @return string 更新后的 HTML（资产 URL 已替换为本地路径）
     */
    public function collect_assets(string $html, string $base_url): string
    {
        WP_to_CF_Logger::info('Starting asset collection', [
            'base_url' => $base_url,
        ]);
        
        // 1. 采集图片
        $html = $this->collect_images($html, $base_url);
        
        // 2. 采集 CSS
        $html = $this->collect_css($html, $base_url);
        
        // 3. 采集 JS
        $html = $this->collect_js($html, $base_url);
        
        WP_to_CF_Logger::info('Asset collection completed', [
            'stats' => $this->stats,
        ]);
        
        return $html;
    }
    
    /**
     * 采集图片
     *
     * @param string $html     HTML 内容
     * @param string $base_url 基础 URL
     * @return string 更新后的 HTML
     */
    private function collect_images(string $html, string $base_url): string
    {
        // 匹配 <img> 标签的 src 属性
        $pattern = '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i';
        
        $html = preg_replace_callback($pattern, function($matches) use ($base_url) {
            $full_match = $matches[0];
            $src = $matches[1];
            
            // 下载并保存图片
            $local_path = $this->download_asset($src, $base_url, 'images');
            
            if ($local_path) {
                // 替换 URL（临时使用本地路径，稍后由 Path Whitewash 洗白）
                $new_src = $this->get_public_url($local_path);
                $full_match = str_replace($src, $new_src, $full_match);
                
                // 处理 srcset 属性（响应式图片）
                if (preg_match('/srcset=["\']([^"\']+)["\']/i', $full_match, $srcset_matches)) {
                    $srcset = $srcset_matches[1];
                    $new_srcset = $this->process_srcset($srcset, $base_url);
                    $full_match = str_replace($srcset, $new_srcset, $full_match);
                }
                
                return $full_match;
            }
            
            return $full_match;
        }, $html);
        
        return $html;
    }
    
    /**
     * 处理 srcset 属性（响应式图片）
     *
     * @param string $srcset   srcset 属性值
     * @param string $base_url 基础 URL
     * @return string 处理后的 srcset
     */
    private function process_srcset(string $srcset, string $base_url): string
    {
        // srcset 格式：url1 1x, url2 2x 或 url1 100w, url2 200w
        $sources = preg_split('/,\s*/', $srcset);
        $new_sources = [];
        
        foreach ($sources as $source) {
            // 分离 URL 和描述符
            if (preg_match('/^(.+?)\s+(.+)$/', trim($source), $matches)) {
                $url = $matches[1];
                $descriptor = $matches[2];
                
                // 下载并保存图片
                $local_path = $this->download_asset($url, $base_url, 'images');
                
                if ($local_path) {
                    $new_url = $this->get_public_url($local_path);
                    $new_sources[] = $new_url . ' ' . $descriptor;
                } else {
                    $new_sources[] = $source;
                }
            } else {
                $new_sources[] = $source;
            }
        }
        
        return implode(', ', $new_sources);
    }
    
    /**
     * 采集 CSS
     *
     * @param string $html     HTML 内容
     * @param string $base_url 基础 URL
     * @return string 更新后的 HTML
     */
    private function collect_css(string $html, string $base_url): string
    {
        // 匹配 <link rel="stylesheet"> 标签的 href 属性
        $pattern = '/<link[^>]+rel=["\']stylesheet["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i';
        
        $html = preg_replace_callback($pattern, function($matches) use ($base_url) {
            $full_match = $matches[0];
            $href = $matches[1];
            
            // 下载并保存 CSS
            $local_path = $this->download_asset($href, $base_url, 'css');
            
            if ($local_path) {
                // 脱敏 CSS 内容
                $this->sanitize_css_file($local_path);
                
                // 替换 URL
                $new_href = $this->get_public_url($local_path);
                return str_replace($href, $new_href, $full_match);
            }
            
            return $full_match;
        }, $html);
        
        // 也匹配 href 在 rel 之前的情况
        $pattern2 = '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']stylesheet["\'][^>]*>/i';
        
        $html = preg_replace_callback($pattern2, function($matches) use ($base_url) {
            $full_match = $matches[0];
            $href = $matches[1];
            
            // 下载并保存 CSS
            $local_path = $this->download_asset($href, $base_url, 'css');
            
            if ($local_path) {
                // 脱敏 CSS 内容
                $this->sanitize_css_file($local_path);
                
                // 替换 URL
                $new_href = $this->get_public_url($local_path);
                return str_replace($href, $new_href, $full_match);
            }
            
            return $full_match;
        }, $html);
        
        return $html;
    }
    
    /**
     * 采集 JS
     *
     * @param string $html     HTML 内容
     * @param string $base_url 基础 URL
     * @return string 更新后的 HTML
     */
    private function collect_js(string $html, string $base_url): string
    {
        // 匹配 <script> 标签的 src 属性
        $pattern = '/<script[^>]+src=["\']([^"\']+)["\'][^>]*><\/script>/i';
        
        $html = preg_replace_callback($pattern, function($matches) use ($base_url) {
            $full_match = $matches[0];
            $src = $matches[1];
            
            // 下载并保存 JS
            $local_path = $this->download_asset($src, $base_url, 'js');
            
            if ($local_path) {
                // 脱敏 JS 内容
                $this->sanitize_js_file($local_path);
                
                // 替换 URL
                $new_src = $this->get_public_url($local_path);
                return str_replace($src, $new_src, $full_match);
            }
            
            return $full_match;
        }, $html);
        
        return $html;
    }
    
    /**
     * 下载资产文件
     *
     * @param string $url      资产 URL
     * @param string $base_url 基础 URL
     * @param string $type     资产类型（images/css/js）
     * @return string|false 本地文件路径，失败返回 false
     */
    private function download_asset(string $url, string $base_url, string $type): string|false
    {
        // 解析完整 URL
        $full_url = $this->resolve_url($url, $base_url);
        
        if (!$full_url) {
            return false;
        }
        
        // 拦截冗余资产（admin-bar, dashicons, wp-embed）
        if ($this->is_blocked_asset($full_url)) {
            WP_to_CF_Logger::info('Blocked redundant asset', [
                'url' => $full_url,
                'reason' => 'admin-bar/dashicons/wp-embed',
            ]);
            return false;
        }
        
        // 白名单：保护第三方远程链接
        if ($this->is_whitelisted_remote($full_url)) {
            WP_to_CF_Logger::info('Whitelisted remote asset (skipped download)', [
                'url' => $full_url,
            ]);
            return false;
        }
        
        // 生成本地文件名
        $filename = $this->generate_filename($full_url);
        $local_path = $this->cache_dir . '/' . $type . '/' . $filename;
        
        // 如果文件已存在，直接返回
        if (file_exists($local_path)) {
            // 计算已存在文件的哈希，加入映射表
            $existing_hash = md5_file($local_path);
            if ($existing_hash && !isset($this->content_hash_map[$existing_hash])) {
                $this->content_hash_map[$existing_hash] = $local_path;
            }
            
            WP_to_CF_Logger::info('Asset already cached', [
                'url' => $full_url,
                'local_path' => $local_path,
            ]);
            return $local_path;
        }
        
        // 下载文件
        $response = wp_remote_get($full_url, [
            'timeout' => 30,
            'sslverify' => false,
        ]);
        
        if (is_wp_error($response)) {
            WP_to_CF_Logger::error('Failed to download asset', [
                'url' => $full_url,
                'error' => $response->get_error_message(),
            ]);
            $this->stats['failed']++;
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            WP_to_CF_Logger::error('Asset download failed', [
                'url' => $full_url,
                'status_code' => $status_code,
            ]);
            $this->stats['failed']++;
            return false;
        }
        
        $content = wp_remote_retrieve_body($response);
        
        if (empty($content)) {
            WP_to_CF_Logger::error('Empty asset content', [
                'url' => $full_url,
            ]);
            $this->stats['failed']++;
            return false;
        }
        
        // 去重检查：基于文件内容哈希
        $content_hash = md5($content);
        
        if (isset($this->content_hash_map[$content_hash])) {
            // 发现重复内容，返回已存在的文件路径
            $existing_path = $this->content_hash_map[$content_hash];
            
            WP_to_CF_Logger::info('Duplicate content detected, reusing existing file', [
                'url' => $full_url,
                'existing_file' => basename($existing_path),
                'new_file' => $filename,
                'content_hash' => $content_hash,
                'size' => strlen($content),
            ]);
            
            $this->stats['duplicates_skipped']++;
            
            return $existing_path;
        }
        
        // 保存文件
        $result = file_put_contents($local_path, $content);
        
        if ($result === false) {
            WP_to_CF_Logger::error('Failed to save asset', [
                'url' => $full_url,
                'local_path' => $local_path,
            ]);
            $this->stats['failed']++;
            return false;
        }
        
        // 更新统计
        $this->stats[$type]++;
        $this->stats['total_size'] += strlen($content);
        
        // 记录到哈希映射表（用于后续去重）
        $this->content_hash_map[$content_hash] = $local_path;
        
        WP_to_CF_Logger::info('Asset downloaded successfully', [
            'url' => $full_url,
            'local_path' => $local_path,
            'size' => strlen($content),
            'content_hash' => $content_hash,
        ]);
        
        return $local_path;
    }
    
    /**
     * 解析完整 URL
     *
     * @param string $url      原始 URL（可能是相对路径）
     * @param string $base_url 基础 URL
     * @return string|false 完整 URL，失败返回 false
     */
    private function resolve_url(string $url, string $base_url): string|false
    {
        // 如果已经是完整 URL，直接返回
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }
        
        // 如果是协议相对 URL（//example.com/...）
        if (strpos($url, '//') === 0) {
            return 'https:' . $url;
        }
        
        // 如果是绝对路径（/path/to/file）
        if (strpos($url, '/') === 0) {
            $parsed = parse_url($base_url);
            return $parsed['scheme'] . '://' . $parsed['host'] . $url;
        }
        
        // 相对路径，拼接基础 URL
        return rtrim($base_url, '/') . '/' . ltrim($url, '/');
    }
    
    /**
     * 生成本地文件名（语义化图片重命名）
     *
     * @param string $url 资产 URL
     * @return string 文件名
     */
    private function generate_filename(string $url): string
    {
        // 提取文件名
        $path = parse_url($url, PHP_URL_PATH);
        $filename = basename($path);
        
        // 如果文件名为空或太长，使用 hash
        if (empty($filename) || strlen($filename) > 100) {
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            $filename = md5($url) . ($ext ? '.' . $ext : '');
        }
        
        // 清理文件名
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        
        // 图片语义化重命名：从 URL 中提取真实日期
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp', 'avif'];
        
        if (in_array(strtolower($ext), $image_exts)) {
            $basename = pathinfo($filename, PATHINFO_FILENAME);
            
            // 从 URL 中提取真实日期 (YYYY/MM)
            $date_suffix = $this->extract_date_from_url($url);
            
            // 如果无法提取日期，使用文件修改时间作为后备
            if (!$date_suffix) {
                // 尝试从文件名中提取日期模式
                if (preg_match('/(\d{4})-(\d{2})/', $basename, $matches)) {
                    $date_suffix = $matches[1] . '-' . $matches[2];
                } else {
                    // 最后后备：使用当前年月
                    $date_suffix = date('Y-m');
                }
            }
            
            // 处理响应式图片尺寸（如 photo-150x150.jpg）
            if (preg_match('/^(.+)-(\d+x\d+)$/', $basename, $matches)) {
                // 格式：photo-150x150-2024-01.jpg
                $filename = $matches[1] . '-' . $matches[2] . '-' . $date_suffix . '.' . $ext;
            } else {
                // 格式：photo-2024-01.jpg
                $filename = $basename . '-' . $date_suffix . '.' . $ext;
            }
            
            WP_to_CF_Logger::info('Image semantic rename applied', [
                'original_url' => $url,
                'extracted_date' => $date_suffix,
                'semantic_filename' => $filename,
            ]);
        }
        
        return $filename;
    }
    
    /**
     * 从 URL 中提取真实日期
     * 
     * @param string $url 原始 URL
     * @return string|null 日期字符串 (YYYY-MM) 或 null
     */
    private function extract_date_from_url(string $url): ?string
    {
        // 正则模式：匹配 /uploads/YYYY/MM/ 格式
        $pattern = '#/uploads/(\d{4})/(\d{2})/#i';
        
        if (preg_match($pattern, $url, $matches)) {
            $year = $matches[1];
            $month = $matches[2];
            
            // 验证日期有效性
            if ($year >= 2000 && $year <= 2099 && $month >= 1 && $month <= 12) {
                return $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT);
            }
        }
        
        return null;
    }
    
    /**
     * 检测是否为需要拦截的冗余资产
     * 
     * @param string $url URL
     * @return bool
     */
    private function is_blocked_asset(string $url): bool
    {
        $blocked_patterns = [
            '#/admin-bar[/\.]#i',
            '#/dashicons[/\.]#i',
            '#/wp-embed[/\.]#i',
            '#admin-bar\.min\.(css|js)#i',
            '#dashicons\.min\.css#i',
            '#wp-embed\.min\.js#i',
        ];
        
        foreach ($blocked_patterns as $pattern) {
            if (preg_match($pattern, $url)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检测是否为白名单远程资源
     * 
     * @param string $url URL
     * @return bool
     */
    private function is_whitelisted_remote(string $url): bool
    {
        $whitelist_patterns = [
            '#//challenges\.cloudflare\.com/#i',
            '#//fonts\.googleapis\.com/#i',
            '#//fonts\.gstatic\.com/#i',
            '#//cdn\.jsdelivr\.net/#i',
            '#//cdnjs\.cloudflare\.com/#i',
        ];
        
        foreach ($whitelist_patterns as $pattern) {
            if (preg_match($pattern, $url)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 获取资产的公网 URL
     *
     * @param string $local_path 本地文件路径
     * @return string 相对路径 URL（用于 Cloudflare Pages）
     */
    private function get_public_url(string $local_path): string
    {
        // 将本地路径转换为相对于 wp-content 的路径
        $relative_path = str_replace(WP_CONTENT_DIR, '', $local_path);
        
        // 标准化路径分隔符（Windows 兼容）
        $relative_path = str_replace('\\', '/', $relative_path);
        
        // 返回相对路径（Cloudflare Pages 会自动处理）
        // 格式：/wp-content/wptocf-cache/images/xxx.jpg
        return '/wp-content' . $relative_path;
    }
    
    /**
     * 脱敏 CSS 文件内容
     *
     * @param string $file_path 文件路径
     * @return void
     */
    private function sanitize_css_file(string $file_path): void
    {
        if (!file_exists($file_path)) {
            return;
        }
        
        $content = file_get_contents($file_path);
        
        if ($content === false) {
            return;
        }
        
        // 1. 脱敏内网信息
        if ($this->sanitizer) {
            $content = $this->sanitizer->sanitize_html($content);
        }
        
        // 2. 深度洗白：清洗 JSON 格式的 WP 路径
        $content = $this->deep_clean_content($content);
        
        // 保存脱敏后的内容
        file_put_contents($file_path, $content);
        
        WP_to_CF_Logger::info('CSS file sanitized and deep cleaned', [
            'file_path' => $file_path,
        ]);
    }
    
    /**
     * 脱敏 JS 文件内容
     *
     * @param string $file_path 文件路径
     * @return void
     */
    private function sanitize_js_file(string $file_path): void
    {
        if (!file_exists($file_path)) {
            return;
        }
        
        $content = file_get_contents($file_path);
        
        if ($content === false) {
            return;
        }
        
        // 1. 脱敏内网信息
        if ($this->sanitizer) {
            $content = $this->sanitizer->sanitize_html($content);
        }
        
        // 2. 深度洗白：清洗 JSON 格式的 WP 路径
        $content = $this->deep_clean_content($content);
        
        // 3. 擦除 Ajax 端点
        $content = $this->erase_ajax_endpoints($content);
        
        // 保存脱敏后的内容
        file_put_contents($file_path, $content);
        
        WP_to_CF_Logger::info('JS file sanitized, deep cleaned, and Ajax erased', [
            'file_path' => $file_path,
        ]);
    }
    
    /**
     * 深度清洗内容（JSON 格式的 WP 路径）
     * 
     * @param string $content 内容
     * @return string 清洗后的内容
     */
    private function deep_clean_content(string $content): string
    {
        // 1. JSON 转义格式：https:\/\/domain.com\/wp-content\/ → \/
        $content = preg_replace(
            '#https?:\\\\/\\\\/[^/\\\\"]+\\\\/wp-content\\\\/uploads\\\\/#i',
            '\\/images\\/',
            $content
        );
        
        $content = preg_replace(
            '#https?:\\\\/\\\\/[^/\\\\"]+\\\\/wp-content\\\\/themes\\\\/[^\\\\/]+\\\\/#i',
            '\\/css\\/',
            $content
        );
        
        $content = preg_replace(
            '#https?:\\\\/\\\\/[^/\\\\"]+\\\\/wp-content\\\\/plugins\\\\/[^\\\\/]+\\\\/#i',
            '\\/js\\/',
            $content
        );
        
        // 2. JSON 转义格式：\/wp-content\/ → \/images\/
        $content = preg_replace(
            '#\\\\/wp-content\\\\/uploads\\\\/#i',
            '\\/images\\/',
            $content
        );
        
        $content = preg_replace(
            '#\\\\/wp-content\\\\/themes\\\\/[^\\\\/]+\\\\/#i',
            '\\/css\\/',
            $content
        );
        
        $content = preg_replace(
            '#\\\\/wp-content\\\\/plugins\\\\/[^\\\\/]+\\\\/#i',
            '\\/js\\/',
            $content
        );
        
        // 3. 标准格式：/wp-content/ → /images/
        $content = preg_replace(
            '#/wp-content/uploads/#i',
            '/images/',
            $content
        );
        
        $content = preg_replace(
            '#/wp-content/themes/[^/]+/#i',
            '/css/',
            $content
        );
        
        $content = preg_replace(
            '#/wp-content/plugins/[^/]+/#i',
            '/js/',
            $content
        );
        
        // 4. wp-includes 路径
        $content = preg_replace(
            '#\\\\/wp-includes\\\\/#i',
            '\\/assets\\/',
            $content
        );
        
        $content = preg_replace(
            '#/wp-includes/#i',
            '/assets/',
            $content
        );
        
        return $content;
    }
    
    /**
     * 擦除 Ajax 端点
     * 
     * @param string $content 内容
     * @return string 清洗后的内容
     */
    private function erase_ajax_endpoints(string $content): string
    {
        // 1. admin-ajax.php → /
        $content = preg_replace(
            '#https?://[^/]+/wp-admin/admin-ajax\.php#i',
            '/',
            $content
        );
        
        $content = preg_replace(
            '#/wp-admin/admin-ajax\.php#i',
            '/',
            $content
        );
        
        // 2. JSON 转义格式
        $content = preg_replace(
            '#https?:\\\\/\\\\/[^/\\\\"]+\\\\/wp-admin\\\\/admin-ajax\\.php#i',
            '\\/',
            $content
        );
        
        $content = preg_replace(
            '#\\\\/wp-admin\\\\/admin-ajax\\.php#i',
            '\\/',
            $content
        );
        
        // 3. xmlrpc.php
        $content = preg_replace(
            '#https?://[^/]+/xmlrpc\.php#i',
            '/',
            $content
        );
        
        $content = preg_replace(
            '#/xmlrpc\.php#i',
            '/',
            $content
        );
        
        // 4. wp-json API
        $content = preg_replace(
            '#https?://[^/]+/wp-json/[^"\'\s]*#i',
            '/',
            $content
        );
        
        $content = preg_replace(
            '#/wp-json/[^"\'\s]*#i',
            '/',
            $content
        );
        
        return $content;
    }
    
    /**
     * 获取采集统计
     *
     * @return array 统计信息
     */
    public function get_stats(): array
    {
        return $this->stats;
    }
    
    /**
     * 清理缓存目录
     *
     * @return bool 是否成功
     */
    public function clear_cache(): bool
    {
        if (!file_exists($this->cache_dir)) {
            return true;
        }
        
        // 递归删除目录
        $this->remove_directory($this->cache_dir);
        
        // 重新创建目录
        wp_mkdir_p($this->cache_dir);
        wp_mkdir_p($this->cache_dir . '/images');
        wp_mkdir_p($this->cache_dir . '/css');
        wp_mkdir_p($this->cache_dir . '/js');
        
        WP_to_CF_Logger::info('Asset cache cleared');
        
        return true;
    }
    
    /**
     * 递归删除目录
     *
     * @param string $dir 目录路径
     * @return void
     */
    private function remove_directory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            
            if (is_dir($path)) {
                $this->remove_directory($path);
            } else {
                unlink($path);
            }
        }
        
        rmdir($dir);
    }
}

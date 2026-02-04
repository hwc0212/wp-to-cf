<?php
/**
 * 通用洗白引擎类
 * 
 * 处理 CSS、WooCommerce、Elementor 等所有路径洗白场景
 * 支持多属性扫描、JSON 递归洗白、物理验证
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_Universal_Whitewash
 * 
 * 通用洗白引擎
 */
class WP_to_CF_Universal_Whitewash
{
    /**
     * 缓存目录
     *
     * @var string
     */
    private string $cache_dir;
    
    /**
     * 统计信息
     *
     * @var array
     */
    private array $stats = [
        'css_files_processed' => 0,
        'inline_styles_processed' => 0,
        'json_attributes_processed' => 0,
        'srcset_processed' => 0,
        'data_attributes_processed' => 0,
        'paths_whitewashed' => 0,
        'paths_skipped' => 0,
        'cache_leaks_removed' => 0,
        'fonts_localized' => 0,
        'litespeed_neutralized' => 0,
        'dynamic_requests_blocked' => 0,
    ];
    
    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->cache_dir = WP_CONTENT_DIR . '/wptocf-cache';
        
        // 确保 fonts 目录存在
        if (!file_exists($this->cache_dir . '/fonts')) {
            wp_mkdir_p($this->cache_dir . '/fonts');
        }
    }
    
    /**
     * 通用 HTML 洗白入口
     * 
     * @param string $html HTML 内容
     * @return string 洗白后的 HTML
     */
    public function whitewash_html(string $html): string
    {
        WP_to_CF_Logger::info('Starting Universal Whitewashing Engine');
        
        // 0. LiteSpeed 中和：还原 type="litespeed/javascript"
        $html = $this->neutralize_litespeed($html);
        
        // 1. 处理所有 data-* 属性
        $html = $this->whitewash_data_attributes($html);
        
        // 2. 处理 srcset 属性
        $html = $this->whitewash_srcset_attributes($html);
        
        // 3. 处理内联 style 属性
        $html = $this->whitewash_inline_styles($html);
        
        // 4. 处理 <style> 标签
        $html = $this->whitewash_style_tags($html);
        
        // 5. 拦截动态请求：中和 POST/fetch/XHR
        $html = $this->neutralize_dynamic_requests($html);
        
        // 6. 零泄漏：移除所有缓存路径引用
        $html = $this->remove_cache_leaks($html);
        
        WP_to_CF_Logger::info('Universal Whitewashing completed', [
            'stats' => $this->stats,
        ]);
        
        return $html;
    }
    
    /**
     * 洗白 CSS 文件
     * 
     * @param string $css_file_path CSS 文件路径
     * @return bool 是否成功
     */
    public function whitewash_css_file(string $css_file_path): bool
    {
        if (!file_exists($css_file_path)) {
            WP_to_CF_Logger::warning('CSS file not found', [
                'path' => $css_file_path,
            ]);
            return false;
        }
        
        $content = file_get_contents($css_file_path);
        
        if ($content === false) {
            return false;
        }
        
        // 洗白 CSS 中的 url()
        $content = $this->whitewash_css_urls($content);
        
        // 零泄漏
        $content = $this->remove_cache_leaks($content);
        
        // 保存
        $result = file_put_contents($css_file_path, $content);
        
        if ($result !== false) {
            $this->stats['css_files_processed']++;
            
            WP_to_CF_Logger::info('CSS file whitewashed', [
                'path' => $css_file_path,
            ]);
        }
        
        return $result !== false;
    }

    
    /**
     * 洗白 CSS 中的 url()
     * 
     * @param string $css CSS 内容
     * @return string 洗白后的 CSS
     */
    private function whitewash_css_urls(string $css): string
    {
        // 正则：url\s*\(\s*["']?([^"'\)]+)["']?\s*\)
        // 支持：url(path), url("path"), url('path')
        $css = preg_replace_callback(
            '#url\s*\(\s*["\']?([^"\'\)]+)["\']?\s*\)#i',
            function($matches) {
                $original_url = trim($matches[1]);
                
                // 跳过：data URI, 外部 URL, 已洗白路径
                if ($this->should_skip_url($original_url)) {
                    return $matches[0];
                }
                
                // 检查是否为字体文件
                if ($this->is_font_file($original_url)) {
                    // 字体文件：下载并本地化
                    $whitewashed_url = $this->localize_font($original_url);
                } else {
                    // 普通资产：洗白路径
                    $whitewashed_url = $this->whitewash_single_path($original_url);
                }
                
                // 如果洗白失败，保留原路径
                if ($whitewashed_url === $original_url) {
                    $this->stats['paths_skipped']++;
                    return $matches[0];
                }
                
                $this->stats['paths_whitewashed']++;
                
                // 返回洗白后的 url()
                return 'url(' . $whitewashed_url . ')';
            },
            $css
        );
        
        return $css;
    }
    
    /**
     * 检查是否为字体文件
     * 
     * @param string $url URL
     * @return bool
     */
    private function is_font_file(string $url): bool
    {
        return (bool) preg_match('#\.(woff2?|ttf|eot|otf)(\?.*)?$#i', $url);
    }
    
    /**
     * 本地化字体文件
     * 
     * @param string $font_url 字体 URL
     * @return string 本地化后的路径
     */
    private function localize_font(string $font_url): string
    {
        // 移除查询参数
        $clean_url = preg_replace('/[?#].*$/', '', $font_url);
        
        // 提取文件名
        $filename = basename($clean_url);
        
        // 清理文件名
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        
        // 构建本地路径
        $local_path = $this->cache_dir . '/fonts/' . $filename;
        
        // 如果文件已存在，直接返回
        if (file_exists($local_path)) {
            WP_to_CF_Logger::info('Font already localized', [
                'url' => $font_url,
                'local_path' => $local_path,
            ]);
            return '/fonts/' . $filename;
        }
        
        // 解析完整 URL
        $full_url = $this->resolve_font_url($font_url);
        
        if (!$full_url) {
            WP_to_CF_Logger::warning('Failed to resolve font URL', [
                'url' => $font_url,
            ]);
            return $font_url;
        }
        
        // 下载字体文件
        $response = wp_remote_get($full_url, [
            'timeout' => 30,
            'sslverify' => false,
        ]);
        
        if (is_wp_error($response)) {
            WP_to_CF_Logger::error('Failed to download font', [
                'url' => $full_url,
                'error' => $response->get_error_message(),
            ]);
            return $font_url;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            WP_to_CF_Logger::error('Font download failed', [
                'url' => $full_url,
                'status_code' => $status_code,
            ]);
            return $font_url;
        }
        
        $content = wp_remote_retrieve_body($response);
        
        if (empty($content)) {
            WP_to_CF_Logger::error('Empty font content', [
                'url' => $full_url,
            ]);
            return $font_url;
        }
        
        // 保存字体文件
        $result = file_put_contents($local_path, $content);
        
        if ($result === false) {
            WP_to_CF_Logger::error('Failed to save font', [
                'url' => $full_url,
                'local_path' => $local_path,
            ]);
            return $font_url;
        }
        
        WP_to_CF_Logger::info('Font localized successfully', [
            'url' => $full_url,
            'local_path' => $local_path,
            'size' => strlen($content),
        ]);
        
        // 更新统计
        $this->stats['fonts_localized']++;
        
        // 返回本地化路径（根相对路径）
        return '/fonts/' . $filename;
    }
    
    /**
     * 解析字体 URL
     * 
     * @param string $url 原始 URL
     * @return string|false 完整 URL
     */
    private function resolve_font_url(string $url): string|false
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
            $home_url = home_url();
            $parsed = parse_url($home_url);
            return $parsed['scheme'] . '://' . $parsed['host'] . $url;
        }
        
        // 相对路径：无法解析
        WP_to_CF_Logger::warning('Cannot resolve relative font URL', [
            'url' => $url,
        ]);
        
        return false;
    }
    
    /**
     * LiteSpeed 中和：还原 type="litespeed/javascript"
     * 
     * @param string $html HTML 内容
     * @return string 中和后的 HTML
     */
    private function neutralize_litespeed(string $html): string
    {
        WP_to_CF_Logger::info('Neutralizing LiteSpeed tags');
        
        // 1. 还原 type="litespeed/javascript" → type="text/javascript"
        $html = preg_replace(
            '#type=["\']litespeed/javascript["\']#i',
            'type="text/javascript"',
            $html
        );
        
        // 2. 移除 data-optimized 属性
        $html = preg_replace(
            '#\s+data-optimized=["\'][^"\']*["\']#i',
            '',
            $html
        );
        
        $this->stats['litespeed_neutralized']++;
        
        WP_to_CF_Logger::info('LiteSpeed neutralization completed');
        
        return $html;
    }
    
    /**
     * 中和动态请求：拦截 POST/fetch/XHR
     * 
     * @param string $html HTML 内容
     * @return string 中和后的 HTML
     */
    private function neutralize_dynamic_requests(string $html): string
    {
        WP_to_CF_Logger::info('Neutralizing dynamic requests');
        
        // 在 </body> 前注入拦截脚本
        $neutralizer_script = <<<'SCRIPT'
<script type="text/javascript">
(function() {
    // 拦截 fetch
    if (typeof window.fetch !== 'undefined') {
        const originalFetch = window.fetch;
        window.fetch = function(url, options) {
            // 检查是否为 POST 请求或指向 admin-ajax.php
            if ((options && options.method === 'POST') || 
                (typeof url === 'string' && (url.includes('admin-ajax.php') || url.includes('/assets/')))) {
                console.warn('[WP-to-CF] Blocked dynamic request:', url);
                return Promise.resolve(new Response('{}', {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' }
                }));
            }
            return originalFetch.apply(this, arguments);
        };
    }
    
    // 拦截 XMLHttpRequest
    if (typeof window.XMLHttpRequest !== 'undefined') {
        const originalOpen = XMLHttpRequest.prototype.open;
        XMLHttpRequest.prototype.open = function(method, url) {
            // 检查是否为 POST 请求或指向 admin-ajax.php
            if (method.toUpperCase() === 'POST' || 
                (typeof url === 'string' && (url.includes('admin-ajax.php') || url.includes('/assets/')))) {
                console.warn('[WP-to-CF] Blocked XHR request:', url);
                // 重定向到空端点
                arguments[1] = 'data:application/json,{}';
            }
            return originalOpen.apply(this, arguments);
        };
    }
})();
</script>
SCRIPT;
        
        // 在 </body> 前注入
        $html = preg_replace(
            '#</body>#i',
            $neutralizer_script . "\n</body>",
            $html,
            1
        );
        
        $this->stats['dynamic_requests_blocked']++;
        
        WP_to_CF_Logger::info('Dynamic request neutralization completed');
        
        return $html;
    }
    
    /**
     * 洗白单个路径
     * 
     * @param string $path 原始路径
     * @return string 洗白后的路径
     */
    private function whitewash_single_path(string $path): string
    {
        // 移除查询参数和锚点
        $clean_path = preg_replace('/[?#].*$/', '', $path);
        
        // 1. 图片路径：/wp-content/uploads/YYYY/MM/filename.ext → /images/filename-YYYY-MM.ext
        if (preg_match('#/wp-content/uploads/(\d{4})/(\d{2})/([^/"\s\?]+)\.([a-z0-9]+)$#i', $clean_path, $matches)) {
            $year = $matches[1];
            $month = $matches[2];
            $filename = $matches[3];
            $ext = $matches[4];
            
            $whitewashed = '/images/' . $filename . '-' . $year . '-' . $month . '.' . $ext;
            
            // 物理验证
            if ($this->validate_physical_file($whitewashed)) {
                WP_to_CF_Logger::info('Image path whitewashed', [
                    'original' => $path,
                    'whitewashed' => $whitewashed,
                ]);
                return $whitewashed;
            }
        }
        
        // 2. 特殊情况：images/YYYY/MM/filename.ext → /images/filename-YYYY-MM.ext
        if (preg_match('#^/?images?/(\d{4})/(\d{2})/([^/"\s\?]+)\.([a-z0-9]+)$#i', $clean_path, $matches)) {
            $year = $matches[1];
            $month = $matches[2];
            $filename = $matches[3];
            $ext = $matches[4];
            
            $whitewashed = '/images/' . $filename . '-' . $year . '-' . $month . '.' . $ext;
            
            // 物理验证
            if ($this->validate_physical_file($whitewashed)) {
                WP_to_CF_Logger::info('Special image path whitewashed', [
                    'original' => $path,
                    'whitewashed' => $whitewashed,
                ]);
                return $whitewashed;
            }
        }
        
        // 3. CSS 文件：/wp-content/themes/xxx/style.css → /css/t-style.hash.css
        if (preg_match('#/wp-content/themes/[^/]+/(.+\.css)$#i', $clean_path, $matches)) {
            $filename = basename($matches[1]);
            $hash = substr(md5($path), 0, 8);
            $whitewashed = '/css/t-' . pathinfo($filename, PATHINFO_FILENAME) . '.' . $hash . '.css';
            
            // 物理验证
            if ($this->validate_physical_file($whitewashed)) {
                return $whitewashed;
            }
        }
        
        // 4. JS 文件：/wp-content/plugins/xxx/script.js → /js/p-script.hash.js
        if (preg_match('#/wp-content/plugins/[^/]+/(.+\.js)$#i', $clean_path, $matches)) {
            $filename = basename($matches[1]);
            $hash = substr(md5($path), 0, 8);
            $whitewashed = '/js/p-' . pathinfo($filename, PATHINFO_FILENAME) . '.' . $hash . '.js';
            
            // 物理验证
            if ($this->validate_physical_file($whitewashed)) {
                return $whitewashed;
            }
        }
        
        // 5. 字体文件：/wp-content/themes/xxx/fonts/font.woff2 → /fonts/font.woff2
        if (preg_match('#/wp-content/(?:themes|plugins)/[^/]+/.*(\.(?:woff2?|ttf|eot|otf))$#i', $clean_path, $matches)) {
            $filename = basename($clean_path);
            $whitewashed = '/fonts/' . $filename;
            
            // 物理验证
            if ($this->validate_physical_file($whitewashed)) {
                return $whitewashed;
            }
        }
        
        // 洗白失败：保留原路径
        return $path;
    }
    
    /**
     * 物理验证：检查文件是否存在
     * 
     * @param string $whitewashed_path 洗白后的路径
     * @return bool 文件是否存在
     */
    private function validate_physical_file(string $whitewashed_path): bool
    {
        // 构建物理路径
        $physical_path = $this->cache_dir . $whitewashed_path;
        
        return file_exists($physical_path);
    }
    
    /**
     * 检查是否应跳过 URL
     * 
     * @param string $url URL
     * @return bool 是否跳过
     */
    private function should_skip_url(string $url): bool
    {
        // 跳过 data URI
        if (strpos($url, 'data:') === 0) {
            return true;
        }
        
        // 跳过外部 URL
        if (preg_match('#^https?://#i', $url)) {
            return true;
        }
        
        // 跳过已洗白路径
        if (preg_match('#^/(images|css|js|fonts)/#', $url)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 洗白 data-* 属性
     * 
     * @param string $html HTML 内容
     * @return string 洗白后的 HTML
     */
    private function whitewash_data_attributes(string $html): string
    {
        // 目标属性列表
        $data_attributes = [
            'data-src',
            'data-thumb',
            'data-thumb-srcset',
            'data-large_image',
            'data-large-image',
            'data-settings',  // JSON
            'data-config',    // JSON
        ];
        
        foreach ($data_attributes as $attr) {
            // JSON 属性特殊处理
            if (in_array($attr, ['data-settings', 'data-config'])) {
                $html = $this->whitewash_json_attribute($html, $attr);
            } else {
                // 普通属性
                $html = $this->whitewash_simple_attribute($html, $attr);
            }
        }
        
        return $html;
    }
    
    /**
     * 洗白简单属性
     * 
     * @param string $html HTML 内容
     * @param string $attr 属性名
     * @return string 洗白后的 HTML
     */
    private function whitewash_simple_attribute(string $html, string $attr): string
    {
        $pattern = '#(' . preg_quote($attr, '#') . '=["\'])([^"\']+)(["\'])#i';
        
        $html = preg_replace_callback($pattern, function($matches) use ($attr) {
            $prefix = $matches[1];
            $value = $matches[2];
            $suffix = $matches[3];
            
            // 如果包含逗号，可能是 srcset 格式
            if (strpos($value, ',') !== false) {
                $value = $this->whitewash_srcset_value($value);
            } else {
                $value = $this->whitewash_single_path($value);
            }
            
            $this->stats['data_attributes_processed']++;
            
            return $prefix . $value . $suffix;
        }, $html);
        
        return $html;
    }

    
    /**
     * 洗白 JSON 属性
     * 
     * @param string $html HTML 内容
     * @param string $attr 属性名
     * @return string 洗白后的 HTML
     */
    private function whitewash_json_attribute(string $html, string $attr): string
    {
        $pattern = '#(' . preg_quote($attr, '#') . '=["\'])([^"\']+)(["\'])#i';
        
        $html = preg_replace_callback($pattern, function($matches) use ($attr) {
            $prefix = $matches[1];
            $json_string = $matches[2];
            $suffix = $matches[3];
            
            // HTML 实体解码
            $json_string = html_entity_decode($json_string, ENT_QUOTES | ENT_HTML5);
            
            // 尝试解码 JSON
            $data = json_decode($json_string, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                // JSON 解码失败，保留原值
                WP_to_CF_Logger::warning('JSON decode failed', [
                    'attr' => $attr,
                    'error' => json_last_error_msg(),
                ]);
                return $matches[0];
            }
            
            // 递归洗白 JSON 数据
            $data = $this->recursive_whitewash_json_data($data);
            
            // 重新编码
            $json_string = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            
            // HTML 实体编码（保持原格式）
            $json_string = htmlspecialchars($json_string, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
            
            $this->stats['json_attributes_processed']++;
            
            return $prefix . $json_string . $suffix;
        }, $html);
        
        return $html;
    }
    
    /**
     * 递归洗白 JSON 数据
     * 
     * @param mixed $data JSON 数据
     * @return mixed 洗白后的数据
     */
    private function recursive_whitewash_json_data($data)
    {
        if (is_array($data)) {
            // 数组：递归处理每个元素
            foreach ($data as $key => $value) {
                $data[$key] = $this->recursive_whitewash_json_data($value);
            }
            return $data;
        } elseif (is_object($data)) {
            // 对象：递归处理每个属性
            foreach ($data as $key => $value) {
                $data->$key = $this->recursive_whitewash_json_data($value);
            }
            return $data;
        } elseif (is_string($data)) {
            // 字符串：检查是否包含 WordPress 路径
            if (strpos($data, '/wp-content/') !== false || strpos($data, '\\/wp-content\\/') !== false) {
                // 洗白路径
                $data = $this->whitewash_string_paths($data);
            }
            return $data;
        } else {
            // 其他类型：直接返回
            return $data;
        }
    }
    
    /**
     * 洗白字符串中的所有路径
     * 
     * @param string $str 字符串
     * @return string 洗白后的字符串
     */
    private function whitewash_string_paths(string $str): string
    {
        // 1. 标准格式：/wp-content/uploads/YYYY/MM/filename.ext
        $str = preg_replace_callback(
            '#/wp-content/uploads/(\d{4})/(\d{2})/([^/"\s\?]+)\.([a-z0-9]+)#i',
            function($matches) {
                $whitewashed = '/images/' . $matches[3] . '-' . $matches[1] . '-' . $matches[2] . '.' . $matches[4];
                
                // 物理验证
                if ($this->validate_physical_file($whitewashed)) {
                    return $whitewashed;
                }
                
                // 验证失败，保留原路径
                return $matches[0];
            },
            $str
        );
        
        // 2. JSON 转义格式：\/wp-content\/uploads\/YYYY\/MM\/filename.ext
        $str = preg_replace_callback(
            '#\\\\/wp-content\\\\/uploads\\\\/(\d{4})\\\\/(\d{2})\\\\/([^/"\s\?\\\\]+)\\.([a-z0-9]+)#i',
            function($matches) {
                $whitewashed = '\\/images\\/' . $matches[3] . '-' . $matches[1] . '-' . $matches[2] . '.' . $matches[4];
                
                // 物理验证（移除转义）
                $physical_path = str_replace('\\/', '/', $whitewashed);
                if ($this->validate_physical_file($physical_path)) {
                    return $whitewashed;
                }
                
                // 验证失败，保留原路径
                return $matches[0];
            },
            $str
        );
        
        // 3. 完整 URL 格式：https://domain.com/wp-content/uploads/YYYY/MM/filename.ext
        $str = preg_replace_callback(
            '#https?://[^/]+/wp-content/uploads/(\d{4})/(\d{2})/([^/"\s\?]+)\.([a-z0-9]+)#i',
            function($matches) {
                $whitewashed = '/images/' . $matches[3] . '-' . $matches[1] . '-' . $matches[2] . '.' . $matches[4];
                
                // 物理验证
                if ($this->validate_physical_file($whitewashed)) {
                    return $whitewashed;
                }
                
                // 验证失败，保留原路径
                return $matches[0];
            },
            $str
        );
        
        // 4. JSON 转义完整 URL：https:\/\/domain.com\/wp-content\/uploads\/YYYY\/MM\/filename.ext
        $str = preg_replace_callback(
            '#https?:\\\\/\\\\/[^/\\\\]+\\\\/wp-content\\\\/uploads\\\\/(\d{4})\\\\/(\d{2})\\\\/([^/"\s\?\\\\]+)\\.([a-z0-9]+)#i',
            function($matches) {
                $whitewashed = '\\/images\\/' . $matches[3] . '-' . $matches[1] . '-' . $matches[2] . '.' . $matches[4];
                
                // 物理验证（移除转义）
                $physical_path = str_replace('\\/', '/', $whitewashed);
                if ($this->validate_physical_file($physical_path)) {
                    return $whitewashed;
                }
                
                // 验证失败，保留原路径
                return $matches[0];
            },
            $str
        );
        
        return $str;
    }
    
    /**
     * 洗白 srcset 属性
     * 
     * @param string $html HTML 内容
     * @return string 洗白后的 HTML
     */
    private function whitewash_srcset_attributes(string $html): string
    {
        $pattern = '#(srcset=["\'])([^"\']+)(["\'])#i';
        
        $html = preg_replace_callback($pattern, function($matches) {
            $prefix = $matches[1];
            $srcset = $matches[2];
            $suffix = $matches[3];
            
            $srcset = $this->whitewash_srcset_value($srcset);
            
            $this->stats['srcset_processed']++;
            
            return $prefix . $srcset . $suffix;
        }, $html);
        
        return $html;
    }
    
    /**
     * 洗白 srcset 值
     * 
     * @param string $srcset srcset 值
     * @return string 洗白后的 srcset
     */
    private function whitewash_srcset_value(string $srcset): string
    {
        // srcset 格式：url1 1x, url2 2x 或 url1 100w, url2 200w
        // 使用 explode 拆分，严禁直接正则替换
        $sources = explode(',', $srcset);
        $new_sources = [];
        
        foreach ($sources as $source) {
            $source = trim($source);
            
            if (empty($source)) {
                continue;
            }
            
            // 分离 URL 和描述符
            $parts = preg_split('/\s+/', $source, 2);
            
            if (count($parts) === 2) {
                // 有描述符：url descriptor
                $url = $parts[0];
                $descriptor = $parts[1];
                
                // 洗白 URL
                $whitewashed_url = $this->whitewash_single_path($url);
                
                $new_sources[] = $whitewashed_url . ' ' . $descriptor;
            } elseif (count($parts) === 1) {
                // 只有 URL，没有描述符
                $url = $parts[0];
                $whitewashed_url = $this->whitewash_single_path($url);
                $new_sources[] = $whitewashed_url;
            }
        }
        
        return implode(', ', $new_sources);
    }
    
    /**
     * 洗白内联 style 属性
     * 
     * @param string $html HTML 内容
     * @return string 洗白后的 HTML
     */
    private function whitewash_inline_styles(string $html): string
    {
        $pattern = '#(style=["\'])([^"\']+)(["\'])#i';
        
        $html = preg_replace_callback($pattern, function($matches) {
            $prefix = $matches[1];
            $style = $matches[2];
            $suffix = $matches[3];
            
            // 洗白 style 中的 url()
            $style = $this->whitewash_css_urls($style);
            
            $this->stats['inline_styles_processed']++;
            
            return $prefix . $style . $suffix;
        }, $html);
        
        return $html;
    }
    
    /**
     * 洗白 <style> 标签
     * 
     * @param string $html HTML 内容
     * @return string 洗白后的 HTML
     */
    private function whitewash_style_tags(string $html): string
    {
        $pattern = '#(<style[^>]*>)(.*?)(</style>)#is';
        
        $html = preg_replace_callback($pattern, function($matches) {
            $open_tag = $matches[1];
            $css = $matches[2];
            $close_tag = $matches[3];
            
            // 洗白 CSS 中的 url()
            $css = $this->whitewash_css_urls($css);
            
            $this->stats['inline_styles_processed']++;
            
            return $open_tag . $css . $close_tag;
        }, $html);
        
        return $html;
    }
    
    /**
     * 移除缓存路径泄漏
     * 
     * @param string $content 内容
     * @return string 清理后的内容
     */
    private function remove_cache_leaks(string $content): string
    {
        $leak_patterns = [
            // 标准格式
            '#/wp-content/wptocf-cache/#i' => '/',
            
            // JSON 转义格式
            '#\\\\/wp-content\\\\/wptocf-cache\\\\/#i' => '\\/',
            
            // 完整 URL
            '#https?://[^/]+/wp-content/wptocf-cache/#i' => '/',
            
            // JSON 转义完整 URL
            '#https?:\\\\/\\\\/[^/\\\\]+\\\\/wp-content\\\\/wptocf-cache\\\\/#i' => '\\/',
        ];
        
        $original_content = $content;
        
        foreach ($leak_patterns as $pattern => $replacement) {
            $content = preg_replace($pattern, $replacement, $content);
        }
        
        // 统计移除次数
        if ($content !== $original_content) {
            $this->stats['cache_leaks_removed']++;
        }
        
        return $content;
    }
    
    /**
     * 批量洗白 CSS 文件
     * 
     * @return int 处理的文件数
     */
    public function whitewash_all_css_files(): int
    {
        $css_dir = $this->cache_dir . '/css';
        
        if (!is_dir($css_dir)) {
            return 0;
        }
        
        $files = glob($css_dir . '/*.css');
        $count = 0;
        
        foreach ($files as $file) {
            if ($this->whitewash_css_file($file)) {
                $count++;
            }
        }
        
        WP_to_CF_Logger::info('Batch CSS whitewashing completed', [
            'files_processed' => $count,
        ]);
        
        return $count;
    }
    
    /**
     * 获取统计信息
     *
     * @return array 统计信息
     */
    public function get_stats(): array
    {
        return $this->stats;
    }
}

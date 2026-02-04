<?php
/**
 * 静态站点导出器
 * 
 * 导出WordPress站点为静态文件，用于Cloudflare Pages部署
 * - 导出HTML页面
 * - 收集CSS、JS、图片等资源到统一目录
 * - 重写所有URL，去除WordPress痕迹
 * - 支持增量导出（通过缓存机制）
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_to_CF_Site_Exporter
{
    private $site_url;
    private $site_host;
    private $collected_assets = [];
    private $path_mapping = [];
    private $used_filenames = [
        'css' => [],
        'js' => [],
        'images' => [],
        'fonts' => [],
    ];
    private $stats = [
        'html_files' => 0,
        'css_files' => 0,
        'js_files' => 0,
        'images' => 0,
        'fonts' => 0,
    ];
    
    /** @var WP_to_CF_Export_Cache */
    private $cache = null;
    
    /** @var bool 是否启用增量导出 */
    private $incremental = false;

    public function __construct()
    {
        $this->site_url = rtrim(home_url(), '/');
        $this->site_host = parse_url($this->site_url, PHP_URL_HOST);
    }
    
    /**
     * 启用增量导出模式
     */
    public function enable_incremental($enable = true)
    {
        $this->incremental = $enable;
        if ($enable && !$this->cache) {
            require_once __DIR__ . '/class-export-cache.php';
            $this->cache = new WP_to_CF_Export_Cache();
        }
        return $this;
    }
    
    /**
     * 获取缓存实例
     */
    public function get_cache()
    {
        if (!$this->cache) {
            require_once __DIR__ . '/class-export-cache.php';
            $this->cache = new WP_to_CF_Export_Cache();
        }
        return $this->cache;
    }

    public function export_site()
    {
        WP_to_CF_Logger::info('开始导出静态站点', ['incremental' => $this->incremental]);
        
        try {
            @set_time_limit(0);
            @ini_set('memory_limit', '512M');
            
            $urls = $this->collect_page_urls();
            WP_to_CF_Logger::info('收集到页面', ['count' => count($urls)]);
            
            $html_files = [];
            foreach ($urls as $url => $file_path) {
                $html = $this->fetch_page($url);
                if ($html) {
                    $this->extract_assets($html);
                    $html_files[$file_path] = $html;
                    $this->stats['html_files']++;
                }
            }
            
            WP_to_CF_Logger::info('发现资源', ['count' => count($this->collected_assets)]);
            
            $asset_files = $this->load_and_remap_assets();
            $asset_files = $this->process_css_urls($asset_files);
            
            foreach ($html_files as $path => $html) {
                $html_files[$path] = $this->process_html($html);
            }
            
            // 合并所有文件用于缓存更新
            $all_files = array_merge($html_files, $asset_files);
            
            // 始终更新缓存（无论是否增量模式）
            $cache = $this->get_cache();
            $cache->update_files($all_files);
            
            $zip_path = $this->create_zip($html_files, $asset_files);
            
            $zip_size = filesize($zip_path);
            $upload_dir = wp_upload_dir();
            $zip_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $zip_path);
            
            WP_to_CF_Logger::info('导出完成', $this->stats);
            
            return [
                'success' => true,
                'zip_path' => $zip_path,
                'zip_url' => $zip_url,
                'file_count' => array_sum($this->stats),
                'zip_size' => $zip_size,
                'zip_size_mb' => round($zip_size / 1024 / 1024, 2),
                'stats' => $this->stats,
            ];
            
        } catch (Exception $e) {
            WP_to_CF_Logger::error('导出失败', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * 导出并直接部署到 Cloudflare（支持增量上传）
     * Cloudflare 会自动跳过已存在的文件（基于 hash）
     */
    public function export_and_deploy()
    {
        WP_to_CF_Logger::info('开始导出并部署');
        
        try {
            @set_time_limit(0);
            @ini_set('memory_limit', '512M');
            
            $urls = $this->collect_page_urls();
            WP_to_CF_Logger::info('收集到页面', ['count' => count($urls)]);
            
            $html_files = [];
            foreach ($urls as $url => $file_path) {
                $html = $this->fetch_page($url);
                if ($html) {
                    $this->extract_assets($html);
                    $html_files[$file_path] = $html;
                    $this->stats['html_files']++;
                }
            }
            
            WP_to_CF_Logger::info('发现资源', ['count' => count($this->collected_assets)]);
            
            $asset_files = $this->load_and_remap_assets();
            $asset_files = $this->process_css_urls($asset_files);
            
            foreach ($html_files as $path => $html) {
                $html_files[$path] = $this->process_html($html);
            }
            
            // 收集 sitemap
            $sitemap_files = $this->collect_sitemaps();
            
            // 生成 robots.txt
            $main_sitemap = $this->determine_main_sitemap($sitemap_files);
            $robots_content = $this->generate_robots_txt(!empty($sitemap_files), $main_sitemap);
            
            // 合并所有文件
            $all_files = array_merge($html_files, $asset_files, $sitemap_files);
            $all_files['robots.txt'] = $robots_content;
            
            // 更新缓存
            if ($this->cache) {
                $this->cache->update_files($all_files);
            }
            
            WP_to_CF_Logger::info('导出完成，开始部署', [
                'total_files' => count($all_files),
                'stats' => $this->stats,
            ]);
            
            // 部署到 Cloudflare（Cloudflare 会自动去重）
            $api = new WP_to_CF_Cloudflare_API();
            if (!$api->is_configured()) {
                return ['success' => false, 'error' => 'Cloudflare API 未配置'];
            }
            
            $deployment_id = $api->create_deployment($all_files);
            
            if ($deployment_id) {
                return [
                    'success' => true,
                    'deployment_id' => $deployment_id,
                    'file_count' => count($all_files),
                    'stats' => $this->stats,
                ];
            } else {
                return ['success' => false, 'error' => '部署失败'];
            }
            
        } catch (Exception $e) {
            WP_to_CF_Logger::error('导出部署失败', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * 确定主 sitemap 文件名
     */
    private function determine_main_sitemap($sitemap_files)
    {
        if (empty($sitemap_files)) {
            return '';
        }
        
        foreach (['sitemap_index.xml', 'wp-sitemap.xml', 'sitemap.xml', 'sitemaps.xml'] as $name) {
            if (isset($sitemap_files[$name])) {
                return $name;
            }
        }
        
        return array_key_first($sitemap_files);
    }

    private function collect_page_urls()
    {
        $urls = [];
        $urls[$this->site_url . '/'] = 'index.html';
        
        $posts = get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'numberposts' => -1,
        ]);
        
        foreach ($posts as $post) {
            $permalink = get_permalink($post->ID);
            $urls[$permalink] = $this->url_to_file_path($permalink);
        }
        
        if (class_exists('WooCommerce')) {
            $products = get_posts([
                'post_type' => 'product',
                'post_status' => 'publish',
                'numberposts' => -1,
            ]);
            
            foreach ($products as $product) {
                $permalink = get_permalink($product->ID);
                $urls[$permalink] = $this->url_to_file_path($permalink);
            }
            
            $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true]);
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $link = get_term_link($term);
                    if (!is_wp_error($link)) {
                        $urls[$link] = $this->url_to_file_path($link);
                    }
                }
            }
        }
        
        foreach (['category', 'post_tag'] as $taxonomy) {
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => true]);
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $link = get_term_link($term);
                    if (!is_wp_error($link)) {
                        $urls[$link] = $this->url_to_file_path($link);
                    }
                }
            }
        }
        
        return $urls;
    }

    private function url_to_file_path($url)
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = trim($path, '/');
        
        if (empty($path)) {
            return 'index.html';
        }
        
        if (preg_match('/\.[a-z0-9]+$/i', $path)) {
            return $path;
        }
        
        return $path . '/index.html';
    }

    private function fetch_page($url)
    {
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'sslverify' => false,
        ]);
        
        if (is_wp_error($response)) {
            WP_to_CF_Logger::warning('抓取失败', ['url' => $url]);
            return false;
        }
        
        return wp_remote_retrieve_body($response);
    }

    /**
     * 通用资源提取方法
     * 从渲染后的 HTML 中提取所有静态资源，不依赖特定页面构建器
     */
    private function extract_assets($html)
    {
        // 1. 提取所有 <link> 标签中的 CSS 文件
        if (preg_match_all('/<link[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            foreach ($matches[1] as $url) {
                // CSS 文件
                if (preg_match('/\.css(\?|$)/i', $url)) {
                    $this->add_asset($url, 'css');
                }
                // 图标文件
                elseif (preg_match('/\.(ico|png|svg|jpg|jpeg|gif|webp)(\?|$)/i', $url)) {
                    $this->add_asset($url, 'image');
                }
                // 字体预加载
                elseif (preg_match('/\.(woff2?|ttf|eot|otf)(\?|$)/i', $url)) {
                    $this->add_asset($url, 'font');
                }
            }
        }
        
        // 2. 提取所有 <script> 标签中的 JS 文件
        if (preg_match_all('/<script[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            foreach ($matches[1] as $url) {
                if (preg_match('/\.js(\?|$)/i', $url)) {
                    $this->add_asset($url, 'js');
                }
            }
        }
        
        // 3. 提取所有 <img> 标签中的图片
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            foreach ($matches[1] as $url) {
                if (strpos($url, 'data:') !== 0) {
                    $this->add_asset($url, 'image');
                }
            }
        }
        
        // 4. 提取 srcset 中的所有图片
        if (preg_match_all('/srcset=["\']([^"\']+)["\']/', $html, $matches)) {
            foreach ($matches[1] as $srcset) {
                if (preg_match_all('/([^\s,]+)\s+\d+[wx]/i', $srcset, $urls)) {
                    foreach ($urls[1] as $url) {
                        $this->add_asset($url, 'image');
                    }
                }
            }
        }
        
        // 5. 提取内联 style 和 <style> 标签中的 url()
        $this->extract_urls_from_css_in_html($html);
        
        // 6. 提取 data-* 属性中的资源（通用处理，适用于所有构建器）
        $this->extract_data_attributes($html);
        
        // 7. 提取 JSON 数据中的资源（如 data-settings, data-config 等）
        $this->extract_json_resources($html);
        
        // 8. 提取所有 wp-content 路径的资源（兜底）
        $this->extract_wp_content_resources($html);
    }
    
    /**
     * 从 HTML 中的 CSS（内联样式和 style 标签）提取 url()
     */
    private function extract_urls_from_css_in_html($html)
    {
        // 提取 style 属性中的 url() - 使用更宽松的匹配
        // 匹配 style="..." 或 style='...'，允许内部包含 HTML 实体
        if (preg_match_all('/style=(["\'])(.+?)\1/is', $html, $matches)) {
            foreach ($matches[2] as $style) {
                $style = html_entity_decode($style, ENT_QUOTES, 'UTF-8');
                $this->extract_urls_from_css($style);
            }
        }
        
        // 提取 <style> 标签中的 url()
        if (preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $html, $matches)) {
            foreach ($matches[1] as $css) {
                $this->extract_urls_from_css($css);
            }
        }
        
        // 专门提取 background-image: url(...) 格式（包括 HTML 实体编码的版本）
        // 匹配: background-image: url(&quot;/path/to/image.jpg&quot;)
        if (preg_match_all('/background-image:\s*url\(&quot;([^&]+)&quot;\)/i', $html, $matches)) {
            foreach ($matches[1] as $url) {
                if (strpos($url, 'data:') === 0) continue;
                $this->add_asset($url, 'image');
            }
        }
        
        // 匹配: background-image: url("/path/to/image.jpg")
        if (preg_match_all('/background-image:\s*url\(["\']?([^"\')\s]+)["\']?\)/i', $html, $matches)) {
            foreach ($matches[1] as $url) {
                if (strpos($url, 'data:') === 0) continue;
                $this->add_asset($url, 'image');
            }
        }
    }
    
    /**
     * 从 CSS 内容中提取 url()
     */
    private function extract_urls_from_css($css)
    {
        if (preg_match_all('/url\(["\']?([^"\')\s]+)["\']?\)/i', $css, $matches)) {
            foreach ($matches[1] as $url) {
                if (strpos($url, 'data:') === 0) continue;
                
                $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
                $type = in_array($ext, ['woff', 'woff2', 'ttf', 'eot', 'otf']) ? 'font' : 'image';
                $this->add_asset($url, $type);
            }
        }
    }
    
    /**
     * 提取 data-* 属性中的资源
     */
    private function extract_data_attributes($html)
    {
        // 常见的图片相关 data 属性
        $data_attrs = [
            'data-src', 'data-bg', 'data-background', 'data-lazy-src',
            'data-srcset', 'data-bg-src', 'data-image', 'data-poster',
            'data-thumb', 'data-thumbnail', 'data-original'
        ];
        
        foreach ($data_attrs as $attr) {
            if (preg_match_all('/' . preg_quote($attr, '/') . '=["\']([^"\']+)["\']/', $html, $matches)) {
                foreach ($matches[1] as $url) {
                    if (strpos($url, 'data:') === 0) continue;
                    if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg|ico)(\?|$)/i', $url)) {
                        $this->add_asset($url, 'image');
                    }
                }
            }
        }
    }
    
    /**
     * 提取 JSON 数据中的资源（适用于各种页面构建器）
     */
    private function extract_json_resources($html)
    {
        // 匹配所有可能包含 JSON 的 data 属性 - 使用更宽松的匹配
        $json_attrs = ['data-settings', 'data-config', 'data-options', 'data-elementor-settings'];
        
        foreach ($json_attrs as $attr) {
            // 使用更宽松的匹配，允许内部包含 HTML 实体
            if (preg_match_all('/' . preg_quote($attr, '/') . '=(["\'])(.+?)\1/is', $html, $matches)) {
                foreach ($matches[2] as $json_encoded) {
                    $json = html_entity_decode($json_encoded, ENT_QUOTES, 'UTF-8');
                    $this->extract_urls_from_json($json);
                }
            }
        }
        
        // 也处理 <script type="application/json"> 中的数据
        if (preg_match_all('/<script[^>]+type=["\']application\/json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            foreach ($matches[1] as $json) {
                $this->extract_urls_from_json($json);
            }
        }
    }
    
    /**
     * 从 JSON 字符串中提取资源 URL
     */
    private function extract_urls_from_json($json)
    {
        // 处理转义的 URL: \/wp-content\/...
        $pattern1 = '#\\\\/wp-content\\\\/[^"]+\.(jpg|jpeg|png|gif|webp|svg|css|js|woff2?|ttf|eot|otf)(?=")#i';
        if (preg_match_all($pattern1, $json, $matches)) {
            foreach ($matches[0] as $url) {
                $url = str_replace('\\/', '/', $url);
                $this->add_asset_by_extension($url);
            }
        }
        
        // 处理普通 URL: /wp-content/... 或 http://...
        $pattern2 = '#(?:https?://[^"]+/wp-content/[^"]+|/wp-content/[^"]+)\.(jpg|jpeg|png|gif|webp|svg|css|js|woff2?|ttf|eot|otf)(?=["\\s])#i';
        if (preg_match_all($pattern2, $json, $matches)) {
            foreach ($matches[0] as $url) {
                $this->add_asset_by_extension($url);
            }
        }
        
        // 提取 "url" 字段中的 wp-content 路径
        if (preg_match_all('/"url"\s*:\s*"([^"]*wp-content[^"]*)"/', $json, $matches)) {
            foreach ($matches[1] as $url) {
                $url = str_replace('\\/', '/', $url);
                $this->add_asset_by_extension($url);
            }
        }
        
        // 提取所有 JSON 中的 "url" 字段图片（不限于 wp-content）
        // 格式: "url":"\/images\/xxx.webp" 或 "url":"/images/xxx.webp"
        if (preg_match_all('#"url"\s*:\s*"([^"]+\\.(jpg|jpeg|png|gif|webp|svg))"#i', $json, $matches)) {
            foreach ($matches[1] as $url) {
                $url = str_replace('\\/', '/', $url);
                $this->add_asset($url, 'image');
            }
        }
        
        // 提取转义格式的任意图片路径: \/images\/xxx.webp, \/uploads\/xxx.webp 等
        if (preg_match_all('#\\\\/[a-zA-Z0-9_-]+\\\\/[^"]+\\.(jpg|jpeg|png|gif|webp|svg)(?=")#i', $json, $matches)) {
            foreach ($matches[0] as $url) {
                $url = str_replace('\\/', '/', $url);
                $this->add_asset($url, 'image');
            }
        }
    }
    
    /**
     * 根据文件扩展名添加资源
     */
    private function add_asset_by_extension($url)
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico'])) {
            $this->add_asset($url, 'image');
        } elseif ($ext === 'css') {
            $this->add_asset($url, 'css');
        } elseif ($ext === 'js') {
            $this->add_asset($url, 'js');
        } elseif (in_array($ext, ['woff', 'woff2', 'ttf', 'eot', 'otf'])) {
            $this->add_asset($url, 'font');
        }
    }
    
    /**
     * 提取所有资源路径（兜底方案）
     * 包括 wp-content 路径和其他常见资源路径
     */
    private function extract_wp_content_resources($html)
    {
        // 匹配所有 wp-content 路径
        $patterns = [
            // 完整 URL
            '#https?://[^"\']+/wp-content/[^"\'\s]+\.(jpg|jpeg|png|gif|webp|svg|ico|css|js|woff2?|ttf|eot|otf)#i',
            // 相对路径
            '#["\'](/wp-content/[^"\']+\.(jpg|jpeg|png|gif|webp|svg|ico|css|js|woff2?|ttf|eot|otf))["\']#i',
            // 转义路径
            '#\\\\/wp-content\\\\/[^"]+\\.(jpg|jpeg|png|gif|webp|svg|ico|css|js|woff2?|ttf|eot|otf)#i',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[0] as $match) {
                    $url = trim($match, '"\' ');
                    $url = str_replace('\\/', '/', $url);
                    $this->add_asset_by_extension($url);
                }
            }
        }
        
        // 提取常见资源目录的图片（/images/, /assets/, /uploads/ 等）
        // 这些可能是主题或插件重写后的路径
        $common_dirs = ['images', 'assets', 'uploads', 'media', 'img', 'static'];
        foreach ($common_dirs as $dir) {
            // 匹配引号内的路径
            $pattern = '#["\']/' . $dir . '/[^"\']+\.(jpg|jpeg|png|gif|webp|svg|ico)["\']#i';
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[0] as $match) {
                    $url = trim($match, '"\' ');
                    $this->add_asset($url, 'image');
                }
            }
            
            // 匹配转义路径
            $pattern_escaped = '#\\\\/' . $dir . '\\\\/[^"]+\\.(jpg|jpeg|png|gif|webp|svg|ico)#i';
            if (preg_match_all($pattern_escaped, $html, $matches)) {
                foreach ($matches[0] as $match) {
                    $url = str_replace('\\/', '/', $match);
                    $this->add_asset($url, 'image');
                }
            }
        }
    }

    private function add_asset($url, $type)
    {
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        } elseif (strpos($url, '/') === 0) {
            $url = $this->site_url . $url;
        } elseif (strpos($url, 'http') !== 0) {
            return;
        }
        
        $url_host = parse_url($url, PHP_URL_HOST);
        if (!$url_host) return;
        
        $site_host_clean = str_replace('www.', '', $this->site_host);
        $url_host_clean = str_replace('www.', '', $url_host);
        
        if ($url_host_clean !== $site_host_clean) {
            return;
        }
        
        $url_clean = strtok($url, '?');
        
        if (!isset($this->collected_assets[$url_clean])) {
            $this->collected_assets[$url_clean] = $type;
        }
    }

    private function load_and_remap_assets()
    {
        $files = [];
        $total = count($this->collected_assets);
        
        foreach ($this->collected_assets as $url => $type) {
            $url_path = parse_url($url, PHP_URL_PATH);
            $original_path = ltrim($url_path, '/');
            
            if (empty($original_path)) continue;
            
            // 尝试多种可能的本地路径
            $local_path = $this->find_local_file($original_path);
            if (!$local_path) continue;
            
            $content = @file_get_contents($local_path);
            if ($content === false) continue;
            
            $new_path = $this->generate_new_path($original_path, $type);
            
            // 添加路径映射
            $this->path_mapping[$original_path] = $new_path;
            $this->path_mapping['/' . $original_path] = '/' . $new_path;
            
            $files[$new_path] = $content;
            
            switch ($type) {
                case 'css':
                    $this->stats['css_files']++;
                    $base_url = dirname($url);
                    $this->extract_css_assets($content, $base_url);
                    break;
                case 'js':
                    $this->stats['js_files']++;
                    $base_url = dirname($url);
                    $this->extract_js_chunks($content, $base_url);
                    break;
                case 'image':
                    $this->stats['images']++;
                    break;
                case 'font':
                    $this->stats['fonts']++;
                    break;
            }
        }
        
        $extra = $this->load_extra_assets();
        $files = array_merge($files, $extra);
        
        WP_to_CF_Logger::info('资源加载完成', $this->stats);
        return $files;
    }
    
    /**
     * 查找本地文件路径
     * 支持多种路径格式（标准路径、缓存插件路径、重写路径等）
     */
    private function find_local_file($path)
    {
        // 1. 标准路径
        $local_path = ABSPATH . $path;
        if (file_exists($local_path) && is_file($local_path)) {
            return $local_path;
        }
        
        // 2. LiteSpeed Cache 短路径 (如 css/xxx.css -> wp-content/litespeed/css/xxx.css)
        if (preg_match('#^(css|js|cssjs)/[a-f0-9]+\.(css|js)$#i', $path)) {
            $litespeed_path = ABSPATH . 'wp-content/litespeed/' . $path;
            if (file_exists($litespeed_path) && is_file($litespeed_path)) {
                return $litespeed_path;
            }
        }
        
        // 3. 常见的重写路径映射
        // /images/xxx.webp -> /wp-content/uploads/xxx.webp
        // /images/xxx.webp -> /wp-content/uploads/年/月/xxx.webp
        $common_rewrites = [
            'images' => 'wp-content/uploads',
            'assets' => 'wp-content/uploads',
            'media' => 'wp-content/uploads',
            'uploads' => 'wp-content/uploads',
        ];
        
        foreach ($common_rewrites as $virtual_dir => $real_dir) {
            if (strpos($path, $virtual_dir . '/') === 0) {
                $filename = basename($path);
                
                // 直接在 uploads 根目录查找
                $direct_path = ABSPATH . $real_dir . '/' . $filename;
                if (file_exists($direct_path) && is_file($direct_path)) {
                    return $direct_path;
                }
                
                // 在 uploads 子目录中递归查找（限制深度避免性能问题）
                $found = $this->find_file_recursive(ABSPATH . $real_dir, $filename, 3);
                if ($found) {
                    return $found;
                }
            }
        }
        
        // 4. 其他缓存插件路径可以在这里添加
        
        return null;
    }
    
    /**
     * 递归查找文件
     */
    private function find_file_recursive($dir, $filename, $max_depth, $current_depth = 0)
    {
        if ($current_depth >= $max_depth || !is_dir($dir)) {
            return null;
        }
        
        // 先检查当前目录
        $direct = $dir . '/' . $filename;
        if (file_exists($direct) && is_file($direct)) {
            return $direct;
        }
        
        // 递归子目录
        $items = @scandir($dir);
        if (!$items) return null;
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $item_path = $dir . '/' . $item;
            if (is_dir($item_path)) {
                $found = $this->find_file_recursive($item_path, $filename, $max_depth, $current_depth + 1);
                if ($found) {
                    return $found;
                }
            }
        }
        
        return null;
    }

    private function generate_new_path($original_path, $type)
    {
        $filename = basename($original_path);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $name = pathinfo($filename, PATHINFO_FILENAME);
        
        // webpack bundles 需要保持原始路径，否则动态加载会失败
        // 匹配: xxx.hash.bundle.min.js, xxx.hash.min.js 等格式
        if (strpos($original_path, 'wp-content/plugins/') !== false && 
            preg_match('/\.[a-f0-9]{8,}\.(bundle\.)?min\.(js|css)$/', $filename)) {
            return $original_path;
        }
        
        // 如果原始路径是 /images/xxx 格式，保持原始路径
        // 这些路径可能是主题/插件重写后的路径，HTML 中直接引用
        if (preg_match('#^(images|assets|media|uploads)/[^/]+\.[a-z]+$#i', $original_path)) {
            return $original_path;
        }
        
        $dir = match($type) {
            'css' => 'css',
            'js' => 'js',
            'image' => 'images',
            'font' => 'fonts',
            default => 'assets',
        };
        
        $type_key = ($type === 'image') ? 'images' : (($type === 'font') ? 'fonts' : $type);
        if (!isset($this->used_filenames[$type_key])) {
            $this->used_filenames[$type_key] = [];
        }
        
        $final_filename = $filename;
        $counter = 1;
        
        while (in_array(strtolower($final_filename), $this->used_filenames[$type_key])) {
            $final_filename = $name . '-' . $counter . '.' . $ext;
            $counter++;
        }
        
        $this->used_filenames[$type_key][] = strtolower($final_filename);
        
        return $dir . '/' . $final_filename;
    }

    private function load_extra_assets()
    {
        $files = [];
        
        foreach ($this->collected_assets as $url => $type) {
            $url_path = parse_url($url, PHP_URL_PATH);
            $original_path = ltrim($url_path, '/');
            
            if (empty($original_path)) continue;
            if (isset($this->path_mapping[$original_path])) continue;
            
            $local_path = ABSPATH . $original_path;
            if (!file_exists($local_path) || !is_file($local_path)) continue;
            
            $content = @file_get_contents($local_path);
            if ($content === false) continue;
            
            $new_path = $this->generate_new_path($original_path, $type);
            
            $this->path_mapping[$original_path] = $new_path;
            $this->path_mapping['/' . $original_path] = '/' . $new_path;
            
            $files[$new_path] = $content;
            
            switch ($type) {
                case 'font':
                    $this->stats['fonts']++;
                    break;
                case 'image':
                    $this->stats['images']++;
                    break;
                case 'js':
                    $this->stats['js_files']++;
                    break;
                case 'css':
                    $this->stats['css_files']++;
                    break;
            }
        }
        
        return $files;
    }

    /**
     * 处理 CSS 和 JS 文件中的资源路径
     */
    private function process_css_urls($files)
    {
        $processed = [];
        
        foreach ($files as $path => $content) {
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            
            if ($ext === 'css') {
                $content = $this->rewrite_css_urls($content, $path);
            } elseif ($ext === 'js') {
                $content = $this->rewrite_js_urls($content, $path);
            }
            
            $processed[$path] = $content;
        }
        
        return $processed;
    }
    
    /**
     * 重写 CSS 文件中的 url() 路径
     */
    private function rewrite_css_urls($content, $css_path)
    {
        return preg_replace_callback(
            '/url\(["\']?([^"\')\s]+)["\']?\)/i',
            function($matches) use ($css_path) {
                $url = $matches[1];
                
                if (strpos($url, 'data:') === 0) {
                    return $matches[0];
                }
                
                $new_url = $this->find_mapped_url($url);
                if ($new_url) {
                    // CSS 文件中使用相对路径
                    if (strpos($new_url, '/') === 0) {
                        $new_url = '..' . $new_url;
                    }
                    return 'url("' . $new_url . '")';
                }
                
                return $matches[0];
            },
            $content
        );
    }
    
    /**
     * 重写 JS 文件中的资源路径
     * 处理字符串中的图片、CSS、JS 路径引用
     */
    private function rewrite_js_urls($content, $js_path)
    {
        // 1. 处理字符串中的 /wp-content/ 路径（图片、CSS、JS）
        $content = preg_replace_callback(
            '#(["\'])(/wp-content/[^"\']+\.(jpg|jpeg|png|gif|webp|svg|ico|css|js|woff2?|ttf|eot|otf))(["\'])#i',
            function($m) {
                $new_path = $this->find_mapped_url($m[2]);
                return $m[1] . ($new_path ?: $m[2]) . $m[4];
            },
            $content
        );
        
        // 2. 处理字符串中的 /images/, /assets/, /css/, /js/ 等路径
        $content = preg_replace_callback(
            '#(["\'])(/(images|assets|uploads|media|img|static|css|js|fonts)/[^"\']+\.(jpg|jpeg|png|gif|webp|svg|ico|css|js|woff2?|ttf|eot|otf))(["\'])#i',
            function($m) {
                $new_path = $this->find_mapped_url($m[2]);
                return $m[1] . ($new_path ?: $m[2]) . $m[5];
            },
            $content
        );
        
        // 3. 处理转义格式的路径 (JSON 中的 \/wp-content\/... 或 \/images\/...)
        $content = preg_replace_callback(
            '#\\\\/(images|assets|uploads|media|wp-content|css|js|fonts)\\\\/[^"]+\\.(jpg|jpeg|png|gif|webp|svg|ico|css|js|woff2?|ttf|eot|otf)#i',
            function($m) {
                $path = '/' . str_replace('\\/', '/', $m[0]);
                $new_path = $this->find_mapped_url($path);
                if ($new_path) {
                    return str_replace('/', '\\/', $new_path);
                }
                return $m[0];
            },
            $content
        );
        
        // 4. 处理相对路径引用（如 "./xxx.js", "../css/xxx.css"）
        $content = preg_replace_callback(
            '#(["\'])(\.\.?/[^"\']+\.(css|js))(["\'])#i',
            function($m) use ($js_path) {
                // 计算绝对路径
                $js_dir = dirname($js_path);
                $relative = $m[2];
                $absolute = $this->resolve_relative_path($js_dir, $relative);
                
                $new_path = $this->find_mapped_url('/' . $absolute);
                if ($new_path) {
                    return $m[1] . $new_path . $m[4];
                }
                return $m[0];
            },
            $content
        );
        
        return $content;
    }
    
    /**
     * 解析相对路径为绝对路径
     */
    private function resolve_relative_path($base_dir, $relative)
    {
        // 移除 ./ 前缀
        if (strpos($relative, './') === 0) {
            $relative = substr($relative, 2);
        }
        
        $parts = explode('/', $base_dir);
        $rel_parts = explode('/', $relative);
        
        foreach ($rel_parts as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }
        
        return implode('/', array_filter($parts));
    }

    private function find_mapped_url($url)
    {
        $url_clean = strtok($url, '?');
        
        // 移除前导斜杠进行查找
        $path_without_slash = ltrim($url_clean, '/');
        
        // 直接查找（不带斜杠）
        if (isset($this->path_mapping[$path_without_slash])) {
            return '/' . $this->path_mapping[$path_without_slash];
        }
        
        // 带斜杠查找
        $path_with_slash = '/' . $path_without_slash;
        if (isset($this->path_mapping[$path_with_slash])) {
            return $this->path_mapping[$path_with_slash];
        }
        
        // LiteSpeed 短路径查找 (如 /css/xxx.css -> css/xxx.css)
        if (preg_match('#^(css|js|cssjs)/[a-f0-9]+\.(css|js)$#i', $path_without_slash)) {
            // 尝试查找 LiteSpeed 完整路径
            $litespeed_full = 'wp-content/litespeed/' . $path_without_slash;
            if (isset($this->path_mapping[$litespeed_full])) {
                return '/' . $this->path_mapping[$litespeed_full];
            }
            if (isset($this->path_mapping['/' . $litespeed_full])) {
                return $this->path_mapping['/' . $litespeed_full];
            }
        }
        
        // 尝试只用文件名查找（处理路径不完全匹配的情况）
        $filename = basename($path_without_slash);
        foreach ($this->path_mapping as $orig => $new) {
            if (basename($orig) === $filename) {
                // 验证路径的关键部分匹配
                $orig_clean = ltrim($orig, '/');
                
                // 对于 uploads 目录的文件，验证年月目录
                if (strpos($path_without_slash, 'uploads/') !== false && strpos($orig_clean, 'uploads/') !== false) {
                    // 提取年月目录
                    if (preg_match('#uploads/(\d{4}/\d{2})/#', $path_without_slash, $m1) &&
                        preg_match('#uploads/(\d{4}/\d{2})/#', $orig_clean, $m2)) {
                        if ($m1[1] === $m2[1]) {
                            return '/' . ltrim($new, '/');
                        }
                    } else {
                        // 没有年月目录，直接匹配文件名
                        return '/' . ltrim($new, '/');
                    }
                }
                // 对于其他目录的文件，直接匹配
                elseif (strpos($path_without_slash, 'wp-content/') !== false && strpos($orig_clean, 'wp-content/') !== false) {
                    // 进一步验证路径相似性
                    $path_parts = explode('/', $path_without_slash);
                    $orig_parts = explode('/', $orig_clean);
                    
                    // 如果路径深度相似且文件名相同，认为是同一个文件
                    if (abs(count($path_parts) - count($orig_parts)) <= 2) {
                        return '/' . ltrim($new, '/');
                    }
                }
                // 对于 LiteSpeed 短路径，通过文件名匹配
                elseif (preg_match('#^(css|js|cssjs)/#', $path_without_slash) && 
                        (strpos($orig_clean, 'litespeed/') !== false || preg_match('#^(css|js|cssjs)/#', $orig_clean))) {
                    return '/' . ltrim($new, '/');
                }
            }
        }
        
        return null;
    }

    private function extract_css_assets($css, $base_url)
    {
        if (!preg_match_all('/url\(["\']?([^"\')\s]+)["\']?\)/i', $css, $m)) {
            return;
        }
        
        foreach ($m[1] as $url) {
            if (strpos($url, 'data:') === 0) continue;
            
            if (strpos($url, 'http') !== 0 && strpos($url, '//') !== 0) {
                if (strpos($url, '/') === 0) {
                    $url = $this->site_url . $url;
                } else {
                    $full_url = $base_url . '/' . $url;
                    $url = $this->normalize_url($full_url);
                }
            }
            
            $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
            $type = in_array($ext, ['woff', 'woff2', 'ttf', 'eot', 'otf']) ? 'font' : 'image';
            
            $this->add_asset($url, $type);
        }
    }

    private function extract_js_chunks($js, $base_url)
    {
        if (preg_match_all('/["\']([a-zA-Z0-9_-]+\.[a-f0-9]{10,}\.(?:bundle\.)?min\.js)["\']/', $js, $m)) {
            foreach ($m[1] as $chunk) {
                $chunk_url = $base_url . '/' . $chunk;
                $this->add_asset($chunk_url, 'js');
            }
        }
        
        if (preg_match_all('/["\']([^"\']+\.min\.js)["\']/', $js, $m)) {
            foreach ($m[1] as $file) {
                if (strpos($file, 'http') === 0) continue;
                if (strlen($file) < 10) continue;
                if (strpos($file, '/') !== false || strpos($file, '.') === false) continue;
                
                $file_url = $base_url . '/' . $file;
                $this->add_asset($file_url, 'js');
            }
        }
        
        // 扫描所有插件的 JS bundles
        $this->scan_plugin_assets();
        
        if (preg_match_all('/["\']([a-zA-Z0-9_-]+)["\']:\s*["\']([a-zA-Z0-9_.-]+)["\']/', $js, $m)) {
            foreach ($m[2] as $module) {
                if (preg_match('/\.[a-f0-9]{8,}\./', $module)) {
                    $module_url = $base_url . '/' . $module;
                    $this->add_asset($module_url, 'js');
                }
            }
        }
    }
    
    /**
     * 扫描所有插件目录，收集 webpack bundle 和动态加载的 JS/CSS 文件
     * 保持原始路径结构，确保动态加载能正常工作
     */
    private function scan_plugin_assets()
    {
        static $scanned = false;
        if ($scanned) return;
        $scanned = true;
        
        $plugins_dir = ABSPATH . 'wp-content/plugins/';
        if (!is_dir($plugins_dir)) return;
        
        // 递归扫描所有插件的 assets/js 目录
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($plugins_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        $count = 0;
        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            
            $filename = $file->getFilename();
            $filepath = $file->getPathname();
            
            // 只收集 bundle 文件和动态加载的 JS
            // 匹配: xxx.hash.bundle.min.js, xxx.hash.min.js 等 webpack 输出格式
            if (preg_match('/\.[a-f0-9]{8,}\.(bundle\.)?min\.js$/', $filename) ||
                preg_match('/\.[a-f0-9]{8,}\.min\.css$/', $filename)) {
                
                $relative_path = str_replace(ABSPATH, '', $filepath);
                $url = $this->site_url . '/' . $relative_path;
                $type = pathinfo($filename, PATHINFO_EXTENSION) === 'js' ? 'js' : 'css';
                $this->add_asset($url, $type);
                $count++;
            }
        }
    }

    private function normalize_url($url)
    {
        $parsed = parse_url($url);
        if (!isset($parsed['path'])) {
            return $url;
        }
        
        $path = $parsed['path'];
        $parts = explode('/', $path);
        $normalized = [];
        
        foreach ($parts as $part) {
            if ($part === '..') {
                array_pop($normalized);
            } elseif ($part !== '.' && $part !== '') {
                $normalized[] = $part;
            }
        }
        
        $new_path = '/' . implode('/', $normalized);
        
        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] . '://' : '';
        $host = isset($parsed['host']) ? $parsed['host'] : '';
        
        return $scheme . $host . $new_path;
    }


    private function process_html($html)
    {
        $host_clean = str_replace('www.', '', $this->site_host);
        
        $html = preg_replace(
            '#https?:\\\\/\\\\/(www\.)?' . preg_quote($host_clean, '#') . '\\\\/#',
            '\\/',
            $html
        );
        
        $html = preg_replace(
            '#https?://(www\.)?' . preg_quote($host_clean, '#') . '/#',
            '/',
            $html
        );
        
        $html = preg_replace_callback(
            '#(href=["\'])([^"\']+\.css)(\?[^"\']*)?(["\'])#i',
            function($m) {
                $new_path = $this->find_mapped_url($m[2]);
                return $m[1] . ($new_path ?: $m[2]) . $m[4];
            },
            $html
        );
        
        $html = preg_replace_callback(
            '#(src=["\'])([^"\']+\.js)(\?[^"\']*)?(["\'])#i',
            function($m) {
                $new_path = $this->find_mapped_url($m[2]);
                return $m[1] . ($new_path ?: $m[2]) . $m[4];
            },
            $html
        );
        
        $html = preg_replace_callback(
            '#(src=["\'])([^"\']+\.(jpg|jpeg|png|gif|webp|svg|ico))(\?[^"\']*)?(["\'])#i',
            function($m) {
                $new_path = $this->find_mapped_url($m[2]);
                return $m[1] . ($new_path ?: $m[2]) . $m[5];
            },
            $html
        );
        
        $html = preg_replace_callback(
            '/srcset=["\']([^"\']+)["\']/',
            function($matches) {
                $srcset = $matches[1];
                $srcset = preg_replace_callback(
                    '/([^\s,]+)(\s+\d+[wx])/i',
                    function($m) {
                        $url = strtok($m[1], '?');
                        $new_path = $this->find_mapped_url($url);
                        return ($new_path ?: $url) . $m[2];
                    },
                    $srcset
                );
                return 'srcset="' . $srcset . '"';
            },
            $html
        );
        
        // 处理包含 url() 的 style 属性 - 使用更宽松的匹配
        $html = preg_replace_callback(
            '/style=(["\'])(.+?)\1/is',
            function($matches) {
                $quote = $matches[1];
                $style = $matches[2];
                
                // 检查是否包含 url() 或 background-image
                if (strpos($style, 'url(') === false && strpos($style, 'background-image') === false) {
                    return $matches[0];
                }
                
                // 先解码 HTML 实体
                $style_decoded = html_entity_decode($style, ENT_QUOTES, 'UTF-8');
                
                $style_decoded = preg_replace_callback(
                    '/url\(["\']?([^"\')\s]+)["\']?\)/',
                    function($m) {
                        if (strpos($m[1], 'data:') === 0) return $m[0];
                        $url = strtok($m[1], '?');
                        $new_path = $this->find_mapped_url($url);
                        return 'url("' . ($new_path ?: $url) . '")';
                    },
                    $style_decoded
                );
                
                // 重新编码为 HTML 实体
                $style_encoded = htmlspecialchars($style_decoded, ENT_QUOTES, 'UTF-8', false);
                
                return 'style=' . $quote . $style_encoded . $quote;
            },
            $html
        );
        
        $html = preg_replace_callback(
            '#(<link[^>]+href=["\'])([^"\']+\.(woff2?|ttf|eot|otf))(\?[^"\']*)?(["\'][^>]*>)#i',
            function($m) {
                $new_path = $this->find_mapped_url($m[2]);
                return $m[1] . ($new_path ?: $m[2]) . $m[5];
            },
            $html
        );
        
        // 重写所有 <link> 标签中的图片路径（包括 favicon、icon、apple-touch-icon）
        $html = preg_replace_callback(
            '#<link([^>]*)>#i',
            function($m) {
                $attrs = $m[1];
                
                // 检查是否是 icon 相关的 link
                if (!preg_match('/rel=["\'][^"\']*icon["\']|rel=["\']apple-touch-icon["\']/i', $attrs)) {
                    return $m[0];
                }
                
                // 提取并替换 href
                if (preg_match('/href=["\']([^"\']+)["\']/', $attrs, $href_match)) {
                    $old_href = $href_match[1];
                    $new_path = $this->find_mapped_url($old_href);
                    if ($new_path) {
                        $attrs = str_replace($href_match[0], 'href="' . $new_path . '"', $attrs);
                        return '<link' . $attrs . '>';
                    }
                }
                
                return $m[0];
            },
            $html
        );
        
        // 重写 Elementor data-settings 中的图片URL - 使用更宽松的匹配
        $slideshow_rewrites = [];
        $html = preg_replace_callback(
            '/data-settings=(["\'])(.+?)\1/is',
            function($matches) use (&$slideshow_rewrites) {
                $quote = $matches[1];
                $json = $matches[2];
                
                // 先解码HTML实体
                $json_decoded = html_entity_decode($json, ENT_QUOTES, 'UTF-8');
                
                // 检查是否包含 wp-content 路径
                if (strpos($json_decoded, 'wp-content') === false) {
                    return $matches[0];
                }
                
                // 处理转义的URL格式: \/wp-content\/uploads\/...
                // JSON 中的转义斜杠是 \/ (一个反斜杠+一个斜杠)
                // 在 PHP 正则中，\\\/ 匹配 \/
                $json_decoded = preg_replace_callback(
                    '#\\\\/wp-content\\\\/uploads\\\\/[^"]+\\.(jpg|jpeg|png|gif|webp|svg)#i',
                    function($m) use (&$slideshow_rewrites) {
                        // 将 \/path\/to\/file.jpg 转换为 /path/to/file.jpg
                        $original_path = str_replace('\\/', '/', $m[0]);
                        
                        $new_path = $this->find_mapped_url($original_path);
                        
                        if ($new_path) {
                            $slideshow_rewrites[$original_path] = $new_path;
                            // 转换回转义格式
                            return str_replace('/', '\\/', $new_path);
                        }
                        return $m[0];
                    },
                    $json_decoded
                );
                
                // 也处理非转义格式的URL: /wp-content/uploads/...
                $json_decoded = preg_replace_callback(
                    '#(?<!\\\\)/(wp-content/[^"]+\\.(jpg|jpeg|png|gif|webp|svg))#i',
                    function($m) use (&$slideshow_rewrites) {
                        $new_path = $this->find_mapped_url('/' . $m[1]);
                        
                        if ($new_path) {
                            $slideshow_rewrites['/' . $m[1]] = $new_path;
                            return $new_path;
                        }
                        return $m[0];
                    },
                    $json_decoded
                );
                
                // 重新编码为HTML实体
                $json_encoded = htmlspecialchars($json_decoded, ENT_QUOTES, 'UTF-8', false);
                
                return 'data-settings=' . $quote . $json_encoded . $quote;
            },
            $html
        );
        
        // 为 Elementor background slideshow 添加 CSS 回退
        // 静态站点上 JS 不执行，需要将第一张图片设置为内联背景
        $html = preg_replace_callback(
            '/<div([^>]*?)class="([^"]*elementor-(?:column|section)[^"]*)"([^>]*?)data-settings="([^"]*background_slideshow_gallery[^"]*)"([^>]*)>/is',
            function($matches) {
                $before_class = $matches[1];
                $classes = $matches[2];
                $after_class = $matches[3];
                $data_settings = $matches[4];
                $rest = $matches[5];
                
                // 解码 HTML 实体
                $json = html_entity_decode($data_settings, ENT_QUOTES, 'UTF-8');
                
                // 提取第一张图片 URL
                if (preg_match('/"background_slideshow_gallery"\s*:\s*\[\s*\{[^}]*"url"\s*:\s*"([^"]+)"/', $json, $url_match)) {
                    $first_image = str_replace('\\/', '/', $url_match[1]);
                    
                    // 检查是否已有 style 属性
                    $full_tag = $matches[0];
                    if (preg_match('/style="([^"]*)"/', $full_tag, $style_match)) {
                        // 已有 style，追加背景
                        $existing_style = $style_match[1];
                        if (strpos($existing_style, 'background') === false) {
                            $new_style = rtrim($existing_style, ';') . ';background-image:url(\'' . $first_image . '\');background-size:cover;background-position:center;';
                            $full_tag = str_replace('style="' . $existing_style . '"', 'style="' . $new_style . '"', $full_tag);
                        }
                        return $full_tag;
                    } else {
                        // 没有 style，添加新的
                        $style_attr = ' style="background-image:url(\'' . $first_image . '\');background-size:cover;background-position:center;"';
                        return '<div' . $before_class . 'class="' . $classes . '"' . $after_class . 'data-settings="' . $data_settings . '"' . $style_attr . $rest . '>';
                    }
                }
                
                return $matches[0];
            },
            $html
        );
        
        // 重写 data-bg 和 data-src 属性中的图片URL
        $html = preg_replace_callback(
            '/(data-(?:bg|src|lazy-src|background))=["\']([^"\']+)["\']/',
            function($m) {
                $url = $m[2];
                if (strpos($url, 'data:') === 0) return $m[0];
                $new_path = $this->find_mapped_url($url);
                return $m[1] . '="' . ($new_path ?: $url) . '"';
            },
            $html
        );
        
        // 清理WordPress痕迹
        $html = preg_replace('/<meta[^>]+name=["\']generator["\'][^>]*>/i', '', $html);
        $html = preg_replace('/<link[^>]+rel=["\']https:\/\/api\.w\.org\/["\'][^>]*>/i', '', $html);
        $html = preg_replace('/<link[^>]+rel=["\']wlwmanifest["\'][^>]*>/i', '', $html);
        $html = preg_replace('/<link[^>]+rel=["\']EditURI["\'][^>]*>/i', '', $html);
        $html = preg_replace('/<link[^>]+rel=["\']shortlink["\'][^>]*>/i', '', $html);
        $html = preg_replace('/<link[^>]+rel=["\']pingback["\'][^>]*>/i', '', $html);
        $html = preg_replace('/<link[^>]+type=["\']application\/(rss|atom)\+xml["\'][^>]*>/i', '', $html);
        $html = preg_replace('/<link[^>]+type=["\']application\/(json|xml)\+oembed["\'][^>]*>/i', '', $html);
        $html = preg_replace('/<link[^>]+rel=["\']alternate["\'][^>]+type=["\']application\/json["\'][^>]*>/i', '', $html);
        
        // 移除 wp-includes/js 目录下的所有脚本（静态站点不需要这些 WordPress 核心 JS）
        // 这包括 wp-emoji-loader, jquery-migrate, wp-embed 等
        // 使用多种模式确保完全移除
        
        // 模式1: <script src="...wp-includes/js/..."></script>
        $html = preg_replace('#<script[^>]+src=["\'][^"\']*[/\\\\]wp-includes[/\\\\]js[/\\\\][^"\']*["\'][^>]*></script>#is', '', $html);
        
        // 模式2: <script src="...wp-includes/js/..." ...></script> (带内容)
        $html = preg_replace('#<script[^>]+src=["\'][^"\']*[/\\\\]wp-includes[/\\\\]js[/\\\\][^"\']*["\'][^>]*>.*?</script>#is', '', $html);
        
        // 模式3: 自闭合标签 <script src="...wp-includes/js/..." />
        $html = preg_replace('#<script[^>]+src=["\'][^"\']*[/\\\\]wp-includes[/\\\\]js[/\\\\][^"\']*["\'][^>]*/>#is', '', $html);
        
        // 移除 wp-emoji 相关的内联脚本和样式
        $html = preg_replace('/<script[^>]*>[^<]*wp-emoji[^<]*<\/script>/is', '', $html);
        $html = preg_replace('/<script[^>]+id=["\']wp-emoji-settings["\'][^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style[^>]*>[^<]*img\.wp-smiley[^<]*<\/style>/is', '', $html);
        $html = preg_replace('/<style[^>]*>[^<]*wp-emoji[^<]*<\/style>/is', '', $html);
        
        // 移除 twemoji 脚本
        $html = preg_replace('/<script[^>]+src=["\'][^"\']*twemoji[^"\']*["\'][^>]*>.*?<\/script>/is', '', $html);
        
        // 移除 WooCommerce AJAX 相关脚本（静态站点上不工作）
        // 移除 wc-add-to-cart 及其内联配置
        $html = preg_replace('#<script[^>]+id=["\']wc-add-to-cart-js-extra["\'][^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<script[^>]+id=["\']wc-add-to-cart-js["\'][^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<script[^>]+id=["\']wc-add-to-cart-js["\'][^>]*></script>#is', '', $html);
        // 移除 woocommerce 主脚本及其内联配置
        $html = preg_replace('#<script[^>]+id=["\']woocommerce-js-extra["\'][^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<script[^>]+id=["\']woocommerce-js["\'][^>]*>.*?</script>#is', '', $html);
        // 移除 wc-cart-fragments（购物车 AJAX）
        $html = preg_replace('#<script[^>]+id=["\']wc-cart-fragments-js-extra["\'][^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<script[^>]+id=["\']wc-cart-fragments-js["\'][^>]*>.*?</script>#is', '', $html);
        // 移除 js.cookie（WooCommerce 依赖）
        $html = preg_replace('#<script[^>]+id=["\']wc-js-cookie-js["\'][^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<script[^>]+id=["\']wc-js-cookie-js["\'][^>]*></script>#is', '', $html);
        // 移除所有包含 wc_add_to_cart_params 或 woocommerce_params 的内联脚本
        $html = preg_replace('#<script[^>]*>.*?wc_add_to_cart_params.*?</script>#is', '', $html);
        $html = preg_replace('#<script[^>]*>.*?woocommerce_params.*?</script>#is', '', $html);
        $html = preg_replace('#<script[^>]*>.*?wc_cart_fragments_params.*?</script>#is', '', $html);
        
        // 移除 WordPress speculation rules（预加载规则，静态站点不需要）
        $html = preg_replace('#<script[^>]+type=["\']speculationrules["\'][^>]*>.*?</script>#is', '', $html);
        
        // 移除 wp-i18n 相关脚本
        $html = preg_replace('#<script[^>]+id=["\']wp-i18n-js-after["\'][^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<script[^>]+id=["\']wp-i18n-js["\'][^>]*>.*?</script>#is', '', $html);
        
        // 移除 wp-emoji module 脚本（type="module" 的 emoji loader）
        $html = preg_replace('#<script[^>]+type=["\']module["\'][^>]*>.*?wp-emoji-settings.*?</script>#is', '', $html);
        $html = preg_replace('#<script[^>]+type=["\']module["\'][^>]*>.*?wpEmojiSettings.*?</script>#is', '', $html);
        $html = preg_replace('#<script[^>]+type=["\']module["\'][^>]*>.*?_wpemojiSettings.*?</script>#is', '', $html);
        
        // 移除 wp-emoji-settings 的 JSON 数据
        $html = preg_replace('#<script[^>]+id=["\']wp-emoji-settings["\'][^>]+type=["\']application/json["\'][^>]*>.*?</script>#is', '', $html);
        
        // 移除 Cloudflare Turnstile 相关脚本（静态站点上验证码不工作）
        $html = preg_replace('#<script[^>]+src=["\'][^"\']*challenges\.cloudflare\.com/turnstile[^"\']*["\'][^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<script[^>]+src=["\'][^"\']*challenges\.cloudflare\.com/turnstile[^"\']*["\'][^>]*></script>#is', '', $html);
        $html = preg_replace('#<script[^>]+id=["\']cfturnstile[^"\']*["\'][^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<script[^>]+id=["\']cfturnstile[^"\']*["\'][^>]*></script>#is', '', $html);
        // 移除 Turnstile 配置脚本
        $html = preg_replace('#<script[^>]*>.*?cfturnstile.*?</script>#is', '', $html);
        
        // 移除 jquery-migrate（静态站点不需要）
        $html = preg_replace('#<script[^>]+id=["\']jquery-migrate-js["\'][^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<script[^>]+id=["\']jquery-migrate-js["\'][^>]*></script>#is', '', $html);
        $html = preg_replace('#<script[^>]+src=["\'][^"\']*jquery-migrate[^"\']*["\'][^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<script[^>]+src=["\'][^"\']*jquery-migrate[^"\']*["\'][^>]*></script>#is', '', $html);
        
        // 移除 Elementor 配置脚本（包含 admin-ajax.php 和 wp-json 路径）
        $html = preg_replace('#<script[^>]+id=["\']elementor-pro-frontend-js-before["\'][^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<script[^>]+id=["\']elementor-frontend-js-before["\'][^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<script[^>]*>\s*var\s+ElementorProFrontendConfig\s*=.*?</script>#is', '', $html);
        $html = preg_replace('#<script[^>]*>\s*var\s+elementorFrontendConfig\s*=.*?</script>#is', '', $html);
        
        // 移除 WooCommerce 订单追踪脚本（包含 admin-ajax.php）
        $html = preg_replace('#<script[^>]+id=["\']wc-order-attribution-js-extra["\'][^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<script[^>]*>\s*var\s+wc_order_attribution\s*=.*?</script>#is', '', $html);
        
        // 移除 Contact Form 7 配置脚本（包含 wp-json 路径）
        $html = preg_replace('#<script[^>]+id=["\']contact-form-7-js-before["\'][^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<script[^>]*>\s*var\s+wpcf7\s*=.*?</script>#is', '', $html);
        
        $html = preg_replace('/<link[^>]+rel=["\']https:\/\/wordpress\.org\/["\'][^>]*>/i', '', $html);
        $html = preg_replace('/<link[^>]+href=["\'][^"\']*\/wp-json\/[^"\']*["\'][^>]*>/i', '', $html);
        $html = preg_replace('/<link[^>]+href=["\'][^"\']*xmlrpc\.php[^"\']*["\'][^>]*>/i', '', $html);
        $html = preg_replace('/<!--\s*\/?(?:wp|wordpress)[^>]*-->/i', '', $html);
        
        // 最后一步：通用处理所有剩余的 /wp-content/ 路径
        // 处理普通格式的路径
        $html = preg_replace_callback(
            '#(["\'])(/wp-content/[^"\']+\.(jpg|jpeg|png|gif|webp|svg|ico|css|js|woff2?|ttf|eot|otf))(\?[^"\']*)?(["\'])#i',
            function($m) {
                $path = $m[2];
                $new_path = $this->find_mapped_url($path);
                return $m[1] . ($new_path ?: $path) . $m[5];
            },
            $html
        );
        
        // 处理转义格式的路径 (JSON中的 \/wp-content\/...)
        $html = preg_replace_callback(
            '#\\\\/(wp-content\\\\/[^"\\\\]+\\.(jpg|jpeg|png|gif|webp|svg))#i',
            function($m) {
                $path = '/' . str_replace('\\/', '/', $m[1]);
                $new_path = $this->find_mapped_url($path);
                if ($new_path) {
                    return str_replace('/', '\\/', $new_path);
                }
                return $m[0];
            },
            $html
        );
        
        $html = preg_replace('/\n\s*\n\s*\n/', "\n\n", $html);
        
        // 注入 Elementor carousel 修复样式
        // 静态站点上 Elementor JS 可能不执行，需要 CSS 回退
        // 确保轮播有正确的尺寸和布局
        $elementor_fix_css = '<style id="elementor-static-fix">'
            // 主轮播样式
            . '.elementor-skin-slideshow .elementor-main-swiper{width:100%;min-height:400px;}'
            . '.elementor-skin-slideshow .elementor-main-swiper .swiper-slide{width:100%!important;min-height:400px;}'
            . '.elementor-carousel-image{width:100%;min-height:300px;background-size:cover;background-position:center;}'
            // 缩略图轮播样式
            . '.elementor-thumbnails-swiper{width:100%;margin-top:10px;}'
            . '.elementor-thumbnails-swiper .swiper-wrapper{display:flex;}'
            . '.elementor-thumbnails-swiper .swiper-slide{flex:0 0 auto;width:calc(25% - 8px)!important;min-height:80px;margin-right:10px;}'
            . '.elementor-thumbnails-swiper .swiper-slide:last-child{margin-right:0;}'
            . '.elementor-thumbnails-swiper .elementor-carousel-image{width:100%;min-height:80px;padding-bottom:75%;}'
            // Swiper fade 效果修复
            . '.swiper-fade .swiper-slide{opacity:0!important;}'
            . '.swiper-fade .swiper-slide-active{opacity:1!important;}'
            . '</style>';
        
        // 在 </head> 前注入
        $html = str_replace('</head>', $elementor_fix_css . '</head>', $html);
        
        // 重写 canonical URL 和 SEO meta 标签，使用生产域名
        $production_domain = get_option('wptocf_production_domain', '');
        if (!empty($production_domain)) {
            $production_url = 'https://' . $production_domain;
            
            // 重写 <link rel="canonical" href="...">
            $html = preg_replace_callback(
                '#<link([^>]*rel=["\']canonical["\'][^>]*)>#i',
                function($m) use ($production_url) {
                    $attrs = $m[1];
                    if (preg_match('/href=["\']([^"\']*)["\']/', $attrs, $href_match)) {
                        $old_href = $href_match[1];
                        // 如果是相对路径，添加生产域名
                        if (strpos($old_href, 'http') !== 0) {
                            $new_href = $production_url . '/' . ltrim($old_href, '/');
                            $attrs = str_replace($href_match[0], 'href="' . $new_href . '"', $attrs);
                        }
                    }
                    return '<link' . $attrs . '>';
                },
                $html
            );
            
            // 重写 <meta name="dc.relation" content="...">
            $html = preg_replace_callback(
                '#<meta([^>]*name=["\']dc\.relation["\'][^>]*)>#i',
                function($m) use ($production_url) {
                    $attrs = $m[1];
                    if (preg_match('/content=["\']([^"\']*)["\']/', $attrs, $content_match)) {
                        $old_content = $content_match[1];
                        if (strpos($old_content, 'http') !== 0) {
                            $new_content = $production_url . '/' . ltrim($old_content, '/');
                            $attrs = str_replace($content_match[0], 'content="' . $new_content . '"', $attrs);
                        }
                    }
                    return '<meta' . $attrs . '>';
                },
                $html
            );
            
            // 重写 <meta name="dc.source" content="...">
            $html = preg_replace_callback(
                '#<meta([^>]*name=["\']dc\.source["\'][^>]*)>#i',
                function($m) use ($production_url) {
                    $attrs = $m[1];
                    if (preg_match('/content=["\']([^"\']*)["\']/', $attrs, $content_match)) {
                        $old_content = $content_match[1];
                        if (strpos($old_content, 'http') !== 0) {
                            $new_content = $production_url . '/' . ltrim($old_content, '/');
                            $attrs = str_replace($content_match[0], 'content="' . $new_content . '"', $attrs);
                        }
                    }
                    return '<meta' . $attrs . '>';
                },
                $html
            );
            
            // 重写 Open Graph URL: <meta property="og:url" content="...">
            $html = preg_replace_callback(
                '#<meta([^>]*property=["\']og:url["\'][^>]*)>#i',
                function($m) use ($production_url) {
                    $attrs = $m[1];
                    if (preg_match('/content=["\']([^"\']*)["\']/', $attrs, $content_match)) {
                        $old_content = $content_match[1];
                        if (strpos($old_content, 'http') !== 0) {
                            $new_content = $production_url . '/' . ltrim($old_content, '/');
                            $attrs = str_replace($content_match[0], 'content="' . $new_content . '"', $attrs);
                        }
                    }
                    return '<meta' . $attrs . '>';
                },
                $html
            );
            
            // 重写 Twitter URL: <meta name="twitter:url" content="...">
            $html = preg_replace_callback(
                '#<meta([^>]*name=["\']twitter:url["\'][^>]*)>#i',
                function($m) use ($production_url) {
                    $attrs = $m[1];
                    if (preg_match('/content=["\']([^"\']*)["\']/', $attrs, $content_match)) {
                        $old_content = $content_match[1];
                        if (strpos($old_content, 'http') !== 0) {
                            $new_content = $production_url . '/' . ltrim($old_content, '/');
                            $attrs = str_replace($content_match[0], 'content="' . $new_content . '"', $attrs);
                        }
                    }
                    return '<meta' . $attrs . '>';
                },
                $html
            );
        }
        
        // 注入自定义代码（统计代码、GTM 等）
        $injector = new WP_to_CF_Code_Injector();
        $html = $injector->inject_code($html);
        
        return $html;
    }

    private function create_zip($html_files, $asset_files)
    {
        $upload_dir = wp_upload_dir();
        $zip_path = $upload_dir['basedir'] . '/site-export-' . time() . '.zip';
        
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('无法创建ZIP文件');
        }
        
        foreach ($html_files as $path => $content) {
            $zip->addFromString($path, $content);
        }
        
        foreach ($asset_files as $path => $content) {
            $zip->addFromString($path, $content);
        }
        
        // 收集 sitemap
        $sitemap_files = $this->collect_sitemaps();
        foreach ($sitemap_files as $path => $content) {
            $zip->addFromString($path, $content);
        }
        
        // 确定主 sitemap 文件名（用于 robots.txt）
        $main_sitemap = '';
        if (!empty($sitemap_files)) {
            // 优先使用 sitemap_index.xml，其次 wp-sitemap.xml，最后 sitemap.xml
            foreach (['sitemap_index.xml', 'wp-sitemap.xml', 'sitemap.xml'] as $name) {
                if (isset($sitemap_files[$name])) {
                    $main_sitemap = $name;
                    break;
                }
            }
            if (empty($main_sitemap)) {
                $main_sitemap = array_key_first($sitemap_files);
            }
        }
        
        // 生成 robots.txt（包含 sitemap 链接）
        $robots_content = $this->generate_robots_txt(!empty($sitemap_files), $main_sitemap);
        $zip->addFromString('robots.txt', $robots_content);
        
        $zip->close();
        
        $total_files = count($html_files) + count($asset_files) + count($sitemap_files) + 1;
        WP_to_CF_Logger::info('ZIP创建完成', [
            'path' => $zip_path,
            'files' => $total_files,
            'size_mb' => round(filesize($zip_path) / 1024 / 1024, 2),
            'sitemaps' => count($sitemap_files),
        ]);
        
        return $zip_path;
    }
    
    /**
     * 收集所有 sitemap 文件
     */
    private function collect_sitemaps()
    {
        $sitemaps = [];
        
        // 常见的 sitemap 路径（WordPress 默认 + 各种 SEO 插件）
        $sitemap_urls = [
            'sitemap_index.xml',        // Yoast SEO / Rank Math
            'sitemaps.xml',             // SEOPress
            'wp-sitemap.xml',           // WordPress 默认
            'sitemap.xml',              // 通用
        ];
        
        // 尝试获取主 sitemap
        foreach ($sitemap_urls as $sitemap_path) {
            $sitemap_url = $this->site_url . '/' . $sitemap_path;
            $content = $this->fetch_sitemap($sitemap_url);
            
            if ($content && strlen($content) > 100) { // 确保内容有效
                // 处理 sitemap 内容，替换域名
                $processed_content = $this->process_sitemap_content($content);
                $sitemaps[$sitemap_path] = $processed_content;
                
                // 解析并获取所有子 sitemap
                $sub_urls = $this->extract_sitemap_urls($content);
                foreach ($sub_urls as $sub_url) {
                    $sub_path = $this->url_to_sitemap_path($sub_url);
                    if ($sub_path && !isset($sitemaps[$sub_path])) {
                        // 构建完整 URL
                        if (strpos($sub_url, 'http') !== 0) {
                            $sub_url = $this->site_url . '/' . ltrim($sub_url, '/');
                        }
                        $sub_content = $this->fetch_sitemap($sub_url);
                        if ($sub_content && strlen($sub_content) > 100) {
                            $sitemaps[$sub_path] = $this->process_sitemap_content($sub_content);
                        }
                    }
                }
                
                WP_to_CF_Logger::info('收集 sitemap', [
                    'main' => $sitemap_path,
                    'total' => count($sitemaps)
                ]);
                
                break; // 找到一个有效的 sitemap index 就停止
            }
        }
        
        return $sitemaps;
    }
    
    /**
     * 从 sitemap 内容中提取所有引用的 sitemap URL
     */
    private function extract_sitemap_urls($content)
    {
        $urls = [];
        
        // 匹配 <loc> 标签中的 URL
        if (preg_match_all('#<loc>\s*([^<]+)\s*</loc>#i', $content, $matches)) {
            foreach ($matches[1] as $url) {
                $url = trim($url);
                // 只收集 sitemap 文件（以 .xml 结尾且包含 sitemap 关键字）
                if (preg_match('/sitemap.*\.xml$/i', $url) || preg_match('/-sitemap\d*\.xml$/i', $url)) {
                    $urls[] = $url;
                }
            }
        }
        
        return $urls;
    }
    
    /**
     * 获取 sitemap 内容
     */
    private function fetch_sitemap($url)
    {
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'sslverify' => false,
            'headers' => [
                'Accept' => 'application/xml, text/xml, */*',
            ],
        ]);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        
        // 检查是否是有效的 XML sitemap
        if (empty($body) || strpos($body, '<?xml') === false) {
            // 有些 sitemap 没有 XML 声明，检查是否有 urlset 或 sitemapindex
            if (strpos($body, '<urlset') === false && strpos($body, '<sitemapindex') === false) {
                return false;
            }
        }
        
        return $body;
    }
    
    /**
     * 处理 sitemap 内容，替换域名为生产域名
     */
    private function process_sitemap_content($content)
    {
        $production_domain = get_option('wptocf_production_domain', '');
        
        // 移除 XSL 样式表引用（静态站点不需要）
        $content = preg_replace('#<\?xml-stylesheet[^?]*\?>#i', '', $content);
        
        if (empty($production_domain)) {
            // 没有设置生产域名，只移除协议使用相对路径
            $content = preg_replace(
                '#https?://' . preg_quote($this->site_host, '#') . '#i',
                '',
                $content
            );
        } else {
            // 替换为生产域名
            $content = preg_replace(
                '#https?://' . preg_quote($this->site_host, '#') . '#i',
                'https://' . $production_domain,
                $content
            );
        }
        
        return $content;
    }
    
    /**
     * 将 sitemap URL 转换为本地路径
     */
    private function url_to_sitemap_path($url)
    {
        $path = parse_url($url, PHP_URL_PATH);
        return ltrim($path, '/');
    }
    
    /**
     * 生成 robots.txt 内容
     */
    private function generate_robots_txt($has_sitemap = false, $sitemap_file = 'sitemap_index.xml')
    {
        $production_domain = get_option('wptocf_production_domain', '');
        
        $content = "User-agent: *\n";
        $content .= "Allow: /\n";
        
        // 添加 sitemap 链接
        if ($has_sitemap && !empty($sitemap_file)) {
            $content .= "\n";
            if (!empty($production_domain)) {
                $content .= "Sitemap: https://{$production_domain}/{$sitemap_file}\n";
            } else {
                $content .= "Sitemap: /{$sitemap_file}\n";
            }
        }
        
        return $content;
    }
}

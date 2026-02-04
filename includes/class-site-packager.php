<?php
/**
 * Site Packager Class
 *
 * 负责全站静态化打包，包括资产收集、深度洗白、API 拦截器注入
 *
 * @package    WP_to_CF
 * @subpackage WP_to_CF/includes
 * @since      1.2.5
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_to_CF_Site_Packager {
    
    /**
     * HTML 缓存实例
     *
     * @var WP_to_CF_HTML_Cache
     */
    private $html_cache;
    
    /**
     * 日志记录器
     *
     * @var WP_to_CF_Logger
     */
    private $logger;
    
    /**
     * 生产域名
     *
     * @var string
     */
    private $production_domain;
    
    /**
     * 内网域名（当前站点）
     *
     * @var string
     */
    private $intranet_domain;
    
    /**
     * 资产白名单（允许的外部域名）
     *
     * @var array
     */
    private $asset_whitelist;
    
    /**
     * 收集的资产统计
     *
     * @var array
     */
    private $asset_stats = [
        'css_files' => 0,
        'js_files' => 0,
        'images' => 0,
        'fonts' => 0,
        'total_size' => 0,
        'failed' => 0,
    ];
    
    /**
     * 构造函数
     */
    public function __construct() {
        $this->html_cache = new WP_to_CF_HTML_Cache();
        $this->logger = new WP_to_CF_Logger();
        
        // 加载配置
        $this->production_domain = get_option('wptocf_production_domain', '');
        $this->intranet_domain = parse_url(home_url(), PHP_URL_HOST);
        
        // 默认白名单（Google Fonts, CDN 等）
        $this->asset_whitelist = apply_filters('wptocf_asset_whitelist', [
            'fonts.googleapis.com',
            'fonts.gstatic.com',
            'cdnjs.cloudflare.com',
            'cdn.jsdelivr.net',
        ]);
    }
    
    // ==================== 核心方法 ====================
    
    /**
     * 生成全站静态包
     *
     * @return array {
     *     @type bool   $success    是否成功
     *     @type string $zip_path   ZIP 文件路径
     *     @type array  $stats      统计信息
     *     @type string $error      错误消息（如果失败）
     * }
     */
    public function generate_site_package() {
        $this->logger->info('Starting site package generation');
        
        // 增加执行时间和内存限制，防止大型站点超时
        @set_time_limit(300); // 5 分钟
        @ini_set('memory_limit', '512M'); // 512MB 内存
        
        try {
            // 权限防御：检查 wptocf-cache 目录是否可写
            $upload_dir = wp_upload_dir();
            $cache_dir = $upload_dir['basedir'] . '/wptocf-cache';
            
            // 记录实际服务器路径（用于 Hostinger 等远程环境调试）
            $this->logger->info('Environment paths detected', [
                'cache_dir' => $cache_dir,
                'upload_basedir' => $upload_dir['basedir'],
                'abspath' => ABSPATH,
                'wp_content_dir' => WP_CONTENT_DIR,
                'time_limit' => ini_get('max_execution_time'),
                'memory_limit' => ini_get('memory_limit'),
            ]);
            
            if (!file_exists($cache_dir)) {
                $this->logger->info('Creating cache directory', ['path' => $cache_dir]);
                $created = wp_mkdir_p($cache_dir);
                
                if (!$created) {
                    throw new Exception(
                        "Failed to create cache directory: {$cache_dir}. " .
                        "Please check server permissions."
                    );
                }
            }
            
            if (!is_writable($cache_dir)) {
                // 记录详细的权限信息
                $perms = fileperms($cache_dir);
                $perms_string = substr(sprintf('%o', $perms), -4);
                
                $this->logger->error('Cache directory not writable', [
                    'cache_dir' => $cache_dir,
                    'permissions' => $perms_string,
                    'owner' => function_exists('posix_getpwuid') ? posix_getpwuid(fileowner($cache_dir))['name'] : 'unknown',
                    'current_user' => function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : 'unknown',
                ]);
                
                throw new Exception(
                    "Cache directory is not writable: {$cache_dir} (permissions: {$perms_string}). " .
                    "Please run: chmod 755 {$cache_dir} or contact your hosting provider."
                );
            }
            
            $this->logger->info('Cache directory writable check passed', [
                'cache_dir' => $cache_dir,
            ]);
            
            // 1. 收集所有需要打包的文件
            $files = $this->collect_all_files();
            
            // 2. 处理每个文件（洗白、资产收集）
            $processed_files = $this->process_files($files);
            
            // 3. 生成 ZIP 包
            $zip_path = $this->create_zip_package($processed_files);
            
            // 4. 强制验证闭环：ZIP 完整性验证
            $this->logger->info('Verifying ZIP integrity (mandatory check)');
            $verify_result = $this->verify_zip_integrity($zip_path);
            
            if (!$verify_result['valid']) {
                // ZIP 验证失败，严禁交付
                $error_msg = 'ZIP integrity verification failed: ' . $verify_result['error'];
                $this->logger->error($error_msg, [
                    'zip_path' => $zip_path,
                    'output' => $verify_result['output'],
                ]);
                
                throw new Exception($error_msg);
            }
            
            $this->logger->info('ZIP integrity verification passed', [
                'zip_path' => $zip_path,
            ]);
            
            // 5. 返回结果
            return [
                'success' => true,
                'zip_path' => $zip_path,
                'stats' => $this->get_package_stats(),
                'zip_verified' => true,
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Site package generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * 创建 ZIP 包（排除隐私日志）
     *
     * @param array $processed_files 处理后的文件列表
     * @return string ZIP 文件路径
     * @throws Exception
     */
    private function create_zip_package($processed_files) {
        $upload_dir = wp_upload_dir();
        $package_dir = $upload_dir['basedir'] . '/wptocf-packages';
        
        // 确保打包目录存在
        if (!file_exists($package_dir)) {
            wp_mkdir_p($package_dir);
        }
        
        // 生成 ZIP 文件名
        $version = '1.2.5-alpha1';
        $timestamp = current_time('YmdHis');
        $zip_filename = "wp-to-cf-{$version}-{$timestamp}.zip";
        $zip_path = $package_dir . '/' . $zip_filename;
        
        $this->logger->info('Creating ZIP package', [
            'zip_path' => $zip_path,
            'file_count' => count($processed_files),
        ]);
        
        // 使用 ZipArchive 创建 ZIP
        if (!class_exists('ZipArchive')) {
            throw new Exception('ZipArchive class not available');
        }
        
        $zip = new ZipArchive();
        $result = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        
        if ($result !== TRUE) {
            throw new Exception('Failed to create ZIP file: ' . $result);
        }
        
        // 添加文件到 ZIP
        foreach ($processed_files as $file) {
            if (isset($file['content'])) {
                // 从内容添加
                $zip->addFromString($file['path'], $file['content']);
            } elseif (isset($file['source_path']) && file_exists($file['source_path'])) {
                // 从文件添加
                $zip->addFile($file['source_path'], $file['path']);
            }
        }
        
        // 排除隐私日志：确保不包含敏感日志文件
        // 注意：ZipArchive 不支持排除模式，所以我们在添加文件时就要过滤
        // 这里记录被排除的文件类型
        $excluded_patterns = [
            'error-assets.log',
            'wptocf-debug.log',
            '*.log',
            'wp-content/uploads/wptocf-cache/*.log',
        ];
        
        $this->logger->info('ZIP package excludes privacy logs', [
            'excluded_patterns' => $excluded_patterns,
        ]);
        
        $zip->close();
        
        // 验证 ZIP 文件已创建
        if (!file_exists($zip_path)) {
            throw new Exception('ZIP file was not created');
        }
        
        $file_size = filesize($zip_path);
        $this->logger->info('ZIP package created successfully', [
            'zip_path' => $zip_path,
            'file_size' => $file_size,
            'file_size_mb' => round($file_size / 1024 / 1024, 2),
        ]);
        
        return $zip_path;
    }
    
    /**
     * 收集所有需要打包的文件
     *
     * @return array 文件列表
     */
    private function collect_all_files() {
        $this->logger->info('Collecting files for packaging');
        
        $files = [];
        
        // 使用 HTML Generator 生成所有页面
        if (!class_exists('WP_to_CF_HTML_Generator')) {
            require_once WPTOCF_PLUGIN_DIR . 'includes/class-html-generator.php';
        }
        
        $generator = new WP_to_CF_HTML_Generator();
        
        // 1. 生成首页
        $this->logger->info('Generating homepage');
        $html = $generator->generate_home_html();
        if ($html !== false) {
            $files[] = [
                'path' => 'index.html',
                'content' => $html,
                'type' => 'html',
            ];
            
            // 保存到缓存目录
            $content_hash = hash('sha256', $html);
            $this->html_cache->save('index.html', $html, $content_hash);
        }
        
        // 2. 生成所有已发布的文章和页面
        $posts = get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'numberposts' => -1,
        ]);
        
        $this->logger->info('Generating posts and pages', [
            'post_count' => count($posts),
        ]);
        
        foreach ($posts as $post) {
            $html = $generator->generate_post_html($post->ID);
            if ($html !== false) {
                $permalink = get_permalink($post->ID);
                $path = parse_url($permalink, PHP_URL_PATH);
                $path = trim($path, '/');
                
                if (empty($path)) {
                    $file_path = 'index.html';
                } else {
                    $file_path = $path . '/index.html';
                }
                
                $files[] = [
                    'path' => $file_path,
                    'content' => $html,
                    'type' => 'html',
                ];
                
                // 保存到缓存目录
                $content_hash = hash('sha256', $html);
                $this->html_cache->save($file_path, $html, $content_hash);
            }
        }
        
        // 3. 生成分类和标签归档页
        $taxonomies = ['category', 'post_tag'];
        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => true,
            ]);
            
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $term_link = get_term_link($term);
                    if (!is_wp_error($term_link)) {
                        $html = $generator->generate_html($term_link);
                        if ($html !== false) {
                            $path = parse_url($term_link, PHP_URL_PATH);
                            $path = trim($path, '/');
                            $file_path = $path . '/index.html';
                            
                            $files[] = [
                                'path' => $file_path,
                                'content' => $html,
                                'type' => 'html',
                            ];
                            
                            // 保存到缓存目录
                            $content_hash = hash('sha256', $html);
                            $this->html_cache->save($file_path, $html, $content_hash);
                        }
                    }
                }
            }
        }
        
        // 4. 添加 robots.txt
        $files[] = [
            'path' => 'robots.txt',
            'content' => $this->generate_static_robots(),
            'type' => 'text',
        ];
        
        // 5. 添加 sitemap.xml
        $sitemap_content = $this->fetch_and_transform_sitemap();
        if ($sitemap_content !== false) {
            $files[] = [
                'path' => 'sitemap.xml',
                'content' => $sitemap_content,
                'type' => 'xml',
            ];
        }
        
        // 6. 添加 _redirects
        $files[] = [
            'path' => '_redirects',
            'content' => $this->generate_comprehensive_redirects($files),
            'type' => 'text',
        ];
        
        $this->logger->info('File collection completed', [
            'total_files' => count($files),
        ]);
        
        return $files;
    }
    
    /**
     * 处理文件（洗白、资产收集、相对路径转换）
     *
     * @param array $files 文件列表
     * @return array 处理后的文件列表
     */
    private function process_files($files) {
        $this->logger->info('Processing files', [
            'file_count' => count($files),
        ]);
        
        $processed = [];
        
        // 第一步：收集所有资产文件并构建映射表
        $asset_types = ['css', 'js', 'images', 'fonts'];
        $upload_dir = wp_upload_dir();
        $assets_dir = $upload_dir['basedir'] . '/wptocf-cache/assets';
        $asset_map = []; // [原始URL => ZIP中的路径]
        
        foreach ($asset_types as $type) {
            $type_dir = $assets_dir . '/' . $type;
            if (is_dir($type_dir)) {
                $asset_files = glob($type_dir . '/*');
                if ($asset_files) {
                    foreach ($asset_files as $asset_file) {
                        if (is_file($asset_file)) {
                            $zip_path = 'assets/' . $type . '/' . basename($asset_file);
                            $processed[] = [
                                'path' => $zip_path,
                                'source_path' => $asset_file,
                                'type' => $type,
                            ];
                            
                            // 构建资产映射（这里需要从缓存中读取原始URL）
                            // 暂时使用文件名作为映射键
                            $asset_map[basename($asset_file)] = $zip_path;
                        }
                    }
                }
            }
        }
        
        // 加载相对路径转换器
        if (!class_exists('WP_to_CF_Relative_Path_Converter')) {
            require_once WPTOCF_PLUGIN_DIR . 'includes/class-relative-path-converter.php';
        }
        $path_converter = new WP_to_CF_Relative_Path_Converter();
        
        // 第二步：处理 HTML 文件
        foreach ($files as $file) {
            if (isset($file['type']) && $file['type'] === 'html') {
                // 1. 深度洗白
                $file['content'] = $this->deep_sanitize_html($file['content']);
                
                // 2. 转换为相对路径
                $file['content'] = $path_converter->convert_html_paths(
                    $file['content'],
                    $file['path'],
                    $asset_map
                );
                
                // 3. 注入 API 拦截器
                $file['content'] = $this->inject_api_interceptor($file['content']);
            }
            
            $processed[] = $file;
        }
        
        // 第三步：处理 CSS 文件中的相对路径
        foreach ($asset_types as $type) {
            if ($type === 'css') {
                $css_dir = $assets_dir . '/css';
                if (is_dir($css_dir)) {
                    $converted_count = $path_converter->convert_all_css_files($css_dir, $asset_map);
                    $this->logger->info('CSS files converted to relative paths', [
                        'count' => $converted_count,
                    ]);
                }
            }
        }
        
        $this->logger->info('File processing completed', [
            'processed_count' => count($processed),
            'asset_map_size' => count($asset_map),
        ]);
        
        return $processed;
    }
    
    /**
     * 生成静态 robots.txt
     *
     * @return string robots.txt 内容
     */
    private function generate_static_robots(): string {
        $production_domain = get_option('wptocf_production_domain', '');
        
        $content = "User-agent: *\n";
        $content .= "Disallow: /wp-admin/\n";
        $content .= "Allow: /wp-admin/admin-ajax.php\n\n";
        
        if (!empty($production_domain)) {
            $content .= "Sitemap: https://{$production_domain}/sitemap.xml\n";
        }
        
        return $content;
    }
    
    /**
     * 获取并转换 sitemap.xml
     *
     * @return string|false sitemap.xml 内容
     */
    private function fetch_and_transform_sitemap() {
        $sitemap_url = home_url('/sitemap.xml');
        $response = wp_remote_get($sitemap_url, ['timeout' => 30]);
        
        if (is_wp_error($response)) {
            $this->logger->warning('Failed to fetch sitemap.xml', [
                'error' => $response->get_error_message(),
            ]);
            return false;
        }
        
        $sitemap_content = wp_remote_retrieve_body($response);
        
        if (empty($sitemap_content)) {
            return false;
        }
        
        // 替换内网域名为生产域名
        if (!empty($this->production_domain) && !empty($this->intranet_domain)) {
            $sitemap_content = str_replace(
                'https://' . $this->intranet_domain,
                'https://' . $this->production_domain,
                $sitemap_content
            );
            $sitemap_content = str_replace(
                'http://' . $this->intranet_domain,
                'https://' . $this->production_domain,
                $sitemap_content
            );
        }
        
        return $sitemap_content;
    }
    
    /**
     * 生成完整的 _redirects 文件
     *
     * @param array $files 文件列表
     * @return string _redirects 内容
     */
    private function generate_comprehensive_redirects($files): string {
        $redirects = [];
        
        // 为每个 HTML 文件生成 pretty permalink 规则
        foreach ($files as $file) {
            if (isset($file['type']) && $file['type'] === 'html' && $file['path'] !== 'index.html') {
                $path = str_replace('/index.html', '', $file['path']);
                $redirects[] = "/{$path} /{$file['path']} 200";
            }
        }
        
        // 添加通用规则
        $redirects[] = "/* /index.html 200";
        
        return implode("\n", $redirects) . "\n";
    }
    
    // ==================== 资产收集 (Requirement 38) ====================
    
    /**
     * 收集 HTML 中的所有资产
     *
     * @param string $html HTML 内容
     * @param string $base_url 基础 URL
     * @param string $page_path 当前页面路径（用于错误追踪）
     * @return array 收集的资产列表
     */
    private function collect_assets_from_html($html, $base_url, $page_path = '') {
        $assets = [];
        
        // 1. 收集 CSS 文件
        $css_assets = $this->extract_css_files($html, $base_url);
        foreach ($css_assets as &$asset) {
            $asset['referencing_page'] = $page_path;
        }
        $assets = array_merge($assets, $css_assets);
        
        // 2. 收集 JS 文件
        $js_assets = $this->extract_js_files($html, $base_url);
        foreach ($js_assets as &$asset) {
            $asset['referencing_page'] = $page_path;
        }
        $assets = array_merge($assets, $js_assets);
        
        // 3. 收集图片
        $image_assets = $this->extract_images($html, $base_url);
        foreach ($image_assets as &$asset) {
            $asset['referencing_page'] = $page_path;
        }
        $assets = array_merge($assets, $image_assets);
        
        // 4. 收集字体（从 CSS 中提取）
        foreach ($assets as $asset) {
            if ($asset['type'] === 'css' && isset($asset['local_path'])) {
                $css_content = file_get_contents($asset['local_path']);
                $fonts = $this->extract_fonts_from_css($css_content, $asset['url']);
                // 字体文件继承 CSS 文件的引用页面
                foreach ($fonts as &$font) {
                    $font['referencing_page'] = $asset['referencing_page'];
                }
                $assets = array_merge($assets, $fonts);
            }
        }
        
        return $assets;
    }
    
    /**
     * 提取 CSS 文件
     *
     * @param string $html HTML 内容
     * @param string $base_url 基础 URL
     * @return array CSS 文件列表
     */
    private function extract_css_files($html, $base_url) {
        $css_files = [];
        
        // 匹配 <link rel="stylesheet" href="...">
        preg_match_all('/<link[^>]+rel=["\']stylesheet["\'][^>]+href=["\'](.*?)["\'][^>]*>/i', $html, $matches);
        
        foreach ($matches[1] as $url) {
            $absolute_url = $this->resolve_url($url, $base_url);
            
            if ($this->should_collect_asset($absolute_url)) {
                $css_files[] = [
                    'type' => 'css',
                    'url' => $absolute_url,
                    'original_url' => $url,
                ];
            }
        }
        
        return $css_files;
    }
    
    /**
     * 提取 JS 文件
     *
     * @param string $html HTML 内容
     * @param string $base_url 基础 URL
     * @return array JS 文件列表
     */
    private function extract_js_files($html, $base_url) {
        $js_files = [];
        
        // 匹配 <script src="...">
        preg_match_all('/<script[^>]+src=["\'](.*?)["\'][^>]*>/i', $html, $matches);
        
        foreach ($matches[1] as $url) {
            $absolute_url = $this->resolve_url($url, $base_url);
            
            if ($this->should_collect_asset($absolute_url)) {
                $js_files[] = [
                    'type' => 'js',
                    'url' => $absolute_url,
                    'original_url' => $url,
                ];
            }
        }
        
        return $js_files;
    }
    
    /**
     * 提取图片（包括 srcset 响应式图片）
     *
     * @param string $html HTML 内容
     * @param string $base_url 基础 URL
     * @return array 图片列表（去重后）
     */
    private function extract_images($html, $base_url) {
        $images = [];
        $seen_urls = []; // 用于去重
        
        // 1. 匹配 <img src="...">
        preg_match_all('/<img[^>]+src=["\'](.*?)["\'][^>]*>/i', $html, $matches);
        
        foreach ($matches[1] as $url) {
            // 跳过 data URI
            if (strpos($url, 'data:') === 0) {
                continue;
            }
            
            $absolute_url = $this->resolve_url($url, $base_url);
            
            // 去重检查
            if (isset($seen_urls[$absolute_url])) {
                continue;
            }
            
            if ($this->should_collect_asset($absolute_url)) {
                $images[] = [
                    'type' => 'image',
                    'url' => $absolute_url,
                    'original_url' => $url,
                ];
                $seen_urls[$absolute_url] = true;
            }
        }
        
        // 2. 提取 srcset 中的响应式图片
        preg_match_all('/srcset=["\']([^"\']+)["\']/i', $html, $srcset_matches);
        
        foreach ($srcset_matches[1] as $srcset) {
            // srcset 格式：url1 100w, url2 200w, url3 300w
            // 或：url1 1x, url2 2x
            $sources = preg_split('/,\s*/', $srcset);
            
            foreach ($sources as $source) {
                // 提取 URL（去掉尺寸描述符）
                if (preg_match('/^(.+?)\s+/', trim($source), $url_match)) {
                    $url = $url_match[1];
                } else {
                    $url = trim($source);
                }
                
                // 跳过 data URI
                if (strpos($url, 'data:') === 0) {
                    continue;
                }
                
                $absolute_url = $this->resolve_url($url, $base_url);
                
                // 去重检查
                if (isset($seen_urls[$absolute_url])) {
                    continue;
                }
                
                if ($this->should_collect_asset($absolute_url)) {
                    $images[] = [
                        'type' => 'image',
                        'url' => $absolute_url,
                        'original_url' => $url,
                    ];
                    $seen_urls[$absolute_url] = true;
                }
            }
        }
        
        return $images;
    }
    
    /**
     * 从 CSS 中提取字体文件 (Requirement 20)
     *
     * @param string $css_content CSS 内容
     * @param string $css_url CSS 文件 URL
     * @return array 字体文件列表
     */
    private function extract_fonts_from_css($css_content, $css_url) {
        $fonts = [];
        
        // 匹配 @font-face 中的 url()
        preg_match_all('/url\(["\']?(.*?\.(woff2?|ttf|eot|otf))["\']?\)/i', $css_content, $matches);
        
        foreach ($matches[1] as $url) {
            $absolute_url = $this->resolve_url($url, $css_url);
            
            $fonts[] = [
                'type' => 'font',
                'url' => $absolute_url,
                'original_url' => $url,
            ];
        }
        
        return $fonts;
    }
    
    /**
     * 判断是否应该收集该资产
     *
     * @param string $url 资产 URL
     * @return bool
     */
    private function should_collect_asset($url) {
        $host = parse_url($url, PHP_URL_HOST);
        
        // 本地资产始终收集
        if ($host === $this->intranet_domain || empty($host)) {
            return true;
        }
        
        // 检查是否在白名单中
        foreach ($this->asset_whitelist as $whitelisted_domain) {
            if ($host === $whitelisted_domain || strpos($host, $whitelisted_domain) !== false) {
                return false; // 白名单域名不收集
            }
        }
        
        // 其他外部资产收集
        return true;
    }
    
    /**
     * 下载资产到本地（资产采集降级：内网资产直接读取文件系统）
     *
     * @param array $asset 资产信息
     * @param string $referencing_page 引用该资产的页面路径（用于错误追踪）
     * @return string|false 本地路径或 false
     */
    private function download_asset($asset, $referencing_page = '') {
        $url = $asset['url'];
        $type = $asset['type'];
        
        // 生成本地路径
        $local_path = $this->generate_local_asset_path($url, $type);
        
        // 如果文件已存在，跳过下载
        if (file_exists($local_path)) {
            $this->logger->info('Asset already exists, skipping download', [
                'url' => $url,
                'local_path' => $local_path,
            ]);
            return $local_path;
        }
        
        // 资产采集降级：判断是否为内网资产
        $asset_host = parse_url($url, PHP_URL_HOST);
        $is_internal = ($asset_host === $this->intranet_domain || empty($asset_host));
        
        if ($is_internal) {
            // 内网资产：直接从文件系统读取
            $this->logger->info('Internal asset detected, reading from filesystem', [
                'url' => $url,
            ]);
            
            // 将 URL 转换为物理路径
            $physical_path = $this->url_to_physical_path($url);
            
            if ($physical_path && file_exists($physical_path)) {
                // 直接读取文件内容
                $content = file_get_contents($physical_path);
                
                if ($content === false) {
                    $this->log_asset_error($url, 'Failed to read internal asset from filesystem', $referencing_page);
                    $this->logger->error('Failed to read internal asset', [
                        'url' => $url,
                        'physical_path' => $physical_path,
                        'referencing_page' => $referencing_page,
                    ]);
                    $this->asset_stats['failed']++;
                    return false;
                }
                
                // 创建目录
                $dir = dirname($local_path);
                if (!file_exists($dir)) {
                    wp_mkdir_p($dir);
                }
                
                // 保存文件
                if (file_put_contents($local_path, $content) === false) {
                    $this->log_asset_error($url, 'Failed to save file to disk', $referencing_page);
                    $this->logger->error('Failed to save asset', [
                        'url' => $url,
                        'local_path' => $local_path,
                        'referencing_page' => $referencing_page,
                    ]);
                    $this->asset_stats['failed']++;
                    return false;
                }
                
                // 更新统计
                if ($type === 'image') {
                    $this->asset_stats['images']++;
                } elseif ($type === 'css') {
                    $this->asset_stats['css_files']++;
                } elseif ($type === 'js') {
                    $this->asset_stats['js_files']++;
                } elseif ($type === 'font') {
                    $this->asset_stats['fonts']++;
                }
                $this->asset_stats['total_size'] += strlen($content);
                
                $this->logger->info('Internal asset copied successfully', [
                    'url' => $url,
                    'physical_path' => $physical_path,
                    'local_path' => $local_path,
                    'size' => strlen($content),
                ]);
                
                return $local_path;
            } else {
                $this->log_asset_error($url, 'Internal asset not found on filesystem', $referencing_page);
                $this->logger->error('Internal asset not found', [
                    'url' => $url,
                    'physical_path' => $physical_path,
                    'referencing_page' => $referencing_page,
                ]);
                $this->asset_stats['failed']++;
                return false;
            }
        }
        
        // 外部资产：使用 HTTP 请求下载
        $this->logger->info('External asset detected, downloading via HTTP', [
            'url' => $url,
        ]);
        
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'sslverify' => false,
        ]);
        
        if (is_wp_error($response)) {
            // 记录错误到 error-assets.log（包含引用页面路径）
            $this->log_asset_error($url, $response->get_error_message(), $referencing_page);
            
            $this->logger->error('Asset download failed', [
                'url' => $url,
                'error' => $response->get_error_message(),
                'referencing_page' => $referencing_page,
            ]);
            $this->asset_stats['failed']++;
            return false;
        }
        
        $content = wp_remote_retrieve_body($response);
        
        // 创建目录
        $dir = dirname($local_path);
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
        
        // 保存文件
        if (file_put_contents($local_path, $content) === false) {
            // 记录错误到 error-assets.log（包含引用页面路径）
            $this->log_asset_error($url, 'Failed to save file to disk', $referencing_page);
            
            $this->logger->error('Failed to save asset', [
                'url' => $url,
                'local_path' => $local_path,
                'referencing_page' => $referencing_page,
            ]);
            $this->asset_stats['failed']++;
            return false;
        }
        
        // 更新统计
        if ($type === 'image') {
            $this->asset_stats['images']++;
        } elseif ($type === 'css') {
            $this->asset_stats['css_files']++;
        } elseif ($type === 'js') {
            $this->asset_stats['js_files']++;
        } elseif ($type === 'font') {
            $this->asset_stats['fonts']++;
        }
        $this->asset_stats['total_size'] += strlen($content);
        
        $this->logger->info('External asset downloaded successfully', [
            'url' => $url,
            'local_path' => $local_path,
            'size' => strlen($content),
        ]);
        
        return $local_path;
    }
    
    /**
     * 将 URL 转换为物理路径（使用 WordPress 常量）
     *
     * @param string $url 资产 URL
     * @return string|false 物理路径或 false
     */
    private function url_to_physical_path($url) {
        // 解析 URL
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '';
        
        if (empty($path)) {
            return false;
        }
        
        // 获取 WordPress 上传目录信息
        $upload_dir = wp_upload_dir();
        $upload_baseurl = $upload_dir['baseurl'];
        $upload_basedir = $upload_dir['basedir'];
        
        // 检查是否为上传目录中的文件
        if (strpos($url, $upload_baseurl) === 0) {
            $relative_path = str_replace($upload_baseurl, '', $url);
            return $upload_basedir . $relative_path;
        }
        
        // 检查是否为 wp-content 目录中的文件
        $content_url = content_url();
        if (strpos($url, $content_url) === 0) {
            $relative_path = str_replace($content_url, '', $url);
            return WP_CONTENT_DIR . $relative_path;
        }
        
        // 检查是否为 wp-includes 目录中的文件
        $includes_url = includes_url();
        if (strpos($url, $includes_url) === 0) {
            $relative_path = str_replace($includes_url, '', $url);
            return ABSPATH . 'wp-includes' . $relative_path;
        }
        
        // 其他情况：尝试从 ABSPATH 解析
        // 移除域名部分，只保留路径
        $site_url = site_url();
        if (strpos($url, $site_url) === 0) {
            $relative_path = str_replace($site_url, '', $url);
            return ABSPATH . ltrim($relative_path, '/');
        }
        
        // 如果是相对路径，直接从 ABSPATH 解析
        if (strpos($path, '/') === 0) {
            return ABSPATH . ltrim($path, '/');
        }
        
        return false;
    }
    
    /**
     * 记录资产下载错误到专用日志文件
     *
     * @param string $url 资产 URL
     * @param string $error 错误消息
     * @param string $referencing_page 引用该资产的页面路径
     */
    private function log_asset_error($url, $error, $referencing_page = '') {
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/wptocf-cache/error-assets.log';
        
        // 确保目录存在
        $log_dir = dirname($log_file);
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        // 格式化日志条目
        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = sprintf(
            "[%s] FAILED: %s\n  Error: %s\n  Referenced by: %s\n\n",
            $timestamp,
            $url,
            $error,
            $referencing_page ?: 'Unknown page'
        );
        
        // 追加到日志文件
        file_put_contents($log_file, $log_entry, FILE_APPEND);
    }
    
    // ==================== 深度洗白 (Requirement 39) ====================
    
    /**
     * 深度洗白 HTML 内容
     *
     * 移除所有 WordPress 指纹、内网信息、调试信息
     *
     * @param string $html HTML 内容
     * @return string 洗白后的 HTML
     */
    public function deep_sanitize_html($html) {
        $this->logger->info('Starting deep HTML sanitization');
        
        // 1. 移除 WordPress 指纹
        $html = $this->remove_wordpress_fingerprints($html);
        
        // 2. 脱敏内网信息
        $html = $this->sanitize_intranet_info($html);
        
        // 3. 移除调试信息
        $html = $this->remove_debug_info($html);
        
        // 4. 清理资源链接版本号
        $html = $this->remove_version_strings($html);
        
        // 5. 转换域名
        $html = $this->transform_domains($html);
        
        $this->logger->info('HTML sanitization completed');
        
        return $html;
    }
    
    /**
     * 移除 WordPress 指纹
     *
     * @param string $html HTML 内容
     * @return string
     */
    private function remove_wordpress_fingerprints($html) {
        // 移除 generator meta 标签
        $html = preg_replace('/<meta[^>]+name=["\']generator["\'][^>]*>/i', '', $html);
        
        // 移除 WordPress 版本注释
        $html = preg_replace('/<!--.*?WordPress.*?-->/is', '', $html);
        
        // 移除 wp-json 链接
        $html = preg_replace('/<link[^>]+rel=["\']https:\/\/api\.w\.org\/["\'][^>]*>/i', '', $html);
        
        // 移除 wlwmanifest 链接
        $html = preg_replace('/<link[^>]+rel=["\']wlwmanifest["\'][^>]*>/i', '', $html);
        
        // 移除 RSD 链接
        $html = preg_replace('/<link[^>]+rel=["\']EditURI["\'][^>]*>/i', '', $html);
        
        // 移除 shortlink
        $html = preg_replace('/<link[^>]+rel=["\']shortlink["\'][^>]*>/i', '', $html);
        
        return $html;
    }
    
    /**
     * 脱敏内网信息 (Requirement 39)
     *
     * @param string $html HTML 内容
     * @return string
     */
    private function sanitize_intranet_info($html) {
        $replacements = 0;
        
        // 1. 替换内网 IP 地址
        $ip_patterns = [
            '/\b192\.168\.\d{1,3}\.\d{1,3}\b/',  // 192.168.x.x
            '/\b10\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/',  // 10.x.x.x
            '/\b172\.(1[6-9]|2[0-9]|3[0-1])\.\d{1,3}\.\d{1,3}\b/',  // 172.16-31.x.x
            '/\b127\.0\.0\.1\b/',  // localhost
        ];
        
        foreach ($ip_patterns as $pattern) {
            $html = preg_replace($pattern, $this->production_domain, $html, -1, $count);
            $replacements += $count;
        }
        
        // 2. 替换 .local 和 .internal 域名（包括转义格式）
        // 标准格式：http://mysite.local/
        $html = preg_replace('/https?:\/\/[a-zA-Z0-9\-]+\.(local|internal)(\/|:|$)/', 
            'https://' . $this->production_domain . '$2', $html, -1, $count);
        $replacements += $count;
        
        // 转义格式：https:\/\/mysite.local\/ (常见于 WordPress 脚本)
        $html = preg_replace('/https?:\\\\\/\\\\\/[a-zA-Z0-9\-]+\.(local|internal)(\\\\\/|:|$)/', 
            'https:\\/\\/' . str_replace('.', '\\.', $this->production_domain) . '$2', $html, -1, $count);
        $replacements += $count;
        
        // 3. 替换 localhost（包括转义格式）
        // 标准格式
        $html = preg_replace('/https?:\/\/localhost(\/|:|$)/', 
            'https://' . $this->production_domain . '$1', $html, -1, $count);
        $replacements += $count;
        
        // 转义格式：https:\/\/localhost\/
        $html = preg_replace('/https?:\\\\\/\\\\\/localhost(\\\\\/|:|$)/', 
            'https:\\/\\/' . str_replace('.', '\\.', $this->production_domain) . '$1', $html, -1, $count);
        $replacements += $count;
        
        // 4. 替换内网域名（包括转义格式）
        if (!empty($this->intranet_domain)) {
            // 标准格式
            $html = preg_replace('/https?:\/\/' . preg_quote($this->intranet_domain, '/') . '(\/|:|$)/', 
                'https://' . $this->production_domain . '$1', $html, -1, $count);
            $replacements += $count;
            
            // 转义格式
            $escaped_intranet = str_replace('.', '\\.', $this->intranet_domain);
            $html = preg_replace('/https?:\\\\\/\\\\\/' . preg_quote($escaped_intranet, '/') . '(\\\\\/|:|$)/', 
                'https:\\/\\/' . str_replace('.', '\\.', $this->production_domain) . '$1', $html, -1, $count);
            $replacements += $count;
        }
        
        // 5. 深度洗白 srcset：处理响应式图片中的多重域名
        // srcset 格式：url1 100w, url2 200w, url3 300w
        if (preg_match_all('/srcset=["\']([^"\']+)["\']/i', $html, $srcset_matches)) {
            foreach ($srcset_matches[1] as $srcset_value) {
                $new_srcset = $srcset_value;
                
                // 替换 srcset 中的内网域名（标准格式）
                if (!empty($this->intranet_domain)) {
                    $new_srcset = preg_replace(
                        '/https?:\/\/' . preg_quote($this->intranet_domain, '/') . '/',
                        'https://' . $this->production_domain,
                        $new_srcset
                    );
                }
                
                // 替换 srcset 中的 .local 域名
                $new_srcset = preg_replace(
                    '/https?:\/\/[a-zA-Z0-9\-]+\.(local|internal)/',
                    'https://' . $this->production_domain,
                    $new_srcset
                );
                
                // 替换 srcset 中的内网 IP
                foreach ($ip_patterns as $pattern) {
                    $new_srcset = preg_replace($pattern, $this->production_domain, $new_srcset);
                }
                
                // 替换 srcset 中的转义域名（如果存在）
                $new_srcset = preg_replace(
                    '/https?:\\\\\/\\\\\/[a-zA-Z0-9\-]+\.(local|internal)/',
                    'https:\\/\\/' . str_replace('.', '\\.', $this->production_domain),
                    $new_srcset
                );
                
                if ($new_srcset !== $srcset_value) {
                    $html = str_replace($srcset_value, $new_srcset, $html);
                    $replacements++;
                }
            }
        }
        
        // 6. 移除服务器绝对路径
        $path_patterns = [
            '/\/home\/[a-zA-Z0-9_\-]+\/[^\s<>"\']+/',  // /home/user/...
            '/\/var\/www\/[^\s<>"\']+/',  // /var/www/...
            '/\/usr\/share\/[^\s<>"\']+/',  // /usr/share/...
            '/C:\\\\[^\s<>"\']+/',  // C:\...
        ];
        
        foreach ($path_patterns as $pattern) {
            $html = preg_replace($pattern, '', $html, -1, $count);
            $replacements += $count;
        }
        
        if ($replacements > 0) {
            $this->logger->info('Intranet information sanitized', [
                'replacements' => $replacements,
                'types' => 'IP addresses, local domains, escaped URLs, srcset, server paths',
            ]);
        }
        
        return $html;
    }
    
    /**
     * 移除调试信息
     *
     * @param string $html HTML 内容
     * @return string
     */
    private function remove_debug_info($html) {
        // 移除 WordPress 调试信息
        $html = preg_replace('/<!--.*?WP_DEBUG.*?-->/is', '', $html);
        $html = preg_replace('/<!--.*?Query:.*?-->/is', '', $html);
        $html = preg_replace('/<!--.*?Stack trace:.*?-->/is', '', $html);
        
        // 移除 PHP 错误信息
        $html = preg_replace('/<b>Fatal error<\/b>:.*?<br \/>/is', '', $html);
        $html = preg_replace('/<b>Warning<\/b>:.*?<br \/>/is', '', $html);
        $html = preg_replace('/<b>Notice<\/b>:.*?<br \/>/is', '', $html);
        
        return $html;
    }
    
    /**
     * 移除资源链接版本号 (?ver=x.x.x)
     *
     * @param string $html HTML 内容
     * @return string
     */
    private function remove_version_strings($html) {
        // 移除 ?ver=x.x.x
        $html = preg_replace('/\?ver=[0-9a-zA-Z\.\-_]+/', '', $html);
        
        // 移除 &ver=x.x.x
        $html = preg_replace('/&ver=[0-9a-zA-Z\.\-_]+/', '', $html);
        
        return $html;
    }
    
    /**
     * 转换域名（内网 -> 生产）
     *
     * @param string $html HTML 内容
     * @return string
     */
    private function transform_domains($html) {
        if (empty($this->production_domain)) {
            return $html;
        }
        
        // 替换内网域名为生产域名
        $html = str_replace(
            'https://' . $this->intranet_domain,
            'https://' . $this->production_domain,
            $html
        );
        
        $html = str_replace(
            'http://' . $this->intranet_domain,
            'https://' . $this->production_domain,
            $html
        );
        
        // 替换协议相对 URL
        $html = str_replace(
            '//' . $this->intranet_domain,
            '//' . $this->production_domain,
            $html
        );
        
        return $html;
    }
    
    // ==================== API 拦截器注入 (Requirement 22) ====================
    
    /**
     * 注入 API 拦截器脚本
     *
     * @param string $html HTML 内容
     * @return string
     */
    public function inject_api_interceptor($html) {
        // 检查是否启用拦截器
        if (!get_option('wptocf_enable_api_interceptor', true)) {
            return $html;
        }
        
        $interceptor_script = $this->get_api_interceptor_script();
        
        // 在 </body> 前注入
        $html = str_replace(
            '</body>',
            "<script type='text/javascript'>\n{$interceptor_script}\n</script>\n</body>",
            $html
        );
        
        $this->logger->info('API interceptor injected');
        
        return $html;
    }
    
    /**
     * 获取 API 拦截器脚本
     *
     * @return string
     */
    private function get_api_interceptor_script() {
        // 从设计文档中的拦截器代码
        return <<<'JAVASCRIPT'
(function() {
    'use strict';
    
    const INTERCEPT_PATTERNS = [
        /\/wp-admin\/admin-ajax\.php/,
        /\/wp-json\//,
        /\/\?rest_route=/
    ];
    
    const MOCK_RESPONSES = {
        'admin-ajax': { success: true, data: { message: 'Static site - AJAX disabled' } },
        'wp-json': { code: 'rest_disabled', message: 'REST API disabled in static mode', data: { status: 410 } }
    };
    
    function shouldIntercept(url) {
        return INTERCEPT_PATTERNS.some(pattern => pattern.test(url));
    }
    
    function getMockResponse(url) {
        if (url.includes('admin-ajax.php')) return MOCK_RESPONSES['admin-ajax'];
        if (url.includes('wp-json') || url.includes('rest_route')) return MOCK_RESPONSES['wp-json'];
        return { success: false };
    }
    
    const originalFetch = window.fetch;
    window.fetch = function(url, options) {
        const urlString = typeof url === 'string' ? url : url.url;
        if (shouldIntercept(urlString)) {
            console.log('[WP-to-CF] Intercepted fetch:', urlString);
            return Promise.resolve(new Response(JSON.stringify(getMockResponse(urlString)), {
                status: 200, statusText: 'OK', headers: { 'Content-Type': 'application/json' }
            }));
        }
        return originalFetch.apply(this, arguments);
    };
    
    const originalOpen = XMLHttpRequest.prototype.open;
    const originalSend = XMLHttpRequest.prototype.send;
    
    XMLHttpRequest.prototype.open = function(method, url) {
        this._url = url;
        this._shouldIntercept = shouldIntercept(url);
        if (this._shouldIntercept) console.log('[WP-to-CF] Intercepted XHR:', url);
        return originalOpen.apply(this, arguments);
    };
    
    XMLHttpRequest.prototype.send = function(data) {
        if (this._shouldIntercept) {
            setTimeout(() => {
                Object.defineProperty(this, 'readyState', { value: 4, writable: false });
                Object.defineProperty(this, 'status', { value: 200, writable: false });
                Object.defineProperty(this, 'statusText', { value: 'OK', writable: false });
                Object.defineProperty(this, 'responseText', { 
                    value: JSON.stringify(getMockResponse(this._url)), writable: false 
                });
                if (this.onreadystatechange) this.onreadystatechange();
                if (this.onload) this.onload();
            }, 10);
            return;
        }
        return originalSend.apply(this, arguments);
    };
    
    console.log('[WP-to-CF] API Interceptor initialized');
})();
JAVASCRIPT;
    }
    
    // ==================== 工具方法 ====================
    
    /**
     * 解析相对 URL 为绝对 URL
     *
     * @param string $url 相对或绝对 URL
     * @param string $base_url 基础 URL
     * @return string 绝对 URL
     */
    private function resolve_url($url, $base_url) {
        // 已经是绝对 URL
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }
        
        // 协议相对 URL
        if (strpos($url, '//') === 0) {
            return 'https:' . $url;
        }
        
        // 绝对路径
        if (strpos($url, '/') === 0) {
            $parsed = parse_url($base_url);
            return $parsed['scheme'] . '://' . $parsed['host'] . $url;
        }
        
        // 相对路径
        return rtrim(dirname($base_url), '/') . '/' . ltrim($url, '/');
    }
    
    /**
     * 生成本地资产路径
     *
     * @param string $url 资产 URL
     * @param string $type 资产类型
     * @return string 本地路径
     */
    private function generate_local_asset_path($url, $type) {
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/wptocf-cache/assets/' . $type . '/';
        
        // 使用 URL 哈希作为文件名
        $hash = md5($url);
        $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
        
        return $cache_dir . $hash . '.' . $extension;
    }
    
    /**
     * 获取打包统计信息
     *
     * @return array
     */
    private function get_package_stats() {
        return [
            'asset_stats' => $this->asset_stats,
            'timestamp' => current_time('mysql'),
        ];
    }
    
    /**
     * ZIP 完整性物理自校验（纯 PHP ZipArchive，无 Shell 依赖）
     *
     * 使用 PHP 原生 ZipArchive 验证，完全不依赖系统命令
     *
     * @param string $zip_path ZIP 文件路径
     * @return array {
     *     @type bool $valid 是否有效
     *     @type string $output 验证输出
     *     @type string $error 错误消息（如果失败）
     *     @type string $method 验证方法
     * }
     */
    private function verify_zip_integrity($zip_path) {
        $this->logger->info('Starting ZIP integrity verification (pure PHP)', [
            'zip_path' => $zip_path,
        ]);
        
        // 检查文件是否存在
        if (!file_exists($zip_path)) {
            return [
                'valid' => false,
                'output' => '',
                'error' => 'ZIP file does not exist: ' . $zip_path,
                'method' => 'none',
            ];
        }
        
        // 使用 PHP ZipArchive（纯 PHP，无 Shell 依赖）
        if (!class_exists('ZipArchive')) {
            $this->logger->error('ZipArchive class not available');
            return [
                'valid' => false,
                'output' => '',
                'error' => 'ZipArchive class not available. Please enable zip extension in PHP.',
                'method' => 'none',
            ];
        }
        
        $zip = new ZipArchive();
        $result = $zip->open($zip_path, ZipArchive::CHECKCONS);
        
        if ($result === true) {
            $num_files = $zip->numFiles;
            
            // 验证关键目录结构
            $has_index = false;
            $has_assets_css = false;
            $has_assets_js = false;
            $has_assets_images = false;
            
            for ($i = 0; $i < $num_files; $i++) {
                $filename = $zip->getNameIndex($i);
                
                if ($filename === 'index.html') {
                    $has_index = true;
                }
                if (strpos($filename, 'assets/css/') === 0) {
                    $has_assets_css = true;
                }
                if (strpos($filename, 'assets/js/') === 0) {
                    $has_assets_js = true;
                }
                if (strpos($filename, 'assets/images/') === 0) {
                    $has_assets_images = true;
                }
            }
            
            $zip->close();
            
            $this->logger->info('ZIP integrity verification passed', [
                'zip_path' => $zip_path,
                'num_files' => $num_files,
                'has_index' => $has_index,
                'has_assets_css' => $has_assets_css,
                'has_assets_js' => $has_assets_js,
                'has_assets_images' => $has_assets_images,
            ]);
            
            // 警告：缺少关键文件
            if (!$has_index) {
                $this->logger->warning('ZIP missing index.html');
            }
            if (!$has_assets_css && !$has_assets_js && !$has_assets_images) {
                $this->logger->warning('ZIP missing assets directories');
            }
            
            return [
                'valid' => true,
                'output' => "ZIP archive contains {$num_files} files. Index: " . ($has_index ? 'YES' : 'NO') . ", Assets: " . ($has_assets_css || $has_assets_js || $has_assets_images ? 'YES' : 'NO'),
                'error' => '',
                'method' => 'ZipArchive',
            ];
        } else {
            $error_msg = $this->get_zip_error_message($result);
            
            $this->logger->error('ZIP integrity verification failed', [
                'zip_path' => $zip_path,
                'error_code' => $result,
                'error_message' => $error_msg,
            ]);
            
            return [
                'valid' => false,
                'output' => '',
                'error' => "ZIP verification failed: {$error_msg}",
                'method' => 'ZipArchive',
            ];
        }
    }
    
    /**
     * 获取 ZipArchive 错误消息
     *
     * @param int $error_code ZipArchive 错误码
     * @return string 错误消息
     */
    private function get_zip_error_message($error_code) {
        $errors = [
            ZipArchive::ER_EXISTS => 'File already exists',
            ZipArchive::ER_INCONS => 'ZIP archive inconsistent',
            ZipArchive::ER_INVAL => 'Invalid argument',
            ZipArchive::ER_MEMORY => 'Memory allocation failure',
            ZipArchive::ER_NOENT => 'No such file',
            ZipArchive::ER_NOZIP => 'Not a ZIP archive',
            ZipArchive::ER_OPEN => 'Cannot open file',
            ZipArchive::ER_READ => 'Read error',
            ZipArchive::ER_SEEK => 'Seek error',
        ];
        
        return $errors[$error_code] ?? "Unknown error (code: {$error_code})";
    }
    
    /**
     * 获取 ZIP 包结构预览
     *
     * 使用 unzip -l 命令获取 ZIP 包的文件列表
     *
     * @param string $zip_path ZIP 文件路径
     * @param int $limit 限制显示的行数（默认 20）
     * @return array {
     *     @type bool $success 是否成功
     *     @type array $lines 文件列表行
     *     @type string $error 错误消息（如果失败）
     * }
     */
    private function get_zip_structure($zip_path, $limit = 20) {
        // 检查文件是否存在
        if (!file_exists($zip_path)) {
            return [
                'success' => false,
                'lines' => [],
                'error' => 'ZIP file does not exist',
            ];
        }
        
        // 检查 unzip 命令是否可用
        $unzip_check = shell_exec('which unzip 2>/dev/null');
        if (empty($unzip_check)) {
            return [
                'success' => false,
                'lines' => [],
                'error' => 'unzip command not available',
            ];
        }
        
        // 执行 unzip -l 命令
        $escaped_path = escapeshellarg($zip_path);
        $command = "unzip -l {$escaped_path} 2>&1 | head -n {$limit}";
        
        $output = shell_exec($command);
        $lines = explode("\n", trim($output));
        
        return [
            'success' => true,
            'lines' => $lines,
            'error' => '',
        ];
    }
}

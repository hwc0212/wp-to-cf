<?php
/**
 * 路径洗白类
 * 
 * 彻底移除 WordPress 特征路径，将所有资产重定位到简洁的根目录结构
 * 实现去中心化存储和防冲突哈希机制
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_Path_Whitewash
 * 
 * 路径洗白映射算法实现
 */
class WP_to_CF_Path_Whitewash
{
    /**
     * 路径映射表（运行时构建）
     * 
     * @var array ['原始路径' => '洗白路径']
     */
    private array $path_map = [];
    
    /**
     * 文件名使用记录（防冲突）
     * 
     * @var array ['target_dir' => ['filename' => true]]
     */
    private array $filename_registry = [];
    
    /**
     * 统计信息
     *
     * @var array
     */
    private array $stats = [
        'images' => 0,
        'css' => 0,
        'js' => 0,
        'fonts' => 0,
        'assets' => 0,
        'conflicts_resolved' => 0,
    ];
    
    /**
     * 洗白单个路径
     * 
     * @param string $original_path 原始路径（如 /wp-content/uploads/2024/01/image.jpg）
     * @param string $content 文件内容（用于计算哈希，可选）
     * @return string 洗白后的路径（如 /images/image.jpg）
     */
    public function whitewash_path(string $original_path, string $content = ''): string
    {
        // 如果已经映射过，直接返回
        if (isset($this->path_map[$original_path])) {
            return $this->path_map[$original_path];
        }
        
        // 1. 确定资源类型和目标目录
        $target_dir = $this->determine_target_directory($original_path);
        
        // 2. 提取文件名
        $filename = basename($original_path);
        
        // 移除查询参数和锚点
        $filename = preg_replace('/[?#].*$/', '', $filename);
        
        $pathinfo = pathinfo($filename);
        $basename = $pathinfo['filename'];
        $extension = $pathinfo['extension'] ?? '';
        
        // 3. 强制哈希：主题和插件的 CSS/JS 必须带前缀和哈希
        $needs_forced_hash = $this->needs_forced_hash($original_path, $extension);
        
        if ($needs_forced_hash) {
            // 生成前缀（t- 或 p-）
            $prefix = $this->get_asset_prefix($original_path);
            
            // 生成 8 位哈希
            $hash_source = $original_path . $content;
            $hash = substr(md5($hash_source), 0, 8);
            
            // 构建新文件名：prefix-basename.hash.ext
            $final_filename = $prefix . '-' . $basename . '.' . $hash . ($extension ? '.' . $extension : '');
            
            WP_to_CF_Logger::info('Forced hash applied to theme/plugin asset', [
                'original_path' => $original_path,
                'prefix' => $prefix,
                'hash' => $hash,
                'final_filename' => $final_filename,
            ]);
        } else {
            // 4. 检查文件名冲突（仅对非强制哈希的文件）
            $final_filename = $filename;
            
            if (isset($this->filename_registry[$target_dir][$filename])) {
                // 冲突！需要添加哈希
                $this->stats['conflicts_resolved']++;
                
                // 生成 8 位哈希（基于原始路径 + 内容）
                $hash_source = $original_path . $content;
                $hash = substr(md5($hash_source), 0, 8);
                
                // 构建新文件名：basename.hash.ext
                $final_filename = $basename . '.' . $hash . ($extension ? '.' . $extension : '');
                
                WP_to_CF_Logger::info('Path conflict resolved with hash', [
                    'original_path' => $original_path,
                    'original_filename' => $filename,
                    'hashed_filename' => $final_filename,
                    'hash' => $hash,
                ]);
            }
        }
        
        // 5. 记录文件名使用
        $this->filename_registry[$target_dir][$filename] = true;
        
        // 6. 生成洗白路径（绝对路径，以 / 开头）
        $whitewashed_path = '/' . $target_dir . '/' . $final_filename;
        
        // 7. 保存映射
        $this->path_map[$original_path] = $whitewashed_path;
        
        // 8. 更新统计
        $this->stats[$target_dir]++;
        
        WP_to_CF_Logger::info('Path whitewashed', [
            'original' => $original_path,
            'whitewashed' => $whitewashed_path,
            'target_dir' => $target_dir,
        ]);
        
        return $whitewashed_path;
    }
    
    /**
     * 检测是否需要强制哈希
     * 
     * @param string $path 原始路径
     * @param string $extension 文件扩展名
     * @return bool
     */
    private function needs_forced_hash(string $path, string $extension): bool
    {
        // 只对 CSS 和 JS 文件强制哈希
        if (!in_array(strtolower($extension), ['css', 'js', 'mjs'])) {
            return false;
        }
        
        // 检测是否来自主题或插件
        return $this->is_theme_asset($path) || $this->is_plugin_asset($path);
    }
    
    /**
     * 获取资产前缀
     * 
     * @param string $path 原始路径
     * @return string 前缀（t 或 p）
     */
    private function get_asset_prefix(string $path): string
    {
        if ($this->is_theme_asset($path)) {
            return 't';
        } elseif ($this->is_plugin_asset($path)) {
            return 'p';
        }
        
        return 'a'; // assets
    }
    
    /**
     * 检测路径是否来自主题
     * 
     * @param string $path 路径
     * @return bool
     */
    private function is_theme_asset(string $path): bool
    {
        return (bool) preg_match('#/wp-content/themes/#i', $path);
    }
    
    /**
     * 检测路径是否来自插件
     * 
     * @param string $path 路径
     * @return bool
     */
    private function is_plugin_asset(string $path): bool
    {
        return (bool) preg_match('#/wp-content/plugins/#i', $path);
    }
    
    /**
     * 确定目标目录
     * 
     * @param string $path 原始路径
     * @return string 目标目录（images/css/js/fonts/assets）
     */
    private function determine_target_directory(string $path): string
    {
        // 图片扩展名
        $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp', 'avif'];
        
        // CSS 扩展名
        $css_exts = ['css'];
        
        // JS 扩展名
        $js_exts = ['js', 'mjs'];
        
        // 字体扩展名
        $font_exts = ['woff', 'woff2', 'ttf', 'eot', 'otf'];
        
        // 获取扩展名
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        
        // 按扩展名分类
        if (in_array($ext, $image_exts)) {
            return 'images';
        } elseif (in_array($ext, $css_exts)) {
            return 'css';
        } elseif (in_array($ext, $js_exts)) {
            return 'js';
        } elseif (in_array($ext, $font_exts)) {
            return 'fonts';
        }
        
        // 默认：根据路径特征判断
        if (preg_match('#/(images?|img|pics?|photos?|gallery)/#i', $path)) {
            return 'images';
        } elseif (preg_match('#/css/#i', $path)) {
            return 'css';
        } elseif (preg_match('#/(js|javascript|scripts?)/#i', $path)) {
            return 'js';
        } elseif (preg_match('#/fonts?/#i', $path)) {
            return 'fonts';
        }
        
        // 兜底：assets 目录
        return 'assets';
    }
    
    /**
     * 批量洗白 HTML 中的所有路径
     * 
     * @param string $html HTML 内容
     * @return string 洗白后的 HTML
     */
    public function whitewash_html(string $html): string
    {
        WP_to_CF_Logger::info('Starting HTML path whitewashing');
        
        // ✅ 修复：使用正确的正则捕获图片路径
        // 1. 图片路径洗白：/wp-content/uploads/YYYY/MM/filename.ext → /images/filename-YYYY-MM.ext
        $html = preg_replace_callback(
            '#/wp-content/uploads/(\d{4})/(\d{2})/([^/"\s\?]+)\.([a-z0-9]+)#i',
            function($matches) {
                // $matches[1] = 年份 (YYYY)
                // $matches[2] = 月份 (MM)
                // $matches[3] = 文件名（不含扩展名）
                // $matches[4] = 扩展名
                
                // 重新拼装：images/文件名-年-月.扩展名
                $whitewashed = '/images/' . $matches[3] . '-' . $matches[1] . '-' . $matches[2] . '.' . $matches[4];
                
                WP_to_CF_Logger::info('Image path whitewashed', [
                    'original' => $matches[0],
                    'whitewashed' => $whitewashed,
                    'year' => $matches[1],
                    'month' => $matches[2],
                    'filename' => $matches[3],
                    'ext' => $matches[4],
                ]);
                
                return $whitewashed;
            },
            $html
        );
        
        // 2. CSS 文件洗白
        $html = preg_replace_callback(
            '#(<link[^>]+href=["\'])(/wp-content/(?:themes|plugins)/[^/]+/[^"\']+\.css[^"\']*)(["\'][^>]*>)#i',
            function($matches) {
                $original_path = $matches[2];
                $whitewashed_path = $this->whitewash_path($original_path, $original_path);
                return $matches[1] . $whitewashed_path . $matches[3];
            },
            $html
        );
        
        // 3. JS 文件洗白
        $html = preg_replace_callback(
            '#(<script[^>]+src=["\'])(/wp-content/(?:themes|plugins)/[^/]+/[^"\']+\.js[^"\']*)(["\'][^>]*>)#i',
            function($matches) {
                $original_path = $matches[2];
                $whitewashed_path = $this->whitewash_path($original_path, $original_path);
                return $matches[1] . $whitewashed_path . $matches[3];
            },
            $html
        );
        
        // 4. 兜底正则：处理映射表之外的 WordPress 路径
        $html = $this->apply_fallback_patterns($html);
        
        WP_to_CF_Logger::info('HTML path whitewashing completed', [
            'stats' => $this->stats,
        ]);
        
        return $html;
    }
    
    /**
     * 应用兜底正则模式
     * 
     * 处理映射表之外的 WordPress 路径
     *
     * @param string $html HTML 内容
     * @return string 处理后的 HTML
     */
    private function apply_fallback_patterns(string $html): string
    {
        // 兜底模式：将所有剩余的 wp-content/wp-includes 路径重写为 /assets/
        $fallback_patterns = [
            // 标准格式
            '#(["\'\(])(/wp-content/[^"\')\s]+)(["\'\)])#i' => '$1/assets/$3',
            '#(["\'\(])(/wp-includes/[^"\')\s]+)(["\'\)])#i' => '$1/assets/$3',
            
            // 完整 URL 格式（剥离域名）
            '#(["\'\(])https?://[^/]+(/wp-content/[^"\')\s]+)(["\'\)])#i' => '$1/assets/$3',
            '#(["\'\(])https?://[^/]+(/wp-includes/[^"\')\s]+)(["\'\)])#i' => '$1/assets/$3',
        ];
        
        foreach ($fallback_patterns as $pattern => $replacement) {
            $html = preg_replace($pattern, $replacement, $html);
        }
        
        return $html;
    }
    
    /**
     * 获取完整的路径映射表
     * 
     * @return array ['原始路径' => '洗白路径']
     */
    public function get_path_map(): array
    {
        return $this->path_map;
    }
    
    /**
     * 设置路径映射表（用于从缓存加载）
     *
     * @param array $path_map 路径映射表
     * @return void
     */
    public function set_path_map(array $path_map): void
    {
        $this->path_map = $path_map;
        
        // 重建文件名注册表
        foreach ($path_map as $original => $whitewashed) {
            $target_dir = dirname(ltrim($whitewashed, '/'));
            $filename = basename($whitewashed);
            
            // 提取原始文件名（移除哈希）
            $original_filename = basename($original);
            $original_filename = preg_replace('/[?#].*$/', '', $original_filename);
            
            $this->filename_registry[$target_dir][$original_filename] = true;
        }
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
    
    /**
     * 验证洗白后的路径
     *
     * @param string $path 路径
     * @return bool 是否有效
     */
    public function validate_whitewashed_path(string $path): bool
    {
        // 1. 必须以 / 开头
        if ($path[0] !== '/') {
            return false;
        }
        
        // 2. 不能包含 WordPress 特征
        if (preg_match('#/wp-(content|includes|admin)/#i', $path)) {
            return false;
        }
        
        // 3. 必须在允许的目录中
        if (!preg_match('#^/(images|css|js|fonts|assets)/#', $path)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 保存映射表到缓存
     *
     * @return bool 是否成功
     */
    public function save_map_to_cache(): bool
    {
        $cache_key = 'wptocf_path_map';
        $expiration = DAY_IN_SECONDS; // 24 小时
        
        return set_transient($cache_key, $this->path_map, $expiration);
    }
    
    /**
     * 从缓存加载映射表
     *
     * @return bool 是否成功加载
     */
    public function load_map_from_cache(): bool
    {
        $cache_key = 'wptocf_path_map';
        $cached_map = get_transient($cache_key);
        
        if ($cached_map && is_array($cached_map)) {
            $this->set_path_map($cached_map);
            
            WP_to_CF_Logger::info('Path map loaded from cache', [
                'map_size' => count($cached_map),
            ]);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * 清除映射表缓存
     *
     * @return bool 是否成功
     */
    public function clear_map_cache(): bool
    {
        $cache_key = 'wptocf_path_map';
        return delete_transient($cache_key);
    }
}

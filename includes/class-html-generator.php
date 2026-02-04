<?php
/**
 * HTML 生成器类
 * 
 * 负责获取文章的完整 HTML 内容
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_HTML_Generator
 * 
 * 生成文章的静态 HTML
 */
class WP_to_CF_HTML_Generator
{
    /**
     * 通用 HTML 生成方法
     * 
     * 从任意 URL 获取 HTML 内容
     *
     * @param string $url 要获取的 URL
     * @return string|false HTML 内容，失败返回 false
     */
    public function generate_html(string $url): string|false
    {
        WP_to_CF_Logger::info('Generating HTML from URL', [
            'url' => $url,
        ]);

        // 使用 wp_remote_get 获取完整的 HTML
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'sslverify' => false, // 内网环境可能没有有效的 SSL 证书
            'headers' => [
                'User-Agent' => 'WP-to-CF/1.0.0 (WordPress Static Generator)',
            ],
        ]);

        // 检查请求是否成功
        if (is_wp_error($response)) {
            WP_to_CF_Logger::error('Failed to fetch HTML', [
                'url' => $url,
                'error' => $response->get_error_message(),
            ]);
            return false;
        }

        // 检查 HTTP 状态码
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            WP_to_CF_Logger::error('HTTP request failed', [
                'url' => $url,
                'status_code' => $status_code,
            ]);
            return false;
        }

        // 获取响应体
        $html = wp_remote_retrieve_body($response);

        if (empty($html)) {
            WP_to_CF_Logger::error('Empty HTML response', [
                'url' => $url,
            ]);
            return false;
        }

        // Phase 1: 采集静态资产（图片、CSS、JS）
        $html = $this->collect_assets($html, $url);

        // Phase 2: 基础清理（移除冗余资产、WordPress 指纹）
        $html = $this->remove_blocked_assets($html);
        $html = $this->remove_wordpress_fingerprints($html);
        
        // Phase 3: HTML 瘦身
        $html = $this->debloat_html($html);
        
        // 注意：相对路径转换将在 Site_Packager 中统一处理

        WP_to_CF_Logger::info('HTML generated successfully', [
            'url' => $url,
            'html_size' => strlen($html),
        ]);

        return $html;
    }

    /**
     * 采集 HTML 中的静态资产
     * 
     * 提取并下载图片、CSS、JS 文件到本地缓存
     *
     * @param string $html HTML 内容
     * @param string $base_url 基础 URL（用于解析相对路径）
     * @return string 更新后的 HTML（资产 URL 已替换为本地路径）
     */
    private function collect_assets(string $html, string $base_url): string
    {
        // 创建资产采集器实例
        $asset_collector = new WP_to_CF_Asset_Collector();
        
        // 采集资产并更新 HTML
        $html = $asset_collector->collect_assets($html, $base_url);
        
        // 获取采集统计
        $stats = $asset_collector->get_stats();
        
        WP_to_CF_Logger::info('Asset collection completed', [
            'images' => $stats['images'],
            'css' => $stats['css'],
            'js' => $stats['js'],
            'failed' => $stats['failed'],
            'total_size' => $stats['total_size'],
        ]);
        
        return $html;
    }
    
    /**
     * 移除 WordPress 指纹
     * 
     * @param string $html HTML 内容
     * @return string 清理后的 HTML
     */
    private function remove_wordpress_fingerprints(string $html): string
    {
        WP_to_CF_Logger::info('Removing WordPress fingerprints');
        
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
        
        WP_to_CF_Logger::info('WordPress fingerprints removed');
        
        return $html;
    }

    /**
     * 移除冗余资产引用
     * 
     * @param string $html HTML 内容
     * @return string 清理后的 HTML
     */
    private function remove_blocked_assets(string $html): string
    {
        WP_to_CF_Logger::info('Removing blocked assets from HTML');
        
        // 1. 移除 admin-bar 相关的 <link> 和 <script>
        $html = preg_replace('#<link[^>]*admin-bar[^>]*>\s*#i', '', $html);
        $html = preg_replace('#<script[^>]*admin-bar[^>]*>.*?</script>\s*#is', '', $html);
        
        // 2. 移除 dashicons
        $html = preg_replace('#<link[^>]*dashicons[^>]*>\s*#i', '', $html);
        
        // 3. 移除 wp-embed
        $html = preg_replace('#<script[^>]*wp-embed[^>]*>.*?</script>\s*#is', '', $html);
        
        // 4. 移除内联样式中的引用
        $html = preg_replace('#@import\s+url\(["\']?[^)]*admin-bar[^)]*["\']?\);?\s*#i', '', $html);
        $html = preg_replace('#@import\s+url\(["\']?[^)]*dashicons[^)]*["\']?\);?\s*#i', '', $html);
        
        WP_to_CF_Logger::info('Blocked assets removed from HTML');
        
        return $html;
    }
    

    
    /**
     * HTML Debloat：移除冗余内容
     * 
     * @param string $html HTML 内容
     * @return string 瘦身后的 HTML
     */
    private function debloat_html(string $html): string
    {
        WP_to_CF_Logger::info('Starting HTML debloat');
        
        // 1. 移除内联样式中的 admin-bar 和 dashicons
        $html = preg_replace('#<style[^>]*>.*?admin-bar.*?</style>#is', '', $html);
        $html = preg_replace('#<style[^>]*>.*?dashicons.*?</style>#is', '', $html);
        
        // 2. 移除评论相关的 JavaScript
        $html = preg_replace('#<script[^>]*>.*?comment.*?</script>#is', '', $html);
        
        // 3. 移除 WordPress 嵌入相关的 JavaScript
        $html = preg_replace('#<script[^>]*>.*?wp\.embed.*?</script>#is', '', $html);
        
        // 4. 移除 CSS/JS 链接中的 ?ver= 版本号
        $html = preg_replace('#(<link[^>]+href=["\'][^"\']+)\?ver=[^"\']*(["\'])#i', '$1$2', $html);
        $html = preg_replace('#(<script[^>]+src=["\'][^"\']+)\?ver=[^"\']*(["\'])#i', '$1$2', $html);
        
        // 5. 移除空的内联样式和脚本
        $html = preg_replace('#<style[^>]*>\s*</style>#is', '', $html);
        $html = preg_replace('#<script[^>]*>\s*</script>#is', '', $html);
        
        // 6. 移除多余的空白行
        $html = preg_replace('#\n\s*\n\s*\n#', "\n\n", $html);
        
        WP_to_CF_Logger::info('HTML debloat completed');
        
        return $html;
    }



    /**
     * 生成文章的 HTML
     *
     * @param int $post_id 文章 ID
     * @return string|false HTML 内容，失败返回 false
     */
    public function generate_post_html(int $post_id): string|false
    {
        // 验证文章是否存在
        $post = get_post($post_id);
        if (!$post) {
            WP_to_CF_Logger::error('Post not found', [
                'post_id' => $post_id,
            ]);
            return false;
        }

        // 验证文章状态
        if ($post->post_status !== 'publish') {
            WP_to_CF_Logger::warning('Post is not published', [
                'post_id' => $post_id,
                'post_status' => $post->post_status,
            ]);
            return false;
        }

        // 获取文章永久链接
        $permalink = get_permalink($post_id);
        if (!$permalink) {
            WP_to_CF_Logger::error('Failed to get permalink', [
                'post_id' => $post_id,
            ]);
            return false;
        }

        WP_to_CF_Logger::info('Generating HTML for post', [
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'permalink' => $permalink,
        ]);

        // 使用通用方法获取 HTML
        return $this->generate_html($permalink);
    }

    /**
     * 生成首页的 HTML
     *
     * @return string|false HTML 内容，失败返回 false
     */
    public function generate_home_html(): string|false
    {
        $home_url = home_url('/');

        WP_to_CF_Logger::info('Generating HTML for homepage', [
            'home_url' => $home_url,
        ]);

        // 使用通用方法获取 HTML
        return $this->generate_html($home_url);
    }

    /**
     * 批量生成多个文章的 HTML
     *
     * @param array $post_ids 文章 ID 数组
     * @return array 结果数组，格式：['post_id' => 'html' or false]
     */
    public function generate_batch_html(array $post_ids): array
    {
        $results = [];

        WP_to_CF_Logger::info('Starting batch HTML generation', [
            'post_count' => count($post_ids),
        ]);

        foreach ($post_ids as $post_id) {
            $html = $this->generate_post_html($post_id);
            $results[$post_id] = $html;

            // 短暂延迟，避免过快请求
            usleep(100000); // 0.1 秒
        }

        $success_count = count(array_filter($results));
        $failed_count = count($post_ids) - $success_count;

        WP_to_CF_Logger::info('Batch HTML generation completed', [
            'total' => count($post_ids),
            'success' => $success_count,
            'failed' => $failed_count,
        ]);

        return $results;
    }
}

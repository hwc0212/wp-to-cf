<?php
/**
 * WordPress 清理器类
 * 
 * 负责移除 WordPress 默认的冗余代码和标签
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_WordPress_Debloat
 * 
 * 清理 WordPress 生成的冗余代码，优化静态 HTML
 */
class WP_to_CF_WordPress_Debloat
{
    /**
     * 清理 HTML
     *
     * @param string $html 原始 HTML
     * @return string 清理后的 HTML
     */
    public function debloat_html(string $html): string
    {
        WP_to_CF_Logger::info('Starting WordPress debloat');

        $original_size = strlen($html);

        // 1. 移除 wp-emoji 相关代码
        $html = $this->remove_wp_emoji($html);

        // 2. 移除冗余的 Meta 标签
        $html = $this->remove_redundant_meta_tags($html);

        // 3. 移除 WordPress 版本信息
        $html = $this->remove_wp_version($html);

        // 4. 移除 REST API 链接
        $html = $this->remove_rest_api_links($html);

        // 5. 移除 oEmbed 链接
        $html = $this->remove_oembed_links($html);

        // 6. 移除 RSD 链接
        $html = $this->remove_rsd_link($html);

        // 7. 移除 Windows Live Writer 清单
        $html = $this->remove_wlwmanifest_link($html);

        // 8. 移除短链接
        $html = $this->remove_shortlink($html);

        // 9. 清理多余的空白行
        $html = $this->cleanup_whitespace($html);

        $cleaned_size = strlen($html);
        $saved_bytes = $original_size - $cleaned_size;

        WP_to_CF_Logger::info('WordPress debloat completed', [
            'original_size' => $original_size,
            'cleaned_size' => $cleaned_size,
            'saved_bytes' => $saved_bytes,
            'saved_percentage' => round(($saved_bytes / $original_size) * 100, 2) . '%',
        ]);

        return $html;
    }

    /**
     * 移除 wp-emoji 相关代码
     *
     * @param string $html HTML 内容
     * @return string 清理后的 HTML
     */
    private function remove_wp_emoji(string $html): string
    {
        // 移除 emoji 检测脚本
        $patterns = [
            // 移除 emoji 脚本标签
            '/<script[^>]*>.*?wp-emoji-release\.min\.js.*?<\/script>/is',
            // 移除 emoji 内联脚本
            '/<script[^>]*>.*?window\._wpemojiSettings.*?<\/script>/is',
            // 移除 emoji 样式
            '/<style[^>]*>.*?wp-emoji.*?<\/style>/is',
            // 移除 emoji 相关的 img 标签
            '/<img[^>]*class=["\'][^"\']*wp-smiley[^"\']*["\'][^>]*>/i',
        ];

        foreach ($patterns as $pattern) {
            $html = preg_replace($pattern, '', $html);
        }

        WP_to_CF_Logger::info('Removed wp-emoji code');

        return $html;
    }

    /**
     * 移除冗余的 Meta 标签
     *
     * @param string $html HTML 内容
     * @return string 清理后的 HTML
     */
    private function remove_redundant_meta_tags(string $html): string
    {
        $patterns = [
            // 移除 generator meta 标签
            '/<meta[^>]*name=["\']generator["\'][^>]*>/i',
            // 移除 EditURI
            '/<link[^>]*rel=["\']EditURI["\'][^>]*>/i',
        ];

        foreach ($patterns as $pattern) {
            $html = preg_replace($pattern, '', $html);
        }

        WP_to_CF_Logger::info('Removed redundant meta tags');

        return $html;
    }

    /**
     * 移除 WordPress 版本信息
     *
     * @param string $html HTML 内容
     * @return string 清理后的 HTML
     */
    private function remove_wp_version(string $html): string
    {
        // 移除 URL 中的 ver= 参数
        $html = preg_replace('/\?ver=[0-9.]+/', '', $html);
        $html = preg_replace('/&ver=[0-9.]+/', '', $html);

        WP_to_CF_Logger::info('Removed WordPress version info');

        return $html;
    }

    /**
     * 移除 REST API 链接
     *
     * @param string $html HTML 内容
     * @return string 清理后的 HTML
     */
    private function remove_rest_api_links(string $html): string
    {
        $patterns = [
            '/<link[^>]*rel=["\']https:\/\/api\.w\.org\/["\'][^>]*>/i',
            '/<link[^>]*href=["\'][^"\']*\/wp-json\/[^"\']*["\'][^>]*>/i',
        ];

        foreach ($patterns as $pattern) {
            $html = preg_replace($pattern, '', $html);
        }

        WP_to_CF_Logger::info('Removed REST API links');

        return $html;
    }

    /**
     * 移除 oEmbed 链接
     *
     * @param string $html HTML 内容
     * @return string 清理后的 HTML
     */
    private function remove_oembed_links(string $html): string
    {
        $patterns = [
            '/<link[^>]*rel=["\']alternate["\'][^>]*type=["\']application\/json\+oembed["\'][^>]*>/i',
            '/<link[^>]*type=["\']application\/json\+oembed["\'][^>]*rel=["\']alternate["\'][^>]*>/i',
            '/<link[^>]*rel=["\']alternate["\'][^>]*type=["\']text\/xml\+oembed["\'][^>]*>/i',
            '/<link[^>]*type=["\']text\/xml\+oembed["\'][^>]*rel=["\']alternate["\'][^>]*>/i',
        ];

        foreach ($patterns as $pattern) {
            $html = preg_replace($pattern, '', $html);
        }

        WP_to_CF_Logger::info('Removed oEmbed links');

        return $html;
    }

    /**
     * 移除 RSD 链接
     *
     * @param string $html HTML 内容
     * @return string 清理后的 HTML
     */
    private function remove_rsd_link(string $html): string
    {
        $html = preg_replace('/<link[^>]*rel=["\']EditURI["\'][^>]*>/i', '', $html);

        WP_to_CF_Logger::info('Removed RSD link');

        return $html;
    }

    /**
     * 移除 Windows Live Writer 清单
     *
     * @param string $html HTML 内容
     * @return string 清理后的 HTML
     */
    private function remove_wlwmanifest_link(string $html): string
    {
        $html = preg_replace('/<link[^>]*rel=["\']wlwmanifest["\'][^>]*>/i', '', $html);

        WP_to_CF_Logger::info('Removed wlwmanifest link');

        return $html;
    }

    /**
     * 移除短链接
     *
     * @param string $html HTML 内容
     * @return string 清理后的 HTML
     */
    private function remove_shortlink(string $html): string
    {
        $html = preg_replace('/<link[^>]*rel=["\']shortlink["\'][^>]*>/i', '', $html);

        WP_to_CF_Logger::info('Removed shortlink');

        return $html;
    }

    /**
     * 清理多余的空白行
     *
     * @param string $html HTML 内容
     * @return string 清理后的 HTML
     */
    private function cleanup_whitespace(string $html): string
    {
        // 移除连续的空白行（保留单个换行）
        $html = preg_replace('/\n\s*\n\s*\n/', "\n\n", $html);

        // 移除行尾空白
        $html = preg_replace('/[ \t]+$/m', '', $html);

        WP_to_CF_Logger::info('Cleaned up whitespace');

        return $html;
    }

    /**
     * 获取清理统计信息
     *
     * @param string $original_html 原始 HTML
     * @param string $cleaned_html  清理后的 HTML
     * @return array 统计信息
     */
    public function get_debloat_stats(string $original_html, string $cleaned_html): array
    {
        $original_size = strlen($original_html);
        $cleaned_size = strlen($cleaned_html);
        $saved_bytes = $original_size - $cleaned_size;

        return [
            'original_size' => $original_size,
            'cleaned_size' => $cleaned_size,
            'saved_bytes' => $saved_bytes,
            'saved_percentage' => $original_size > 0 ? round(($saved_bytes / $original_size) * 100, 2) : 0,
            'original_lines' => substr_count($original_html, "\n"),
            'cleaned_lines' => substr_count($cleaned_html, "\n"),
        ];
    }

    /**
     * 检查 HTML 中是否包含需要清理的内容
     *
     * @param string $html HTML 内容
     * @return array 检查结果
     */
    public function check_bloat(string $html): array
    {
        return [
            'has_wp_emoji' => stripos($html, 'wp-emoji') !== false,
            'has_generator_meta' => stripos($html, 'name="generator"') !== false,
            'has_rest_api_link' => stripos($html, 'wp-json') !== false,
            'has_oembed_link' => stripos($html, 'oembed') !== false,
            'has_rsd_link' => stripos($html, 'EditURI') !== false,
            'has_wlwmanifest' => stripos($html, 'wlwmanifest') !== false,
            'has_shortlink' => stripos($html, 'rel="shortlink"') !== false,
            'has_version_params' => stripos($html, '?ver=') !== false || stripos($html, '&ver=') !== false,
        ];
    }
}

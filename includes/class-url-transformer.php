<?php
/**
 * URL 转换器类
 * 
 * 负责将 HTML 中的内网 URL 转换为公网域名
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_URL_Transformer
 * 
 * 将内网链接和资源路径转换为公网域名
 */
class WP_to_CF_URL_Transformer
{
    /**
     * 内网域名（WordPress 站点 URL）
     *
     * @var string
     */
    private string $internal_domain;

    /**
     * 公网域名（生产环境域名）
     *
     * @var string
     */
    private string $production_domain;

    /**
     * 内网 URL（完整）
     *
     * @var string
     */
    private string $internal_url;

    /**
     * 公网 URL（完整）
     *
     * @var string
     */
    private string $production_url;

    /**
     * 构造函数
     */
    public function __construct()
    {
        // 获取 WordPress 站点 URL（内网）
        $this->internal_url = home_url();
        
        // 解析内网域名
        $parsed = parse_url($this->internal_url);
        $this->internal_domain = $parsed['host'] ?? '';
        
        // 如果有端口，添加到域名中
        if (isset($parsed['port'])) {
            $this->internal_domain .= ':' . $parsed['port'];
        }

        // 获取配置的公网域名
        $production_domain = get_option('wptocf_production_domain', '');
        
        if (empty($production_domain)) {
            WP_to_CF_Logger::warning('Production domain not configured');
            $this->production_domain = $this->internal_domain;
            $this->production_url = $this->internal_url;
        } else {
            // 清理公网域名（移除协议和尾部斜杠）
            $production_domain = preg_replace('#^https?://#', '', $production_domain);
            $production_domain = rtrim($production_domain, '/');
            
            $this->production_domain = $production_domain;
            
            // 构建公网 URL（使用 HTTPS）
            $this->production_url = 'https://' . $production_domain;
        }

        WP_to_CF_Logger::info('URL Transformer initialized', [
            'internal_url' => $this->internal_url,
            'internal_domain' => $this->internal_domain,
            'production_url' => $this->production_url,
            'production_domain' => $this->production_domain,
        ]);
    }

    /**
     * 转换 HTML 中的所有 URL
     *
     * @param string $html 原始 HTML
     * @return string 转换后的 HTML
     */
    public function transform_html(string $html): string
    {
        // 如果内网和公网域名相同，不需要转换
        if ($this->internal_domain === $this->production_domain) {
            WP_to_CF_Logger::info('Internal and production domains are the same, skipping transformation');
            return $html;
        }

        WP_to_CF_Logger::info('Starting URL transformation', [
            'html_size' => strlen($html),
        ]);

        $original_html = $html;

        // 1. 转换完整 URL（带协议）
        $html = $this->transform_full_urls($html);

        // 2. 转换协议相对 URL（//domain.com/path）
        $html = $this->transform_protocol_relative_urls($html);

        // 3. 转换域名引用（在 JavaScript 和 CSS 中）
        $html = $this->transform_domain_references($html);

        // 统计转换次数
        $changes = $this->count_differences($original_html, $html);

        WP_to_CF_Logger::info('URL transformation completed', [
            'changes' => $changes,
            'original_size' => strlen($original_html),
            'transformed_size' => strlen($html),
        ]);

        return $html;
    }

    /**
     * 转换完整 URL（http:// 或 https://）
     *
     * @param string $html HTML 内容
     * @return string 转换后的 HTML
     */
    private function transform_full_urls(string $html): string
    {
        // 转换 http:// 和 https:// 开头的完整 URL
        // 同时处理 JSON 转义的斜杠（https:\/\/ 和 http:\/\/）
        
        $patterns = [
            // HTTP (normal)
            '#http://' . preg_quote($this->internal_domain, '#') . '([/\'\"\s>])#i',
            // HTTP (JSON escaped)
            '#http:\\\\/\\\\/' . preg_quote($this->internal_domain, '#') . '([/\\\\\'\"\s>])#i',
            // HTTPS (normal)
            '#https://' . preg_quote($this->internal_domain, '#') . '([/\'\"\s>])#i',
            // HTTPS (JSON escaped)
            '#https:\\\\/\\\\/' . preg_quote($this->internal_domain, '#') . '([/\\\\\'\"\s>])#i',
        ];

        $replacements = [
            $this->production_url . '$1',
            str_replace('/', '\\/', $this->production_url) . '$1',
            $this->production_url . '$1',
            str_replace('/', '\\/', $this->production_url) . '$1',
        ];

        foreach ($patterns as $index => $pattern) {
            $html = preg_replace($pattern, $replacements[$index], $html);
        }

        return $html;
    }

    /**
     * 转换协议相对 URL（//domain.com/path）
     *
     * @param string $html HTML 内容
     * @return string 转换后的 HTML
     */
    private function transform_protocol_relative_urls(string $html): string
    {
        // 转换 //domain.com 格式的 URL
        $pattern = '#//' . preg_quote($this->internal_domain, '#') . '([/\'\"\s>])#i';
        $replacement = '//' . $this->production_domain . '$1';

        $html = preg_replace($pattern, $replacement, $html);

        return $html;
    }

    /**
     * 转换域名引用（在 JavaScript 和 CSS 中）
     *
     * @param string $html HTML 内容
     * @return string 转换后的 HTML
     */
    private function transform_domain_references(string $html): string
    {
        // 转换 JavaScript 中的域名引用
        // 例如：var siteUrl = "http://internal.com";
        // 例如：location.hostname === "internal.com"
        
        // 转换引号中的域名
        $patterns = [
            // 单引号
            '#\'' . preg_quote($this->internal_domain, '#') . '\'#i',
            // 双引号
            '#"' . preg_quote($this->internal_domain, '#') . '"#i',
        ];

        $replacements = [
            "'" . $this->production_domain . "'",
            '"' . $this->production_domain . '"',
        ];

        foreach ($patterns as $index => $pattern) {
            $html = preg_replace($pattern, $replacements[$index], $html);
        }

        return $html;
    }

    /**
     * 转换特定属性中的 URL
     * 
     * 针对 src, href, data-src, srcset 等属性
     *
     * @param string $html HTML 内容
     * @return string 转换后的 HTML
     */
    public function transform_attributes(string $html): string
    {
        // 常见的包含 URL 的属性
        $attributes = [
            'src',
            'href',
            'data-src',
            'data-href',
            'srcset',
            'data-srcset',
            'poster',
            'data-poster',
            'content', // meta 标签
        ];

        foreach ($attributes as $attr) {
            // 匹配属性值中的内网 URL
            $pattern = '#(' . $attr . '=["\'])' . preg_quote($this->internal_url, '#') . '#i';
            $replacement = '$1' . $this->production_url;
            
            $html = preg_replace($pattern, $replacement, $html);
        }

        return $html;
    }

    /**
     * 转换 srcset 属性（响应式图片）
     *
     * @param string $html HTML 内容
     * @return string 转换后的 HTML
     */
    public function transform_srcset(string $html): string
    {
        // srcset 格式：url1 1x, url2 2x 或 url1 100w, url2 200w
        // 需要转换其中的所有 URL
        
        $pattern = '#(srcset=["\'][^"\']*?)' . preg_quote($this->internal_url, '#') . '#i';
        $replacement = '$1' . $this->production_url;
        
        $html = preg_replace($pattern, $replacement, $html);

        return $html;
    }

    /**
     * 转换 CSS 中的 URL
     *
     * @param string $html HTML 内容
     * @return string 转换后的 HTML
     */
    public function transform_css_urls(string $html): string
    {
        // CSS 中的 url() 函数
        // 例如：background: url('http://internal.com/image.jpg');
        
        $pattern = '#url\(["\']?' . preg_quote($this->internal_url, '#') . '#i';
        $replacement = 'url(\'' . $this->production_url;
        
        $html = preg_replace($pattern, $replacement, $html);

        return $html;
    }

    /**
     * 获取转换统计信息
     *
     * @return array 统计信息
     */
    public function get_transformation_stats(): array
    {
        return [
            'internal_url' => $this->internal_url,
            'internal_domain' => $this->internal_domain,
            'production_url' => $this->production_url,
            'production_domain' => $this->production_domain,
            'transformation_needed' => $this->internal_domain !== $this->production_domain,
        ];
    }

    /**
     * 统计两个字符串的差异数量
     *
     * @param string $original 原始字符串
     * @param string $modified 修改后的字符串
     * @return int 差异数量（近似）
     */
    private function count_differences(string $original, string $modified): int
    {
        // 简单统计：计算内网域名在原始 HTML 中出现的次数
        $count = substr_count($original, $this->internal_domain);
        
        return $count;
    }

    /**
     * 验证转换结果
     * 
     * 检查转换后的 HTML 是否还包含内网域名
     *
     * @param string $html 转换后的 HTML
     * @return array 验证结果
     */
    public function validate_transformation(string $html): array
    {
        $internal_count = substr_count($html, $this->internal_domain);
        $production_count = substr_count($html, $this->production_domain);

        $result = [
            'internal_domain_found' => $internal_count > 0,
            'internal_domain_count' => $internal_count,
            'production_domain_count' => $production_count,
            'transformation_complete' => $internal_count === 0,
        ];

        if ($internal_count > 0) {
            WP_to_CF_Logger::warning('Internal domain still found in HTML after transformation', [
                'internal_domain' => $this->internal_domain,
                'count' => $internal_count,
            ]);
        }

        return $result;
    }
}

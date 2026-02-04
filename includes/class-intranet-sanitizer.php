<?php
/**
 * 内网信息脱敏类
 * 
 * 清除 HTML 中的内网 IP、域名和服务器路径信息
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_Intranet_Sanitizer
 * 
 * 处理内网信息脱敏，防止内网架构泄露到公网
 */
class WP_to_CF_Intranet_Sanitizer
{
    /**
     * 生产域名
     *
     * @var string
     */
    private string $production_domain;
    
    /**
     * Cloudflare Pages 域名
     *
     * @var string
     */
    private string $cloudflare_domain;
    
    /**
     * 脱敏统计
     *
     * @var array
     */
    private array $sanitization_stats = [];
    
    /**
     * 检测到的问题
     *
     * @var array
     */
    private array $detected_issues = [];
    
    /**
     * 构造函数
     *
     * @param string $production_domain  生产域名
     * @param string $cloudflare_domain Cloudflare Pages 域名
     */
    public function __construct(string $production_domain, string $cloudflare_domain)
    {
        $this->production_domain = $production_domain;
        $this->cloudflare_domain = $cloudflare_domain;
        
        // 初始化统计
        $this->sanitization_stats = [
            'ips' => 0,
            'domains' => 0,
            'paths' => 0,
            'debug' => 0,
            'errors' => 0,
            'custom' => 0,
        ];
    }
    
    /**
     * 脱敏 HTML 内容（主入口）
     *
     * @param string $html HTML 内容
     * @return string 脱敏后的 HTML
     */
    public function sanitize_html(string $html): string
    {
        // 1. 检测并替换内网 IP
        $html = $this->sanitize_internal_ips($html);
        
        // 2. 检测并替换内网域名
        $html = $this->sanitize_internal_domains($html);
        
        // 3. 检测并移除服务器路径
        $html = $this->sanitize_server_paths($html);
        
        // 4. 检测并脱敏 data-* 属性中的绝对路径
        $html = $this->sanitize_data_attributes($html);
        
        // 5. 检测并移除调试信息
        $html = $this->sanitize_debug_info($html);
        
        // 6. 检测并移除 PHP 错误
        $html = $this->sanitize_php_errors($html);
        
        // 7. 应用自定义脱敏规则（Hook）
        $html = $this->apply_custom_patterns($html);
        
        return $html;
    }
    
    /**
     * 脱敏 data-* 属性中的绝对路径
     *
     * @param string $html HTML 内容
     * @return string 处理后的 HTML
     */
    private function sanitize_data_attributes(string $html): string
    {
        // 匹配 data-* 属性中的内网 IP 和域名
        $patterns = [
            // data-* 属性中的内网 IP
            '/(data-[a-z\-]+=["\'])https?:\/\/192\.168\.\d{1,3}\.\d{1,3}/i' => '$1https://' . $this->production_domain,
            '/(data-[a-z\-]+=["\'])https?:\/\/10\.\d{1,3}\.\d{1,3}\.\d{1,3}/i' => '$1https://' . $this->production_domain,
            '/(data-[a-z\-]+=["\'])https?:\/\/172\.(1[6-9]|2[0-9]|3[0-1])\.\d{1,3}\.\d{1,3}/i' => '$1https://' . $this->production_domain,
            
            // data-* 属性中的内网域名
            '/(data-[a-z\-]+=["\'])https?:\/\/[a-z0-9\-]+\.local/i' => '$1https://' . $this->cloudflare_domain,
            '/(data-[a-z\-]+=["\'])https?:\/\/[a-z0-9\-]+\.internal/i' => '$1https://' . $this->cloudflare_domain,
            '/(data-[a-z\-]+=["\'])https?:\/\/localhost/i' => '$1https://' . $this->production_domain,
            '/(data-[a-z\-]+=["\'])https?:\/\/127\.0\.0\.1/i' => '$1https://' . $this->production_domain,
        ];
        
        foreach ($patterns as $pattern => $replacement) {
            $count = 0;
            $html = preg_replace($pattern, $replacement, $html, -1, $count);
            if ($count > 0) {
                $this->sanitization_stats['custom'] += $count;
            }
        }
        
        return $html;
    }
    
    /**
     * 检测并替换内网 IP
     *
     * @param string $html HTML 内容
     * @return string 处理后的 HTML
     */
    private function sanitize_internal_ips(string $html): string
    {
        // 白名单：Cloudflare CDN 地址（不脱敏）
        $cloudflare_whitelist = [
            'cloudflare.com',
            'cloudflareinsights.com',
            'cloudflare-dns.com',
            'pages.dev',
        ];
        
        // 检查是否在白名单中
        foreach ($cloudflare_whitelist as $whitelist_domain) {
            if (strpos($html, $whitelist_domain) !== false) {
                WP_to_CF_Logger::info('Cloudflare CDN detected, applying whitelist protection');
            }
        }
        
        $patterns = [
            '/\b192\.168\.\d{1,3}\.\d{1,3}\b/',     // 192.168.x.x
            '/\b10\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/',  // 10.x.x.x
            '/\b172\.(1[6-9]|2[0-9]|3[0-1])\.\d{1,3}\.\d{1,3}\b/', // 172.16-31.x.x
            '/\b127\.0\.0\.1\b/',                    // localhost IP
        ];
        
        foreach ($patterns as $pattern) {
            $count = 0;
            $html = preg_replace($pattern, $this->production_domain, $html, -1, $count);
            $this->sanitization_stats['ips'] += $count;
        }
        
        return $html;
    }
    
    /**
     * 检测并替换内网域名
     *
     * @param string $html HTML 内容
     * @return string 处理后的 HTML
     */
    private function sanitize_internal_domains(string $html): string
    {
        $patterns = [
            // 标准格式
            '/https?:\/\/[a-z0-9\-]+\.local\b/i'     => 'https://' . $this->cloudflare_domain,
            '/https?:\/\/[a-z0-9\-]+\.internal\b/i'  => 'https://' . $this->cloudflare_domain,
            '/https?:\/\/localhost\b/i'              => 'https://' . $this->production_domain,
            '/https?:\/\/127\.0\.0\.1\b/'            => 'https://' . $this->production_domain,
            
            // JSON 转义格式（https:\/\/ 和 http:\/\/）
            '/https?:\\\\\\/\\\\\\/[a-z0-9\-]+\.local\b/i'    => 'https:\\/\\/' . $this->cloudflare_domain,
            '/https?:\\\\\\/\\\\\\/[a-z0-9\-]+\.internal\b/i' => 'https:\\/\\/' . $this->cloudflare_domain,
            '/https?:\\\\\\/\\\\\\/localhost\b/i'             => 'https:\\/\\/' . $this->production_domain,
            '/https?:\\\\\\/\\\\\\/127\.0\.0\.1\b/'           => 'https:\\/\\/' . $this->production_domain,
            
            // CSS url() 格式
            '/url\(["\']?https?:\/\/[a-z0-9\-]+\.local[^\)]*\)/i'     => 'url(https://' . $this->cloudflare_domain . ')',
            '/url\(["\']?https?:\/\/[a-z0-9\-]+\.internal[^\)]*\)/i'  => 'url(https://' . $this->cloudflare_domain . ')',
            '/url\(["\']?https?:\/\/localhost[^\)]*\)/i'              => 'url(https://' . $this->production_domain . ')',
            '/url\(["\']?https?:\/\/127\.0\.0\.1[^\)]*\)/i'           => 'url(https://' . $this->production_domain . ')',
        ];
        
        foreach ($patterns as $pattern => $replacement) {
            $count = 0;
            $html = preg_replace($pattern, $replacement, $html, -1, $count);
            $this->sanitization_stats['domains'] += $count;
        }
        
        return $html;
    }
    
    /**
     * 检测并移除服务器路径
     *
     * @param string $html HTML 内容
     * @return string 处理后的 HTML
     */
    private function sanitize_server_paths(string $html): string
    {
        $patterns = [
            '/\/home\/[a-z0-9_\-]+\/[^\s<>"\']+/i',   // /home/user/...
            '/\/var\/www\/[^\s<>"\']+/i',             // /var/www/...
            '/\/usr\/share\/[^\s<>"\']+/i',           // /usr/share/...
            '/\/opt\/[^\s<>"\']+/i',                  // /opt/...
            '/\/srv\/[^\s<>"\']+/i',                  // /srv/...
        ];
        
        foreach ($patterns as $pattern) {
            $count = 0;
            $html = preg_replace($pattern, '', $html, -1, $count);
            $this->sanitization_stats['paths'] += $count;
        }
        
        return $html;
    }
    
    /**
     * 检测并移除调试信息
     *
     * @param string $html HTML 内容
     * @return string 处理后的 HTML
     */
    private function sanitize_debug_info(string $html): string
    {
        $patterns = [
            '/<\!-- WP_DEBUG: .* -->/s',              // WP_DEBUG 注释
            '/<!-- Query: .* -->/s',                  // 数据库查询
            '/<!-- Stack trace:.*?-->/s',             // 堆栈跟踪
        ];
        
        foreach ($patterns as $pattern) {
            $count = 0;
            $html = preg_replace($pattern, '', $html, -1, $count);
            $this->sanitization_stats['debug'] += $count;
        }
        
        return $html;
    }
    
    /**
     * 检测并移除 PHP 错误
     *
     * @param string $html HTML 内容
     * @return string 处理后的 HTML
     */
    private function sanitize_php_errors(string $html): string
    {
        $patterns = [
            '/<b>Fatal error<\/b>:.*?<br \/>/s',      // Fatal error
            '/<b>Warning<\/b>:.*?<br \/>/s',          // Warning
            '/<b>Notice<\/b>:.*?<br \/>/s',           // Notice
            '/PHP (Fatal error|Warning|Notice):.*?\n/s', // CLI 错误
        ];
        
        foreach ($patterns as $pattern) {
            $count = 0;
            $html = preg_replace($pattern, '', $html, -1, $count);
            $this->sanitization_stats['errors'] += $count;
        }
        
        return $html;
    }
    
    /**
     * 应用自定义脱敏规则（Hook）
     *
     * @param string $html HTML 内容
     * @return string 处理后的 HTML
     */
    private function apply_custom_patterns(string $html): string
    {
        /**
         * 过滤器：允许扩展脱敏规则
         *
         * @param array $patterns 自定义脱敏规则数组 [pattern => replacement]
         */
        $custom_patterns = apply_filters('wptocf_sanitizer_patterns', []);
        
        if (!is_array($custom_patterns) || empty($custom_patterns)) {
            return $html;
        }
        
        foreach ($custom_patterns as $pattern => $replacement) {
            $count = 0;
            $html = preg_replace($pattern, $replacement, $html, -1, $count);
            $this->sanitization_stats['custom'] += $count;
        }
        
        return $html;
    }
    
    /**
     * 验证脱敏结果（二次扫描）
     *
     * @param string $html HTML 内容
     * @return bool 是否通过验证
     */
    public function verify_sanitization(string $html): bool
    {
        $issues = [];
        
        // 检查是否还有内网 IP
        if (preg_match('/\b192\.168\.\d{1,3}\.\d{1,3}\b/', $html)) {
            $issues[] = 'Internal IP detected: 192.168.x.x';
        }
        
        if (preg_match('/\b10\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', $html)) {
            $issues[] = 'Internal IP detected: 10.x.x.x';
        }
        
        if (preg_match('/\b172\.(1[6-9]|2[0-9]|3[0-1])\.\d{1,3}\.\d{1,3}\b/', $html)) {
            $issues[] = 'Internal IP detected: 172.16-31.x.x';
        }
        
        // 检查是否还有 .local 域名
        if (preg_match('/\.local\b/i', $html)) {
            $issues[] = 'Internal domain detected: *.local';
        }
        
        // 检查是否还有 .internal 域名
        if (preg_match('/\.internal\b/i', $html)) {
            $issues[] = 'Internal domain detected: *.internal';
        }
        
        // 检查是否还有服务器路径
        if (preg_match('/\/home\/[a-z0-9_\-]+\//i', $html)) {
            $issues[] = 'Server path detected: /home/...';
        }
        
        if (preg_match('/\/var\/www\//i', $html)) {
            $issues[] = 'Server path detected: /var/www/...';
        }
        
        $this->detected_issues = $issues;
        
        return empty($issues);
    }
    
    /**
     * 获取脱敏统计
     *
     * @return array 统计信息
     */
    public function get_sanitization_stats(): array
    {
        return [
            'internal_ips_replaced' => $this->sanitization_stats['ips'],
            'internal_domains_replaced' => $this->sanitization_stats['domains'],
            'server_paths_removed' => $this->sanitization_stats['paths'],
            'debug_info_removed' => $this->sanitization_stats['debug'],
            'php_errors_removed' => $this->sanitization_stats['errors'],
            'custom_patterns_applied' => $this->sanitization_stats['custom'],
            'detected_issues' => $this->detected_issues,
        ];
    }
    
    /**
     * 生成脱敏报告
     *
     * @return void
     */
    public function generate_report(): void
    {
        $report = [
            'timestamp' => current_time('mysql'),
            'stats' => $this->get_sanitization_stats(),
            'issues' => $this->detected_issues,
        ];
        
        $log_file = WP_CONTENT_DIR . '/uploads/wptocf-sanitization-report.log';
        
        // 确保目录存在
        $log_dir = dirname($log_file);
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        file_put_contents(
            $log_file,
            json_encode($report, JSON_PRETTY_PRINT) . "\n",
            FILE_APPEND
        );
        
        WP_to_CF_Logger::info('Sanitization report generated', [
            'log_file' => $log_file,
            'stats' => $this->sanitization_stats,
        ]);
    }
}

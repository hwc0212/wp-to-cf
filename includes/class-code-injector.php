<?php
/**
 * 代码注入器类
 * 
 * 负责将自定义代码注入到 HTML 的指定位置
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_Code_Injector
 * 
 * 在 HTML 中注入自定义代码（统计代码、GTM 等）
 */
class WP_to_CF_Code_Injector
{
    /**
     * 注入代码到 HTML
     *
     * @param string $html 原始 HTML
     * @return string 注入后的 HTML
     */
    public function inject_code(string $html): string
    {
        // 获取配置的代码
        $head_code = get_option('wptocf_head_code', '');
        $body_start_code = get_option('wptocf_body_start_code', '');
        $body_end_code = get_option('wptocf_body_end_code', '');

        // 如果没有任何代码需要注入，直接返回
        if (empty($head_code) && empty($body_start_code) && empty($body_end_code)) {
            WP_to_CF_Logger::info('No code injection configured, skipping');
            return $html;
        }

        WP_to_CF_Logger::info('Starting code injection', [
            'has_head_code' => !empty($head_code),
            'has_body_start_code' => !empty($body_start_code),
            'has_body_end_code' => !empty($body_end_code),
        ]);

        // 1. 注入到 </head> 前
        if (!empty($head_code)) {
            $html = $this->inject_before_closing_tag($html, '</head>', $head_code, 'head');
        }

        // 2. 注入到 <body> 后
        if (!empty($body_start_code)) {
            $html = $this->inject_after_opening_tag($html, '<body', $body_start_code, 'body start');
        }

        // 3. 注入到 </body> 前
        if (!empty($body_end_code)) {
            $html = $this->inject_before_closing_tag($html, '</body>', $body_end_code, 'body end');
        }

        WP_to_CF_Logger::info('Code injection completed');

        return $html;
    }

    /**
     * 在闭合标签前注入代码
     *
     * @param string $html     HTML 内容
     * @param string $tag      闭合标签（例如 </head>）
     * @param string $code     要注入的代码
     * @param string $location 位置描述（用于日志）
     * @return string 注入后的 HTML
     */
    private function inject_before_closing_tag(string $html, string $tag, string $code, string $location): string
    {
        // 查找标签位置（不区分大小写）
        $pos = stripos($html, $tag);

        if ($pos === false) {
            WP_to_CF_Logger::warning("Tag not found for injection", [
                'tag' => $tag,
                'location' => $location,
            ]);
            return $html;
        }

        // 在标签前插入代码
        $before = substr($html, 0, $pos);
        $after = substr($html, $pos);

        // 添加注释标记
        $injected_code = "\n<!-- WP to CF: {$location} code injection start -->\n";
        $injected_code .= $code;
        $injected_code .= "\n<!-- WP to CF: {$location} code injection end -->\n";

        $result = $before . $injected_code . $after;

        WP_to_CF_Logger::info("Code injected before tag", [
            'tag' => $tag,
            'location' => $location,
            'code_length' => strlen($code),
        ]);

        return $result;
    }

    /**
     * 在开放标签后注入代码
     *
     * @param string $html     HTML 内容
     * @param string $tag      开放标签（例如 <body）
     * @param string $code     要注入的代码
     * @param string $location 位置描述（用于日志）
     * @return string 注入后的 HTML
     */
    private function inject_after_opening_tag(string $html, string $tag, string $code, string $location): string
    {
        // 查找标签位置（不区分大小写）
        $pos = stripos($html, $tag);

        if ($pos === false) {
            WP_to_CF_Logger::warning("Tag not found for injection", [
                'tag' => $tag,
                'location' => $location,
            ]);
            return $html;
        }

        // 找到标签的结束位置（>）
        $tag_end_pos = strpos($html, '>', $pos);

        if ($tag_end_pos === false) {
            WP_to_CF_Logger::warning("Tag closing bracket not found", [
                'tag' => $tag,
                'location' => $location,
            ]);
            return $html;
        }

        // 在标签结束后插入代码
        $before = substr($html, 0, $tag_end_pos + 1);
        $after = substr($html, $tag_end_pos + 1);

        // 添加注释标记
        $injected_code = "\n<!-- WP to CF: {$location} code injection start -->\n";
        $injected_code .= $code;
        $injected_code .= "\n<!-- WP to CF: {$location} code injection end -->\n";

        $result = $before . $injected_code . $after;

        WP_to_CF_Logger::info("Code injected after tag", [
            'tag' => $tag,
            'location' => $location,
            'code_length' => strlen($code),
        ]);

        return $result;
    }

    /**
     * 验证 HTML 结构
     * 
     * 检查 HTML 是否包含必要的标签
     *
     * @param string $html HTML 内容
     * @return array 验证结果
     */
    public function validate_html_structure(string $html): array
    {
        $result = [
            'has_html_tag' => stripos($html, '<html') !== false,
            'has_head_tag' => stripos($html, '<head') !== false,
            'has_body_tag' => stripos($html, '<body') !== false,
            'has_closing_head' => stripos($html, '</head>') !== false,
            'has_closing_body' => stripos($html, '</body>') !== false,
            'has_closing_html' => stripos($html, '</html>') !== false,
        ];

        $result['is_valid'] = $result['has_html_tag'] 
            && $result['has_head_tag'] 
            && $result['has_body_tag']
            && $result['has_closing_head']
            && $result['has_closing_body']
            && $result['has_closing_html'];

        return $result;
    }

    /**
     * 获取注入统计信息
     *
     * @return array 统计信息
     */
    public function get_injection_stats(): array
    {
        $head_code = get_option('wptocf_head_code', '');
        $body_start_code = get_option('wptocf_body_start_code', '');
        $body_end_code = get_option('wptocf_body_end_code', '');

        return [
            'head_code_length' => strlen($head_code),
            'body_start_code_length' => strlen($body_start_code),
            'body_end_code_length' => strlen($body_end_code),
            'total_code_length' => strlen($head_code) + strlen($body_start_code) + strlen($body_end_code),
            'has_head_code' => !empty($head_code),
            'has_body_start_code' => !empty($body_start_code),
            'has_body_end_code' => !empty($body_end_code),
        ];
    }
}

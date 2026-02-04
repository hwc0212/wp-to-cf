<?php
/**
 * Relative Path Converter
 * 
 * 将 HTML 和 CSS 中的绝对路径转换为相对路径
 * 确保静态站点在任何环境都能正常工作（自愈合部署）
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_Relative_Path_Converter
 * 
 * 简单、可靠的相对路径转换器
 */
class WP_to_CF_Relative_Path_Converter
{
    /**
     * 转换 HTML 中的所有路径为相对路径
     *
     * @param string $html HTML 内容
     * @param string $current_page_path 当前页面路径（如 "about/index.html"）
     * @param array $asset_map 资产映射表 [原始URL => 本地路径]
     * @return string 转换后的 HTML
     */
    public function convert_html_paths(string $html, string $current_page_path, array $asset_map): string
    {
        WP_to_CF_Logger::info('Converting HTML paths to relative', [
            'current_page' => $current_page_path,
            'asset_count' => count($asset_map),
        ]);
        
        // 1. 替换资产 URL 为相对路径
        foreach ($asset_map as $original_url => $local_path) {
            $relative_path = $this->calculate_relative_path($current_page_path, $local_path);
            
            // 替换所有出现的 URL
            $html = str_replace($original_url, $relative_path, $html);
            
            // 也替换 URL 编码的版本
            $html = str_replace(urlencode($original_url), $relative_path, $html);
        }
        
        // 2. 处理内联样式中的 url()
        $html = preg_replace_callback(
            '/style=["\']([^"\']*url\([^)]+\)[^"\']*)["\']/i',
            function($matches) use ($current_page_path, $asset_map) {
                $style = $matches[1];
                foreach ($asset_map as $original_url => $local_path) {
                    $relative_path = $this->calculate_relative_path($current_page_path, $local_path);
                    $style = str_replace($original_url, $relative_path, $style);
                }
                return 'style="' . $style . '"';
            },
            $html
        );
        
        WP_to_CF_Logger::info('HTML path conversion completed');
        
        return $html;
    }
    
    /**
     * 转换 CSS 中的所有路径为相对路径
     *
     * @param string $css CSS 内容
     * @param string $css_file_path CSS 文件路径（如 "assets/css/style.css"）
     * @param array $asset_map 资产映射表 [原始URL => 本地路径]
     * @return string 转换后的 CSS
     */
    public function convert_css_paths(string $css, string $css_file_path, array $asset_map): string
    {
        WP_to_CF_Logger::info('Converting CSS paths to relative', [
            'css_file' => $css_file_path,
            'asset_count' => count($asset_map),
        ]);
        
        // 替换 url() 中的路径
        foreach ($asset_map as $original_url => $local_path) {
            $relative_path = $this->calculate_relative_path($css_file_path, $local_path);
            
            // 替换 url("...") 和 url('...') 和 url(...)
            $css = preg_replace(
                '/url\(["\']?' . preg_quote($original_url, '/') . '["\']?\)/i',
                'url(' . $relative_path . ')',
                $css
            );
        }
        
        WP_to_CF_Logger::info('CSS path conversion completed');
        
        return $css;
    }
    
    /**
     * 计算从一个文件到另一个文件的相对路径
     *
     * @param string $from 起始文件路径（如 "about/team/index.html"）
     * @param string $to 目标文件路径（如 "assets/css/style.css"）
     * @return string 相对路径（如 "../../assets/css/style.css"）
     */
    public function calculate_relative_path(string $from, string $to): string
    {
        // 标准化路径分隔符
        $from = str_replace('\\', '/', $from);
        $to = str_replace('\\', '/', $to);
        
        // 移除开头的斜杠
        $from = ltrim($from, '/');
        $to = ltrim($to, '/');
        
        // 分割路径
        $from_parts = explode('/', dirname($from));
        $to_parts = explode('/', $to);
        
        // 移除空元素
        $from_parts = array_filter($from_parts, function($part) {
            return $part !== '' && $part !== '.';
        });
        $to_parts = array_filter($to_parts, function($part) {
            return $part !== '' && $part !== '.';
        });
        
        // 重新索引数组
        $from_parts = array_values($from_parts);
        $to_parts = array_values($to_parts);
        
        // 找到共同的路径前缀
        $common_length = 0;
        $min_length = min(count($from_parts), count($to_parts));
        
        for ($i = 0; $i < $min_length; $i++) {
            if ($from_parts[$i] === $to_parts[$i]) {
                $common_length++;
            } else {
                break;
            }
        }
        
        // 计算需要返回的层级数
        $up_levels = count($from_parts) - $common_length;
        
        // 构建相对路径
        $relative_parts = [];
        
        // 添加 ../ 部分
        for ($i = 0; $i < $up_levels; $i++) {
            $relative_parts[] = '..';
        }
        
        // 添加目标路径的剩余部分
        for ($i = $common_length; $i < count($to_parts); $i++) {
            $relative_parts[] = $to_parts[$i];
        }
        
        // 如果相对路径为空，使用当前目录
        if (empty($relative_parts)) {
            return './' . basename($to);
        }
        
        $relative_path = implode('/', $relative_parts);
        
        WP_to_CF_Logger::debug('Calculated relative path', [
            'from' => $from,
            'to' => $to,
            'relative' => $relative_path,
        ]);
        
        return $relative_path;
    }
    
    /**
     * 批量转换目录中的所有 CSS 文件
     *
     * @param string $css_dir CSS 文件目录
     * @param array $asset_map 资产映射表
     * @return int 转换的文件数
     */
    public function convert_all_css_files(string $css_dir, array $asset_map): int
    {
        if (!is_dir($css_dir)) {
            return 0;
        }
        
        $count = 0;
        $css_files = glob($css_dir . '/*.css');
        
        foreach ($css_files as $css_file) {
            $css_content = file_get_contents($css_file);
            
            if ($css_content === false) {
                continue;
            }
            
            // 计算 CSS 文件在 ZIP 中的路径
            $css_relative_path = 'assets/css/' . basename($css_file);
            
            // 转换路径
            $converted_css = $this->convert_css_paths($css_content, $css_relative_path, $asset_map);
            
            // 保存回文件
            if (file_put_contents($css_file, $converted_css) !== false) {
                $count++;
            }
        }
        
        WP_to_CF_Logger::info('Batch CSS conversion completed', [
            'files_converted' => $count,
        ]);
        
        return $count;
    }
}

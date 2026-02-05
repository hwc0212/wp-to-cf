<?php
/**
 * 缓存管理器类
 * 
 * 管理资产缓存：查看大小、清理缓存
 *
 * @package WP_to_CF
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_to_CF_Cache_Manager
{
    /**
     * 缓存目录
     */
    private string $cache_dir;
    
    public function __construct()
    {
        // 文件缓存目录（与 universal-whitewash 和 html-cache 一致）
        $this->cache_dir = WP_CONTENT_DIR . '/wptocf-cache';
    }
    
    /**
     * 获取缓存统计信息
     * 
     * @return array 统计信息
     */
    public function get_stats(): array
    {
        $stats = [
            'exists' => false,
            'total_size' => 0,
            'total_size_mb' => 0,
            'css_count' => 0,
            'js_count' => 0,
            'images_count' => 0,
            'fonts_count' => 0,
            'css_size' => 0,
            'js_size' => 0,
            'images_size' => 0,
            'fonts_size' => 0,
            // 导出缓存（哈希缓存）
            'export_cache_count' => 0,
            'export_cache_size' => 0,
            'export_cache_size_mb' => 0,
            'export_cache_last' => 0,
        ];
        
        // 文件缓存统计
        if (file_exists($this->cache_dir)) {
            $stats['exists'] = true;
            $types = ['css', 'js', 'images', 'fonts'];
            
            foreach ($types as $type) {
                $type_dir = $this->cache_dir . '/' . $type;
                
                if (is_dir($type_dir)) {
                    $files = glob($type_dir . '/*');
                    $count = 0;
                    $size = 0;
                    
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            $count++;
                            $size += filesize($file);
                        }
                    }
                    
                    $stats[$type . '_count'] = $count;
                    $stats[$type . '_size'] = $size;
                    $stats['total_size'] += $size;
                }
            }
        }
        
        // 导出缓存统计（数据库中的哈希缓存）
        $export_cache_data = get_option('wptocf_export_cache', null);
        if ($export_cache_data && isset($export_cache_data['files'])) {
            $stats['export_cache_count'] = count($export_cache_data['files']);
            $stats['export_cache_last'] = $export_cache_data['last_export'] ?? 0;
            
            // 按类型统计
            $stats['export_html_count'] = 0;
            $stats['export_css_count'] = 0;
            $stats['export_js_count'] = 0;
            $stats['export_images_count'] = 0;
            $stats['export_fonts_count'] = 0;
            $stats['export_other_count'] = 0;
            
            $stats['export_html_size'] = 0;
            $stats['export_css_size'] = 0;
            $stats['export_js_size'] = 0;
            $stats['export_images_size'] = 0;
            $stats['export_fonts_size'] = 0;
            $stats['export_other_size'] = 0;
            
            foreach ($export_cache_data['files'] as $path => $file_data) {
                $size = $file_data['size'] ?? 0;
                $stats['export_cache_size'] += $size;
                
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                
                if ($ext === 'html' || $ext === 'htm') {
                    $stats['export_html_count']++;
                    $stats['export_html_size'] += $size;
                } elseif ($ext === 'css') {
                    $stats['export_css_count']++;
                    $stats['export_css_size'] += $size;
                } elseif ($ext === 'js') {
                    $stats['export_js_count']++;
                    $stats['export_js_size'] += $size;
                } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico'])) {
                    $stats['export_images_count']++;
                    $stats['export_images_size'] += $size;
                } elseif (in_array($ext, ['woff', 'woff2', 'ttf', 'eot', 'otf'])) {
                    $stats['export_fonts_count']++;
                    $stats['export_fonts_size'] += $size;
                } else {
                    $stats['export_other_count']++;
                    $stats['export_other_size'] += $size;
                }
            }
            
            $stats['export_cache_size_mb'] = round($stats['export_cache_size'] / 1024 / 1024, 2);
            $stats['export_html_size_mb'] = round($stats['export_html_size'] / 1024 / 1024, 2);
            $stats['export_css_size_mb'] = round($stats['export_css_size'] / 1024 / 1024, 2);
            $stats['export_js_size_mb'] = round($stats['export_js_size'] / 1024 / 1024, 2);
            $stats['export_images_size_mb'] = round($stats['export_images_size'] / 1024 / 1024, 2);
            $stats['export_fonts_size_mb'] = round($stats['export_fonts_size'] / 1024 / 1024, 2);
            $stats['export_other_size_mb'] = round($stats['export_other_size'] / 1024 / 1024, 2);
        }
        
        $stats['total_size_mb'] = round($stats['total_size'] / 1024 / 1024, 2);
        $stats['css_size_mb'] = round($stats['css_size'] / 1024 / 1024, 2);
        $stats['js_size_mb'] = round($stats['js_size'] / 1024 / 1024, 2);
        $stats['images_size_mb'] = round($stats['images_size'] / 1024 / 1024, 2);
        $stats['fonts_size_mb'] = round($stats['fonts_size'] / 1024 / 1024, 2);
        
        return $stats;
    }
    
    /**
     * 清理所有缓存
     * 
     * @return array 清理结果
     */
    public function clear_all(): array
    {
        $deleted_files = 0;
        $freed_space = 0;
        
        // 清理文件缓存
        if (file_exists($this->cache_dir)) {
            $stats_before = $this->get_stats();
            $freed_space = $stats_before['total_size'];
            
            $types = ['css', 'js', 'images', 'fonts'];
            
            foreach ($types as $type) {
                $type_dir = $this->cache_dir . '/' . $type;
                
                if (is_dir($type_dir)) {
                    $files = glob($type_dir . '/*');
                    
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            if (unlink($file)) {
                                $deleted_files++;
                            }
                        }
                    }
                }
            }
        }
        
        // 清理导出缓存（文件系统缓存）
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-export-cache.php';
        $export_cache = new WP_to_CF_Export_Cache();
        $export_stats = $export_cache->get_stats();
        $export_cache->clear();
        
        WP_to_CF_Logger::info('Cache cleared', [
            'deleted_files' => $deleted_files,
            'freed_space_mb' => round($freed_space / 1024 / 1024, 2),
            'export_cache_cleared' => $export_stats['file_count'],
        ]);
        
        return [
            'success' => true,
            'deleted_files' => $deleted_files + $export_stats['file_count'],
            'freed_space' => $freed_space + $export_stats['total_size'],
            'freed_space_mb' => round(($freed_space + $export_stats['total_size']) / 1024 / 1024, 2),
            'export_cache_cleared' => true,
            'message' => sprintf('已清理 %d 个文件，释放 %s MB 空间', 
                $deleted_files + $export_stats['file_count'], 
                round(($freed_space + $export_stats['total_size']) / 1024 / 1024, 2)),
        ];
    }
    
    /**
     * 清理指定类型的缓存
     * 
     * @param string $type 类型 (css|js|images|fonts|export)
     * @return array 清理结果
     */
    public function clear_type(string $type): array
    {
        // 清理导出缓存（哈希缓存）
        if ($type === 'export') {
            require_once WPTOCF_PLUGIN_DIR . 'includes/class-export-cache.php';
            $export_cache = new WP_to_CF_Export_Cache();
            $stats = $export_cache->get_stats();
            $export_cache->clear();
            
            WP_to_CF_Logger::info('Export cache cleared', [
                'cleared_entries' => $stats['file_count'],
            ]);
            
            return [
                'success' => true,
                'deleted_files' => $stats['file_count'],
                'freed_space' => $stats['total_size'],
                'freed_space_mb' => $stats['total_size_mb'],
                'message' => sprintf('已清理 %d 个导出缓存文件，释放 %s MB', $stats['file_count'], $stats['total_size_mb']),
            ];
        }
        
        $valid_types = ['css', 'js', 'images', 'fonts'];
        
        if (!in_array($type, $valid_types)) {
            return [
                'success' => false,
                'message' => '无效的缓存类型',
            ];
        }
        
        $type_dir = $this->cache_dir . '/' . $type;
        
        if (!is_dir($type_dir)) {
            return [
                'success' => true,
                'deleted_files' => 0,
                'freed_space' => 0,
                'message' => '缓存目录不存在',
            ];
        }
        
        $files = glob($type_dir . '/*');
        $deleted_files = 0;
        $freed_space = 0;
        
        foreach ($files as $file) {
            if (is_file($file)) {
                $freed_space += filesize($file);
                if (unlink($file)) {
                    $deleted_files++;
                }
            }
        }
        
        WP_to_CF_Logger::info('Cache type cleared', [
            'type' => $type,
            'deleted_files' => $deleted_files,
            'freed_space_mb' => round($freed_space / 1024 / 1024, 2),
        ]);
        
        return [
            'success' => true,
            'deleted_files' => $deleted_files,
            'freed_space' => $freed_space,
            'freed_space_mb' => round($freed_space / 1024 / 1024, 2),
            'message' => sprintf('已清理 %d 个 %s 文件', $deleted_files, $type),
        ];
    }
    
    /**
     * 获取缓存目录路径
     * 
     * @return string 缓存目录路径
     */
    public function get_cache_dir(): string
    {
        return $this->cache_dir;
    }
}

<?php
/**
 * 包管理器类
 * 
 * 管理导出的 ZIP 包：列出、删除、清理旧包
 *
 * @package WP_to_CF
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_to_CF_Package_Manager
{
    /**
     * 获取所有导出的包
     * 
     * @return array 包列表
     */
    public function get_all_packages(): array
    {
        $upload_dir = wp_upload_dir();
        // 导出器保存在 wptocf-exports 子目录
        $packages_dir = $upload_dir['basedir'] . '/wptocf-exports';
        
        // 查找所有 static-site-*.zip 文件（导出器使用的命名格式）
        $pattern = $packages_dir . '/static-site-*.zip';
        $files = glob($pattern);
        
        if (empty($files)) {
            $files = [];
        }
        
        $packages = [];
        
        foreach ($files as $file) {
            $filename = basename($file);
            $file_size = filesize($file);
            $file_time = filemtime($file);
            
            $packages[] = [
                'filename' => $filename,
                'path' => $file,
                'url' => str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $file),
                'size' => $file_size,
                'size_mb' => round($file_size / 1024 / 1024, 2),
                'created' => $file_time,
                'created_formatted' => date('Y-m-d H:i:s', $file_time),
                'age_days' => floor((time() - $file_time) / 86400),
            ];
        }
        
        // 按创建时间倒序排列
        usort($packages, function($a, $b) {
            return $b['created'] - $a['created'];
        });
        
        return $packages;
    }
    
    /**
     * 删除指定的包
     * 
     * @param string $filename 文件名
     * @return bool 是否成功
     */
    public function delete_package(string $filename): bool
    {
        $upload_dir = wp_upload_dir();
        // 文件在 wptocf-exports 子目录
        $file_path = $upload_dir['basedir'] . '/wptocf-exports/' . $filename;
        
        // 安全检查：确保文件名符合预期格式 (static-site-*.zip)
        if (!preg_match('/^static-site-[a-z0-9\-]+\.zip$/i', $filename)) {
            WP_to_CF_Logger::error('Invalid package filename', ['filename' => $filename]);
            return false;
        }
        
        // 检查文件是否存在
        if (!file_exists($file_path)) {
            WP_to_CF_Logger::warning('Package file not found', ['path' => $file_path]);
            return false;
        }
        
        // 删除文件
        $result = unlink($file_path);
        
        if ($result) {
            WP_to_CF_Logger::info('Package deleted', ['filename' => $filename]);
        } else {
            WP_to_CF_Logger::error('Failed to delete package', ['filename' => $filename]);
        }
        
        return $result;
    }
    
    /**
     * 清理旧包（保留最新的 N 个）
     * 
     * @param int $keep_count 保留数量
     * @return array 清理结果
     */
    public function cleanup_old_packages(int $keep_count = 5): array
    {
        $packages = $this->get_all_packages();
        
        if (count($packages) <= $keep_count) {
            return [
                'success' => true,
                'deleted_count' => 0,
                'message' => '无需清理',
            ];
        }
        
        // 保留最新的 N 个，删除其余的
        $packages_to_delete = array_slice($packages, $keep_count);
        $deleted_count = 0;
        $failed_count = 0;
        
        foreach ($packages_to_delete as $package) {
            if ($this->delete_package($package['filename'])) {
                $deleted_count++;
            } else {
                $failed_count++;
            }
        }
        
        WP_to_CF_Logger::info('Old packages cleaned up', [
            'deleted' => $deleted_count,
            'failed' => $failed_count,
            'kept' => $keep_count,
        ]);
        
        return [
            'success' => true,
            'deleted_count' => $deleted_count,
            'failed_count' => $failed_count,
            'message' => sprintf('已删除 %d 个旧包', $deleted_count),
        ];
    }
    
    /**
     * 获取包统计信息
     * 
     * @return array 统计信息
     */
    public function get_stats(): array
    {
        $packages = $this->get_all_packages();
        
        $total_size = 0;
        foreach ($packages as $package) {
            $total_size += $package['size'];
        }
        
        return [
            'total_count' => count($packages),
            'total_size' => $total_size,
            'total_size_mb' => round($total_size / 1024 / 1024, 2),
            'oldest_age_days' => !empty($packages) ? end($packages)['age_days'] : 0,
        ];
    }
}

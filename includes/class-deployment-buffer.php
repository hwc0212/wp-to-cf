<?php
/**
 * 部署缓冲区类
 * 
 * 管理本地静态文件缓冲区，用于原子化批量部署
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_Deployment_Buffer
 * 
 * 管理待部署文件的本地缓冲区
 */
class WP_to_CF_Deployment_Buffer
{
    /**
     * 缓冲区根目录
     *
     * @var string
     */
    private string $buffer_dir;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $upload_dir = wp_upload_dir();
        $this->buffer_dir = trailingslashit($upload_dir['basedir']) . 'wptocf-buffer';
        
        // 确保缓冲区目录存在
        $this->ensure_buffer_dir();
    }

    /**
     * 确保缓冲区目录存在
     *
     * @return bool 是否成功
     */
    private function ensure_buffer_dir(): bool
    {
        if (!file_exists($this->buffer_dir)) {
            if (!wp_mkdir_p($this->buffer_dir)) {
                WP_to_CF_Logger::error('Failed to create buffer directory', [
                    'buffer_dir' => $this->buffer_dir,
                ]);
                return false;
            }
            
            // 添加 .htaccess 保护
            $htaccess = $this->buffer_dir . '/.htaccess';
            file_put_contents($htaccess, "Deny from all\n");
        }
        
        return true;
    }

    /**
     * 保存文件到缓冲区
     *
     * @param string $file_path 文件路径（相对路径，如 'index.html' 或 '2024/01/post/index.html'）
     * @param string $content   文件内容
     * @return bool 是否成功
     */
    public function save_file(string $file_path, string $content): bool
    {
        // 移除开头的斜杠
        $file_path = ltrim($file_path, '/');
        
        $full_path = $this->buffer_dir . '/' . $file_path;
        $dir = dirname($full_path);
        
        // 确保目录存在
        if (!file_exists($dir)) {
            if (!wp_mkdir_p($dir)) {
                WP_to_CF_Logger::error('Failed to create buffer subdirectory', [
                    'dir' => $dir,
                    'file_path' => $file_path,
                ]);
                return false;
            }
        }
        
        // 写入文件
        $result = file_put_contents($full_path, $content);
        
        if ($result === false) {
            WP_to_CF_Logger::error('Failed to save file to buffer', [
                'file_path' => $file_path,
                'full_path' => $full_path,
            ]);
            return false;
        }
        
        WP_to_CF_Logger::info('File saved to buffer', [
            'file_path' => $file_path,
            'size' => strlen($content),
        ]);
        
        return true;
    }

    /**
     * 获取缓冲区中的所有文件
     *
     * @return array 文件数组，键为相对路径，值为内容
     */
    public function get_all_files(): array
    {
        $files = [];
        
        if (!file_exists($this->buffer_dir)) {
            return $files;
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->buffer_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() !== '.htaccess') {
                $relative_path = str_replace($this->buffer_dir . DIRECTORY_SEPARATOR, '', $file->getPathname());
                $relative_path = str_replace('\\', '/', $relative_path); // Windows 兼容
                
                $content = file_get_contents($file->getPathname());
                if ($content !== false) {
                    $files[$relative_path] = $content;
                }
            }
        }
        
        WP_to_CF_Logger::info('Retrieved all files from buffer', [
            'file_count' => count($files),
        ]);
        
        return $files;
    }

    /**
     * 获取特定文件
     *
     * @param string $file_path 文件路径
     * @return string|null 文件内容，如果不存在返回 null
     */
    public function get_file(string $file_path): ?string
    {
        $file_path = ltrim($file_path, '/');
        $full_path = $this->buffer_dir . '/' . $file_path;
        
        if (!file_exists($full_path)) {
            return null;
        }
        
        $content = file_get_contents($full_path);
        return $content !== false ? $content : null;
    }

    /**
     * 检查文件是否存在
     *
     * @param string $file_path 文件路径
     * @return bool 是否存在
     */
    public function has_file(string $file_path): bool
    {
        $file_path = ltrim($file_path, '/');
        $full_path = $this->buffer_dir . '/' . $file_path;
        return file_exists($full_path);
    }

    /**
     * 清空缓冲区
     *
     * @return bool 是否成功
     */
    public function clear(): bool
    {
        if (!file_exists($this->buffer_dir)) {
            return true;
        }
        
        try {
            $this->delete_directory_recursive($this->buffer_dir);
            $this->ensure_buffer_dir(); // 重新创建空目录
            
            WP_to_CF_Logger::info('Buffer cleared successfully');
            return true;
        } catch (Exception $e) {
            WP_to_CF_Logger::error('Failed to clear buffer', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 递归删除目录
     *
     * @param string $dir 目录路径
     * @return void
     */
    private function delete_directory_recursive(string $dir): void
    {
        if (!file_exists($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            
            if (is_dir($path)) {
                $this->delete_directory_recursive($path);
            } else {
                unlink($path);
            }
        }
        
        rmdir($dir);
    }

    /**
     * 获取缓冲区统计信息
     *
     * @return array 统计信息
     */
    public function get_stats(): array
    {
        $files = $this->get_all_files();
        $total_size = 0;
        
        foreach ($files as $content) {
            $total_size += strlen($content);
        }
        
        return [
            'file_count' => count($files),
            'total_size' => $total_size,
            'total_size_mb' => round($total_size / 1024 / 1024, 2),
            'buffer_dir' => $this->buffer_dir,
        ];
    }

    /**
     * 获取超过指定时间的文件
     *
     * @param int $seconds 秒数
     * @return array 文件路径数组
     */
    public function get_files_older_than(int $seconds): array
    {
        $old_files = [];
        $threshold = time() - $seconds;
        
        if (!file_exists($this->buffer_dir)) {
            return $old_files;
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->buffer_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() !== '.htaccess') {
                if ($file->getMTime() < $threshold) {
                    $relative_path = str_replace($this->buffer_dir . DIRECTORY_SEPARATOR, '', $file->getPathname());
                    $relative_path = str_replace('\\', '/', $relative_path);
                    $old_files[] = $relative_path;
                }
            }
        }
        
        return $old_files;
    }

    /**
     * 删除特定文件
     *
     * @param string $file_path 文件路径
     * @return bool 是否成功
     */
    public function delete_file(string $file_path): bool
    {
        $file_path = ltrim($file_path, '/');
        $full_path = $this->buffer_dir . '/' . $file_path;
        
        if (!file_exists($full_path)) {
            return true;
        }
        
        $result = unlink($full_path);
        
        if ($result) {
            WP_to_CF_Logger::info('File deleted from buffer', [
                'file_path' => $file_path,
            ]);
        } else {
            WP_to_CF_Logger::error('Failed to delete file from buffer', [
                'file_path' => $file_path,
            ]);
        }
        
        return $result;
    }
}

<?php
/**
 * HTML 磁盘缓存类
 * 
 * 持久化生成的 HTML 到本地磁盘，避免重复生成
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_HTML_Cache
 * 
 * 管理 HTML 文件的磁盘缓存
 */
class WP_to_CF_HTML_Cache
{
    /**
     * 缓存根目录
     *
     * @var string
     */
    private string $cache_dir;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $upload_dir = wp_upload_dir();
        $this->cache_dir = trailingslashit($upload_dir['basedir']) . 'wptocf-cache';
        
        // 确保缓存目录存在
        $this->ensure_cache_dir();
    }

    /**
     * 确保缓存目录存在
     *
     * @return bool 是否成功
     */
    private function ensure_cache_dir(): bool
    {
        if (!file_exists($this->cache_dir)) {
            if (!wp_mkdir_p($this->cache_dir)) {
                WP_to_CF_Logger::error('Failed to create cache directory', [
                    'cache_dir' => $this->cache_dir,
                ]);
                return false;
            }
            
            // 添加 .htaccess 保护
            $htaccess = $this->cache_dir . '/.htaccess';
            file_put_contents($htaccess, "Deny from all\n");
        }
        
        return true;
    }

    /**
     * 保存 HTML 到缓存
     *
     * @param string $file_path 文件路径（相对路径）
     * @param string $content   HTML 内容
     * @param string $hash      内容哈希
     * @return bool 是否成功
     */
    public function save(string $file_path, string $content, string $hash): bool
    {
        // 移除开头的斜杠
        $file_path = ltrim($file_path, '/');
        
        // 生成缓存文件路径（使用哈希作为文件名）
        $cache_file = $this->get_cache_file_path($file_path, $hash);
        $dir = dirname($cache_file);
        
        // 确保目录存在
        if (!file_exists($dir)) {
            if (!wp_mkdir_p($dir)) {
                WP_to_CF_Logger::error('Failed to create cache subdirectory', [
                    'dir' => $dir,
                    'file_path' => $file_path,
                ]);
                return false;
            }
        }
        
        // 写入文件
        $result = file_put_contents($cache_file, $content);
        
        if ($result === false) {
            WP_to_CF_Logger::error('Failed to save file to cache', [
                'file_path' => $file_path,
                'cache_file' => $cache_file,
            ]);
            return false;
        }
        
        WP_to_CF_Logger::info('File saved to cache', [
            'file_path' => $file_path,
            'hash' => $hash,
            'size' => strlen($content),
        ]);
        
        return true;
    }

    /**
     * 从缓存读取 HTML
     *
     * @param string $file_path 文件路径
     * @param string $hash      期望的内容哈希
     * @return string|null HTML 内容，如果不存在或哈希不匹配返回 null
     */
    public function get(string $file_path, string $hash): ?string
    {
        $file_path = ltrim($file_path, '/');
        $cache_file = $this->get_cache_file_path($file_path, $hash);
        
        if (!file_exists($cache_file)) {
            return null;
        }
        
        $content = file_get_contents($cache_file);
        
        if ($content === false) {
            return null;
        }
        
        // 验证哈希
        $actual_hash = hash('sha256', $content);
        if ($actual_hash !== $hash) {
            WP_to_CF_Logger::warning('Cache file hash mismatch', [
                'file_path' => $file_path,
                'expected_hash' => $hash,
                'actual_hash' => $actual_hash,
            ]);
            return null;
        }
        
        WP_to_CF_Logger::info('File retrieved from cache', [
            'file_path' => $file_path,
            'hash' => $hash,
            'size' => strlen($content),
        ]);
        
        return $content;
    }

    /**
     * 检查缓存是否存在且有效
     *
     * @param string $file_path 文件路径
     * @param string $hash      期望的内容哈希
     * @return bool 是否存在且有效
     */
    public function has(string $file_path, string $hash): bool
    {
        $file_path = ltrim($file_path, '/');
        $cache_file = $this->get_cache_file_path($file_path, $hash);
        
        return file_exists($cache_file);
    }

    /**
     * 生成缓存文件路径
     *
     * @param string $file_path 原始文件路径
     * @param string $hash      内容哈希
     * @return string 缓存文件路径
     */
    private function get_cache_file_path(string $file_path, string $hash): string
    {
        // 使用文件路径的目录结构 + 哈希作为文件名
        $dir = dirname($file_path);
        if ($dir === '.') {
            $dir = '';
        }
        
        // 缓存文件名：hash.html
        $cache_file_name = $hash . '.html';
        
        if (empty($dir)) {
            return $this->cache_dir . '/' . $cache_file_name;
        } else {
            return $this->cache_dir . '/' . $dir . '/' . $cache_file_name;
        }
    }

    /**
     * 删除特定文件的缓存
     *
     * @param string $file_path 文件路径
     * @return bool 是否成功
     */
    public function delete(string $file_path): bool
    {
        $file_path = ltrim($file_path, '/');
        $dir = dirname($file_path);
        if ($dir === '.') {
            $dir = '';
        }
        
        $cache_dir = empty($dir) ? $this->cache_dir : $this->cache_dir . '/' . $dir;
        
        if (!file_exists($cache_dir)) {
            return true;
        }
        
        // 删除该目录下的所有缓存文件
        $files = glob($cache_dir . '/*.html');
        $deleted = 0;
        
        foreach ($files as $file) {
            if (unlink($file)) {
                $deleted++;
            }
        }
        
        WP_to_CF_Logger::info('Cache files deleted', [
            'file_path' => $file_path,
            'deleted_count' => $deleted,
        ]);
        
        return true;
    }

    /**
     * 清空所有缓存
     *
     * @return bool 是否成功
     */
    public function clear_all(): bool
    {
        if (!file_exists($this->cache_dir)) {
            return true;
        }
        
        try {
            $this->delete_directory_recursive($this->cache_dir);
            $this->ensure_cache_dir(); // 重新创建空目录
            
            WP_to_CF_Logger::info('All cache cleared successfully');
            return true;
        } catch (Exception $e) {
            WP_to_CF_Logger::error('Failed to clear cache', [
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
     * 获取缓存统计信息
     *
     * @return array 统计信息
     */
    public function get_stats(): array
    {
        if (!file_exists($this->cache_dir)) {
            return [
                'file_count' => 0,
                'total_size' => 0,
                'total_size_mb' => 0,
                'cache_dir' => $this->cache_dir,
            ];
        }
        
        $file_count = 0;
        $total_size = 0;
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->cache_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() !== '.htaccess') {
                $file_count++;
                $total_size += $file->getSize();
            }
        }
        
        return [
            'file_count' => $file_count,
            'total_size' => $total_size,
            'total_size_mb' => round($total_size / 1024 / 1024, 2),
            'cache_dir' => $this->cache_dir,
        ];
    }
}


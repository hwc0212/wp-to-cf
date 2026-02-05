<?php
/**
 * 导出缓存管理器
 * 
 * 使用文件系统缓存已生成的静态文件
 * - 存储实际文件内容到文件系统
 * - 存储元数据（哈希、大小）到 wp_options
 * - 支持增量更新和导出
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_to_CF_Export_Cache
{
    private const OPTION_KEY = 'wptocf_export_cache';
    private const CACHE_VERSION = 2;
    
    /** @var string 缓存目录 */
    private $cache_dir;
    
    /** @var array 元数据缓存 */
    private $meta = null;
    
    public function __construct()
    {
        $upload_dir = wp_upload_dir();
        $this->cache_dir = $upload_dir['basedir'] . '/wptocf-cache/';
        $this->ensure_cache_dir();
        $this->load_meta();
    }
    
    /**
     * 确保缓存目录存在
     */
    private function ensure_cache_dir()
    {
        if (!file_exists($this->cache_dir)) {
            wp_mkdir_p($this->cache_dir);
            // 添加 .htaccess 防止直接访问
            file_put_contents($this->cache_dir . '.htaccess', 'Deny from all');
            file_put_contents($this->cache_dir . 'index.php', '<?php // Silence is golden');
        }
    }
    
    /**
     * 加载元数据
     */
    private function load_meta()
    {
        $data = get_option(self::OPTION_KEY, null);
        
        if ($data && isset($data['version']) && $data['version'] === self::CACHE_VERSION) {
            $this->meta = $data;
        } else {
            // 版本升级，清理旧缓存
            $this->clear();
            $this->meta = [
                'version' => self::CACHE_VERSION,
                'last_export' => 0,
                'files' => [],
            ];
        }
    }
    
    /**
     * 保存元数据
     */
    private function save_meta()
    {
        $this->meta['last_export'] = time();
        update_option(self::OPTION_KEY, $this->meta, false);
    }
    
    /**
     * 获取文件的缓存路径
     */
    private function get_cache_path($path)
    {
        // 使用路径的 hash 作为文件名，避免目录结构问题
        $hash = md5($path);
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        return $this->cache_dir . $hash . '.' . $ext;
    }
    
    /**
     * 计算文件哈希（与 Cloudflare 兼容）
     */
    public function calculate_hash($content, $path)
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $base64_content = base64_encode($content);
        return substr(hash('sha256', $base64_content . $ext), 0, 32);
    }
    
    /**
     * 检查文件是否已缓存
     */
    public function has_file($path)
    {
        if (!isset($this->meta['files'][$path])) {
            return false;
        }
        
        $cache_path = $this->get_cache_path($path);
        return file_exists($cache_path);
    }
    
    /**
     * 获取缓存的文件内容
     */
    public function get_file($path)
    {
        if (!$this->has_file($path)) {
            return null;
        }
        
        $cache_path = $this->get_cache_path($path);
        return @file_get_contents($cache_path);
    }
    
    /**
     * 获取所有缓存的文件内容
     */
    public function get_all_files()
    {
        $files = [];
        foreach ($this->meta['files'] as $path => $meta) {
            $content = $this->get_file($path);
            if ($content !== null) {
                $files[$path] = $content;
            }
        }
        return $files;
    }
    
    /**
     * 保存文件到缓存
     */
    public function save_file($path, $content)
    {
        $cache_path = $this->get_cache_path($path);
        
        if (@file_put_contents($cache_path, $content) === false) {
            WP_to_CF_Logger::error('缓存文件写入失败', ['path' => $path]);
            return false;
        }
        
        $this->meta['files'][$path] = [
            'hash' => $this->calculate_hash($content, $path),
            'size' => strlen($content),
            'mtime' => time(),
        ];
        
        return true;
    }
    
    /**
     * 批量保存文件到缓存
     */
    public function save_files($files)
    {
        $saved = 0;
        foreach ($files as $path => $content) {
            if ($this->save_file($path, $content)) {
                $saved++;
            }
        }
        $this->save_meta();
        return $saved;
    }
    
    /**
     * 更新缓存（只更新变化的文件）
     * 返回: ['added' => count, 'updated' => count, 'unchanged' => count]
     */
    public function update_files($files)
    {
        $added = 0;
        $updated = 0;
        $unchanged = 0;
        
        foreach ($files as $path => $content) {
            $new_hash = $this->calculate_hash($content, $path);
            
            if (!isset($this->meta['files'][$path])) {
                // 新文件
                $this->save_file($path, $content);
                $added++;
            } elseif ($this->meta['files'][$path]['hash'] !== $new_hash) {
                // 文件已变化
                $this->save_file($path, $content);
                $updated++;
            } else {
                // 文件未变化
                $unchanged++;
            }
        }
        
        $this->save_meta();
        
        return [
            'added' => $added,
            'updated' => $updated,
            'unchanged' => $unchanged,
        ];
    }
    
    /**
     * 删除缓存文件
     */
    public function delete_file($path)
    {
        $cache_path = $this->get_cache_path($path);
        if (file_exists($cache_path)) {
            @unlink($cache_path);
        }
        unset($this->meta['files'][$path]);
    }
    
    /**
     * 清理不再需要的文件
     */
    public function prune($current_paths)
    {
        $cached_paths = array_keys($this->meta['files']);
        $removed = array_diff($cached_paths, $current_paths);
        
        foreach ($removed as $path) {
            $this->delete_file($path);
        }
        
        if (!empty($removed)) {
            $this->save_meta();
        }
        
        return count($removed);
    }
    
    /**
     * 获取已缓存文件的哈希
     */
    public function get_cached_hash($path)
    {
        return $this->meta['files'][$path]['hash'] ?? null;
    }
    
    /**
     * 获取所有缓存的文件哈希
     */
    public function get_all_hashes()
    {
        $hashes = [];
        foreach ($this->meta['files'] as $path => $data) {
            $hashes[$path] = $data['hash'];
        }
        return $hashes;
    }
    
    /**
     * 过滤出变化的文件
     */
    public function filter_changed_files($files)
    {
        $changed = [];
        $unchanged = [];
        
        foreach ($files as $path => $content) {
            $new_hash = $this->calculate_hash($content, $path);
            
            if (isset($this->meta['files'][$path]) && 
                $this->meta['files'][$path]['hash'] === $new_hash) {
                $unchanged[$path] = $new_hash;
            } else {
                $changed[$path] = $content;
            }
        }
        
        return [
            'changed' => $changed,
            'unchanged' => $unchanged,
        ];
    }
    
    /**
     * 清除所有缓存
     */
    public function clear()
    {
        // 删除缓存目录中的所有文件
        if (is_dir($this->cache_dir)) {
            $files = glob($this->cache_dir . '*');
            foreach ($files as $file) {
                if (is_file($file) && basename($file) !== '.htaccess' && basename($file) !== 'index.php') {
                    @unlink($file);
                }
            }
        }
        
        $this->meta = [
            'version' => self::CACHE_VERSION,
            'last_export' => 0,
            'files' => [],
        ];
        delete_option(self::OPTION_KEY);
    }
    
    /**
     * 获取缓存统计
     */
    public function get_stats()
    {
        $total_size = 0;
        $by_type = [
            'html' => ['count' => 0, 'size' => 0],
            'css' => ['count' => 0, 'size' => 0],
            'js' => ['count' => 0, 'size' => 0],
            'images' => ['count' => 0, 'size' => 0],
            'fonts' => ['count' => 0, 'size' => 0],
            'other' => ['count' => 0, 'size' => 0],
        ];
        
        foreach ($this->meta['files'] as $path => $data) {
            $total_size += $data['size'];
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            
            if ($ext === 'html') {
                $by_type['html']['count']++;
                $by_type['html']['size'] += $data['size'];
            } elseif ($ext === 'css') {
                $by_type['css']['count']++;
                $by_type['css']['size'] += $data['size'];
            } elseif ($ext === 'js') {
                $by_type['js']['count']++;
                $by_type['js']['size'] += $data['size'];
            } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico'])) {
                $by_type['images']['count']++;
                $by_type['images']['size'] += $data['size'];
            } elseif (in_array($ext, ['woff', 'woff2', 'ttf', 'eot', 'otf'])) {
                $by_type['fonts']['count']++;
                $by_type['fonts']['size'] += $data['size'];
            } else {
                $by_type['other']['count']++;
                $by_type['other']['size'] += $data['size'];
            }
        }
        
        return [
            'file_count' => count($this->meta['files']),
            'total_size' => $total_size,
            'total_size_mb' => round($total_size / 1024 / 1024, 2),
            'last_export' => $this->meta['last_export'],
            'by_type' => $by_type,
        ];
    }
    
    /**
     * 检查缓存是否存在且有效
     */
    public function is_valid()
    {
        return !empty($this->meta['files']) && $this->meta['last_export'] > 0;
    }
    
    /**
     * 获取缓存目录路径
     */
    public function get_cache_dir()
    {
        return $this->cache_dir;
    }
}

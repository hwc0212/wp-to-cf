<?php
/**
 * 导出缓存管理器
 * 
 * 缓存已生成的文件哈希，支持增量导出
 * - 存储文件内容哈希
 * - 比较文件变化
 * - 支持增量 ZIP 生成
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_to_CF_Export_Cache
{
    private const OPTION_KEY = 'wptocf_export_cache';
    private const CACHE_VERSION = 1;
    
    /**
     * 缓存数据结构:
     * [
     *   'version' => 1,
     *   'last_export' => timestamp,
     *   'files' => [
     *     'path/to/file.html' => [
     *       'hash' => 'sha256hash',
     *       'size' => 1234,
     *       'mtime' => timestamp,
     *     ],
     *     ...
     *   ]
     * ]
     */
    private $cache = null;
    
    public function __construct()
    {
        $this->load_cache();
    }
    
    /**
     * 加载缓存
     */
    private function load_cache()
    {
        $data = get_option(self::OPTION_KEY, null);
        
        if ($data && isset($data['version']) && $data['version'] === self::CACHE_VERSION) {
            $this->cache = $data;
        } else {
            $this->cache = [
                'version' => self::CACHE_VERSION,
                'last_export' => 0,
                'files' => [],
            ];
        }
    }
    
    /**
     * 保存缓存
     */
    private function save_cache()
    {
        $this->cache['last_export'] = time();
        update_option(self::OPTION_KEY, $this->cache, false);
    }
    
    /**
     * 计算文件哈希（与 Cloudflare 兼容）
     * Cloudflare Pages 使用: sha256(base64内容 + 扩展名) 取前32字符
     */
    public function calculate_hash($content, $path)
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $base64_content = base64_encode($content);
        return substr(hash('sha256', $base64_content . $ext), 0, 32);
    }
    
    /**
     * 检查文件是否已缓存且未变化
     */
    public function is_cached($path, $content)
    {
        if (!isset($this->cache['files'][$path])) {
            return false;
        }
        
        $cached = $this->cache['files'][$path];
        $current_hash = $this->calculate_hash($content, $path);
        
        return $cached['hash'] === $current_hash;
    }
    
    /**
     * 获取已缓存文件的哈希
     */
    public function get_cached_hash($path)
    {
        return $this->cache['files'][$path]['hash'] ?? null;
    }
    
    /**
     * 更新文件缓存
     */
    public function update_file($path, $content)
    {
        $this->cache['files'][$path] = [
            'hash' => $this->calculate_hash($content, $path),
            'size' => strlen($content),
            'mtime' => time(),
        ];
    }
    
    /**
     * 批量更新文件缓存
     */
    public function update_files($files)
    {
        foreach ($files as $path => $content) {
            $this->update_file($path, $content);
        }
        $this->save_cache();
    }
    
    /**
     * 过滤出变化的文件（增量导出）
     * 返回: ['changed' => [...], 'unchanged' => [...]]
     */
    public function filter_changed_files($files)
    {
        $changed = [];
        $unchanged = [];
        
        foreach ($files as $path => $content) {
            if ($this->is_cached($path, $content)) {
                $unchanged[$path] = $this->cache['files'][$path]['hash'];
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
     * 获取所有缓存的文件哈希
     */
    public function get_all_hashes()
    {
        $hashes = [];
        foreach ($this->cache['files'] as $path => $data) {
            $hashes[$path] = $data['hash'];
        }
        return $hashes;
    }
    
    /**
     * 清除缓存
     */
    public function clear()
    {
        $this->cache = [
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
        foreach ($this->cache['files'] as $data) {
            $total_size += $data['size'];
        }
        
        return [
            'file_count' => count($this->cache['files']),
            'total_size' => $total_size,
            'total_size_mb' => round($total_size / 1024 / 1024, 2),
            'last_export' => $this->cache['last_export'],
        ];
    }
    
    /**
     * 移除不再存在的文件
     */
    public function prune($current_files)
    {
        $current_paths = array_keys($current_files);
        $cached_paths = array_keys($this->cache['files']);
        
        $removed = array_diff($cached_paths, $current_paths);
        foreach ($removed as $path) {
            unset($this->cache['files'][$path]);
        }
        
        if (!empty($removed)) {
            $this->save_cache();
        }
        
        return count($removed);
    }
}

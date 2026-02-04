<?php
/**
 * 哈希账本类
 * 
 * 负责计算内容哈希、变更检测和账本管理
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_Hash_Ledger
 * 
 * 管理文件哈希值，实现增量同步的变更检测
 */
class WP_to_CF_Hash_Ledger
{
    /**
     * 数据库表名
     */
    private string $table_name;

    /**
     * 构造函数
     */
    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wptocf_ledger';
    }

    /**
     * 计算内容的 SHA-256 哈希值
     *
     * @param string $content 文件内容
     * @return string 64 位十六进制哈希值
     */
    public function calculate_hash(string $content): string
    {
        return hash('sha256', $content);
    }

    /**
     * 检查文件是否发生变更
     * 
     * 通过比较新哈希与历史哈希判断文件是否需要上传
     *
     * @param int    $post_id   文章 ID
     * @param string $file_path 文件路径
     * @param string $new_hash  新的哈希值
     * @return bool 是否发生变更（true=需要上传，false=跳过）
     */
    public function has_changed(int $post_id, string $file_path, string $new_hash): bool
    {
        $old_hash = $this->get_hash($post_id, $file_path);

        // 如果没有历史记录，视为新文件（需要上传）
        if ($old_hash === null) {
            WP_to_CF_Logger::info('New file detected, upload required', [
                'post_id' => $post_id,
                'file_path' => $file_path,
                'new_hash' => $new_hash,
            ]);
            return true;
        }

        // 比较哈希值
        $has_changed = ($old_hash !== $new_hash);

        if ($has_changed) {
            // 标记为 modified 状态
            $this->mark_as_modified($post_id, $file_path);
            
            WP_to_CF_Logger::info('Content changed, upload required', [
                'post_id' => $post_id,
                'file_path' => $file_path,
                'old_hash' => $old_hash,
                'new_hash' => $new_hash,
            ]);
        } else {
            WP_to_CF_Logger::info('Content unchanged, skipping upload', [
                'post_id' => $post_id,
                'file_path' => $file_path,
                'hash' => $new_hash,
            ]);
        }

        return $has_changed;
    }

    /**
     * 更新账本记录
     * 
     * 在文件成功上传后更新哈希值和同步时间
     *
     * @param int    $post_id   文章 ID
     * @param string $file_path 文件路径
     * @param string $hash      哈希值
     * @param int    $file_size 文件大小（字节）
     * @return bool 是否成功
     */
    public function update_ledger(int $post_id, string $file_path, string $hash, int $file_size = 0): bool
    {
        global $wpdb;

        $data = [
            'post_id' => $post_id,
            'file_path' => $file_path,
            'content_hash' => $hash,
            'file_size' => $file_size,
            'sync_status' => 'synced',
            'last_synced' => current_time('mysql'),
        ];

        // 使用 REPLACE INTO 语法（如果存在则更新，不存在则插入）
        $result = $wpdb->replace(
            $this->table_name,
            $data,
            ['%d', '%s', '%s', '%d', '%s', '%s']
        );

        if ($result === false) {
            WP_to_CF_Logger::error('Failed to update ledger', [
                'post_id' => $post_id,
                'file_path' => $file_path,
                'error' => $wpdb->last_error,
            ]);
            return false;
        }

        WP_to_CF_Logger::info('Ledger updated successfully', [
            'post_id' => $post_id,
            'file_path' => $file_path,
            'hash' => $hash,
            'file_size' => $file_size,
            'sync_status' => 'synced',
        ]);

        return true;
    }

    /**
     * 获取文件的历史哈希值
     *
     * @param int    $post_id   文章 ID
     * @param string $file_path 文件路径
     * @return string|null 哈希值，如果不存在返回 null
     */
    public function get_hash(int $post_id, string $file_path): ?string
    {
        global $wpdb;

        $hash = $wpdb->get_var($wpdb->prepare(
            "SELECT content_hash FROM {$this->table_name} WHERE file_path = %s LIMIT 1",
            $file_path
        ));

        return $hash !== null ? $hash : null;
    }

    /**
     * 删除文章的所有账本记录
     * 
     * 当文章被删除时调用
     *
     * @param int $post_id 文章 ID
     * @return bool 是否成功
     */
    public function delete_by_post_id(int $post_id): bool
    {
        global $wpdb;

        $result = $wpdb->delete(
            $this->table_name,
            ['post_id' => $post_id],
            ['%d']
        );

        if ($result === false) {
            WP_to_CF_Logger::error('Failed to delete ledger records', [
                'post_id' => $post_id,
                'error' => $wpdb->last_error,
            ]);
            return false;
        }

        WP_to_CF_Logger::info('Ledger records deleted', [
            'post_id' => $post_id,
            'deleted_count' => $result,
        ]);

        return true;
    }

    /**
     * 获取文章的所有文件记录
     *
     * @param int $post_id 文章 ID
     * @return array 文件记录数组
     */
    public function get_files_by_post_id(int $post_id): array
    {
        global $wpdb;

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE post_id = %d ORDER BY last_synced DESC",
            $post_id
        ), ARRAY_A);

        return $results !== null ? $results : [];
    }

    /**
     * 清理过期的账本记录
     * 
     * 删除超过指定天数未同步的记录
     *
     * @param int $days 天数，默认 90 天
     * @return int 删除的记录数
     */
    public function cleanup_old_records(int $days = 90): int
    {
        global $wpdb;

        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE last_synced < %s",
            $cutoff_date
        ));

        if ($deleted > 0) {
            WP_to_CF_Logger::info('Cleaned up old ledger records', [
                'deleted_count' => $deleted,
                'cutoff_date' => $cutoff_date,
            ]);
        }

        return $deleted !== false ? $deleted : 0;
    }

    /**
     * 获取账本统计信息
     *
     * @return array 统计信息
     */
    public function get_stats(): array
    {
        global $wpdb;

        $total_files = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $total_size = $wpdb->get_var("SELECT SUM(file_size) FROM {$this->table_name}");
        $unique_posts = $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$this->table_name}");

        return [
            'total_files' => (int) $total_files,
            'total_size' => (int) $total_size,
            'unique_posts' => (int) $unique_posts,
            'avg_file_size' => $total_files > 0 ? round($total_size / $total_files, 2) : 0,
        ];
    }

    /**
     * 标记文件为 modified 状态
     * 
     * 当内容发生变更时调用，用于手动模式下的"脏状态"标记
     *
     * @param int    $post_id   文章 ID
     * @param string $file_path 文件路径
     * @return bool 是否成功
     */
    public function mark_as_modified(int $post_id, string $file_path): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            $this->table_name,
            ['sync_status' => 'modified'],
            ['file_path' => $file_path],
            ['%s'],
            ['%s']
        );

        if ($result === false) {
            WP_to_CF_Logger::error('Failed to mark file as modified', [
                'post_id' => $post_id,
                'file_path' => $file_path,
                'error' => $wpdb->last_error,
            ]);
            return false;
        }

        WP_to_CF_Logger::info('File marked as modified', [
            'post_id' => $post_id,
            'file_path' => $file_path,
        ]);

        return true;
    }

    /**
     * 获取所有 modified 状态的文件
     *
     * @return array 文件记录数组
     */
    public function get_modified_files(): array
    {
        global $wpdb;

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE sync_status = %s ORDER BY last_synced DESC",
            'modified'
        ), ARRAY_A);

        return $results !== null ? $results : [];
    }

    /**
     * 获取所有文件记录
     * 
     * 用于原子化批量部署，确保包含所有已发布的文件
     *
     * @return array 文件记录数组
     */
    public function get_all_files(): array
    {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} ORDER BY last_synced DESC",
            ARRAY_A
        );

        return $results !== null ? $results : [];
    }
}

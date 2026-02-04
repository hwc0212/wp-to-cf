<?php
/**
 * 部署队列管理类
 * 
 * 管理手动模式下的变更队列
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_Deployment_Queue
 * 
 * 处理手动模式下的文件变更记录和队列管理
 */
class WP_to_CF_Deployment_Queue
{
    /**
     * 记录变更到队列
     *
     * @param int         $post_id      文章 ID
     * @param string      $file_path    文件路径
     * @param string      $change_type  变更类型（create/update/delete）
     * @param string|null $content_hash 内容哈希（可选）
     * @return bool 是否成功
     */
    public function add_change(int $post_id, string $file_path, string $change_type, ?string $content_hash = null): bool
    {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wptocf_deployment_queue';
        
        // 验证变更类型
        if (!in_array($change_type, ['create', 'update', 'delete'], true)) {
            WP_to_CF_Logger::error('Invalid change type', [
                'change_type' => $change_type,
                'post_id' => $post_id,
                'file_path' => $file_path,
            ]);
            return false;
        }
        
        // 检查是否已存在相同文件的变更记录
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE file_path = %s",
            $file_path
        ));
        
        // 如果已存在，更新记录（保留最新的变更）
        if ($existing) {
            $result = $wpdb->update(
                $table,
                [
                    'post_id' => $post_id,
                    'change_type' => $change_type,
                    'content_hash' => $content_hash,
                    'created_at' => current_time('mysql'),
                ],
                ['id' => $existing],
                ['%d', '%s', '%s', '%s'],
                ['%d']
            );
            
            if ($result !== false) {
                WP_to_CF_Logger::info('Deployment queue updated', [
                    'id' => $existing,
                    'post_id' => $post_id,
                    'file_path' => $file_path,
                    'change_type' => $change_type,
                ]);
                return true;
            }
            
            return false;
        }
        
        // 插入新记录
        $result = $wpdb->insert(
            $table,
            [
                'post_id' => $post_id,
                'file_path' => $file_path,
                'change_type' => $change_type,
                'content_hash' => $content_hash,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );
        
        if ($result) {
            WP_to_CF_Logger::info('Change added to deployment queue', [
                'id' => $wpdb->insert_id,
                'post_id' => $post_id,
                'file_path' => $file_path,
                'change_type' => $change_type,
            ]);
            return true;
        }
        
        WP_to_CF_Logger::error('Failed to add change to deployment queue', [
            'post_id' => $post_id,
            'file_path' => $file_path,
            'change_type' => $change_type,
            'error' => $wpdb->last_error,
        ]);
        
        return false;
    }
    
    /**
     * 获取待部署文件列表
     *
     * @param int $limit  限制数量
     * @param int $offset 偏移量
     * @return array 待部署文件列表
     */
    public function get_pending_changes(int $limit = 100, int $offset = 0): array
    {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wptocf_deployment_queue';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $limit,
            $offset
        ), ARRAY_A);
        
        return $results ?: [];
    }
    
    /**
     * 获取待部署文件统计
     *
     * @return array 统计信息 ['create' => 5, 'update' => 10, 'delete' => 2, 'total' => 17]
     */
    public function get_pending_stats(): array
    {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wptocf_deployment_queue';
        
        $results = $wpdb->get_results(
            "SELECT change_type, COUNT(*) as count FROM {$table} GROUP BY change_type",
            ARRAY_A
        );
        
        $stats = [
            'create' => 0,
            'update' => 0,
            'delete' => 0,
            'total' => 0,
        ];
        
        foreach ($results as $row) {
            $stats[$row['change_type']] = (int) $row['count'];
            $stats['total'] += (int) $row['count'];
        }
        
        return $stats;
    }
    
    /**
     * 清空队列（部署完成后）
     *
     * @return bool 是否成功
     */
    public function clear_queue(): bool
    {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wptocf_deployment_queue';
        
        $result = $wpdb->query("TRUNCATE TABLE {$table}");
        
        if ($result !== false) {
            WP_to_CF_Logger::info('Deployment queue cleared');
            return true;
        }
        
        WP_to_CF_Logger::error('Failed to clear deployment queue', [
            'error' => $wpdb->last_error,
        ]);
        
        return false;
    }
    
    /**
     * 删除特定文件的变更记录
     *
     * @param int $post_id 文章 ID
     * @return bool 是否成功
     */
    public function remove_change(int $post_id): bool
    {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wptocf_deployment_queue';
        
        $result = $wpdb->delete(
            $table,
            ['post_id' => $post_id],
            ['%d']
        );
        
        if ($result !== false) {
            WP_to_CF_Logger::info('Change removed from deployment queue', [
                'post_id' => $post_id,
                'rows_affected' => $result,
            ]);
            return true;
        }
        
        return false;
    }
    
    /**
     * 获取队列中的所有文件路径
     *
     * @return array 文件路径数组
     */
    public function get_all_file_paths(): array
    {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wptocf_deployment_queue';
        
        $results = $wpdb->get_col("SELECT file_path FROM {$table}");
        
        return $results ?: [];
    }
}

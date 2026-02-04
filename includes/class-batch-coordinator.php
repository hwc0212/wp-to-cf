<?php
/**
 * 批次协调器类
 * 
 * 跟踪任务批次的完成状态，决定何时触发原子化部署
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_Batch_Coordinator
 * 
 * 管理任务批次和触发 final_dispatch
 */
class WP_to_CF_Batch_Coordinator
{
    /**
     * 批次表名
     *
     * @var string
     */
    private string $table_name;

    /**
     * 构造函数
     */
    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wptocf_batches';
    }

    /**
     * 创建新批次
     *
     * @param string $batch_id 批次 ID
     * @param array  $task_ids 任务 ID 数组
     * @return bool 是否成功
     */
    public function create_batch(string $batch_id, array $task_ids): bool
    {
        global $wpdb;
        
        $result = $wpdb->insert(
            $this->table_name,
            [
                'id' => $batch_id,
                'task_ids' => wp_json_encode($task_ids),
                'completed_tasks' => wp_json_encode([]),
                'status' => 'pending',
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );
        
        if ($result === false) {
            WP_to_CF_Logger::error('Failed to create batch', [
                'batch_id' => $batch_id,
                'task_count' => count($task_ids),
                'error' => $wpdb->last_error,
            ]);
            return false;
        }
        
        WP_to_CF_Logger::info('Batch created', [
            'batch_id' => $batch_id,
            'task_count' => count($task_ids),
        ]);
        
        return true;
    }

    /**
     * 标记任务完成
     *
     * @param string $batch_id 批次 ID
     * @param int    $task_id  任务 ID
     * @return void
     */
    public function mark_task_completed(string $batch_id, int $task_id): void
    {
        global $wpdb;
        
        // 获取批次信息
        $batch = $this->get_batch_info($batch_id);
        
        if (!$batch) {
            WP_to_CF_Logger::warning('Batch not found when marking task completed', [
                'batch_id' => $batch_id,
                'task_id' => $task_id,
            ]);
            return;
        }
        
        // 解析已完成任务
        $completed_tasks = json_decode($batch['completed_tasks'], true) ?: [];
        
        // 添加新完成的任务
        if (!in_array($task_id, $completed_tasks, true)) {
            $completed_tasks[] = $task_id;
        }
        
        // 更新数据库
        $wpdb->update(
            $this->table_name,
            [
                'completed_tasks' => wp_json_encode($completed_tasks),
            ],
            ['id' => $batch_id],
            ['%s'],
            ['%s']
        );
        
        WP_to_CF_Logger::info('Task marked as completed in batch', [
            'batch_id' => $batch_id,
            'task_id' => $task_id,
            'completed_count' => count($completed_tasks),
        ]);
    }

    /**
     * 检查批次是否全部完成
     *
     * @param string $batch_id 批次 ID
     * @return bool 是否完成
     */
    public function is_batch_complete(string $batch_id): bool
    {
        $batch = $this->get_batch_info($batch_id);
        
        if (!$batch) {
            return false;
        }
        
        $task_ids = json_decode($batch['task_ids'], true) ?: [];
        $completed_tasks = json_decode($batch['completed_tasks'], true) ?: [];
        
        // 检查是否所有任务都已完成
        $is_complete = count($task_ids) === count($completed_tasks);
        
        if ($is_complete) {
            WP_to_CF_Logger::info('Batch is complete', [
                'batch_id' => $batch_id,
                'total_tasks' => count($task_ids),
            ]);
        }
        
        return $is_complete;
    }

    /**
     * 获取批次信息
     *
     * @param string $batch_id 批次 ID
     * @return array|null 批次信息，如果不存在返回 null
     */
    public function get_batch_info(string $batch_id): ?array
    {
        global $wpdb;
        
        $batch = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE id = %s",
                $batch_id
            ),
            ARRAY_A
        );
        
        return $batch ?: null;
    }

    /**
     * 标记批次完成
     *
     * @param string $batch_id       批次 ID
     * @param string $deployment_id  部署 ID（可选）
     * @return bool 是否成功
     */
    public function mark_batch_completed(string $batch_id, string $deployment_id = ''): bool
    {
        global $wpdb;
        
        $result = $wpdb->update(
            $this->table_name,
            [
                'status' => 'completed',
                'deployment_id' => $deployment_id,
                'completed_at' => current_time('mysql'),
            ],
            ['id' => $batch_id],
            ['%s', '%s', '%s'],
            ['%s']
        );
        
        if ($result === false) {
            WP_to_CF_Logger::error('Failed to mark batch as completed', [
                'batch_id' => $batch_id,
                'error' => $wpdb->last_error,
            ]);
            return false;
        }
        
        WP_to_CF_Logger::info('Batch marked as completed', [
            'batch_id' => $batch_id,
            'deployment_id' => $deployment_id,
        ]);
        
        return true;
    }

    /**
     * 获取卡死的批次
     *
     * @param int $age_minutes 超过多少分钟视为卡死
     * @return array 批次数组
     */
    public function get_stuck_batches(int $age_minutes = 30): array
    {
        global $wpdb;
        
        $threshold = date('Y-m-d H:i:s', strtotime("-{$age_minutes} minutes"));
        
        $batches = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} 
                WHERE status = 'pending' 
                AND created_at < %s",
                $threshold
            ),
            ARRAY_A
        );
        
        return $batches ?: [];
    }

    /**
     * 清理旧批次记录
     *
     * @param int $days 保留多少天的记录
     * @return int 删除的记录数
     */
    public function cleanup_old_batches(int $days = 7): int
    {
        global $wpdb;
        
        $threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table_name} 
                WHERE status = 'completed' 
                AND completed_at < %s",
                $threshold
            )
        );
        
        if ($deleted > 0) {
            WP_to_CF_Logger::info('Old batches cleaned up', [
                'deleted_count' => $deleted,
                'older_than_days' => $days,
            ]);
        }
        
        return $deleted;
    }
}

<?php
/**
 * 任务队列管理类
 * 
 * 负责任务的增删改查和状态管理
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_Task_Queue
 * 
 * 管理 wp_wptocf_queue 表中的任务
 */
class WP_to_CF_Task_Queue
{
    /**
     * 任务状态：待处理
     */
    public const STATUS_PENDING = 'pending';

    /**
     * 任务状态：处理中
     */
    public const STATUS_PROCESSING = 'processing';

    /**
     * 任务状态：已完成
     */
    public const STATUS_COMPLETED = 'completed';

    /**
     * 任务状态：失败
     */
    public const STATUS_FAILED = 'failed';

    /**
     * 任务状态：永久失败
     */
    public const STATUS_PERMANENT_FAILED = 'permanent_failed';

    /**
     * 最大重试次数
     */
    public const MAX_RETRY_COUNT = 3;

    /**
     * 数据库表名
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
        $this->table_name = $wpdb->prefix . 'wptocf_queue';
        
        // 自动重置卡死的任务
        $this->reset_stuck_tasks();
    }

    /**
     * 重置卡死的任务
     * 
     * 将状态为 processing 且超过 10 分钟的任务重置为 pending
     * 这些任务可能因为 Fatal Error 或其他异常而卡死
     *
     * @return int 重置的任务数量
     */
    public function reset_stuck_tasks(): int
    {
        global $wpdb;

        // 查找超过 10 分钟仍在处理中的任务
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_name}
             SET status = %s,
                 updated_at = %s
             WHERE status = %s
             AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)",
            self::STATUS_PENDING,
            current_time('mysql'),
            self::STATUS_PROCESSING
        ));

        if ($result === false) {
            WP_to_CF_Logger::error('Failed to reset stuck tasks', [
                'error' => $wpdb->last_error,
            ]);
            return 0;
        }

        if ($result > 0) {
            WP_to_CF_Logger::warning('Reset stuck tasks', [
                'reset_count' => $result,
                'reason' => 'Tasks stuck in processing status for more than 10 minutes',
            ]);
        }

        return $result;
    }

    /**
     * 添加任务到队列
     *
     * @param int         $post_id   文章 ID
     * @param string      $task_type 任务类型（generate_html, delete_html, final_dispatch）
     * @param int         $priority  优先级（0=P0, 1=P1, 2=P2, 3=P3）
     * @param string|null $batch_id  批次 ID（可选）
     * @return int|false 任务 ID，失败返回 false
     */
    public function add_task(int $post_id, string $task_type, int $priority, ?string $batch_id = null): int|false
    {
        global $wpdb;

        // 检查是否已存在相同的待处理任务
        $existing_task = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} 
             WHERE post_id = %d 
             AND task_type = %s 
             AND status IN (%s, %s)
             LIMIT 1",
            $post_id,
            $task_type,
            self::STATUS_PENDING,
            self::STATUS_PROCESSING
        ));

        if ($existing_task) {
            WP_to_CF_Logger::info('Task already exists, skipping', [
                'post_id' => $post_id,
                'task_type' => $task_type,
                'existing_task_id' => $existing_task,
            ]);
            return (int) $existing_task;
        }

        // 插入新任务
        $result = $wpdb->insert(
            $this->table_name,
            [
                'post_id' => $post_id,
                'task_type' => $task_type,
                'batch_id' => $batch_id,
                'priority' => $priority,
                'status' => self::STATUS_PENDING,
                'retry_count' => 0,
                'error_message' => null,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            [
                '%d', // post_id
                '%s', // task_type
                '%s', // batch_id
                '%d', // priority
                '%s', // status
                '%d', // retry_count
                '%s', // error_message
                '%s', // created_at
                '%s', // updated_at
            ]
        );

        if ($result === false) {
            WP_to_CF_Logger::error('Failed to insert task', [
                'post_id' => $post_id,
                'task_type' => $task_type,
                'priority' => $priority,
                'batch_id' => $batch_id,
                'error' => $wpdb->last_error,
            ]);
            return false;
        }

        $task_id = $wpdb->insert_id;

        WP_to_CF_Logger::info('Task added to queue', [
            'task_id' => $task_id,
            'post_id' => $post_id,
            'task_type' => $task_type,
            'priority' => $priority,
            'batch_id' => $batch_id,
        ]);

        return $task_id;
    }

    /**
     * 获取待处理的任务
     * 
     * 按优先级（P0 > P1 > P2）和创建时间排序
     *
     * @param int $limit 返回数量限制
     * @return array 任务列表
     */
    public function get_pending_tasks(int $limit = 20): array
    {
        global $wpdb;

        $tasks = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name}
             WHERE status = %s
             ORDER BY priority ASC, created_at ASC
             LIMIT %d",
            self::STATUS_PENDING,
            $limit
        ), ARRAY_A);

        if ($tasks === null) {
            WP_to_CF_Logger::error('Failed to fetch pending tasks', [
                'error' => $wpdb->last_error,
            ]);
            return [];
        }

        WP_to_CF_Logger::info('Fetched pending tasks', [
            'count' => count($tasks),
            'limit' => $limit,
        ]);

        return $tasks;
    }

    /**
     * 更新任务状态
     *
     * @param int    $task_id       任务 ID
     * @param string $status        新状态
     * @param string $error_message 错误消息（可选）
     * @return bool 是否成功
     */
    public function update_task_status(int $task_id, string $status, string $error_message = ''): bool
    {
        global $wpdb;

        $data = [
            'status' => $status,
            'updated_at' => current_time('mysql'),
        ];

        $format = ['%s', '%s'];

        // 如果有错误消息，添加到更新数据中
        if (!empty($error_message)) {
            $data['error_message'] = $error_message;
            $format[] = '%s';
        }

        $result = $wpdb->update(
            $this->table_name,
            $data,
            ['id' => $task_id],
            $format,
            ['%d']
        );

        if ($result === false) {
            WP_to_CF_Logger::error('Failed to update task status', [
                'task_id' => $task_id,
                'status' => $status,
                'error' => $wpdb->last_error,
            ]);
            return false;
        }

        WP_to_CF_Logger::info('Task status updated', [
            'task_id' => $task_id,
            'status' => $status,
            'error_message' => $error_message,
        ]);

        return true;
    }

    /**
     * 增加任务重试次数
     *
     * @param int $task_id 任务 ID
     * @return bool 是否成功
     */
    public function increment_retry_count(int $task_id): bool
    {
        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_name}
             SET retry_count = retry_count + 1,
                 updated_at = %s
             WHERE id = %d",
            current_time('mysql'),
            $task_id
        ));

        if ($result === false) {
            WP_to_CF_Logger::error('Failed to increment retry count', [
                'task_id' => $task_id,
                'error' => $wpdb->last_error,
            ]);
            return false;
        }

        WP_to_CF_Logger::info('Task retry count incremented', [
            'task_id' => $task_id,
        ]);

        return true;
    }

    /**
     * 获取任务的重试次数
     *
     * @param int $task_id 任务 ID
     * @return int 重试次数，失败返回 -1
     */
    public function get_retry_count(int $task_id): int
    {
        global $wpdb;

        $retry_count = $wpdb->get_var($wpdb->prepare(
            "SELECT retry_count FROM {$this->table_name} WHERE id = %d",
            $task_id
        ));

        if ($retry_count === null) {
            WP_to_CF_Logger::error('Failed to get retry count', [
                'task_id' => $task_id,
                'error' => $wpdb->last_error,
            ]);
            return -1;
        }

        return (int) $retry_count;
    }

    /**
     * 处理任务失败
     * 
     * 如果重试次数 < 3，重新入队（状态改为 pending）
     * 如果重试次数 >= 3，标记为永久失败
     *
     * @param int    $task_id       任务 ID
     * @param string $error_message 错误消息
     * @return bool 是否成功
     */
    public function handle_task_failure(int $task_id, string $error_message): bool
    {
        // 增加重试次数
        if (!$this->increment_retry_count($task_id)) {
            return false;
        }

        // 获取当前重试次数
        $retry_count = $this->get_retry_count($task_id);

        if ($retry_count < 0) {
            return false;
        }

        // 判断是否超过最大重试次数
        if ($retry_count >= self::MAX_RETRY_COUNT) {
            // 标记为永久失败
            WP_to_CF_Logger::warning('Task permanently failed after max retries', [
                'task_id' => $task_id,
                'retry_count' => $retry_count,
                'error_message' => $error_message,
            ]);

            return $this->update_task_status(
                $task_id,
                self::STATUS_PERMANENT_FAILED,
                $error_message
            );
        } else {
            // 重新入队
            WP_to_CF_Logger::info('Task failed, re-queuing', [
                'task_id' => $task_id,
                'retry_count' => $retry_count,
                'error_message' => $error_message,
            ]);

            return $this->update_task_status(
                $task_id,
                self::STATUS_PENDING,
                $error_message
            );
        }
    }

    /**
     * 删除任务
     *
     * @param int $task_id 任务 ID
     * @return bool 是否成功
     */
    public function delete_task(int $task_id): bool
    {
        global $wpdb;

        $result = $wpdb->delete(
            $this->table_name,
            ['id' => $task_id],
            ['%d']
        );

        if ($result === false) {
            WP_to_CF_Logger::error('Failed to delete task', [
                'task_id' => $task_id,
                'error' => $wpdb->last_error,
            ]);
            return false;
        }

        WP_to_CF_Logger::info('Task deleted', [
            'task_id' => $task_id,
        ]);

        return true;
    }

    /**
     * 获取任务统计信息
     *
     * @return array 统计信息
     */
    public function get_statistics(): array
    {
        global $wpdb;

        $stats = $wpdb->get_results(
            "SELECT status, COUNT(*) as count
             FROM {$this->table_name}
             GROUP BY status",
            ARRAY_A
        );

        if ($stats === null) {
            WP_to_CF_Logger::error('Failed to get task statistics', [
                'error' => $wpdb->last_error,
            ]);
            return [];
        }

        $result = [];
        foreach ($stats as $stat) {
            $result[$stat['status']] = (int) $stat['count'];
        }

        return $result;
    }

    /**
     * 清理已完成的任务
     * 
     * 删除超过指定天数的已完成任务
     *
     * @param int $days 保留天数
     * @return int 删除的任务数量
     */
    public function cleanup_completed_tasks(int $days = 7): int
    {
        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name}
             WHERE status = %s
             AND updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            self::STATUS_COMPLETED,
            $days
        ));

        if ($result === false) {
            WP_to_CF_Logger::error('Failed to cleanup completed tasks', [
                'error' => $wpdb->last_error,
            ]);
            return 0;
        }

        WP_to_CF_Logger::info('Completed tasks cleaned up', [
            'deleted_count' => $result,
            'days' => $days,
        ]);

        return $result;
    }
}

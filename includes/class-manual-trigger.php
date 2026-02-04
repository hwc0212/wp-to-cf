<?php
/**
 * 手动触发管理类
 * 
 * 管理手动部署模式和触发逻辑
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_Manual_Trigger
 * 
 * 处理手动部署模式的切换和触发
 */
class WP_to_CF_Manual_Trigger
{
    /**
     * 部署队列实例
     *
     * @var WP_to_CF_Deployment_Queue
     */
    private WP_to_CF_Deployment_Queue $queue;
    
    /**
     * 任务队列实例
     *
     * @var WP_to_CF_Task_Queue
     */
    private WP_to_CF_Task_Queue $task_queue;
    
    /**
     * 构造函数
     *
     * @param WP_to_CF_Deployment_Queue $queue      部署队列实例
     * @param WP_to_CF_Task_Queue       $task_queue 任务队列实例
     */
    public function __construct(WP_to_CF_Deployment_Queue $queue, WP_to_CF_Task_Queue $task_queue)
    {
        $this->queue = $queue;
        $this->task_queue = $task_queue;
    }
    
    /**
     * 检查是否为手动模式
     *
     * @return bool 是否为手动模式
     */
    public function is_manual_mode(): bool
    {
        // 默认为 manual，除非明确设置为 auto
        $mode = get_option('wptocf_deployment_mode', 'manual');
        
        // 记录当前模式（用于调试）
        WP_to_CF_Logger::info('Checking deployment mode', [
            'mode' => $mode,
            'is_manual' => $mode === 'manual',
        ]);
        
        return $mode === 'manual';
    }
    
    /**
     * 切换部署模式
     *
     * @param string $mode 部署模式（auto/manual）
     * @return bool 是否成功
     */
    public function set_deployment_mode(string $mode): bool
    {
        // 验证模式
        if (!in_array($mode, ['auto', 'manual'], true)) {
            WP_to_CF_Logger::error('Invalid deployment mode', [
                'mode' => $mode,
            ]);
            return false;
        }
        
        $result = update_option('wptocf_deployment_mode', $mode);
        
        if ($result) {
            WP_to_CF_Logger::info('Deployment mode changed', [
                'mode' => $mode,
            ]);
        }
        
        return $result;
    }
    
    /**
     * 手动触发部署
     *
     * @return array 部署结果 ['success' => true, 'batch_id' => 'xxx', 'file_count' => 23]
     */
    public function trigger_deployment(): array
    {
        // 检查是否有待部署文件
        $stats = $this->queue->get_pending_stats();
        
        if ($stats['total'] === 0) {
            WP_to_CF_Logger::warning('No pending changes to deploy');
            return [
                'success' => false,
                'message' => 'No pending changes to deploy',
                'file_count' => 0,
            ];
        }
        
        // 创建批次
        $batch_id = $this->create_batch_from_queue();
        
        if (!$batch_id) {
            WP_to_CF_Logger::error('Failed to create batch from queue');
            return [
                'success' => false,
                'message' => 'Failed to create batch',
                'file_count' => 0,
            ];
        }
        
        // 清空部署队列
        $this->queue->clear_queue();
        
        // 触发任务调度器
        $scheduler = new WP_to_CF_Task_Scheduler($this->task_queue);
        $scheduler->schedule_next_batch(0); // 立即执行
        
        WP_to_CF_Logger::info('Manual deployment triggered', [
            'batch_id' => $batch_id,
            'file_count' => $stats['total'],
        ]);
        
        return [
            'success' => true,
            'batch_id' => $batch_id,
            'file_count' => $stats['total'],
            'message' => sprintf('Deployment started with %d files', $stats['total']),
        ];
    }
    
    /**
     * 从队列创建批次任务
     *
     * @return string|false 批次 ID 或 false
     */
    private function create_batch_from_queue()
    {
        // 获取所有待部署文件
        $changes = $this->queue->get_pending_changes(1000); // 最多 1000 个文件
        
        if (empty($changes)) {
            return false;
        }
        
        // 生成批次 ID
        $batch_id = 'manual_' . date('Ymd_His') . '_' . wp_rand(1000, 9999);
        
        $task_ids = [];
        
        // 为每个变更创建任务
        foreach ($changes as $change) {
            $task_type = $this->get_task_type_from_change($change['change_type']);
            
            $task_id = $this->task_queue->add_task(
                (int) $change['post_id'],
                $task_type,
                1, // P1 优先级
                $batch_id
            );
            
            if ($task_id) {
                $task_ids[] = $task_id;
            }
        }
        
        // 创建 final_dispatch 任务
        $final_task_id = $this->task_queue->add_task(
            0,
            'final_dispatch',
            3, // P3 优先级
            $batch_id
        );
        
        if ($final_task_id) {
            $task_ids[] = $final_task_id;
        }
        
        // 注册批次
        $coordinator = new WP_to_CF_Batch_Coordinator();
        $coordinator->create_batch($batch_id, $task_ids);
        
        WP_to_CF_Logger::info('Batch created from deployment queue', [
            'batch_id' => $batch_id,
            'task_count' => count($task_ids),
            'change_count' => count($changes),
        ]);
        
        return $batch_id;
    }
    
    /**
     * 根据变更类型获取任务类型
     *
     * @param string $change_type 变更类型（create/update/delete）
     * @return string 任务类型
     */
    private function get_task_type_from_change(string $change_type): string
    {
        switch ($change_type) {
            case 'create':
            case 'update':
                return 'generate_html';
            case 'delete':
                return 'delete_html';
            default:
                return 'generate_html';
        }
    }
}

<?php
/**
 * 内容监听器类
 * 
 * 监听 WordPress 内容变更事件，自动创建静态化任务
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_Content_Listener
 * 
 * 监听文章发布、更新、删除事件，并创建相应的任务队列
 */
class WP_to_CF_Content_Listener
{
    /**
     * 静态锁：防止同一请求重复触发
     *
     * @var bool
     */
    private static bool $already_processed = false;

    /**
     * 任务队列实例
     *
     * @var WP_to_CF_Task_Queue
     */
    private WP_to_CF_Task_Queue $queue;

    /**
     * 任务调度器实例
     *
     * @var WP_to_CF_Task_Scheduler
     */
    private WP_to_CF_Task_Scheduler $scheduler;

    /**
     * 部署队列实例
     *
     * @var WP_to_CF_Deployment_Queue|null
     */
    private ?WP_to_CF_Deployment_Queue $deployment_queue = null;

    /**
     * 手动触发实例
     *
     * @var WP_to_CF_Manual_Trigger|null
     */
    private ?WP_to_CF_Manual_Trigger $manual_trigger = null;

    /**
     * 构造函数
     *
     * @param WP_to_CF_Task_Queue     $queue     任务队列实例
     * @param WP_to_CF_Task_Scheduler $scheduler 任务调度器实例
     */
    public function __construct(WP_to_CF_Task_Queue $queue, WP_to_CF_Task_Scheduler $scheduler)
    {
        $this->queue = $queue;
        $this->scheduler = $scheduler;
        
        // 初始化部署队列和手动触发（延迟加载）
        $this->deployment_queue = new WP_to_CF_Deployment_Queue();
        $this->manual_trigger = new WP_to_CF_Manual_Trigger($this->deployment_queue, $queue);
    }

    /**
     * 注册 WordPress 钩子
     *
     * @return void
     */
    public function register_hooks(): void
    {
        // 监听文章状态变化（发布）
        add_action('transition_post_status', [$this, 'on_post_status_change'], 10, 3);
        
        // 监听文章删除
        add_action('before_delete_post', [$this, 'on_post_delete'], 10, 2);
    }

    /**
     * 处理文章状态变化事件
     * 
     * 当文章从任何状态变为 publish 时触发 P0 任务（实时）
     * 当已发布文章更新时触发 P1 任务（普通）
     *
     * @param string  $new_status 新状态
     * @param string  $old_status 旧状态
     * @param WP_Post $post       文章对象
     * @return void
     */
    public function on_post_status_change(string $new_status, string $old_status, WP_Post $post): void
    {
        // 静态锁：防止同一请求重复触发
        if (self::$already_processed) {
            WP_to_CF_Logger::info('Request already processed, skipping duplicate trigger', [
                'post_id' => $post->ID,
                'new_status' => $new_status,
                'old_status' => $old_status,
            ]);
            return;
        }
        
        // 设置锁并在请求结束时重置
        self::$already_processed = true;
        
        // 注册 shutdown 钩子以重置锁（防止影响后续请求）
        if (!has_action('shutdown', [__CLASS__, 'reset_lock'])) {
            add_action('shutdown', [__CLASS__, 'reset_lock']);
        }

        // 过滤修订版本和自动保存（防止重复触发）
        if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) {
            WP_to_CF_Logger::info('Ignoring revision or autosave', [
                'post_id' => $post->ID,
                'is_revision' => wp_is_post_revision($post->ID),
                'is_autosave' => wp_is_post_autosave($post->ID),
            ]);
            return;
        }

        // 只处理文章类型（post, page, product）
        $allowed_post_types = ['post', 'page'];
        
        // 如果 WooCommerce 激活，添加 product 支持
        if (class_exists('WooCommerce')) {
            $allowed_post_types[] = 'product';
        }
        
        if (!in_array($post->post_type, $allowed_post_types, true)) {
            WP_to_CF_Logger::info('Ignoring post type: ' . $post->post_type, [
                'post_id' => $post->ID,
                'post_type' => $post->post_type,
            ]);
            return;
        }

        // 情况 1: 文章从非 publish 状态变为 publish（新发布）
        if ($new_status === 'publish' && $old_status !== 'publish') {
            $this->handle_post_publish($post);
            return;
        }

        // 情况 2: 文章已经是 publish 状态，内容更新
        if ($new_status === 'publish' && $old_status === 'publish') {
            $this->handle_post_update($post);
            return;
        }

        // 情况 3: 文章从 publish 变为其他状态（取消发布）
        if ($old_status === 'publish' && $new_status !== 'publish') {
            $this->handle_post_unpublish($post);
            return;
        }
    }

    /**
     * 重置静态锁（在请求结束时调用）
     *
     * @return void
     */
    public static function reset_lock(): void
    {
        self::$already_processed = false;
        WP_to_CF_Logger::info('Request lock reset');
    }

    /**
     * 处理文章发布事件（P0 优先级 - 实时）
     *
     * @param WP_Post $post 文章对象
     * @return void
     */
    private function handle_post_publish(WP_Post $post): void
    {
        WP_to_CF_Logger::info('Post published', [
            'post_id' => $post->ID,
            'post_title' => $post->post_title,
            'post_type' => $post->post_type,
        ]);

        // 检查是否为手动模式
        if ($this->manual_trigger && $this->manual_trigger->is_manual_mode()) {
            WP_to_CF_Logger::info('Manual mode enabled, adding to deployment queue', [
                'post_id' => $post->ID,
            ]);
            
            // 手动模式：记录到部署队列
            $this->add_to_deployment_queue($post, 'create');
            return;
        }

        // 自动模式：创建批次任务
        WP_to_CF_Logger::info('Auto mode enabled, creating batch', [
            'post_id' => $post->ID,
        ]);

        // 1. 生成批次 ID
        $batch_id = $this->generate_batch_id();
        
        // 2. 收集所有任务 ID
        $task_ids = [];

        // 3. 创建文章 HTML 生成任务（P0）
        $task_id = $this->queue->add_task(
            $post->ID,
            'generate_html',
            0, // P0 优先级
            $batch_id
        );

        if ($task_id) {
            $task_ids[] = $task_id;
            WP_to_CF_Logger::info('P0 task created successfully', [
                'task_id' => $task_id,
                'post_id' => $post->ID,
                'batch_id' => $batch_id,
            ]);
        } else {
            WP_to_CF_Logger::error('Failed to create P0 task', [
                'post_id' => $post->ID,
            ]);
        }

        // 4. 创建特殊页面任务（P1 优先级）
        $special_task_ids = $this->create_special_page_tasks($post->ID, 1, $batch_id);
        $task_ids = array_merge($task_ids, $special_task_ids);

        // 5. 创建 final_dispatch 任务（P3 优先级 - 最低）
        $final_task_id = $this->queue->add_task(
            0, // post_id = 0 表示全局任务
            'final_dispatch',
            3, // P3 优先级
            $batch_id
        );
        
        if ($final_task_id) {
            $task_ids[] = $final_task_id;
            WP_to_CF_Logger::info('Final dispatch task created', [
                'task_id' => $final_task_id,
                'batch_id' => $batch_id,
            ]);
        }

        // 6. 注册批次
        $coordinator = new WP_to_CF_Batch_Coordinator();
        $coordinator->create_batch($batch_id, $task_ids);

        WP_to_CF_Logger::info('Batch created for post publish', [
            'batch_id' => $batch_id,
            'task_count' => count($task_ids),
        ]);

        // 7. 立即触发处理（不等待 WP-Cron）
        $this->scheduler->schedule_next_batch(0); // 0 秒延迟，立即执行
    }

    /**
     * 处理文章更新事件（P1 优先级 - 普通）
     *
     * @param WP_Post $post 文章对象
     * @return void
     */
    private function handle_post_update(WP_Post $post): void
    {
        WP_to_CF_Logger::info('Post updated', [
            'post_id' => $post->ID,
            'post_title' => $post->post_title,
            'post_type' => $post->post_type,
        ]);

        // 检查是否为手动模式
        if ($this->manual_trigger && $this->manual_trigger->is_manual_mode()) {
            WP_to_CF_Logger::info('Manual mode enabled, adding to deployment queue', [
                'post_id' => $post->ID,
            ]);
            
            // 手动模式：记录到部署队列
            $this->add_to_deployment_queue($post, 'update');
            return;
        }

        // 自动模式：创建批次任务
        WP_to_CF_Logger::info('Auto mode enabled, creating batch', [
            'post_id' => $post->ID,
        ]);

        // 1. 生成批次 ID
        $batch_id = $this->generate_batch_id();
        
        // 2. 收集所有任务 ID
        $task_ids = [];

        // 3. 创建文章 HTML 生成任务（P1）
        $task_id = $this->queue->add_task(
            $post->ID,
            'generate_html',
            1, // P1 优先级
            $batch_id
        );

        if ($task_id) {
            $task_ids[] = $task_id;
            WP_to_CF_Logger::info('P1 task created successfully', [
                'task_id' => $task_id,
                'post_id' => $post->ID,
                'batch_id' => $batch_id,
            ]);
        } else {
            WP_to_CF_Logger::error('Failed to create P1 task', [
                'post_id' => $post->ID,
            ]);
        }

        // 4. 创建特殊页面任务（P1 优先级）
        $special_task_ids = $this->create_special_page_tasks($post->ID, 1, $batch_id);
        $task_ids = array_merge($task_ids, $special_task_ids);

        // 5. 创建 final_dispatch 任务（P3 优先级 - 最低）
        $final_task_id = $this->queue->add_task(
            0, // post_id = 0 表示全局任务
            'final_dispatch',
            3, // P3 优先级
            $batch_id
        );
        
        if ($final_task_id) {
            $task_ids[] = $final_task_id;
            WP_to_CF_Logger::info('Final dispatch task created', [
                'task_id' => $final_task_id,
                'batch_id' => $batch_id,
            ]);
        }

        // 6. 注册批次
        $coordinator = new WP_to_CF_Batch_Coordinator();
        $coordinator->create_batch($batch_id, $task_ids);

        WP_to_CF_Logger::info('Batch created for post update', [
            'batch_id' => $batch_id,
            'task_count' => count($task_ids),
        ]);

        // 7. 调度处理（10 秒延迟）
        $this->scheduler->schedule_next_batch(10);
    }

    /**
     * 处理文章取消发布事件（P2 优先级 - 低优先级）
     *
     * @param WP_Post $post 文章对象
     * @return void
     */
    private function handle_post_unpublish(WP_Post $post): void
    {
        WP_to_CF_Logger::info('Post unpublished, creating P2 task', [
            'post_id' => $post->ID,
            'post_title' => $post->post_title,
            'post_type' => $post->post_type,
        ]);

        $task_id = $this->queue->add_task(
            $post->ID,
            'delete_html',
            2 // P2 优先级
        );

        if ($task_id) {
            WP_to_CF_Logger::info('P2 task created successfully', [
                'task_id' => $task_id,
                'post_id' => $post->ID,
            ]);
        } else {
            WP_to_CF_Logger::error('Failed to create P2 task', [
                'post_id' => $post->ID,
            ]);
        }
    }

    /**
     * 处理文章删除事件
     *
     * @param int     $post_id 文章 ID
     * @param WP_Post $post    文章对象
     * @return void
     */
    public function on_post_delete(int $post_id, WP_Post $post): void
    {
        // 只处理文章类型（post, page, product）
        $allowed_post_types = ['post', 'page'];
        
        // 如果 WooCommerce 激活，添加 product 支持
        if (class_exists('WooCommerce')) {
            $allowed_post_types[] = 'product';
        }
        
        if (!in_array($post->post_type, $allowed_post_types, true)) {
            return;
        }

        // 只处理已发布的文章
        if ($post->post_status !== 'publish') {
            return;
        }

        WP_to_CF_Logger::info('Post deleted, creating P2 task', [
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'post_type' => $post->post_type,
        ]);

        // 1. 创建文章删除任务（P2）
        $task_id = $this->queue->add_task(
            $post_id,
            'delete_html',
            2 // P2 优先级
        );

        if ($task_id) {
            WP_to_CF_Logger::info('P2 task created successfully', [
                'task_id' => $task_id,
                'post_id' => $post_id,
            ]);
        } else {
            WP_to_CF_Logger::error('Failed to create P2 task', [
                'post_id' => $post_id,
            ]);
        }

        // 2. 创建特殊页面任务（P2 优先级）
        $this->create_special_page_tasks($post_id, 2);
    }

    /**
     * 创建特殊页面任务
     * 
     * 为文章关联的首页、分类页、标签页创建静态化任务
     * 为产品关联的首页、产品分类页创建静态化任务（WooCommerce）
     *
     * @param int         $post_id  文章 ID
     * @param int         $priority 优先级（0=P0, 1=P1, 2=P2）
     * @param string|null $batch_id 批次 ID（可选）
     * @return array 创建的任务 ID 数组
     */
    private function create_special_page_tasks(int $post_id, int $priority, ?string $batch_id = null): array
    {
        WP_to_CF_Logger::info('Creating special page tasks', [
            'post_id' => $post_id,
            'priority' => $priority,
            'batch_id' => $batch_id,
        ]);

        $task_ids = [];
        $post = get_post($post_id);

        // 1. 创建首页任务
        $home_task_id = $this->queue->add_task(
            0, // 首页使用 post_id = 0
            'staticize_home',
            $priority,
            $batch_id
        );

        if ($home_task_id) {
            $task_ids[] = $home_task_id;
            WP_to_CF_Logger::info('Home page task created', [
                'task_id' => $home_task_id,
                'priority' => $priority,
                'batch_id' => $batch_id,
            ]);
        }

        // 2. 获取文章的分类和标签（或产品分类）
        $special_pages_manager = new WP_to_CF_Special_Pages_Manager();
        
        // 检查是否为 WooCommerce 产品
        if ($post && $post->post_type === 'product' && class_exists('WooCommerce')) {
            // 处理产品分类
            $product_categories = $special_pages_manager->get_product_categories($post_id);
            
            // 为每个产品分类创建任务
            foreach ($product_categories as $category) {
                $category_task_id = $this->queue->add_task(
                    $category->term_id,
                    'staticize_product_category',
                    $priority,
                    $batch_id
                );

                if ($category_task_id) {
                    $task_ids[] = $category_task_id;
                    WP_to_CF_Logger::info('Product category page task created', [
                        'task_id' => $category_task_id,
                        'category_id' => $category->term_id,
                        'category_name' => $category->name,
                        'priority' => $priority,
                        'batch_id' => $batch_id,
                    ]);
                }
            }
            
            WP_to_CF_Logger::info('Product special page tasks created', [
                'post_id' => $post_id,
                'product_categories_count' => count($product_categories),
                'total_tasks' => count($task_ids),
                'batch_id' => $batch_id,
            ]);
        } else {
            // 处理普通文章/页面的分类和标签
            $categories = $special_pages_manager->get_post_categories($post_id);
            $tags = $special_pages_manager->get_post_tags($post_id);

            // 3. 为每个分类创建任务
            foreach ($categories as $category) {
                $category_task_id = $this->queue->add_task(
                    $category->term_id,
                    'staticize_category',
                    $priority,
                    $batch_id
                );

                if ($category_task_id) {
                    $task_ids[] = $category_task_id;
                    WP_to_CF_Logger::info('Category page task created', [
                        'task_id' => $category_task_id,
                        'category_id' => $category->term_id,
                        'category_name' => $category->name,
                        'priority' => $priority,
                        'batch_id' => $batch_id,
                    ]);
                }
            }

            // 4. 为每个标签创建任务
            foreach ($tags as $tag) {
                $tag_task_id = $this->queue->add_task(
                    $tag->term_id,
                    'staticize_tag',
                    $priority,
                    $batch_id
                );

                if ($tag_task_id) {
                    $task_ids[] = $tag_task_id;
                    WP_to_CF_Logger::info('Tag page task created', [
                        'task_id' => $tag_task_id,
                        'tag_id' => $tag->term_id,
                        'tag_name' => $tag->name,
                        'priority' => $priority,
                        'batch_id' => $batch_id,
                    ]);
                }
            }

            WP_to_CF_Logger::info('Special page tasks created', [
                'post_id' => $post_id,
                'categories_count' => count($categories),
                'tags_count' => count($tags),
                'total_tasks' => count($task_ids),
                'batch_id' => $batch_id,
            ]);
        }

        return $task_ids;
    }

    /**
     * 生成批次 ID
     *
     * @return string 批次 ID
     */
    private function generate_batch_id(): string
    {
        return 'batch_' . date('Ymd_His') . '_' . wp_rand(1000, 9999);
    }

    /**
     * 添加变更到部署队列（手动模式）
     *
     * @param WP_Post $post        文章对象
     * @param string  $change_type 变更类型（create/update/delete）
     * @return void
     */
    private function add_to_deployment_queue(WP_Post $post, string $change_type): void
    {
        if (!$this->deployment_queue) {
            WP_to_CF_Logger::error('Deployment queue not initialized');
            return;
        }

        // 获取文章的文件路径
        $file_path = $this->get_post_file_path($post);
        
        // 添加到部署队列
        $result = $this->deployment_queue->add_change(
            $post->ID,
            $file_path,
            $change_type,
            null // content_hash 将在实际生成时计算
        );

        if ($result) {
            WP_to_CF_Logger::info('Change added to deployment queue', [
                'post_id' => $post->ID,
                'file_path' => $file_path,
                'change_type' => $change_type,
            ]);
        } else {
            WP_to_CF_Logger::error('Failed to add change to deployment queue', [
                'post_id' => $post->ID,
                'file_path' => $file_path,
                'change_type' => $change_type,
            ]);
        }
    }

    /**
     * 获取文章的文件路径
     *
     * @param WP_Post $post 文章对象
     * @return string 文件路径
     */
    private function get_post_file_path(WP_Post $post): string
    {
        // 获取文章的 slug
        $slug = $post->post_name;
        
        // 根据文章类型生成路径
        if ($post->post_type === 'page') {
            return '/' . $slug . '.html';
        }
        
        // 文章类型为 post
        return '/posts/' . $slug . '.html';
    }
}

<?php
/**
 * 任务调度器类
 * 
 * 负责异步处理任务队列，使用 WP-Cron 调度
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_Task_Scheduler
 * 
 * 管理任务的异步处理和调度
 */
class WP_to_CF_Task_Scheduler
{
    /**
     * 任务队列实例
     *
     * @var WP_to_CF_Task_Queue
     */
    private WP_to_CF_Task_Queue $queue;

    /**
     * 批次处理超时时间（秒）
     */
    private const BATCH_TIMEOUT = 25;

    /**
     * 每批次处理的最大任务数
     */
    private const BATCH_SIZE = 20;

    /**
     * WP-Cron 钩子名称
     */
    private const CRON_HOOK = 'wptocf_process_queue';

    /**
     * 构造函数
     *
     * @param WP_to_CF_Task_Queue $queue 任务队列实例
     */
    public function __construct(WP_to_CF_Task_Queue $queue)
    {
        $this->queue = $queue;
    }

    /**
     * 注册 WordPress 钩子
     *
     * @return void
     */
    public function register_hooks(): void
    {
        // 注册 WP-Cron 处理钩子（备用）
        add_action(self::CRON_HOOK, [$this, 'process_batch']);
        
        // 注册 REST API 端点（主要触发方式）
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * 注册 REST API 路由
     *
     * @return void
     */
    public function register_rest_routes(): void
    {
        register_rest_route('wptocf/v1', '/process-tasks', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_process_tasks'],
            'permission_callback' => '__return_true', // 公开访问（内部调用）
        ]);
    }

    /**
     * REST API 回调：处理任务
     *
     * @param WP_REST_Request $request 请求对象
     * @return WP_REST_Response 响应对象
     */
    public function rest_process_tasks(WP_REST_Request $request): WP_REST_Response
    {
        WP_to_CF_Logger::info('REST API triggered task processing');
        
        // 执行批次处理
        $this->process_batch();
        
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Task processing triggered',
        ], 200);
    }

    /**
     * 调度下一批次处理
     * 
     * 使用 Loopback 触发器绕过 WP-Cron 限制
     *
     * @param int $delay 延迟时间（秒），默认 10 秒
     * @return bool 是否成功调度
     */
    public function schedule_next_batch(int $delay = 10): bool
    {
        WP_to_CF_Logger::info('Manually triggering task processing due to potential Cron limitation', [
            'delay' => $delay,
            'method' => 'loopback_trigger',
        ]);

        // 如果延迟为 0，立即同步执行
        if ($delay === 0) {
            WP_to_CF_Logger::info('Immediate synchronous processing requested');
            $this->process_batch();
            return true;
        }

        // 否则使用异步 Loopback 触发器
        return $this->trigger_async_processing($delay);
    }

    /**
     * 使用 Loopback 触发器异步处理任务
     * 
     * 通过 wp_remote_post 调用 REST API 端点，绕过 WP-Cron
     *
     * @param int $delay 延迟时间（秒）
     * @return bool 是否成功触发
     */
    private function trigger_async_processing(int $delay = 0): bool
    {
        $rest_url = rest_url('wptocf/v1/process-tasks');

        WP_to_CF_Logger::info('Triggering async processing via loopback', [
            'rest_url' => $rest_url,
            'delay' => $delay,
        ]);

        // 如果有延迟，先等待
        if ($delay > 0) {
            // 使用 WP-Cron 作为备用（如果可用）
            $timestamp = time() + $delay;
            wp_schedule_single_event($timestamp, self::CRON_HOOK);
            
            WP_to_CF_Logger::info('Scheduled delayed processing', [
                'delay' => $delay,
                'timestamp' => $timestamp,
            ]);
        }

        // 立即发送异步请求（非阻塞）
        $response = wp_remote_post($rest_url, [
            'timeout' => 0.01, // 极短超时，立即返回（非阻塞）
            'blocking' => false, // 非阻塞模式
            'sslverify' => false, // 跳过 SSL 验证（本地回环）
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'trigger' => 'loopback',
                'timestamp' => time(),
            ]),
        ]);

        if (is_wp_error($response)) {
            WP_to_CF_Logger::warning('Loopback trigger failed, falling back to direct execution', [
                'error' => $response->get_error_message(),
            ]);
            
            // 如果 Loopback 失败，直接同步执行
            $this->process_batch();
            return true;
        }

        WP_to_CF_Logger::info('Loopback trigger sent successfully');
        return true;
    }

    /**
     * 处理一批任务
     * 
     * 从队列中获取待处理任务，逐个处理
     * 包含 25 秒超时保护机制
     *
     * @return void
     */
    public function process_batch(): void
    {
        $batch_start_time = microtime(true);

        WP_to_CF_Logger::info('Starting batch processing', [
            'start_time' => date('Y-m-d H:i:s'),
        ]);

        // 获取待处理任务
        $tasks = $this->queue->get_pending_tasks(self::BATCH_SIZE);

        if (empty($tasks)) {
            WP_to_CF_Logger::info('No pending tasks to process');
            return;
        }

        $processed_count = 0;
        $success_count = 0;
        $failed_count = 0;

        foreach ($tasks as $task) {
            // 检查是否超时
            $elapsed_time = microtime(true) - $batch_start_time;
            if ($elapsed_time >= self::BATCH_TIMEOUT) {
                WP_to_CF_Logger::warning('Batch processing timeout reached', [
                    'elapsed_time' => $elapsed_time,
                    'processed_count' => $processed_count,
                    'remaining_tasks' => count($tasks) - $processed_count,
                ]);

                // 调度下一批次
                $this->schedule_next_batch(5);
                break;
            }

            // 处理单个任务
            $result = $this->process_single_task($task);

            $processed_count++;
            if ($result) {
                $success_count++;
            } else {
                $failed_count++;
            }
        }

        $batch_end_time = microtime(true);
        $total_time = $batch_end_time - $batch_start_time;

        WP_to_CF_Logger::info('Batch processing completed', [
            'processed_count' => $processed_count,
            'success_count' => $success_count,
            'failed_count' => $failed_count,
            'total_time' => round($total_time, 2) . 's',
            'end_time' => date('Y-m-d H:i:s'),
        ]);

        // 如果还有待处理任务，调度下一批次
        if ($processed_count >= self::BATCH_SIZE) {
            $this->schedule_next_batch(10);
        }
    }

    /**
     * 处理单个任务
     * 
     * Phase 3: 实现实际的 HTML 生成、代码注入和 URL 转换
     * 新架构：保存到缓冲区而不是立即上传
     *
     * @param array $task 任务数据
     * @return bool 是否成功
     */
    private function process_single_task(array $task): bool
    {
        $task_id = (int) $task['id'];
        $post_id = (int) $task['post_id'];
        $task_type = $task['task_type'];
        $batch_id = $task['batch_id'] ?? null;

        WP_to_CF_Logger::info('Processing task', [
            'task_id' => $task_id,
            'post_id' => $post_id,
            'task_type' => $task_type,
            'priority' => $task['priority'],
            'retry_count' => $task['retry_count'],
            'batch_id' => $batch_id,
        ]);

        // 更新任务状态为 processing
        if (!$this->queue->update_task_status($task_id, WP_to_CF_Task_Queue::STATUS_PROCESSING)) {
            WP_to_CF_Logger::error('Failed to update task status to processing', [
                'task_id' => $task_id,
            ]);
            return false;
        }

        try {
            // 处理 final_dispatch 任务（原子化批量部署）
            if ($task_type === 'final_dispatch') {
                $this->process_final_dispatch_task($task);
            } 
            // 处理其他任务类型（保存到缓冲区）
            elseif ($task_type === 'generate_html') {
                $this->process_generate_html_task($post_id);
            } elseif ($task_type === 'delete_html') {
                $this->process_delete_html_task($post_id);
            } elseif ($task_type === 'staticize_home') {
                $this->process_staticize_home_task();
            } elseif ($task_type === 'staticize_category') {
                $this->process_staticize_category_task($post_id); // post_id 实际是 term_id
            } elseif ($task_type === 'staticize_tag') {
                $this->process_staticize_tag_task($post_id); // post_id 实际是 term_id
            } elseif ($task_type === 'staticize_product_category') {
                $this->process_staticize_product_category_task($post_id); // post_id 实际是 term_id
            } elseif ($task_type === 'staticize_all') {
                $this->process_staticize_all_task();
            } else {
                throw new Exception("Unknown task type: {$task_type}");
            }

            // 标记任务为已完成
            $this->queue->update_task_status($task_id, WP_to_CF_Task_Queue::STATUS_COMPLETED);

            // 如果有批次 ID，通知批次协调器
            if ($batch_id && $task_type !== 'final_dispatch') {
                $coordinator = new WP_to_CF_Batch_Coordinator();
                $coordinator->mark_task_completed($batch_id, $task_id);
            }

            WP_to_CF_Logger::info('Task completed successfully', [
                'task_id' => $task_id,
                'post_id' => $post_id,
                'task_type' => $task_type,
                'batch_id' => $batch_id,
            ]);

            return true;

        } catch (Exception $e) {
            // 处理失败
            $error_message = $e->getMessage();

            WP_to_CF_Logger::error('Task processing failed', [
                'task_id' => $task_id,
                'post_id' => $post_id,
                'error' => $error_message,
                'trace' => $e->getTraceAsString(),
            ]);

            // 处理任务失败（自动重试或标记为永久失败）
            $this->queue->handle_task_failure($task_id, $error_message);

            return false;
        }
    }

    /**
     * 处理 HTML 生成任务
     * 
     * 新架构：保存到缓冲区而不是立即上传
     *
     * @param int $post_id 文章 ID
     * @return void
     * @throws Exception 如果处理失败
     */
    private function process_generate_html_task(int $post_id): void
    {
        WP_to_CF_Logger::info('Starting HTML generation', [
            'post_id' => $post_id,
        ]);

        // 1. 生成 HTML
        $generator = new WP_to_CF_HTML_Generator();
        $html = $generator->generate_post_html($post_id);

        if ($html === false) {
            throw new Exception("Failed to generate HTML for post {$post_id}");
        }

        $original_html_size = strlen($html);

        // 1.5. 内容验证：检查最小字节数（防止上传空页面或错误页面）
        if ($original_html_size < 1000) {
            throw new Exception("Generated HTML too small ({$original_html_size} bytes), possible error for post {$post_id}");
        }

        // 2. 本地化远程资源（Asset Localizer）
        $localizer = new WP_to_CF_Asset_Localizer();
        $html = $localizer->localize_assets($html);

        // 3. 清理 WordPress 冗余代码（WordPress Debloat）
        $debloat = new WP_to_CF_WordPress_Debloat();
        $html_before_debloat = $html;
        $html = $debloat->debloat_html($html);

        // 4. 注入自定义代码
        $injector = new WP_to_CF_Code_Injector();
        $html = $injector->inject_code($html);

        // 5. 转换 URL
        $transformer = new WP_to_CF_URL_Transformer();
        $html = $transformer->transform_html($html);

        // 验证转换结果
        $validation = $transformer->validate_transformation($html);
        if (!$validation['transformation_complete']) {
            WP_to_CF_Logger::warning('URL transformation may be incomplete', $validation);
        }

        // 6. 计算 Hash
        $ledger = new WP_to_CF_Hash_Ledger();
        $content_hash = $ledger->calculate_hash($html);
        
        // 生成文件路径
        $post = get_post($post_id);
        $file_path = $this->generate_file_path($post);

        // 7. 保存到缓冲区（不上传）
        $buffer = new WP_to_CF_Deployment_Buffer();
        $buffer_saved = $buffer->save_file($file_path, $html);

        if (!$buffer_saved) {
            throw new Exception("Failed to save file to buffer: {$file_path}");
        }

        // 7.5. 保存到磁盘缓存（持久化）
        $cache = new WP_to_CF_HTML_Cache();
        $cache->save($file_path, $html, $content_hash);

        // 8. 更新账本
        $ledger_updated = $ledger->update_ledger($post_id, $file_path, $content_hash, strlen($html));

        if (!$ledger_updated) {
            WP_to_CF_Logger::warning('Failed to update ledger', [
                'post_id' => $post_id,
                'file_path' => $file_path,
            ]);
        }

        WP_to_CF_Logger::info('HTML generation completed and saved to buffer', [
            'post_id' => $post_id,
            'file_path' => $file_path,
            'original_html_size' => $original_html_size,
            'final_html_size' => strlen($html),
            'content_hash' => $content_hash,
            'localization_stats' => $localizer->get_localization_stats(),
            'debloat_stats' => $debloat->get_debloat_stats($html_before_debloat, $html),
            'transformation_stats' => $transformer->get_transformation_stats(),
        ]);
    }

    /**
     * 生成文件路径
     * 
     * 根据文章的 permalink 生成 Cloudflare Pages 路径
     * 路径必须与 WordPress permalink 结构一致
     *
     * @param WP_Post $post 文章对象
     * @return string 文件路径
     */
    private function generate_file_path(WP_Post $post): string
    {
        // 获取文章的 permalink
        $permalink = get_permalink($post->ID);
        
        // 解析 permalink，提取路径部分
        $parsed = parse_url($permalink);
        $path = $parsed['path'] ?? '/';
        
        // 移除开头的斜杠
        $path = ltrim($path, '/');
        
        // 如果路径为空或只是斜杠，使用 index.html
        if (empty($path) || $path === '/') {
            $file_path = 'index.html';
        } else {
            // 移除尾部斜杠
            $path = rtrim($path, '/');
            
            // 添加 index.html
            $file_path = $path . '/index.html';
        }

        WP_to_CF_Logger::info('Generated file path from permalink', [
            'post_id' => $post->ID,
            'permalink' => $permalink,
            'file_path' => $file_path,
        ]);

        return $file_path;
    }

    /**
     * 处理 HTML 删除任务
     *
     * @param int $post_id 文章 ID
     * @return void
     * @throws Exception 如果处理失败
     */
    private function process_delete_html_task(int $post_id): void
    {
        WP_to_CF_Logger::info('Starting HTML deletion', [
            'post_id' => $post_id,
        ]);

        // 1. 从 Cloudflare Pages 删除文件
        $api_client = new WP_to_CF_Cloudflare_API();
        
        if (!$api_client->is_configured()) {
            throw new Exception('Cloudflare API not configured');
        }

        // 获取文章信息生成文件路径
        $post = get_post($post_id);
        if (!$post) {
            throw new Exception("Post not found: {$post_id}");
        }

        $file_path = $this->generate_file_path($post);

        // 删除文件（注意：Cloudflare Pages 不支持直接删除单个文件）
        $delete_success = $api_client->delete_file($file_path);

        if (!$delete_success) {
            WP_to_CF_Logger::warning('Failed to delete file from Cloudflare Pages', [
                'post_id' => $post_id,
                'file_path' => $file_path,
            ]);
        }

        // 2. 从 Ledger 中删除记录
        $ledger = new WP_to_CF_Hash_Ledger();
        $ledger_deleted = $ledger->delete_by_post_id($post_id);

        if (!$ledger_deleted) {
            WP_to_CF_Logger::warning('Failed to delete ledger records', [
                'post_id' => $post_id,
            ]);
        }

        WP_to_CF_Logger::info('HTML deletion completed', [
            'post_id' => $post_id,
            'file_path' => $file_path,
        ]);
    }

    /**
     * 处理首页静态化任务
     * 
     * 新架构：保存到缓冲区而不是立即上传
     *
     * @return void
     * @throws Exception 如果处理失败
     */
    private function process_staticize_home_task(): void
    {
        WP_to_CF_Logger::info('Starting home page staticization');

        $special_pages_manager = new WP_to_CF_Special_Pages_Manager();
        $result = $special_pages_manager->staticize_home();

        if (!$result['success']) {
            throw new Exception($result['error'] ?? 'Failed to staticize home page');
        }

        // 保存到缓冲区（不上传）
        $buffer = new WP_to_CF_Deployment_Buffer();
        $buffer_saved = $buffer->save_file($result['file_path'], $result['content']);

        if (!$buffer_saved) {
            throw new Exception("Failed to save home page to buffer");
        }

        WP_to_CF_Logger::info('Home page staticization completed and saved to buffer', [
            'file_path' => $result['file_path'],
            'content_size' => strlen($result['content']),
        ]);
    }

    /**
     * 处理分类页静态化任务
     * 
     * 新架构：保存到缓冲区而不是立即上传
     *
     * @param int $term_id 分类 ID
     * @return void
     * @throws Exception 如果处理失败
     */
    private function process_staticize_category_task(int $term_id): void
    {
        WP_to_CF_Logger::info('Starting category page staticization', [
            'term_id' => $term_id,
        ]);

        // 获取分类对象
        $category = get_term($term_id, 'category');

        if (is_wp_error($category) || !$category) {
            throw new Exception("Category not found: {$term_id}");
        }

        $special_pages_manager = new WP_to_CF_Special_Pages_Manager();
        $result = $special_pages_manager->staticize_category($category);

        if (!$result['success']) {
            throw new Exception($result['error'] ?? 'Failed to staticize category page');
        }

        // 保存到缓冲区（不上传）
        $buffer = new WP_to_CF_Deployment_Buffer();
        $buffer_saved = $buffer->save_file($result['file_path'], $result['content']);

        if (!$buffer_saved) {
            throw new Exception("Failed to save category page to buffer: {$result['file_path']}");
        }

        WP_to_CF_Logger::info('Category page staticization completed and saved to buffer', [
            'term_id' => $term_id,
            'category_name' => $result['category_name'],
            'file_path' => $result['file_path'],
            'content_size' => strlen($result['content']),
        ]);
    }

    /**
     * 处理标签页静态化任务
     * 
     * 新架构：保存到缓冲区而不是立即上传
     *
     * @param int $term_id 标签 ID
     * @return void
     * @throws Exception 如果处理失败
     */
    private function process_staticize_tag_task(int $term_id): void
    {
        WP_to_CF_Logger::info('Starting tag page staticization', [
            'term_id' => $term_id,
        ]);

        // 获取标签对象
        $tag = get_term($term_id, 'post_tag');

        if (is_wp_error($tag) || !$tag) {
            throw new Exception("Tag not found: {$term_id}");
        }

        $special_pages_manager = new WP_to_CF_Special_Pages_Manager();
        $result = $special_pages_manager->staticize_tag($tag);

        if (!$result['success']) {
            throw new Exception($result['error'] ?? 'Failed to staticize tag page');
        }

        // 保存到缓冲区（不上传）
        $buffer = new WP_to_CF_Deployment_Buffer();
        $buffer_saved = $buffer->save_file($result['file_path'], $result['content']);

        if (!$buffer_saved) {
            throw new Exception("Failed to save tag page to buffer: {$result['file_path']}");
        }

        WP_to_CF_Logger::info('Tag page staticization completed and saved to buffer', [
            'term_id' => $term_id,
            'tag_name' => $result['tag_name'],
            'file_path' => $result['file_path'],
            'content_size' => strlen($result['content']),
        ]);
    }
    
    /**
     * 处理产品分类页静态化任务（WooCommerce）
     * 
     * 新架构：保存到缓冲区而不是立即上传
     *
     * @param int $term_id 产品分类 ID
     * @return void
     * @throws Exception 如果处理失败
     */
    private function process_staticize_product_category_task(int $term_id): void
    {
        WP_to_CF_Logger::info('Starting product category page staticization', [
            'term_id' => $term_id,
        ]);

        // 获取产品分类对象
        $product_cat = get_term($term_id, 'product_cat');

        if (is_wp_error($product_cat) || !$product_cat) {
            throw new Exception("Product category not found: {$term_id}");
        }

        $special_pages_manager = new WP_to_CF_Special_Pages_Manager();
        $result = $special_pages_manager->staticize_product_category($product_cat);

        if (!$result['success']) {
            throw new Exception($result['error'] ?? 'Failed to staticize product category page');
        }

        // 保存到缓冲区（不上传）
        $buffer = new WP_to_CF_Deployment_Buffer();
        $buffer_saved = $buffer->save_file($result['file_path'], $result['content']);

        if (!$buffer_saved) {
            throw new Exception("Failed to save product category page to buffer: {$result['file_path']}");
        }

        WP_to_CF_Logger::info('Product category page staticization completed and saved to buffer', [
            'term_id' => $term_id,
            'category_name' => $result['category_name'],
            'file_path' => $result['file_path'],
            'content_size' => strlen($result['content']),
        ]);
    }

    /**
     * 处理全站静态化任务
     * 
     * 一次性静态化所有已发布的文章、页面、产品、分类和标签
     *
     * @return void
     * @throws Exception 如果处理失败
     */
    private function process_staticize_all_task(): void
    {
        WP_to_CF_Logger::info('Starting full site staticization');

        $stats = [
            'posts_queued' => 0,
            'products_queued' => 0,
            'categories_queued' => 0,
            'product_categories_queued' => 0,
            'tags_queued' => 0,
            'home_queued' => 0,
        ];

        // 1. 静态化首页
        $home_task_id = $this->queue->add_task(0, 'staticize_home', 0);
        if ($home_task_id) {
            $stats['home_queued'] = 1;
        }

        // 2. 静态化所有已发布的文章和页面
        $post_types = ['post', 'page'];
        
        // 如果 WooCommerce 激活，添加 product 类型
        if (class_exists('WooCommerce')) {
            $post_types[] = 'product';
            WP_to_CF_Logger::info('WooCommerce detected, including products in staticization');
        }
        
        $posts = get_posts([
            'post_type' => $post_types,
            'post_status' => 'publish',
            'numberposts' => -1,
        ]);

        foreach ($posts as $post) {
            $task_id = $this->queue->add_task($post->ID, 'generate_html', 1);
            if ($task_id) {
                if ($post->post_type === 'product') {
                    $stats['products_queued']++;
                } else {
                    $stats['posts_queued']++;
                }
            }
        }

        // 3. 静态化所有分类页
        $categories = get_categories(['hide_empty' => false]);
        foreach ($categories as $category) {
            $task_id = $this->queue->add_task($category->term_id, 'staticize_category', 2);
            if ($task_id) {
                $stats['categories_queued']++;
            }
        }
        
        // 4. 静态化所有产品分类页（WooCommerce）
        if (class_exists('WooCommerce')) {
            $product_categories = get_terms([
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
            ]);
            
            if (!is_wp_error($product_categories)) {
                foreach ($product_categories as $product_cat) {
                    $task_id = $this->queue->add_task($product_cat->term_id, 'staticize_product_category', 2);
                    if ($task_id) {
                        $stats['product_categories_queued']++;
                    }
                }
            }
        }

        // 5. 静态化所有标签页
        $tags = get_tags(['hide_empty' => false]);
        foreach ($tags as $tag) {
            $task_id = $this->queue->add_task($tag->term_id, 'staticize_tag', 2);
            if ($task_id) {
                $stats['tags_queued']++;
            }
        }

        WP_to_CF_Logger::info('Full site staticization tasks queued', $stats);

        // 立即触发处理
        $this->schedule_next_batch(0);
    }

    /**
     * 处理 final_dispatch 任务（原子化批量部署）
     * 
     * 这是新架构的核心：收集缓冲区中的所有文件，确保包含完整站点，然后一次性部署
     *
     * @param array $task 任务数据
     * @return void
     * @throws Exception 如果处理失败
     */
    private function process_final_dispatch_task(array $task): void
    {
        $batch_id = $task['batch_id'] ?? null;
        
        WP_to_CF_Logger::info('Starting final dispatch (atomic batch deployment)', [
            'batch_id' => $batch_id,
        ]);

        // 1. 收集缓冲区中的所有文件
        $buffer = new WP_to_CF_Deployment_Buffer();
        $files = $buffer->get_all_files();

        WP_to_CF_Logger::info('Collected files from buffer', [
            'file_count' => count($files),
            'batch_id' => $batch_id,
        ]);

        // 2. 确保包含必需文件（从账本读取所有已发布内容）
        $files = $this->ensure_required_files($files);

        WP_to_CF_Logger::info('Ensured required files', [
            'total_file_count' => count($files),
            'batch_id' => $batch_id,
        ]);

        // 3. 生成 _redirects 文件
        $files['_redirects'] = $this->generate_comprehensive_redirects();

        WP_to_CF_Logger::info('Generated _redirects file', [
            'redirects_size' => strlen($files['_redirects']),
            'batch_id' => $batch_id,
        ]);

        // 4. 一次性批量部署到 Cloudflare Pages
        $api_client = new WP_to_CF_Cloudflare_API();
        
        if (!$api_client->is_configured()) {
            throw new Exception('Cloudflare API not configured');
        }

        $deployment_id = $api_client->create_deployment($files);

        if (!$deployment_id) {
            throw new Exception('Failed to create Cloudflare Pages deployment');
        }

        WP_to_CF_Logger::info('Atomic batch deployment completed', [
            'deployment_id' => $deployment_id,
            'file_count' => count($files),
            'batch_id' => $batch_id,
        ]);

        // 5. 清空缓冲区
        $buffer->clear();

        // 6. 标记批次完成
        if ($batch_id) {
            $coordinator = new WP_to_CF_Batch_Coordinator();
            $coordinator->mark_batch_completed($batch_id, $deployment_id);
        }

        WP_to_CF_Logger::info('Final dispatch completed successfully', [
            'batch_id' => $batch_id,
            'deployment_id' => $deployment_id,
        ]);
    }

    /**
     * 确保包含所有必需文件
     * 
     * 从账本读取所有已发布的文件，优先使用磁盘缓存，避免重复生成
     * 确保 index.html 始终排在第一位
     *
     * @param array $files 当前文件数组
     * @return array 补全后的文件数组
     */
    private function ensure_required_files(array $files): array
    {
        $ledger = new WP_to_CF_Hash_Ledger();
        
        // 获取账本中的所有文件
        $ledger_files = $ledger->get_all_files();

        WP_to_CF_Logger::info('Checking ledger for required files', [
            'ledger_file_count' => count($ledger_files),
            'buffer_file_count' => count($files),
        ]);

        $cache = new WP_to_CF_HTML_Cache();
        $added_from_cache = 0;
        $regenerated = 0;

        foreach ($ledger_files as $ledger_entry) {
            $file_path = $ledger_entry['file_path'];
            
            // 如果文件已在缓冲区中，跳过
            if (isset($files[$file_path])) {
                continue;
            }
            
            $content_hash = $ledger_entry['content_hash'];
            
            // 优先从磁盘缓存读取
            $html = $cache->get($file_path, $content_hash);
            
            if ($html !== null) {
                // 缓存命中！直接使用
                $files[$file_path] = $html;
                $added_from_cache++;
                
                WP_to_CF_Logger::info('Added file from disk cache', [
                    'file_path' => $file_path,
                    'hash' => $content_hash,
                ]);
                continue;
            }
            
            // 缓存未命中，需要重新生成
            try {
                $post_id = $ledger_entry['post_id'];
                
                if ($post_id > 0) {
                    $generator = new WP_to_CF_HTML_Generator();
                    $html = $generator->generate_post_html($post_id);
                    
                    if ($html !== false) {
                        $files[$file_path] = $html;
                        $regenerated++;
                        
                        // 保存到缓存供下次使用
                        $cache->save($file_path, $html, $content_hash);
                        
                        WP_to_CF_Logger::info('Regenerated and cached missing file', [
                            'file_path' => $file_path,
                            'post_id' => $post_id,
                        ]);
                    }
                }
            } catch (Exception $e) {
                WP_to_CF_Logger::warning('Failed to regenerate file from ledger', [
                    'file_path' => $file_path,
                    'post_id' => $ledger_entry['post_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 确保 index.html 始终排在第一位（首页优先）
        if (isset($files['index.html'])) {
            $index_content = $files['index.html'];
            unset($files['index.html']);
            $files = array_merge(['index.html' => $index_content], $files);
            
            WP_to_CF_Logger::info('Prioritized index.html to first position');
        }

        WP_to_CF_Logger::info('Required files ensured', [
            'added_from_cache' => $added_from_cache,
            'regenerated' => $regenerated,
            'total_count' => count($files),
        ]);

        return $files;
    }

    /**
     * 生成综合重定向规则
     * 
     * 扫描所有已发布的文章、分类、标签，生成完整的 _redirects 文件
     * 避免生成错误的 404 跳转规则
     *
     * @return string _redirects 文件内容
     */
    private function generate_comprehensive_redirects(): string
    {
        $redirects = [];
        
        // 添加头部注释
        $redirects[] = '# WordPress to Cloudflare Pages - Redirects';
        $redirects[] = '# Generated: ' . date('Y-m-d H:i:s');
        $redirects[] = '';

        // 1. 扫描所有已发布的文章
        $posts = get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'numberposts' => -1,
        ]);

        foreach ($posts as $post) {
            $permalink = get_permalink($post->ID);
            $parsed = parse_url($permalink);
            $path = $parsed['path'] ?? '/';
            $path = rtrim($path, '/');
            
            if (!empty($path) && $path !== '/') {
                // 只添加有效的重定向规则（200 状态码表示重写，不是重定向）
                $redirects[] = "{$path} {$path}/index.html 200";
                $redirects[] = "{$path}/ {$path}/index.html 200";
            }
        }

        // 2. 扫描所有分类
        $categories = get_categories(['hide_empty' => false]);
        foreach ($categories as $category) {
            $category_link = get_category_link($category->term_id);
            $parsed = parse_url($category_link);
            $path = $parsed['path'] ?? '';
            $path = rtrim($path, '/');
            
            if (!empty($path)) {
                $redirects[] = "{$path} {$path}/index.html 200";
                $redirects[] = "{$path}/ {$path}/index.html 200";
            }
        }

        // 3. 扫描所有标签
        $tags = get_tags(['hide_empty' => false]);
        foreach ($tags as $tag) {
            $tag_link = get_tag_link($tag->term_id);
            $parsed = parse_url($tag_link);
            $path = $parsed['path'] ?? '';
            $path = rtrim($path, '/');
            
            if (!empty($path)) {
                $redirects[] = "{$path} {$path}/index.html 200";
                $redirects[] = "{$path}/ {$path}/index.html 200";
            }
        }

        // 4. 添加自定义重定向规则（从设置读取）
        $custom_redirects = get_option('wptocf_custom_redirects', '');
        if (!empty($custom_redirects)) {
            $redirects[] = '';
            $redirects[] = '# Custom Redirects';
            $redirects[] = trim($custom_redirects);
        }

        // 5. 添加全局 SPA 回退规则（防止 404）
        // 注意：这个规则应该放在最后，作为兜底
        $redirects[] = '';
        $redirects[] = '# Global Fallback (prevents 404 errors)';
        $redirects[] = '/* /index.html 200';

        // 6. 按路径长度排序（最具体的规则优先）
        // 注意：跳过头部注释和全局回退规则
        $header_lines = array_slice($redirects, 0, 3);
        $redirect_lines = array_slice($redirects, 3, -3); // 排除头部和回退规则
        $fallback_lines = array_slice($redirects, -3); // 最后3行（回退规则）
        
        // 去重并排序
        $redirect_lines = array_unique($redirect_lines);
        usort($redirect_lines, function($a, $b) {
            // 跳过注释行和空行
            if (empty(trim($a)) || strpos(trim($a), '#') === 0) return 1;
            if (empty(trim($b)) || strpos(trim($b), '#') === 0) return -1;
            return strlen($b) - strlen($a);
        });

        $final_redirects = array_merge($header_lines, $redirect_lines, $fallback_lines);

        WP_to_CF_Logger::info('Generated comprehensive redirects', [
            'redirect_count' => count($redirect_lines),
            'post_count' => count($posts),
            'category_count' => count($categories),
            'tag_count' => count($tags),
        ]);

        return implode("\n", $final_redirects);
    }

    /**
     * 立即触发批次处理
     * 
     * 绕过 WP-Cron，直接同步执行处理
     * 用于测试和紧急处理
     *
     * @return void
     */
    public function trigger_immediate_processing(): void
    {
        WP_to_CF_Logger::info('Manually triggering immediate batch processing (synchronous)');
        $this->process_batch();
    }

    /**
     * 取消所有已调度的批次处理
     *
     * @return bool 是否成功
     */
    public function cancel_scheduled_batches(): bool
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);

        if (!$timestamp) {
            WP_to_CF_Logger::info('No scheduled batches to cancel');
            return true;
        }

        $result = wp_unschedule_event($timestamp, self::CRON_HOOK);

        if ($result) {
            WP_to_CF_Logger::info('Scheduled batch cancelled', [
                'timestamp' => $timestamp,
                'scheduled_time' => date('Y-m-d H:i:s', $timestamp),
            ]);
        } else {
            WP_to_CF_Logger::error('Failed to cancel scheduled batch', [
                'timestamp' => $timestamp,
            ]);
        }

        return $result;
    }

    /**
     * 获取下一次调度时间
     *
     * @return int|false 时间戳，如果没有调度返回 false
     */
    public function get_next_scheduled_time(): int|false
    {
        return wp_next_scheduled(self::CRON_HOOK);
    }
}

<?php
/**
 * 提交同步类
 * 
 * 从第三方表单服务拉取提交数据，同步到 WordPress
 * 支持：表单提交、评论
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_to_CF_Submission_Sync
{
    private string $table_name;
    
    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wptocf_submissions';
    }
    
    /**
     * 初始化
     */
    public function init(): void
    {
        $this->maybe_create_table();
        
        // 注册定时任务
        add_action('wptocf_sync_submissions', [$this, 'sync_all']);
        
        // 激活时设置定时任务
        if (!wp_next_scheduled('wptocf_sync_submissions')) {
            wp_schedule_event(time(), 'hourly', 'wptocf_sync_submissions');
        }
    }
    
    /**
     * 创建提交记录表
     */
    public function maybe_create_table(): void
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            external_id varchar(255) NOT NULL,
            form_id varchar(255) NOT NULL,
            submission_type varchar(50) DEFAULT 'form',
            post_id bigint(20) UNSIGNED DEFAULT 0,
            data longtext NOT NULL,
            status varchar(20) DEFAULT 'pending',
            synced_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_external_id (external_id),
            KEY idx_form_id (form_id),
            KEY idx_status (status)
        ) {$charset_collate};";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
    
    /**
     * 同步所有配置的表单
     */
    public function sync_all(): array
    {
        $results = ['synced' => 0, 'errors' => []];

        // 0. 从本站 Worker 后端拉取（新架构：D1 暂存 → WordPress 出站拉取）
        if (get_option('wptocf_worker_backend_enabled', '0') === '1') {
            $worker_result = $this->sync_worker();
            $results['synced'] += $worker_result['synced'];
            if (!empty($worker_result['error'])) {
                $results['errors'][] = $worker_result['error'];
            }
        }

        // 1. 同步评论表单（从评论同步配置）
        $comment_result = $this->sync_comment_form();
        $results['synced'] += $comment_result['synced'];
        if (!empty($comment_result['error'])) {
            $results['errors'][] = $comment_result['error'];
        }
        
        // 2. 同步其他表单（从表单配置）
        $form_admin = new WP_to_CF_Form_Mapping_Admin();
        $mappings = $form_admin->get_enabled_mappings();
        
        foreach ($mappings as $mapping) {
            $service_type = $mapping['service_type'] ?? '';
            
            // Getform/Forminit 同步
            if ($service_type === 'getform') {
                $api_token = get_option('wptocf_getform_api_token', '');
                if (empty($api_token)) {
                    $results['errors'][] = 'Getform API Token 未配置（表单配置页面）';
                    continue;
                }
                
                $form_id = $this->extract_getform_id($mapping['service_endpoint']);
                if (!$form_id) {
                    continue;
                }
                
                $sync_result = $this->sync_getform($form_id, $mapping, $api_token);
                $results['synced'] += $sync_result['synced'];
                if (!empty($sync_result['error'])) {
                    $results['errors'][] = $sync_result['error'];
                }
            }
            // form.huwencai.com 同步
            elseif ($service_type === 'cfform') {
                $api_key = get_option('wptocf_cfform_api_key', '');
                if (empty($api_key)) {
                    $results['errors'][] = 'form.huwencai.com API Key 未配置（评论同步页面）';
                    continue;
                }
                
                $sync_result = $this->sync_cfform($mapping, $api_key);
                $results['synced'] += $sync_result['synced'];
                if (!empty($sync_result['error'])) {
                    $results['errors'][] = $sync_result['error'];
                }
            }
        }
        
        // 处理待处理的提交
        $this->process_pending_submissions();
        
        WP_to_CF_Logger::info('同步完成', $results);
        
        return $results;
    }
    
    /**
     * 同步评论表单（从评论同步配置）
     */
    private function sync_comment_form(): array
    {
        $result = ['synced' => 0, 'error' => ''];
        
        $service = get_option('wptocf_comment_service', '');
        $endpoint = get_option('wptocf_comment_endpoint', '');
        
        if (empty($service) || empty($endpoint)) {
            return $result; // 未配置，跳过
        }
        
        $mapping = [
            'form_id' => 'commentform',
            'form_name' => '评论表单',
            'service_type' => $service,
            'service_endpoint' => $endpoint,
        ];
        
        if ($service === 'getform') {
            $api_token = get_option('wptocf_getform_api_token', '');
            if (empty($api_token)) {
                $result['error'] = '评论同步: Getform API Token 未配置';
                return $result;
            }
            
            $form_id = $this->extract_getform_id($endpoint);
            if (!$form_id) {
                $result['error'] = '评论同步: 无法从端点提取 Getform ID';
                return $result;
            }
            
            return $this->sync_getform($form_id, $mapping, $api_token);
        }
        
        if ($service === 'cfform') {
            $api_key = get_option('wptocf_cfform_api_key', '');
            if (empty($api_key)) {
                $result['error'] = '评论同步: form.huwencai.com API Key 未配置';
                return $result;
            }
            
            return $this->sync_cfform($mapping, $api_key);
        }
        
        return $result;
    }
    
    /**
     * 从本站 Worker 后端拉取提交（表单/评论）
     *
     * 流程：GET /__wptocf/pull（Bearer 密钥）→ 存入本地表 → POST /__wptocf/ack 回执
     */
    private function sync_worker(): array
    {
        $result = ['synced' => 0, 'error' => ''];

        $base = $this->get_worker_base_url();
        if (empty($base)) {
            $result['error'] = 'Worker 拉取: 未设置 Worker 访问地址或生产域名';
            return $result;
        }

        $secret = $this->get_pull_secret();
        if (empty($secret)) {
            $result['error'] = 'Worker 拉取: 未生成拉取密钥（请在 Worker 后端保存设置）';
            return $result;
        }

        $response = wp_remote_get($base . '/__wptocf/pull?limit=100', [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $secret],
        ]);

        if (is_wp_error($response)) {
            $result['error'] = 'Worker 拉取失败: ' . $response->get_error_message();
            return $result;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $result['error'] = "Worker 拉取失败: HTTP {$code}";
            WP_to_CF_Logger::error('Worker pull error', ['code' => $code, 'body' => wp_remote_retrieve_body($response)]);
            return $result;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $items = $body['items'] ?? [];
        $ack_ids = [];

        foreach ($items as $item) {
            $external_id = $item['id'] ?? '';
            if (empty($external_id)) {
                continue;
            }
            // 无论是否已存在，都回执以推进 Worker 侧游标
            $ack_ids[] = $external_id;

            if ($this->is_synced($external_id)) {
                continue;
            }

            $data = is_array($item['data'] ?? null) ? $item['data'] : [];
            // 附带 Worker 侧邮件发送状态，便于失败后人工补救
            if (!empty($item['email'])) {
                $data['_wptocf_email'] = $item['email'];
            }

            $type = ($item['type'] ?? 'form') === 'comment' ? 'comment' : 'form';
            $post_id = intval($item['post_id'] ?? 0);
            if (!$post_id) {
                $post_id = $this->extract_post_id($data);
            }

            // ISO8601 → MySQL datetime
            $created = $item['created_at'] ?? '';
            $ts = $created ? strtotime($created) : false;
            $created_at = $ts ? gmdate('Y-m-d H:i:s', $ts) : current_time('mysql');

            $this->save_submission([
                'external_id' => $external_id,
                'form_id' => $item['form_id'] ?? 'default',
                'submission_type' => $type,
                'post_id' => $post_id,
                'data' => $data,
                'created_at' => $created_at,
            ]);

            $result['synced']++;
        }

        // 回执，标记 Worker 侧已消费
        if (!empty($ack_ids)) {
            $ack = wp_remote_post($base . '/__wptocf/ack', [
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . $secret,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode(['ids' => $ack_ids]),
            ]);
            if (is_wp_error($ack)) {
                WP_to_CF_Logger::warning('Worker ack failed', ['error' => $ack->get_error_message()]);
            }
        }

        return $result;
    }

    /**
     * 获取 Worker 访问基础 URL（优先显式设置，否则回退生产域名）
     */
    private function get_worker_base_url(): string
    {
        $base = trim((string) get_option('wptocf_worker_base_url', ''));
        if (empty($base)) {
            $domain = trim((string) get_option('wptocf_production_domain', ''));
            if (!empty($domain)) {
                $base = 'https://' . preg_replace('#^https?://#', '', $domain);
            }
        }
        return rtrim($base, '/');
    }

    /**
     * 获取解密后的拉取密钥
     */
    private function get_pull_secret(): string
    {
        $encrypted = get_option('wptocf_worker_pull_secret', '');
        if (empty($encrypted)) {
            return '';
        }
        $decrypted = WP_to_CF_Crypto::decrypt($encrypted);
        return $decrypted !== false ? $decrypted : '';
    }

    /**
     * 同步 Getform/Forminit 表单
     * 
     * 使用 Forminit API (原 Getform)
     * 文档: https://docs.getform.io/features/getform-api/api-authentication/
     */
    public function sync_getform(string $getform_id, array $mapping, string $api_token): array
    {
        $result = ['synced' => 0, 'error' => ''];
        
        $last_sync = get_option("wptocf_last_sync_{$getform_id}", '');
        
        // 调用 Forminit API (原 Getform，已更名)
        // 端点: GET https://api.forminit.com/v1/forms/{formId}
        // 认证: X-API-Key header
        $response = wp_remote_get(
            "https://api.forminit.com/v1/forms/{$getform_id}",
            [
                'headers' => [
                    'X-API-Key' => $api_token,
                    'Accept' => 'application/json',
                ],
                'timeout' => 30,
            ]
        );
        
        if (is_wp_error($response)) {
            $result['error'] = "获取 {$mapping['form_name']} 失败: " . $response->get_error_message();
            return $result;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $body = wp_remote_retrieve_body($response);
            $result['error'] = "获取 {$mapping['form_name']} 失败: HTTP {$code}";
            WP_to_CF_Logger::error('Forminit API 错误', ['code' => $code, 'body' => $body]);
            return $result;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $submissions = $body['data']['submissions'] ?? [];
        
        foreach ($submissions as $submission) {
            $external_id = $submission['id'] ?? '';
            if (empty($external_id)) {
                continue;
            }
            
            // 检查是否已同步
            if ($this->is_synced($external_id)) {
                continue;
            }
            
            // 确定提交类型
            $submission_type = $this->determine_submission_type($submission, $mapping);
            
            // 从 blocks.sender 或直接字段提取数据
            $submission_data = $submission['blocks'] ?? $submission;
            $post_id = $this->extract_post_id($submission_data);
            
            // 保存到本地表
            $this->save_submission([
                'external_id' => $external_id,
                'form_id' => $mapping['form_id'],
                'submission_type' => $submission_type,
                'post_id' => $post_id,
                'data' => $submission_data,
                'created_at' => $submission['submissionDate'] ?? current_time('mysql'),
            ]);
            
            $result['synced']++;
        }
        
        // 更新最后同步时间
        update_option("wptocf_last_sync_{$getform_id}", current_time('mysql'));
        
        return $result;
    }
    
    /**
     * 从 Getform endpoint 提取 form ID
     */
    private function extract_getform_id(string $endpoint): string
    {
        // https://getform.io/f/xxxxx
        if (preg_match('#getform\.io/f/([a-zA-Z0-9-]+)#', $endpoint, $matches)) {
            return $matches[1];
        }
        return '';
    }
    
    /**
     * 同步 form.huwencai.com
     * 
     * API: GET {endpoint}/api/forms/{form_id}/submissions
     * 认证: X-API-Key header
     */
    public function sync_cfform(array $mapping, string $api_key): array
    {
        $result = ['synced' => 0, 'error' => ''];
        
        $endpoint = rtrim($mapping['service_endpoint'], '/');
        $form_id = $this->extract_cfform_id($endpoint);
        
        if (!$form_id) {
            $result['error'] = "无法从端点提取表单 ID: {$endpoint}";
            return $result;
        }
        
        // 获取 API 基础 URL
        $base_url = $this->extract_cfform_base_url($endpoint);
        if (!$base_url) {
            $result['error'] = "无法解析 API 基础 URL: {$endpoint}";
            return $result;
        }
        
        // 调用 form.huwencai.com API
        $response = wp_remote_get(
            "{$base_url}/api/forms/{$form_id}/submissions",
            [
                'headers' => [
                    'X-API-Key' => $api_key,
                    'Accept' => 'application/json',
                ],
                'timeout' => 30,
            ]
        );
        
        if (is_wp_error($response)) {
            $result['error'] = "获取 {$mapping['form_name']} 失败: " . $response->get_error_message();
            return $result;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $body = wp_remote_retrieve_body($response);
            $result['error'] = "获取 {$mapping['form_name']} 失败: HTTP {$code}";
            WP_to_CF_Logger::error('form.huwencai.com API 错误', ['code' => $code, 'body' => $body]);
            return $result;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $submissions = $body['data']['submissions'] ?? [];
        
        foreach ($submissions as $submission) {
            $external_id = $submission['id'] ?? '';
            if (empty($external_id)) {
                continue;
            }
            
            // 检查是否已同步
            if ($this->is_synced($external_id)) {
                continue;
            }
            
            // 提交数据
            $submission_data = $submission['data'] ?? $submission;
            
            // 确定提交类型
            $submission_type = $this->determine_submission_type($submission_data, $mapping);
            $post_id = $this->extract_post_id($submission_data);
            
            // 保存到本地表
            $this->save_submission([
                'external_id' => $external_id,
                'form_id' => $mapping['form_id'],
                'submission_type' => $submission_type,
                'post_id' => $post_id,
                'data' => $submission_data,
                'created_at' => $submission['created_at'] ?? current_time('mysql'),
            ]);
            
            $result['synced']++;
        }
        
        // 更新最后同步时间
        update_option("wptocf_last_sync_cfform_{$form_id}", current_time('mysql'));
        
        return $result;
    }
    
    /**
     * 从 form.huwencai.com endpoint 提取 form ID
     * 格式: https://form.huwencai.com/f/xxxxx
     */
    private function extract_cfform_id(string $endpoint): string
    {
        if (preg_match('#/f/([a-z0-9]+)$#', $endpoint, $matches)) {
            return $matches[1];
        }
        return '';
    }
    
    /**
     * 从 form.huwencai.com endpoint 提取基础 URL
     */
    private function extract_cfform_base_url(string $endpoint): string
    {
        if (preg_match('#^(https?://[^/]+)#', $endpoint, $matches)) {
            return $matches[1];
        }
        return '';
    }
    
    /**
     * 检查是否已同步
     */
    private function is_synced(string $external_id): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->table_name} WHERE external_id = %s",
                $external_id
            )
        );
    }
    
    /**
     * 保存提交记录
     */
    private function save_submission(array $data): int
    {
        global $wpdb;
        
        $wpdb->insert(
            $this->table_name,
            [
                'external_id' => $data['external_id'],
                'form_id' => $data['form_id'],
                'submission_type' => $data['submission_type'],
                'post_id' => $data['post_id'],
                'data' => json_encode($data['data'], JSON_UNESCAPED_UNICODE),
                'status' => 'pending',
                'created_at' => $data['created_at'],
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s', '%s']
        );
        
        return $wpdb->insert_id;
    }
    
    /**
     * 确定提交类型
     */
    private function determine_submission_type(array $submission, array $mapping): string
    {
        // 检查是否有评论相关字段
        if (isset($submission['_post_id']) || isset($submission['post_id']) || isset($submission['post_slug'])) {
            if (isset($submission['comment']) || isset($submission['comment_content'])) {
                return 'comment';
            }
        }
        return 'form';
    }
    
    /**
     * 提取文章 ID
     */
    private function extract_post_id(array $submission): int
    {
        // 直接有 post_id
        if (!empty($submission['_post_id'])) {
            return intval($submission['_post_id']);
        }
        if (!empty($submission['post_id'])) {
            return intval($submission['post_id']);
        }
        
        // 通过 slug 查找
        if (!empty($submission['post_slug'])) {
            $post = get_page_by_path($submission['post_slug'], OBJECT, ['post', 'page', 'product']);
            if ($post) {
                return $post->ID;
            }
        }
        
        // 通过 URL 查找
        if (!empty($submission['_page_url'])) {
            $post_id = url_to_postid($submission['_page_url']);
            if ($post_id) {
                return $post_id;
            }
        }
        
        return 0;
    }
    
    /**
     * 处理待处理的提交
     */
    public function process_pending_submissions(): array
    {
        global $wpdb;
        
        $results = ['comments' => 0, 'forms' => 0, 'errors' => []];
        
        $pending = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} WHERE status = 'pending' ORDER BY created_at ASC LIMIT 50",
            ARRAY_A
        );
        
        foreach ($pending as $submission) {
            $data = json_decode($submission['data'], true);
            
            if ($submission['submission_type'] === 'comment') {
                $result = $this->process_comment($submission, $data);
                if ($result) {
                    $results['comments']++;
                } else {
                    $results['errors'][] = "评论处理失败: {$submission['external_id']}";
                }
            } else {
                $result = $this->process_form_submission($submission, $data);
                if ($result) {
                    $results['forms']++;
                }
            }
            
            // 更新状态
            $wpdb->update(
                $this->table_name,
                ['status' => $result ? 'processed' : 'failed', 'synced_at' => current_time('mysql')],
                ['id' => $submission['id']],
                ['%s', '%s'],
                ['%d']
            );
        }
        
        return $results;
    }
    
    /**
     * 处理评论
     */
    private function process_comment(array $submission, array $data): bool
    {
        $post_id = $submission['post_id'];
        
        if (!$post_id) {
            // 尝试再次查找
            $post_id = $this->extract_post_id($data);
        }
        
        if (!$post_id) {
            WP_to_CF_Logger::warning('评论无法关联文章', ['external_id' => $submission['external_id']]);
            return false;
        }
        
        // 提取评论字段
        $comment_content = $data['comment'] ?? $data['comment_content'] ?? $data['message'] ?? '';
        $author_name = $data['name'] ?? $data['author'] ?? $data['author_name'] ?? '匿名';
        $author_email = $data['email'] ?? $data['author_email'] ?? '';
        $author_url = $data['url'] ?? $data['website'] ?? $data['author_url'] ?? '';
        
        if (empty($comment_content)) {
            return false;
        }
        
        // 检查是否已存在相同评论
        $existing = get_comments([
            'post_id' => $post_id,
            'author_email' => $author_email,
            'search' => substr($comment_content, 0, 50),
            'number' => 1,
        ]);
        
        if (!empty($existing)) {
            WP_to_CF_Logger::info('评论已存在，跳过', ['external_id' => $submission['external_id']]);
            return true; // 标记为已处理
        }
        
        // 插入评论
        $comment_id = wp_insert_comment([
            'comment_post_ID' => $post_id,
            'comment_author' => $author_name,
            'comment_author_email' => $author_email,
            'comment_author_url' => $author_url,
            'comment_content' => $comment_content,
            'comment_type' => 'comment',
            'comment_approved' => 0, // 待审核
            'comment_date' => $submission['created_at'],
            'comment_date_gmt' => get_gmt_from_date($submission['created_at']),
            'comment_meta' => [
                '_wptocf_external_id' => $submission['external_id'],
                '_wptocf_source' => 'static_site',
            ],
        ]);
        
        if ($comment_id) {
            WP_to_CF_Logger::info('评论已导入', [
                'comment_id' => $comment_id,
                'post_id' => $post_id,
                'external_id' => $submission['external_id'],
            ]);
            return true;
        }
        
        return false;
    }
    
    /**
     * 处理表单提交
     */
    private function process_form_submission(array $submission, array $data): bool
    {
        // 获取表单配置
        $form_admin = new WP_to_CF_Form_Mapping_Admin();
        $mapping = $form_admin->get_mapping($submission['form_id']);
        
        // 表单提交才发送邮件通知（评论不发邮件）
        // 注意：启用 Worker 后端时，通知邮件已由 Worker 在边缘发送，WordPress 不再重复发送
        $worker_backend = get_option('wptocf_worker_backend_enabled', '0') === '1';
        $notify_email = get_option('wptocf_form_notify_email', get_option('admin_email'));

        if ($notify_email && !$worker_backend) {
            $this->send_form_notification($notify_email, $submission, $data, $mapping);
        }
        
        // 触发钩子，允许其他插件处理
        do_action('wptocf_form_submission_processed', $submission, $data, $mapping);
        
        return true;
    }
    
    /**
     * 发送表单通知邮件
     */
    private function send_form_notification(string $to, array $submission, array $data, ?array $mapping): void
    {
        $form_name = $mapping['form_name'] ?? $submission['form_id'];
        $subject = sprintf(__('新的表单提交: %s', 'wp-to-cf'), $form_name);
        
        $body = "<h2>新的表单提交</h2>";
        $body .= "<p><strong>表单:</strong> {$form_name}</p>";
        $body .= "<p><strong>时间:</strong> {$submission['created_at']}</p>";
        $body .= "<hr>";
        $body .= "<table style='width:100%;border-collapse:collapse;'>";
        
        foreach ($data as $key => $value) {
            if (strpos($key, '_') === 0) continue; // 跳过内部字段
            $display_key = ucfirst(str_replace(['_', '-'], ' ', $key));
            $display_value = is_array($value) ? implode(', ', $value) : esc_html($value);
            $body .= "<tr><td style='padding:8px;border:1px solid #ddd;background:#f9f9f9;'><strong>{$display_key}</strong></td>";
            $body .= "<td style='padding:8px;border:1px solid #ddd;'>{$display_value}</td></tr>";
        }
        
        $body .= "</table>";
        
        wp_mail($to, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
    }
    
    /**
     * 手动同步
     */
    public function manual_sync(): array
    {
        return $this->sync_all();
    }
    
    /**
     * 获取同步统计
     */
    public function get_stats(): array
    {
        global $wpdb;
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $pending = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'pending'");
        $processed = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'processed'");
        $comments = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE submission_type = 'comment' AND status = 'processed'");
        
        return [
            'total' => intval($total),
            'pending' => intval($pending),
            'processed' => intval($processed),
            'comments_imported' => intval($comments),
            'last_sync' => get_option('wptocf_last_sync_time', ''),
        ];
    }
    
    /**
     * 获取最近的提交记录
     */
    public function get_recent_submissions(int $limit = 20): array
    {
        global $wpdb;
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} ORDER BY created_at DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
    }
}

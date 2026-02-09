<?php
/**
 * 表单映射管理类
 * 
 * 负责表单处理规则的配置和管理
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_Form_Mapping_Admin
 * 
 * 管理静态站点表单处理配置
 */
class WP_to_CF_Form_Mapping_Admin
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
        $this->table_name = $wpdb->prefix . 'wptocf_form_mappings';
    }

    /**
     * 初始化
     */
    public function init(): void
    {
        // 确保表存在
        $this->maybe_create_table();
    }

    /**
     * 创建数据库表（如果不存在）
     */
    public function maybe_create_table(): void
    {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id varchar(255) NOT NULL,
            form_name varchar(255) DEFAULT '',
            service_type varchar(50) DEFAULT 'formspree',
            service_endpoint varchar(2048) DEFAULT '',
            redirect_url varchar(2048) DEFAULT '',
            success_message text DEFAULT '',
            enabled tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_form_id (form_id)
        ) {$charset_collate};";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        
        // 检查是否需要迁移旧数据（添加新字段）
        $this->maybe_migrate_table();
    }
    
    /**
     * 迁移旧表结构
     */
    private function maybe_migrate_table(): void
    {
        global $wpdb;
        
        // 检查 service_type 字段是否存在
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$this->table_name}");
        
        if (!in_array('service_type', $columns)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN service_type varchar(50) DEFAULT 'formspree' AFTER form_name");
        }
        
        if (!in_array('service_endpoint', $columns)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN service_endpoint varchar(2048) DEFAULT '' AFTER service_type");
        }
        
        // 移除不再需要的字段（如果存在）
        if (in_array('recipient_email', $columns)) {
            $wpdb->query("ALTER TABLE {$this->table_name} DROP COLUMN recipient_email");
        }
        if (in_array('email_subject', $columns)) {
            $wpdb->query("ALTER TABLE {$this->table_name} DROP COLUMN email_subject");
        }
    }

    /**
     * 获取所有表单映射
     * 
     * @return array
     */
    public function get_all_mappings(): array
    {
        global $wpdb;
        
        $results = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} ORDER BY created_at DESC",
            ARRAY_A
        );
        
        return $results ?: [];
    }

    /**
     * 获取启用的表单映射
     * 
     * @return array
     */
    public function get_enabled_mappings(): array
    {
        global $wpdb;
        
        $results = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} WHERE enabled = 1 ORDER BY form_id ASC",
            ARRAY_A
        );
        
        return $results ?: [];
    }

    /**
     * 根据 form_id 获取映射
     * 
     * @param string $form_id
     * @return array|null
     */
    public function get_mapping(string $form_id): ?array
    {
        global $wpdb;
        
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE form_id = %s",
                $form_id
            ),
            ARRAY_A
        );
        
        return $result ?: null;
    }

    /**
     * 根据 ID 获取映射
     * 
     * @param int $id
     * @return array|null
     */
    public function get_mapping_by_id(int $id): ?array
    {
        global $wpdb;
        
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE id = %d",
                $id
            ),
            ARRAY_A
        );
        
        return $result ?: null;
    }

    /**
     * 保存表单映射
     * 
     * @param array $data
     * @return array{success: bool, message: string, id?: int}
     */
    public function save_mapping(array $data): array
    {
        global $wpdb;
        
        // 验证数据
        $validation = $this->validate_mapping($data);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'message' => implode(', ', $validation['errors']),
            ];
        }
        
        $form_id = sanitize_text_field($data['form_id']);
        $id = isset($data['id']) ? intval($data['id']) : 0;
        
        // 检查 form_id 是否已存在（排除当前记录）
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->table_name} WHERE form_id = %s AND id != %d",
                $form_id,
                $id
            )
        );
        
        if ($existing) {
            return [
                'success' => false,
                'message' => __('表单 ID 已存在', 'wp-to-cf'),
            ];
        }
        
        $insert_data = [
            'form_id' => $form_id,
            'form_name' => sanitize_text_field($data['form_name'] ?? ''),
            'service_type' => sanitize_text_field($data['service_type'] ?? 'formspree'),
            'service_endpoint' => esc_url_raw($data['service_endpoint'] ?? ''),
            'redirect_url' => sanitize_text_field($data['redirect_url'] ?? ''),
            'success_message' => sanitize_textarea_field($data['success_message'] ?? ''),
            'enabled' => isset($data['enabled']) ? intval($data['enabled']) : 1,
        ];
        
        if ($id > 0) {
            // 更新
            $result = $wpdb->update(
                $this->table_name,
                $insert_data,
                ['id' => $id],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%d'],
                ['%d']
            );
            
            if ($result === false) {
                return [
                    'success' => false,
                    'message' => __('更新失败', 'wp-to-cf'),
                ];
            }
            
            return [
                'success' => true,
                'message' => __('更新成功', 'wp-to-cf'),
                'id' => $id,
            ];
        } else {
            // 新增
            $result = $wpdb->insert(
                $this->table_name,
                $insert_data,
                ['%s', '%s', '%s', '%s', '%s', '%s', '%d']
            );
            
            if ($result === false) {
                return [
                    'success' => false,
                    'message' => __('保存失败', 'wp-to-cf'),
                ];
            }
            
            return [
                'success' => true,
                'message' => __('保存成功', 'wp-to-cf'),
                'id' => $wpdb->insert_id,
            ];
        }
    }

    /**
     * 删除表单映射
     * 
     * @param int $id
     * @return bool
     */
    public function delete_mapping(int $id): bool
    {
        global $wpdb;
        
        $result = $wpdb->delete(
            $this->table_name,
            ['id' => $id],
            ['%d']
        );
        
        return $result !== false;
    }

    /**
     * 验证表单映射数据
     * 
     * @param array $data
     * @return array{valid: bool, errors: string[]}
     */
    public function validate_mapping(array $data): array
    {
        $errors = [];
        
        // form_id 必填
        if (empty($data['form_id'])) {
            $errors[] = __('表单 ID 不能为空', 'wp-to-cf');
        } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $data['form_id'])) {
            $errors[] = __('表单 ID 只能包含字母、数字、下划线和连字符', 'wp-to-cf');
        }
        
        // service_endpoint 必填
        if (empty($data['service_endpoint'])) {
            $errors[] = __('表单服务端点不能为空', 'wp-to-cf');
        } elseif (!filter_var($data['service_endpoint'], FILTER_VALIDATE_URL)) {
            $errors[] = __('表单服务端点必须是有效的 URL', 'wp-to-cf');
        }
        
        // URL 格式验证（如果填写了，允许相对路径）
        if (!empty($data['redirect_url'])) {
            $url = $data['redirect_url'];
            // 允许以 / 开头的相对路径
            if (!preg_match('/^\//', $url) && !filter_var($url, FILTER_VALIDATE_URL)) {
                $errors[] = __('跳转路径格式不正确（应以 / 开头）', 'wp-to-cf');
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
    
    /**
     * 获取支持的表单服务列表
     * 
     * @return array
     */
    public static function get_supported_services(): array
    {
        return [
            'formspree' => [
                'name' => 'Formspree',
                'url' => 'https://formspree.io',
                'endpoint_pattern' => 'https://formspree.io/f/{form_id}',
                'description' => __('免费版每月 50 次提交，支持邮件通知', 'wp-to-cf'),
            ],
            'getform' => [
                'name' => 'Getform',
                'url' => 'https://getform.io',
                'endpoint_pattern' => 'https://getform.io/f/{form_id}',
                'description' => __('免费版每月 50 次提交，支持文件上传', 'wp-to-cf'),
            ],
            'web3forms' => [
                'name' => 'Web3Forms',
                'url' => 'https://web3forms.com',
                'endpoint_pattern' => 'https://api.web3forms.com/submit',
                'description' => __('完全免费，无限提交，需要 access_key', 'wp-to-cf'),
            ],
            'basin' => [
                'name' => 'Basin',
                'url' => 'https://usebasin.com',
                'endpoint_pattern' => 'https://usebasin.com/f/{form_id}',
                'description' => __('免费版每月 100 次提交，支持 Zapier 集成', 'wp-to-cf'),
            ],
            'fabform' => [
                'name' => 'Fabform',
                'url' => 'https://fabform.io',
                'endpoint_pattern' => 'https://fabform.io/f/{form_id}',
                'description' => __('免费版每月 250 次提交', 'wp-to-cf'),
            ],
            'custom' => [
                'name' => __('自定义', 'wp-to-cf'),
                'url' => '',
                'endpoint_pattern' => '',
                'description' => __('使用自定义的表单处理端点', 'wp-to-cf'),
            ],
        ];
    }

    /**
     * 获取表单映射统计
     * 
     * @return array
     */
    public function get_stats(): array
    {
        global $wpdb;
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $enabled = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE enabled = 1");
        
        return [
            'total' => intval($total),
            'enabled' => intval($enabled),
            'disabled' => intval($total) - intval($enabled),
        ];
    }

    /**
     * 导出配置为 JSON（用于静态站点 form-bridge.js）
     * 
     * @return array
     */
    public function export_config(): array
    {
        $mappings = $this->get_enabled_mappings();
        $config = [];
        
        foreach ($mappings as $mapping) {
            $config[$mapping['form_id']] = [
                'service_type' => $mapping['service_type'] ?? 'formspree',
                'endpoint' => $mapping['service_endpoint'],
                'redirect_url' => $mapping['redirect_url'],
                'success_message' => $mapping['success_message'] ?: __('提交成功！', 'wp-to-cf'),
            ];
        }
        
        return $config;
    }
}

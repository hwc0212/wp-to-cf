<?php
/**
 * 插件激活器
 * 
 * 负责插件激活时的数据库表创建和初始化配置
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_Activator
 * 
 * 处理插件激活逻辑
 */
class WP_to_CF_Activator
{
    /**
     * 插件激活主方法
     * 
     * 创建数据库表并设置默认配置
     * 
     * @return void
     */
    public static function activate(): void
    {
        self::create_tables();
        self::set_default_options();
        self::maybe_upgrade_database();
        
        // 刷新重写规则（如果需要自定义端点）
        flush_rewrite_rules();
    }

    /**
     * 创建数据库表
     * 
     * 创建任务队列表、增量账本表、批次跟踪表和部署队列表
     * 使用 dbDelta() 确保表结构可升级
     * 
     * @return void
     */
    private static function create_tables(): void
    {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $table_queue = $wpdb->prefix . 'wptocf_queue';
        $table_ledger = $wpdb->prefix . 'wptocf_ledger';
        $table_batches = $wpdb->prefix . 'wptocf_batches';
        $table_deployment_queue = $wpdb->prefix . 'wptocf_deployment_queue';

        // 任务队列表 SQL
        // 注意：dbDelta() 对 SQL 格式要求严格
        // - 每个字段定义必须独占一行
        // - PRIMARY KEY 必须有两个空格
        // - 索引定义必须独占一行
        $sql_queue = "CREATE TABLE {$table_queue} (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id bigint(20) UNSIGNED NOT NULL,
  task_type varchar(50) NOT NULL,
  batch_id varchar(32) NULL,
  priority tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  status varchar(20) NOT NULL DEFAULT 'pending',
  retry_count tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  error_message text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_status_priority (status,priority,created_at),
  KEY idx_post_id (post_id),
  KEY idx_batch_id (batch_id),
  KEY idx_created_at (created_at)
) {$charset_collate};";

        // 增量账本表 SQL
        $sql_ledger = "CREATE TABLE {$table_ledger} (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id bigint(20) UNSIGNED NOT NULL,
  file_path varchar(500) NOT NULL,
  content_hash char(64) NOT NULL,
  file_size int(10) UNSIGNED NOT NULL DEFAULT 0,
  sync_status varchar(20) NOT NULL DEFAULT 'synced',
  last_synced datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY unique_file_path (file_path),
  KEY idx_post_id (post_id),
  KEY idx_content_hash (content_hash),
  KEY idx_sync_status (sync_status),
  KEY idx_last_synced (last_synced)
) {$charset_collate};";

        // 批次跟踪表 SQL
        $sql_batches = "CREATE TABLE {$table_batches} (
  id varchar(32) NOT NULL,
  task_ids text NOT NULL,
  completed_tasks text NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  deployment_id varchar(100) NULL,
  created_at datetime NOT NULL,
  completed_at datetime NULL,
  PRIMARY KEY  (id),
  KEY idx_status (status),
  KEY idx_created_at (created_at)
) {$charset_collate};";

        // 部署队列表 SQL（手动模式）
        $sql_deployment_queue = "CREATE TABLE {$table_deployment_queue} (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id bigint(20) UNSIGNED NOT NULL,
  file_path varchar(500) NOT NULL,
  change_type varchar(20) NOT NULL,
  content_hash char(64) NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_post_id (post_id),
  KEY idx_change_type (change_type),
  KEY idx_created_at (created_at)
) {$charset_collate};";

        // 使用 dbDelta 创建表（支持表结构更新）
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_queue);
        dbDelta($sql_ledger);
        dbDelta($sql_batches);
        dbDelta($sql_deployment_queue);

        // 保存数据库版本
        update_option('wptocf_db_version', WPTOCF_DB_VERSION);
    }

    /**
     * 设置默认配置选项
     * 
     * 初始化插件的默认配置值
     * 
     * @return void
     */
    private static function set_default_options(): void
    {
        // 只在选项不存在时设置默认值
        $defaults = [
            'wptocf_account_id' => '',
            'wptocf_api_token' => '',
            'wptocf_project_name' => '',
            'wptocf_production_domain' => '',
            'wptocf_head_code' => '',
            'wptocf_body_start_code' => '',
            'wptocf_body_end_code' => '',
            'wptocf_custom_redirects' => '',
            'wptocf_deployment_mode' => 'manual', // 默认为手动模式
        ];

        foreach ($defaults as $option_name => $default_value) {
            if (get_option($option_name) === false) {
                add_option($option_name, $default_value);
            }
        }
    }

    /**
     * 检查并升级数据库
     * 
     * 用于处理插件更新时的数据库架构变更
     * 
     * @return void
     */
    private static function maybe_upgrade_database(): void
    {
        $current_db_version = get_option('wptocf_db_version', '0');
        
        // 如果数据库版本已是最新，无需升级
        if (version_compare($current_db_version, WPTOCF_DB_VERSION, '>=')) {
            // 即使版本相同，也要检查字段完整性（防止手动删除或升级失败）
            self::verify_table_structure();
            return;
        }
        
        // 执行升级
        self::create_tables(); // dbDelta 会自动处理表结构更新
        
        // 验证表结构
        self::verify_table_structure();
        
        // 更新数据库版本
        update_option('wptocf_db_version', WPTOCF_DB_VERSION);
        
        // 记录升级日志
        if (class_exists('WP_to_CF_Logger')) {
            WP_to_CF_Logger::info('Database upgraded', [
                'from_version' => $current_db_version,
                'to_version' => WPTOCF_DB_VERSION,
            ]);
        }
    }

    /**
     * 验证表结构完整性并修复缺失字段
     * 
     * @return void
     */
    private static function verify_table_structure(): void
    {
        global $wpdb;
        
        $table_ledger = $wpdb->prefix . 'wptocf_ledger';
        
        // 检查 sync_status 字段是否存在
        $column_exists = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = %s 
                AND TABLE_NAME = %s 
                AND COLUMN_NAME = 'sync_status'",
                DB_NAME,
                $table_ledger
            )
        );
        
        // 如果字段不存在，添加它
        if (empty($column_exists)) {
            $wpdb->query(
                "ALTER TABLE {$table_ledger} 
                ADD COLUMN sync_status varchar(20) NOT NULL DEFAULT 'synced' AFTER file_size,
                ADD INDEX idx_sync_status (sync_status)"
            );
            
            if (class_exists('WP_to_CF_Logger')) {
                WP_to_CF_Logger::info('Added missing sync_status column to ledger table');
            }
        }
    }
}

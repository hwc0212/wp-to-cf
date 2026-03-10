<?php
/**
 * 插件核心类
 * 
 * 负责插件初始化、依赖加载和钩子注册
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_Core
 * 
 * 插件的主控制器类
 */
class WP_to_CF_Core
{
    /**
     * 插件版本
     */
    private string $version;

    /**
     * 任务队列实例
     */
    private ?WP_to_CF_Task_Queue $queue = null;

    /**
     * 任务调度器实例
     */
    private ?WP_to_CF_Task_Scheduler $scheduler = null;

    /**
     * 内容监听器实例
     */
    private ?WP_to_CF_Content_Listener $content_listener = null;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->version = WPTOCF_VERSION;
    }

    /**
     * 初始化插件
     * 
     * @return void
     */
    public function init(): void
    {
        $this->load_dependencies();
        $this->verify_database_integrity(); // 启动时验证数据库完整性
        $this->register_hooks();
        $this->load_textdomain();
    }

    /**
     * 加载依赖文件
     * 
     * @return void
     */
    private function load_dependencies(): void
    {
        // 加载工具类（必须最先加载）
        require_once WPTOCF_PLUGIN_DIR . 'includes/utils/class-logger.php';
        require_once WPTOCF_PLUGIN_DIR . 'includes/utils/class-crypto.php';
        
        // 加载核心功能类
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-task-queue.php';
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-deployment-buffer.php';
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-batch-coordinator.php';
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-html-cache.php';
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-task-scheduler.php';
        
        // 加载 Phase 1 类（手动部署控制）
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-deployment-queue.php';
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-manual-trigger.php';
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-intranet-sanitizer.php';
        
        // 加载内容监听器（依赖上面的类）
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-content-listener.php';
        
        // 加载 Phase 3 类（HTML 生成和转换）
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-path-whitewash.php';
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-universal-whitewash.php';
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-html-generator.php';
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-asset-collector.php';
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-asset-localizer.php';
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-wordpress-debloat.php';
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-code-injector.php';
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-url-transformer.php';
        
        // 加载 Phase 4 类（哈希账本和 Cloudflare API）
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-hash-ledger.php';
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-cloudflare-api.php';
        
        // 加载资产同步类
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-assets-scanner.php';
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-assets-ledger.php';
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-assets-sync-manager.php';
        
        // 加载特殊页面管理器
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-special-pages-manager.php';
        
        // 加载站点导出器
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-site-exporter.php';
        
        // 加载站点打包器
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-site-packager.php';
        
        // 加载包管理器和缓存管理器
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-package-manager.php';
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-cache-manager.php';
        
        // 加载表单处理相关类
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-form-mapping-admin.php';
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-form-scanner.php';
        require_once WPTOCF_PLUGIN_DIR . 'includes/class-submission-sync.php';
        
        // 后台管理
        if (is_admin()) {
            require_once WPTOCF_PLUGIN_DIR . 'admin/class-settings-page.php';
        }
    }

    /**
     * 验证数据库完整性
     * 
     * 在插件初始化时检查数据库表结构，确保所有必需字段和表存在
     * 
     * @return void
     */
    private function verify_database_integrity(): void
    {
        global $wpdb;
        
        $table_ledger = $wpdb->prefix . 'wptocf_ledger';
        $table_deployment_queue = $wpdb->prefix . 'wptocf_deployment_queue';
        
        // 检查所有必需的表是否存在
        $tables_to_check = [
            'wptocf_queue',
            'wptocf_ledger',
            'wptocf_batches',
            'wptocf_deployment_queue',
        ];
        
        $missing_tables = [];
        foreach ($tables_to_check as $table_name) {
            $full_table_name = $wpdb->prefix . $table_name;
            $exists = $wpdb->get_var("SHOW TABLES LIKE '{$full_table_name}'") === $full_table_name;
            if (!$exists) {
                $missing_tables[] = $table_name;
            }
        }
        
        // 如果有表缺失，触发完整的激活流程
        if (!empty($missing_tables)) {
            WP_to_CF_Logger::warning('Missing database tables, triggering activation', [
                'missing_tables' => $missing_tables,
            ]);
            
            require_once WPTOCF_PLUGIN_DIR . 'includes/class-activator.php';
            WP_to_CF_Activator::activate();
            return;
        }
        
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
            WP_to_CF_Logger::warning('Missing sync_status column, adding it now');
            
            $wpdb->query(
                "ALTER TABLE {$table_ledger} 
                ADD COLUMN sync_status varchar(20) NOT NULL DEFAULT 'synced' AFTER file_size,
                ADD INDEX idx_sync_status (sync_status)"
            );
            
            WP_to_CF_Logger::info('Added missing sync_status column to ledger table');
        }
    }

    /**
     * 注册 WordPress 钩子
     * 
     * @return void
     */
    private function register_hooks(): void
    {
        // 初始化任务队列和调度器（单例模式）
        $this->queue = new WP_to_CF_Task_Queue();
        $this->scheduler = new WP_to_CF_Task_Scheduler($this->queue);
        $this->content_listener = new WP_to_CF_Content_Listener($this->queue, $this->scheduler);
        
        // 注册内容监听器钩子
        $this->content_listener->register_hooks();
        
        // 注册任务调度器钩子
        $this->scheduler->register_hooks();
        
        // 后台管理钩子
        if (is_admin()) {
            $settings_page = new WP_to_CF_Settings_Page();
            add_action('admin_menu', [$settings_page, 'add_menu']);
            add_action('admin_init', [$settings_page, 'register_settings']);
            add_action('wp_ajax_wptocf_export_site', [$settings_page, 'ajax_export_site']);
            add_action('wp_ajax_wptocf_export_and_deploy', [$settings_page, 'ajax_export_and_deploy']);
            add_action('wp_ajax_wptocf_incremental_deploy', [$settings_page, 'ajax_incremental_deploy']);
            add_action('wp_ajax_wptocf_staticize_all', [$settings_page, 'ajax_staticize_all']);
            
            // 分块部署 AJAX（解决共享主机超时）
            add_action('wp_ajax_wptocf_chunked_collect', [$settings_page, 'ajax_chunked_collect']);
            add_action('wp_ajax_wptocf_chunked_fetch', [$settings_page, 'ajax_chunked_fetch']);
            add_action('wp_ajax_wptocf_chunked_deploy', [$settings_page, 'ajax_chunked_deploy']);
            
            // 包管理 AJAX
            add_action('wp_ajax_wptocf_get_packages', [$settings_page, 'ajax_get_packages']);
            add_action('wp_ajax_wptocf_delete_package', [$settings_page, 'ajax_delete_package']);
            add_action('wp_ajax_wptocf_cleanup_packages', [$settings_page, 'ajax_cleanup_packages']);
            
            // 缓存管理 AJAX
            add_action('wp_ajax_wptocf_get_cache_stats', [$settings_page, 'ajax_get_cache_stats']);
            add_action('wp_ajax_wptocf_clear_cache', [$settings_page, 'ajax_clear_cache']);
            
            // Cloudflare 配置 AJAX
            add_action('wp_ajax_wptocf_validate_credentials', [$settings_page, 'ajax_validate_cf_credentials']);
            add_action('wp_ajax_wptocf_create_project', [$settings_page, 'ajax_create_pages_project']);
            
            // 表单配置 AJAX
            add_action('wp_ajax_wptocf_scan_forms', [$settings_page, 'ajax_scan_forms']);
            add_action('wp_ajax_wptocf_get_form_mappings', [$settings_page, 'ajax_get_form_mappings']);
            add_action('wp_ajax_wptocf_save_form_mapping', [$settings_page, 'ajax_save_form_mapping']);
            add_action('wp_ajax_wptocf_delete_form_mapping', [$settings_page, 'ajax_delete_form_mapping']);
            
            // 表单配置管理
            $form_admin = new WP_to_CF_Form_Mapping_Admin();
            $form_admin->init();
            
            // 提交同步管理
            $submission_sync = new WP_to_CF_Submission_Sync();
            $submission_sync->init();
            
            // 提交同步 AJAX
            add_action('wp_ajax_wptocf_sync_submissions', [$settings_page, 'ajax_sync_submissions']);
            add_action('wp_ajax_wptocf_get_sync_stats', [$settings_page, 'ajax_get_sync_stats']);
            add_action('wp_ajax_wptocf_delete_submission', [$settings_page, 'ajax_delete_submission']);
        }
    }

    /**
     * 加载插件文本域
     * 
     * @return void
     */
    private function load_textdomain(): void
    {
        load_plugin_textdomain(
            'wp-to-cf',
            false,
            dirname(plugin_basename(WPTOCF_PLUGIN_FILE)) . '/languages'
        );
    }
}

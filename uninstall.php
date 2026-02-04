<?php
/**
 * 插件卸载脚本
 * 
 * 当插件被卸载时，删除所有数据库表和配置选项
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// 删除数据库表
$table_queue = $wpdb->prefix . 'wptocf_queue';
$table_ledger = $wpdb->prefix . 'wptocf_ledger';

$wpdb->query("DROP TABLE IF EXISTS {$table_queue}");
$wpdb->query("DROP TABLE IF EXISTS {$table_ledger}");

// 删除所有配置选项
$options = [
    'wptocf_account_id',
    'wptocf_api_token',
    'wptocf_project_name',
    'wptocf_production_domain',
    'wptocf_head_code',
    'wptocf_body_start_code',
    'wptocf_body_end_code',
    'wptocf_db_version',
    'wptocf_custom_redirects',
];

foreach ($options as $option) {
    delete_option($option);
}

// 清理所有计划任务
wp_clear_scheduled_hook('wptocf_process_queue');

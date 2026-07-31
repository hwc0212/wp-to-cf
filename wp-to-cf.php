<?php
/**
 * Plugin Name: WordPress to Cloudflare
 * Plugin URI: https://github.com/hwc0212/wp-to-cf
 * Description: 将 WordPress 网站转换为静态 HTML 并部署到 Cloudflare Workers（静态资源），支持内网后端与公网静态前端分离
 * Version: 1.6.0
 * Requires at least: 6.0
 * Requires PHP: 7.3
 * Author: huwencai.com
 * Author URI: https://huwencai.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-to-cf
 * Domain Path: /languages
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 插件版本
define('WPTOCF_VERSION', '1.6.0');

// 插件路径
define('WPTOCF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPTOCF_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPTOCF_PLUGIN_FILE', __FILE__);

// 数据库版本
define('WPTOCF_DB_VERSION', '1.1');

/**
 * 插件激活钩子
 */
function wptocf_activate(): void
{
    require_once WPTOCF_PLUGIN_DIR . 'includes/class-activator.php';
    WP_to_CF_Activator::activate();
}
register_activation_hook(__FILE__, 'wptocf_activate');

/**
 * 插件停用钩子
 */
function wptocf_deactivate(): void
{
    // 停用时不删除数据，保留配置和队列
    // 仅清理计划任务
    $timestamp = wp_next_scheduled('wptocf_process_queue');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'wptocf_process_queue');
    }
}
register_deactivation_hook(__FILE__, 'wptocf_deactivate');

/**
 * 插件卸载钩子（在 uninstall.php 中实现）
 */

/**
 * 加载插件核心类
 */
require_once WPTOCF_PLUGIN_DIR . 'includes/class-core.php';

/**
 * 加载管理工具
 */
if (is_admin()) {
    require_once WPTOCF_PLUGIN_DIR . 'admin/manual-cache-warmup.php';
}

/**
 * 初始化插件
 */
function wptocf_init(): void
{
    $plugin = new WP_to_CF_Core();
    $plugin->init();
}
add_action('plugins_loaded', 'wptocf_init');

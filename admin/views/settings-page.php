<?php
/**
 * 设置页面视图 - Tab 结构
 * 
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 检查 Cloudflare 配置是否完整
$cf_api = new WP_to_CF_Cloudflare_API();
$cf_configured = $cf_api->is_configured();

// 检查导出缓存是否存在
$export_cache_data = get_option('wptocf_export_cache', null);
$has_export_cache = $export_cache_data && isset($export_cache_data['files']) && count($export_cache_data['files']) > 0;

// 当前 Tab
$current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'cloudflare';
$tabs = [
    'cloudflare' => __('Cloudflare 配置', 'wp-to-cf'),
    'export' => __('导出与部署', 'wp-to-cf'),
    'forms' => __('表单配置', 'wp-to-cf'),
    'sync' => __('评论同步', 'wp-to-cf'),
    'submissions' => __('表单提交', 'wp-to-cf'),
    'advanced' => __('高级设置', 'wp-to-cf'),
];
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <?php settings_errors('wptocf_messages'); ?>

    <!-- Tab 导航 -->
    <nav class="nav-tab-wrapper">
        <?php foreach ($tabs as $tab_id => $tab_name): ?>
            <a href="?page=wp-to-cf-settings&tab=<?php echo esc_attr($tab_id); ?>" 
               class="nav-tab <?php echo $current_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($tab_name); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="tab-content" style="margin-top: 20px;">
        <?php
        switch ($current_tab) {
            case 'cloudflare':
                include __DIR__ . '/tab-cloudflare.php';
                break;
            case 'export':
                include __DIR__ . '/tab-export.php';
                break;
            case 'forms':
                include __DIR__ . '/tab-forms.php';
                break;
            case 'sync':
                include __DIR__ . '/tab-sync.php';
                break;
            case 'submissions':
                include __DIR__ . '/tab-submissions.php';
                break;
            case 'advanced':
                include __DIR__ . '/tab-advanced.php';
                break;
        }
        ?>
    </div>
</div>

<style>
.wptocf-panel {
    background: white;
    border: 1px solid #c3c4c7;
    padding: 15px;
    margin: 20px 0;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
}
.wptocf-panel h2 {
    margin-top: 0;
}
.wptocf-panel.blue { border-left: 4px solid #2271b1; }
.wptocf-panel.green { border-left: 4px solid #00a32a; }
.wptocf-panel.purple { border-left: 4px solid #9b59b6; }
.wptocf-panel.yellow { border-left: 4px solid #f0b849; }
.wptocf-panel.cyan { border-left: 4px solid #72aee6; }
.wptocf-help ul { list-style-type: disc; margin-left: 20px; }
.wptocf-help li { margin-bottom: 10px; }
#wptocf-export-btn.loading { opacity: 0.6; pointer-events: none; }
.wptocf-export-success { background: #d7f0d7; border: 1px solid #00a32a; padding: 10px; border-radius: 4px; }
.wptocf-export-error { background: #ffd7d7; border: 1px solid #d63638; padding: 10px; border-radius: 4px; }
.wptocf-combobox { position: relative; display: inline-block; }
.wptocf-combobox-input { padding-right: 30px !important; }
.wptocf-combobox-arrow { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: #666; font-size: 10px; cursor: pointer; }
.wptocf-combobox-dropdown { display: none; position: absolute; top: 100%; left: 0; right: 0; max-height: 200px; overflow-y: auto; background: #fff; border: 1px solid #8c8f94; border-top: none; border-radius: 0 0 4px 4px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); z-index: 1000; margin: 0; padding: 0; list-style: none; }
.wptocf-combobox-dropdown li { padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f0f0f0; }
.wptocf-combobox-dropdown li:last-child { border-bottom: none; }
.wptocf-combobox-dropdown li:hover { background: #f0f6fc; }
.wptocf-combobox.open .wptocf-combobox-dropdown { display: block; }
.wptocf-toggle-guide { margin-left: 5px; text-decoration: none; }
.wptocf-guide-panel { border-radius: 4px; }
.wptocf-guide-panel ol { line-height: 1.8; }
.wptocf-warning-box { background: #fff8e5; border: 1px solid #f0b849; border-radius: 4px; padding: 12px; margin-bottom: 15px; }
</style>

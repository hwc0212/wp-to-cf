<?php
/**
 * 设置页面视图
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
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <?php settings_errors('wptocf_messages'); ?>

    <form method="post" action="options.php">
        <?php
        // 输出设置部分（包含 WordPress Settings API 自动生成的 nonce）
        settings_fields('wptocf_settings');
        do_settings_sections('wp-to-cf-settings');
        
        // 提交按钮
        submit_button(__('保存设置', 'wp-to-cf'));
        ?>
    </form>

    <!-- 一键静态化全站面板 -->
    <div class="wptocf-export-panel" style="background: white; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; padding: 15px; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
        <h2 style="margin-top: 0;">
            <span class="dashicons dashicons-update"></span>
            <?php esc_html_e('一键静态化全站', 'wp-to-cf'); ?>
        </h2>
        <p>
            <?php esc_html_e('将整个站点静态化并打包为 ZIP 文件，可下载或直接上传到 Cloudflare Pages。', 'wp-to-cf'); ?>
        </p>
        <p>
            <button type="button" id="wptocf-export-btn" class="button button-secondary">
                <span class="dashicons dashicons-download" style="vertical-align: middle;"></span>
                <?php esc_html_e('导出为 ZIP', 'wp-to-cf'); ?>
            </button>
            <button type="button" id="wptocf-export-deploy-btn" class="button button-primary" <?php echo $cf_configured ? '' : 'disabled'; ?>>
                <span class="dashicons dashicons-cloud-upload" style="vertical-align: middle;"></span>
                <?php esc_html_e('全量上传', 'wp-to-cf'); ?>
            </button>
            <button type="button" id="wptocf-incremental-deploy-btn" class="button button-primary" <?php echo ($cf_configured && $has_export_cache) ? '' : 'disabled'; ?> style="background: #00a32a; border-color: #00a32a;">
                <span class="dashicons dashicons-upload" style="vertical-align: middle;"></span>
                <?php esc_html_e('增量上传', 'wp-to-cf'); ?>
            </button>
            <?php if (!$cf_configured): ?>
                <span style="margin-left: 10px; color: #d63638; font-size: 12px;">
                    <span class="dashicons dashicons-warning" style="font-size: 14px; vertical-align: middle;"></span>
                    <?php esc_html_e('请先配置 Cloudflare Pages 凭证', 'wp-to-cf'); ?>
                </span>
            <?php elseif (!$has_export_cache): ?>
                <span id="wptocf-no-cache-warning" style="margin-left: 10px; color: #dba617; font-size: 12px;">
                    <span class="dashicons dashicons-info" style="font-size: 14px; vertical-align: middle;"></span>
                    <?php esc_html_e('请先执行全量上传以建立缓存', 'wp-to-cf'); ?>
                </span>
            <?php endif; ?>
            <span id="wptocf-export-status" style="margin-left: 10px;"></span>
        </p>
        <p style="font-size: 12px; color: #666; margin-top: 5px;">
            <strong><?php esc_html_e('全量上传', 'wp-to-cf'); ?>:</strong> <?php esc_html_e('重新生成并上传所有文件', 'wp-to-cf'); ?> &nbsp;|&nbsp;
            <strong><?php esc_html_e('增量上传', 'wp-to-cf'); ?>:</strong> <?php esc_html_e('只上传变化的文件（更快，Cloudflare 自动去重）', 'wp-to-cf'); ?>
        </p>
        <!-- 进度条 -->
        <div id="wptocf-export-progress" style="display: none; margin-top: 15px;">
            <div style="background: #f0f0f0; border-radius: 4px; height: 30px; position: relative; overflow: hidden;">
                <div id="wptocf-export-progress-bar" style="background: linear-gradient(90deg, #2271b1, #72aee6); height: 100%; width: 0%; transition: width 0.3s ease; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 12px;">
                    0%
                </div>
            </div>
            <div id="wptocf-export-progress-text" style="margin-top: 8px; font-size: 13px; color: #666;">
                <?php esc_html_e('准备中...', 'wp-to-cf'); ?>
            </div>
        </div>
        <div id="wptocf-export-result" style="margin-top: 15px; display: none;"></div>
    </div>

    <!-- 包管理面板 -->
    <div class="wptocf-packages-panel" style="background: white; border: 1px solid #c3c4c7; border-left: 4px solid #72aee6; padding: 15px; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
        <h2 style="margin-top: 0;">
            <span class="dashicons dashicons-archive"></span>
            <?php esc_html_e('包管理', 'wp-to-cf'); ?>
        </h2>
        <p>
            <?php esc_html_e('管理已导出的 ZIP 包，查看历史记录，删除旧文件。', 'wp-to-cf'); ?>
        </p>
        <p>
            <button type="button" id="wptocf-refresh-packages-btn" class="button button-secondary">
                <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
                <?php esc_html_e('刷新列表', 'wp-to-cf'); ?>
            </button>
            <button type="button" id="wptocf-cleanup-packages-btn" class="button button-secondary">
                <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
                <?php esc_html_e('清理旧包（保留最新5个）', 'wp-to-cf'); ?>
            </button>
        </p>
        <div id="wptocf-packages-stats" style="margin: 15px 0; padding: 10px; background: #f0f6fc; border-radius: 4px;"></div>
        <div id="wptocf-packages-list" style="margin-top: 15px;"></div>
    </div>

    <!-- 缓存管理面板 -->
    <div class="wptocf-cache-panel" style="background: white; border: 1px solid #c3c4c7; border-left: 4px solid #f0b849; padding: 15px; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
        <h2 style="margin-top: 0;">
            <span class="dashicons dashicons-database"></span>
            <?php esc_html_e('缓存管理', 'wp-to-cf'); ?>
        </h2>
        <p>
            <?php esc_html_e('管理资产缓存（CSS、JS、图片、字体），查看缓存大小，清理缓存。', 'wp-to-cf'); ?>
        </p>
        <p>
            <button type="button" id="wptocf-refresh-cache-btn" class="button button-secondary">
                <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
                <?php esc_html_e('刷新统计', 'wp-to-cf'); ?>
            </button>
            <button type="button" id="wptocf-clear-cache-all-btn" class="button button-secondary">
                <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
                <?php esc_html_e('清理所有缓存', 'wp-to-cf'); ?>
            </button>
        </p>
        <div id="wptocf-cache-stats" style="margin-top: 15px;"></div>
    </div>

    <!-- 帮助信息 -->
    <div class="wptocf-help" style="background: #f0f6fc; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; padding: 15px; margin: 20px 0;">
        <h3 style="margin-top: 0;">
            <span class="dashicons dashicons-info"></span>
            <?php esc_html_e('使用说明', 'wp-to-cf'); ?>
        </h3>
        <ul style="margin-left: 20px;">
            <li>
                <strong><?php esc_html_e('ZIP 下载（无需配置）:', 'wp-to-cf'); ?></strong>
                <?php esc_html_e('点击「导出为 ZIP」按钮，下载后手动上传到 Cloudflare Pages 或其他静态托管服务', 'wp-to-cf'); ?>
            </li>
            <li>
                <strong><?php esc_html_e('自动上传（需要配置）:', 'wp-to-cf'); ?></strong>
                <?php esc_html_e('填写 Cloudflare 凭证后，可使用「全量上传」或「增量上传」一键部署到 Cloudflare Pages', 'wp-to-cf'); ?>
            </li>
            <li>
                <strong><?php esc_html_e('Production Domain:', 'wp-to-cf'); ?></strong>
                <?php esc_html_e('静态 HTML 中的所有内网链接将被替换为此域名。无论使用哪种方式，都建议设置此项', 'wp-to-cf'); ?>
            </li>
        </ul>
    </div>
</div>

<style>
.wptocf-environment-status table td {
    padding: 10px;
}
.wptocf-environment-status table tr:nth-child(even) {
    background: #f9f9f9;
}
.wptocf-help ul {
    list-style-type: disc;
}
.wptocf-help li {
    margin-bottom: 10px;
}
#wptocf-export-btn.loading {
    opacity: 0.6;
    pointer-events: none;
}
.wptocf-export-success {
    background: #d7f0d7;
    border: 1px solid #00a32a;
    padding: 10px;
    border-radius: 4px;
}
.wptocf-export-error {
    background: #ffd7d7;
    border: 1px solid #d63638;
    padding: 10px;
    border-radius: 4px;
}
/* Combobox 样式 */
.wptocf-combobox {
    position: relative;
    display: inline-block;
}
.wptocf-combobox-input {
    padding-right: 30px !important;
}
.wptocf-combobox-arrow {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: #666;
    font-size: 10px;
    cursor: pointer;
    user-select: none;
}
.wptocf-combobox-dropdown {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    max-height: 200px;
    overflow-y: auto;
    background: #fff;
    border: 1px solid #8c8f94;
    border-top: none;
    border-radius: 0 0 4px 4px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    z-index: 1000;
    margin: 0;
    padding: 0;
    list-style: none;
}
.wptocf-combobox-dropdown li {
    padding: 8px 12px;
    cursor: pointer;
    border-bottom: 1px solid #f0f0f0;
}
.wptocf-combobox-dropdown li:last-child {
    border-bottom: none;
}
.wptocf-combobox-dropdown li:hover,
.wptocf-combobox-dropdown li.selected {
    background: #f0f6fc;
}
.wptocf-combobox.open .wptocf-combobox-dropdown {
    display: block;
}
.wptocf-combobox.open .wptocf-combobox-arrow {
    transform: translateY(-50%) rotate(180deg);
}
/* 可折叠指南样式 */
.wptocf-toggle-guide {
    margin-left: 5px;
    text-decoration: none;
}
.wptocf-guide-panel {
    border-radius: 4px;
}
.wptocf-guide-panel ol {
    line-height: 1.8;
}
</style>

<script>
jQuery(document).ready(function($) {
    // 折叠指南切换
    $('.wptocf-toggle-guide').on('click', function(e) {
        e.preventDefault();
        var targetId = $(this).data('target');
        var $target = $('#' + targetId);
        $target.slideToggle(200);
    });
    
    // 更新进度
    function updateProgress($bar, $text, percent, message, elapsed) {
        $bar.css('width', percent + '%').text(Math.round(percent) + '%');
        $text.text(message + (elapsed ? ' (' + elapsed + '秒)' : ''));
    }

    // ZIP 导出按钮
    $('#wptocf-export-btn').on('click', function() {
        var $btn = $(this);
        var $result = $('#wptocf-export-result');
        var $progress = $('#wptocf-export-progress');
        var $progressBar = $('#wptocf-export-progress-bar');
        var $progressText = $('#wptocf-export-progress-text');

        $btn.addClass('loading').prop('disabled', true);
        $result.hide();
        $progress.show();
        
        var startTime = Date.now();
        var progress = 0;
        var stage = 0;
        var stages = [
            {p: 10, msg: '正在收集页面URL'},
            {p: 25, msg: '正在抓取页面HTML'},
            {p: 40, msg: '正在提取资源链接'},
            {p: 55, msg: '正在加载CSS、JS、图片'},
            {p: 70, msg: '正在处理路径映射'},
            {p: 85, msg: '正在创建ZIP包'},
            {p: 95, msg: '正在完成'}
        ];
        
        var progressInterval = setInterval(function() {
            var elapsed = Math.round((Date.now() - startTime) / 1000);
            if (stage < stages.length && progress < stages[stage].p) {
                progress += 0.5;
                if (progress >= stages[stage].p) {
                    updateProgress($progressBar, $progressText, stages[stage].p, stages[stage].msg, elapsed);
                    stage++;
                } else {
                    $progressBar.css('width', progress + '%').text(Math.round(progress) + '%');
                    $progressText.text($progressText.text().split(' (')[0] + ' (' + elapsed + '秒)');
                }
            } else {
                $progressText.text($progressText.text().split(' (')[0] + ' (' + elapsed + '秒)');
            }
        }, 200);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            timeout: 1800000,
            data: {
                action: 'wptocf_export_site',
                nonce: '<?php echo wp_create_nonce('wptocf_export_site'); ?>'
            },
            success: function(response) {
                clearInterval(progressInterval);
                var elapsed = Math.round((Date.now() - startTime) / 1000);
                
                if (response.success) {
                    updateProgress($progressBar, $progressText, 100, '导出完成', elapsed);
                    setTimeout(function() {
                        $btn.removeClass('loading').prop('disabled', false);
                        $progress.hide();
                        $result.html(
                            '<div class="wptocf-export-success">' +
                            '<p><strong>导出成功！</strong> 用时 ' + elapsed + ' 秒</p>' +
                            '<p>' + response.data.message + '</p>' +
                            '<p><a href="' + response.data.zip_url + '" class="button button-primary" download>下载 ZIP 文件</a></p>' +
                            '</div>'
                        ).show();
                        // 自动刷新包列表
                        refreshPackages();
                    }, 500);
                } else {
                    $btn.removeClass('loading').prop('disabled', false);
                    $progress.hide();
                    $result.html('<div class="wptocf-export-error"><p><strong>导出失败</strong></p><p>' + response.data.message + '</p></div>').show();
                }
            },
            error: function(xhr, status, error) {
                clearInterval(progressInterval);
                $btn.removeClass('loading').prop('disabled', false);
                $progress.hide();
                $result.html('<div class="wptocf-export-error"><p><strong>导出失败</strong></p><p>网络错误: ' + status + '</p></div>').show();
            }
        });
    });

    // 导出并上传到 Cloudflare 按钮（全量上传）
    $('#wptocf-export-deploy-btn').on('click', function() {
        var $btn = $(this);
        var $result = $('#wptocf-export-result');
        var $progress = $('#wptocf-export-progress');
        var $progressBar = $('#wptocf-export-progress-bar');
        var $progressText = $('#wptocf-export-progress-text');

        if (!confirm('确定要全量上传到 Cloudflare Pages 吗？\n\n全量上传会重新生成并上传所有文件。')) {
            return;
        }

        $btn.addClass('loading').prop('disabled', true);
        $('#wptocf-export-btn').prop('disabled', true);
        $('#wptocf-incremental-deploy-btn').prop('disabled', true);
        $result.hide();
        $progress.show();
        
        var startTime = Date.now();
        var progress = 0;
        var stage = 0;
        var stages = [
            {p: 5, msg: '正在收集页面URL'},
            {p: 15, msg: '正在抓取页面HTML'},
            {p: 25, msg: '正在提取资源链接'},
            {p: 40, msg: '正在加载CSS、JS、图片'},
            {p: 50, msg: '正在处理路径映射'},
            {p: 60, msg: '正在创建ZIP包'},
            {p: 70, msg: '正在准备上传'},
            {p: 80, msg: '正在上传到Cloudflare'},
            {p: 90, msg: '正在等待部署'},
            {p: 95, msg: '正在验证部署'}
        ];
        
        var progressInterval = setInterval(function() {
            var elapsed = Math.round((Date.now() - startTime) / 1000);
            if (stage < stages.length && progress < stages[stage].p) {
                progress += 0.3;
                if (progress >= stages[stage].p) {
                    updateProgress($progressBar, $progressText, stages[stage].p, stages[stage].msg, elapsed);
                    stage++;
                } else {
                    $progressBar.css('width', progress + '%').text(Math.round(progress) + '%');
                    $progressText.text($progressText.text().split(' (')[0] + ' (' + elapsed + '秒)');
                }
            } else {
                $progressText.text($progressText.text().split(' (')[0] + ' (' + elapsed + '秒)');
            }
        }, 200);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            timeout: 1800000,
            data: {
                action: 'wptocf_export_and_deploy',
                nonce: '<?php echo wp_create_nonce('wptocf_export_and_deploy'); ?>'
            },
            success: function(response) {
                clearInterval(progressInterval);
                var elapsed = Math.round((Date.now() - startTime) / 1000);
                
                $btn.removeClass('loading').prop('disabled', false);
                $('#wptocf-export-btn').prop('disabled', false);
                $('#wptocf-incremental-deploy-btn').prop('disabled', false);

                if (response.success) {
                    updateProgress($progressBar, $progressText, 100, '部署完成', elapsed);
                    setTimeout(function() {
                        $progress.hide();
                        $result.html(
                            '<div class="wptocf-export-success">' +
                            '<p><strong>全量部署成功！</strong> 用时 ' + elapsed + ' 秒</p>' +
                            '<p>' + response.data.message + '</p>' +
                            '<p>部署ID: <code>' + response.data.deployment_id + '</code></p>' +
                            (response.data.deployment_url ? '<p><a href="' + response.data.deployment_url + '" target="_blank" class="button button-primary">访问部署站点</a></p>' : '') +
                            '</div>'
                        ).show();
                        // 自动刷新包列表和缓存统计
                        refreshPackages();
                        refreshCacheStats();
                    }, 500);
                } else {
                    $progress.hide();
                    $result.html('<div class="wptocf-export-error"><p><strong>部署失败</strong></p><p>' + response.data.message + '</p></div>').show();
                }
            },
            error: function(xhr, status, error) {
                clearInterval(progressInterval);
                $btn.removeClass('loading').prop('disabled', false);
                $('#wptocf-export-btn').prop('disabled', false);
                $('#wptocf-incremental-deploy-btn').prop('disabled', false);
                $progress.hide();
                $result.html('<div class="wptocf-export-error"><p><strong>部署失败</strong></p><p>网络错误: ' + status + '</p></div>').show();
            }
        });
    });

    // 增量上传到 Cloudflare 按钮
    $('#wptocf-incremental-deploy-btn').on('click', function() {
        var $btn = $(this);
        var $result = $('#wptocf-export-result');
        var $progress = $('#wptocf-export-progress');
        var $progressBar = $('#wptocf-export-progress-bar');
        var $progressText = $('#wptocf-export-progress-text');

        if (!confirm('确定要增量上传到 Cloudflare Pages 吗？\n\n增量上传只会上传变化的文件，Cloudflare 会自动跳过已存在的文件。')) {
            return;
        }

        $btn.addClass('loading').prop('disabled', true);
        $('#wptocf-export-btn').prop('disabled', true);
        $('#wptocf-export-deploy-btn').prop('disabled', true);
        $result.hide();
        $progress.show();
        
        // 修改进度条颜色为绿色
        $progressBar.css('background', 'linear-gradient(90deg, #00a32a, #46b450)');
        
        var startTime = Date.now();
        var progress = 0;
        var stage = 0;
        var stages = [
            {p: 5, msg: '正在收集页面URL'},
            {p: 15, msg: '正在抓取页面HTML'},
            {p: 25, msg: '正在提取资源链接'},
            {p: 40, msg: '正在加载CSS、JS、图片'},
            {p: 50, msg: '正在处理路径映射'},
            {p: 60, msg: '正在比对缓存（增量模式）'},
            {p: 70, msg: '正在准备上传'},
            {p: 80, msg: '正在增量上传到Cloudflare'},
            {p: 90, msg: '正在等待部署'},
            {p: 95, msg: '正在验证部署'}
        ];
        
        var progressInterval = setInterval(function() {
            var elapsed = Math.round((Date.now() - startTime) / 1000);
            if (stage < stages.length && progress < stages[stage].p) {
                progress += 0.3;
                if (progress >= stages[stage].p) {
                    updateProgress($progressBar, $progressText, stages[stage].p, stages[stage].msg, elapsed);
                    stage++;
                } else {
                    $progressBar.css('width', progress + '%').text(Math.round(progress) + '%');
                    $progressText.text($progressText.text().split(' (')[0] + ' (' + elapsed + '秒)');
                }
            } else {
                $progressText.text($progressText.text().split(' (')[0] + ' (' + elapsed + '秒)');
            }
        }, 200);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            timeout: 1800000,
            data: {
                action: 'wptocf_incremental_deploy',
                nonce: '<?php echo wp_create_nonce('wptocf_incremental_deploy'); ?>'
            },
            success: function(response) {
                clearInterval(progressInterval);
                var elapsed = Math.round((Date.now() - startTime) / 1000);
                
                $btn.removeClass('loading').prop('disabled', false);
                $('#wptocf-export-btn').prop('disabled', false);
                $('#wptocf-export-deploy-btn').prop('disabled', false);
                
                // 恢复进度条颜色
                $progressBar.css('background', 'linear-gradient(90deg, #2271b1, #72aee6)');

                if (response.success) {
                    updateProgress($progressBar, $progressText, 100, '增量部署完成', elapsed);
                    setTimeout(function() {
                        $progress.hide();
                        $result.html(
                            '<div class="wptocf-export-success" style="border-left-color: #00a32a;">' +
                            '<p><strong>增量部署成功！</strong> 用时 ' + elapsed + ' 秒</p>' +
                            '<p>' + response.data.message + '</p>' +
                            '<p>部署ID: <code>' + response.data.deployment_id + '</code></p>' +
                            (response.data.deployment_url ? '<p><a href="' + response.data.deployment_url + '" target="_blank" class="button button-primary" style="background: #00a32a; border-color: #00a32a;">访问部署站点</a></p>' : '') +
                            '</div>'
                        ).show();
                        // 自动刷新缓存统计
                        refreshCacheStats();
                    }, 500);
                } else {
                    $progress.hide();
                    $result.html('<div class="wptocf-export-error"><p><strong>增量部署失败</strong></p><p>' + response.data.message + '</p></div>').show();
                }
            },
            error: function(xhr, status, error) {
                clearInterval(progressInterval);
                $btn.removeClass('loading').prop('disabled', false);
                $('#wptocf-export-btn').prop('disabled', false);
                $('#wptocf-export-deploy-btn').prop('disabled', false);
                $progressBar.css('background', 'linear-gradient(90deg, #2271b1, #72aee6)');
                $progress.hide();
                $result.html('<div class="wptocf-export-error"><p><strong>增量部署失败</strong></p><p>网络错误: ' + status + '</p></div>').show();
            }
        });
    });

    // 全站静态化按钮
    $('#wptocf-staticize-all-btn').on('click', function() {
        var $btn = $(this);
        var $status = $('#wptocf-staticize-all-status');
        var $result = $('#wptocf-staticize-all-result');

        // 确认操作
        if (!confirm('<?php esc_html_e('确定要静态化全站吗？这可能需要几分钟时间。', 'wp-to-cf'); ?>')) {
            return;
        }

        // 禁用按钮
        $btn.addClass('loading');
        $status.html('<span class="spinner is-active" style="float: none; margin: 0;"></span> <?php esc_html_e('正在启动...', 'wp-to-cf'); ?>');
        $result.hide();

        // 发送 AJAX 请求
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wptocf_staticize_all',
                nonce: '<?php echo wp_create_nonce('wptocf_staticize_all'); ?>'
            },
            success: function(response) {
                $btn.removeClass('loading');
                $status.html('');

                if (response.success) {
                    $result.html(
                        '<div class="wptocf-export-success">' +
                        '<p><strong><?php esc_html_e('任务已启动！', 'wp-to-cf'); ?></strong></p>' +
                        '<p>' + response.data.message + '</p>' +
                        '<p><?php esc_html_e('您可以在 WP Debug 日志中查看处理进度。', 'wp-to-cf'); ?></p>' +
                        '</div>'
                    ).show();
                } else {
                    $result.html(
                        '<div class="wptocf-export-error">' +
                        '<p><strong><?php esc_html_e('启动失败', 'wp-to-cf'); ?></strong></p>' +
                        '<p>' + response.data.message + '</p>' +
                        '</div>'
                    ).show();
                }
            },
            error: function(xhr, status, error) {
                $btn.removeClass('loading');
                $status.html('');
                $result.html(
                    '<div class="wptocf-export-error">' +
                    '<p><strong><?php esc_html_e('启动失败', 'wp-to-cf'); ?></strong></p>' +
                    '<p><?php esc_html_e('网络错误，请重试', 'wp-to-cf'); ?></p>' +
                    '</div>'
                ).show();
            }
        });
    });

    // ========== 包管理 ==========
    
    // 刷新包列表
    function refreshPackages() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wptocf_get_packages',
                nonce: '<?php echo wp_create_nonce('wptocf_manage_packages'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    displayPackages(response.data.packages, response.data.stats);
                }
            }
        });
    }
    
    // 显示包列表
    function displayPackages(packages, stats) {
        var $stats = $('#wptocf-packages-stats');
        var $list = $('#wptocf-packages-list');
        
        // 显示统计信息
        $stats.html(
            '<strong><?php esc_html_e('总计:', 'wp-to-cf'); ?></strong> ' + stats.total_count + ' <?php esc_html_e('个包', 'wp-to-cf'); ?>, ' +
            '<strong><?php esc_html_e('占用空间:', 'wp-to-cf'); ?></strong> ' + stats.total_size_mb + ' MB'
        );
        
        // 显示包列表
        if (packages.length === 0) {
            $list.html('<p><?php esc_html_e('暂无导出的包', 'wp-to-cf'); ?></p>');
            return;
        }
        
        var html = '<table class="widefat" style="margin-top: 10px;">';
        html += '<thead><tr>';
        html += '<th><?php esc_html_e('文件名', 'wp-to-cf'); ?></th>';
        html += '<th><?php esc_html_e('大小', 'wp-to-cf'); ?></th>';
        html += '<th><?php esc_html_e('创建时间', 'wp-to-cf'); ?></th>';
        html += '<th><?php esc_html_e('操作', 'wp-to-cf'); ?></th>';
        html += '</tr></thead><tbody>';
        
        packages.forEach(function(pkg) {
            html += '<tr>';
            html += '<td>' + pkg.filename + '</td>';
            html += '<td>' + pkg.size_mb + ' MB</td>';
            html += '<td>' + pkg.created_formatted + ' (' + pkg.age_days + ' <?php esc_html_e('天前', 'wp-to-cf'); ?>)</td>';
            html += '<td>';
            html += '<a href="' + pkg.url + '" class="button button-small" download><span class="dashicons dashicons-download"></span> <?php esc_html_e('下载', 'wp-to-cf'); ?></a> ';
            html += '<button class="button button-small wptocf-delete-package" data-filename="' + pkg.filename + '"><span class="dashicons dashicons-trash"></span> <?php esc_html_e('删除', 'wp-to-cf'); ?></button>';
            html += '</td>';
            html += '</tr>';
        });
        
        html += '</tbody></table>';
        $list.html(html);
    }
    
    // 刷新包列表按钮
    $('#wptocf-refresh-packages-btn').on('click', function() {
        refreshPackages();
    });
    
    // 清理旧包按钮
    $('#wptocf-cleanup-packages-btn').on('click', function() {
        if (!confirm('<?php esc_html_e('确定要清理旧包吗？将保留最新的5个包。', 'wp-to-cf'); ?>')) {
            return;
        }
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wptocf_cleanup_packages',
                nonce: '<?php echo wp_create_nonce('wptocf_manage_packages'); ?>',
                keep_count: 5
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    refreshPackages();
                } else {
                    alert('<?php esc_html_e('清理失败', 'wp-to-cf'); ?>');
                }
            }
        });
    });
    
    // 删除单个包
    $(document).on('click', '.wptocf-delete-package', function() {
        var filename = $(this).data('filename');
        
        if (!confirm('<?php esc_html_e('确定要删除这个包吗？', 'wp-to-cf'); ?>')) {
            return;
        }
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wptocf_delete_package',
                nonce: '<?php echo wp_create_nonce('wptocf_manage_packages'); ?>',
                filename: filename
            },
            success: function(response) {
                if (response.success) {
                    refreshPackages();
                } else {
                    alert('<?php esc_html_e('删除失败', 'wp-to-cf'); ?>');
                }
            }
        });
    });
    
    // 页面加载时刷新包列表
    refreshPackages();
    
    // ========== 缓存管理 ==========
    
    // 刷新缓存统计
    function refreshCacheStats() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wptocf_get_cache_stats',
                nonce: '<?php echo wp_create_nonce('wptocf_manage_cache'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    displayCacheStats(response.data.stats);
                }
            }
        });
    }
    
    // 显示缓存统计
    function displayCacheStats(stats) {
        var $stats = $('#wptocf-cache-stats');
        var $incrementalBtn = $('#wptocf-incremental-deploy-btn');
        var $noCacheWarning = $('#wptocf-no-cache-warning');
        
        // 更新增量上传按钮状态
        var hasCache = stats.export_cache_count > 0;
        if (hasCache) {
            $incrementalBtn.prop('disabled', false);
            $noCacheWarning.hide();
        } else {
            $incrementalBtn.prop('disabled', true);
            if ($noCacheWarning.length === 0) {
                $incrementalBtn.after('<span id="wptocf-no-cache-warning" style="margin-left: 10px; color: #dba617; font-size: 12px;"><span class="dashicons dashicons-info" style="font-size: 14px; vertical-align: middle;"></span> <?php esc_html_e('请先执行全量上传以建立缓存', 'wp-to-cf'); ?></span>');
            } else {
                $noCacheWarning.show();
            }
        }
        
        var html = '<table class="widefat">';
        html += '<thead><tr>';
        html += '<th><?php esc_html_e('类型', 'wp-to-cf'); ?></th>';
        html += '<th><?php esc_html_e('文件数', 'wp-to-cf'); ?></th>';
        html += '<th><?php esc_html_e('大小', 'wp-to-cf'); ?></th>';
        html += '<th><?php esc_html_e('操作', 'wp-to-cf'); ?></th>';
        html += '</tr></thead><tbody>';
        
        // 导出缓存（哈希缓存）- 显示明细
        var exportLastTime = stats.export_cache_last > 0 ? new Date(stats.export_cache_last * 1000).toLocaleString() : '<?php esc_html_e('从未', 'wp-to-cf'); ?>';
        html += '<tr style="background: #f0f6fc;">';
        html += '<td colspan="4"><strong><?php esc_html_e('导出缓存', 'wp-to-cf'); ?></strong> <small style="color:#666;">(<?php esc_html_e('上次导出', 'wp-to-cf'); ?>: ' + exportLastTime + ')</small></td>';
        html += '</tr>';
        
        // HTML
        html += '<tr style="background: #f9f9f9;">';
        html += '<td style="padding-left: 20px;">HTML</td>';
        html += '<td>' + (stats.export_html_count || 0) + '</td>';
        html += '<td>' + (stats.export_html_size_mb || 0) + ' MB</td>';
        html += '<td></td>';
        html += '</tr>';
        
        // CSS
        html += '<tr style="background: #f9f9f9;">';
        html += '<td style="padding-left: 20px;">CSS</td>';
        html += '<td>' + (stats.export_css_count || 0) + '</td>';
        html += '<td>' + (stats.export_css_size_mb || 0) + ' MB</td>';
        html += '<td></td>';
        html += '</tr>';
        
        // JS
        html += '<tr style="background: #f9f9f9;">';
        html += '<td style="padding-left: 20px;">JavaScript</td>';
        html += '<td>' + (stats.export_js_count || 0) + '</td>';
        html += '<td>' + (stats.export_js_size_mb || 0) + ' MB</td>';
        html += '<td></td>';
        html += '</tr>';
        
        // 图片
        html += '<tr style="background: #f9f9f9;">';
        html += '<td style="padding-left: 20px;"><?php esc_html_e('图片', 'wp-to-cf'); ?></td>';
        html += '<td>' + (stats.export_images_count || 0) + '</td>';
        html += '<td>' + (stats.export_images_size_mb || 0) + ' MB</td>';
        html += '<td></td>';
        html += '</tr>';
        
        // 字体
        html += '<tr style="background: #f9f9f9;">';
        html += '<td style="padding-left: 20px;"><?php esc_html_e('字体', 'wp-to-cf'); ?></td>';
        html += '<td>' + (stats.export_fonts_count || 0) + '</td>';
        html += '<td>' + (stats.export_fonts_size_mb || 0) + ' MB</td>';
        html += '<td></td>';
        html += '</tr>';
        
        // 其他
        if (stats.export_other_count > 0) {
            html += '<tr style="background: #f9f9f9;">';
            html += '<td style="padding-left: 20px;"><?php esc_html_e('其他', 'wp-to-cf'); ?></td>';
            html += '<td>' + stats.export_other_count + '</td>';
            html += '<td>' + (stats.export_other_size_mb || 0) + ' MB</td>';
            html += '<td></td>';
            html += '</tr>';
        }
        
        // 导出缓存小计
        html += '<tr style="background: #e8f4e8;">';
        html += '<td style="padding-left: 20px;"><strong><?php esc_html_e('小计', 'wp-to-cf'); ?></strong></td>';
        html += '<td><strong>' + stats.export_cache_count + '</strong></td>';
        html += '<td><strong>' + stats.export_cache_size_mb + ' MB</strong></td>';
        html += '<td></td>';
        html += '</tr>';
        
        html += '<tr style="background: #2271b1; color: white; font-weight: bold;">';
        html += '<td><?php esc_html_e('总计', 'wp-to-cf'); ?></td>';
        html += '<td>' + stats.export_cache_count + ' <?php esc_html_e('文件', 'wp-to-cf'); ?></td>';
        html += '<td>' + stats.export_cache_size_mb + ' MB</td>';
        html += '<td></td>';
        html += '</tr>';
        
        html += '</tbody></table>';
        $stats.html(html);
    }
    
    // 刷新缓存统计按钮
    $('#wptocf-refresh-cache-btn').on('click', function() {
        refreshCacheStats();
    });
    
    // 清理所有缓存按钮
    $('#wptocf-clear-cache-all-btn').on('click', function() {
        if (!confirm('<?php esc_html_e('确定要清理所有缓存吗？', 'wp-to-cf'); ?>')) {
            return;
        }
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wptocf_clear_cache',
                nonce: '<?php echo wp_create_nonce('wptocf_manage_cache'); ?>',
                type: 'all'
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    refreshCacheStats();
                } else {
                    alert('<?php esc_html_e('清理失败', 'wp-to-cf'); ?>');
                }
            }
        });
    });
    
    // 页面加载时刷新缓存统计
    refreshCacheStats();
    
    // ========== Cloudflare 凭证验证 ==========
    
    // 项目列表数据
    var projectList = [];
    
    // Combobox 功能
    var $combobox = $('#wptocf-project-combobox');
    var $input = $('#wptocf_project_name');
    var $dropdown = $('#wptocf_project_dropdown');
    var $arrow = $combobox.find('.wptocf-combobox-arrow');
    
    // 渲染下拉列表
    function renderDropdown(filter) {
        $dropdown.empty();
        var filtered = projectList;
        if (filter) {
            filter = filter.toLowerCase();
            filtered = projectList.filter(function(p) {
                return p.name.toLowerCase().indexOf(filter) !== -1;
            });
        }
        if (filtered.length === 0) {
            $combobox.removeClass('open');
            return;
        }
        filtered.forEach(function(project) {
            $dropdown.append('<li data-value="' + project.name + '">' + project.name + '</li>');
        });
        $combobox.addClass('open');
    }
    
    // 点击箭头切换下拉
    $arrow.on('click', function(e) {
        e.stopPropagation();
        if ($combobox.hasClass('open')) {
            $combobox.removeClass('open');
        } else if (projectList.length > 0) {
            renderDropdown('');
        }
    });
    
    // 输入时过滤
    $input.on('input', function() {
        if (projectList.length > 0) {
            renderDropdown($(this).val());
        }
    });
    
    // 聚焦时显示下拉
    $input.on('focus', function() {
        if (projectList.length > 0 && !$combobox.hasClass('open')) {
            renderDropdown($(this).val());
        }
    });
    
    // 选择项目
    $dropdown.on('click', 'li', function() {
        $input.val($(this).data('value'));
        $combobox.removeClass('open');
    });
    
    // 点击外部关闭下拉
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.wptocf-combobox').length) {
            $combobox.removeClass('open');
        }
    });
    
    // 验证凭证按钮
    $('#wptocf-validate-btn').on('click', function() {
        var $btn = $(this);
        var $status = $('#wptocf-validate-status');
        var accountId = $('#wptocf_account_id').val();
        var apiToken = $('#wptocf_api_token').val();
        
        if (!accountId || !apiToken) {
            $status.html('<span style="color: #d63638;"><?php esc_html_e('请先填写 Account ID 和 API Token', 'wp-to-cf'); ?></span>');
            return;
        }
        
        $btn.prop('disabled', true);
        $status.html('<span class="spinner is-active" style="float: none; margin: 0;"></span> <?php esc_html_e('验证中...', 'wp-to-cf'); ?>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wptocf_validate_credentials',
                nonce: '<?php echo wp_create_nonce('wptocf_validate_credentials'); ?>',
                account_id: accountId,
                api_token: apiToken
            },
            success: function(response) {
                $btn.prop('disabled', false);
                
                if (response.success) {
                    $status.html('<span style="color: #00a32a;"><span class="dashicons dashicons-yes"></span> ' + response.data.message + '</span>');
                    
                    // 保存项目列表
                    projectList = response.data.projects || [];
                    
                } else {
                    $status.html('<span style="color: #d63638;"><span class="dashicons dashicons-no"></span> ' + response.data.message + '</span>');
                }
            },
            error: function() {
                $btn.prop('disabled', false);
                $status.html('<span style="color: #d63638;"><?php esc_html_e('网络错误', 'wp-to-cf'); ?></span>');
            }
        });
    });
});
</script>

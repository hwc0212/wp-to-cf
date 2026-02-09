<?php
/**
 * Tab: 导出与部署
 */
if (!defined('ABSPATH')) exit;

$cf_api = new WP_to_CF_Cloudflare_API();
$cf_configured = $cf_api->is_configured();
$export_cache_data = get_option('wptocf_export_cache', null);
$has_export_cache = $export_cache_data && isset($export_cache_data['files']) && count($export_cache_data['files']) > 0;
?>

<!-- 一键静态化全站面板 -->
<div class="wptocf-panel blue">
    <h2><span class="dashicons dashicons-update"></span> <?php esc_html_e('一键静态化全站', 'wp-to-cf'); ?></h2>
    <p><?php esc_html_e('将整个站点静态化并打包为 ZIP 文件，可下载或直接上传到 Cloudflare Pages。', 'wp-to-cf'); ?></p>
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
        <strong><?php esc_html_e('增量上传', 'wp-to-cf'); ?>:</strong> <?php esc_html_e('只上传变化的文件（更快）', 'wp-to-cf'); ?>
    </p>
    <!-- 进度条 -->
    <div id="wptocf-export-progress" style="display: none; margin-top: 15px;">
        <div style="background: #f0f0f0; border-radius: 4px; height: 30px; position: relative; overflow: hidden;">
            <div id="wptocf-export-progress-bar" style="background: linear-gradient(90deg, #2271b1, #72aee6); height: 100%; width: 0%; transition: width 0.3s ease; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 12px;">0%</div>
        </div>
        <div id="wptocf-export-progress-text" style="margin-top: 8px; font-size: 13px; color: #666;"><?php esc_html_e('准备中...', 'wp-to-cf'); ?></div>
    </div>
    <div id="wptocf-export-result" style="margin-top: 15px; display: none;"></div>
</div>

<!-- 包管理面板 -->
<div class="wptocf-panel cyan">
    <h2><span class="dashicons dashicons-archive"></span> <?php esc_html_e('包管理', 'wp-to-cf'); ?></h2>
    <p><?php esc_html_e('管理已导出的 ZIP 包，查看历史记录，删除旧文件。', 'wp-to-cf'); ?></p>
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
<div class="wptocf-panel yellow">
    <h2><span class="dashicons dashicons-database"></span> <?php esc_html_e('缓存管理', 'wp-to-cf'); ?></h2>
    <p><?php esc_html_e('管理资产缓存（CSS、JS、图片、字体），查看缓存大小，清理缓存。', 'wp-to-cf'); ?></p>
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

<script>
jQuery(document).ready(function($) {
    function updateProgress($bar, $text, percent, message, elapsed) {
        $bar.css('width', percent + '%').text(Math.round(percent) + '%');
        $text.text(message + (elapsed ? ' (' + elapsed + '秒)' : ''));
    }

    // ZIP 导出
    $('#wptocf-export-btn').on('click', function() {
        var $btn = $(this), $result = $('#wptocf-export-result'), $progress = $('#wptocf-export-progress');
        var $progressBar = $('#wptocf-export-progress-bar'), $progressText = $('#wptocf-export-progress-text');
        $btn.addClass('loading').prop('disabled', true);
        $result.hide();
        $progress.show();
        var startTime = Date.now(), progress = 0, stage = 0;
        var stages = [{p:10,msg:'正在收集页面URL'},{p:25,msg:'正在抓取页面HTML'},{p:40,msg:'正在提取资源链接'},{p:55,msg:'正在加载CSS、JS、图片'},{p:70,msg:'正在处理路径映射'},{p:85,msg:'正在创建ZIP包'},{p:95,msg:'正在完成'}];
        var progressInterval = setInterval(function() {
            var elapsed = Math.round((Date.now() - startTime) / 1000);
            if (stage < stages.length && progress < stages[stage].p) {
                progress += 0.5;
                if (progress >= stages[stage].p) { updateProgress($progressBar, $progressText, stages[stage].p, stages[stage].msg, elapsed); stage++; }
                else { $progressBar.css('width', progress + '%').text(Math.round(progress) + '%'); }
            }
        }, 200);
        $.ajax({
            url: ajaxurl, type: 'POST', timeout: 1800000,
            data: { action: 'wptocf_export_site', nonce: '<?php echo wp_create_nonce('wptocf_export_site'); ?>' },
            success: function(response) {
                clearInterval(progressInterval);
                var elapsed = Math.round((Date.now() - startTime) / 1000);
                if (response.success) {
                    updateProgress($progressBar, $progressText, 100, '导出完成', elapsed);
                    setTimeout(function() {
                        $btn.removeClass('loading').prop('disabled', false);
                        $progress.hide();
                        $result.html('<div class="wptocf-export-success"><p><strong>导出成功！</strong> 用时 ' + elapsed + ' 秒</p><p>' + response.data.message + '</p><p><a href="' + response.data.zip_url + '" class="button button-primary" download>下载 ZIP 文件</a></p></div>').show();
                        refreshPackages();
                    }, 500);
                } else {
                    $btn.removeClass('loading').prop('disabled', false);
                    $progress.hide();
                    $result.html('<div class="wptocf-export-error"><p><strong>导出失败</strong></p><p>' + response.data.message + '</p></div>').show();
                }
            },
            error: function(xhr, status) {
                clearInterval(progressInterval);
                $btn.removeClass('loading').prop('disabled', false);
                $progress.hide();
                $result.html('<div class="wptocf-export-error"><p><strong>导出失败</strong></p><p>网络错误: ' + status + '</p></div>').show();
            }
        });
    });

    // 全量上传
    $('#wptocf-export-deploy-btn').on('click', function() {
        var $btn = $(this), $result = $('#wptocf-export-result'), $progress = $('#wptocf-export-progress');
        var $progressBar = $('#wptocf-export-progress-bar'), $progressText = $('#wptocf-export-progress-text');
        if (!confirm('确定要全量上传到 Cloudflare Pages 吗？')) return;
        $btn.addClass('loading').prop('disabled', true);
        $('#wptocf-export-btn, #wptocf-incremental-deploy-btn').prop('disabled', true);
        $result.hide(); $progress.show();
        var startTime = Date.now(), progress = 0, stage = 0;
        var stages = [{p:5,msg:'正在收集页面URL'},{p:15,msg:'正在抓取页面HTML'},{p:25,msg:'正在提取资源链接'},{p:40,msg:'正在加载CSS、JS、图片'},{p:50,msg:'正在处理路径映射'},{p:60,msg:'正在创建ZIP包'},{p:70,msg:'正在准备上传'},{p:80,msg:'正在上传到Cloudflare'},{p:90,msg:'正在等待部署'},{p:95,msg:'正在验证部署'}];
        var progressInterval = setInterval(function() {
            var elapsed = Math.round((Date.now() - startTime) / 1000);
            if (stage < stages.length && progress < stages[stage].p) {
                progress += 0.3;
                if (progress >= stages[stage].p) { updateProgress($progressBar, $progressText, stages[stage].p, stages[stage].msg, elapsed); stage++; }
            }
        }, 200);
        $.ajax({
            url: ajaxurl, type: 'POST', timeout: 1800000,
            data: { action: 'wptocf_export_and_deploy', nonce: '<?php echo wp_create_nonce('wptocf_export_and_deploy'); ?>' },
            success: function(response) {
                clearInterval(progressInterval);
                var elapsed = Math.round((Date.now() - startTime) / 1000);
                $btn.removeClass('loading').prop('disabled', false);
                $('#wptocf-export-btn, #wptocf-incremental-deploy-btn').prop('disabled', false);
                if (response.success) {
                    updateProgress($progressBar, $progressText, 100, '部署完成', elapsed);
                    setTimeout(function() {
                        $progress.hide();
                        $result.html('<div class="wptocf-export-success"><p><strong>全量部署成功！</strong> 用时 ' + elapsed + ' 秒</p><p>' + response.data.message + '</p><p>部署ID: <code>' + response.data.deployment_id + '</code></p>' + (response.data.deployment_url ? '<p><a href="' + response.data.deployment_url + '" target="_blank" class="button button-primary">访问部署站点</a></p>' : '') + '</div>').show();
                        refreshPackages(); refreshCacheStats();
                    }, 500);
                } else {
                    $progress.hide();
                    $result.html('<div class="wptocf-export-error"><p><strong>部署失败</strong></p><p>' + response.data.message + '</p></div>').show();
                }
            },
            error: function(xhr, status) {
                clearInterval(progressInterval);
                $btn.removeClass('loading').prop('disabled', false);
                $('#wptocf-export-btn, #wptocf-incremental-deploy-btn').prop('disabled', false);
                $progress.hide();
                $result.html('<div class="wptocf-export-error"><p><strong>部署失败</strong></p><p>网络错误: ' + status + '</p></div>').show();
            }
        });
    });

    // 增量上传
    $('#wptocf-incremental-deploy-btn').on('click', function() {
        var $btn = $(this), $result = $('#wptocf-export-result'), $progress = $('#wptocf-export-progress');
        var $progressBar = $('#wptocf-export-progress-bar'), $progressText = $('#wptocf-export-progress-text');
        if (!confirm('确定要增量上传到 Cloudflare Pages 吗？')) return;
        $btn.addClass('loading').prop('disabled', true);
        $('#wptocf-export-btn, #wptocf-export-deploy-btn').prop('disabled', true);
        $result.hide(); $progress.show();
        $progressBar.css('background', 'linear-gradient(90deg, #00a32a, #46b450)');
        var startTime = Date.now(), progress = 0, stage = 0;
        var stages = [{p:5,msg:'正在收集页面URL'},{p:15,msg:'正在抓取页面HTML'},{p:25,msg:'正在提取资源链接'},{p:40,msg:'正在加载CSS、JS、图片'},{p:50,msg:'正在处理路径映射'},{p:60,msg:'正在比对缓存'},{p:70,msg:'正在准备上传'},{p:80,msg:'正在增量上传'},{p:90,msg:'正在等待部署'},{p:95,msg:'正在验证部署'}];
        var progressInterval = setInterval(function() {
            var elapsed = Math.round((Date.now() - startTime) / 1000);
            if (stage < stages.length && progress < stages[stage].p) {
                progress += 0.3;
                if (progress >= stages[stage].p) { updateProgress($progressBar, $progressText, stages[stage].p, stages[stage].msg, elapsed); stage++; }
            }
        }, 200);
        $.ajax({
            url: ajaxurl, type: 'POST', timeout: 1800000,
            data: { action: 'wptocf_incremental_deploy', nonce: '<?php echo wp_create_nonce('wptocf_incremental_deploy'); ?>' },
            success: function(response) {
                clearInterval(progressInterval);
                var elapsed = Math.round((Date.now() - startTime) / 1000);
                $btn.removeClass('loading').prop('disabled', false);
                $('#wptocf-export-btn, #wptocf-export-deploy-btn').prop('disabled', false);
                $progressBar.css('background', 'linear-gradient(90deg, #2271b1, #72aee6)');
                if (response.success) {
                    updateProgress($progressBar, $progressText, 100, '增量部署完成', elapsed);
                    setTimeout(function() {
                        $progress.hide();
                        $result.html('<div class="wptocf-export-success" style="border-left-color:#00a32a;"><p><strong>增量部署成功！</strong> 用时 ' + elapsed + ' 秒</p><p>' + response.data.message + '</p><p>部署ID: <code>' + response.data.deployment_id + '</code></p>' + (response.data.deployment_url ? '<p><a href="' + response.data.deployment_url + '" target="_blank" class="button button-primary" style="background:#00a32a;border-color:#00a32a;">访问部署站点</a></p>' : '') + '</div>').show();
                        refreshCacheStats();
                    }, 500);
                } else {
                    $progress.hide();
                    $result.html('<div class="wptocf-export-error"><p><strong>增量部署失败</strong></p><p>' + response.data.message + '</p></div>').show();
                }
            },
            error: function(xhr, status) {
                clearInterval(progressInterval);
                $btn.removeClass('loading').prop('disabled', false);
                $('#wptocf-export-btn, #wptocf-export-deploy-btn').prop('disabled', false);
                $progressBar.css('background', 'linear-gradient(90deg, #2271b1, #72aee6)');
                $progress.hide();
                $result.html('<div class="wptocf-export-error"><p><strong>增量部署失败</strong></p><p>网络错误: ' + status + '</p></div>').show();
            }
        });
    });

    // 包管理
    function refreshPackages() {
        $.ajax({
            url: ajaxurl, type: 'POST',
            data: { action: 'wptocf_get_packages', nonce: '<?php echo wp_create_nonce('wptocf_manage_packages'); ?>' },
            success: function(response) {
                if (response.success) displayPackages(response.data.packages, response.data.stats);
            }
        });
    }
    
    function displayPackages(packages, stats) {
        var $stats = $('#wptocf-packages-stats'), $list = $('#wptocf-packages-list');
        $stats.html('<strong><?php esc_html_e('总计:', 'wp-to-cf'); ?></strong> ' + stats.total_count + ' <?php esc_html_e('个包', 'wp-to-cf'); ?>, <strong><?php esc_html_e('占用空间:', 'wp-to-cf'); ?></strong> ' + stats.total_size_mb + ' MB');
        if (packages.length === 0) { $list.html('<p><?php esc_html_e('暂无导出的包', 'wp-to-cf'); ?></p>'); return; }
        var html = '<table class="widefat" style="margin-top:10px;"><thead><tr><th><?php esc_html_e('文件名', 'wp-to-cf'); ?></th><th><?php esc_html_e('大小', 'wp-to-cf'); ?></th><th><?php esc_html_e('创建时间', 'wp-to-cf'); ?></th><th><?php esc_html_e('操作', 'wp-to-cf'); ?></th></tr></thead><tbody>';
        packages.forEach(function(pkg) {
            html += '<tr><td>' + pkg.filename + '</td><td>' + pkg.size_mb + ' MB</td><td>' + pkg.created_formatted + ' (' + pkg.age_days + ' <?php esc_html_e('天前', 'wp-to-cf'); ?>)</td><td><a href="' + pkg.url + '" class="button button-small" download><span class="dashicons dashicons-download"></span></a> <button class="button button-small wptocf-delete-package" data-filename="' + pkg.filename + '"><span class="dashicons dashicons-trash"></span></button></td></tr>';
        });
        html += '</tbody></table>';
        $list.html(html);
    }
    
    $('#wptocf-refresh-packages-btn').on('click', refreshPackages);
    
    $('#wptocf-cleanup-packages-btn').on('click', function() {
        if (!confirm('<?php esc_html_e('确定要清理旧包吗？将保留最新的5个包。', 'wp-to-cf'); ?>')) return;
        $.ajax({
            url: ajaxurl, type: 'POST',
            data: { action: 'wptocf_cleanup_packages', nonce: '<?php echo wp_create_nonce('wptocf_manage_packages'); ?>', keep_count: 5 },
            success: function(response) { if (response.success) { alert(response.data.message); refreshPackages(); } }
        });
    });
    
    $(document).on('click', '.wptocf-delete-package', function() {
        var filename = $(this).data('filename');
        if (!confirm('<?php esc_html_e('确定要删除这个包吗？', 'wp-to-cf'); ?>')) return;
        $.ajax({
            url: ajaxurl, type: 'POST',
            data: { action: 'wptocf_delete_package', nonce: '<?php echo wp_create_nonce('wptocf_manage_packages'); ?>', filename: filename },
            success: function(response) { if (response.success) refreshPackages(); }
        });
    });
    
    // 缓存管理
    function refreshCacheStats() {
        $.ajax({
            url: ajaxurl, type: 'POST',
            data: { action: 'wptocf_get_cache_stats', nonce: '<?php echo wp_create_nonce('wptocf_manage_cache'); ?>' },
            success: function(response) { if (response.success) displayCacheStats(response.data.stats); }
        });
    }
    
    function displayCacheStats(stats) {
        var $stats = $('#wptocf-cache-stats');
        var hasCache = stats.export_cache_count > 0;
        $('#wptocf-incremental-deploy-btn').prop('disabled', !hasCache);
        var exportLastTime = stats.export_cache_last > 0 ? new Date(stats.export_cache_last * 1000).toLocaleString() : '<?php esc_html_e('从未', 'wp-to-cf'); ?>';
        var html = '<table class="widefat"><thead><tr><th><?php esc_html_e('类型', 'wp-to-cf'); ?></th><th><?php esc_html_e('文件数', 'wp-to-cf'); ?></th><th><?php esc_html_e('大小', 'wp-to-cf'); ?></th></tr></thead><tbody>';
        html += '<tr style="background:#f0f6fc;"><td colspan="3"><strong><?php esc_html_e('导出缓存', 'wp-to-cf'); ?></strong> <small style="color:#666;">(<?php esc_html_e('上次导出', 'wp-to-cf'); ?>: ' + exportLastTime + ')</small></td></tr>';
        html += '<tr style="background:#f9f9f9;"><td style="padding-left:20px;">HTML</td><td>' + (stats.export_html_count||0) + '</td><td>' + (stats.export_html_size_mb||0) + ' MB</td></tr>';
        html += '<tr style="background:#f9f9f9;"><td style="padding-left:20px;">CSS</td><td>' + (stats.export_css_count||0) + '</td><td>' + (stats.export_css_size_mb||0) + ' MB</td></tr>';
        html += '<tr style="background:#f9f9f9;"><td style="padding-left:20px;">JavaScript</td><td>' + (stats.export_js_count||0) + '</td><td>' + (stats.export_js_size_mb||0) + ' MB</td></tr>';
        html += '<tr style="background:#f9f9f9;"><td style="padding-left:20px;"><?php esc_html_e('图片', 'wp-to-cf'); ?></td><td>' + (stats.export_images_count||0) + '</td><td>' + (stats.export_images_size_mb||0) + ' MB</td></tr>';
        html += '<tr style="background:#f9f9f9;"><td style="padding-left:20px;"><?php esc_html_e('字体', 'wp-to-cf'); ?></td><td>' + (stats.export_fonts_count||0) + '</td><td>' + (stats.export_fonts_size_mb||0) + ' MB</td></tr>';
        html += '<tr style="background:#e8f4e8;"><td style="padding-left:20px;"><strong><?php esc_html_e('小计', 'wp-to-cf'); ?></strong></td><td><strong>' + stats.export_cache_count + '</strong></td><td><strong>' + stats.export_cache_size_mb + ' MB</strong></td></tr>';
        html += '</tbody></table>';
        $stats.html(html);
    }
    
    $('#wptocf-refresh-cache-btn').on('click', refreshCacheStats);
    
    $('#wptocf-clear-cache-all-btn').on('click', function() {
        if (!confirm('<?php esc_html_e('确定要清理所有缓存吗？', 'wp-to-cf'); ?>')) return;
        $.ajax({
            url: ajaxurl, type: 'POST',
            data: { action: 'wptocf_clear_cache', nonce: '<?php echo wp_create_nonce('wptocf_manage_cache'); ?>', type: 'all' },
            success: function(response) { if (response.success) { alert(response.data.message); refreshCacheStats(); } }
        });
    });
    
    // 初始化
    refreshPackages();
    refreshCacheStats();
});
</script>

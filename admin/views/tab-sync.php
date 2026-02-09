<?php
/**
 * Tab: 评论同步
 */
if (!defined('ABSPATH')) exit;

$comment_service = get_option('wptocf_comment_service', '');
$comment_endpoint = get_option('wptocf_comment_endpoint', '');
?>

<form method="post" action="options.php">
    <?php settings_fields('wptocf_settings'); ?>
    
    <div class="wptocf-panel green">
        <h2><span class="dashicons dashicons-admin-comments"></span> <?php esc_html_e('评论同步', 'wp-to-cf'); ?></h2>
        
        <!-- 功能说明 -->
        <div class="wptocf-warning-box">
            <p style="margin: 0 0 8px 0;"><span class="dashicons dashicons-info" style="color: #2271b1;"></span> <strong><?php esc_html_e('功能说明', 'wp-to-cf'); ?></strong></p>
            <ul style="margin: 0; padding-left: 20px; font-size: 13px; color: #666;">
                <li><?php esc_html_e('静态站点的评论表单会提交到第三方服务', 'wp-to-cf'); ?></li>
                <li><?php esc_html_e('WordPress 定时从第三方服务拉取评论数据并导入', 'wp-to-cf'); ?></li>
                <li><?php esc_html_e('导入的评论默认为待审核状态', 'wp-to-cf'); ?></li>
            </ul>
        </div>
        
        <h3 style="margin-top: 20px;"><?php esc_html_e('评论表单配置', 'wp-to-cf'); ?></h3>
        
        <table class="form-table">
            <tr>
                <th><label for="wptocf_comment_service"><?php esc_html_e('服务类型', 'wp-to-cf'); ?></label></th>
                <td>
                    <select id="wptocf_comment_service" name="wptocf_comment_service" class="regular-text">
                        <option value=""><?php esc_html_e('-- 未配置 --', 'wp-to-cf'); ?></option>
                        <option value="getform" <?php selected($comment_service, 'getform'); ?>>Getform (Forminit)</option>
                        <option value="cfform" <?php selected($comment_service, 'cfform'); ?>>form.huwencai.com</option>
                    </select>
                    <p class="description"><?php esc_html_e('选择评论表单使用的服务', 'wp-to-cf'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="wptocf_comment_endpoint"><?php esc_html_e('服务端点', 'wp-to-cf'); ?></label></th>
                <td>
                    <input type="url" id="wptocf_comment_endpoint" name="wptocf_comment_endpoint" 
                           value="<?php echo esc_attr($comment_endpoint); ?>" 
                           class="regular-text" placeholder="https://getform.io/f/xxxxx">
                    <p class="description" id="wptocf-endpoint-hint"></p>
                </td>
            </tr>
        </table>
        
        <h3 style="margin-top: 30px;"><?php esc_html_e('API 配置（用于数据回流）', 'wp-to-cf'); ?></h3>
        
        <div id="wptocf-getform-config" style="display: none;">
            <table class="form-table">
                <tr>
                    <th><label for="wptocf_getform_api_token"><?php esc_html_e('Forminit API Token', 'wp-to-cf'); ?></label></th>
                    <td>
                        <input type="password" id="wptocf_getform_api_token" name="wptocf_getform_api_token" 
                               value="<?php echo esc_attr(get_option('wptocf_getform_api_token', '')); ?>" 
                               class="regular-text" placeholder="fi_your_secret_api_key">
                        <p class="description">
                            <?php esc_html_e('登录 Forminit → 右上角头像 → Account → API Tokens → Create Token', 'wp-to-cf'); ?>
                            <a href="https://app.getform.io/" target="_blank"><?php esc_html_e('打开控制台', 'wp-to-cf'); ?></a>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        
        <div id="wptocf-cfform-config" style="display: none;">
            <table class="form-table">
                <tr>
                    <th><label for="wptocf_cfform_api_key"><?php esc_html_e('form.huwencai.com API Key', 'wp-to-cf'); ?></label></th>
                    <td>
                        <input type="password" id="wptocf_cfform_api_key" name="wptocf_cfform_api_key" 
                               value="<?php echo esc_attr(get_option('wptocf_cfform_api_key', '')); ?>" 
                               class="regular-text" placeholder="cffs_xxxxx">
                        <p class="description">
                            <?php esc_html_e('创建表单时生成的 API Key', 'wp-to-cf'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        
        <table class="form-table">
            <tr>
                <th><label for="wptocf_form_notify_email"><?php esc_html_e('通知邮箱', 'wp-to-cf'); ?></label></th>
                <td>
                    <input type="email" id="wptocf_form_notify_email" name="wptocf_form_notify_email" 
                           value="<?php echo esc_attr(get_option('wptocf_form_notify_email', get_option('admin_email'))); ?>" 
                           class="regular-text">
                    <p class="description"><?php esc_html_e('新评论同步后发送通知到此邮箱', 'wp-to-cf'); ?></p>
                </td>
            </tr>
        </table>
        
        <?php submit_button(__('保存设置', 'wp-to-cf')); ?>
        
        <hr style="margin: 20px 0;">
        
        <h3><?php esc_html_e('同步操作', 'wp-to-cf'); ?></h3>
        <p>
            <button type="button" id="wptocf-sync-btn" class="button button-primary">
                <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
                <?php esc_html_e('立即同步', 'wp-to-cf'); ?>
            </button>
            <span id="wptocf-sync-status" style="margin-left: 10px;"></span>
        </p>
        
        <!-- 同步统计 -->
        <div id="wptocf-sync-stats" style="margin-top: 15px; background: #f9f9f9; padding: 15px; border-radius: 4px;">
            <h4 style="margin-top: 0;"><?php esc_html_e('同步统计', 'wp-to-cf'); ?></h4>
            <div id="wptocf-sync-stats-content"><?php esc_html_e('加载中...', 'wp-to-cf'); ?></div>
        </div>
    </div>
</form>

<script>
jQuery(document).ready(function($) {
    var formNonce = '<?php echo wp_create_nonce('wptocf_ajax'); ?>';
    
    var endpointHints = {
        'getform': '<?php esc_html_e('在 Getform 创建表单后获取，格式: https://getform.io/f/xxxxx', 'wp-to-cf'); ?>',
        'cfform': '<?php esc_html_e('在 huwencai.com/getform 申请后获取，格式: https://form.huwencai.com/f/xxxxx', 'wp-to-cf'); ?>'
    };
    
    function updateServiceUI() {
        var service = $('#wptocf_comment_service').val();
        $('#wptocf-getform-config').hide();
        $('#wptocf-cfform-config').hide();
        
        if (service === 'getform') {
            $('#wptocf-getform-config').show();
        } else if (service === 'cfform') {
            $('#wptocf-cfform-config').show();
        }
        
        $('#wptocf-endpoint-hint').text(endpointHints[service] || '');
    }
    
    $('#wptocf_comment_service').on('change', updateServiceUI);
    updateServiceUI();
    
    function loadSyncStats() {
        $.ajax({
            url: ajaxurl, type: 'POST',
            data: { action: 'wptocf_get_sync_stats', nonce: formNonce },
            success: function(response) {
                if (response.success) {
                    var stats = response.data.stats;
                    var html = '<table style="width:100%;">';
                    html += '<tr><td><?php esc_html_e('总提交数', 'wp-to-cf'); ?></td><td><strong>' + stats.total + '</strong></td></tr>';
                    html += '<tr><td><?php esc_html_e('待处理', 'wp-to-cf'); ?></td><td><strong>' + stats.pending + '</strong></td></tr>';
                    html += '<tr><td><?php esc_html_e('已处理', 'wp-to-cf'); ?></td><td><strong>' + stats.processed + '</strong></td></tr>';
                    html += '<tr><td><?php esc_html_e('已导入评论', 'wp-to-cf'); ?></td><td><strong>' + stats.comments_imported + '</strong></td></tr>';
                    html += '<tr><td><?php esc_html_e('上次同步', 'wp-to-cf'); ?></td><td>' + (stats.last_sync || '<?php esc_html_e('从未', 'wp-to-cf'); ?>') + '</td></tr>';
                    html += '</table>';
                    $('#wptocf-sync-stats-content').html(html);
                }
            }
        });
    }
    
    $('#wptocf-sync-btn').on('click', function() {
        var $btn = $(this), $status = $('#wptocf-sync-status');
        $btn.prop('disabled', true);
        $status.html('<span class="spinner is-active" style="float: none; margin: 0;"></span> <?php esc_html_e('同步中...', 'wp-to-cf'); ?>');
        $.ajax({
            url: ajaxurl, type: 'POST',
            data: { action: 'wptocf_sync_submissions', nonce: formNonce },
            success: function(response) {
                $btn.prop('disabled', false);
                if (response.success) {
                    $status.html('<span style="color: #00a32a;"><span class="dashicons dashicons-yes"></span> ' + response.data.message + '</span>');
                    loadSyncStats();
                } else {
                    $status.html('<span style="color: #d63638;"><span class="dashicons dashicons-no"></span> ' + (response.data.message || '<?php esc_html_e('同步失败', 'wp-to-cf'); ?>') + '</span>');
                }
            },
            error: function() {
                $btn.prop('disabled', false);
                $status.html('<span style="color: #d63638;"><?php esc_html_e('网络错误', 'wp-to-cf'); ?></span>');
            }
        });
    });
    
    loadSyncStats();
});
</script>

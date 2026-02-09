<?php
/**
 * Tab: 高级设置
 */
if (!defined('ABSPATH')) exit;

// 获取默认清理规则
$settings_page = new WP_to_CF_Settings_Page();
$default_rules = $settings_page->get_default_cleanup_rules();
?>

<form method="post" action="options.php">
    <?php settings_fields('wptocf_settings'); ?>
    
    <!-- 代码注入 -->
    <div class="wptocf-panel blue">
        <h2><span class="dashicons dashicons-editor-code"></span> <?php esc_html_e('代码注入配置', 'wp-to-cf'); ?></h2>
        <p><?php esc_html_e('在生成的静态 HTML 中注入自定义代码，例如 Google Analytics、Facebook Pixel 等。', 'wp-to-cf'); ?></p>
        
        <table class="form-table">
            <tr>
                <th><label for="wptocf_head_code"><?php esc_html_e('Head Code (</head> 前)', 'wp-to-cf'); ?></label></th>
                <td>
                    <textarea name="wptocf_head_code" id="wptocf_head_code" rows="5" class="large-text code"><?php echo esc_textarea(get_option('wptocf_head_code', '')); ?></textarea>
                    <p class="description"><?php esc_html_e('在 </head> 标签前注入的代码，例如 Google Analytics', 'wp-to-cf'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="wptocf_body_start_code"><?php esc_html_e('Body Start Code (<body> 后)', 'wp-to-cf'); ?></label></th>
                <td>
                    <textarea name="wptocf_body_start_code" id="wptocf_body_start_code" rows="5" class="large-text code"><?php echo esc_textarea(get_option('wptocf_body_start_code', '')); ?></textarea>
                    <p class="description"><?php esc_html_e('在 <body> 标签后注入的代码，例如 GTM noscript', 'wp-to-cf'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="wptocf_body_end_code"><?php esc_html_e('Body End Code (</body> 前)', 'wp-to-cf'); ?></label></th>
                <td>
                    <textarea name="wptocf_body_end_code" id="wptocf_body_end_code" rows="5" class="large-text code"><?php echo esc_textarea(get_option('wptocf_body_end_code', '')); ?></textarea>
                    <p class="description"><?php esc_html_e('在 </body> 标签前注入的代码，例如聊天插件', 'wp-to-cf'); ?></p>
                </td>
            </tr>
        </table>
    </div>
    
    <!-- 脚本清理规则 -->
    <div class="wptocf-panel yellow">
        <h2><span class="dashicons dashicons-editor-strikethrough"></span> <?php esc_html_e('脚本清理规则', 'wp-to-cf'); ?></h2>
        <p><?php esc_html_e('配置需要从静态页面中移除的脚本。静态站点上某些 WordPress 脚本无法正常工作（如 AJAX、后台功能），移除它们可以避免控制台错误。', 'wp-to-cf'); ?></p>
        <p style="color: #666; font-size: 12px;">
            <span class="dashicons dashicons-info" style="font-size: 14px;"></span>
            <?php esc_html_e('提示：部署后在浏览器按 F12 打开开发者工具，查看 Console 中的错误，根据错误信息添加需要清理的脚本。', 'wp-to-cf'); ?>
        </p>
        
        <table class="form-table">
            <tr>
                <th><label for="wptocf_script_cleanup_rules"><?php esc_html_e('清理规则列表', 'wp-to-cf'); ?></label></th>
                <td>
                    <?php
                    $rules_value = get_option('wptocf_script_cleanup_rules', '');
                    if (empty($rules_value)) $rules_value = $default_rules;
                    ?>
                    <textarea name="wptocf_script_cleanup_rules" id="wptocf_script_cleanup_rules" rows="15" class="large-text code" style="font-family: monospace; font-size: 13px;"><?php echo esc_textarea($rules_value); ?></textarea>
                    <p class="description">
                        <?php esc_html_e('每行一个规则，匹配脚本 src 或 id 属性。以 # 开头的行为注释。', 'wp-to-cf'); ?>
                        <a href="javascript:void(0);" class="wptocf-toggle-guide" data-target="wptocf-cleanup-guide"><?php esc_html_e('查看使用说明', 'wp-to-cf'); ?></a>
                    </p>
                    <div id="wptocf-cleanup-guide" class="wptocf-guide-panel" style="display: none; background: #f0f6fc; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; padding: 12px 15px; margin: 10px 0 0 0; max-width: 700px;">
                        <p style="margin: 0 0 10px 0; font-weight: bold;"><?php esc_html_e('如何添加清理规则：', 'wp-to-cf'); ?></p>
                        <ol style="margin: 0 0 10px 0; padding-left: 20px; line-height: 1.8;">
                            <li><?php esc_html_e('部署静态站点后，在浏览器按 F12 打开开发者工具', 'wp-to-cf'); ?></li>
                            <li><?php esc_html_e('切换到 Console（控制台）标签，查看红色错误信息', 'wp-to-cf'); ?></li>
                            <li><?php esc_html_e('找到报错的脚本文件名或关键词', 'wp-to-cf'); ?></li>
                            <li><?php esc_html_e('将关键词添加到上方规则列表', 'wp-to-cf'); ?></li>
                            <li><?php esc_html_e('保存设置并重新部署', 'wp-to-cf'); ?></li>
                        </ol>
                    </div>
                    <p style="margin-top: 10px;">
                        <button type="button" id="wptocf-reset-cleanup-rules" class="button button-secondary">
                            <span class="dashicons dashicons-undo" style="vertical-align: middle;"></span>
                            <?php esc_html_e('恢复默认规则', 'wp-to-cf'); ?>
                        </button>
                    </p>
                </td>
            </tr>
        </table>
    </div>
    
    <?php submit_button(__('保存设置', 'wp-to-cf')); ?>
</form>

<!-- 帮助信息 -->
<div class="wptocf-help" style="background: #f0f6fc; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; padding: 15px; margin: 20px 0;">
    <h3 style="margin-top: 0;"><span class="dashicons dashicons-info"></span> <?php esc_html_e('使用说明', 'wp-to-cf'); ?></h3>
    <ul>
        <li><strong><?php esc_html_e('ZIP 下载（无需配置）:', 'wp-to-cf'); ?></strong> <?php esc_html_e('点击「导出为 ZIP」按钮，下载后手动上传到 Cloudflare Pages', 'wp-to-cf'); ?></li>
        <li><strong><?php esc_html_e('自动上传（需要配置）:', 'wp-to-cf'); ?></strong> <?php esc_html_e('填写 Cloudflare 凭证后，可使用「全量上传」或「增量上传」一键部署', 'wp-to-cf'); ?></li>
        <li><strong><?php esc_html_e('表单处理:', 'wp-to-cf'); ?></strong> <?php esc_html_e('静态站点表单需要配置第三方服务。支持 Formspree、Getform、Web3Forms 等，但只有 Getform 支持数据回流', 'wp-to-cf'); ?></li>
        <li><strong><?php esc_html_e('评论同步:', 'wp-to-cf'); ?></strong> <?php esc_html_e('需要将评论表单配置为 Getform 服务，WordPress 才能定时拉取评论', 'wp-to-cf'); ?></li>
    </ul>
</div>

<script>
jQuery(document).ready(function($) {
    $('.wptocf-toggle-guide').on('click', function(e) {
        e.preventDefault();
        var targetId = $(this).data('target');
        $('#' + targetId).slideToggle(200);
    });
    
    $('#wptocf-reset-cleanup-rules').on('click', function() {
        if (confirm('<?php echo esc_js(__('确定要恢复默认清理规则吗？当前规则将被覆盖。', 'wp-to-cf')); ?>')) {
            $('#wptocf_script_cleanup_rules').val(<?php echo json_encode($default_rules); ?>);
        }
    });
});
</script>

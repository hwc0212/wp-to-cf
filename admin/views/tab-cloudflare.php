<?php
/**
 * Tab: Cloudflare 配置
 */
if (!defined('ABSPATH')) exit;
?>

<form method="post" action="options.php">
    <?php
    settings_fields('wptocf_settings');
    ?>
    
    <div class="wptocf-panel blue">
        <h2>
            <span class="dashicons dashicons-cloud"></span>
            <?php esc_html_e('Cloudflare Workers 配置', 'wp-to-cf'); ?>
        </h2>
        <p><?php esc_html_e('配置 Cloudflare Workers 连接信息，用于自动上传功能（使用 Workers 静态资源部署，替代已弃用的 Pages）。如果只使用 ZIP 下载手动上传，可跳过此配置。', 'wp-to-cf'); ?></p>
        
        <table class="form-table">
            <tr>
                <th><label for="wptocf_account_id"><?php esc_html_e('Account ID', 'wp-to-cf'); ?></label></th>
                <td>
                    <input type="text" name="wptocf_account_id" id="wptocf_account_id" 
                           value="<?php echo esc_attr(get_option('wptocf_account_id', '')); ?>" 
                           class="regular-text" placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                    <p class="description"><?php esc_html_e('32 位十六进制字符串，可在 Cloudflare 控制台获取', 'wp-to-cf'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="wptocf_api_token"><?php esc_html_e('API Token', 'wp-to-cf'); ?></label></th>
                <td>
                    <?php
                    $encrypted_value = get_option('wptocf_api_token', '');
                    $has_token = !empty($encrypted_value);
                    $display_value = $has_token ? 'sk_••••••••••••••••••••••••' : '';
                    ?>
                    <input type="password" name="wptocf_api_token" id="wptocf_api_token" 
                           value="<?php echo esc_attr($display_value); ?>" 
                           class="regular-text" placeholder="<?php echo $has_token ? esc_attr__('留空保持不变', 'wp-to-cf') : 'sk_test_...'; ?>">
                    <button type="button" id="wptocf-validate-btn" class="button button-secondary" style="margin-left: 10px;">
                        <span class="dashicons dashicons-yes-alt" style="vertical-align: middle;"></span>
                        <?php esc_html_e('验证并获取列表', 'wp-to-cf'); ?>
                    </button>
                    <span id="wptocf-validate-status" style="margin-left: 10px;"></span>
                    <p class="description">
                        <?php echo $has_token ? esc_html__('API Token 已加密保存。留空保持不变，输入新值将覆盖。', 'wp-to-cf') : esc_html__('Cloudflare API Token，需要 Workers 脚本编辑权限。', 'wp-to-cf'); ?>
                        <a href="javascript:void(0);" class="wptocf-toggle-guide" data-target="wptocf-api-guide"><?php esc_html_e('如何获取？', 'wp-to-cf'); ?></a>
                    </p>
                    <div id="wptocf-api-guide" class="wptocf-guide-panel" style="display: none; background: #f0f6fc; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; padding: 12px 15px; margin: 10px 0 0 0; max-width: 600px;">
                        <ol style="margin: 0; padding-left: 20px;">
                            <li><?php esc_html_e('登录 Cloudflare 控制台', 'wp-to-cf'); ?> → <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank"><?php esc_html_e('我的个人资料 → API 令牌', 'wp-to-cf'); ?></a></li>
                            <li><?php esc_html_e('点击「创建令牌」→「创建自定义令牌」', 'wp-to-cf'); ?></li>
                            <li><?php esc_html_e('权限设置：', 'wp-to-cf'); ?><strong><?php esc_html_e('帐户 → Workers 脚本 → 编辑', 'wp-to-cf'); ?></strong></li>
                            <li><?php esc_html_e('账户资源：包括 → 所有帐户（或选择特定账户）', 'wp-to-cf'); ?></li>
                            <li><?php esc_html_e('点击「继续以显示摘要」→「创建令牌」→ 复制令牌', 'wp-to-cf'); ?></li>
                        </ol>
                    </div>
                </td>
            </tr>
            <tr>
                <th><label for="wptocf_project_name"><?php esc_html_e('Worker 名称', 'wp-to-cf'); ?></label></th>
                <td>
                    <div class="wptocf-combobox" id="wptocf-project-combobox">
                        <input type="text" name="wptocf_project_name" id="wptocf_project_name" 
                               value="<?php echo esc_attr(get_option('wptocf_project_name', '')); ?>" 
                               class="regular-text wptocf-combobox-input" placeholder="my-wordpress-site" autocomplete="off">
                        <span class="wptocf-combobox-arrow">▼</span>
                        <ul class="wptocf-combobox-dropdown" id="wptocf_project_dropdown"></ul>
                    </div>
                    <p class="description"><?php esc_html_e('Cloudflare Worker 脚本名称（只能包含小写字母、数字和连字符）。验证凭证后可从下拉列表选择已有 Worker，或输入新名称（首次部署时自动创建）。', 'wp-to-cf'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="wptocf_production_domain"><?php esc_html_e('Production Domain', 'wp-to-cf'); ?></label></th>
                <td>
                    <input type="text" name="wptocf_production_domain" id="wptocf_production_domain" 
                           value="<?php echo esc_attr(get_option('wptocf_production_domain', '')); ?>" 
                           class="regular-text" placeholder="www.example.com">
                    <p class="description">
                        <?php esc_html_e('公网域名（不含 http://）。示例：example.com、www.example.com', 'wp-to-cf'); ?>
                        <a href="javascript:void(0);" class="wptocf-toggle-guide" data-target="wptocf-domain-guide"><?php esc_html_e('如何绑定域名？', 'wp-to-cf'); ?></a>
                    </p>
                    <div id="wptocf-domain-guide" class="wptocf-guide-panel" style="display: none; background: #fff8e5; border: 1px solid #c3c4c7; border-left: 4px solid #dba617; padding: 12px 15px; margin: 10px 0 0 0; max-width: 600px;">
                        <p style="margin: 0 0 8px 0; font-weight: bold;"><?php esc_html_e('首次部署后在 Cloudflare 控制台绑定域名：', 'wp-to-cf'); ?></p>
                        <ol style="margin: 0; padding-left: 20px;">
                            <li><?php esc_html_e('进入 Workers 和 Pages → 选择您的 Worker', 'wp-to-cf'); ?></li>
                            <li><?php esc_html_e('点击「设置」→「域和路由」→「添加」→「自定义域」', 'wp-to-cf'); ?></li>
                            <li><?php esc_html_e('输入域名（如 example.com 或 www.example.com）', 'wp-to-cf'); ?></li>
                            <li><?php esc_html_e('域名在 Cloudflare 则自动配置 DNS，否则按提示添加 CNAME 记录', 'wp-to-cf'); ?></li>
                        </ol>
                    </div>
                </td>
            </tr>
        </table>
        
        <?php submit_button(__('保存设置', 'wp-to-cf')); ?>
    </div>
</form>

<script>
jQuery(document).ready(function($) {
    // 折叠指南切换
    $('.wptocf-toggle-guide').on('click', function(e) {
        e.preventDefault();
        var targetId = $(this).data('target');
        $('#' + targetId).slideToggle(200);
    });
    
    // 项目列表数据
    var projectList = [];
    
    // Combobox 功能
    var $combobox = $('#wptocf-project-combobox');
    var $input = $('#wptocf_project_name');
    var $dropdown = $('#wptocf_project_dropdown');
    var $arrow = $combobox.find('.wptocf-combobox-arrow');
    
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
    
    $arrow.on('click', function(e) {
        e.stopPropagation();
        if ($combobox.hasClass('open')) {
            $combobox.removeClass('open');
        } else if (projectList.length > 0) {
            renderDropdown('');
        }
    });
    
    $input.on('input', function() {
        if (projectList.length > 0) renderDropdown($(this).val());
    });
    
    $input.on('focus', function() {
        if (projectList.length > 0 && !$combobox.hasClass('open')) renderDropdown($(this).val());
    });
    
    $dropdown.on('click', 'li', function() {
        $input.val($(this).data('value'));
        $combobox.removeClass('open');
    });
    
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.wptocf-combobox').length) $combobox.removeClass('open');
    });
    
    // 验证凭证
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

<?php
/**
 * Tab: Worker 后端（动态功能：表单 / 评论 / 邮件）
 *
 * 独立处理自身表单提交（不走 Settings API 的共享 option group，避免跨 tab 覆盖）。
 */
if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_options')) {
    return;
}

/* ------------------------------------------------------------------ *
 * 处理保存
 * ------------------------------------------------------------------ */
if (
    isset($_POST['wptocf_backend_nonce']) &&
    wp_verify_nonce($_POST['wptocf_backend_nonce'], 'wptocf_save_backend')
) {
    // 明文选项
    update_option('wptocf_worker_backend_enabled', isset($_POST['worker_backend_enabled']) ? '1' : '0');
    update_option('wptocf_worker_base_url', esc_url_raw(trim($_POST['worker_base_url'] ?? '')));
    update_option('wptocf_d1_database_id', sanitize_text_field($_POST['d1_database_id'] ?? ''));
    update_option('wptocf_d1_database_name', sanitize_text_field($_POST['d1_database_name'] ?? ''));
    update_option('wptocf_turnstile_site_key', sanitize_text_field($_POST['turnstile_site_key'] ?? ''));

    $provider = sanitize_text_field($_POST['mail_provider'] ?? 'none');
    if (!in_array($provider, ['none', 'ses', 'http', 'smtp'], true)) {
        $provider = 'none';
    }
    update_option('wptocf_mail_provider', $provider);
    update_option('wptocf_mail_from', sanitize_text_field($_POST['mail_from'] ?? ''));
    update_option('wptocf_mail_to', sanitize_text_field($_POST['mail_to'] ?? ''));
    update_option('wptocf_mail_subject', sanitize_text_field($_POST['mail_subject'] ?? ''));

    update_option('wptocf_ses_region', sanitize_text_field($_POST['ses_region'] ?? ''));
    update_option('wptocf_ses_access_key_id', sanitize_text_field($_POST['ses_access_key_id'] ?? ''));
    update_option('wptocf_http_endpoint', esc_url_raw(trim($_POST['http_endpoint'] ?? '')));
    update_option('wptocf_smtp_host', sanitize_text_field($_POST['smtp_host'] ?? ''));
    update_option('wptocf_smtp_port', (string) intval($_POST['smtp_port'] ?? 587));
    update_option('wptocf_smtp_user', sanitize_text_field($_POST['smtp_user'] ?? ''));

    // 密钥：留空或为脱敏占位符则保持不变，否则加密保存
    $save_secret = function (string $option, $posted) {
        $posted = (string) $posted;
        if ($posted === '' || strpos($posted, '••••') !== false) {
            return; // 保持原值
        }
        $encrypted = WP_to_CF_Crypto::encrypt($posted);
        if ($encrypted !== false) {
            update_option($option, $encrypted);
        }
    };
    $save_secret('wptocf_turnstile_secret_key', $_POST['turnstile_secret_key'] ?? '');
    $save_secret('wptocf_ses_secret_access_key', $_POST['ses_secret_access_key'] ?? '');
    $save_secret('wptocf_http_api_key', $_POST['http_api_key'] ?? '');
    $save_secret('wptocf_smtp_pass', $_POST['smtp_pass'] ?? '');

    // 启用后端时若无 pull 密钥则自动生成
    if (get_option('wptocf_worker_backend_enabled') === '1' && empty(get_option('wptocf_worker_pull_secret', ''))) {
        $secret = wp_generate_password(48, false, false);
        $encrypted = WP_to_CF_Crypto::encrypt($secret);
        if ($encrypted !== false) {
            update_option('wptocf_worker_pull_secret', $encrypted);
        }
    }

    echo '<div class="notice notice-success is-dismissible"><p>' .
        esc_html__('Worker 后端设置已保存。请重新执行「全量上传」以将配置同步到 Worker。', 'wp-to-cf') .
        '</p></div>';
}

/* ------------------------------------------------------------------ *
 * 读取当前值
 * ------------------------------------------------------------------ */
$enabled       = get_option('wptocf_worker_backend_enabled', '0') === '1';
$base_url      = get_option('wptocf_worker_base_url', '');
$d1_id         = get_option('wptocf_d1_database_id', '');
$d1_name       = get_option('wptocf_d1_database_name', '');
$ts_site       = get_option('wptocf_turnstile_site_key', '');
$provider      = get_option('wptocf_mail_provider', 'none');
$mail_from     = get_option('wptocf_mail_from', '');
$mail_to       = get_option('wptocf_mail_to', get_option('admin_email'));
$mail_subject  = get_option('wptocf_mail_subject', '[{type}] 新提交 - {form_id}');
$ses_region    = get_option('wptocf_ses_region', 'us-east-1');
$ses_key_id    = get_option('wptocf_ses_access_key_id', '');
$http_endpoint = get_option('wptocf_http_endpoint', '');
$smtp_host     = get_option('wptocf_smtp_host', '');
$smtp_port     = get_option('wptocf_smtp_port', '587');
$smtp_user     = get_option('wptocf_smtp_user', '');

$has_pull_secret = !empty(get_option('wptocf_worker_pull_secret', ''));
$mask = function (string $opt): string {
    return !empty(get_option($opt, '')) ? '••••••••••••' : '';
};
$prod_domain = get_option('wptocf_production_domain', '');
?>

<form method="post" action="?page=wp-to-cf-settings&tab=backend">
    <?php wp_nonce_field('wptocf_save_backend', 'wptocf_backend_nonce'); ?>

    <div class="wptocf-panel purple">
        <h2><span class="dashicons dashicons-rest-api"></span> <?php esc_html_e('Worker 动态后端', 'wp-to-cf'); ?></h2>
        <p><?php esc_html_e('启用后，静态站点的表单与评论提交会发送到本站 Worker，暂存于 Cloudflare D1，并由 Worker 发送通知邮件；WordPress 通过定时任务出站拉取入库。', 'wp-to-cf'); ?></p>

        <table class="form-table">
            <tr>
                <th><?php esc_html_e('启用 Worker 后端', 'wp-to-cf'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="worker_backend_enabled" value="1" <?php checked($enabled); ?>>
                        <?php esc_html_e('将表单/评论提交路由到本站 Worker', 'wp-to-cf'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th><label for="worker_base_url"><?php esc_html_e('Worker 访问地址', 'wp-to-cf'); ?></label></th>
                <td>
                    <input type="text" name="worker_base_url" id="worker_base_url" class="regular-text"
                           value="<?php echo esc_attr($base_url); ?>"
                           placeholder="<?php echo esc_attr($prod_domain ? 'https://' . preg_replace('#^https?://#', '', $prod_domain) : 'https://www.example.com'); ?>">
                    <button type="button" class="button" id="wptocf-test-backend"><?php esc_html_e('测试连接', 'wp-to-cf'); ?></button>
                    <span id="wptocf-test-backend-status" style="margin-left:8px;"></span>
                    <p class="description"><?php esc_html_e('WordPress 出站拉取所用的站点地址。留空则使用生产域名。', 'wp-to-cf'); ?></p>
                </td>
            </tr>
        </table>
    </div>

    <div class="wptocf-panel blue">
        <h2><span class="dashicons dashicons-database"></span> <?php esc_html_e('D1 数据库（暂存提交）', 'wp-to-cf'); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="d1_database_name"><?php esc_html_e('数据库', 'wp-to-cf'); ?></label></th>
                <td>
                    <input type="text" name="d1_database_name" id="d1_database_name" class="regular-text"
                           value="<?php echo esc_attr($d1_name); ?>" placeholder="wp-to-cf-submissions" autocomplete="off">
                    <input type="hidden" name="d1_database_id" id="d1_database_id" value="<?php echo esc_attr($d1_id); ?>">
                    <button type="button" class="button" id="wptocf-d1-list"><?php esc_html_e('获取列表', 'wp-to-cf'); ?></button>
                    <button type="button" class="button" id="wptocf-d1-create"><?php esc_html_e('创建并建表', 'wp-to-cf'); ?></button>
                    <button type="button" class="button" id="wptocf-d1-provision"><?php esc_html_e('初始化表结构', 'wp-to-cf'); ?></button>
                    <span id="wptocf-d1-status" style="margin-left:8px;"></span>
                    <select id="wptocf-d1-select" style="display:none; margin-top:8px;"></select>
                    <p class="description">
                        <?php esc_html_e('当前数据库 ID：', 'wp-to-cf'); ?>
                        <code id="wptocf-d1-id-display"><?php echo esc_html($d1_id ?: __('未设置', 'wp-to-cf')); ?></code>
                    </p>
                    <p class="description"><?php esc_html_e('需要 API Token 具备「D1 编辑」权限。选择已有库后点「初始化表结构」，或直接「创建并建表」。', 'wp-to-cf'); ?></p>
                </td>
            </tr>
        </table>
    </div>

    <div class="wptocf-panel green">
        <h2><span class="dashicons dashicons-email"></span> <?php esc_html_e('邮件发送（Worker 侧）', 'wp-to-cf'); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="mail_provider"><?php esc_html_e('发送方式', 'wp-to-cf'); ?></label></th>
                <td>
                    <select name="mail_provider" id="mail_provider">
                        <option value="none" <?php selected($provider, 'none'); ?>><?php esc_html_e('不发送', 'wp-to-cf'); ?></option>
                        <option value="ses" <?php selected($provider, 'ses'); ?>>AWS SES</option>
                        <option value="http" <?php selected($provider, 'http'); ?>><?php esc_html_e('HTTP API（Resend 等）', 'wp-to-cf'); ?></option>
                        <option value="smtp" <?php selected($provider, 'smtp'); ?>>SMTP</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="mail_from"><?php esc_html_e('发件人', 'wp-to-cf'); ?></label></th>
                <td><input type="text" name="mail_from" id="mail_from" class="regular-text" value="<?php echo esc_attr($mail_from); ?>" placeholder="Name &lt;no-reply@example.com&gt;"></td>
            </tr>
            <tr>
                <th><label for="mail_to"><?php esc_html_e('通知收件人', 'wp-to-cf'); ?></label></th>
                <td>
                    <input type="text" name="mail_to" id="mail_to" class="regular-text" value="<?php echo esc_attr($mail_to); ?>" placeholder="you@example.com">
                    <p class="description"><?php esc_html_e('多个地址用英文逗号分隔。', 'wp-to-cf'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="mail_subject"><?php esc_html_e('邮件主题', 'wp-to-cf'); ?></label></th>
                <td>
                    <input type="text" name="mail_subject" id="mail_subject" class="regular-text" value="<?php echo esc_attr($mail_subject); ?>">
                    <p class="description"><?php esc_html_e('可用占位符：{type}、{form_id}', 'wp-to-cf'); ?></p>
                </td>
            </tr>
        </table>

        <table class="form-table wptocf-mail-section" data-provider="ses">
            <tr><th colspan="2"><h3 style="margin:0;">AWS SES</h3></th></tr>
            <tr>
                <th><label for="ses_region"><?php esc_html_e('区域', 'wp-to-cf'); ?></label></th>
                <td><input type="text" name="ses_region" id="ses_region" class="regular-text" value="<?php echo esc_attr($ses_region); ?>" placeholder="us-east-1"></td>
            </tr>
            <tr>
                <th><label for="ses_access_key_id">Access Key ID</label></th>
                <td><input type="text" name="ses_access_key_id" id="ses_access_key_id" class="regular-text" value="<?php echo esc_attr($ses_key_id); ?>" autocomplete="off"></td>
            </tr>
            <tr>
                <th><label for="ses_secret_access_key">Secret Access Key</label></th>
                <td>
                    <input type="password" name="ses_secret_access_key" id="ses_secret_access_key" class="regular-text" value="<?php echo esc_attr($mask('wptocf_ses_secret_access_key')); ?>" autocomplete="new-password" placeholder="<?php echo $mask('wptocf_ses_secret_access_key') ? esc_attr__('留空保持不变', 'wp-to-cf') : ''; ?>">
                    <p class="description"><?php esc_html_e('加密存储；部署时作为 Worker secret 同步。', 'wp-to-cf'); ?></p>
                </td>
            </tr>
        </table>

        <table class="form-table wptocf-mail-section" data-provider="http">
            <tr><th colspan="2"><h3 style="margin:0;"><?php esc_html_e('HTTP API', 'wp-to-cf'); ?></h3></th></tr>
            <tr>
                <th><label for="http_endpoint"><?php esc_html_e('端点 URL', 'wp-to-cf'); ?></label></th>
                <td><input type="text" name="http_endpoint" id="http_endpoint" class="regular-text" value="<?php echo esc_attr($http_endpoint); ?>" placeholder="https://api.resend.com/emails"></td>
            </tr>
            <tr>
                <th><label for="http_api_key">API Key</label></th>
                <td><input type="password" name="http_api_key" id="http_api_key" class="regular-text" value="<?php echo esc_attr($mask('wptocf_http_api_key')); ?>" autocomplete="new-password" placeholder="<?php echo $mask('wptocf_http_api_key') ? esc_attr__('留空保持不变', 'wp-to-cf') : 'Bearer token'; ?>"></td>
            </tr>
        </table>

        <table class="form-table wptocf-mail-section" data-provider="smtp">
            <tr><th colspan="2"><h3 style="margin:0;">SMTP</h3></th></tr>
            <tr>
                <th><label for="smtp_host"><?php esc_html_e('主机', 'wp-to-cf'); ?></label></th>
                <td><input type="text" name="smtp_host" id="smtp_host" class="regular-text" value="<?php echo esc_attr($smtp_host); ?>" placeholder="smtp.example.com"></td>
            </tr>
            <tr>
                <th><label for="smtp_port"><?php esc_html_e('端口', 'wp-to-cf'); ?></label></th>
                <td>
                    <input type="number" name="smtp_port" id="smtp_port" class="small-text" value="<?php echo esc_attr($smtp_port); ?>">
                    <span class="description"><?php esc_html_e('587 = STARTTLS，465 = TLS（不支持 25）', 'wp-to-cf'); ?></span>
                </td>
            </tr>
            <tr>
                <th><label for="smtp_user"><?php esc_html_e('账号', 'wp-to-cf'); ?></label></th>
                <td><input type="text" name="smtp_user" id="smtp_user" class="regular-text" value="<?php echo esc_attr($smtp_user); ?>" autocomplete="off"></td>
            </tr>
            <tr>
                <th><label for="smtp_pass"><?php esc_html_e('密码', 'wp-to-cf'); ?></label></th>
                <td><input type="password" name="smtp_pass" id="smtp_pass" class="regular-text" value="<?php echo esc_attr($mask('wptocf_smtp_pass')); ?>" autocomplete="new-password" placeholder="<?php echo $mask('wptocf_smtp_pass') ? esc_attr__('留空保持不变', 'wp-to-cf') : ''; ?>"></td>
            </tr>
        </table>
    </div>

    <div class="wptocf-panel yellow">
        <h2><span class="dashicons dashicons-shield"></span> <?php esc_html_e('安全', 'wp-to-cf'); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e('拉取密钥', 'wp-to-cf'); ?></th>
                <td>
                    <?php if ($has_pull_secret): ?>
                        <span style="color:#00a32a;"><span class="dashicons dashicons-yes"></span> <?php esc_html_e('已生成（加密存储，部署时同步到 Worker）', 'wp-to-cf'); ?></span>
                    <?php else: ?>
                        <span style="color:#996800;"><?php esc_html_e('启用后端并保存后将自动生成', 'wp-to-cf'); ?></span>
                    <?php endif; ?>
                    <p class="description"><?php esc_html_e('用于 WordPress 与 Worker 之间 pull/ack 接口的鉴权。', 'wp-to-cf'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="turnstile_site_key"><?php esc_html_e('Turnstile Site Key', 'wp-to-cf'); ?></label></th>
                <td><input type="text" name="turnstile_site_key" id="turnstile_site_key" class="regular-text" value="<?php echo esc_attr($ts_site); ?>" placeholder="0x4AAA..."></td>
            </tr>
            <tr>
                <th><label for="turnstile_secret_key"><?php esc_html_e('Turnstile Secret Key', 'wp-to-cf'); ?></label></th>
                <td>
                    <input type="password" name="turnstile_secret_key" id="turnstile_secret_key" class="regular-text" value="<?php echo esc_attr($mask('wptocf_turnstile_secret_key')); ?>" autocomplete="new-password" placeholder="<?php echo $mask('wptocf_turnstile_secret_key') ? esc_attr__('留空保持不变', 'wp-to-cf') : ''; ?>">
                    <p class="description"><?php esc_html_e('可选。填写后 Worker 会校验人机验证（需在表单页放置 Turnstile 组件）。', 'wp-to-cf'); ?></p>
                </td>
            </tr>
        </table>
    </div>

    <?php submit_button(__('保存后端设置', 'wp-to-cf')); ?>
</form>

<script>
jQuery(function ($) {
    var nonce = '<?php echo esc_js(wp_create_nonce('wptocf_backend')); ?>';

    function togglemail() {
        var p = $('#mail_provider').val();
        $('.wptocf-mail-section').each(function () {
            $(this).toggle($(this).data('provider') === p);
        });
    }
    $('#mail_provider').on('change', togglemail);
    togglemail();

    function busy($el, on) { $el.prop('disabled', on); }

    $('#wptocf-d1-list').on('click', function () {
        var $s = $('#wptocf-d1-status'); busy($(this), true);
        $s.text('<?php echo esc_js(__('获取中...', 'wp-to-cf')); ?>');
        $.post(ajaxurl, { action: 'wptocf_list_d1', nonce: nonce }, function (r) {
            busy($('#wptocf-d1-list'), false);
            if (!r.success) { $s.html('<span style="color:#d63638;">' + r.data.message + '</span>'); return; }
            var $sel = $('#wptocf-d1-select').empty().show();
            $sel.append('<option value="">— <?php echo esc_js(__('选择数据库', 'wp-to-cf')); ?> —</option>');
            (r.data.databases || []).forEach(function (db) {
                $sel.append('<option value="' + db.uuid + '" data-name="' + db.name + '">' + db.name + '</option>');
            });
            $s.text('');
        });
    });

    $('#wptocf-d1-select').on('change', function () {
        var id = $(this).val(), name = $(this).find(':selected').data('name') || '';
        if (id) {
            $('#d1_database_id').val(id);
            $('#d1_database_name').val(name);
            $('#wptocf-d1-id-display').text(id);
        }
    });

    $('#wptocf-d1-create').on('click', function () {
        var name = $('#d1_database_name').val();
        if (!name) { alert('<?php echo esc_js(__('请输入数据库名称', 'wp-to-cf')); ?>'); return; }
        var $s = $('#wptocf-d1-status'); busy($(this), true);
        $s.text('<?php echo esc_js(__('创建中...', 'wp-to-cf')); ?>');
        $.post(ajaxurl, { action: 'wptocf_create_d1', nonce: nonce, name: name }, function (r) {
            busy($('#wptocf-d1-create'), false);
            if (!r.success) { $s.html('<span style="color:#d63638;">' + r.data.message + '</span>'); return; }
            $('#d1_database_id').val(r.data.database.uuid);
            $('#wptocf-d1-id-display').text(r.data.database.uuid);
            $s.html('<span style="color:#00a32a;"><?php echo esc_js(__('创建并建表成功', 'wp-to-cf')); ?></span>');
        });
    });

    $('#wptocf-d1-provision').on('click', function () {
        var id = $('#d1_database_id').val();
        if (!id) { alert('<?php echo esc_js(__('请先选择或创建数据库', 'wp-to-cf')); ?>'); return; }
        var $s = $('#wptocf-d1-status'); busy($(this), true);
        $s.text('<?php echo esc_js(__('初始化中...', 'wp-to-cf')); ?>');
        $.post(ajaxurl, { action: 'wptocf_provision_d1', nonce: nonce, database_id: id }, function (r) {
            busy($('#wptocf-d1-provision'), false);
            if (!r.success) { $s.html('<span style="color:#d63638;">' + r.data.message + '</span>'); return; }
            $s.html('<span style="color:#00a32a;">' + r.data.message + '</span>');
        });
    });

    $('#wptocf-test-backend').on('click', function () {
        var $s = $('#wptocf-test-backend-status'); busy($(this), true);
        $s.text('<?php echo esc_js(__('测试中...', 'wp-to-cf')); ?>');
        $.post(ajaxurl, { action: 'wptocf_test_backend', nonce: nonce }, function (r) {
            busy($('#wptocf-test-backend'), false);
            if (!r.success) { $s.html('<span style="color:#d63638;">' + r.data.message + '</span>'); return; }
            var db = r.data.has_db ? ' (D1 ✓)' : ' (D1 ✗)';
            $s.html('<span style="color:#00a32a;">' + r.data.message + db + '</span>');
        });
    });
});
</script>

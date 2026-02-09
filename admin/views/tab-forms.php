<?php
/**
 * Tab: 表单配置
 */
if (!defined('ABSPATH')) exit;
?>

<div class="wptocf-panel purple">
    <h2><span class="dashicons dashicons-feedback"></span> <?php esc_html_e('表单配置', 'wp-to-cf'); ?></h2>
    <p><?php esc_html_e('配置静态站点的表单处理规则。扫描站点中的表单，设置第三方服务端点。', 'wp-to-cf'); ?></p>
    
    <!-- 数据回流说明 -->
    <div class="wptocf-warning-box" style="background: #e8f4e8; border-color: #00a32a;">
        <p style="margin: 0;"><span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span> 
            <strong><?php esc_html_e('数据回流说明', 'wp-to-cf'); ?>：</strong>
            <?php esc_html_e('如需将表单提交数据同步回 WordPress，请使用 Getform (Forminit) 或 form.huwencai.com 服务，并在「评论同步」页面配置 API Key。', 'wp-to-cf'); ?>
        </p>
    </div>
    
    <!-- 服务商说明 -->
    <div class="wptocf-warning-box" style="background: #f9f9f9;">
        <p style="margin: 0 0 8px 0;"><span class="dashicons dashicons-info" style="color: #2271b1;"></span> <strong><?php esc_html_e('支持的表单服务', 'wp-to-cf'); ?></strong>
            <a href="javascript:void(0);" class="wptocf-toggle-guide" data-target="wptocf-service-guide" style="margin-left: 10px;"><?php esc_html_e('展开申请教程', 'wp-to-cf'); ?></a>
        </p>
        <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
            <tr style="background: #f0f6fc;">
                <th style="padding: 8px; text-align: left; border: 1px solid #ddd;"><?php esc_html_e('服务', 'wp-to-cf'); ?></th>
                <th style="padding: 8px; text-align: left; border: 1px solid #ddd;"><?php esc_html_e('免费额度', 'wp-to-cf'); ?></th>
                <th style="padding: 8px; text-align: left; border: 1px solid #ddd;"><?php esc_html_e('端点格式', 'wp-to-cf'); ?></th>
                <th style="padding: 8px; text-align: left; border: 1px solid #ddd;"><?php esc_html_e('数据回流', 'wp-to-cf'); ?></th>
                <th style="padding: 8px; text-align: left; border: 1px solid #ddd;"><?php esc_html_e('申请', 'wp-to-cf'); ?></th>
            </tr>
            <tr>
                <td style="padding: 8px; border: 1px solid #ddd;"><strong>Formspree</strong></td>
                <td style="padding: 8px; border: 1px solid #ddd;">50次/月</td>
                <td style="padding: 8px; border: 1px solid #ddd;"><code>https://formspree.io/f/xxxxx</code></td>
                <td style="padding: 8px; border: 1px solid #ddd;"><span style="color:#999;">✗ 付费版</span></td>
                <td style="padding: 8px; border: 1px solid #ddd;"><a href="https://formspree.io/register" target="_blank"><?php esc_html_e('注册', 'wp-to-cf'); ?></a></td>
            </tr>
            <tr style="background: #e8f4e8;">
                <td style="padding: 8px; border: 1px solid #ddd;"><strong>Getform (Forminit)</strong></td>
                <td style="padding: 8px; border: 1px solid #ddd;">50次/月</td>
                <td style="padding: 8px; border: 1px solid #ddd;"><code>https://getform.io/f/xxxxx</code></td>
                <td style="padding: 8px; border: 1px solid #ddd;"><span style="color:#00a32a;">✓ 支持</span></td>
                <td style="padding: 8px; border: 1px solid #ddd;"><a href="https://app.getform.io/register" target="_blank"><?php esc_html_e('注册', 'wp-to-cf'); ?></a></td>
            </tr>
            <tr>
                <td style="padding: 8px; border: 1px solid #ddd;"><strong>Web3Forms</strong></td>
                <td style="padding: 8px; border: 1px solid #ddd;">无限制</td>
                <td style="padding: 8px; border: 1px solid #ddd;"><code>https://api.web3forms.com/submit</code></td>
                <td style="padding: 8px; border: 1px solid #ddd;"><span style="color:#999;">✗</span></td>
                <td style="padding: 8px; border: 1px solid #ddd;"><a href="https://web3forms.com/#start" target="_blank"><?php esc_html_e('获取 Key', 'wp-to-cf'); ?></a></td>
            </tr>
            <tr>
                <td style="padding: 8px; border: 1px solid #ddd;"><strong>Basin</strong></td>
                <td style="padding: 8px; border: 1px solid #ddd;">100次/月</td>
                <td style="padding: 8px; border: 1px solid #ddd;"><code>https://usebasin.com/f/xxxxx</code></td>
                <td style="padding: 8px; border: 1px solid #ddd;"><span style="color:#999;">✗</span></td>
                <td style="padding: 8px; border: 1px solid #ddd;"><a href="https://usebasin.com/users/sign_up" target="_blank"><?php esc_html_e('注册', 'wp-to-cf'); ?></a></td>
            </tr>
            <tr>
                <td style="padding: 8px; border: 1px solid #ddd;"><strong>Fabform</strong></td>
                <td style="padding: 8px; border: 1px solid #ddd;">250次/月</td>
                <td style="padding: 8px; border: 1px solid #ddd;"><code>https://fabform.io/f/xxxxx</code></td>
                <td style="padding: 8px; border: 1px solid #ddd;"><span style="color:#999;">✗</span></td>
                <td style="padding: 8px; border: 1px solid #ddd;"><a href="https://app.fabform.io/register" target="_blank"><?php esc_html_e('注册', 'wp-to-cf'); ?></a></td>
            </tr>
            <tr style="background: #fff3cd;">
                <td style="padding: 8px; border: 1px solid #ddd;"><strong>form.huwencai.com</strong></td>
                <td style="padding: 8px; border: 1px solid #ddd;">免费</td>
                <td style="padding: 8px; border: 1px solid #ddd;"><code>https://form.huwencai.com/f/xxxxx</code></td>
                <td style="padding: 8px; border: 1px solid #ddd;"><span style="color:#00a32a;">✓ 支持</span></td>
                <td style="padding: 8px; border: 1px solid #ddd;"><a href="https://huwencai.com/getform" target="_blank"><?php esc_html_e('申请', 'wp-to-cf'); ?></a></td>
            </tr>
        </table>
        
        <div id="wptocf-service-guide" style="display: none; margin-top: 15px; padding: 15px; background: #fff; border: 1px solid #ddd; border-radius: 4px;">
            <h4 style="margin-top: 0;"><?php esc_html_e('各服务商申请教程', 'wp-to-cf'); ?></h4>
            
            <details style="margin-bottom: 10px;">
                <summary style="cursor: pointer; font-weight: bold; color: #2271b1;">Formspree <?php esc_html_e('申请步骤', 'wp-to-cf'); ?></summary>
                <ol style="margin: 10px 0 0 20px; line-height: 1.8;">
                    <li><?php esc_html_e('访问', 'wp-to-cf'); ?> <a href="https://formspree.io/register" target="_blank">formspree.io/register</a> <?php esc_html_e('注册账号', 'wp-to-cf'); ?></li>
                    <li><?php esc_html_e('点击「+ New Form」创建新表单', 'wp-to-cf'); ?></li>
                    <li><?php esc_html_e('输入表单名称，选择接收邮箱', 'wp-to-cf'); ?></li>
                    <li><?php esc_html_e('创建后复制端点 URL（格式：https://formspree.io/f/xxxxx）', 'wp-to-cf'); ?></li>
                </ol>
            </details>
            
            <details style="margin-bottom: 10px;">
                <summary style="cursor: pointer; font-weight: bold; color: #00a32a;">Getform (Forminit) <?php esc_html_e('申请步骤', 'wp-to-cf'); ?> - <?php esc_html_e('推荐，支持数据回流', 'wp-to-cf'); ?></summary>
                <ol style="margin: 10px 0 0 20px; line-height: 1.8;">
                    <li><?php esc_html_e('访问', 'wp-to-cf'); ?> <a href="https://app.getform.io/register" target="_blank">app.getform.io/register</a> <?php esc_html_e('注册账号', 'wp-to-cf'); ?></li>
                    <li><?php esc_html_e('点击「Create」创建新表单', 'wp-to-cf'); ?></li>
                    <li><?php esc_html_e('输入表单名称，选择时区', 'wp-to-cf'); ?></li>
                    <li><?php esc_html_e('创建后复制端点 URL（格式：https://getform.io/f/xxxxx）', 'wp-to-cf'); ?></li>
                    <li><strong><?php esc_html_e('获取 API Token（用于数据回流）：', 'wp-to-cf'); ?></strong><?php esc_html_e('点击右上角头像 → Account → API Tokens → Create Token', 'wp-to-cf'); ?></li>
                </ol>
            </details>
            
            <details style="margin-bottom: 10px;">
                <summary style="cursor: pointer; font-weight: bold; color: #2271b1;">Web3Forms <?php esc_html_e('申请步骤', 'wp-to-cf'); ?></summary>
                <ol style="margin: 10px 0 0 20px; line-height: 1.8;">
                    <li><?php esc_html_e('访问', 'wp-to-cf'); ?> <a href="https://web3forms.com/#start" target="_blank">web3forms.com</a></li>
                    <li><?php esc_html_e('输入接收邮箱，点击「Create Access Key」', 'wp-to-cf'); ?></li>
                    <li><?php esc_html_e('复制生成的 Access Key', 'wp-to-cf'); ?></li>
                    <li><?php esc_html_e('端点固定为：https://api.web3forms.com/submit', 'wp-to-cf'); ?></li>
                    <li><?php esc_html_e('表单中需要添加隐藏字段 access_key（插件会自动处理）', 'wp-to-cf'); ?></li>
                </ol>
            </details>
            
            <details style="margin-bottom: 10px;">
                <summary style="cursor: pointer; font-weight: bold; color: #2271b1;">Basin <?php esc_html_e('申请步骤', 'wp-to-cf'); ?></summary>
                <ol style="margin: 10px 0 0 20px; line-height: 1.8;">
                    <li><?php esc_html_e('访问', 'wp-to-cf'); ?> <a href="https://usebasin.com/users/sign_up" target="_blank">usebasin.com</a> <?php esc_html_e('注册账号', 'wp-to-cf'); ?></li>
                    <li><?php esc_html_e('点击「New Form」创建新表单', 'wp-to-cf'); ?></li>
                    <li><?php esc_html_e('输入表单名称', 'wp-to-cf'); ?></li>
                    <li><?php esc_html_e('创建后复制端点 URL（格式：https://usebasin.com/f/xxxxx）', 'wp-to-cf'); ?></li>
                </ol>
            </details>
            
            <details>
                <summary style="cursor: pointer; font-weight: bold; color: #2271b1;">Fabform <?php esc_html_e('申请步骤', 'wp-to-cf'); ?></summary>
                <ol style="margin: 10px 0 0 20px; line-height: 1.8;">
                    <li><?php esc_html_e('访问', 'wp-to-cf'); ?> <a href="https://app.fabform.io/register" target="_blank">app.fabform.io</a> <?php esc_html_e('注册账号', 'wp-to-cf'); ?></li>
                    <li><?php esc_html_e('点击「Create Form」创建新表单', 'wp-to-cf'); ?></li>
                    <li><?php esc_html_e('输入表单名称', 'wp-to-cf'); ?></li>
                    <li><?php esc_html_e('创建后复制端点 URL（格式：https://fabform.io/f/xxxxx）', 'wp-to-cf'); ?></li>
                </ol>
            </details>
            
            <details>
                <summary style="cursor: pointer; font-weight: bold; color: #f0ad4e;">form.huwencai.com <?php esc_html_e('申请步骤', 'wp-to-cf'); ?> - <?php esc_html_e('免费，支持数据回流', 'wp-to-cf'); ?></summary>
                <ol style="margin: 10px 0 0 20px; line-height: 1.8;">
                    <li><?php esc_html_e('访问', 'wp-to-cf'); ?> <a href="https://huwencai.com/getform" target="_blank">huwencai.com/getform</a> <?php esc_html_e('提交申请', 'wp-to-cf'); ?></li>
                    <li><?php esc_html_e('填写你的域名和邮箱', 'wp-to-cf'); ?></li>
                    <li><?php esc_html_e('等待审核通过（通常 24 小时内）', 'wp-to-cf'); ?></li>
                    <li><?php esc_html_e('审核通过后会收到邮件，包含表单 ID 和 API Key', 'wp-to-cf'); ?></li>
                    <li><?php esc_html_e('端点格式', 'wp-to-cf'); ?>: <code>https://form.huwencai.com/f/你的表单ID</code></li>
                </ol>
            </details>
        </div>
    </div>
    
    <p>
        <button type="button" id="wptocf-scan-forms-btn" class="button button-secondary">
            <span class="dashicons dashicons-search" style="vertical-align: middle;"></span>
            <?php esc_html_e('扫描表单', 'wp-to-cf'); ?>
        </button>
        <button type="button" id="wptocf-refresh-scan-btn" class="button button-secondary" style="display: none;">
            <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
            <?php esc_html_e('重新扫描', 'wp-to-cf'); ?>
        </button>
        <button type="button" id="wptocf-add-form-btn" class="button button-secondary">
            <span class="dashicons dashicons-plus" style="vertical-align: middle;"></span>
            <?php esc_html_e('手动添加', 'wp-to-cf'); ?>
        </button>
        <span id="wptocf-form-status" style="margin-left: 10px;"></span>
    </p>
    
    <!-- 扫描结果 -->
    <div id="wptocf-scan-results" style="display: none; margin-top: 15px; background: #f9f9f9; padding: 15px; border-radius: 4px;">
        <h4 style="margin-top: 0;">
            <?php esc_html_e('发现的表单', 'wp-to-cf'); ?>
            <span id="wptocf-scan-cache-info" style="font-weight: normal; font-size: 12px; color: #666; margin-left: 10px;"></span>
        </h4>
        <div id="wptocf-scan-results-list"></div>
    </div>
    
    <!-- 已配置的表单列表 -->
    <div id="wptocf-form-mappings" style="margin-top: 15px;">
        <h4><?php esc_html_e('已配置的表单', 'wp-to-cf'); ?></h4>
        <table class="wp-list-table widefat fixed striped" id="wptocf-form-table">
            <thead>
                <tr>
                    <th style="width: 20%;"><?php esc_html_e('表单 ID', 'wp-to-cf'); ?></th>
                    <th style="width: 20%;"><?php esc_html_e('名称', 'wp-to-cf'); ?></th>
                    <th style="width: 25%;"><?php esc_html_e('服务类型', 'wp-to-cf'); ?></th>
                    <th style="width: 10%;"><?php esc_html_e('状态', 'wp-to-cf'); ?></th>
                    <th style="width: 25%;"><?php esc_html_e('操作', 'wp-to-cf'); ?></th>
                </tr>
            </thead>
            <tbody id="wptocf-form-table-body">
                <tr><td colspan="5" style="text-align: center;"><?php esc_html_e('加载中...', 'wp-to-cf'); ?></td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- 表单编辑弹窗 -->
<div id="wptocf-form-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 100000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border-radius: 8px; width: 500px; max-width: 90%; max-height: 80vh; overflow-y: auto;">
        <h3 id="wptocf-form-modal-title" style="margin-top: 0;"><?php esc_html_e('编辑表单配置', 'wp-to-cf'); ?></h3>
        <input type="hidden" id="wptocf-form-id-hidden" value="">
        <table class="form-table">
            <tr>
                <th><label for="wptocf-form-id"><?php esc_html_e('表单 ID', 'wp-to-cf'); ?> *</label></th>
                <td><input type="text" id="wptocf-form-id" class="regular-text" placeholder="contact-form-1"></td>
            </tr>
            <tr>
                <th><label for="wptocf-form-name"><?php esc_html_e('表单名称', 'wp-to-cf'); ?></label></th>
                <td><input type="text" id="wptocf-form-name" class="regular-text" placeholder="联系表单"></td>
            </tr>
            <tr>
                <th><label for="wptocf-form-service"><?php esc_html_e('表单服务', 'wp-to-cf'); ?> *</label></th>
                <td>
                    <select id="wptocf-form-service" class="regular-text">
                        <option value="formspree">Formspree</option>
                        <option value="getform">Getform (Forminit)</option>
                        <option value="cfform">form.huwencai.com</option>
                        <option value="web3forms">Web3Forms</option>
                        <option value="basin">Basin</option>
                        <option value="fabform">Fabform</option>
                        <option value="custom"><?php esc_html_e('自定义', 'wp-to-cf'); ?></option>
                    </select>
                    <p class="description" id="wptocf-service-desc"></p>
                </td>
            </tr>
            <tr>
                <th><label for="wptocf-form-endpoint"><?php esc_html_e('服务端点', 'wp-to-cf'); ?> *</label></th>
                <td>
                    <input type="url" id="wptocf-form-endpoint" class="regular-text" placeholder="https://formspree.io/f/xxxxx">
                    <p class="description"><?php esc_html_e('在第三方表单服务创建表单后获取的提交 URL', 'wp-to-cf'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="wptocf-form-redirect"><?php esc_html_e('跳转路径', 'wp-to-cf'); ?></label></th>
                <td><input type="text" id="wptocf-form-redirect" class="regular-text" placeholder="/thank-you/"></td>
            </tr>
            <tr>
                <th><label for="wptocf-form-message"><?php esc_html_e('成功消息', 'wp-to-cf'); ?></label></th>
                <td><textarea id="wptocf-form-message" class="large-text" rows="2" placeholder="提交成功！"></textarea></td>
            </tr>
            <tr>
                <th><label for="wptocf-form-enabled"><?php esc_html_e('启用', 'wp-to-cf'); ?></label></th>
                <td><input type="checkbox" id="wptocf-form-enabled" checked></td>
            </tr>
        </table>
        <p style="margin-top: 15px;">
            <button type="button" id="wptocf-form-save-btn" class="button button-primary"><?php esc_html_e('保存', 'wp-to-cf'); ?></button>
            <button type="button" id="wptocf-form-cancel-btn" class="button"><?php esc_html_e('取消', 'wp-to-cf'); ?></button>
        </p>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var formNonce = '<?php echo wp_create_nonce('wptocf_ajax'); ?>';
    var configuredFormIds = [];
    
    // 展开/收起教程
    $('.wptocf-toggle-guide').on('click', function(e) {
        e.preventDefault();
        var targetId = $(this).data('target');
        $('#' + targetId).slideToggle(200);
    });
    
    function loadFormMappings() {
        $.ajax({
            url: ajaxurl, type: 'POST',
            data: { action: 'wptocf_get_form_mappings', nonce: formNonce },
            success: function(response) {
                if (response.success) {
                    renderFormTable(response.data.mappings);
                    configuredFormIds = response.data.mappings.map(function(m) { return m.form_id; });
                    updateScanResultsButtons();
                }
            }
        });
    }
    
    function updateScanResultsButtons() {
        $('.wptocf-add-scanned-form').each(function() {
            var formId = $(this).data('form-id');
            if (configuredFormIds.indexOf(formId) !== -1) {
                $(this).text('<?php esc_html_e('已配置', 'wp-to-cf'); ?>').prop('disabled', true).addClass('button-disabled');
            } else {
                $(this).text('<?php esc_html_e('添加', 'wp-to-cf'); ?>').prop('disabled', false).removeClass('button-disabled');
            }
        });
    }
    
    function renderFormTable(mappings) {
        var $tbody = $('#wptocf-form-table-body');
        if (!mappings || mappings.length === 0) {
            $tbody.html('<tr><td colspan="5" style="text-align: center;"><?php esc_html_e('暂无配置的表单', 'wp-to-cf'); ?></td></tr>');
            return;
        }
        var html = '';
        mappings.forEach(function(m) {
            html += '<tr><td><code>' + m.form_id + '</code></td><td>' + (m.form_name || '-') + '</td><td>' + (m.service_type || 'formspree') + '</td><td>' + (m.enabled == 1 ? '<span style="color: #00a32a;">✓</span>' : '<span style="color: #999;">✗</span>') + '</td><td><button class="button button-small wptocf-edit-form" data-id="' + m.id + '"><span class="dashicons dashicons-edit"></span></button> <button class="button button-small wptocf-delete-form" data-id="' + m.id + '"><span class="dashicons dashicons-trash"></span></button></td></tr>';
        });
        $tbody.html(html);
    }
    
    // 扫描表单
    function scanForms(forceRefresh) {
        var $btn = $('#wptocf-scan-forms-btn'), $refreshBtn = $('#wptocf-refresh-scan-btn');
        var $status = $('#wptocf-form-status'), $results = $('#wptocf-scan-results'), $list = $('#wptocf-scan-results-list');
        var $cacheInfo = $('#wptocf-scan-cache-info');
        
        $btn.prop('disabled', true);
        $refreshBtn.prop('disabled', true);
        $status.html('<span class="spinner is-active" style="float: none; margin: 0;"></span> <?php esc_html_e('扫描中...', 'wp-to-cf'); ?>');
        
        $.ajax({
            url: ajaxurl, type: 'POST',
            data: { action: 'wptocf_scan_forms', nonce: formNonce, force_refresh: forceRefresh ? '1' : '0' },
            success: function(response) {
                $btn.prop('disabled', false);
                $refreshBtn.prop('disabled', false);
                $status.html('');
                
                if (response.success) {
                    var forms = response.data.forms;
                    var cached = response.data.cached;
                    var scanTime = response.data.scan_time;
                    
                    // 显示缓存信息
                    if (cached) {
                        $cacheInfo.html('(<?php esc_html_e('缓存数据', 'wp-to-cf'); ?>: ' + scanTime + ')');
                    } else {
                        $cacheInfo.html('(<?php esc_html_e('扫描时间', 'wp-to-cf'); ?>: ' + scanTime + ')');
                    }
                    
                    // 显示重新扫描按钮
                    $refreshBtn.show();
                    
                    if (forms.length === 0) {
                        $list.html('<p><?php esc_html_e('未发现表单', 'wp-to-cf'); ?></p>');
                    } else {
                        var html = '<table class="widefat"><thead><tr><th><?php esc_html_e('表单 ID', 'wp-to-cf'); ?></th><th><?php esc_html_e('类型', 'wp-to-cf'); ?></th><th><?php esc_html_e('来源', 'wp-to-cf'); ?></th><th><?php esc_html_e('操作', 'wp-to-cf'); ?></th></tr></thead><tbody>';
                        forms.forEach(function(f) {
                            var isConfigured = configuredFormIds.indexOf(f.form_id) !== -1;
                            html += '<tr><td><code>' + f.form_id + '</code></td><td>' + f.type + '</td><td>' + (f.page_title || f.source || '-') + '</td><td>';
                            if (isConfigured) html += '<button class="button button-small button-disabled" disabled><?php esc_html_e('已配置', 'wp-to-cf'); ?></button>';
                            else html += '<button class="button button-small wptocf-add-scanned-form" data-form-id="' + f.form_id + '" data-form-name="' + (f.form_name || f.type + ' 表单') + '"><?php esc_html_e('添加', 'wp-to-cf'); ?></button>';
                            html += '</td></tr>';
                        });
                        html += '</tbody></table>';
                        $list.html(html);
                    }
                    $results.show();
                } else {
                    $status.html('<span style="color: #d63638;"><?php esc_html_e('扫描失败', 'wp-to-cf'); ?></span>');
                }
            },
            error: function() {
                $btn.prop('disabled', false);
                $refreshBtn.prop('disabled', false);
                $status.html('<span style="color: #d63638;"><?php esc_html_e('网络错误', 'wp-to-cf'); ?></span>');
            }
        });
    }
    
    $('#wptocf-scan-forms-btn').on('click', function() { scanForms(false); });
    $('#wptocf-refresh-scan-btn').on('click', function() { scanForms(true); });
    
    $(document).on('click', '.wptocf-add-scanned-form', function() {
        openFormModal(0, $(this).data('form-id'), $(this).data('form-name'));
    });
    
    $('#wptocf-add-form-btn').on('click', function() { openFormModal(0, '', ''); });
    
    $(document).on('click', '.wptocf-edit-form', function() {
        var id = $(this).data('id');
        $.ajax({
            url: ajaxurl, type: 'POST',
            data: { action: 'wptocf_get_form_mappings', nonce: formNonce },
            success: function(response) {
                if (response.success) {
                    var mapping = response.data.mappings.find(function(m) { return m.id == id; });
                    if (mapping) openFormModal(mapping.id, mapping.form_id, mapping.form_name, mapping.service_type, mapping.service_endpoint, mapping.redirect_url, mapping.success_message, mapping.enabled);
                }
            }
        });
    });
    
    var serviceDescriptions = {
        'formspree': '<?php esc_html_e('免费 50次/月', 'wp-to-cf'); ?> - <a href="https://formspree.io" target="_blank">formspree.io</a>',
        'getform': '<?php esc_html_e('免费 50次/月，支持数据回流', 'wp-to-cf'); ?> - <a href="https://getform.io" target="_blank">getform.io</a>',
        'cfform': '<?php esc_html_e('免费，支持数据回流', 'wp-to-cf'); ?> - <a href="https://huwencai.com/getform" target="_blank"><?php esc_html_e('申请', 'wp-to-cf'); ?></a>',
        'web3forms': '<?php esc_html_e('完全免费，需要 access_key', 'wp-to-cf'); ?> - <a href="https://web3forms.com" target="_blank">web3forms.com</a>',
        'basin': '<?php esc_html_e('免费 100次/月', 'wp-to-cf'); ?> - <a href="https://usebasin.com" target="_blank">usebasin.com</a>',
        'fabform': '<?php esc_html_e('免费 250次/月', 'wp-to-cf'); ?> - <a href="https://fabform.io" target="_blank">fabform.io</a>',
        'custom': '<?php esc_html_e('使用自定义的表单处理端点', 'wp-to-cf'); ?>'
    };
    
    $('#wptocf-form-service').on('change', function() {
        $('#wptocf-service-desc').html(serviceDescriptions[$(this).val()] || '');
    });
    
    function openFormModal(id, formId, formName, serviceType, endpoint, redirect, message, enabled) {
        $('#wptocf-form-id-hidden').val(id || 0);
        $('#wptocf-form-id').val(formId || '').prop('readonly', id > 0);
        $('#wptocf-form-name').val(formName || '');
        $('#wptocf-form-service').val(serviceType || 'formspree').trigger('change');
        $('#wptocf-form-endpoint').val(endpoint || '');
        $('#wptocf-form-redirect').val(redirect || '');
        $('#wptocf-form-message').val(message || '');
        $('#wptocf-form-enabled').prop('checked', enabled !== 0);
        $('#wptocf-form-modal-title').text(id > 0 ? '<?php esc_html_e('编辑表单配置', 'wp-to-cf'); ?>' : '<?php esc_html_e('添加表单配置', 'wp-to-cf'); ?>');
        $('#wptocf-form-modal').show();
    }
    
    $('#wptocf-form-cancel-btn').on('click', function() { $('#wptocf-form-modal').hide(); });
    
    $('#wptocf-form-save-btn').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true);
        $.ajax({
            url: ajaxurl, type: 'POST',
            data: {
                action: 'wptocf_save_form_mapping', nonce: formNonce,
                id: $('#wptocf-form-id-hidden').val(), form_id: $('#wptocf-form-id').val(), form_name: $('#wptocf-form-name').val(),
                service_type: $('#wptocf-form-service').val(), service_endpoint: $('#wptocf-form-endpoint').val(),
                redirect_url: $('#wptocf-form-redirect').val(), success_message: $('#wptocf-form-message').val(),
                enabled: $('#wptocf-form-enabled').is(':checked') ? 1 : 0
            },
            success: function(response) {
                $btn.prop('disabled', false);
                if (response.success) { $('#wptocf-form-modal').hide(); loadFormMappings(); $('#wptocf-scan-results').hide(); }
                else alert(response.data.message || '<?php esc_html_e('保存失败', 'wp-to-cf'); ?>');
            },
            error: function() { $btn.prop('disabled', false); alert('<?php esc_html_e('网络错误', 'wp-to-cf'); ?>'); }
        });
    });
    
    $(document).on('click', '.wptocf-delete-form', function() {
        if (!confirm('<?php esc_html_e('确定要删除这个表单配置吗？', 'wp-to-cf'); ?>')) return;
        var id = $(this).data('id');
        $.ajax({
            url: ajaxurl, type: 'POST',
            data: { action: 'wptocf_delete_form_mapping', nonce: formNonce, id: id },
            success: function(response) { if (response.success) loadFormMappings(); else alert(response.data.message || '<?php esc_html_e('删除失败', 'wp-to-cf'); ?>'); }
        });
    });
    
    loadFormMappings();
    
    // 页面加载时自动显示缓存的扫描结果
    scanForms(false);
});
</script>

<?php
/**
 * 后台设置页面类
 * 
 * 负责渲染和处理插件设置页面
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_Settings_Page
 * 
 * 管理插件的后台设置界面
 */
class WP_to_CF_Settings_Page
{
    /**
     * 设置页面 slug
     */
    private const PAGE_SLUG = 'wp-to-cf-settings';

    /**
     * 设置组名
     */
    private const OPTION_GROUP = 'wptocf_settings';

    /**
     * Nonce action
     */
    private const NONCE_ACTION = 'wptocf_save_settings';

    /**
     * Nonce name
     */
    private const NONCE_NAME = 'wptocf_settings_nonce';

    /**
     * 添加菜单项
     * 
     * @return void
     */
    public function add_menu(): void
    {
        add_menu_page(
            __('WP to CF 设置', 'wp-to-cf'),
            __('WP to CF', 'wp-to-cf'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page'],
            'dashicons-cloud-upload',
            80
        );
        
    }

    /**
     * 注册设置
     * 
     * @return void
     */
    public function register_settings(): void
    {
        // Cloudflare 配置部分
        add_settings_section(
            'wptocf_cloudflare_section',
            __('Cloudflare Pages 配置', 'wp-to-cf'),
            [$this, 'render_cloudflare_section'],
            self::PAGE_SLUG
        );

        // Account ID
        register_setting(
            self::OPTION_GROUP,
            'wptocf_account_id',
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_with_nonce_check'],
                'default' => '',
            ]
        );

        add_settings_field(
            'wptocf_account_id',
            __('Account ID', 'wp-to-cf'),
            [$this, 'render_account_id_field'],
            self::PAGE_SLUG,
            'wptocf_cloudflare_section'
        );

        // API Token
        register_setting(
            self::OPTION_GROUP,
            'wptocf_api_token',
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_api_token'],
                'default' => '',
            ]
        );

        add_settings_field(
            'wptocf_api_token',
            __('API Token', 'wp-to-cf'),
            [$this, 'render_api_token_field'],
            self::PAGE_SLUG,
            'wptocf_cloudflare_section'
        );

        // Project Name
        register_setting(
            self::OPTION_GROUP,
            'wptocf_project_name',
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_with_nonce_check'],
                'default' => '',
            ]
        );

        add_settings_field(
            'wptocf_project_name',
            __('Project Name', 'wp-to-cf'),
            [$this, 'render_project_name_field'],
            self::PAGE_SLUG,
            'wptocf_cloudflare_section'
        );

        // Production Domain
        register_setting(
            self::OPTION_GROUP,
            'wptocf_production_domain',
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_domain'],
                'default' => '',
            ]
        );

        add_settings_field(
            'wptocf_production_domain',
            __('Production Domain', 'wp-to-cf'),
            [$this, 'render_production_domain_field'],
            self::PAGE_SLUG,
            'wptocf_cloudflare_section'
        );

        // 代码注入部分
        add_settings_section(
            'wptocf_code_injection_section',
            __('代码注入配置', 'wp-to-cf'),
            [$this, 'render_code_injection_section'],
            self::PAGE_SLUG
        );

        // Head Code
        register_setting(
            self::OPTION_GROUP,
            'wptocf_head_code',
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_code'],
                'default' => '',
            ]
        );

        add_settings_field(
            'wptocf_head_code',
            __('Head Code (</head> 前)', 'wp-to-cf'),
            [$this, 'render_head_code_field'],
            self::PAGE_SLUG,
            'wptocf_code_injection_section'
        );

        // Body Start Code
        register_setting(
            self::OPTION_GROUP,
            'wptocf_body_start_code',
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_code'],
                'default' => '',
            ]
        );

        add_settings_field(
            'wptocf_body_start_code',
            __('Body Start Code (<body> 后)', 'wp-to-cf'),
            [$this, 'render_body_start_code_field'],
            self::PAGE_SLUG,
            'wptocf_code_injection_section'
        );

        // Body End Code
        register_setting(
            self::OPTION_GROUP,
            'wptocf_body_end_code',
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_code'],
                'default' => '',
            ]
        );

        add_settings_field(
            'wptocf_body_end_code',
            __('Body End Code (</body> 前)', 'wp-to-cf'),
            [$this, 'render_body_end_code_field'],
            self::PAGE_SLUG,
            'wptocf_code_injection_section'
        );

        // 脚本清理规则部分
        add_settings_section(
            'wptocf_script_cleanup_section',
            __('脚本清理规则', 'wp-to-cf'),
            [$this, 'render_script_cleanup_section'],
            self::PAGE_SLUG
        );

        // 清理规则
        register_setting(
            self::OPTION_GROUP,
            'wptocf_script_cleanup_rules',
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_cleanup_rules'],
                'default' => $this->get_default_cleanup_rules(),
            ]
        );

        add_settings_field(
            'wptocf_script_cleanup_rules',
            __('清理规则列表', 'wp-to-cf'),
            [$this, 'render_script_cleanup_rules_field'],
            self::PAGE_SLUG,
            'wptocf_script_cleanup_section'
        );
        
        // 提交同步配置
        register_setting(
            self::OPTION_GROUP,
            'wptocf_getform_api_token',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => '',
            ]
        );
        
        register_setting(
            self::OPTION_GROUP,
            'wptocf_cfform_api_key',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => '',
            ]
        );
        
        register_setting(
            self::OPTION_GROUP,
            'wptocf_form_notify_email',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_email',
                'default' => '',
            ]
        );
        
        // 评论同步配置
        register_setting(
            self::OPTION_GROUP,
            'wptocf_comment_service',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => '',
            ]
        );
        
        register_setting(
            self::OPTION_GROUP,
            'wptocf_comment_endpoint',
            [
                'type' => 'string',
                'sanitize_callback' => 'esc_url_raw',
                'default' => '',
            ]
        );
    }

    /**
     * 获取默认清理规则
     * 
     * @return string 默认规则（每行一个）
     */
    public function get_default_cleanup_rules(): string
    {
        return implode("\n", [
            '# WordPress 核心（静态站点不需要）',
            'wp-emoji',
            'wp-embed',
            'jquery-migrate',
            'wp-polyfill',
            'wp-i18n',
            '',
            '# WooCommerce AJAX（静态站点不工作）',
            'wc-add-to-cart',
            'wc-cart-fragments',
            'woocommerce.min.js',
            'order-attribution',
            'wc_add_to_cart_params',
            'woocommerce_params',
            'wc_cart_fragments_params',
            '',
            '# Contact Form 7（静态站点不工作）',
            'contact-form-7',
            'wpcf7',
            '',
            '# 其他不需要的',
            'admin-bar',
            'dashicons',
            'speculationrules',
            'challenges.cloudflare.com/turnstile',
            'cfturnstile',
            'twemoji',
        ]);
    }

    /**
     * 渲染设置页面
     * 
     * @return void
     */
    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        require_once WPTOCF_PLUGIN_DIR . 'admin/views/settings-page.php';
    }

    /**
     * 验证 Account ID 格式
     * 
     * @param string $account_id Account ID
     * @return bool 是否有效
     */
    private function validate_account_id(string $account_id): bool
    {
        // Account ID 应该是 32 位十六进制字符串
        return preg_match('/^[a-f0-9]{32}$/i', $account_id) === 1;
    }

    /**
     * 验证域名格式
     * 
     * @param string $domain 域名
     * @return bool 是否有效
     */
    private function validate_domain(string $domain): bool
    {
        // 移除协议前缀
        $domain = preg_replace('#^https?://#', '', $domain);
        // 移除尾部斜杠
        $domain = rtrim($domain, '/');
        
        // 验证域名格式
        return filter_var('http://' . $domain, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * 通用的带 Nonce 验证的清理方法
     * 
     * @param string $value 输入值
     * @return string 清理后的值
     */
    public function sanitize_with_nonce_check(string $value): string
    {
        // 验证 Nonce
        if (!$this->verify_nonce()) {
            WP_to_CF_Logger::error('Nonce verification failed in sanitize_with_nonce_check');
            add_settings_error(
                'wptocf_messages',
                'wptocf_nonce_error',
                __('安全验证失败，请刷新页面后重试', 'wp-to-cf'),
                'error'
            );
            return get_option(current_filter(), ''); // 返回原值
        }

        return sanitize_text_field($value);
    }

    /**
     * 清理 API Token（加密存储）
     * 
     * @param string $value API Token
     * @return string 加密后的 Token
     */
    public function sanitize_api_token(string $value): string
    {
        // 验证 Nonce
        if (!$this->verify_nonce()) {
            WP_to_CF_Logger::error('Nonce verification failed in sanitize_api_token');
            add_settings_error(
                'wptocf_messages',
                'wptocf_nonce_error',
                __('安全验证失败，请刷新页面后重试', 'wp-to-cf'),
                'error'
            );
            return get_option('wptocf_api_token', ''); // 返回原值
        }

        if (empty($value)) {
            return '';
        }

        // 如果值已经是加密的（以前保存的），直接返回
        $current_value = get_option('wptocf_api_token', '');
        if ($value === $current_value) {
            return $value;
        }

        // 如果是脱敏显示的占位符，保持原值不变
        if (strpos($value, '••••') !== false) {
            return $current_value;
        }

        // 加密新的 Token
        $encrypted = WP_to_CF_Crypto::encrypt($value);
        if ($encrypted === false) {
            WP_to_CF_Logger::error('Failed to encrypt API token');
            add_settings_error(
                'wptocf_messages',
                'wptocf_encryption_error',
                __('API Token 加密失败，请检查 wp-config.php 中的 AUTH_KEY 配置', 'wp-to-cf'),
                'error'
            );
            return $current_value; // 返回原值
        }

        return $encrypted;
    }

    /**
     * 清理域名
     * 
     * @param string $value 域名
     * @return string 清理后的域名
     */
    public function sanitize_domain(string $value): string
    {
        // 验证 Nonce
        if (!$this->verify_nonce()) {
            WP_to_CF_Logger::error('Nonce verification failed in sanitize_domain');
            add_settings_error(
                'wptocf_messages',
                'wptocf_nonce_error',
                __('安全验证失败，请刷新页面后重试', 'wp-to-cf'),
                'error'
            );
            return get_option('wptocf_production_domain', ''); // 返回原值
        }

        // 移除协议前缀
        $value = preg_replace('#^https?://#', '', $value);
        // 移除尾部斜杠
        $value = rtrim($value, '/');
        // 转换为小写
        $value = strtolower($value);
        
        return sanitize_text_field($value);
    }

    /**
     * 清理代码注入内容（允许完整的 HTML/JavaScript）
     * 
     * @param string $value 代码内容
     * @return string 清理后的代码
     */
    public function sanitize_code(string $value): string
    {
        // 验证 Nonce
        if (!$this->verify_nonce()) {
            WP_to_CF_Logger::error('Nonce verification failed in sanitize_code');
            add_settings_error(
                'wptocf_messages',
                'wptocf_nonce_error',
                __('安全验证失败，请刷新页面后重试', 'wp-to-cf'),
                'error'
            );
            // 返回原值
            $option_name = str_replace('sanitize_option_', '', current_filter());
            return get_option($option_name, '');
        }

        // 检查用户是否有 unfiltered_html 权限
        if (!current_user_can('unfiltered_html')) {
            WP_to_CF_Logger::warning('User attempted to save code without unfiltered_html capability');
            add_settings_error(
                'wptocf_messages',
                'wptocf_permission_error',
                __('您没有权限保存未过滤的 HTML 代码', 'wp-to-cf'),
                'error'
            );
            // 返回原值
            $option_name = str_replace('sanitize_option_', '', current_filter());
            return get_option($option_name, '');
        }

        // 移除 WordPress 自动添加的斜杠
        $value = wp_unslash($value);

        // 不进行任何过滤，保存原始代码
        // 这是安全的，因为：
        // 1. 已验证 Nonce（防止 CSRF）
        // 2. 已验证用户权限（unfiltered_html）
        // 3. 代码只会在静态化时注入到生成的 HTML 中，不会在 WordPress 后台执行
        return $value;
    }

    /**
     * 验证 Nonce
     * 
     * @return bool 是否验证通过
     */
    private function verify_nonce(): bool
    {
        // 检查是否是设置保存请求
        if (!isset($_POST['option_page']) || $_POST['option_page'] !== self::OPTION_GROUP) {
            return false;
        }

        // 验证 Nonce
        if (!isset($_POST['_wpnonce'])) {
            return false;
        }

        return wp_verify_nonce($_POST['_wpnonce'], self::OPTION_GROUP . '-options') !== false;
    }

    /**
     * 渲染 Cloudflare 配置部分说明
     * 
     * @return void
     */
    public function render_cloudflare_section(): void
    {
        ?>
        <p><?php esc_html_e('配置 Cloudflare Pages 连接信息，用于自动上传功能。如果只使用 ZIP 下载手动上传，可跳过此配置。', 'wp-to-cf'); ?></p>
        <?php
    }

    /**
     * 渲染代码注入部分说明
     * 
     * @return void
     */
    public function render_code_injection_section(): void
    {
        echo '<p>' . esc_html__('在生成的静态 HTML 中注入自定义代码，例如 Google Analytics、Facebook Pixel 等。', 'wp-to-cf') . '</p>';
    }

    /**
     * 渲染 Account ID 字段
     * 
     * @return void
     */
    public function render_account_id_field(): void
    {
        $value = get_option('wptocf_account_id', '');
        ?>
        <input type="text" 
               name="wptocf_account_id" 
               id="wptocf_account_id" 
               value="<?php echo esc_attr($value); ?>" 
               class="regular-text"
               placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
        <p class="description">
            <?php esc_html_e('32 位十六进制字符串，可在 Cloudflare 控制台获取', 'wp-to-cf'); ?>
        </p>
        <?php
    }

    /**
     * 渲染 API Token 字段
     * 
     * @return void
     */
    public function render_api_token_field(): void
    {
        $encrypted_value = get_option('wptocf_api_token', '');
        $has_token = !empty($encrypted_value);
        
        // 如果已有 Token，显示脱敏版本
        $display_value = $has_token ? 'sk_••••••••••••••••••••••••' : '';
        ?>
        <input type="password" 
               name="wptocf_api_token" 
               id="wptocf_api_token" 
               value="<?php echo esc_attr($display_value); ?>" 
               class="regular-text"
               placeholder="<?php echo $has_token ? esc_attr__('留空保持不变', 'wp-to-cf') : 'sk_test_...'; ?>">
        <button type="button" id="wptocf-validate-btn" class="button button-secondary" style="margin-left: 10px;">
            <span class="dashicons dashicons-yes-alt" style="vertical-align: middle;"></span>
            <?php esc_html_e('验证并获取列表', 'wp-to-cf'); ?>
        </button>
        <span id="wptocf-validate-status" style="margin-left: 10px;"></span>
        <p class="description">
            <?php 
            if ($has_token) {
                esc_html_e('API Token 已加密保存。留空保持不变，输入新值将覆盖。', 'wp-to-cf');
            } else {
                esc_html_e('Cloudflare API Token，需要 Pages 编辑权限。', 'wp-to-cf');
            }
            ?>
            <a href="javascript:void(0);" class="wptocf-toggle-guide" data-target="wptocf-api-guide"><?php esc_html_e('如何获取？', 'wp-to-cf'); ?></a>
        </p>
        <div id="wptocf-api-guide" class="wptocf-guide-panel" style="display: none; background: #f0f6fc; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; padding: 12px 15px; margin: 10px 0 0 0; max-width: 600px;">
            <ol style="margin: 0; padding-left: 20px;">
                <li><?php esc_html_e('登录 Cloudflare 控制台', 'wp-to-cf'); ?> → <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank"><?php esc_html_e('我的个人资料 → API 令牌', 'wp-to-cf'); ?></a></li>
                <li><?php esc_html_e('点击「创建令牌」→「创建自定义令牌」', 'wp-to-cf'); ?></li>
                <li><?php esc_html_e('权限设置：', 'wp-to-cf'); ?><strong><?php esc_html_e('帐户 → Cloudflare Pages → 编辑', 'wp-to-cf'); ?></strong></li>
                <li><?php esc_html_e('账户资源：包括 → 所有帐户（或选择特定账户）', 'wp-to-cf'); ?></li>
                <li><?php esc_html_e('点击「继续以显示摘要」→「创建令牌」→ 复制令牌', 'wp-to-cf'); ?></li>
            </ol>
            <p style="margin: 10px 0 0 0; font-size: 12px; color: #666;">
                <span class="dashicons dashicons-lock" style="font-size: 14px;"></span>
                <?php esc_html_e('令牌将使用 AES-256-CBC 加密存储。', 'wp-to-cf'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * 渲染 Project Name 字段
     * 
     * @return void
     */
    public function render_project_name_field(): void
    {
        $value = get_option('wptocf_project_name', '');
        ?>
        <div class="wptocf-combobox" id="wptocf-project-combobox">
            <input type="text" 
                   name="wptocf_project_name" 
                   id="wptocf_project_name" 
                   value="<?php echo esc_attr($value); ?>" 
                   class="regular-text wptocf-combobox-input"
                   placeholder="my-wordpress-site"
                   autocomplete="off">
            <span class="wptocf-combobox-arrow">▼</span>
            <ul class="wptocf-combobox-dropdown" id="wptocf_project_dropdown"></ul>
        </div>
        <p class="description">
            <?php esc_html_e('Cloudflare Pages 项目名称。验证凭证后可从下拉列表选择已有项目，或输入新名称自动创建。', 'wp-to-cf'); ?>
        </p>
        <?php
    }

    /**
     * 渲染 Production Domain 字段
     * 
     * @return void
     */
    public function render_production_domain_field(): void
    {
        $value = get_option('wptocf_production_domain', '');
        ?>
        <input type="text" 
               name="wptocf_production_domain" 
               id="wptocf_production_domain" 
               value="<?php echo esc_attr($value); ?>" 
               class="regular-text"
               placeholder="www.example.com">
        <p class="description">
            <?php esc_html_e('公网域名（不含 http://）。示例：example.com、www.example.com、blog.example.com', 'wp-to-cf'); ?>
            <a href="javascript:void(0);" class="wptocf-toggle-guide" data-target="wptocf-domain-guide"><?php esc_html_e('如何绑定域名？', 'wp-to-cf'); ?></a>
        </p>
        <div id="wptocf-domain-guide" class="wptocf-guide-panel" style="display: none; background: #fff8e5; border: 1px solid #c3c4c7; border-left: 4px solid #dba617; padding: 12px 15px; margin: 10px 0 0 0; max-width: 600px;">
            <p style="margin: 0 0 8px 0; font-weight: bold;"><?php esc_html_e('首次部署后在 Cloudflare 控制台绑定域名：', 'wp-to-cf'); ?></p>
            <ol style="margin: 0; padding-left: 20px;">
                <li><?php esc_html_e('进入 Workers 和 Pages → 选择您的项目', 'wp-to-cf'); ?></li>
                <li><?php esc_html_e('点击「自定义域」→「设置自定义域」', 'wp-to-cf'); ?></li>
                <li><?php esc_html_e('输入域名（如 example.com 或 www.example.com）', 'wp-to-cf'); ?></li>
                <li><?php esc_html_e('域名在 Cloudflare 则自动配置 DNS，否则按提示添加 CNAME 记录', 'wp-to-cf'); ?></li>
            </ol>
            <p style="margin: 8px 0 0 0; font-size: 12px; color: #666;">
                <?php esc_html_e('域名绑定只需操作一次，后续部署会自动更新。', 'wp-to-cf'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * 渲染 Head Code 字段
     * 
     * @return void
     */
    public function render_head_code_field(): void
    {
        $value = get_option('wptocf_head_code', '');
        ?>
        <textarea name="wptocf_head_code" 
                  id="wptocf_head_code" 
                  rows="5" 
                  class="large-text code"><?php echo esc_textarea($value); ?></textarea>
        <p class="description">
            <?php esc_html_e('在 </head> 标签前注入的代码，例如 Google Analytics', 'wp-to-cf'); ?>
        </p>
        <?php
    }

    /**
     * 渲染 Body Start Code 字段
     * 
     * @return void
     */
    public function render_body_start_code_field(): void
    {
        $value = get_option('wptocf_body_start_code', '');
        ?>
        <textarea name="wptocf_body_start_code" 
                  id="wptocf_body_start_code" 
                  rows="5" 
                  class="large-text code"><?php echo esc_textarea($value); ?></textarea>
        <p class="description">
            <?php esc_html_e('在 <body> 标签后注入的代码，例如 GTM noscript', 'wp-to-cf'); ?>
        </p>
        <?php
    }

    /**
     * 渲染 Body End Code 字段
     * 
     * @return void
     */
    public function render_body_end_code_field(): void
    {
        $value = get_option('wptocf_body_end_code', '');
        ?>
        <textarea name="wptocf_body_end_code" 
                  id="wptocf_body_end_code" 
                  rows="5" 
                  class="large-text code"><?php echo esc_textarea($value); ?></textarea>
        <p class="description">
            <?php esc_html_e('在 </body> 标签前注入的代码，例如聊天插件', 'wp-to-cf'); ?>
        </p>
        <?php
    }

    /**
     * 渲染脚本清理规则部分说明
     * 
     * @return void
     */
    public function render_script_cleanup_section(): void
    {
        ?>
        <p>
            <?php esc_html_e('配置需要从静态页面中移除的脚本。静态站点上某些 WordPress 脚本无法正常工作（如 AJAX、后台功能），移除它们可以避免控制台错误。', 'wp-to-cf'); ?>
        </p>
        <p style="color: #666; font-size: 12px;">
            <span class="dashicons dashicons-info" style="font-size: 14px;"></span>
            <?php esc_html_e('提示：部署后在浏览器按 F12 打开开发者工具，查看 Console 中的错误，根据错误信息添加需要清理的脚本。', 'wp-to-cf'); ?>
        </p>
        <?php
    }

    /**
     * 渲染脚本清理规则字段
     * 
     * @return void
     */
    public function render_script_cleanup_rules_field(): void
    {
        $value = get_option('wptocf_script_cleanup_rules', '');
        if (empty($value)) {
            $value = $this->get_default_cleanup_rules();
        }
        ?>
        <textarea name="wptocf_script_cleanup_rules" 
                  id="wptocf_script_cleanup_rules" 
                  rows="15" 
                  class="large-text code"
                  style="font-family: monospace; font-size: 13px;"><?php echo esc_textarea($value); ?></textarea>
        <p class="description">
            <?php esc_html_e('每行一个规则，匹配脚本 src 或 id 属性。以 # 开头的行为注释。', 'wp-to-cf'); ?>
            <a href="javascript:void(0);" class="wptocf-toggle-guide" data-target="wptocf-cleanup-guide"><?php esc_html_e('查看使用说明', 'wp-to-cf'); ?></a>
        </p>
        <div id="wptocf-cleanup-guide" class="wptocf-guide-panel" style="display: none; background: #f0f6fc; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; padding: 12px 15px; margin: 10px 0 0 0; max-width: 700px;">
            <p style="margin: 0 0 10px 0; font-weight: bold;"><?php esc_html_e('如何添加清理规则：', 'wp-to-cf'); ?></p>
            <ol style="margin: 0 0 10px 0; padding-left: 20px; line-height: 1.8;">
                <li><?php esc_html_e('部署静态站点后，在浏览器按 F12 打开开发者工具', 'wp-to-cf'); ?></li>
                <li><?php esc_html_e('切换到 Console（控制台）标签，查看红色错误信息', 'wp-to-cf'); ?></li>
                <li><?php esc_html_e('找到报错的脚本文件名或关键词（如 wc-add-to-cart.min.js）', 'wp-to-cf'); ?></li>
                <li><?php esc_html_e('将关键词添加到上方规则列表（如 wc-add-to-cart）', 'wp-to-cf'); ?></li>
                <li><?php esc_html_e('保存设置并重新部署', 'wp-to-cf'); ?></li>
            </ol>
            <p style="margin: 0 0 8px 0; font-weight: bold;"><?php esc_html_e('规则匹配说明：', 'wp-to-cf'); ?></p>
            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                <li><code>wc-add-to-cart</code> → <?php esc_html_e('匹配 src 或 id 包含此关键词的脚本', 'wp-to-cf'); ?></li>
                <li><code>wp-includes/js/wp-emoji</code> → <?php esc_html_e('匹配路径包含此字符串的脚本', 'wp-to-cf'); ?></li>
                <li><code>speculationrules</code> → <?php esc_html_e('匹配 type="speculationrules" 的脚本', 'wp-to-cf'); ?></li>
                <li><code># 这是注释</code> → <?php esc_html_e('以 # 开头的行会被忽略', 'wp-to-cf'); ?></li>
            </ul>
            <p style="margin: 10px 0 0 0; font-size: 12px; color: #666;">
                <span class="dashicons dashicons-info" style="font-size: 14px;"></span>
                <?php esc_html_e('提示：规则越具体越好，避免误删需要的脚本。如果删错了导致功能异常，删除对应规则重新部署即可。', 'wp-to-cf'); ?>
            </p>
        </div>
        <p style="margin-top: 10px;">
            <button type="button" id="wptocf-reset-cleanup-rules" class="button button-secondary">
                <span class="dashicons dashicons-undo" style="vertical-align: middle;"></span>
                <?php esc_html_e('恢复默认规则', 'wp-to-cf'); ?>
            </button>
        </p>
        <script>
        jQuery(document).ready(function($) {
            $('#wptocf-reset-cleanup-rules').on('click', function() {
                if (confirm('<?php echo esc_js(__('确定要恢复默认清理规则吗？当前规则将被覆盖。', 'wp-to-cf')); ?>')) {
                    $('#wptocf_script_cleanup_rules').val(<?php echo json_encode($this->get_default_cleanup_rules()); ?>);
                }
            });
        });
        </script>
        <?php
    }

    /**
     * 清理清理规则
     * 
     * @param string $value 规则内容
     * @return string 清理后的规则
     */
    public function sanitize_cleanup_rules(string $value): string
    {
        // 验证 Nonce
        if (!$this->verify_nonce()) {
            WP_to_CF_Logger::error('Nonce verification failed in sanitize_cleanup_rules');
            add_settings_error(
                'wptocf_messages',
                'wptocf_nonce_error',
                __('安全验证失败，请刷新页面后重试', 'wp-to-cf'),
                'error'
            );
            return get_option('wptocf_script_cleanup_rules', $this->get_default_cleanup_rules());
        }

        // 标准化换行符
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        
        // 移除空白行（但保留注释）
        $lines = explode("\n", $value);
        $cleaned_lines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (!empty($trimmed)) {
                $cleaned_lines[] = $trimmed;
            }
        }
        
        return implode("\n", $cleaned_lines);
    }

    /**
     * 获取环境健康状态
     * 
     * @return array 环境状态信息
     */
    public function get_environment_status(): array
    {
        $status = [
            'encryption_available' => WP_to_CF_Crypto::is_encryption_available(),
            'openssl_loaded' => extension_loaded('openssl'),
            'auth_key_defined' => defined('AUTH_KEY') && !empty(AUTH_KEY),
            'php_version' => PHP_VERSION,
            'php_version_ok' => version_compare(PHP_VERSION, '8.2.0', '>='),
        ];

        return $status;
    }

    /**
     * AJAX 处理器：导出静态站点为 ZIP
     * 
     * @return void
     */
    public function ajax_export_site(): void
    {
        // 验证权限
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('您没有权限执行此操作', 'wp-to-cf'),
            ]);
            return;
        }

        // 验证 Nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wptocf_export_site')) {
            wp_send_json_error([
                'message' => __('安全验证失败', 'wp-to-cf'),
            ]);
            return;
        }

        // 增加执行时间和内存
        @set_time_limit(0); // 无限制
        @ini_set('memory_limit', '1G');

        // 执行导出
        $exporter = new WP_to_CF_Site_Exporter();
        $result = $exporter->export_site();

        if ($result['success']) {
            wp_send_json_success([
                'message' => sprintf(
                    __('导出成功！共 %d 个文件，ZIP 大小：%s MB', 'wp-to-cf'),
                    $result['file_count'],
                    $result['zip_size_mb']
                ),
                'zip_url' => $result['zip_url'],
                'file_count' => $result['file_count'],
                'zip_size_mb' => $result['zip_size_mb'],
            ]);
        } else {
            wp_send_json_error([
                'message' => sprintf(
                    __('导出失败：%s', 'wp-to-cf'),
                    $result['error']
                ),
            ]);
        }
    }

    /**
     * AJAX 处理器：导出并上传到 Cloudflare Pages
     * 
     * @return void
     */
    public function ajax_export_and_deploy(): void
    {
        // 验证权限
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('您没有权限执行此操作', 'wp-to-cf'),
            ]);
            return;
        }

        // 验证 Nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wptocf_export_and_deploy')) {
            wp_send_json_error([
                'message' => __('安全验证失败', 'wp-to-cf'),
            ]);
            return;
        }

        // 检查 Cloudflare API 配置
        $api = new WP_to_CF_Cloudflare_API();
        if (!$api->is_configured()) {
            wp_send_json_error([
                'message' => __('请先配置 Cloudflare API 凭证（Account ID、API Token、Project Name）', 'wp-to-cf'),
            ]);
            return;
        }

        // 增加执行时间和内存
        @set_time_limit(0);
        @ini_set('memory_limit', '1G');

        // 执行导出
        $exporter = new WP_to_CF_Site_Exporter();
        $result = $exporter->export_site();

        if (!$result['success']) {
            wp_send_json_error([
                'message' => sprintf(__('导出失败：%s', 'wp-to-cf'), $result['error']),
            ]);
            return;
        }

        // 读取ZIP文件并上传到Cloudflare
        $zip_path = $result['zip_path'];
        
        // 解压ZIP获取文件列表
        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== true) {
            wp_send_json_error([
                'message' => __('无法打开ZIP文件', 'wp-to-cf'),
            ]);
            return;
        }

        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            // 跳过目录
            if (substr($filename, -1) === '/') {
                continue;
            }
            $content = $zip->getFromIndex($i);
            if ($content !== false) {
                $files[$filename] = $content;
            }
        }
        $zip->close();

        WP_to_CF_Logger::info('准备上传到Cloudflare', ['file_count' => count($files)]);

        // 上传到Cloudflare Pages
        $deployment_id = $api->create_deployment($files);

        if ($deployment_id === false) {
            wp_send_json_error([
                'message' => __('上传到 Cloudflare Pages 失败，请检查日志', 'wp-to-cf'),
            ]);
            return;
        }

        // 获取部署URL
        $project_name = get_option('wptocf_project_name', '');
        $production_domain = get_option('wptocf_production_domain', '');
        $deployment_url = !empty($production_domain) ? 'https://' . $production_domain : "https://{$project_name}.pages.dev";

        wp_send_json_success([
            'message' => sprintf(
                __('上传成功！共 %d 个文件，部署 ID：%s', 'wp-to-cf'),
                count($files),
                $deployment_id
            ),
            'deployment_id' => $deployment_id,
            'deployment_url' => $deployment_url,
            'file_count' => count($files),
        ]);
    }

    /**
     * AJAX 处理器：增量上传到 Cloudflare Pages
     * 
     * @return void
     */
    public function ajax_incremental_deploy(): void
    {
        // 验证权限
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('您没有权限执行此操作', 'wp-to-cf'),
            ]);
            return;
        }

        // 验证 Nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wptocf_incremental_deploy')) {
            wp_send_json_error([
                'message' => __('安全验证失败', 'wp-to-cf'),
            ]);
            return;
        }

        // 检查 Cloudflare API 配置
        $api = new WP_to_CF_Cloudflare_API();
        if (!$api->is_configured()) {
            wp_send_json_error([
                'message' => __('请先配置 Cloudflare API 凭证（Account ID、API Token、Project Name）', 'wp-to-cf'),
            ]);
            return;
        }

        // 增加执行时间和内存
        @set_time_limit(0);
        @ini_set('memory_limit', '1G');

        // 执行增量部署
        $exporter = new WP_to_CF_Site_Exporter();
        $result = $exporter->incremental_deploy();

        if (!$result['success']) {
            wp_send_json_error([
                'message' => sprintf(__('增量部署失败：%s', 'wp-to-cf'), $result['error']),
            ]);
            return;
        }

        // 获取部署URL
        $project_name = get_option('wptocf_project_name', '');
        $production_domain = get_option('wptocf_production_domain', '');
        $deployment_url = !empty($production_domain) ? 'https://' . $production_domain : "https://{$project_name}.pages.dev";

        $message = isset($result['changed_count']) 
            ? sprintf(__('增量上传成功！%d 个文件变化，共 %d 个文件', 'wp-to-cf'), $result['changed_count'], $result['file_count'])
            : ($result['message'] ?? sprintf(__('增量上传成功！共 %d 个文件', 'wp-to-cf'), $result['file_count']));

        wp_send_json_success([
            'message' => $message,
            'deployment_id' => $result['deployment_id'],
            'deployment_url' => $deployment_url,
            'file_count' => $result['file_count'],
            'changed_count' => $result['changed_count'] ?? 0,
        ]);
    }

    /**
     * AJAX 处理器：一键静态化全站
     * 
     * @return void
     */
    public function ajax_staticize_all(): void
    {
        // 验证权限
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('您没有权限执行此操作', 'wp-to-cf'),
            ]);
            return;
        }

        // 验证 Nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wptocf_staticize_all')) {
            wp_send_json_error([
                'message' => __('安全验证失败', 'wp-to-cf'),
            ]);
            return;
        }

        // 添加全站静态化任务
        $queue = new WP_to_CF_Task_Queue();
        $task_id = $queue->add_task(0, 'staticize_all', 0);

        if ($task_id === false) {
            wp_send_json_error([
                'message' => __('添加任务失败', 'wp-to-cf'),
            ]);
            return;
        }

        // 立即触发处理
        $scheduler = new WP_to_CF_Task_Scheduler($queue);
        $scheduler->schedule_next_batch(0);

        wp_send_json_success([
            'message' => __('全站静态化任务已启动，正在后台处理...', 'wp-to-cf'),
            'task_id' => $task_id,
        ]);
    }

    /**
     * AJAX 处理器：获取包列表
     */
    public function ajax_get_packages(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('您没有权限执行此操作', 'wp-to-cf')]);
            return;
        }

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wptocf_manage_packages')) {
            wp_send_json_error(['message' => __('安全验证失败', 'wp-to-cf')]);
            return;
        }

        $manager = new WP_to_CF_Package_Manager();
        $packages = $manager->get_all_packages();
        $stats = $manager->get_stats();

        wp_send_json_success([
            'packages' => $packages,
            'stats' => $stats,
        ]);
    }

    /**
     * AJAX 处理器：删除包
     */
    public function ajax_delete_package(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('您没有权限执行此操作', 'wp-to-cf')]);
            return;
        }

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wptocf_manage_packages')) {
            wp_send_json_error(['message' => __('安全验证失败', 'wp-to-cf')]);
            return;
        }

        if (!isset($_POST['filename'])) {
            wp_send_json_error(['message' => __('缺少文件名参数', 'wp-to-cf')]);
            return;
        }

        $manager = new WP_to_CF_Package_Manager();
        $result = $manager->delete_package($_POST['filename']);

        if ($result) {
            wp_send_json_success(['message' => __('包已删除', 'wp-to-cf')]);
        } else {
            wp_send_json_error(['message' => __('删除失败', 'wp-to-cf')]);
        }
    }

    /**
     * AJAX 处理器：清理旧包
     */
    public function ajax_cleanup_packages(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('您没有权限执行此操作', 'wp-to-cf')]);
            return;
        }

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wptocf_manage_packages')) {
            wp_send_json_error(['message' => __('安全验证失败', 'wp-to-cf')]);
            return;
        }

        $keep_count = isset($_POST['keep_count']) ? intval($_POST['keep_count']) : 5;
        
        $manager = new WP_to_CF_Package_Manager();
        $result = $manager->cleanup_old_packages($keep_count);

        wp_send_json_success($result);
    }

    /**
     * AJAX 处理器：获取缓存统计
     */
    public function ajax_get_cache_stats(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('您没有权限执行此操作', 'wp-to-cf')]);
            return;
        }

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wptocf_manage_cache')) {
            wp_send_json_error(['message' => __('安全验证失败', 'wp-to-cf')]);
            return;
        }

        $manager = new WP_to_CF_Cache_Manager();
        $stats = $manager->get_stats();

        wp_send_json_success(['stats' => $stats]);
    }

    /**
     * AJAX 处理器：清理缓存
     */
    public function ajax_clear_cache(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('您没有权限执行此操作', 'wp-to-cf')]);
            return;
        }

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wptocf_manage_cache')) {
            wp_send_json_error(['message' => __('安全验证失败', 'wp-to-cf')]);
            return;
        }

        $type = isset($_POST['type']) ? $_POST['type'] : 'all';
        
        $manager = new WP_to_CF_Cache_Manager();
        
        if ($type === 'all') {
            $result = $manager->clear_all();
        } else {
            $result = $manager->clear_type($type);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX 处理器：验证 Cloudflare 凭证并获取项目/域名列表
     */
    public function ajax_validate_cf_credentials(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('您没有权限执行此操作', 'wp-to-cf')]);
            return;
        }

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wptocf_validate_credentials')) {
            wp_send_json_error(['message' => __('安全验证失败', 'wp-to-cf')]);
            return;
        }

        $account_id = sanitize_text_field($_POST['account_id'] ?? '');
        $api_token = sanitize_text_field($_POST['api_token'] ?? '');

        if (empty($account_id) || empty($api_token)) {
            wp_send_json_error(['message' => __('请填写 Account ID 和 API Token', 'wp-to-cf')]);
            return;
        }

        // 如果 api_token 是脱敏的，使用已保存的
        if (strpos($api_token, '••••') !== false) {
            $encrypted_token = get_option('wptocf_api_token', '');
            if (!empty($encrypted_token)) {
                $api_token = WP_to_CF_Crypto::decrypt($encrypted_token);
                if ($api_token === false) {
                    wp_send_json_error(['message' => __('无法解密已保存的 API Token', 'wp-to-cf')]);
                    return;
                }
            } else {
                wp_send_json_error(['message' => __('请输入新的 API Token', 'wp-to-cf')]);
                return;
            }
        }

        // 验证凭证
        $validation = WP_to_CF_Cloudflare_API::validate_credentials($account_id, $api_token);
        if (!$validation['success']) {
            wp_send_json_error(['message' => $validation['message']]);
            return;
        }

        // 获取项目列表
        $projects_result = WP_to_CF_Cloudflare_API::list_pages_projects($account_id, $api_token);
        $projects = $projects_result['success'] ? $projects_result['projects'] : [];

        // 获取域名列表
        $zones_result = WP_to_CF_Cloudflare_API::list_zones($account_id, $api_token);
        $zones = $zones_result['success'] ? $zones_result['zones'] : [];

        wp_send_json_success([
            'message' => __('验证成功', 'wp-to-cf'),
            'account_name' => $validation['account_name'] ?? '',
            'projects' => $projects,
            'zones' => $zones,
        ]);
    }

    /**
     * AJAX 处理器：创建 Pages 项目
     */
    public function ajax_create_pages_project(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('您没有权限执行此操作', 'wp-to-cf')]);
            return;
        }

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wptocf_create_project')) {
            wp_send_json_error(['message' => __('安全验证失败', 'wp-to-cf')]);
            return;
        }

        $account_id = sanitize_text_field($_POST['account_id'] ?? '');
        $api_token = sanitize_text_field($_POST['api_token'] ?? '');
        $project_name = sanitize_text_field($_POST['project_name'] ?? '');

        if (empty($project_name)) {
            wp_send_json_error(['message' => __('请输入项目名称', 'wp-to-cf')]);
            return;
        }

        // 如果 api_token 是脱敏的，使用已保存的
        if (strpos($api_token, '••••') !== false) {
            $encrypted_token = get_option('wptocf_api_token', '');
            if (!empty($encrypted_token)) {
                $api_token = WP_to_CF_Crypto::decrypt($encrypted_token);
            }
        }

        $result = WP_to_CF_Cloudflare_API::create_pages_project($account_id, $api_token, $project_name);
        
        if ($result['success']) {
            wp_send_json_success([
                'message' => $result['message'],
                'project' => $result['project'],
            ]);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }

    /**
     * AJAX 处理器：扫描表单
     */
    public function ajax_scan_forms(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('您没有权限执行此操作', 'wp-to-cf')]);
            return;
        }

        check_ajax_referer('wptocf_ajax', 'nonce');

        // 检查是否强制刷新
        $force_refresh = isset($_POST['force_refresh']) && $_POST['force_refresh'] === '1';
        
        // 尝试从缓存获取
        if (!$force_refresh) {
            $cached = get_transient('wptocf_scanned_forms');
            if ($cached !== false) {
                wp_send_json_success([
                    'forms' => $cached['forms'],
                    'count' => count($cached['forms']),
                    'cached' => true,
                    'scan_time' => $cached['scan_time'],
                ]);
                return;
            }
        }

        $scanner = new WP_to_CF_Form_Scanner();
        
        // 先快速扫描数据库
        $db_forms = $scanner->quick_scan();
        
        // 再扫描页面 HTML
        $html_forms = $scanner->scan_all_forms();
        
        // 合并去重
        $all_forms = [];
        $seen_ids = [];
        
        foreach ($db_forms as $form) {
            if (!in_array($form['form_id'], $seen_ids)) {
                $all_forms[] = $form;
                $seen_ids[] = $form['form_id'];
            }
        }
        
        foreach ($html_forms as $form) {
            if (!in_array($form['form_id'], $seen_ids)) {
                $all_forms[] = $form;
                $seen_ids[] = $form['form_id'];
            }
        }
        
        // 缓存扫描结果（24小时）
        $cache_data = [
            'forms' => $all_forms,
            'scan_time' => current_time('mysql'),
        ];
        set_transient('wptocf_scanned_forms', $cache_data, DAY_IN_SECONDS);

        wp_send_json_success([
            'forms' => $all_forms,
            'count' => count($all_forms),
            'cached' => false,
            'scan_time' => $cache_data['scan_time'],
        ]);
    }

    /**
     * AJAX 处理器：获取表单配置列表
     */
    public function ajax_get_form_mappings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('您没有权限执行此操作', 'wp-to-cf')]);
            return;
        }

        check_ajax_referer('wptocf_ajax', 'nonce');

        $form_admin = new WP_to_CF_Form_Mapping_Admin();
        $mappings = $form_admin->get_all_mappings();
        $stats = $form_admin->get_stats();

        wp_send_json_success([
            'mappings' => $mappings,
            'stats' => $stats,
        ]);
    }

    /**
     * AJAX 处理器：保存表单配置
     */
    public function ajax_save_form_mapping(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('您没有权限执行此操作', 'wp-to-cf')]);
            return;
        }

        check_ajax_referer('wptocf_ajax', 'nonce');

        $data = [
            'id' => intval($_POST['id'] ?? 0),
            'form_id' => sanitize_text_field($_POST['form_id'] ?? ''),
            'form_name' => sanitize_text_field($_POST['form_name'] ?? ''),
            'service_type' => sanitize_text_field($_POST['service_type'] ?? 'formspree'),
            'service_endpoint' => esc_url_raw($_POST['service_endpoint'] ?? ''),
            'redirect_url' => sanitize_text_field($_POST['redirect_url'] ?? ''),
            'success_message' => sanitize_textarea_field($_POST['success_message'] ?? ''),
            'enabled' => intval($_POST['enabled'] ?? 1),
        ];

        $form_admin = new WP_to_CF_Form_Mapping_Admin();
        $result = $form_admin->save_mapping($data);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX 处理器：获取支持的表单服务列表
     */
    public function ajax_get_form_services(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('您没有权限执行此操作', 'wp-to-cf')]);
            return;
        }

        check_ajax_referer('wptocf_ajax', 'nonce');

        $services = WP_to_CF_Form_Mapping_Admin::get_supported_services();
        wp_send_json_success(['services' => $services]);
    }

    /**
     * AJAX 处理器：删除表单配置
     */
    public function ajax_delete_form_mapping(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('您没有权限执行此操作', 'wp-to-cf')]);
            return;
        }

        check_ajax_referer('wptocf_ajax', 'nonce');

        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            wp_send_json_error(['message' => __('无效的 ID', 'wp-to-cf')]);
            return;
        }

        $form_admin = new WP_to_CF_Form_Mapping_Admin();
        $result = $form_admin->delete_mapping($id);

        if ($result) {
            wp_send_json_success(['message' => __('删除成功', 'wp-to-cf')]);
        } else {
            wp_send_json_error(['message' => __('删除失败', 'wp-to-cf')]);
        }
    }
    
    /**
     * AJAX 处理器：手动同步提交
     */
    public function ajax_sync_submissions(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('您没有权限执行此操作', 'wp-to-cf')]);
            return;
        }

        check_ajax_referer('wptocf_ajax', 'nonce');

        $sync = new WP_to_CF_Submission_Sync();
        $result = $sync->manual_sync();
        
        update_option('wptocf_last_sync_time', current_time('mysql'));

        wp_send_json_success([
            'message' => sprintf(__('同步完成，获取 %d 条新提交', 'wp-to-cf'), $result['synced']),
            'synced' => $result['synced'],
            'errors' => $result['errors'],
        ]);
    }
    
    /**
     * AJAX 处理器：获取同步统计
     */
    public function ajax_get_sync_stats(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('您没有权限执行此操作', 'wp-to-cf')]);
            return;
        }

        check_ajax_referer('wptocf_ajax', 'nonce');

        $sync = new WP_to_CF_Submission_Sync();
        $stats = $sync->get_stats();
        $recent = $sync->get_recent_submissions(10);

        wp_send_json_success([
            'stats' => $stats,
            'recent' => $recent,
        ]);
    }
    
    /**
     * AJAX 处理器：删除提交记录
     */
    public function ajax_delete_submission(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('您没有权限执行此操作', 'wp-to-cf')]);
            return;
        }

        check_ajax_referer('wptocf_ajax', 'nonce');

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        if (!$id) {
            wp_send_json_error(['message' => __('无效的记录 ID', 'wp-to-cf')]);
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'wptocf_submissions';
        
        $deleted = $wpdb->delete($table_name, ['id' => $id], ['%d']);
        
        if ($deleted) {
            wp_send_json_success(['message' => __('删除成功', 'wp-to-cf')]);
        } else {
            wp_send_json_error(['message' => __('删除失败', 'wp-to-cf')]);
        }
    }

    // ========================================================================
    // 分块部署 AJAX 处理器（解决共享主机超时问题）
    // ========================================================================

    /**
     * AJAX: 第一步 - 收集 URL
     */
    public function ajax_chunked_collect(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('您没有权限执行此操作', 'wp-to-cf')]);
            return;
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wptocf_chunked_deploy')) {
            wp_send_json_error(['message' => __('安全验证失败', 'wp-to-cf')]);
            return;
        }

        $api = new WP_to_CF_Cloudflare_API();
        if (!$api->is_configured()) {
            wp_send_json_error(['message' => __('请先配置 Cloudflare API 凭证', 'wp-to-cf')]);
            return;
        }

        @set_time_limit(120);
        $exporter = new WP_to_CF_Site_Exporter();
        $result = $exporter->chunked_collect_urls();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error(['message' => $result['error'] ?? '收集 URL 失败']);
        }
    }

    /**
     * AJAX: 第二步 - 分批抓取页面
     */
    public function ajax_chunked_fetch(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('您没有权限执行此操作', 'wp-to-cf')]);
            return;
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wptocf_chunked_deploy')) {
            wp_send_json_error(['message' => __('安全验证失败', 'wp-to-cf')]);
            return;
        }

        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 30;

        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $exporter = new WP_to_CF_Site_Exporter();
        $result = $exporter->chunked_fetch_pages($offset, $limit);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error(['message' => $result['error'] ?? '抓取页面失败']);
        }
    }

    /**
     * AJAX: 第三步 - 处理资源并部署
     */
    public function ajax_chunked_deploy(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('您没有权限执行此操作', 'wp-to-cf')]);
            return;
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wptocf_chunked_deploy')) {
            wp_send_json_error(['message' => __('安全验证失败', 'wp-to-cf')]);
            return;
        }

        @set_time_limit(0);
        @ini_set('memory_limit', '1G');

        $exporter = new WP_to_CF_Site_Exporter();
        $result = $exporter->chunked_process_and_deploy();

        if ($result['success']) {
            $project_name = get_option('wptocf_project_name', '');
            $production_domain = get_option('wptocf_production_domain', '');
            $deployment_url = !empty($production_domain) ? 'https://' . $production_domain : "https://{$project_name}.pages.dev";

            wp_send_json_success([
                'message' => sprintf(__('部署成功！共 %d 个文件', 'wp-to-cf'), $result['file_count']),
                'deployment_id' => $result['deployment_id'],
                'deployment_url' => $deployment_url,
                'file_count' => $result['file_count'],
            ]);
        } else {
            wp_send_json_error(['message' => $result['error'] ?? '部署失败']);
        }
    }
}

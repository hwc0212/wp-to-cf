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
        echo '<p>' . esc_html__('配置 Cloudflare Pages 连接信息。这些信息可以在 Cloudflare 控制台中获取。', 'wp-to-cf') . '</p>';
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
        <p class="description">
            <?php 
            if ($has_token) {
                esc_html_e('API Token 已加密保存。留空保持不变，输入新值将覆盖。', 'wp-to-cf');
            } else {
                esc_html_e('Cloudflare API Token，需要 Pages 权限', 'wp-to-cf');
            }
            ?>
        </p>
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
        <input type="text" 
               name="wptocf_project_name" 
               id="wptocf_project_name" 
               value="<?php echo esc_attr($value); ?>" 
               class="regular-text"
               placeholder="my-wordpress-site">
        <p class="description">
            <?php esc_html_e('Cloudflare Pages 项目名称', 'wp-to-cf'); ?>
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
               placeholder="example.com">
        <p class="description">
            <?php esc_html_e('公网域名（不含 http://），例如：example.com 或 www.example.com', 'wp-to-cf'); ?>
        </p>
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

        // 执行增量导出和部署
        $exporter = new WP_to_CF_Site_Exporter();
        $exporter->enable_incremental(true);
        $result = $exporter->export_and_deploy();

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

        wp_send_json_success([
            'message' => sprintf(
                __('增量上传成功！共 %d 个文件，部署 ID：%s', 'wp-to-cf'),
                $result['file_count'],
                $result['deployment_id']
            ),
            'deployment_id' => $result['deployment_id'],
            'deployment_url' => $deployment_url,
            'file_count' => $result['file_count'],
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
}

<?php
/**
 * Cloudflare Workers Static Assets API 客户端
 *
 * Cloudflare 官方已宣布使用 Workers 静态资源（Static Assets）替代 Pages。
 * 本客户端使用 Workers 直接上传（Direct Upload）流程部署静态站点：
 *
 * 1. 提交 manifest 创建上传会话（assets-upload-session），返回上传 JWT 及需要上传的分批（buckets）
 * 2. 按 buckets 分批上传文件（multipart/form-data，base64 编码），最后一次响应返回完成 JWT
 * 3. 使用完成 JWT 上传/部署 Worker 脚本（PUT /workers/scripts/:name），附带静态资源
 *
 * 参考：https://developers.cloudflare.com/workers/static-assets/direct-upload/
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_to_CF_Cloudflare_API
{
    private const API_BASE_URL = 'https://api.cloudflare.com/client/v4';
    private const REQUEST_TIMEOUT = 180;

    /**
     * Worker 入口模块的 part 名称（同时作为 main_module 值）
     */
    private const WORKER_MODULE_NAME = 'worker.js';

    /**
     * 兼容性日期（Workers 运行时行为基准）
     */
    private const COMPATIBILITY_DATE = '2025-06-01';

    private $account_id;
    private $api_token;
    private $script_name;
    private $last_error = '';

    public function __construct()
    {
        $this->account_id = get_option('wptocf_account_id', '');
        // 复用原有 project_name 选项作为 Worker 脚本名称，保持向后兼容
        $this->script_name = get_option('wptocf_project_name', '');

        $encrypted_token = get_option('wptocf_api_token', '');
        if (!empty($encrypted_token)) {
            $decrypted = WP_to_CF_Crypto::decrypt($encrypted_token);
            $this->api_token = $decrypted !== false ? $decrypted : '';
        } else {
            $this->api_token = '';
        }
    }

    public function is_configured(): bool
    {
        return !empty($this->account_id) &&
               !empty($this->api_token) &&
               !empty($this->script_name);
    }

    public function get_last_error(): string
    {
        return $this->last_error;
    }

    /**
     * 创建部署（Workers 静态资源直接上传流程）
     *
     * @param array $files 完整的文件列表 [路径 => 内容]。注意：必须是站点的完整文件集合，
     *                     未变化的文件会通过 manifest 去重自动跳过上传。
     * @return string|false 成功返回 version id（或脚本名），失败返回 false
     */
    public function create_deployment(array $files)
    {
        if (empty($files)) {
            WP_to_CF_Logger::error('No files to deploy');
            return false;
        }

        WP_to_CF_Logger::info('Starting Workers deployment', ['file_count' => count($files)]);

        // 1. 标准化路径并计算哈希，构建 manifest
        // hash 计算方式与 Pages 相同：sha256(base64内容 + 扩展名) 取前 32 位十六进制
        $file_data = [];   // hash => ['content' => ..., 'path' => ..., 'contentType' => ...]
        $manifest = [];    // "/path" => ['hash' => ..., 'size' => ...]

        foreach ($files as $path => $content) {
            $path = ltrim(str_replace('\\', '/', $path), '/');
            if (empty($path)) continue;

            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $base64_content = base64_encode($content);
            $hash = substr(hash('sha256', $base64_content . $ext), 0, 32);

            // manifest 路径必须以 / 开头，且不含双斜杠
            $manifest_path = '/' . ltrim($path, '/');
            $manifest_path = preg_replace('#/+#', '/', $manifest_path);

            $manifest[$manifest_path] = [
                'hash' => $hash,
                'size' => strlen($content),
            ];

            // 以 hash 为键存储文件内容，便于按 bucket 上传
            $file_data[$hash] = [
                'content' => $content,
                'path' => $manifest_path,
                'contentType' => $this->get_content_type($path),
            ];
        }

        WP_to_CF_Logger::info('Manifest prepared', [
            'total' => count($manifest),
            'has_index' => isset($manifest['/index.html']),
        ]);

        // 2. 创建上传会话，获取 upload JWT 与需要上传的 buckets
        $session = $this->create_upload_session($manifest);
        if ($session === false) {
            WP_to_CF_Logger::error('Failed to create asset upload session');
            return false;
        }

        $upload_jwt = $session['jwt'] ?? '';
        $buckets = $session['buckets'] ?? [];

        if (empty($upload_jwt)) {
            WP_to_CF_Logger::error('Upload session did not return a JWT');
            return false;
        }

        // 3. 上传文件。如果 buckets 为空，说明所有资源均已上传过，upload_jwt 即为完成令牌。
        $completion_jwt = $upload_jwt;

        if (!empty($buckets)) {
            WP_to_CF_Logger::info('Uploading asset buckets', ['bucket_count' => count($buckets)]);

            foreach ($buckets as $i => $bucket_hashes) {
                if (function_exists('set_time_limit')) {
                    @set_time_limit(300);
                }

                WP_to_CF_Logger::info('Uploading bucket', [
                    'bucket' => $i + 1,
                    'total' => count($buckets),
                    'files' => count($bucket_hashes),
                ]);

                $result = $this->upload_bucket($upload_jwt, $bucket_hashes, $file_data);
                if ($result === false) {
                    WP_to_CF_Logger::error('Failed to upload bucket', ['bucket' => $i + 1]);
                    return false;
                }

                // 上传完成后（201）会返回完成令牌
                if (is_string($result) && $result !== '') {
                    $completion_jwt = $result;
                }

                if ($i < count($buckets) - 1) {
                    sleep(1);
                }
            }
        } else {
            WP_to_CF_Logger::info('No new assets to upload, reusing existing assets');
        }

        // 4. 上传/部署 Worker 脚本（附带静态资源）
        $version_id = $this->deploy_worker_script($completion_jwt);
        if ($version_id === false) {
            WP_to_CF_Logger::error('Failed to deploy Worker script');
            return false;
        }

        WP_to_CF_Logger::info('Worker deployed', ['version' => $version_id]);

        // 5. 尽力启用 workers.dev 子域名（非致命）
        $this->enable_workers_subdomain();

        return $version_id;
    }

    /**
     * 创建资源上传会话，提交 manifest
     *
     * @return array|false ['jwt' => ..., 'buckets' => [[hash, ...], ...]]
     */
    private function create_upload_session(array $manifest)
    {
        $url = self::API_BASE_URL . "/accounts/{$this->account_id}/workers/scripts/" .
               rawurlencode($this->script_name) . "/assets-upload-session";

        $body = json_encode(['manifest' => $manifest], JSON_UNESCAPED_SLASHES);

        $response = wp_remote_post($url, [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type' => 'application/json',
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            $this->last_error = '创建上传会话失败: ' . $response->get_error_message();
            WP_to_CF_Logger::error('Upload session request failed', ['error' => $response->get_error_message()]);
            return false;
        }

        $status = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($status < 200 || $status >= 300) {
            $error_msg = $data['errors'][0]['message'] ?? '未知错误';
            $this->last_error = "创建上传会话失败 (HTTP {$status}): {$error_msg}";
            WP_to_CF_Logger::error('Upload session error', [
                'status' => $status,
                'errors' => $data['errors'] ?? [],
            ]);
            return false;
        }

        if (!isset($data['result']['jwt'])) {
            $this->last_error = '上传会话响应格式错误';
            WP_to_CF_Logger::error('Upload session JWT missing', ['response' => $data]);
            return false;
        }

        return [
            'jwt' => $data['result']['jwt'],
            'buckets' => $data['result']['buckets'] ?? [],
        ];
    }

    /**
     * 上传一个 bucket 的文件（multipart/form-data，base64 编码，带重试）
     *
     * @param string $upload_jwt   上传令牌
     * @param array  $bucket_hashes 该批需上传的文件 hash 列表
     * @param array  $file_data     hash => 文件数据映射
     * @return string|false 成功返回完成令牌（可能为空字符串表示尚未完成），失败返回 false
     */
    private function upload_bucket($upload_jwt, array $bucket_hashes, array $file_data, $max_retries = 3)
    {
        $url = self::API_BASE_URL . "/accounts/{$this->account_id}/workers/assets/upload?base64=true";

        $boundary = '----WPtoCF' . uniqid();
        $parts = '';

        foreach ($bucket_hashes as $hash) {
            if (!isset($file_data[$hash])) {
                WP_to_CF_Logger::error('Bucket references unknown hash', ['hash' => $hash]);
                return false;
            }

            $data = $file_data[$hash];
            $parts .= "--{$boundary}\r\n" .
                      "Content-Disposition: form-data; name=\"{$hash}\"; filename=\"{$hash}\"\r\n" .
                      "Content-Type: {$data['contentType']}\r\n" .
                      "\r\n" .
                      base64_encode($data['content']) . "\r\n";
        }

        $body = $parts . "--{$boundary}--\r\n";

        $retry = 0;
        while ($retry < $max_retries) {
            $response = wp_remote_post($url, [
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => [
                    'Authorization' => 'Bearer ' . $upload_jwt,
                    'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                ],
                'body' => $body,
            ]);

            if (is_wp_error($response)) {
                $retry++;
                WP_to_CF_Logger::warning('Bucket upload failed, retrying', [
                    'error' => $response->get_error_message(),
                    'retry' => $retry,
                    'max_retries' => $max_retries,
                ]);
                if ($retry < $max_retries) {
                    sleep(2 * $retry);
                    continue;
                }
                WP_to_CF_Logger::error('Bucket upload failed after retries', ['error' => $response->get_error_message()]);
                return false;
            }

            $status = wp_remote_retrieve_response_code($response);
            $data = json_decode(wp_remote_retrieve_body($response), true);

            if ($status >= 200 && $status < 300) {
                // 全部上传完成时返回 201，携带完成令牌
                return $data['result']['jwt'] ?? '';
            }

            if ($status >= 500 && $retry < $max_retries - 1) {
                $retry++;
                WP_to_CF_Logger::warning('Bucket upload server error, retrying', [
                    'status' => $status,
                    'retry' => $retry,
                ]);
                sleep(2 * $retry);
                continue;
            }

            WP_to_CF_Logger::error('Bucket upload error', [
                'status' => $status,
                'response' => $data,
            ]);
            return false;
        }

        return false;
    }

    /**
     * 上传/部署 Worker 脚本，附带静态资源并立即部署
     *
     * @param string $completion_jwt 资源上传完成令牌
     * @return string|false 成功返回 version/deployment id，失败返回 false
     */
    private function deploy_worker_script($completion_jwt)
    {
        $url = self::API_BASE_URL . "/accounts/{$this->account_id}/workers/scripts/" .
               rawurlencode($this->script_name);

        $metadata = [
            'main_module' => self::WORKER_MODULE_NAME,
            'compatibility_date' => self::COMPATIBILITY_DATE,
            'assets' => [
                'jwt' => $completion_jwt,
                'config' => [
                    // 自动处理尾部斜杠，匹配 /about → /about/index.html
                    'html_handling' => 'auto-trailing-slash',
                    // 未匹配到资源时返回自定义 404 页面（/404.html）
                    'not_found_handling' => '404-page',
                ],
            ],
            'bindings' => $this->build_worker_bindings(),
        ];

        $boundary = '----WPtoCF' . uniqid();
        $metadata_json = json_encode($metadata, JSON_UNESCAPED_SLASHES);
        $worker_script = $this->get_worker_script();

        $body = "--{$boundary}\r\n" .
                "Content-Disposition: form-data; name=\"metadata\"\r\n" .
                "Content-Type: application/json\r\n" .
                "\r\n" .
                $metadata_json . "\r\n" .
                "--{$boundary}\r\n" .
                "Content-Disposition: form-data; name=\"" . self::WORKER_MODULE_NAME . "\"; filename=\"" . self::WORKER_MODULE_NAME . "\"\r\n" .
                "Content-Type: application/javascript+module\r\n" .
                "\r\n" .
                $worker_script . "\r\n" .
                "--{$boundary}--\r\n";

        $response = wp_remote_request($url, [
            'method' => 'PUT',
            'timeout' => 120,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            $this->last_error = '部署 Worker 失败: ' . $response->get_error_message();
            WP_to_CF_Logger::error('Deploy worker failed', ['error' => $response->get_error_message()]);
            return false;
        }

        $status = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        WP_to_CF_Logger::info('Worker deploy response', [
            'status' => $status,
            'success' => $data['success'] ?? false,
        ]);

        if ($status >= 200 && $status < 300 && ($data['success'] ?? false)) {
            // 返回 version id（若可用），否则返回脚本名作为标识
            return $data['result']['id'] ?? $this->script_name;
        }

        $error_msg = $data['errors'][0]['message'] ?? '未知错误';
        $this->last_error = "部署 Worker 失败 (HTTP {$status}): {$error_msg}";
        WP_to_CF_Logger::error('Worker deploy failed', [
            'status' => $status,
            'errors' => $data['errors'] ?? [],
        ]);

        return false;
    }

    /**
     * 获取 Worker 入口脚本内容
     *
     * 从 includes/worker/worker.js 加载完整脚本（静态资源托管 + 表单/评论接收 +
     * 邮件发送 + pull/ack 拉取接口）。若文件缺失则回退为最小透传脚本。
     */
    private function get_worker_script(): string
    {
        $file = WPTOCF_PLUGIN_DIR . 'includes/worker/worker.js';
        if (file_exists($file)) {
            $content = file_get_contents($file);
            if ($content !== false && $content !== '') {
                return $content;
            }
        }

        WP_to_CF_Logger::warning('worker.js not found, falling back to passthrough script', ['file' => $file]);

        // 回退：仅透传静态资源
        return "export default {\n" .
               "  async fetch(request, env) {\n" .
               "    return env.ASSETS.fetch(request);\n" .
               "  }\n" .
               "};\n";
    }

    /**
     * 组装 Worker 绑定（bindings）
     *
     * 始终包含 ASSETS（静态资源）绑定；根据 WordPress 配置追加 D1 数据库绑定，
     * 以及表单接收 / 邮件发送所需的变量与密钥绑定。
     *
     * 敏感值使用 secret_text 类型（Cloudflare 侧加密存储），非敏感值使用 plain_text。
     *
     * @return array bindings 数组
     */
    private function build_worker_bindings(): array
    {
        $bindings = [
            ['type' => 'assets', 'name' => 'ASSETS'],
        ];

        // D1 数据库（用于暂存提交与邮件记录）
        $d1_id = get_option('wptocf_d1_database_id', '');
        if (!empty($d1_id)) {
            $bindings[] = [
                'type' => 'd1',
                'name' => 'DB',
                'id' => $d1_id,
            ];
        }

        $add_text = function (string $name, $value) use (&$bindings) {
            $value = (string) $value;
            if ($value !== '') {
                $bindings[] = ['type' => 'plain_text', 'name' => $name, 'text' => $value];
            }
        };
        $add_secret = function (string $name, $value) use (&$bindings) {
            $value = (string) $value;
            if ($value !== '') {
                $bindings[] = ['type' => 'secret_text', 'name' => $name, 'text' => $value];
            }
        };

        // pull/ack 共享密钥
        $add_secret('WPTOCF_PULL_SECRET', $this->get_decrypted_option('wptocf_worker_pull_secret'));

        // Turnstile 密钥（可选）
        $add_secret('WPTOCF_TURNSTILE_SECRET', $this->get_decrypted_option('wptocf_turnstile_secret_key'));

        // 邮件通用配置
        $provider = get_option('wptocf_mail_provider', 'none');
        $add_text('WPTOCF_MAIL_PROVIDER', $provider);
        $add_text('WPTOCF_MAIL_FROM', get_option('wptocf_mail_from', ''));
        $add_text('WPTOCF_MAIL_TO', get_option('wptocf_mail_to', ''));
        $add_text('WPTOCF_MAIL_SUBJECT', get_option('wptocf_mail_subject', ''));

        // 各后端配置
        if ($provider === 'ses') {
            $add_text('WPTOCF_SES_REGION', get_option('wptocf_ses_region', ''));
            $add_text('WPTOCF_SES_ACCESS_KEY_ID', get_option('wptocf_ses_access_key_id', ''));
            $add_secret('WPTOCF_SES_SECRET_ACCESS_KEY', $this->get_decrypted_option('wptocf_ses_secret_access_key'));
        } elseif ($provider === 'http') {
            $add_text('WPTOCF_HTTP_ENDPOINT', get_option('wptocf_http_endpoint', ''));
            $add_secret('WPTOCF_HTTP_API_KEY', $this->get_decrypted_option('wptocf_http_api_key'));
        } elseif ($provider === 'smtp') {
            $add_text('WPTOCF_SMTP_HOST', get_option('wptocf_smtp_host', ''));
            $add_text('WPTOCF_SMTP_PORT', get_option('wptocf_smtp_port', '587'));
            $add_text('WPTOCF_SMTP_USER', get_option('wptocf_smtp_user', ''));
            $add_secret('WPTOCF_SMTP_PASS', $this->get_decrypted_option('wptocf_smtp_pass'));
        }

        return $bindings;
    }

    /**
     * 读取并解密一个加密存储的选项
     *
     * @param string $option_name 选项名
     * @return string 解密后的明文，失败或为空时返回空字符串
     */
    private function get_decrypted_option(string $option_name): string
    {
        $encrypted = get_option($option_name, '');
        if (empty($encrypted)) {
            return '';
        }
        $decrypted = WP_to_CF_Crypto::decrypt($encrypted);
        return $decrypted !== false ? $decrypted : '';
    }

    /**
     * 尽力启用 workers.dev 子域名访问（非致命）
     */
    private function enable_workers_subdomain(): void
    {
        $url = self::API_BASE_URL . "/accounts/{$this->account_id}/workers/scripts/" .
               rawurlencode($this->script_name) . "/subdomain";

        $response = wp_remote_post($url, [
            'method' => 'POST',
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode(['enabled' => true, 'previews_enabled' => false]),
        ]);

        if (is_wp_error($response)) {
            WP_to_CF_Logger::warning('Enable workers.dev subdomain failed', [
                'error' => $response->get_error_message(),
            ]);
            return;
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            WP_to_CF_Logger::info('workers.dev subdomain not enabled (non-fatal)', ['status' => $status]);
        }
    }

    /**
     * 删除单个文件
     *
     * Workers 静态资源（与 Pages 一样）不支持通过 API 删除单个文件。
     * 文件的移除会在下一次全量/增量部署时通过重建 manifest 自动完成
     * （新 manifest 中不包含该文件，即视为已删除）。
     *
     * 此处为兼容调用方保留方法，作为无操作占位并记录日志。
     *
     * @param string $file_path 文件路径
     * @return bool 始终返回 true（删除将延迟到下次部署）
     */
    public function delete_file(string $file_path): bool
    {
        WP_to_CF_Logger::info('Single-file deletion is deferred to next deployment (Workers rebuilds assets from manifest)', [
            'file_path' => $file_path,
        ]);
        return true;
    }

    /**
     * 获取Content-Type
     */
    private function get_content_type($path)
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $types = [
            'html' => 'text/html; charset=utf-8',
            'htm' => 'text/html; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'xml' => 'application/xml; charset=utf-8',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'eot' => 'application/vnd.ms-fontobject',
            'txt' => 'text/plain; charset=utf-8',
            'pdf' => 'application/pdf',
            'map' => 'application/json',
        ];

        return $types[$ext] ?? 'application/octet-stream';
    }

    public function test_connection(): bool
    {
        if (!$this->is_configured()) {
            return false;
        }

        $url = self::API_BASE_URL . "/accounts/{$this->account_id}/workers/scripts/" .
               rawurlencode($this->script_name);

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $this->api_token],
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $status = wp_remote_retrieve_response_code($response);
        // 200 表示脚本存在；404 表示尚未部署但凭证有效，也视为连接正常
        return ($status >= 200 && $status < 300) || $status === 404;
    }

    public function get_project_info()
    {
        if (!$this->is_configured()) {
            return false;
        }

        $url = self::API_BASE_URL . "/accounts/{$this->account_id}/workers/scripts/" .
               rawurlencode($this->script_name);

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $this->api_token],
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data['result'] ?? false;
    }

    /**
     * 验证 API 凭证
     *
     * @param string $account_id Account ID
     * @param string $api_token API Token
     * @return array 验证结果
     */
    public static function validate_credentials(string $account_id, string $api_token): array
    {
        // 使用 Workers 脚本列表端点验证，需要 Workers Scripts 权限
        $url = self::API_BASE_URL . "/accounts/{$account_id}/workers/scripts";

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => ['Authorization' => 'Bearer ' . $api_token],
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => '网络错误: ' . $response->get_error_message(),
            ];
        }

        $status = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($status === 401 || $status === 403) {
            return [
                'success' => false,
                'message' => 'API Token 无效或权限不足（需要 Workers 脚本编辑权限）',
            ];
        }

        if ($status === 404) {
            return [
                'success' => false,
                'message' => 'Account ID 不存在',
            ];
        }

        if ($status >= 200 && $status < 300 && ($data['success'] ?? false)) {
            return [
                'success' => true,
                'message' => '验证成功',
                'account_name' => '',
            ];
        }

        return [
            'success' => false,
            'message' => $data['errors'][0]['message'] ?? '未知错误',
        ];
    }

    /**
     * 获取 Workers 脚本列表（作为可选择的部署目标）
     *
     * 保留原方法名以兼容调用方；返回结构中 projects 字段为脚本名称列表。
     *
     * @param string $account_id Account ID
     * @param string $api_token API Token
     * @return array 脚本列表
     */
    public static function list_pages_projects(string $account_id, string $api_token): array
    {
        $url = self::API_BASE_URL . "/accounts/{$account_id}/workers/scripts";

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $api_token],
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!($data['success'] ?? false)) {
            return ['success' => false, 'message' => $data['errors'][0]['message'] ?? '获取 Worker 列表失败'];
        }

        $projects = [];
        foreach ($data['result'] ?? [] as $script) {
            $projects[] = [
                'name' => $script['id'] ?? '',
                'subdomain' => '',
                'domains' => [],
                'created_on' => $script['created_on'] ?? '',
            ];
        }

        return ['success' => true, 'projects' => $projects];
    }

    /**
     * 获取域名列表（Zones）
     *
     * @param string $account_id Account ID
     * @param string $api_token API Token
     * @return array 域名列表
     */
    public static function list_zones(string $account_id, string $api_token): array
    {
        $url = self::API_BASE_URL . "/zones?account.id={$account_id}&per_page=50";

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $api_token],
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!($data['success'] ?? false)) {
            return ['success' => false, 'message' => $data['errors'][0]['message'] ?? '获取域名列表失败'];
        }

        $zones = [];
        foreach ($data['result'] ?? [] as $zone) {
            $zones[] = [
                'id' => $zone['id'],
                'name' => $zone['name'],
                'status' => $zone['status'],
            ];
        }

        return ['success' => true, 'zones' => $zones];
    }

    /**
     * 创建部署目标（Worker）
     *
     * Workers 无需预先创建：脚本会在首次部署（上传静态资源）时自动创建。
     * 此方法保留以兼容原有调用方，仅校验名称并返回成功提示。
     *
     * @param string $account_id Account ID
     * @param string $api_token API Token
     * @param string $project_name Worker 脚本名称
     * @return array 结果
     */
    public static function create_pages_project(string $account_id, string $api_token, string $project_name): array
    {
        $name = trim($project_name);

        // Worker 名称规则：小写字母、数字、连字符
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $name)) {
            return [
                'success' => false,
                'message' => 'Worker 名称只能包含小写字母、数字和连字符，且以字母或数字开头',
            ];
        }

        return [
            'success' => true,
            'message' => 'Worker 将在首次部署时自动创建',
            'project' => ['name' => $name],
        ];
    }

    /**
     * 在 D1 数据库上执行一条 SQL
     *
     * @param string $account_id  Account ID
     * @param string $api_token   API Token
     * @param string $database_id D1 数据库 ID
     * @param string $sql         SQL 语句
     * @param array  $params      绑定参数
     * @return array 执行结果
     */
    public static function d1_query(string $account_id, string $api_token, string $database_id, string $sql, array $params = []): array
    {
        $url = self::API_BASE_URL . "/accounts/{$account_id}/d1/database/{$database_id}/query";

        $response = wp_remote_post($url, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_token,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode(['sql' => $sql, 'params' => array_values($params)]),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $status = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($status >= 200 && $status < 300 && ($data['success'] ?? false)) {
            return ['success' => true, 'result' => $data['result'] ?? []];
        }

        return [
            'success' => false,
            'message' => $data['errors'][0]['message'] ?? "D1 查询失败 (HTTP {$status})",
        ];
    }

    /**
     * 初始化 D1 表结构（提交表 + 邮件记录表）
     *
     * @param string $account_id  Account ID
     * @param string $api_token   API Token
     * @param string $database_id D1 数据库 ID
     * @return array 结果
     */
    public static function provision_d1_schema(string $account_id, string $api_token, string $database_id): array
    {
        $statements = [
            "CREATE TABLE IF NOT EXISTS submissions (
                id TEXT PRIMARY KEY,
                type TEXT NOT NULL,
                form_id TEXT,
                post_id INTEGER DEFAULT 0,
                data TEXT,
                status TEXT DEFAULT 'pending',
                created_at TEXT NOT NULL,
                synced_at TEXT
            )",
            "CREATE INDEX IF NOT EXISTS idx_submissions_status ON submissions (status)",
            "CREATE TABLE IF NOT EXISTS emails (
                id TEXT PRIMARY KEY,
                submission_id TEXT,
                to_addr TEXT,
                from_addr TEXT,
                subject TEXT,
                body TEXT,
                provider TEXT,
                status TEXT DEFAULT 'pending',
                error TEXT,
                attempts INTEGER DEFAULT 0,
                created_at TEXT NOT NULL,
                sent_at TEXT,
                synced INTEGER DEFAULT 0
            )",
            "CREATE INDEX IF NOT EXISTS idx_emails_submission ON emails (submission_id)",
        ];

        foreach ($statements as $sql) {
            $result = self::d1_query($account_id, $api_token, $database_id, $sql);
            if (!$result['success']) {
                return [
                    'success' => false,
                    'message' => '初始化 D1 表结构失败: ' . ($result['message'] ?? '未知错误'),
                ];
            }
        }

        return ['success' => true, 'message' => 'D1 表结构初始化完成'];
    }

    /**
     * 获取 D1 数据库列表
     *
     * @param string $account_id Account ID
     * @param string $api_token API Token
     * @return array 数据库列表
     */
    public static function list_d1_databases(string $account_id, string $api_token): array
    {
        $url = self::API_BASE_URL . "/accounts/{$account_id}/d1/database";

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $api_token],
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!($data['success'] ?? false)) {
            return ['success' => false, 'message' => $data['errors'][0]['message'] ?? '获取D1数据库列表失败'];
        }

        $databases = [];
        foreach ($data['result'] ?? [] as $db) {
            $databases[] = [
                'uuid' => $db['uuid'],
                'name' => $db['name'],
                'created_at' => $db['created_at'] ?? '',
            ];
        }

        return ['success' => true, 'databases' => $databases];
    }

    /**
     * 创建 D1 数据库
     *
     * @param string $account_id Account ID
     * @param string $api_token API Token
     * @param string $db_name 数据库名称
     * @return array 创建结果
     */
    public static function create_d1_database(string $account_id, string $api_token, string $db_name): array
    {
        $url = self::API_BASE_URL . "/accounts/{$account_id}/d1/database";

        $response = wp_remote_post($url, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_token,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode(['name' => $db_name]),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $status = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($status >= 200 && $status < 300 && ($data['success'] ?? false)) {
            return [
                'success' => true,
                'message' => 'D1 数据库创建成功',
                'database' => $data['result'],
            ];
        }

        return [
            'success' => false,
            'message' => $data['errors'][0]['message'] ?? '创建D1数据库失败',
        ];
    }
}

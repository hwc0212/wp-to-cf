<?php
/**
 * Cloudflare Pages API 客户端
 * 
 * 使用正确的分批上传流程：
 * 1. 获取上传Token
 * 2. 分批上传文件到buckets（每批最多50MB）
 * 3. 通知Cloudflare新文件已上传
 * 4. 创建部署
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_to_CF_Cloudflare_API
{
    private const API_BASE_URL = 'https://api.cloudflare.com/client/v4';
    private const BUCKET_SIZE_LIMIT = 25 * 1024 * 1024; // 25MB per bucket
    private const BUCKET_FILE_LIMIT = 200; // 最多200个文件每批（减少以避免超时）
    private const REQUEST_TIMEOUT = 180;

    private $account_id;
    private $api_token;
    private $project_name;

    public function __construct()
    {
        $this->account_id = get_option('wptocf_account_id', '');
        $this->project_name = get_option('wptocf_project_name', '');

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
               !empty($this->project_name);
    }

    /**
     * 创建部署（分批上传）
     */
    public function create_deployment(array $files)
    {
        if (empty($files)) {
            WP_to_CF_Logger::error('No files to deploy');
            return false;
        }

        WP_to_CF_Logger::info('Starting deployment', ['file_count' => count($files)]);

        // 1. 标准化文件路径并计算哈希
        // 重要：Cloudflare Pages的hash计算方式是 sha256(base64内容 + 扩展名) 取前32字符
        // manifest路径必须以 / 开头
        $file_data = [];
        foreach ($files as $path => $content) {
            $path = ltrim(str_replace('\\', '/', $path), '/');
            if (empty($path)) continue;
            
            // 获取文件扩展名
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            
            // 计算hash: sha256(base64内容 + 扩展名) 取前32字符
            $base64_content = base64_encode($content);
            $hash = substr(hash('sha256', $base64_content . $ext), 0, 32);
            
            $file_data[$path] = [
                'content' => $content,
                'hash' => $hash,
                'size' => strlen($content),
            ];
        }

        WP_to_CF_Logger::info('Files prepared', ['count' => count($file_data)]);

        // 2. 获取上传Token
        $upload_token = $this->get_upload_token();
        if (!$upload_token) {
            WP_to_CF_Logger::error('Failed to get upload token');
            return false;
        }

        WP_to_CF_Logger::info('Upload token obtained');

        // 3. 分批上传文件
        $all_hashes = [];
        $buckets = $this->split_into_buckets($file_data);
        
        WP_to_CF_Logger::info('Split into buckets', ['bucket_count' => count($buckets)]);

        foreach ($buckets as $i => $bucket) {
            // 每个 bucket 前重置执行时间限制（共享主机可能有限制）
            if (function_exists('set_time_limit')) {
                @set_time_limit(300);
            }
            
            WP_to_CF_Logger::info('Uploading bucket', [
                'bucket' => $i + 1,
                'total' => count($buckets),
                'files' => count($bucket),
            ]);

            $result = $this->upload_bucket($upload_token, $bucket);
            if (!$result) {
                WP_to_CF_Logger::error('Failed to upload bucket', ['bucket' => $i + 1]);
                return false;
            }
            
            WP_to_CF_Logger::info('Bucket uploaded successfully', ['bucket' => $i + 1]);

            foreach ($bucket as $path => $data) {
                $all_hashes[] = $data['hash'];
            }
            
            // 在 bucket 之间添加短暂延迟，避免触发速率限制
            if ($i < count($buckets) - 1) {
                sleep(1);
            }
        }

        // 4. 通知Cloudflare文件已上传
        $upsert_result = $this->upsert_hashes($upload_token, $all_hashes);
        if (!$upsert_result) {
            WP_to_CF_Logger::error('Failed to upsert hashes');
            return false;
        }

        WP_to_CF_Logger::info('Hashes upserted');

        // 5. 创建部署（只发送manifest）
        // 重要：manifest路径必须以 / 开头，且不能有特殊字符
        $manifest = [];
        foreach ($file_data as $path => $data) {
            // 确保路径格式正确
            $clean_path = '/' . ltrim($path, '/');
            // 移除可能的双斜杠
            $clean_path = preg_replace('#/+#', '/', $clean_path);
            $manifest[$clean_path] = $data['hash'];
        }

        WP_to_CF_Logger::info('Manifest paths', [
            'sample' => array_slice(array_keys($manifest), 0, 5),
            'has_index' => isset($manifest['/index.html']),
            'total' => count($manifest),
        ]);

        $deployment_id = $this->finalize_deployment($manifest);
        
        if ($deployment_id) {
            WP_to_CF_Logger::info('Deployment created', ['id' => $deployment_id]);
            
            // 等待部署完成（最多60秒，不阻塞太久）
            $final_status = $this->wait_for_deployment($deployment_id, 60);
            WP_to_CF_Logger::info('Deployment final status', ['status' => $final_status]);
            
            // 即使超时也返回成功，因为部署已经创建
            // Cloudflare会在后台继续处理
        }

        return $deployment_id;
    }

    /**
     * 等待部署完成（非阻塞，只检查几次）
     */
    private function wait_for_deployment($deployment_id, $max_wait = 30)
    {
        $url = self::API_BASE_URL . "/accounts/{$this->account_id}/pages/projects/{$this->project_name}/deployments/{$deployment_id}";
        
        $start = time();
        $last_status = 'unknown';
        $check_count = 0;
        $max_checks = 6; // 最多检查6次，每次5秒
        
        while ($check_count < $max_checks && (time() - $start) < $max_wait) {
            sleep(5);
            $check_count++;
            
            $response = wp_remote_get($url, [
                'timeout' => 10,
                'headers' => ['Authorization' => 'Bearer ' . $this->api_token],
            ]);
            
            if (is_wp_error($response)) {
                WP_to_CF_Logger::warning('Deployment status check failed', [
                    'error' => $response->get_error_message(),
                ]);
                continue;
            }
            
            $data = json_decode(wp_remote_retrieve_body($response), true);
            $status = $data['result']['latest_stage']['name'] ?? 'unknown';
            $stage_status = $data['result']['latest_stage']['status'] ?? 'unknown';
            
            if ($status !== $last_status) {
                WP_to_CF_Logger::info('Deployment stage', [
                    'stage' => $status,
                    'status' => $stage_status,
                    'check' => $check_count,
                ]);
                $last_status = $status;
            }
            
            // 检查是否完成
            if ($stage_status === 'success' && $status === 'deploy') {
                return 'success';
            }
            
            if ($stage_status === 'failure') {
                WP_to_CF_Logger::error('Deployment failed', [
                    'stage' => $status,
                ]);
                return 'failure';
            }
        }
        
        // 超时但不算失败，Cloudflare会继续处理
        WP_to_CF_Logger::info('Deployment status check timeout, deployment continues in background');
        return 'pending';
    }

    /**
     * 获取上传Token
     */
    private function get_upload_token()
    {
        $url = self::API_BASE_URL . "/accounts/{$this->account_id}/pages/projects/{$this->project_name}/upload-token";

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_token,
            ],
        ]);

        if (is_wp_error($response)) {
            $this->last_error = '获取上传Token失败: ' . $response->get_error_message();
            WP_to_CF_Logger::error('Upload token request failed', ['error' => $response->get_error_message()]);
            return false;
        }

        $status = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status < 200 || $status >= 300) {
            $error_msg = $data['errors'][0]['message'] ?? '未知错误';
            $this->last_error = "获取上传Token失败 (HTTP {$status}): {$error_msg}";
            WP_to_CF_Logger::error('Upload token error', [
                'status' => $status,
                'errors' => $data['errors'] ?? [],
            ]);
            return false;
        }
        
        if (!isset($data['result']['jwt'])) {
            $this->last_error = '上传Token响应格式错误';
            WP_to_CF_Logger::error('Upload token not found in response', ['response' => $data]);
            return false;
        }

        return $data['result']['jwt'];
    }

    /**
     * 将文件分成多个bucket（每个最多25MB或300个文件）
     */
    private function split_into_buckets($file_data)
    {
        $buckets = [];
        $current_bucket = [];
        $current_size = 0;
        $current_count = 0;

        foreach ($file_data as $path => $data) {
            // 如果单个文件超过限制，单独一个bucket
            if ($data['size'] > self::BUCKET_SIZE_LIMIT) {
                if (!empty($current_bucket)) {
                    $buckets[] = $current_bucket;
                    $current_bucket = [];
                    $current_size = 0;
                    $current_count = 0;
                }
                $buckets[] = [$path => $data];
                continue;
            }

            // 如果加入当前bucket会超过大小或数量限制，开始新bucket
            if ($current_size + $data['size'] > self::BUCKET_SIZE_LIMIT || 
                $current_count >= self::BUCKET_FILE_LIMIT) {
                $buckets[] = $current_bucket;
                $current_bucket = [];
                $current_size = 0;
                $current_count = 0;
            }

            $current_bucket[$path] = $data;
            $current_size += $data['size'];
            $current_count++;
        }

        if (!empty($current_bucket)) {
            $buckets[] = $current_bucket;
        }

        return $buckets;
    }

    /**
     * 上传一个bucket的文件（带重试机制）
     */
    private function upload_bucket($upload_token, $bucket, $max_retries = 3)
    {
        $url = self::API_BASE_URL . '/pages/assets/upload';

        // 构建上传数据
        $upload_data = [];
        foreach ($bucket as $path => $data) {
            $upload_data[] = [
                'key' => $data['hash'],
                'value' => base64_encode($data['content']),
                'metadata' => [
                    'contentType' => $this->get_content_type($path),
                ],
                'base64' => true,
            ];
        }

        $body = json_encode($upload_data);
        $retry = 0;
        
        while ($retry < $max_retries) {
            $response = wp_remote_post($url, [
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => [
                    'Authorization' => 'Bearer ' . $upload_token,
                    'Content-Type' => 'application/json',
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
                    sleep(2 * $retry); // 指数退避
                    continue;
                }
                WP_to_CF_Logger::error('Bucket upload failed after retries', ['error' => $response->get_error_message()]);
                return false;
            }

            $status = wp_remote_retrieve_response_code($response);
            $data = json_decode(wp_remote_retrieve_body($response), true);

            if ($status >= 200 && $status < 300) {
                return true;
            }
            
            // 5xx 错误可以重试
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
     * 通知Cloudflare新文件已上传（带重试机制）
     */
    private function upsert_hashes($upload_token, $hashes, $max_retries = 3)
    {
        $url = self::API_BASE_URL . '/pages/assets/upsert-hashes';
        $body = json_encode(['hashes' => $hashes]);
        $retry = 0;

        while ($retry < $max_retries) {
            $response = wp_remote_post($url, [
                'timeout' => 120,
                'headers' => [
                    'Authorization' => 'Bearer ' . $upload_token,
                    'Content-Type' => 'application/json',
                ],
                'body' => $body,
            ]);

            if (is_wp_error($response)) {
                $retry++;
                WP_to_CF_Logger::warning('Upsert hashes failed, retrying', [
                    'error' => $response->get_error_message(),
                    'retry' => $retry,
                ]);
                if ($retry < $max_retries) {
                    sleep(2 * $retry);
                    continue;
                }
                WP_to_CF_Logger::error('Upsert hashes failed after retries', ['error' => $response->get_error_message()]);
                return false;
            }

            $status = wp_remote_retrieve_response_code($response);
            
            if ($status >= 200 && $status < 300) {
                return true;
            }
            
            if ($status >= 500 && $retry < $max_retries - 1) {
                $retry++;
                WP_to_CF_Logger::warning('Upsert hashes server error, retrying', [
                    'status' => $status,
                    'retry' => $retry,
                ]);
                sleep(2 * $retry);
                continue;
            }
            
            WP_to_CF_Logger::error('Upsert hashes error', ['status' => $status]);
            return false;
        }
        
        return false;
    }

    /**
     * 完成部署（发送manifest）
     */
    private function finalize_deployment($manifest)
    {
        $url = self::API_BASE_URL . "/accounts/{$this->account_id}/pages/projects/{$this->project_name}/deployments";

        // 构建multipart请求
        $boundary = '----WPtoCF' . uniqid();
        $manifest_json = json_encode($manifest, JSON_UNESCAPED_SLASHES);

        $body = "--{$boundary}\r\n" .
                "Content-Disposition: form-data; name=\"manifest\"\r\n" .
                "Content-Type: application/json\r\n" .
                "\r\n" .
                $manifest_json . "\r\n" .
                "--{$boundary}--";

        $response = wp_remote_post($url, [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            WP_to_CF_Logger::error('Finalize deployment failed', ['error' => $response->get_error_message()]);
            return false;
        }

        $status = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        WP_to_CF_Logger::info('Deployment response', [
            'status' => $status,
            'success' => $data['success'] ?? false,
        ]);

        if ($status >= 200 && $status < 300 && isset($data['result']['id'])) {
            return $data['result']['id'];
        }

        WP_to_CF_Logger::error('Deployment finalization failed', [
            'status' => $status,
            'errors' => $data['errors'] ?? [],
        ]);

        return false;
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

        $url = self::API_BASE_URL . "/accounts/{$this->account_id}/pages/projects/{$this->project_name}";
        
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $this->api_token],
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        return wp_remote_retrieve_response_code($response) >= 200 && 
               wp_remote_retrieve_response_code($response) < 300;
    }

    public function get_project_info()
    {
        if (!$this->is_configured()) {
            return false;
        }

        $url = self::API_BASE_URL . "/accounts/{$this->account_id}/pages/projects/{$this->project_name}";
        
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
        // 使用 Pages 项目列表端点验证，只需要 Cloudflare Pages 权限
        $url = self::API_BASE_URL . "/accounts/{$account_id}/pages/projects";
        
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
                'message' => 'API Token 无效或权限不足',
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
     * 获取 Pages 项目列表
     * 
     * @param string $account_id Account ID
     * @param string $api_token API Token
     * @return array 项目列表
     */
    public static function list_pages_projects(string $account_id, string $api_token): array
    {
        $url = self::API_BASE_URL . "/accounts/{$account_id}/pages/projects";
        
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $api_token],
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!($data['success'] ?? false)) {
            return ['success' => false, 'message' => $data['errors'][0]['message'] ?? '获取项目列表失败'];
        }

        $projects = [];
        foreach ($data['result'] ?? [] as $project) {
            $projects[] = [
                'name' => $project['name'],
                'subdomain' => $project['subdomain'] ?? '',
                'domains' => $project['domains'] ?? [],
                'created_on' => $project['created_on'] ?? '',
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
     * 创建 Pages 项目
     * 
     * @param string $account_id Account ID
     * @param string $api_token API Token
     * @param string $project_name 项目名称
     * @return array 创建结果
     */
    public static function create_pages_project(string $account_id, string $api_token, string $project_name): array
    {
        $url = self::API_BASE_URL . "/accounts/{$account_id}/pages/projects";
        
        $response = wp_remote_post($url, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_token,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'name' => $project_name,
                'production_branch' => 'main',
            ]),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $status = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($status >= 200 && $status < 300 && ($data['success'] ?? false)) {
            return [
                'success' => true,
                'message' => '项目创建成功',
                'project' => $data['result'],
            ];
        }

        return [
            'success' => false,
            'message' => $data['errors'][0]['message'] ?? '创建项目失败',
        ];
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

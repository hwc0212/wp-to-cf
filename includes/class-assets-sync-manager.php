<?php
/**
 * 资产同步管理器类
 * 
 * 负责协调资产扫描、哈希比对和批量上传
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_Assets_Sync_Manager
 * 
 * 管理全局资产同步流程
 */
class WP_to_CF_Assets_Sync_Manager
{
    /**
     * 资产扫描器实例
     */
    private WP_to_CF_Assets_Scanner $scanner;

    /**
     * 资产账本实例
     */
    private WP_to_CF_Assets_Ledger $ledger;

    /**
     * Cloudflare API 实例
     */
    private WP_to_CF_Cloudflare_API $api;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->scanner = new WP_to_CF_Assets_Scanner();
        $this->ledger = new WP_to_CF_Assets_Ledger();
        $this->api = new WP_to_CF_Cloudflare_API();
    }

    /**
     * 执行完整的资产同步
     *
     * @param int $batch_size 每批上传的文件数量（默认 100）
     * @return array 同步结果
     */
    public function sync_assets(int $batch_size = 100): array
    {
        WP_to_CF_Logger::info('Starting assets sync');

        $start_time = microtime(true);

        // 1. 扫描资产文件
        $assets = $this->scanner->scan_assets();

        if (empty($assets)) {
            WP_to_CF_Logger::info('No assets found to sync');
            return [
                'success' => true,
                'scanned' => 0,
                'uploaded' => 0,
                'skipped' => 0,
                'failed' => 0,
                'duration' => 0,
            ];
        }

        // 2. 计算当前哈希
        $current_hashes = $this->scanner->calculate_assets_hashes($assets);

        // 3. 确定需要上传的资产
        $assets_to_upload = $this->ledger->get_assets_to_upload($current_hashes);

        if (empty($assets_to_upload)) {
            WP_to_CF_Logger::info('All assets are up to date');
            return [
                'success' => true,
                'scanned' => count($assets),
                'uploaded' => 0,
                'skipped' => count($assets),
                'failed' => 0,
                'duration' => microtime(true) - $start_time,
            ];
        }

        // 4. 批量上传资产
        $result = $this->upload_assets_in_batches($assets, $assets_to_upload, $current_hashes, $batch_size);

        // 5. 更新最后更新时间
        $this->ledger->update_last_updated();

        $duration = microtime(true) - $start_time;

        WP_to_CF_Logger::info('Assets sync completed', [
            'scanned' => count($assets),
            'uploaded' => $result['uploaded'],
            'skipped' => count($assets) - count($assets_to_upload),
            'failed' => $result['failed'],
            'duration' => round($duration, 2) . 's',
        ]);

        return [
            'success' => $result['failed'] === 0,
            'scanned' => count($assets),
            'uploaded' => $result['uploaded'],
            'skipped' => count($assets) - count($assets_to_upload),
            'failed' => $result['failed'],
            'duration' => $duration,
        ];
    }

    /**
     * 分批上传资产
     *
     * @param array $assets           所有资产，格式：['relative_path' => 'absolute_path']
     * @param array $assets_to_upload 需要上传的资产路径数组
     * @param array $current_hashes   当前哈希，格式：['relative_path' => 'md5_hash']
     * @param int   $batch_size       每批大小
     * @return array 上传结果
     */
    private function upload_assets_in_batches(
        array $assets,
        array $assets_to_upload,
        array $current_hashes,
        int $batch_size
    ): array {
        $uploaded = 0;
        $failed = 0;
        $batches = array_chunk($assets_to_upload, $batch_size);

        WP_to_CF_Logger::info('Starting batch upload', [
            'total_assets' => count($assets_to_upload),
            'batch_size' => $batch_size,
            'total_batches' => count($batches),
        ]);

        foreach ($batches as $batch_index => $batch) {
            WP_to_CF_Logger::info('Processing batch', [
                'batch' => $batch_index + 1,
                'total_batches' => count($batches),
                'files_in_batch' => count($batch),
            ]);

            $result = $this->upload_batch($assets, $batch, $current_hashes);

            $uploaded += $result['uploaded'];
            $failed += $result['failed'];

            // 短暂延迟，避免 API 速率限制
            if ($batch_index < count($batches) - 1) {
                sleep(1);
            }
        }

        return [
            'uploaded' => $uploaded,
            'failed' => $failed,
        ];
    }

    /**
     * 上传单个批次
     *
     * @param array $assets         所有资产
     * @param array $batch          当前批次的资产路径
     * @param array $current_hashes 当前哈希
     * @return array 上传结果
     */
    private function upload_batch(array $assets, array $batch, array $current_hashes): array
    {
        $files_to_upload = [];

        // 准备文件内容
        foreach ($batch as $relative_path) {
            if (!isset($assets[$relative_path])) {
                continue;
            }

            $absolute_path = $assets[$relative_path];
            $content = $this->scanner->get_file_content($absolute_path);

            if ($content === false) {
                WP_to_CF_Logger::warning('Failed to read asset file', [
                    'relative_path' => $relative_path,
                    'absolute_path' => $absolute_path,
                ]);
                continue;
            }

            // 标准化路径（移除开头斜杠，替换反斜杠）
            $normalized_path = ltrim($relative_path, '/');
            $normalized_path = str_replace('\\', '/', $normalized_path);

            $files_to_upload[$normalized_path] = $content;
        }

        if (empty($files_to_upload)) {
            return [
                'uploaded' => 0,
                'failed' => count($batch),
            ];
        }

        // 上传到 Cloudflare Pages
        $deployment_id = $this->api->create_deployment($files_to_upload);

        if ($deployment_id === false) {
            WP_to_CF_Logger::error('Batch upload failed', [
                'files_count' => count($files_to_upload),
            ]);

            return [
                'uploaded' => 0,
                'failed' => count($batch),
            ];
        }

        // 更新账本
        $hashes_to_update = [];
        foreach ($batch as $relative_path) {
            if (isset($current_hashes[$relative_path])) {
                $hashes_to_update[$relative_path] = $current_hashes[$relative_path];
            }
        }

        $this->ledger->update_assets_hashes($hashes_to_update);

        WP_to_CF_Logger::info('Batch uploaded successfully', [
            'deployment_id' => $deployment_id,
            'files_count' => count($files_to_upload),
        ]);

        return [
            'uploaded' => count($files_to_upload),
            'failed' => 0,
        ];
    }

    /**
     * 获取同步统计信息
     *
     * @return array 统计信息
     */
    public function get_sync_stats(): array
    {
        $ledger_stats = $this->ledger->get_stats();

        return [
            'total_tracked_assets' => $ledger_stats['total_assets'],
            'last_sync' => $ledger_stats['last_updated'],
        ];
    }

    /**
     * 清空资产账本（强制重新同步所有资产）
     *
     * @return bool 是否成功
     */
    public function reset_sync(): bool
    {
        WP_to_CF_Logger::info('Resetting assets sync');
        return $this->ledger->clear_ledger();
    }
}

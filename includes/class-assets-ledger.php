<?php
/**
 * 资产账本类
 * 
 * 负责跟踪已上传资产的哈希值，避免重复上传
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_Assets_Ledger
 * 
 * 管理资产上传记录
 */
class WP_to_CF_Assets_Ledger
{
    /**
     * Option 名称
     */
    private const OPTION_NAME = 'wptocf_assets_ledger';

    /**
     * 获取资产账本
     *
     * @return array 账本数组，格式：['relative_path' => 'md5_hash']
     */
    public function get_ledger(): array
    {
        $ledger = get_option(self::OPTION_NAME, []);

        if (!is_array($ledger)) {
            $ledger = [];
        }

        return $ledger;
    }

    /**
     * 更新资产账本
     *
     * @param array $ledger 账本数组
     * @return bool 是否成功
     */
    public function update_ledger(array $ledger): bool
    {
        return update_option(self::OPTION_NAME, $ledger);
    }

    /**
     * 获取单个资产的哈希
     *
     * @param string $relative_path 相对路径
     * @return string|null 哈希值，不存在返回 null
     */
    public function get_asset_hash(string $relative_path): ?string
    {
        $ledger = $this->get_ledger();

        return $ledger[$relative_path] ?? null;
    }

    /**
     * 更新单个资产的哈希
     *
     * @param string $relative_path 相对路径
     * @param string $hash          MD5 哈希
     * @return bool 是否成功
     */
    public function update_asset_hash(string $relative_path, string $hash): bool
    {
        $ledger = $this->get_ledger();
        $ledger[$relative_path] = $hash;

        return $this->update_ledger($ledger);
    }

    /**
     * 批量更新资产哈希
     *
     * @param array $hashes 哈希数组，格式：['relative_path' => 'md5_hash']
     * @return bool 是否成功
     */
    public function update_assets_hashes(array $hashes): bool
    {
        $ledger = $this->get_ledger();

        foreach ($hashes as $relative_path => $hash) {
            $ledger[$relative_path] = $hash;
        }

        return $this->update_ledger($ledger);
    }

    /**
     * 检查资产是否需要上传
     *
     * @param string $relative_path 相对路径
     * @param string $current_hash  当前哈希
     * @return bool 是否需要上传
     */
    public function needs_upload(string $relative_path, string $current_hash): bool
    {
        $stored_hash = $this->get_asset_hash($relative_path);

        // 如果没有记录，需要上传
        if ($stored_hash === null) {
            return true;
        }

        // 如果哈希不同，需要上传
        return $stored_hash !== $current_hash;
    }

    /**
     * 获取需要上传的资产
     *
     * @param array $current_hashes 当前哈希数组，格式：['relative_path' => 'md5_hash']
     * @return array 需要上传的资产路径数组
     */
    public function get_assets_to_upload(array $current_hashes): array
    {
        $to_upload = [];

        foreach ($current_hashes as $relative_path => $current_hash) {
            if ($this->needs_upload($relative_path, $current_hash)) {
                $to_upload[] = $relative_path;
            }
        }

        WP_to_CF_Logger::info('Assets to upload determined', [
            'total_assets' => count($current_hashes),
            'to_upload' => count($to_upload),
            'up_to_date' => count($current_hashes) - count($to_upload),
        ]);

        return $to_upload;
    }

    /**
     * 清空资产账本
     *
     * @return bool 是否成功
     */
    public function clear_ledger(): bool
    {
        return delete_option(self::OPTION_NAME);
    }

    /**
     * 获取账本统计信息
     *
     * @return array 统计信息
     */
    public function get_stats(): array
    {
        $ledger = $this->get_ledger();

        return [
            'total_assets' => count($ledger),
            'last_updated' => get_option(self::OPTION_NAME . '_last_updated', 'Never'),
        ];
    }

    /**
     * 更新最后更新时间
     *
     * @return bool 是否成功
     */
    public function update_last_updated(): bool
    {
        return update_option(self::OPTION_NAME . '_last_updated', current_time('mysql'));
    }
}

<?php
/**
 * 日志记录类
 * 
 * 提供统一的日志记录接口
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_Logger
 * 
 * 日志记录工具类
 */
class WP_to_CF_Logger
{
    /**
     * 日志级别
     */
    private const LEVEL_ERROR = 'ERROR';
    private const LEVEL_WARNING = 'WARNING';
    private const LEVEL_INFO = 'INFO';

    /**
     * 记录错误日志
     * 
     * @param string $message 错误消息
     * @param array $context 上下文信息
     * @return void
     */
    public static function error(string $message, array $context = []): void
    {
        self::log(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * 记录警告日志
     * 
     * @param string $message 警告消息
     * @param array $context 上下文信息
     * @return void
     */
    public static function warning(string $message, array $context = []): void
    {
        self::log(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * 记录信息日志
     * 
     * @param string $message 信息消息
     * @param array $context 上下文信息
     * @return void
     */
    public static function info(string $message, array $context = []): void
    {
        self::log(self::LEVEL_INFO, $message, $context);
    }

    /**
     * 记录调试日志
     * 
     * @param string $message 调试消息
     * @param array $context 上下文信息
     * @return void
     */
    public static function debug(string $message, array $context = []): void
    {
        self::log(self::LEVEL_INFO, $message, $context);
    }

    /**
     * 记录日志
     * 
     * @param string $level 日志级别
     * @param string $message 日志消息
     * @param array $context 上下文信息
     * @return void
     */
    private static function log(string $level, string $message, array $context = []): void
    {
        // 只在 WP_DEBUG 模式下记录日志
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $timestamp = current_time('Y-m-d H:i:s');
        $context_str = !empty($context) ? ' | Context: ' . wp_json_encode($context) : '';
        
        $log_message = sprintf(
            '[%s] [WP-to-CF] [%s] %s%s',
            $timestamp,
            $level,
            $message,
            $context_str
        );

        error_log($log_message);
    }

    /**
     * 记录 API 调用日志
     * 
     * @param string $endpoint API 端点
     * @param array $request 请求数据
     * @param array $response 响应数据
     * @param bool $success 是否成功
     * @return void
     */
    public static function log_api_call(
        string $endpoint,
        array $request,
        array $response,
        bool $success
    ): void {
        $level = $success ? self::LEVEL_INFO : self::LEVEL_ERROR;
        $message = sprintf(
            'API Call to %s %s',
            $endpoint,
            $success ? 'succeeded' : 'failed'
        );
        
        $context = [
            'endpoint' => $endpoint,
            'request' => $request,
            'response' => $response,
        ];

        self::log($level, $message, $context);
    }

    /**
     * 记录任务失败日志
     * 
     * @param int $task_id 任务 ID
     * @param string $reason 失败原因
     * @param array $context 上下文信息
     * @return void
     */
    public static function log_task_failure(
        int $task_id,
        string $reason,
        array $context = []
    ): void {
        $message = sprintf('Task #%d failed: %s', $task_id, $reason);
        self::error($message, $context);
    }
}

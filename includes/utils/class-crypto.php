<?php
/**
 * 加密工具类
 * 
 * 使用 AES-256-CBC 算法加密和解密敏感数据
 *
 * @package WP_to_CF
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_to_CF_Crypto
 * 
 * 提供 AES-256-CBC 加密/解密功能
 */
class WP_to_CF_Crypto
{
    /**
     * 加密算法
     */
    private const CIPHER_METHOD = 'AES-256-CBC';

    /**
     * IV 长度（字节）
     */
    private const IV_LENGTH = 16;

    /**
     * PBKDF2 迭代次数
     */
    private const PBKDF2_ITERATIONS = 10000;

    /**
     * 固定盐值
     */
    private const SALT = 'wptocf_salt';

    /**
     * 检查加密功能是否可用
     * 
     * @return bool 如果 OpenSSL 扩展已加载且 AUTH_KEY 已定义，返回 true
     */
    public static function is_encryption_available(): bool
    {
        return extension_loaded('openssl') && 
               defined('AUTH_KEY') && 
               !empty(AUTH_KEY);
    }

    /**
     * 加密数据
     * 
     * 使用 AES-256-CBC 算法加密明文
     * 
     * @param string $plaintext 明文数据
     * @return string|false 加密后的 base64 编码字符串，失败返回 false
     */
    public static function encrypt(string $plaintext): string|false
    {
        try {
            // 检查加密功能是否可用
            if (!self::is_encryption_available()) {
                $error_msg = 'Encryption not available: ';
                if (!extension_loaded('openssl')) {
                    $error_msg .= 'OpenSSL extension not loaded';
                } elseif (!defined('AUTH_KEY') || empty(AUTH_KEY)) {
                    $error_msg .= 'AUTH_KEY not defined or empty';
                }
                self::log_error($error_msg);
                return false;
            }

            // 生成加密密钥
            $key = self::get_encryption_key();
            
            // 生成随机 IV
            $iv = self::generate_iv();
            
            // 加密数据
            $encrypted = openssl_encrypt(
                $plaintext,
                self::CIPHER_METHOD,
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($encrypted === false) {
                self::log_error('OpenSSL encryption failed: ' . openssl_error_string());
                return false;
            }

            // 将 IV 和密文组合并 base64 编码
            // 格式: base64(iv + encrypted_data)
            $result = base64_encode($iv . $encrypted);
            
            return $result;
            
        } catch (Exception $e) {
            self::log_error('Encryption exception: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * 解密数据
     * 
     * 使用 AES-256-CBC 算法解密密文
     * 
     * @param string $ciphertext 加密的 base64 编码字符串
     * @return string|false 解密后的明文，失败返回 false
     */
    public static function decrypt(string $ciphertext): string|false
    {
        try {
            // 检查加密功能是否可用
            if (!self::is_encryption_available()) {
                $error_msg = 'Decryption not available: ';
                if (!extension_loaded('openssl')) {
                    $error_msg .= 'OpenSSL extension not loaded';
                } elseif (!defined('AUTH_KEY') || empty(AUTH_KEY)) {
                    $error_msg .= 'AUTH_KEY not defined or empty';
                }
                self::log_error($error_msg);
                return false;
            }

            // Base64 解码
            $data = base64_decode($ciphertext, true);
            
            if ($data === false) {
                self::log_error('Base64 decode failed');
                return false;
            }

            // 检查数据长度
            if (strlen($data) < self::IV_LENGTH) {
                self::log_error('Ciphertext too short: ' . strlen($data) . ' bytes');
                return false;
            }

            // 提取 IV 和加密数据
            $iv = substr($data, 0, self::IV_LENGTH);
            $encrypted = substr($data, self::IV_LENGTH);

            // 生成解密密钥
            $key = self::get_encryption_key();

            // 解密数据
            $decrypted = openssl_decrypt(
                $encrypted,
                self::CIPHER_METHOD,
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($decrypted === false) {
                self::log_error('OpenSSL decryption failed: ' . openssl_error_string());
                return false;
            }

            return $decrypted;
            
        } catch (Exception $e) {
            self::log_error('Decryption exception: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * 获取加密密钥
     * 
     * 使用 WordPress AUTH_KEY 派生 256 位加密密钥
     * 
     * @return string 32 字节的加密密钥
     */
    private static function get_encryption_key(): string
    {
        // 使用 hash_pbkdf2 从 AUTH_KEY 派生密钥
        // 这确保即使 AUTH_KEY 长度不足，也能生成 256 位密钥
        return hash_pbkdf2(
            'sha256',
            AUTH_KEY,
            self::SALT,
            self::PBKDF2_ITERATIONS,
            32,  // 密钥长度（字节）
            true // 返回原始二进制数据
        );
    }

    /**
     * 生成随机初始化向量 (IV)
     * 
     * @return string 16 字节的随机 IV
     * @throws Exception 如果无法生成随机字节
     */
    private static function generate_iv(): string
    {
        return random_bytes(self::IV_LENGTH);
    }

    /**
     * 记录错误日志
     * 
     * @param string $message 错误消息
     * @param array $context 上下文信息
     * @return void
     */
    private static function log_error(string $message, array $context = []): void
    {
        // 如果 Logger 类已加载，使用它记录日志
        if (class_exists('WP_to_CF_Logger')) {
            WP_to_CF_Logger::error('[Crypto] ' . $message, $context);
        } elseif (defined('WP_DEBUG') && WP_DEBUG) {
            // 否则直接使用 error_log
            error_log('[WP-to-CF Crypto] ' . $message);
        }
    }
}

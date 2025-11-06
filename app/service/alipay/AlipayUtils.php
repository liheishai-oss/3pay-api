<?php

namespace app\service\alipay;

use Exception;
use support\Log;

/**
 * 支付宝工具类
 */
class AlipayUtils
{
    /**
     * 格式化金额（分转元）
     * @param int $amount 金额（分）
     * @return string 格式化后的金额（元）
     */
    public static function formatAmount(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }
    
    /**
     * 解析金额（元转分）
     * @param string $amount 金额（元）
     * @return int 解析后的金额（分）
     */
    public static function parseAmount(string $amount): int
    {
        return (int) round(floatval($amount) * 100);
    }
    
    /**
     * 生成随机字符串
     * @param int $length 长度
     * @return string 随机字符串
     */
    public static function generateRandomString(int $length = 16): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $randomString;
    }
    
    /**
     * 验证订单号格式
     * @param string $orderNumber 订单号
     * @return bool 是否有效
     */
    public static function validateOrderNumber(string $orderNumber): bool
    {
        // 订单号长度限制：6-64位
        if (strlen($orderNumber) < 6 || strlen($orderNumber) > 64) {
            return false;
        }
        
        // 只能包含字母、数字、下划线、短横线
        return preg_match('/^[a-zA-Z0-9_-]+$/', $orderNumber) === 1;
    }
    
    /**
     * 验证金额格式
     * @param string $amount 金额
     * @return bool 是否有效
     */
    public static function validateAmount(string $amount): bool
    {
        // 金额必须为正数，最多2位小数
        return preg_match('/^[0-9]+(\.[0-9]{1,2})?$/', $amount) === 1 && floatval($amount) > 0;
    }
    
    /**
     * 验证支付宝用户ID格式
     * @param string $userId 用户ID
     * @return bool 是否有效
     */
    public static function validateUserId(string $userId): bool
    {
        // 用户ID长度限制：16-28位
        if (strlen($userId) < 16 || strlen($userId) > 28) {
            return false;
        }
        
        // 只能包含数字
        return preg_match('/^[0-9]+$/', $userId) === 1;
    }
    
    /**
     * 验证授权码格式
     * @param string $authCode 授权码
     * @return bool 是否有效
     */
    public static function validateAuthCode(string $authCode): bool
    {
        // 授权码长度限制：16-32位
        if (strlen($authCode) < 16 || strlen($authCode) > 32) {
            return false;
        }
        
        // 只能包含字母和数字
        return preg_match('/^[a-zA-Z0-9]+$/', $authCode) === 1;
    }
    
    /**
     * 验证回调URL格式
     * @param string $url URL
     * @return bool 是否有效
     */
    public static function validateCallbackUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
    
    /**
     * 获取客户端IP地址
     * @return string IP地址
     */
    public static function getClientIp(): string
    {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * 记录操作日志
     * @param string $action 操作类型
     * @param array $data 操作数据
     * @param string $level 日志级别
     */
    public static function logOperation(string $action, array $data = [], string $level = 'info'): void
    {
        $logData = array_merge([
            'action' => $action,
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => self::getClientIp(),
        ], $data);
        
        switch ($level) {
            case 'error':
                Log::error("支付宝操作: {$action}", $logData);
                break;
            case 'warning':
                Log::warning("支付宝操作: {$action}", $logData);
                break;
            case 'debug':
                Log::debug("支付宝操作: {$action}", $logData);
                break;
            default:
                Log::info("支付宝操作: {$action}", $logData);
                break;
        }
    }
    
    /**
     * 安全记录敏感信息（脱敏处理）
     * @param array $data 原始数据
     * @param array $sensitiveFields 敏感字段
     * @return array 脱敏后的数据
     */
    public static function maskSensitiveData(array $data, array $sensitiveFields = []): array
    {
        $defaultSensitiveFields = [
            'AppPrivateKey', 'merchantPrivateKey', 'access_token', 'refresh_token',
            'auth_code', 'password', 'secret', 'key'
        ];
        
        $sensitiveFields = array_merge($defaultSensitiveFields, $sensitiveFields);
        
        foreach ($sensitiveFields as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $value = $data[$field];
                if (strlen($value) > 8) {
                    $data[$field] = substr($value, 0, 4) . '****' . substr($value, -4);
                } else {
                    $data[$field] = str_repeat('*', strlen($value));
                }
            }
        }
        
        return $data;
    }
    
    /**
     * 检查是否为沙箱环境
     * @param array $paymentInfo 支付配置信息
     * @return bool 是否为沙箱环境
     */
    public static function isSandbox(array $paymentInfo): bool
    {
        return isset($paymentInfo['sandbox']) && $paymentInfo['sandbox'] === true;
    }
    
    /**
     * 获取环境标识
     * @param array $paymentInfo 支付配置信息
     * @return string 环境标识
     */
    public static function getEnvironment(array $paymentInfo): string
    {
        return self::isSandbox($paymentInfo) ? 'sandbox' : 'production';
    }
    
    /**
     * 格式化时间戳
     * @param string $timestamp 时间戳
     * @param string $format 格式
     * @return string 格式化后的时间
     */
    public static function formatTimestamp(string $timestamp, string $format = 'Y-m-d H:i:s'): string
    {
        if (empty($timestamp)) {
            return '';
        }
        
        try {
            $date = new \DateTime($timestamp);
            return $date->format($format);
        } catch (Exception $e) {
            return $timestamp;
        }
    }
    
    /**
     * 计算签名（用于验证）
     * @param array $params 参数
     * @param string $privateKey 私钥
     * @param string $signType 签名类型
     * @return string 签名
     */
    public static function calculateSign(array $params, string $privateKey, string $signType = 'RSA2'): string
    {
        // 过滤空值并排序
        $filteredParams = array_filter($params, function($value) {
            return $value !== '' && $value !== null;
        });
        
        ksort($filteredParams);
        
        // 拼接字符串
        $signString = '';
        foreach ($filteredParams as $key => $value) {
            $signString .= $key . '=' . $value . '&';
        }
        $signString = rtrim($signString, '&');
        
        // 计算签名
        $sign = '';
        if ($signType === 'RSA2') {
            openssl_sign($signString, $sign, $privateKey, OPENSSL_ALGO_SHA256);
        } else {
            openssl_sign($signString, $sign, $privateKey, OPENSSL_ALGO_SHA1);
        }
        
        return base64_encode($sign);
    }
    
    /**
     * 验证签名
     * @param array $params 参数
     * @param string $sign 签名
     * @param string $publicKey 公钥
     * @param string $signType 签名类型
     * @return bool 是否验证通过
     */
    public static function verifySign(array $params, string $sign, string $publicKey, string $signType = 'RSA2'): bool
    {
        // 过滤空值并排序
        $filteredParams = array_filter($params, function($value) {
            return $value !== '' && $value !== null;
        });
        
        ksort($filteredParams);
        
        // 拼接字符串
        $signString = '';
        foreach ($filteredParams as $key => $value) {
            $signString .= $key . '=' . $value . '&';
        }
        $signString = rtrim($signString, '&');
        
        // 验证签名
        $signData = base64_decode($sign);
        if ($signType === 'RSA2') {
            return openssl_verify($signString, $signData, $publicKey, OPENSSL_ALGO_SHA256) === 1;
        } else {
            return openssl_verify($signString, $signData, $publicKey, OPENSSL_ALGO_SHA1) === 1;
        }
    }
}

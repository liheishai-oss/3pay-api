<?php

namespace app\common\helpers;

class SignatureHelper
{
    /**
     * 生成签名
     * 
     * @param array $params 参数数组
     * @param string $secretKey 密钥
     * @param string $signType 签名类型：standard(标准key=value&格式) 或 simple(只拼接值)
     * @param string $algo 签名算法：md5, sha256等
     * @return string
     */
    public static function generate(array $params, string $secretKey, string $signType = 'standard', string $algo = 'md5'): string
    {
        // 移除不需要签名的字段
        unset($params['sign']);
        unset($params['client_ip']);
        unset($params['entities_id']);
        unset($params['debug']);
        
        // 按键名排序
        ksort($params);
        
        // 构建签名字符串
        $stringToSign = '';
        
        if ($signType === 'standard') {
            // 标准格式：key=value&key2=value2&...&key=secretKey
            foreach ($params as $key => $value) {
                if ($value !== '' && $value !== null) {
                    $stringToSign .= $key . '=' . $value . '&';
                }
            }
            $stringToSign .= 'key=' . $secretKey;
        } else {
            // 简单格式：value1value2...secretKey
            foreach ($params as $key => $value) {
                if ($value !== '' && $value !== null) {
                    $stringToSign .= (string)$value;
                }
            }
            $stringToSign .= $secretKey;
        }
        
        // 计算签名
        switch ($algo) {
            case 'sha256':
                $signature = hash('sha256', $stringToSign);
                break;
            case 'md5':
            default:
                $signature = md5($stringToSign);
                break;
        }
        
        return strtoupper($signature);
    }

    /**
     * 验证签名
     * 
     * @param array $params 参数数组
     * @param string $secretKey 密钥
     * @param string $signType 签名类型
     * @param string $algo 签名算法
     * @return bool
     */
    public static function verify(array $params, string $secretKey, string $signType = 'standard', string $algo = 'md5'): bool
    {
        if (!isset($params['sign']) || empty($params['sign'])) {
            return false;
        }

        $expectedSign = self::generate($params, $secretKey, $signType, $algo);

        // 使用hash_equals防止时间攻击
        return hash_equals(strtoupper($expectedSign), strtoupper($params['sign']));
    }
    
    /**
     * 获取签名字符串（用于日志记录）
     * 
     * @param array $params 参数数组
     * @param string $secretKey 密钥
     * @param string $signType 签名类型：standard(标准key=value&格式) 或 simple(只拼接值)
     * @return string 签名字符串
     */
    public static function getStringToSign(array $params, string $secretKey, string $signType = 'standard'): string
    {
        // 移除不需要签名的字段
        unset($params['sign']);
        unset($params['client_ip']);
        unset($params['entities_id']);
        unset($params['debug']);
        
        // 按键名排序
        ksort($params);
        
        // 构建签名字符串
        $stringToSign = '';
        
        if ($signType === 'standard') {
            // 标准格式：key=value&key2=value2&...&key=secretKey
            foreach ($params as $key => $value) {
                if ($value !== '' && $value !== null) {
                    $stringToSign .= $key . '=' . $value . '&';
                }
            }
            $stringToSign .= 'key=' . $secretKey;
        } else {
            // 简单格式：value1value2...secretKey
            foreach ($params as $key => $value) {
                if ($value !== '' && $value !== null) {
                    $stringToSign .= (string)$value;
                }
            }
            $stringToSign .= $secretKey;
        }
        return $stringToSign;
    }
    
    /**
     * 生成随机字符串（用于生成nonce等）
     * 
     * @param int $length 长度
     * @return string
     */
    public static function generateNonce(int $length = 32): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $nonce = '';
        for ($i = 0; $i < $length; $i++) {
            $nonce .= $characters[mt_rand(0, strlen($characters) - 1)];
        }
        return $nonce;
    }
}
<?php

namespace app\common\helpers;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * IP白名单验证辅助类
 */
class IpWhitelistHelper
{
    /**
     * 检查IP是否在白名单中
     * 
     * @param string $ip 要检查的IP地址
     * @param string|null $whitelist IP白名单字符串（多个IP用逗号分隔，支持CIDR格式）
     * @return bool 是否在白名单中
     */
    public static function checkIp(string $ip, ?string $whitelist): bool
    {
        // 如果白名单为空，则允许所有IP
        if (empty($whitelist) || trim($whitelist) === '') {
            return true;
        }

        // 解析白名单（支持逗号分隔）
        $whitelistIps = array_map('trim', explode(',', $whitelist));
        $whitelistIps = array_filter($whitelistIps, function($ip) {
            return !empty($ip);
        });

        // 如果白名单为空，则允许所有IP
        if (empty($whitelistIps)) {
            return true;
        }

        // 使用Symfony的IpUtils检查IP是否在白名单中（支持CIDR格式）
        return IpUtils::checkIp($ip, $whitelistIps);
    }

    /**
     * 验证IP白名单（用于订单创建等API）
     * 
     * @param string $ip 请求IP
     * @param string|null $whitelist 商户IP白名单
     * @return array ['allowed' => bool, 'message' => string]
     */
    public static function validateIp(string $ip, ?string $whitelist): array
    {
        if (self::checkIp($ip, $whitelist)) {
            return [
                'allowed' => true,
                'message' => 'IP验证通过'
            ];
        }

        return [
            'allowed' => false,
            'message' => 'IP地址不在白名单中'
        ];
    }
}


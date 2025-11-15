<?php

namespace app\common\helpers;

class IpWhitelistHelper
{
    /**
     * 验证IP是否在白名单中
     * 
     * @param string $clientIp 客户端IP地址
     * @param string $whitelist IP白名单（支持单个IP、CIDR格式、多个IP用逗号或换行符分隔）
     * @return array ['allowed' => bool, 'matched_rule' => string|null]
     */
    public static function validateIp(string $clientIp, string $whitelist): array
    {
        // 如果白名单为空，默认允许
        if (empty($whitelist)) {
            return ['allowed' => true, 'matched_rule' => null];
        }

        // 标准化IP地址
        $clientIp = trim($clientIp);
        
        // 分割白名单（支持逗号、换行符、分号分隔）
        $whitelistItems = preg_split('/[,\n\r;]+/', $whitelist);
        
        foreach ($whitelistItems as $item) {
            $item = trim($item);
            if (empty($item)) {
                continue;
            }
            
            // 检查是否是CIDR格式（如 192.168.1.0/24）
            if (strpos($item, '/') !== false) {
                if (self::ipInCidr($clientIp, $item)) {
                    return ['allowed' => true, 'matched_rule' => $item];
                }
            } else {
                // 直接匹配IP地址
                if ($clientIp === $item) {
                    return ['allowed' => true, 'matched_rule' => $item];
                }
            }
        }
        
        // 没有匹配到任何规则
        return ['allowed' => false, 'matched_rule' => null];
    }
    
    /**
     * 检查IP是否在CIDR范围内
     * 
     * @param string $ip IP地址
     * @param string $cidr CIDR格式（如 192.168.1.0/24）
     * @return bool
     */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        list($subnet, $mask) = explode('/', $cidr);
        
        // 验证IP和子网格式
        if (!filter_var($ip, FILTER_VALIDATE_IP) || !filter_var($subnet, FILTER_VALIDATE_IP)) {
            return false;
        }
        
        // 转换IP为长整型
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        
        // 计算掩码
        $mask = (int)$mask;
        if ($mask < 0 || $mask > 32) {
            return false;
        }
        
        $maskLong = -1 << (32 - $mask);
        
        // 检查IP是否在子网内
        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
}













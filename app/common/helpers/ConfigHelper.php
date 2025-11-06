<?php
namespace app\common\helpers;

use app\common\constants\SystemConstants;
use app\model\SystemConfig;
use Throwable;

class ConfigHelper
{
    public static function getAll(int $merchant_id = 0): array
    {
        try {
            // 直接查询数据库，不依赖缓存
            $configs = $merchant_id > 0
                ? SystemConfig::where('merchant_id', $merchant_id)->pluck('config_value', 'config_key')
                : SystemConfig::pluck('config_value', 'config_key');
            return $configs->toArray();
        } catch (Throwable $e) {
            echo $e->getMessage();
            // 如果数据库查询失败，返回空数组
            return [];
        }
    }
}

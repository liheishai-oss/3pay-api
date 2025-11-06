<?php

namespace app\common\helpers;

use support\Redis;

class RedisBloomHelper
{
    const DEFAULT_FILTER = 'order_id_filter';

    /**
     * 初始化布隆过滤器
     */
    public static function init(string $filter = self::DEFAULT_FILTER, float $errorRate = 0.01, int $capacity = 100000, int $ttl = 600): void
    {
        if (!Redis::exists($filter)) {
            Redis::rawCommand('BF.RESERVE', $filter, $errorRate, $capacity);
            Redis::expire($filter, $ttl);
        };

    }

    /**
     * 添加元素
     */
    public static function add(string $item, string $filter = self::DEFAULT_FILTER)
    {
        return Redis::rawCommand('BF.ADD', $filter, $item);
    }

    /**
     * 是否存在
     */
    public static function exists(string $item, string $filter = self::DEFAULT_FILTER): bool
    {
        return Redis::rawCommand('BF.EXISTS', $filter, $item) === 1;
    }
}

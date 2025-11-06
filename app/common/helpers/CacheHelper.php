<?php
namespace app\common\helpers;


use support\Log;
use support\Redis;

class CacheHelper
{
    /**
     * 获取缓存数据，支持降级 + 旁路缓存
     *
     * @param string $key Redis key
     * @param callable $dbCallback 数据库查询回调，返回数组
     * @param int $expire 缓存过期时间（秒）
     * @return array|null
     */
    public static function getCacheOrDb(string $key, callable $dbCallback, int $expire = 86400): ?array
    {
        try{
            $data = Redis::hGetAll($key);

            if(!empty($data)){

                return $data;
            }
        } catch (\Throwable $e) {
        }

        try{

            $data = $dbCallback();
            if (!$data) {
                return null;
            }

            // 判断类型，如果是对象且有 toArray 方法就调用，否则直接使用数组
            if (is_object($data) && method_exists($data, 'toArray')) {
                $data = $data->toArray();
            } elseif (!is_array($data)) {

                return null;
            }
            // 尝试写入 Redis（仅在Redis正常时写入）
            try {
                foreach ($data as $field => $value) {
                    Redis::hSet($key, $field, $value);
                }
                Redis::expire($key, $expire);
                
                Log::debug('数据已写入Redis', [
                    'key' => $key,
                    'expire' => $expire
                ]);
            } catch (\Throwable $e) {
                // Redis写入失败，记录警告但不影响业务逻辑
                Log::warning('Redis写入失败，跳过缓存', [
                    'key' => $key,
                    'error' => $e->getMessage()
                ]);
            }

            return $data;
        } catch (\Throwable $e) {
            echo $e->getMessage().$e->getFile().$e->getLine();
            return null;
        }


    }

    /**
     * 获取列表/复杂结构缓存，使用JSON字符串存储
     * 适用于需要缓存数组列表、嵌套数组等非哈希结构的场景
     *
     * @param string $key Redis key
     * @param callable $dbCallback 返回数组或可被 json_encode 的结构
     * @param int $expire 过期时间（秒）
     * @return array|null
     */
    public static function getJsonCacheOrDb(string $key, callable $dbCallback, int $expire = 300): ?array
    {
        try {
            $cached = Redis::get($key);
            if ($cached !== false && $cached !== null) {
                $decoded = json_decode($cached, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (\Throwable $e) {
        }

        try {
            $data = $dbCallback();
            if (is_object($data) && method_exists($data, 'toArray')) {
                $data = $data->toArray();
            }
            if (!is_array($data)) {
                return null;
            }
            try {
                Redis::setex($key, $expire, json_encode($data, JSON_UNESCAPED_UNICODE));
            } catch (\Throwable $e) {
            }
            return $data;
        } catch (\Throwable $e) {
            return null;
        }
    }
}

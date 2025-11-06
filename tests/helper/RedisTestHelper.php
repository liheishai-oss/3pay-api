<?php

namespace tests\helper;

class RedisTestHelper
{
    public static function flushAll(): void
    {
        $redis = new \Redis();
        $redis->connect('redis', 6379);
        $redis->flushAll();
    }
}
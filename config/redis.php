<?php

return [
    'default' => [
        'password' => '',
        'host' => 'redis',
        'port' => 6379,
        'database' => 0,
        'pool' => [
            'max_connections' => 50,     // 支持500并发
            'min_connections' => 10,     // 保持最小连接
            'wait_timeout' => 10,        // 增加等待时间
            'idle_timeout' => 300,       // 5分钟空闲超时
            'heartbeat_interval' => 30,  // 30秒心跳
        ],
    ]
];






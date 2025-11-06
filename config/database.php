<?php

return  [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'driver'      => 'mysql',
            'host'        => 'mysql',
            'port'        => '3306',
            'database'    => 'third_party_payment',
            'username'    => 'third_party_payment',
            'password'    => 'rA8f@D2kLmZx!3pQ',
            'charset'     => 'utf8mb4',
            'collation'   => 'utf8mb4_general_ci',
            'prefix'      => '',
            'strict'      => true,
            'engine'      => null,
            'options'   => [
                PDO::ATTR_EMULATE_PREPARES => false, // Must be false for Swoole and Swow drivers.
            ],
            'pool' => [
                'max_connections' => 100,    // 支持500并发
                'min_connections' => 20,     // 保持最小连接
                'wait_timeout' => 10,        // 增加等待时间
                'idle_timeout' => 300,       // 5分钟空闲超时
                'heartbeat_interval' => 30,  // 30秒心跳
            ],


        ],
    ],
];
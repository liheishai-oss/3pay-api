<?php

/**
 * 监控配置
 */

return [
    // 响应时间监控配置
    'response_time' => [
        'enabled' => true,                    // 是否启用响应时间监控
        'threshold' => 2.0,                   // 响应时间阈值（秒）
        'alert_cooldown' => 5,                // 告警冷却时间（分钟）
    ],
    
    // 非正常响应监控配置
    'abnormal_response' => [
        'enabled' => true,                    // 是否启用非正常响应监控
        'error_rate_threshold' => 0.3,        // 错误率阈值（30%）
        'min_requests_for_alert' => 10,       // 最少请求数才发送告警
        'alert_cooldown' => 5,                // 告警冷却时间（分钟）
    ],
    
    // 统计配置
    'statistics' => [
        'enabled' => true,                    // 是否启用统计
        'retention_hours' => 24,              // 统计数据保留时间（小时）
        'aggregation_interval' => 60,         // 聚合间隔（秒）
    ],
    
    // 告警配置
    'alerts' => [
        'telegram' => [
            'enabled' => true,                // 是否启用Telegram告警
            'rate_limits' => [
                'slow_response' => 20,        // 慢响应告警频率限制（每分钟）
                'abnormal_response' => 15,    // 非正常响应告警频率限制（每分钟）
            ]
        ],
        'log' => [
            'enabled' => true,                // 是否启用日志告警
            'level' => 'warning',             // 日志级别
        ]
    ],
    
    // 监控的供应商和通道
    'monitored_entities' => [
        'suppliers' => [
            'GemPayment',
            'AlipayWeb', 
            'Haitun'
        ],
        'channels' => [
            'gem_payment',
            'alipay_web',
            'haitun'
        ]
    ],
    
    // 错误类型分类
    'error_types' => [
        'payment_failed' => '支付失败',
        'exception' => '系统异常',
        'timeout' => '请求超时',
        'network_error' => '网络错误',
        'invalid_response' => '无效响应',
        'authentication_failed' => '认证失败',
        'insufficient_funds' => '余额不足',
        'invalid_parameters' => '参数错误'
    ],
    
    // HTTP状态码分类
    'http_status_categories' => [
        '2xx' => '成功',
        '4xx' => '客户端错误',
        '5xx' => '服务器错误',
        'timeout' => '超时',
        'network_error' => '网络错误'
    ]
];

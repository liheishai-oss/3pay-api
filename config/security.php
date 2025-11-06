<?php

/**
 * 安全配置建议
 * 请根据实际环境调整以下配置
 */

return [
    // 签名验证配置
    'signature' => [
        'enabled' => true,        // 是否启用签名验证
        'algorithm' => 'sha256',  // 签名算法
        'timeout' => 300,          // 签名有效期（秒）
    ],
    
    // 防重放攻击配置（已禁用）
    'anti_replay' => [
        'enabled' => false,        // 已禁用防重放验证
    ],
    
    // 频率限制配置（开放平台已禁用）
    'rate_limit' => [
        'enabled' => false,        // 开放平台不启用频率限制
        'limits' => [
            'create' => 0,         // 无限制
            'query' => 0,          // 无限制
            'balance' => 0         // 无限制
        ]
    ],
    
    // IP白名单配置
    'ip_whitelist' => [
        'enabled' => true,         // 是否启用IP白名单
        'log_violations' => true,  // 是否记录违规日志
    ],
    
    // 日志配置
    'security_log' => [
        'enabled' => true,         // 是否启用安全日志
        'level' => 'info',        // 日志级别
        'file' => 'security.log'  // 日志文件
    ]
];
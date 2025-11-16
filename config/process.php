<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use support\Log;
use support\Request;
use app\process\Http;

global $argv;

return [

    'webman' => [
        'handler' => Http::class,
        'listen' => 'http://0.0.0.0:8787',
        'count' => cpu_count() * 4,
        'user' => '',
        'group' => '',
        'reusePort' => false,
        'eventLoop' => '',
        'context' => [],
        'constructor' => [
            'requestClass' => Request::class,
            'logger' => Log::channel('default'),
            'appPath' => app_path(),
            'publicPath' => public_path()
        ]
    ],

    // File update detection and automatic reload
    'monitor' => [
        'handler' => app\process\Monitor::class,
        'reloadable' => false,
        'constructor' => [
            // Monitor these directories
            'monitorDir' => array_merge([
                app_path(),
                config_path(),
                base_path() . '/process',
                base_path() . '/support',
                base_path() . '/resource',
                base_path() . '/.env',
            ], glob(base_path() . '/plugin/*/app'), glob(base_path() . '/plugin/*/config'), glob(base_path() . '/plugin/*/api')),
            // Files with these suffixes will be monitored
            'monitorExtensions' => [
                'php', 'html', 'htm', 'env'
            ],
            'options' => [
                'enable_file_monitor' => !in_array('-d', $argv) && DIRECTORY_SEPARATOR === '/',
                'enable_memory_monitor' => DIRECTORY_SEPARATOR === '/',
            ]
        ]
    ],

    // Telegram消息队列监控（每3秒检查一次待发送消息）
    'telegram-message-monitor' => [
        'handler' => app\process\TelegramMessageMonitor::class,
        'reloadable' => true,
        'count' => 1, // 仅启动1个进程
    ],

    // 订单超时自动关闭任务（每60秒扫描一次）
    'order-auto-close' => [
        'handler' => app\process\OrderAutoClose::class,
        'reloadable' => true,
        'count' => 1,
    ],

    // 订单自动补单任务（每5分钟扫描一次待支付订单，查询支付宝状态并补单）
    'order-auto-supplement' => [
        'handler' => app\process\OrderAutoSupplement::class,
        'reloadable' => true,
        'count' => 1,
    ],

    // 订单自动补发回调任务（每3分钟扫描一次已支付但回调失败的订单，自动重试回调）
    'order-auto-notify-retry' => [
        'handler' => app\process\OrderAutoNotifyRetry::class,
        'reloadable' => true,
        'count' => 1,
    ],

    // 订单自动分账任务（每10分钟扫描一次已支付但未分账的订单，自动触发分账）
    'order-auto-royalty' => [
        'handler' => app\process\OrderAutoRoyalty::class,
        'reloadable' => true,
        'count' => 1,
    ],

    // 订单未拉起自动关闭任务（每5分钟扫描一次超过1小时未拉起的订单并关闭）
    'order-unopened-close' => [
        'handler' => app\process\OrderUnopenedClose::class,
        'reloadable' => true,
        'count' => 1,
    ]
];

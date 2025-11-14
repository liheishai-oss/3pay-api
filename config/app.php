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

use support\Request;

return [
    'debug' => false,
    'error_reporting' => E_ALL,
    'default_timezone' => 'Asia/Shanghai',
    'server_public_ip' => null, // webman服务器外网IP，如果为null则自动检测
    'request_class' => Request::class,
    'public_path' => base_path() . DIRECTORY_SEPARATOR . 'public',
    'runtime_path' => base_path(false) . DIRECTORY_SEPARATOR . 'runtime',
    'controller_suffix' => 'Controller',
    'controller_reuse' => false,
    
    // 商户对接配置
    'merchant_api' => [
        'callback_ips' => env('MERCHANT_CALLBACK_IPS', '34.92.49.193,34.150.65.167'), // 回调IP地址，多个用逗号分隔
        'api_gateway' => env('MERCHANT_API_GATEWAY', 'https://api.baiyi-pay.com'), // API网关地址
        'api_docs' => env('MERCHANT_API_DOCS', 'https://www.baiyi-pay.com/docs.html'), // API文档地址
    ],
];

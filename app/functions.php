<?php

use Alipay\EasySDK\Kernel\Config;
use app\exception\MyBusinessException;
use support\Response;
function success($data = [], string $message = '成功', int $code = 200, array $headers = []): Response
{
    $response =  json([
        'code'    => $code,
        'status' =>    true,
        'message' => $message,
        'data'    => formatData($data),
    ]);
    // 设置 Header
    foreach ($headers as $key => $value) {
        $response->header($key, $value);
    }

    return $response;
}

function error(string $message = '失败', int $code = 400,  array $headers = []): Response
{
    $response =  json([
        'code'    => $code,
        'message' => $message,
        'status' =>    false,
        'data'    => (object) [],
    ]);

    // 设置 Header
    foreach ($headers as $key => $value) {
        $response->header($key, $value);
    }

    throw new MyBusinessException($message, $code);
}
function formatData($data): object|array
{
    // 如果是分页对象，转换为数组
    if ($data instanceof \Illuminate\Pagination\LengthAwarePaginator) {
        return $data->toArray();
    }
    
    // 如果是数组，确保至少是一个标准对象格式
    if (is_array($data)) {
        return empty($data) ? (object) [] : $data;
    }
    
    // 其他情况直接返回
    return $data;
}

function buildAlipayConfig(array $merchantConfig): Config
{
    $options = new Config();
    $options->protocol = 'https';
    $options->gatewayHost = 'openapi.alipay.com';
    $options->appId = $merchantConfig['appid'];
    $options->signType = 'RSA2';
    $options->notifyUrl = $merchantConfig['notifyUrl'] ?? '';
    $options->alipayCertPath = base_path($merchantConfig['alipay_cert_public_key']);
    $options->alipayRootCertPath = base_path($merchantConfig['alipay_root_cert']);
    $options->merchantCertPath = base_path($merchantConfig['app_cert_public_key']);
    $options->merchantPrivateKey = $merchantConfig['app_private_key'];
    return $options;
}

function generateSecret($length = 16)
{
    // 生成随机字节
    $bytes = random_bytes($length);

    // 将字节转换为Base32编码
    $base32 = base32_encode($bytes);

    return $base32;
}

// Base32编码函数（使用 Base32 编码标准）
function base32_encode($data)
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $padding = '=';

    $output = '';
    $bits = '';
    foreach (str_split($data) as $char) {
        $bits .= sprintf('%08b', ord($char));
    }

    while (strlen($bits) % 5 != 0) {
        $bits .= '0';
    }

    for ($i = 0; $i < strlen($bits); $i += 5) {
        $index = bindec(substr($bits, $i, 5));
        $output .= $alphabet[$index];
    }

    // 补充 "=" 填充
    while (strlen($output) % 8 != 0) {
        $output .= $padding;
    }

    return $output;
}

<?php
/**
 * 检查OAuth授权配置脚本
 * 用于检查当前OAuth授权使用的支付宝配置信息
 */

require_once __DIR__ . '/vendor/autoload.php';

// 加载环境变量
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (($value[0] ?? '') === '"' && ($value[-1] ?? '') === '"') {
                $value = substr($value, 1, -1);
            } elseif (($value[0] ?? '') === "'" && ($value[-1] ?? '') === "'") {
                $value = substr($value, 1, -1);
            }
            if (!getenv($key)) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}

use app\service\alipay\CheckOAuthConfig;

echo "========================================\n";
echo "OAuth授权配置检查\n";
echo "========================================\n\n";

$config = CheckOAuthConfig::checkConfig();

echo "配置来源: " . ($config['source'] === 'env' ? '✅ .env文件' : '⚠️  支付主体') . "\n";
echo "是否启用: " . ($config['enabled'] ? '✅ 是' : '❌ 否') . "\n\n";

if ($config['source'] === 'env') {
    echo "--- .env配置信息 ---\n";
    echo "AppID: " . ($config['app_id'] ?: '❌ 未配置') . "\n";
    echo "AppID已设置: " . ($config['app_id_set'] ? '✅' : '❌') . "\n";
    echo "私钥已设置: " . ($config['private_key_set'] ? '✅' : '❌') . "\n";
    echo "\n--- 证书配置 ---\n";
    echo "公钥证书路径: " . ($config['public_cert_path'] ?: '❌ 未配置') . "\n";
    echo "公钥证书存在: " . ($config['public_cert_exists'] ? '✅' : '❌') . "\n";
    echo "根证书路径: " . ($config['root_cert_path'] ?: '❌ 未配置') . "\n";
    echo "根证书存在: " . ($config['root_cert_exists'] ? '✅' : '❌') . "\n";
    echo "应用证书路径: " . ($config['app_cert_path'] ?: '❌ 未配置') . "\n";
    echo "应用证书存在: " . ($config['app_cert_exists'] ? '✅' : '❌') . "\n";
    echo "\n配置完整性: " . ($config['config_complete'] ? '✅ 完整' : '❌ 不完整') . "\n";
    
    if (!$config['config_complete']) {
        echo "\n⚠️  警告: OAuth授权配置不完整！\n";
        echo "请检查.env文件中的以下配置项:\n";
        if (!$config['app_id_set']) {
            echo "  ❌ OAUTH_ALIPAY_APP_ID - 未配置\n";
        }
        if (!$config['private_key_set']) {
            echo "  ❌ OAUTH_ALIPAY_APP_PRIVATE_KEY - 未配置\n";
        }
        if (!$config['public_cert_exists']) {
            echo "  ❌ OAUTH_ALIPAY_PUBLIC_CERT_PATH - 证书文件不存在: " . ($config['public_cert_path'] ?: '路径未配置') . "\n";
        }
        if (!$config['root_cert_exists']) {
            echo "  ❌ OAUTH_ALIPAY_ROOT_CERT_PATH - 证书文件不存在: " . ($config['root_cert_path'] ?: '路径未配置') . "\n";
        }
        if (!$config['app_cert_exists']) {
            echo "  ❌ OAUTH_ALIPAY_APP_PUBLIC_CERT_PATH - 证书文件不存在: " . ($config['app_cert_path'] ?: '路径未配置') . "\n";
        }
    }
} else {
    echo "--- 当前状态 ---\n";
    echo "OAuth授权将使用支付主体的支付宝配置\n";
    echo "如需使用专用授权配置，请在.env中设置:\n";
    echo "  OAUTH_ALIPAY_ENABLED=true\n";
    echo "  OAUTH_ALIPAY_APP_ID=你的授权AppID\n";
    echo "  OAUTH_ALIPAY_APP_PRIVATE_KEY=你的授权私钥\n";
}

echo "\n--- 说明 ---\n";
echo $config['message'] . "\n";

echo "\n========================================\n";
echo "检查完成\n";
echo "========================================\n";


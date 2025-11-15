#!/usr/bin/env php
<?php
chdir(__DIR__);
require_once __DIR__ . '/vendor/autoload.php';

// 加载环境变量
loadEnvFile();

// 检查必需的 PHP 扩展
checkRequiredExtensions();

// 检查 Telegram Webhook 信息
checkTelegramWebhook();

support\App::run();

/**
 * 加载 .env 文件
 */
function loadEnvFile()
{
    $envFile = __DIR__ . '/.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue; // 跳过注释行
            }
            
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // 移除引号
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
}

/**
 * 检查必需的 PHP 扩展
 */
function checkRequiredExtensions()
{
    $requiredExtensions = [
        'bcmath' => 'BCMath 扩展（支付宝 SDK 需要）',
        'curl' => 'cURL 扩展（HTTP 请求需要）',
        'json' => 'JSON 扩展（数据处理需要）',
        'mbstring' => 'mbstring 扩展（字符串处理需要）',
        'openssl' => 'OpenSSL 扩展（加密功能需要）',
        'redis' => 'Redis 扩展（缓存功能需要）',
    ];
    
    $missingExtensions = [];
    
    foreach ($requiredExtensions as $ext => $description) {
        if (!extension_loaded($ext)) {
            $missingExtensions[] = [
                'name' => $ext,
                'description' => $description
            ];
        }
    }
    
    if (!empty($missingExtensions)) {
        echo "❌ 缺少必需的 PHP 扩展:\n\n";
        foreach ($missingExtensions as $ext) {
            echo "   - {$ext['name']}: {$ext['description']}\n";
        }
        echo "\n";
        echo "📖 安装方法:\n";
        echo "   Ubuntu/Debian: sudo apt-get install php-{extension-name}\n";
        echo "   例如: sudo apt-get install php-bcmath\n\n";
        echo "   CentOS/RHEL: sudo yum install php-{extension-name}\n";
        echo "   例如: sudo yum install php-bcmath\n\n";
        echo "   安装后需要重启 PHP-FPM 或 Web 服务器\n";
        echo "   或编辑 php.ini 文件，取消注释: extension=bcmath\n\n";
        
        // 如果是关键扩展（如 bcmath），直接退出
        $criticalExtensions = ['bcmath', 'curl', 'json', 'openssl'];
        foreach ($missingExtensions as $ext) {
            if (in_array($ext['name'], $criticalExtensions)) {
                echo "⚠️  关键扩展缺失，程序无法正常运行，请先安装缺失的扩展！\n";
                exit(1);
            }
        }
    } else {
        echo "✅ 所有必需的 PHP 扩展已安装\n";
    }
}

/**
 * 检查 Telegram Webhook 信息
 */
function checkTelegramWebhook()
{
    try {
        $botToken = getenv('TELEGRAM_BOT_TOKEN') ?: config('telegram.bot_token', '');
        
        if (empty($botToken)) {
            echo "⚠️  Telegram Bot Token 未配置\n";
            echo "🔧 正在尝试自动配置...\n\n";
            
            // 尝试自动配置
            if (autoConfigureTelegram()) {
                echo "✅ Telegram 配置完成，重新检查 Webhook...\n\n";
                // 重新获取配置
                $botToken = config('telegram.bot_token', '');
            } else {
                echo "❌ 自动配置失败，请手动配置\n";
                showConfigurationGuide();
                return;
            }
        }

        echo "🔍 正在检查 Telegram Webhook 信息...\n";
        
        $apiUrl = "https://api.telegram.org/bot{$botToken}/getWebhookInfo";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Webman-Telegram-Webhook-Checker/1.0');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return;
        }

        if ($httpCode !== 200) {
            return;
        }

        $result = json_decode($response, true);
        
        if (!$result['ok']) {
            echo "❌ Telegram API 错误: " . ($result['description'] ?? 'Unknown error') . "\n";
            return;
        }

        $webhookInfo = $result['result'];

        echo "✅ Telegram Webhook 信息获取成功:\n";
        echo "   📍 URL: " . ($webhookInfo['url'] ?? '未设置') . "\n";
        echo "   🔢 待处理更新数: " . ($webhookInfo['pending_update_count'] ?? 0) . "\n";
        echo "   🌐 最后错误日期: " . ($webhookInfo['last_error_date'] ?? '无') . "\n";
        echo "   ❌ 最后错误消息: " . ($webhookInfo['last_error_message'] ?? '无') . "\n";
        echo "   🔄 最大连接数: " . ($webhookInfo['max_connections'] ?? '未设置') . "\n";
        echo "   📋 允许的更新类型: " . json_encode($webhookInfo['allowed_updates'] ?? []) . "\n";
        echo "   📋 机器人隐私模式需要关闭\n";

        // 检查是否有错误
        if (!empty($webhookInfo['last_error_message'])) {
            echo "⚠️  警告: Webhook 存在错误，请检查配置\n";
        }
        
        // 检查 URL 是否指向当前服务器
        $currentUrl = env('TELEGRAM_WEBHOOK');
//        if (isset($webhookInfo['url']) && $webhookInfo['url'] !== $currentUrl) {

            if (setTelegramWebhook($botToken, $currentUrl)) {
                echo "✅ Webhook 设置成功\n";
            } else {
                echo "❌ Webhook 设置失败\n";
            }
//        }
        
        echo "\n";

    } catch (\Exception $e) {
        echo "❌ 检查 Telegram Webhook 时发生异常: " . $e->getMessage() . "\n";
    }
}

/**
 * 自动配置 Telegram
 * @return bool
 */
function autoConfigureTelegram(): bool
{
    try {
        // 检查是否存在 .env 文件
        $envFile = __DIR__ . '/.env';
        if (!file_exists($envFile)) {
            echo "❌ .env 文件不存在，创建中...\n";
            createEnvFile();
        }

        // 检查是否已有配置
        $envContent = file_get_contents($envFile);
        if (strpos($envContent, 'TELEGRAM_BOT_TOKEN') !== false) {
            echo "ℹ️  .env 文件中已存在 Telegram 配置\n";
            return true;
        }

        // 尝试从环境变量获取
        $botToken = getenv('TELEGRAM_BOT_TOKEN');
        if (!empty($botToken)) {
            echo "✅ 从环境变量获取到 Bot Token\n";
            addTelegramConfigToEnv($botToken);
            return true;
        }

        // 尝试从用户输入获取
        echo "请输入您的 Telegram Bot Token (格式: 123456789:ABCdefGHIjklMNOpqrsTUVwxyz):\n";
        echo "> ";
        
        // 在非交互模式下，跳过用户输入
        if (!posix_isatty(STDIN)) {
            echo "❌ 非交互模式，无法获取用户输入\n";
            showConfigurationGuide();
            return false;
        }

        $input = trim(fgets(STDIN));
        
        if (empty($input)) {
            echo "❌ 未输入 Bot Token\n";
            showConfigurationGuide();
            return false;
        }

        // 验证 Token 格式
        if (!preg_match('/^\d+:[A-Za-z0-9_-]+$/', $input)) {
            echo "❌ Bot Token 格式无效\n";
            showConfigurationGuide();
            return false;
        }

        // 验证 Token 有效性
        if (validateBotToken($input)) {
            echo "✅ Bot Token 验证成功\n";
            addTelegramConfigToEnv($input);
            return true;
        } else {
            echo "❌ Bot Token 验证失败\n";
            showConfigurationGuide();
            return false;
        }

    } catch (\Exception $e) {
        echo "❌ 自动配置异常: " . $e->getMessage() . "\n";
        return false;
    }
}

/**
 * 创建 .env 文件
 */
function createEnvFile(): void
{
    $envContent = "# Telegram Bot 配置\n";
    $envContent .= "TELEGRAM_BOT_TOKEN=\n";
    $envContent .= "TELEGRAM_DEFAULT_CHAT_ID=\n";
    $envContent .= "TELEGRAM_NOTIFICATION_ENABLED=true\n";
    $envContent .= "TELEGRAM_ALLOWED_IPS=149.154.167.197,149.154.167.198,149.154.167.199,149.154.167.200,149.154.167.201,149.154.167.202,149.154.167.203,149.154.167.204,149.154.167.205,149.154.167.206,149.154.167.207,149.154.167.208,149.154.167.209,149.154.167.210,149.154.167.211,149.154.167.212,149.154.167.213,149.154.167.214,149.154.167.215,149.154.167.216,149.154.167.217,149.154.167.218,149.154.167.219,149.154.167.220,149.154.167.221,149.154.167.222,149.154.167.223,149.154.167.224,149.154.167.225,149.154.167.226,149.154.167.227,149.154.167.228,149.154.167.229,149.154.167.230,149.154.167.231,149.154.167.232,149.154.167.233,149.154.167.234,149.154.167.235,149.154.167.236,149.154.167.237,149.154.167.238,149.154.167.239,149.154.167.240,149.154.167.241,149.154.167.242,149.154.167.243,149.154.167.244,149.154.167.245,149.154.167.246,149.154.167.247,149.154.167.248,149.154.167.249,149.154.167.250,149.154.167.251,149.154.167.252,149.154.167.253,149.154.167.254,149.154.167.255,91.108.4.0/22,91.108.8.0/22,91.108.12.0/22,91.108.16.0/22,91.108.20.0/22,91.108.24.0/22,91.108.28.0/22,91.108.32.0/22,91.108.36.0/22,91.108.40.0/22,91.108.44.0/22,91.108.48.0/22,91.108.52.0/22,91.108.56.0/22,91.108.60.0/22,91.108.64.0/22,91.108.68.0/22,91.108.72.0/22,91.108.76.0/22,91.108.80.0/22,91.108.84.0/22,91.108.88.0/22,91.108.92.0/22,91.108.96.0/22,91.108.100.0/22,91.108.104.0/22,91.108.108.0/22,91.108.112.0/22,91.108.116.0/22,91.108.120.0/22,91.108.124.0/22,91.108.128.0/22,91.108.132.0/22,91.108.136.0/22,91.108.140.0/22,91.108.144.0/22,91.108.148.0/22,91.108.152.0/22,91.108.156.0/22,91.108.160.0/22,91.108.164.0/22,91.108.168.0/22,91.108.172.0/22,91.108.176.0/22,91.108.180.0/22,91.108.184.0/22,91.108.188.0/22,91.108.192.0/22,91.108.196.0/22,91.108.200.0/22,91.108.204.0/22,91.108.208.0/22,91.108.212.0/22,91.108.216.0/22,91.108.220.0/22,91.108.224.0/22,91.108.228.0/22,91.108.232.0/22,91.108.236.0/22,91.108.240.0/22,91.108.244.0/22,91.108.248.0/22,91.108.252.0/22\n";
    
    file_put_contents(__DIR__ . '/.env', $envContent);
    echo "✅ .env 文件创建成功\n";
}

/**
 * 添加 Telegram 配置到 .env 文件
 * @param string $botToken
 */
function addTelegramConfigToEnv(string $botToken): void
{
    $envFile = __DIR__ . '/.env';
    $envContent = file_get_contents($envFile);
    
    // 替换或添加配置
    if (strpos($envContent, 'TELEGRAM_BOT_TOKEN=') !== false) {
        $envContent = preg_replace('/TELEGRAM_BOT_TOKEN=.*/', "TELEGRAM_BOT_TOKEN={$botToken}", $envContent);
    } else {
        $envContent .= "\nTELEGRAM_BOT_TOKEN={$botToken}\n";
    }
    
    file_put_contents($envFile, $envContent);
    echo "✅ Telegram 配置已添加到 .env 文件\n";
}

/**
 * 验证 Bot Token
 * @param string $botToken
 * @return bool
 */
function validateBotToken(string $botToken): bool
{
    try {
        $apiUrl = "https://api.telegram.org/bot{$botToken}/getMe";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return false;
        }
        
        $result = json_decode($response, true);
        return $result['ok'] ?? false;
        
    } catch (\Exception $e) {
        return false;
    }
}

/**
 * 设置 Telegram Webhook
 * @param string $botToken
 * @param string $webhookUrl
 * @return bool
 */
function setTelegramWebhook(string $botToken, string $webhookUrl): bool
{
    try {
        $apiUrl = "https://api.telegram.org/bot{$botToken}/setWebhook";
        
        $data = [
            'url' => $webhookUrl,
            'allowed_updates' => json_encode(['message'])
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return false;
        }
        
        $result = json_decode($response, true);
        return $result['ok'] ?? false;
        
    } catch (\Exception $e) {
        return false;
    }
}

/**
 * 显示配置指南
 */
function showConfigurationGuide(): void
{
    echo "\n📖 Telegram Bot 配置指南:\n";
    echo "1. 在 Telegram 中搜索 @BotFather\n";
    echo "2. 发送 /newbot 命令\n";
    echo "3. 按提示设置机器人名称和用户名\n";
    echo "4. 获取 Token 并配置到 .env 文件中\n\n";
    echo "配置示例:\n";
    echo "TELEGRAM_BOT_TOKEN=your_actual_bot_token_here\n";
    echo "TELEGRAM_DEFAULT_CHAT_ID=your_chat_id_here\n";
    echo "TELEGRAM_NOTIFICATION_ENABLED=true\n\n";
    echo "设置 Webhook 的命令:\n";
    echo "curl -X POST \"https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook\" \\\n";
    echo "     -d \"url=http://mclient.dev.alipay.lu:8081/telegram/webhook\"\n\n";
}

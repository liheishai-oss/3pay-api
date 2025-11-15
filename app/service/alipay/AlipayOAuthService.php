<?php

namespace app\service\alipay;

use Alipay\EasySDK\Kernel\Factory;
use Exception;
use support\Log;
use support\Redis;
use Webman\Event\Event;

/**
 * 支付宝OAuth服务类
 */
class AlipayOAuthService
{
    /**
     * 获取OAuth授权配置（优先使用.env配置，如果没有则使用支付主体配置）
     * @param array|null $paymentInfo 支付配置信息（可选，如果.env未配置则使用此配置）
     * @return array OAuth授权配置
     * @throws Exception
     */
    public static function getOAuthConfig(?array $paymentInfo = null): array
    {
        // 检查是否启用授权专用配置
        $oauthEnabled = env('OAUTH_ALIPAY_ENABLED', false);

        if ($oauthEnabled) {
            Log::info('[步骤1] 开始读取.env配置', ['oauth_enabled' => $oauthEnabled]);
            
            // 使用.env中的授权专用配置
            // 优先从$_ENV读取，因为.env文件可能通过多种方式加载
            $appIdFromEnv = $_ENV['OAUTH_ALIPAY_APP_ID'] ?? null;
            $appIdFromServer = $_SERVER['OAUTH_ALIPAY_APP_ID'] ?? null;
            $appIdFromEnvFunc = env('OAUTH_ALIPAY_APP_ID', '');
            
            Log::info('[步骤2] 读取AppID', [
                'from_ENV' => $appIdFromEnv ? substr($appIdFromEnv, 0, 10) . '...' : 'null',
                'from_SERVER' => $appIdFromServer ? substr($appIdFromServer, 0, 10) . '...' : 'null',
                'from_env_func' => $appIdFromEnvFunc ? substr($appIdFromEnvFunc, 0, 10) . '...' : 'empty',
            ]);
            
            $appId = $appIdFromEnv ?? $appIdFromServer ?? $appIdFromEnvFunc;
            
            $privateKeyFromEnv = $_ENV['OAUTH_ALIPAY_APP_PRIVATE_KEY'] ?? null;
            $privateKeyFromServer = $_SERVER['OAUTH_ALIPAY_APP_PRIVATE_KEY'] ?? null;
            $privateKeyFromEnvFunc = env('OAUTH_ALIPAY_APP_PRIVATE_KEY', '');
            
            Log::info('[步骤3] 读取私钥原始数据', [
                'from_ENV_length' => $privateKeyFromEnv ? strlen($privateKeyFromEnv) : 0,
                'from_ENV_preview' => $privateKeyFromEnv ? substr($privateKeyFromEnv, 0, 50) . '...' : 'null',
                'from_SERVER_length' => $privateKeyFromServer ? strlen($privateKeyFromServer) : 0,
                'from_SERVER_preview' => $privateKeyFromServer ? substr($privateKeyFromServer, 0, 50) . '...' : 'null',
                'from_env_func_length' => strlen($privateKeyFromEnvFunc),
                'from_env_func_preview' => $privateKeyFromEnvFunc ? substr($privateKeyFromEnvFunc, 0, 50) . '...' : 'empty',
                'from_ENV_has_backslash_n' => $privateKeyFromEnv ? (strpos($privateKeyFromEnv, '\\n') !== false) : false,
                'from_ENV_has_real_newline' => $privateKeyFromEnv ? (strpos($privateKeyFromEnv, "\n") !== false) : false,
            ]);
            
            $appPrivateKey = $privateKeyFromEnv ?? $privateKeyFromServer ?? $privateKeyFromEnvFunc;
            
            // 必须同时配置AppID和私钥
            if (empty($appId) || empty($appPrivateKey)) {
                Log::error('[步骤4] OAuth授权配置不完整', [
                    'app_id_set' => !empty($appId),
                    'app_id' => $appId ?: '未设置',
                    'private_key_set' => !empty($appPrivateKey),
                    'private_key_length' => strlen($appPrivateKey),
                    '_ENV_set' => isset($_ENV['OAUTH_ALIPAY_APP_PRIVATE_KEY']),
                    '_SERVER_set' => isset($_SERVER['OAUTH_ALIPAY_APP_PRIVATE_KEY']),
                ]);
                throw new Exception("OAuth授权配置不完整：请检查.env文件中的OAUTH_ALIPAY_APP_ID和OAUTH_ALIPAY_APP_PRIVATE_KEY");
            }
            
            Log::info('[步骤5] 开始处理私钥换行符', [
                'before_length' => strlen($appPrivateKey),
                'before_has_backslash_n' => strpos($appPrivateKey, '\\n') !== false,
                'before_has_real_newline' => strpos($appPrivateKey, "\n") !== false,
                'before_preview' => substr($appPrivateKey, 0, 100),
            ]);
            
            // 处理私钥中的换行符
            // 1. 替换\n字符串为实际换行符（处理转义字符）
            $appPrivateKey = str_replace(['\\n', '\n'], "\n", $appPrivateKey);
            // 2. 清理多余的空白字符，但保留换行符
            $appPrivateKey = trim($appPrivateKey);
            
            Log::info('[步骤6] 私钥换行符处理完成', [
                'after_length' => strlen($appPrivateKey),
                'after_has_real_newline' => strpos($appPrivateKey, "\n") !== false,
                'newline_count' => substr_count($appPrivateKey, "\n"),
                'after_preview' => substr($appPrivateKey, 0, 100),
                'after_end_preview' => substr($appPrivateKey, -100),
            ]);
            
            // 验证私钥格式并测试解析
            $hasBegin = strpos($appPrivateKey, '-----BEGIN') !== false;
            $hasEnd = strpos($appPrivateKey, '-----END') !== false;
            
            Log::info('[步骤7] 检查私钥格式标记', [
                'has_begin' => $hasBegin,
                'has_end' => $hasEnd,
                'begin_pos' => $hasBegin ? strpos($appPrivateKey, '-----BEGIN') : -1,
                'end_pos' => $hasEnd ? strpos($appPrivateKey, '-----END') : -1,
            ]);
            
            // 处理私钥格式：Alipay SDK期望纯Base64内容（不包含BEGIN/END标记）
            // SDK会自动添加BEGIN/END标记并使用wordwrap()格式化
            $privateKeyForSDK = $appPrivateKey;
            
            // 如果私钥包含BEGIN/END标记，提取出纯Base64内容
            if ($hasBegin || $hasEnd) {
                Log::info('[步骤7.5] 检测到私钥包含BEGIN/END标记，提取纯Base64内容', [
                    'has_begin' => $hasBegin,
                    'has_end' => $hasEnd,
                    'original_length' => strlen($appPrivateKey),
                ]);
                
                // 移除BEGIN和END标记，提取纯Base64内容
                $privateKeyForSDK = preg_replace('/-----BEGIN[^-]+-----/', '', $appPrivateKey);
                $privateKeyForSDK = preg_replace('/-----END[^-]+-----/', '', $privateKeyForSDK);
                // 移除所有空白字符（包括换行符、空格等）
                $privateKeyForSDK = preg_replace('/\s+/', '', $privateKeyForSDK);
                
                Log::info('[步骤7.6] 已提取纯Base64私钥内容', [
                    'extracted_length' => strlen($privateKeyForSDK),
                    'extracted_preview' => substr($privateKeyForSDK, 0, 50) . '...',
                ]);
            }
            
            // 验证提取后的私钥（使用完整格式进行openssl验证）
            // 构建用于验证的完整PEM格式私钥
            $privateKeyForVerification = "-----BEGIN RSA PRIVATE KEY-----\n" . 
                wordwrap($privateKeyForSDK, 64, "\n", true) . 
                "\n-----END RSA PRIVATE KEY-----";
            
            Log::info('[步骤7.7] 构建验证用的完整PEM格式私钥', [
                'verification_key_length' => strlen($privateKeyForVerification),
                'base64_key_length' => strlen($privateKeyForSDK),
            ]);
            
            Log::info('[步骤9] 开始openssl验证私钥');
            openssl_error_string(); // 清除之前的错误
            
            // 尝试使用RSA格式
            $privateKeyResource = @openssl_pkey_get_private($privateKeyForVerification);
            
            // 如果RSA格式失败，尝试PKCS#8格式
            if ($privateKeyResource === false) {
                Log::warning('[步骤9.5] RSA格式验证失败，尝试PKCS#8格式');
                $privateKeyForVerificationPKCS8 = "-----BEGIN PRIVATE KEY-----\n" . 
                    wordwrap($privateKeyForSDK, 64, "\n", true) . 
                    "\n-----END PRIVATE KEY-----";
                $privateKeyResource = @openssl_pkey_get_private($privateKeyForVerificationPKCS8);
                if ($privateKeyResource !== false) {
                    Log::info('[步骤9.6] PKCS#8格式验证成功');
                }
            }
            
            if ($privateKeyResource === false) {
                $opensslErrors = [];
                while (($error = openssl_error_string()) !== false) {
                    $opensslErrors[] = $error;
                }
                $opensslErrorStr = implode('; ', $opensslErrors);
                
                Log::error('[步骤10] OAuth私钥openssl验证失败', [
                    'app_id' => $appId,
                    'openssl_errors' => $opensslErrors,
                    'openssl_error_str' => $opensslErrorStr,
                    'base64_key_length' => strlen($privateKeyForSDK),
                    'base64_key_preview' => substr($privateKeyForSDK, 0, 50) . '...' . substr($privateKeyForSDK, -20),
                    'verification_key_preview' => substr($privateKeyForVerification, 0, 100),
                ]);
                
                // 不抛出异常，继续使用私钥（Alipay SDK可能会自己处理）
                Log::warning('[步骤10.5] 私钥openssl验证失败，但继续使用（Alipay SDK可能会自己处理格式）');
            } else {
                Log::info('[步骤11] openssl验证私钥成功');
                // PHP 8.0+ 中 openssl_free_key() 已被弃用，资源会自动释放
            }
            
            // 使用提取的纯Base64内容作为最终私钥（SDK会自动格式化）
            // 这是Alipay SDK期望的格式
            $appPrivateKey = $privateKeyForSDK;
            
            Log::info('[步骤11.5] 最终私钥格式（用于SDK）', [
                'final_key_length' => strlen($appPrivateKey),
                'final_key_preview' => substr($appPrivateKey, 0, 50) . '...',
                'is_pure_base64' => !preg_match('/-----BEGIN|-----END/', $appPrivateKey),
            ]);

            // 从.env中读取证书路径（只使用.env配置，不使用支付主体的证书）
            $alipayPublicCertPath = env('OAUTH_ALIPAY_PUBLIC_CERT_PATH', '');
            $alipayRootCertPath = env('OAUTH_ALIPAY_ROOT_CERT_PATH', '');
            $appPublicCertPath = env('OAUTH_ALIPAY_APP_PUBLIC_CERT_PATH', '');
            
            Log::info('[步骤12] 读取.env证书路径', [
                'alipay_public_cert_path' => $alipayPublicCertPath ?: '未配置',
                'alipay_root_cert_path' => $alipayRootCertPath ?: '未配置',
                'app_public_cert_path' => $appPublicCertPath ?: '未配置',
            ]);
            
            // 检查证书文件是否存在
            $alipayPublicCertExists = !empty($alipayPublicCertPath) && file_exists(base_path($alipayPublicCertPath));
            $alipayRootCertExists = !empty($alipayRootCertPath) && file_exists(base_path($alipayRootCertPath));
            $appPublicCertExists = !empty($appPublicCertPath) && file_exists(base_path($appPublicCertPath));
            
            Log::info('[步骤13] 检查证书文件是否存在', [
                'alipay_public_cert_exists' => $alipayPublicCertExists,
                'alipay_public_cert_full_path' => $alipayPublicCertPath ? base_path($alipayPublicCertPath) : 'N/A',
                'alipay_root_cert_exists' => $alipayRootCertExists,
                'alipay_root_cert_full_path' => $alipayRootCertPath ? base_path($alipayRootCertPath) : 'N/A',
                'app_public_cert_exists' => $appPublicCertExists,
                'app_public_cert_full_path' => $appPublicCertPath ? base_path($appPublicCertPath) : 'N/A',
            ]);
            
            // 只使用.env中的证书配置
            $config = [
                'appid' => $appId,
                'AppPrivateKey' => $appPrivateKey,
                'alipayCertPublicKey' => $alipayPublicCertPath,
                'alipayRootCert' => $alipayRootCertPath,
                'appCertPublicKey' => $appPublicCertPath,
                'notify_url' => config('app.url') . '/api/v1/payment/notify/alipay',
                'sandbox' => false,
            ];
            
            // 验证证书配置完整性
            if (empty($config['alipayCertPublicKey']) || empty($config['alipayRootCert']) || empty($config['appCertPublicKey'])) {
                $missingCerts = [];
                if (empty($config['alipayCertPublicKey'])) $missingCerts[] = 'OAUTH_ALIPAY_PUBLIC_CERT_PATH';
                if (empty($config['alipayRootCert'])) $missingCerts[] = 'OAUTH_ALIPAY_ROOT_CERT_PATH';
                if (empty($config['appCertPublicKey'])) $missingCerts[] = 'OAUTH_ALIPAY_APP_PUBLIC_CERT_PATH';
                
                Log::error('[步骤13.5] OAuth证书配置不完整', [
                    'missing_certs' => $missingCerts,
                    'config' => $config
                ]);
                
                throw new Exception("OAuth授权证书配置不完整：请在.env中配置以下证书路径：" . implode(', ', $missingCerts));
            }
            
            // 验证证书文件是否存在
            if (!$alipayPublicCertExists || !$alipayRootCertExists || !$appPublicCertExists) {
                $missingFiles = [];
                if (!$alipayPublicCertExists) $missingFiles[] = '支付宝公钥证书: ' . base_path($alipayPublicCertPath);
                if (!$alipayRootCertExists) $missingFiles[] = '支付宝根证书: ' . base_path($alipayRootCertPath);
                if (!$appPublicCertExists) $missingFiles[] = '应用公钥证书: ' . base_path($appPublicCertPath);
                
                Log::error('[步骤13.6] OAuth证书文件不存在', [
                    'missing_files' => $missingFiles,
                ]);
                
                throw new Exception("OAuth授权证书文件不存在：\n" . implode("\n", $missingFiles));
            }
            
            Log::info('[步骤14] 配置构建完成', [
                'app_id' => $appId,
                'has_private_key' => !empty($appPrivateKey),
                'private_key_length' => strlen($appPrivateKey),
                'private_key_newline_count' => substr_count($appPrivateKey, "\n"),
                'cert_paths' => [
                    'alipay_public' => $config['alipayCertPublicKey'],
                    'alipay_root' => $config['alipayRootCert'],
                    'app_public' => $config['appCertPublicKey']
                ],
                'cert_files_exist' => [
                    'alipay_public' => $alipayPublicCertExists,
                    'alipay_root' => $alipayRootCertExists,
                    'app_public' => $appPublicCertExists
                ]
            ]);
            
            Log::info('[步骤15] 返回OAuth配置，私钥预览', [
                'private_key_first_100' => substr($appPrivateKey, 0, 100),
                'private_key_last_100' => substr($appPrivateKey, -100),
            ]);
            
            return $config;
        } else {
            // 使用支付主体配置
            if (empty($paymentInfo)) {
                throw new Exception("OAuth授权配置缺失：未启用.env配置且未提供支付配置");
            }

            Log::info("使用支付主体的支付宝配置进行OAuth授权", [
                'app_id' => $paymentInfo['appid'] ?? ''
            ]);

            return $paymentInfo;
        }
    }
    
    /**
     * 获取OAuth授权URL
     * @param array $params 授权参数
     * @param array|null $paymentInfo 支付配置信息（可选，如果.env未配置则使用此配置）
     * @return string 授权URL
     * @throws Exception
     */
    public static function getAuthUrl(array $params, ?array $paymentInfo = null): string
    {
        try {
            // 获取OAuth授权配置（优先使用.env）
            $oauthConfig = self::getOAuthConfig($paymentInfo);
            $config = AlipayConfig::getConfig($oauthConfig);
            
            $result = Factory::setOptions($config)
                ->base()
                ->oauth()
                ->getAuthUrl(
                    $params['redirect_uri'],
                    $params['scope'] ?? 'auth_user',
                    $params['state'] ?? ''
                );
            
            Log::info("支付宝OAuth授权URL生成成功", [
                'redirect_uri' => $params['redirect_uri'],
                'scope' => $params['scope'] ?? 'auth_user',
                'app_id' => $oauthConfig['appid'] ?? ''
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            Log::error("支付宝OAuth授权URL生成失败", [
                'params' => $params,
                'error' => $e->getMessage()
            ]);
            throw new Exception("OAuth授权URL生成失败: " . $e->getMessage());
        }
    }
    
    /**
     * 通过授权码获取访问令牌
     * @param string $authCode 授权码
     * @param array|null $paymentInfo 支付配置信息（可选，如果.env未配置则使用此配置）
     * @return array 用户信息
     * @throws Exception
     */
    public static function getTokenByAuthCode(string $authCode, ?array $paymentInfo = null): array
    {
        try {
            // 获取OAuth授权配置（优先使用.env）
            $oauthConfig = self::getOAuthConfig($paymentInfo);
            
            Log::info("[OAuth-步骤1] 开始获取OAuth令牌", [
                'auth_code_length' => strlen($authCode),
                'auth_code_preview' => substr($authCode, 0, 20) . '...',
                'app_id' => $oauthConfig['appid'] ?? '',
                'use_env_config' => env('OAUTH_ALIPAY_ENABLED', false),
                'oauth_config_keys' => array_keys($oauthConfig),
                'has_AppPrivateKey' => isset($oauthConfig['AppPrivateKey']),
                'AppPrivateKey_length' => isset($oauthConfig['AppPrivateKey']) ? strlen($oauthConfig['AppPrivateKey']) : 0,
            ]);
            
            Log::info("[OAuth-步骤2] 调用AlipayConfig::getConfig", [
                'config_appid' => $oauthConfig['appid'] ?? '',
                'config_has_private_key' => isset($oauthConfig['AppPrivateKey']),
                'config_private_key_preview' => isset($oauthConfig['AppPrivateKey']) ? substr($oauthConfig['AppPrivateKey'], 0, 50) . '...' : 'N/A',
            ]);
            
            $config = AlipayConfig::getConfig($oauthConfig);
            
            Log::info("[OAuth-步骤3] AlipayConfig::getConfig完成", [
                'config_appid' => $config->appId ?? '',
                'config_gateway' => $config->gatewayHost ?? '',
                'config_alipay_cert_path' => $config->alipayCertPath ?? '',
                'config_alipay_root_cert_path' => $config->alipayRootCertPath ?? '',
                'config_merchant_cert_path' => $config->merchantCertPath ?? '',
                'config_has_private_key' => !empty($config->merchantPrivateKey),
                'config_private_key_length' => strlen($config->merchantPrivateKey ?? ''),
            ]);
            
            Log::info("[OAuth-步骤4] 准备调用Factory.getToken", [
                'app_id' => $config->appId ?? 'not_set',
                'gateway_host' => $config->gatewayHost ?? 'not_set',
                'private_key_length' => strlen($config->merchantPrivateKey ?? ''),
                'private_key_has_newlines' => strpos($config->merchantPrivateKey ?? '', "\n") !== false,
                'private_key_first_50' => substr($config->merchantPrivateKey ?? '', 0, 50),
                'private_key_last_50' => substr($config->merchantPrivateKey ?? '', -50),
                'auth_code' => substr($authCode, 0, 20) . '...',
            ]);
            
            try {
                Log::info("[OAuth-步骤5] 开始调用Factory.setOptions");
                $factory = Factory::setOptions($config);
                Log::info("[OAuth-步骤6] Factory.setOptions完成，调用base()");
                $base = $factory->base();
                Log::info("[OAuth-步骤7] base()完成，调用oauth()");
                $oauth = $base->oauth();
                Log::info("[OAuth-步骤8] oauth()完成，调用getToken()");
                $result = $oauth->getToken($authCode);
                Log::info("[OAuth-步骤9] getToken()调用完成", [
                    'result_type' => get_class($result),
                    'result_properties' => array_keys(get_object_vars($result))
                ]);
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                $errorCode = $e->getCode();
                $errorFile = $e->getFile();
                $errorLine = $e->getLine();
                
                echo "\n";
                echo "========================================\n";
                echo "❌ 支付宝SDK调用失败\n";
                echo "========================================\n";
                echo "错误消息: {$errorMessage}\n";
                echo "错误代码: {$errorCode}\n";
                echo "错误文件: {$errorFile}\n";
                echo "错误行号: {$errorLine}\n";
                echo "========================================\n";
                echo "\n";
                
                Log::error("[OAuth-步骤X] getToken调用异常", [
                    'error_message' => $errorMessage,
                    'error_code' => $errorCode,
                    'error_file' => $errorFile,
                    'error_line' => $errorLine,
                    'trace' => $e->getTraceAsString(),
                ]);
                throw $e;
            }
            
            // 统一使用body属性，如果不存在则尝试httpBody
            if (property_exists($result, 'body')) {
                $bodyContent = $result->body;
                Log::info("使用body属性");
            } elseif (property_exists($result, 'httpBody')) {
                $bodyContent = $result->httpBody;
                Log::info("使用httpBody属性（body不存在）");
            } else {
                Log::error("无法获取响应内容", [
                    'available_properties' => array_keys(get_object_vars($result))
                ]);
                throw new Exception("无法获取OAuth响应内容");
            }
            
            Log::info("原始响应内容", [
                'body_length' => strlen($bodyContent),
                'body_preview' => substr($bodyContent, 0, 200)
            ]);
            
            $response = json_decode($bodyContent, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $jsonError = json_last_error_msg();
                echo "\n";
                echo "========================================\n";
                echo "❌ JSON解析失败\n";
                echo "========================================\n";
                echo "JSON错误: {$jsonError}\n";
                echo "响应内容长度: " . strlen($bodyContent) . "\n";
                echo "响应内容预览: " . substr($bodyContent, 0, 500) . "\n";
                echo "========================================\n";
                echo "\n";
                
                Log::error("JSON解析失败", [
                    'json_error' => $jsonError,
                    'body' => $bodyContent
                ]);
                throw new Exception("JSON解析失败: " . $jsonError);
            }
            
            Log::info("响应JSON解析成功", [
                'response_keys' => array_keys($response ?? [])
            ]);
            
            if (!isset($response['alipay_system_oauth_token_response'])) {
                echo "\n";
                echo "========================================\n";
                echo "❌ OAuth响应格式错误\n";
                echo "========================================\n";
                echo "错误: 响应中缺少alipay_system_oauth_token_response字段\n";
                echo "响应数据结构: " . json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
                echo "========================================\n";
                echo "\n";
                
                Log::error("OAuth令牌响应格式错误", [
                    'response' => $response
                ]);
                throw new Exception("OAuth令牌响应格式错误，缺少alipay_system_oauth_token_response字段");
            }
            
            $tokenResponse = $response['alipay_system_oauth_token_response'];
            
            Log::info("tokenResponse内容", [
                'token_response_keys' => array_keys($tokenResponse),
                'has_user_id' => isset($tokenResponse['user_id']),
                'has_access_token' => isset($tokenResponse['access_token'])
            ]);
            
            // 直接获取user_id和access_token（成功响应没有code字段）
            $userId = $tokenResponse['user_id'] ?? '';
            $accessToken = $tokenResponse['access_token'] ?? '';
            
            if (empty($userId)) {
                echo "\n";
                echo "========================================\n";
                echo "❌ 无法获取用户ID\n";
                echo "========================================\n";
                echo "错误: OAuth响应中未包含user_id字段\n";
                echo "响应数据: " . json_encode($tokenResponse, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
                echo "========================================\n";
                echo "\n";
                
                Log::error("无法从OAuth响应中获取用户ID", [
                    'token_response' => $tokenResponse
                ]);
                throw new Exception("无法从OAuth响应中获取用户ID");
            }
            
            // 缓存访问令牌
            if (!empty($accessToken)) {
                $cacheKey = "alipay_oauth_token:" . $userId;
                Redis::setex($cacheKey, 3600, $accessToken); // 1小时过期
            }
            
            Log::info("支付宝OAuth令牌获取成功", [
                'user_id' => $userId,
                'has_access_token' => !empty($accessToken),
                'access_token_length' => strlen($accessToken)
            ]);
            
            return [
                'user_id' => $userId,
                'access_token' => $accessToken,
                'expires_in' => $tokenResponse['expires_in'] ?? 0,
                'refresh_token' => $tokenResponse['refresh_token'] ?? '',
                're_expires_in' => $tokenResponse['re_expires_in'] ?? 0,
            ];
            
        } catch (Exception $e) {
            Log::error("支付宝OAuth令牌获取失败", [
                'auth_code' => substr($authCode, 0, 20) . '...',
                'full_auth_code' => $authCode,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
    
    /**
     * 刷新访问令牌
     * @param string $refreshToken 刷新令牌
     * @param array|null $paymentInfo 支付配置信息（可选，如果.env未配置则使用此配置）
     * @return array 新的令牌信息
     * @throws Exception
     */
    public static function refreshToken(string $refreshToken, ?array $paymentInfo = null): array
    {
        try {
            // 获取OAuth授权配置（优先使用.env）
            $oauthConfig = self::getOAuthConfig($paymentInfo);
            $config = AlipayConfig::getConfig($oauthConfig);
            
            $result = Factory::setOptions($config)
                ->base()
                ->oauth()
                ->refreshToken($refreshToken);
            
            $response = json_decode($result->body, true);
            
            if (!isset($response['alipay_system_oauth_token_response'])) {
                throw new Exception("刷新令牌响应格式错误");
            }
            
            $tokenResponse = $response['alipay_system_oauth_token_response'];
            
            if ($tokenResponse['code'] !== '10000') {
                throw new Exception("刷新令牌失败: " . ($tokenResponse['msg'] ?? '未知错误'));
            }
            
            $userId = $tokenResponse['user_id'] ?? '';
            $accessToken = $tokenResponse['access_token'] ?? '';
            
            // 更新缓存
            if (!empty($accessToken) && !empty($userId)) {
                $cacheKey = "alipay_oauth_token:" . $userId;
                Redis::setex($cacheKey, 3600, $accessToken);
            }
            
            Log::info("支付宝OAuth令牌刷新成功", [
                'user_id' => $userId,
                'has_access_token' => !empty($accessToken)
            ]);
            
            return [
                'user_id' => $userId,
                'access_token' => $accessToken,
                'expires_in' => $tokenResponse['expires_in'] ?? 0,
                'refresh_token' => $tokenResponse['refresh_token'] ?? '',
                're_expires_in' => $tokenResponse['re_expires_in'] ?? 0,
            ];
            
        } catch (Exception $e) {
            Log::error("支付宝OAuth令牌刷新失败", [
                'refresh_token' => $refreshToken,
                'error' => $e->getMessage()
            ]);
            throw new Exception("OAuth令牌刷新失败: " . $e->getMessage());
        }
    }
    
    /**
     * 获取用户信息
     * @param string $accessToken 访问令牌
     * @param array|null $paymentInfo 支付配置信息（可选，如果.env未配置则使用此配置）
     * @return array 用户信息
     * @throws Exception
     */
    public static function getUserInfo(string $accessToken, ?array $paymentInfo = null): array
    {
        try {
            // 获取OAuth授权配置（优先使用.env）
            $oauthConfig = self::getOAuthConfig($paymentInfo);
            $config = AlipayConfig::getConfig($oauthConfig);
            
            $result = Factory::setOptions($config)
                ->base()
                ->oauth()
                ->getUserInfo($accessToken);
            
            $response = json_decode($result->body, true);
            
            if (!isset($response['alipay_user_info_share_response'])) {
                throw new Exception("用户信息响应格式错误");
            }
            
            $userResponse = $response['alipay_user_info_share_response'];
            
            if ($userResponse['code'] !== '10000') {
                throw new Exception("获取用户信息失败: " . ($userResponse['msg'] ?? '未知错误'));
            }
            
            Log::info("支付宝用户信息获取成功", [
                'user_id' => $userResponse['user_id'] ?? '',
                'nick_name' => $userResponse['nick_name'] ?? ''
            ]);
            
            return [
                'user_id' => $userResponse['user_id'] ?? '',
                'nick_name' => $userResponse['nick_name'] ?? '',
                'avatar' => $userResponse['avatar'] ?? '',
                'province' => $userResponse['province'] ?? '',
                'city' => $userResponse['city'] ?? '',
                'gender' => $userResponse['gender'] ?? '',
                'is_certified' => $userResponse['is_certified'] ?? '',
                'is_student_certified' => $userResponse['is_student_certified'] ?? '',
                'user_type' => $userResponse['user_type'] ?? '',
                'user_status' => $userResponse['user_status'] ?? '',
            ];
            
        } catch (Exception $e) {
            Log::error("支付宝用户信息获取失败", [
                'access_token' => $accessToken,
                'error' => $e->getMessage()
            ]);
            throw new Exception("用户信息获取失败: " . $e->getMessage());
        }
    }
    
    /**
     * 检查购买限制
     * @param string $userId 用户ID
     * @param int $maxNumber 最大购买次数
     * @param string $entityId 实体ID
     * @param string $orderNumber 订单号
     * @return bool 是否允许购买
     * @throws Exception
     */
    public static function checkBuyLimit(string $userId, int $maxNumber = 5, string $entityId = '', string $orderNumber = ''): bool
    {
        try {
            $key = "buy_user:{$userId}";
            $buyCount = Redis::get($key) ?: 0;
            
            if ($buyCount >= $maxNumber) {
                Log::warning("用户购买次数超出限制", [
                    'user_id' => $userId,
                    'buy_count' => $buyCount,
                    'max_number' => $maxNumber
                ]);
                return false;
            }
            
            // 触发购买限制检查事件
            Event::dispatch('order.limit.check', [
                'user_id' => $userId,
                'entity_id' => $entityId,
                'max_buy_number' => $maxNumber,
                'payment_order_number' => $orderNumber
            ]);
            
            return true;
            
        } catch (Exception $e) {
            Log::error("检查购买限制失败", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            throw new Exception("检查购买限制失败: " . $e->getMessage());
        }
    }
}

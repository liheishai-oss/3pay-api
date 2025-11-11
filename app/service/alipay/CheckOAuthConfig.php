<?php

namespace app\service\alipay;

use support\Log;

/**
 * 检查OAuth授权配置
 */
class CheckOAuthConfig
{
    /**
     * 检查当前OAuth授权使用的支付宝配置
     * @return array 配置信息
     */
    public static function checkConfig(): array
    {
        $oauthEnabled = env('OAUTH_ALIPAY_ENABLED', false);
        
        if ($oauthEnabled) {
            // 使用.env配置
            $appId = env('OAUTH_ALIPAY_APP_ID', '');
            $hasPrivateKey = !empty(env('OAUTH_ALIPAY_APP_PRIVATE_KEY', ''));
            $publicCertPath = env('OAUTH_ALIPAY_PUBLIC_CERT_PATH', '');
            $rootCertPath = env('OAUTH_ALIPAY_ROOT_CERT_PATH', '');
            $appCertPath = env('OAUTH_ALIPAY_APP_PUBLIC_CERT_PATH', '');
            
            // 检查证书文件是否存在
            $publicCertExists = !empty($publicCertPath) && file_exists(base_path($publicCertPath));
            $rootCertExists = !empty($rootCertPath) && file_exists(base_path($rootCertPath));
            $appCertExists = !empty($appCertPath) && file_exists(base_path($appCertPath));
            
            return [
                'source' => 'env',
                'enabled' => true,
                'app_id' => $appId,
                'app_id_set' => !empty($appId),
                'private_key_set' => $hasPrivateKey,
                'public_cert_set' => !empty($publicCertPath),
                'public_cert_exists' => $publicCertExists,
                'public_cert_path' => $publicCertPath,
                'root_cert_set' => !empty($rootCertPath),
                'root_cert_exists' => $rootCertExists,
                'root_cert_path' => $rootCertPath,
                'app_cert_set' => !empty($appCertPath),
                'app_cert_exists' => $appCertExists,
                'app_cert_path' => $appCertPath,
                'config_complete' => !empty($appId) && $hasPrivateKey && $publicCertExists && $rootCertExists && $appCertExists,
                'message' => !empty($appId) && $hasPrivateKey && $publicCertExists && $rootCertExists && $appCertExists
                    ? '使用.env中的授权专用支付宝配置（配置完整）' 
                    : 'OAuth授权配置不完整，请检查.env文件'
            ];
        } else {
            // 使用支付主体配置
            return [
                'source' => 'subject',
                'enabled' => false,
                'app_id' => null,
                'message' => '未启用.env授权配置，OAuth授权将使用支付主体的支付宝配置'
            ];
        }
    }
    
    /**
     * 获取当前使用的AppID（用于OAuth授权）
     * @param string|null $subjectAppId 支付主体的AppID（作为fallback）
     * @return string
     */
    public static function getCurrentAppId(?string $subjectAppId = null): string
    {
        $oauthEnabled = env('OAUTH_ALIPAY_ENABLED', false);
        
        if ($oauthEnabled) {
            $appId = env('OAUTH_ALIPAY_APP_ID', '');
            if (!empty($appId)) {
                return $appId;
            }
        }
        
        // 如果没有配置或未启用，使用支付主体的AppID
        return $subjectAppId ?? '';
    }
    
    /**
     * 记录OAuth授权配置信息到日志
     * @param string $context 上下文（如订单号）
     */
    public static function logConfig(string $context = ''): void
    {
        $config = self::checkConfig();
        
        Log::info('OAuth授权配置检查', [
            'context' => $context,
            'source' => $config['source'],
            'enabled' => $config['enabled'],
            'app_id' => $config['app_id'] ?? 'N/A',
            'config_complete' => $config['config_complete'] ?? false,
            'message' => $config['message']
        ]);
    }
}


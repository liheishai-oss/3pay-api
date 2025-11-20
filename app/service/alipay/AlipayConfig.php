<?php

namespace app\service\alipay;

/**
 * 支付宝配置管理类
 */
class AlipayConfig
{
    /**
     * 获取支付宝配置
     * @param array $paymentInfo 支付信息
     * @return \Alipay\EasySDK\Kernel\Config
     * @throws \Exception
     */
    public static function getConfig(array $paymentInfo): \Alipay\EasySDK\Kernel\Config
    {
        $config = new \Alipay\EasySDK\Kernel\Config();
        
        // 基础配置
        $config->protocol = 'https';
        
        // 根据沙箱环境设置网关地址
        if (isset($paymentInfo['sandbox']) && $paymentInfo['sandbox']) {
            $config->gatewayHost = 'openapi.alipaydev.com'; // 沙箱环境
        } else {
            $config->gatewayHost = 'openapi.alipay.com'; // 正式环境
        }
        
        $config->appId = $paymentInfo['appid'] ?? '';
        $config->signType = 'RSA2';
        
        // 通知地址
        $config->notifyUrl = $paymentInfo['notify_url'] ?? '';

        // HTTP超时配置（单位：毫秒）
//        $config->http = [
//            'timeout' => ($paymentInfo['http_timeout_ms'] ?? 10000),
//            'connectTimeout' => ($paymentInfo['http_connect_timeout_ms'] ?? 5000),
//        ];
//
        // 证书配置
        // 处理证书路径：如果路径是相对路径，使用base_path；如果已经是绝对路径，直接使用
        $alipayCertPath = $paymentInfo['alipayCertPublicKey'] ?? '';
        $alipayRootCertPath = $paymentInfo['alipayRootCert'] ?? '';
        $appCertPath = $paymentInfo['appCertPublicKey'] ?? '';
        
        // 处理证书路径：统一转换为绝对路径
        if (!empty($alipayCertPath)) {
            // 如果已经是绝对路径（以/开头），直接使用；否则使用base_path处理
            $config->alipayCertPath = (strpos($alipayCertPath, '/') === 0) 
                ? $alipayCertPath 
                : base_path($alipayCertPath);
        } else {
            $config->alipayCertPath = '';
        }
        
        if (!empty($alipayRootCertPath)) {
            $config->alipayRootCertPath = (strpos($alipayRootCertPath, '/') === 0) 
                ? $alipayRootCertPath 
                : base_path($alipayRootCertPath);
        } else {
            $config->alipayRootCertPath = '';
        }
        
        if (!empty($appCertPath)) {
            $config->merchantCertPath = (strpos($appCertPath, '/') === 0) 
                ? $appCertPath 
                : base_path($appCertPath);
        } else {
            $config->merchantCertPath = '';
        }
        
        $config->merchantPrivateKey = $paymentInfo['AppPrivateKey'] ?? '';
        
        // 记录证书路径信息（用于调试）
        \support\Log::debug('AlipayConfig证书路径处理', [
            'input_alipay_cert' => $alipayCertPath,
            'output_alipay_cert' => $config->alipayCertPath,
            'input_root_cert' => $alipayRootCertPath,
            'output_root_cert' => $config->alipayRootCertPath,
            'input_app_cert' => $appCertPath,
            'output_app_cert' => $config->merchantCertPath,
            'base_path' => base_path(),
            'file_exists_alipay' => !empty($config->alipayCertPath) ? file_exists($config->alipayCertPath) : false,
            'file_exists_root' => !empty($config->alipayRootCertPath) ? file_exists($config->alipayRootCertPath) : false,
            'file_exists_app' => !empty($config->merchantCertPath) ? file_exists($config->merchantCertPath) : false,
        ]);
        
        // 验证必要配置
        self::validateConfig($config);
        
        return $config;
    }
    
    /**
     * 验证配置完整性
     * @param \Alipay\EasySDK\Kernel\Config $config
     * @throws \Exception
     */
    private static function validateConfig(\Alipay\EasySDK\Kernel\Config $config): void
    {
        $requiredFields = [
            'appId' => '应用ID',
            'merchantPrivateKey' => '商户私钥',
            'alipayCertPath' => '支付宝公钥证书',
            'alipayRootCertPath' => '支付宝根证书',
            'merchantCertPath' => '应用公钥证书',
            'notifyUrl' => '异步通知地址'
        ];
        
        foreach ($requiredFields as $field => $name) {
            if (empty($config->$field)) {
                throw new \Exception("支付宝配置缺少必要参数: {$name}");
            }
        }
        
        // 验证证书文件是否存在
        $certFiles = [
            'alipayCertPath' => ['path' => $config->alipayCertPath, 'name' => '支付宝公钥证书'],
            'alipayRootCertPath' => ['path' => $config->alipayRootCertPath, 'name' => '支付宝根证书'],
            'merchantCertPath' => ['path' => $config->merchantCertPath, 'name' => '应用公钥证书']
        ];
        
        foreach ($certFiles as $field => $certInfo) {
            $path = $certInfo['path'];
            $name = $certInfo['name'];
            
            if (empty($path)) {
                continue; // 跳过空路径
            }
            
            // 路径已经在getConfig中通过base_path处理过，是完整路径，直接使用
            // 不再重复调用base_path，避免路径被重复拼接
            $fullPath = $path;
            
            // 检查文件是否存在
            if (!file_exists($fullPath)) {
                // 如果文件不存在，尝试使用realpath规范化路径
                $realPath = realpath($fullPath);
                if ($realPath && file_exists($realPath)) {
                    // 如果realpath可以解析，使用realpath
                    continue;
                }
                
                // 如果还是不存在，提供详细的错误信息
                \support\Log::error('支付宝证书文件不存在', [
                    'cert_name' => $name,
                    'cert_field' => $field,
                    'cert_path' => $fullPath,
                    'base_path' => base_path(),
                    'realpath_result' => $realPath,
                    'directory_exists' => is_dir(dirname($fullPath)),
                    'directory_listing' => is_dir(dirname($fullPath)) ? implode(', ', array_slice(scandir(dirname($fullPath)), 0, 10)) : 'N/A'
                ]);
                
                throw new \Exception("支付宝证书文件不存在: {$name} ({$field})，路径: {$fullPath}。请检查证书文件是否存在，或确保数据库中存储了证书内容。");
            }
        }
    }
    
    /**
     * 获取沙箱环境配置
     * @param array $paymentInfo 支付信息
     * @return \Alipay\EasySDK\Kernel\Config
     * @throws \Exception
     */
    public static function getSandboxConfig(array $paymentInfo): \Alipay\EasySDK\Kernel\Config
    {
        $config = self::getConfig($paymentInfo);
        $config->gatewayHost = 'openapi.alipaydev.com';
        return $config;
    }
}

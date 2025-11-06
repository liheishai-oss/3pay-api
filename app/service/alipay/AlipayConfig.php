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
        
        // 证书配置
        $config->alipayCertPath = base_path($paymentInfo['alipayCertPublicKey'] ?? '');
        $config->alipayRootCertPath = base_path($paymentInfo['alipayRootCert'] ?? '');
        $config->merchantCertPath = base_path($paymentInfo['appCertPublicKey'] ?? '');
        $config->merchantPrivateKey = $paymentInfo['AppPrivateKey'] ?? '';
        
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
            'alipayCertPath' => $config->alipayCertPath,
            'alipayRootCertPath' => $config->alipayRootCertPath,
            'merchantCertPath' => $config->merchantCertPath
        ];
        
        foreach ($certFiles as $field => $path) {
            if (!file_exists($path)) {
                throw new \Exception("支付宝证书文件不存在: {$field} => {$path}");
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

<?php

namespace app\service\alipay;

use app\service\alipay\AlipayService;
use app\service\alipay\AlipayException;
use app\service\alipay\AlipayConstants;
use Exception;

/**
 * 支付宝服务使用示例
 */
class AlipayServiceExample
{
    private $alipayService;
    
    public function __construct()
    {
        $this->alipayService = new AlipayService();
    }
    
    /**
     * WAP支付示例
     */
    public function wapPayExample()
    {
        try {
            // 支付配置信息
            $paymentInfo = [
                'appid' => 'your_app_id',
                'AppPrivateKey' => 'your_private_key',
                'alipayCertPublicKey' => 'path/to/alipay_cert_public_key.crt',
                'alipayRootCert' => 'path/to/alipay_root_cert.crt',
                'appCertPublicKey' => 'path/to/app_cert_public_key.crt',
                'notify_url' => 'https://your-domain.com/alipay/notify',
                'sandbox' => false, // 是否沙箱环境
            ];
            
            // 订单信息
            $orderInfo = [
                'payment_order_number' => 'ORDER_' . time(),
                'product_title' => '测试商品',
                'payment_amount' => '1.00', // 金额（元）
                'order_expiry_time' => date('Y-m-d H:i:s', time() + 1800), // 30分钟后过期
                'pid' => 'your_pid',
                'remark' => '测试订单备注',
            ];
            
            // 创建WAP支付
            $paymentUrl = $this->alipayService->wapPay($orderInfo, $paymentInfo);
            
            echo "WAP支付URL: " . $paymentUrl . "\n";
            
        } catch (AlipayException $e) {
            echo "支付宝异常: " . $e->getMessage() . "\n";
            echo "错误代码: " . $e->getErrorCode() . "\n";
            echo "错误详情: " . json_encode($e->getErrorDetails()) . "\n";
        } catch (Exception $e) {
            echo "系统异常: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * APP支付示例
     */
    public function appPayExample()
    {
        try {
            $paymentInfo = $this->getPaymentConfig();
            
            $orderInfo = [
                'payment_order_number' => 'APP_ORDER_' . time(),
                'product_title' => 'APP测试商品',
                'payment_amount' => '1.00',
                'order_expiry_time' => date('Y-m-d H:i:s', time() + 1800),
                'pid' => 'your_pid',
            ];
            
            $paymentParams = $this->alipayService->appPay($orderInfo, $paymentInfo);
            
            echo "APP支付参数: " . $paymentParams . "\n";
            
        } catch (Exception $e) {
            echo "APP支付失败: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * 扫码支付示例
     */
    public function qrPayExample()
    {
        try {
            $paymentInfo = $this->getPaymentConfig();
            
            $orderInfo = [
                'payment_order_number' => 'QR_ORDER_' . time(),
                'product_title' => '扫码测试商品',
                'payment_amount' => '1.00',
                'order_expiry_time' => date('Y-m-d H:i:s', time() + 1800),
                'pid' => 'your_pid',
            ];
            
            $qrCode = $this->alipayService->qrPay($orderInfo, $paymentInfo);
            
            echo "二维码内容: " . $qrCode . "\n";
            
        } catch (Exception $e) {
            echo "扫码支付失败: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * 订单查询示例
     */
    public function queryOrderExample()
    {
        try {
            $paymentInfo = $this->getPaymentConfig();
            $orderNumber = 'ORDER_123456789';
            
            $orderInfo = $this->alipayService->queryOrder($orderNumber, $paymentInfo);
            
            echo "订单信息: " . json_encode($orderInfo, JSON_UNESCAPED_UNICODE) . "\n";
            
        } catch (Exception $e) {
            echo "订单查询失败: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * 退款示例
     */
    public function refundExample()
    {
        try {
            $paymentInfo = $this->getPaymentConfig();
            
            $refundInfo = [
                'order_number' => 'ORDER_123456789',
                'refund_number' => 'REFUND_' . time(),
                'refund_amount' => '1.00',
                'refund_reason' => '用户申请退款',
            ];
            
            $refundResult = $this->alipayService->createRefund($refundInfo, $paymentInfo);
            
            echo "退款结果: " . json_encode($refundResult, JSON_UNESCAPED_UNICODE) . "\n";
            
        } catch (Exception $e) {
            echo "退款失败: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * OAuth授权示例
     */
    public function oauthExample()
    {
        try {
            $paymentInfo = $this->getPaymentConfig();
            
            // 1. 获取授权URL
            $authParams = [
                'redirect_uri' => 'https://your-domain.com/alipay/callback',
                'scope' => AlipayConstants::OAUTH_SCOPE_AUTH_USER,
                'state' => 'test_state',
            ];
            
            $authUrl = $this->alipayService->getAuthUrl($authParams, $paymentInfo);
            echo "授权URL: " . $authUrl . "\n";
            
            // 2. 通过授权码获取用户信息（模拟）
            $authCode = 'mock_auth_code';
            $tokenInfo = $this->alipayService->getTokenByAuthCode($authCode, $paymentInfo);
            echo "令牌信息: " . json_encode($tokenInfo, JSON_UNESCAPED_UNICODE) . "\n";
            
            // 3. 获取用户信息
            if (!empty($tokenInfo['access_token'])) {
                $userInfo = $this->alipayService->getUserInfo($tokenInfo['access_token'], $paymentInfo);
                echo "用户信息: " . json_encode($userInfo, JSON_UNESCAPED_UNICODE) . "\n";
            }
            
        } catch (Exception $e) {
            echo "OAuth授权失败: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * 通知处理示例
     */
    public function notifyExample()
    {
        try {
            $paymentInfo = $this->getPaymentConfig();
            
            // 模拟支付通知参数
            $notifyParams = [
                'gmt_create' => '2024-01-01 12:00:00',
                'charset' => 'UTF-8',
                'gmt_payment' => '2024-01-01 12:01:00',
                'notify_time' => '2024-01-01 12:01:00',
                'subject' => '测试商品',
                'sign' => 'mock_sign',
                'buyer_id' => '2088123456789012',
                'invoice_amount' => '1.00',
                'version' => '1.0',
                'notify_id' => 'mock_notify_id',
                'fund_bill_list' => '[{"amount":"1.00","fundChannel":"ALIPAYACCOUNT"}]',
                'notify_type' => 'trade_status_sync',
                'out_trade_no' => 'ORDER_123456789',
                'total_amount' => '1.00',
                'trade_status' => AlipayConstants::TRADE_STATUS_TRADE_SUCCESS,
                'trade_no' => '2024010122001234567890123456',
                'auth_app_id' => 'your_app_id',
                'receipt_amount' => '1.00',
                'point_amount' => '0.00',
                'app_id' => 'your_app_id',
                'buyer_pay_amount' => '1.00',
                'sign_type' => 'RSA2',
                'seller_id' => '2088123456789012',
            ];
            
            $result = $this->alipayService->handlePaymentNotify($notifyParams, $paymentInfo);
            
            echo "通知处理结果: " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
            
        } catch (Exception $e) {
            echo "通知处理失败: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * 配置验证示例
     */
    public function configValidationExample()
    {
        try {
            $paymentInfo = $this->getPaymentConfig();
            
            $isValid = $this->alipayService->validateConfig($paymentInfo);
            
            echo "配置验证结果: " . ($isValid ? '通过' : '失败') . "\n";
            
        } catch (Exception $e) {
            echo "配置验证失败: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * 获取支付配置
     * @return array
     */
    private function getPaymentConfig(): array
    {
        return [
            'appid' => 'your_app_id',
            'AppPrivateKey' => 'your_private_key',
            'alipayCertPublicKey' => 'path/to/alipay_cert_public_key.crt',
            'alipayRootCert' => 'path/to/alipay_root_cert.crt',
            'appCertPublicKey' => 'path/to/app_cert_public_key.crt',
            'notify_url' => 'https://your-domain.com/alipay/notify',
            'sandbox' => false,
        ];
    }
    
    /**
     * 运行所有示例
     */
    public function runAllExamples()
    {
        echo "=== 支付宝服务使用示例 ===\n\n";
        
        echo "1. WAP支付示例:\n";
        $this->wapPayExample();
        echo "\n";
        
        echo "2. APP支付示例:\n";
        $this->appPayExample();
        echo "\n";
        
        echo "3. 扫码支付示例:\n";
        $this->qrPayExample();
        echo "\n";
        
        echo "4. 订单查询示例:\n";
        $this->queryOrderExample();
        echo "\n";
        
        echo "5. 退款示例:\n";
        $this->refundExample();
        echo "\n";
        
        echo "6. OAuth授权示例:\n";
        $this->oauthExample();
        echo "\n";
        
        echo "7. 通知处理示例:\n";
        $this->notifyExample();
        echo "\n";
        
        echo "8. 配置验证示例:\n";
        $this->configValidationExample();
        echo "\n";
        
        echo "=== 示例运行完成 ===\n";
    }
}

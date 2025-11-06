<?php

namespace app\service\payment;

use app\model\Product;
use app\model\PaymentType;
use app\model\Subject;
use app\model\SubjectCert;
use app\service\alipay\AlipayService;
use app\service\alipay\AlipayException;
use Exception;
use support\Log;

/**
 * 支付工厂类 - 根据产品编号调用不同的支付方式
 */
class PaymentFactory
{
    /**
     * 支付方式映射（按支付宝官方命名规范）
     */
    const PAYMENT_METHODS = [
        'WAP_PAY' => 'AlipayService',      // WAP支付
        'APP_PAY' => 'AlipayService',      // APP支付
        'PAGE_PAY' => 'AlipayService',     // 电脑网站支付
        'PRECREATE' => 'AlipayService',    // 扫码支付（当面付预创建）- 生成二维码
        'BAR_PAY' => 'AlipayService',      // 条码支付（当面付付款）- 扫用户码
        'MINI_APP_PAY' => 'AlipayService', // 小程序支付
        'TRANSFER' => 'AlipayService',     // 转账
        
        // 兼容旧命名（逐步废弃）
        'QR_CODE_PAY' => 'AlipayService',  // ← 废弃，使用 PRECREATE
        'FACE_TO_FACE' => 'AlipayService', // ← 废弃，使用 BAR_PAY
        'FACE_PAY' => 'AlipayService',     // ← 待确认用途
    ];

    /**
     * 根据产品编号创建支付
     * @param string $productCode 产品编号
     * @param array $orderInfo 订单信息
     * @param int $agentId 代理商ID
     * @return array 支付结果
     * @throws Exception
     */
    public static function createPayment(string $productCode, array $orderInfo, int $agentId): array
    {
        try {
            // 1. 根据产品编号获取产品信息
            $product = Product::where('product_code', $productCode)
                ->where('agent_id', $agentId)
                ->where('status', Product::STATUS_ENABLED)
                ->with('paymentType')
                ->first();

            if (!$product) {
                throw new Exception("产品不存在或已禁用: {$productCode}");
            }

            if (!$product->paymentType) {
                throw new Exception("产品未配置支付类型: {$productCode}");
            }

            // 2. 获取支付类型信息
            $paymentType = $product->paymentType;
            if ($paymentType->status !== PaymentType::STATUS_ENABLED) {
                throw new Exception("支付类型已禁用: {$paymentType->product_name}");
            }

            // 3. 查找可用的支付主体
            $subject = self::findAvailableSubject($product->id, $agentId);
            if (!$subject) {
                throw new Exception("暂无可用支付主体");
            }

            // 4. 获取支付配置
            $paymentConfig = self::getPaymentConfig($subject, $paymentType);

            // 5. 根据支付类型调用相应的支付方法
            return self::callPaymentMethod($paymentType, $orderInfo, $paymentConfig);

        } catch (Exception $e) {
            Log::error("支付创建失败", [
                'product_code' => $productCode,
                'agent_id' => $agentId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * 查找可用的支付主体
     * @param int $productId 产品ID
     * @param int $agentId 代理商ID
     * @return Subject|null
     */
    public static function findAvailableSubject(int $productId, int $agentId): ?Subject
    {
        // 通过产品查询支付类型ID，然后通过subject_payment_type关联查询可用主体
        $product = Product::find($productId);
        if (!$product || !$product->payment_type_id) {
            return null;
        }
        
        return Subject::where('agent_id', $agentId)
            ->where('status', Subject::STATUS_ENABLED)
            ->whereHas('subjectPaymentTypes', function($query) use ($product) {
                $query->where('payment_type_id', $product->payment_type_id)
                      ->where('status', 1)
                      ->where('is_enabled', 1);
            })
            ->inRandomOrder()
            ->first();
    }

    /**
     * 获取支付配置
     * @param Subject $subject 支付主体
     * @param PaymentType $paymentType 支付类型
     * @return array 支付配置
     * @throws Exception
     */
    public static function getPaymentConfig(Subject $subject, PaymentType $paymentType): array
    {
        // 获取证书信息
        $cert = SubjectCert::where('subject_id', $subject->id)->first();
        if (!$cert) {
            throw new Exception("支付主体证书配置缺失");
        }

        // 构建支付配置
        $config = [
            'appid' => $subject->alipay_app_id,
            'AppPrivateKey' => $cert->app_private_key,
            'alipayCertPublicKey' => 'public' . $cert->alipay_public_cert_path,
            'alipayRootCert' => 'public' . $cert->alipay_root_cert_path,
            'appCertPublicKey' => 'public' . $cert->app_public_cert_path,
            'notify_url' => config('app.url') . '/api/v1/payment/notify/alipay',
            'sandbox' => false, // 暂时禁用沙箱环境
        ];

        // 验证配置完整性
        self::validatePaymentConfig($config);

        return $config;
    }

    /**
     * 验证支付配置
     * @param array $config 配置信息
     * @throws Exception
     */
    private static function validatePaymentConfig(array $config): void
    {
        $requiredFields = [
            'appid' => '应用ID',
            'AppPrivateKey' => '应用私钥',
            'alipayCertPublicKey' => '支付宝公钥证书',
            'alipayRootCert' => '支付宝根证书',
            'appCertPublicKey' => '应用公钥证书',
        ];

        foreach ($requiredFields as $field => $name) {
            if (empty($config[$field])) {
                throw new Exception("支付配置缺少必要参数: {$name}");
            }
        }
    }

    /**
     * 调用支付方法
     * @param PaymentType $paymentType 支付类型
     * @param array $orderInfo 订单信息
     * @param array $paymentConfig 支付配置
     * @return array 支付结果
     * @throws Exception
     */
    private static function callPaymentMethod(PaymentType $paymentType, array $orderInfo, array $paymentConfig): array
    {
        $method = $paymentType->product_code;
        
        if (!isset(self::PAYMENT_METHODS[$method])) {
            throw new Exception("不支持的支付方式: {$method}");
        }

        $serviceClass = self::PAYMENT_METHODS[$method];
        
        switch ($serviceClass) {
            case 'AlipayService':
                return self::callAlipayMethod($method, $orderInfo, $paymentConfig);
            default:
                throw new Exception("未实现的支付服务: {$serviceClass}");
        }
    }

    /**
     * 调用支付宝支付方法
     * @param string $method 支付方法
     * @param array $orderInfo 订单信息
     * @param array $paymentConfig 支付配置
     * @return array 支付结果
     * @throws Exception
     */
    private static function callAlipayMethod(string $method, array $orderInfo, array $paymentConfig): array
    {
        $alipayService = new AlipayService();

        // 构建支付宝订单信息
        $alipayOrderInfo = [
            'payment_order_number' => $orderInfo['platform_order_no'],
            'product_title' => $orderInfo['subject'],
            'payment_amount' => number_format($orderInfo['amount'], 2, '.', ''),
            'order_expiry_time' => $orderInfo['expire_time'],
            'pid' => $orderInfo['alipay_pid'] ?? '',
            'remark' => $orderInfo['body'] ?? $orderInfo['subject'],
            'quit_url' => $orderInfo['return_url'] ?? '',
            'return_url' => $orderInfo['return_url'] ?? '',
            'buyer_id' => $orderInfo['buyer_id'] ?? '', // 添加buyer_id支持
        ];

        try {
            switch ($method) {
                case 'WAP_PAY':
                    $result = $alipayService->wapPay($alipayOrderInfo, $paymentConfig);
                    return [
                        'payment_url' => $result,
                        'payment_form' => $result, // WAP返回的是表单HTML
                        'payment_method' => 'wap',
                        'payment_type' => 'alipay'
                    ];

                case 'APP_PAY':
                    $result = $alipayService->appPay($alipayOrderInfo, $paymentConfig);
                    return [
                        'payment_params' => $result,
                        'payment_method' => 'app',
                        'payment_type' => 'alipay'
                    ];

                case 'PAGE_PAY':
                    $result = $alipayService->pagePay($alipayOrderInfo, $paymentConfig);
                    return [
                        'payment_form' => $result,
                        'payment_method' => 'page',
                        'payment_type' => 'alipay'
                    ];

                case 'PRECREATE':  // 扫码支付（当面付预创建）- 官方规范命名
                case 'QR_CODE_PAY':  // 兼容旧命名
                    $result = $alipayService->qrPay($alipayOrderInfo, $paymentConfig);
                    return [
                        'qr_code' => $result,
                        'payment_method' => 'qr',
                        'payment_type' => 'alipay'
                    ];

                case 'BAR_PAY':  // 条码支付（当面付付款）- 官方规范命名
                case 'FACE_TO_FACE':  // 兼容旧命名
                    if (empty($orderInfo['auth_code'])) {
                        throw new Exception("条码支付缺少授权码");
                    }
                    $result = $alipayService->barPay($alipayOrderInfo, $orderInfo['auth_code'], $paymentConfig);
                    return [
                        'payment_result' => $result,
                        'payment_method' => 'bar',
                        'payment_type' => 'alipay'
                    ];

                case 'FACE_PAY':
                    $result = $alipayService->facePay($alipayOrderInfo, $paymentConfig);
                    return [
                        'payment_url' => $result,
                        'payment_method' => 'face',
                        'payment_type' => 'alipay'
                    ];

                case 'MINI_APP_PAY':
                    $result = $alipayService->miniAppPay($alipayOrderInfo, $paymentConfig);
                    return [
                        'payment_params' => $result,
                        'payment_method' => 'mini_app',
                        'payment_type' => 'alipay'
                    ];

                case 'TRANSFER':
                    $result = $alipayService->transfer($alipayOrderInfo, $paymentConfig);
                    return [
                        'transfer_result' => $result,
                        'payment_method' => 'transfer',
                        'payment_type' => 'alipay'
                    ];

                default:
                    throw new Exception("不支持的支付宝支付方式: {$method}");
            }

        } catch (AlipayException $e) {
            Log::error("支付宝支付失败", [
                'method' => $method,
                'order_number' => $orderInfo['platform_order_no'],
                'error_code' => $e->getErrorCode(),
                'error_message' => $e->getMessage(),
                'error_details' => $e->getErrorDetails()
            ]);
            throw new Exception("支付创建失败: " . $e->getMessage());
        }
    }

    /**
     * 处理支付通知
     * @param string $productCode 产品编号
     * @param array $notifyParams 通知参数
     * @param int $agentId 代理商ID
     * @return array 处理结果
     * @throws Exception
     */
    public static function handlePaymentNotify(string $productCode, array $notifyParams, int $agentId): array
    {
        try {
            // 获取产品和支付类型信息
            $product = Product::where('product_code', $productCode)
                ->where('agent_id', $agentId)
                ->with('paymentType')
                ->first();

            if (!$product || !$product->paymentType) {
                throw new Exception("产品或支付类型不存在");
            }

            // 获取支付配置
            $subject = Subject::where('agent_id', $agentId)
                ->where('status', Subject::STATUS_ENABLED)
                ->whereHas('subjectPaymentTypes', function($query) use ($product) {
                    $query->where('payment_type_id', $product->payment_type_id)
                          ->where('status', 1)
                          ->where('is_enabled', 1);
                })
                ->first();

            if (!$subject) {
                throw new Exception("支付主体不存在");
            }

            $paymentConfig = self::getPaymentConfig($subject, $product->paymentType);

            // 调用支付宝通知处理
            $alipayService = new AlipayService();
            return $alipayService->handlePaymentNotify($notifyParams, $paymentConfig);

        } catch (Exception $e) {
            Log::error("支付通知处理失败", [
                'product_code' => $productCode,
                'agent_id' => $agentId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * 查询订单状态
     * @param string $productCode 产品编号
     * @param string $orderNumber 订单号
     * @param int $agentId 代理商ID
     * @return array 订单信息
     * @throws Exception
     */
    public static function queryOrder(string $productCode, string $orderNumber, int $agentId): array
    {
        try {
            // 获取产品和支付类型信息
            $product = Product::where('product_code', $productCode)
                ->where('agent_id', $agentId)
                ->with('paymentType')
                ->first();

            if (!$product || !$product->paymentType) {
                throw new Exception("产品或支付类型不存在");
            }

            // 获取支付配置
            $subject = Subject::where('agent_id', $agentId)
                ->where('status', Subject::STATUS_ENABLED)
                ->whereHas('subjectPaymentTypes', function($query) use ($product) {
                    $query->where('payment_type_id', $product->payment_type_id)
                          ->where('status', 1)
                          ->where('is_enabled', 1);
                })
                ->first();

            if (!$subject) {
                throw new Exception("支付主体不存在");
            }

            $paymentConfig = self::getPaymentConfig($subject, $product->paymentType);

            // 调用支付宝查询
            $alipayService = new AlipayService();
            return $alipayService->queryOrder($orderNumber, $paymentConfig);

        } catch (Exception $e) {
            Log::error("订单查询失败", [
                'product_code' => $productCode,
                'order_number' => $orderNumber,
                'agent_id' => $agentId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}

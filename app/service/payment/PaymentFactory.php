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
            
            // 打印实际使用的支付宝主体 appid
            Log::info("创建支付使用的支付宝主体", [
                'product_code' => $productCode,
                'agent_id' => $agentId,
                'subject_id' => $subject->id,
                'subject_company_name' => $subject->company_name,
                'alipay_app_id' => $subject->alipay_app_id ?? '未配置',
                'order_number' => $orderInfo['platform_order_no'] ?? '',
                'payment_method' => $paymentType->product_code ?? ''
            ]);
            
            // 4. 获取支付配置
            $paymentConfig = self::getPaymentConfig($subject, $paymentType);
            
            // 打印传递给支付宝的回调地址
            $notifyUrl = $paymentConfig['notify_url'] ?? '未配置';
            echo "\n" . str_repeat("-", 80) . "\n";
            echo "【创建支付】订单号: " . ($orderInfo['platform_order_no'] ?? '未知') . "\n";
            echo "【回调地址】传递给支付宝的 notify_url: {$notifyUrl}\n";
            echo str_repeat("-", 80) . "\n";
            Log::info("创建支付回调地址", [
                'order_number' => $orderInfo['platform_order_no'] ?? '',
                'notify_url' => $notifyUrl,
                'subject_id' => $subject->id,
                'alipay_app_id' => $subject->alipay_app_id ?? '未配置'
            ]);

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
        
        // 明确过滤：只查询启用状态的主体（status=1），排除关闭状态（status=0）
        $subject = Subject::where('agent_id', $agentId)
            ->where('status', 1)  // 明确使用 1 表示启用状态
            ->whereHas('subjectPaymentTypes', function($query) use ($product) {
                $query->where('payment_type_id', $product->payment_type_id)
                      ->where('status', 1)
                      ->where('is_enabled', 1);
            })
            ->inRandomOrder()
            ->first();
        
        // 双重验证：确保查询到的主体确实是启用状态
        if ($subject && $subject->status !== 1) {
            Log::warning('查询到的主体状态不正确', [
                'subject_id' => $subject->id,
                'subject_status' => $subject->status,
                'expected_status' => 1,
                'product_id' => $productId,
                'agent_id' => $agentId
            ]);
            return null;
        }
        
        return $subject;
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

        // 处理证书路径：优先使用文件路径，如果文件不存在则使用数据库中的证书内容创建临时文件
        $alipayCertPath = self::getCertPath($cert->alipay_public_cert_path, $cert->alipay_public_cert, 'alipay_public_cert');
        $alipayRootCertPath = self::getCertPath($cert->alipay_root_cert_path, $cert->alipay_root_cert, 'alipay_root_cert');
        $appCertPath = self::getCertPath($cert->app_public_cert_path, $cert->app_public_cert, 'app_public_cert');

        // 构建支付配置
        // 从 .env 直接读取 APP_URL
        $appUrl = env('APP_URL', 'http://127.0.0.1:8787');
        $config = [
            'appid' => $subject->alipay_app_id,
            'AppPrivateKey' => $cert->app_private_key,
            'alipayCertPublicKey' => $alipayCertPath,
            'alipayRootCert' => $alipayRootCertPath,
            'appCertPublicKey' => $appCertPath,
            'notify_url' => rtrim($appUrl, '/') . '/api/v1/payment/notify/alipay',
            'sandbox' => false, // 暂时禁用沙箱环境
        ];

        // 验证配置完整性
        self::validatePaymentConfig($config);

        return $config;
    }

    /**
     * 获取证书文件路径，如果文件不存在则从数据库内容创建临时文件
     * @param string|null $certPath 证书文件路径
     * @param string|null $certContent 证书内容（数据库存储）
     * @param string $certType 证书类型（用于错误提示）
     * @return string 证书文件路径
     * @throws Exception
     */
    private static function getCertPath(?string $certPath, ?string $certContent, string $certType): string
    {
        // 如果提供了文件路径，先检查文件是否存在
        if (!empty($certPath)) {
            $fullPath = base_path('public' . $certPath);
            if (file_exists($fullPath)) {
                return 'public' . $certPath;
            }
            
            // 文件不存在，记录警告日志
            Log::warning("证书文件不存在，尝试使用数据库中的证书内容", [
                'cert_type' => $certType,
                'file_path' => $fullPath
            ]);
        }

        // 如果文件不存在，尝试使用数据库中的证书内容
        if (!empty($certContent)) {
            // 创建临时文件
            $tempDir = runtime_path() . '/certs';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $tempFile = $tempDir . '/' . uniqid($certType . '_', true) . '.crt';
            if (file_put_contents($tempFile, $certContent) === false) {
                throw new Exception("无法创建临时证书文件: {$certType}");
            }

            // 返回相对于base_path的路径
            $relativePath = str_replace(base_path() . '/', '', $tempFile);
            
            Log::info("使用数据库证书内容创建临时文件", [
                'cert_type' => $certType,
                'temp_file' => $tempFile
            ]);

            return $relativePath;
        }

        // 既没有文件也没有内容
        throw new Exception("证书配置缺失: {$certType}（文件不存在且数据库中没有证书内容）");
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
        // 处理subject和body：如果没传递使用默认值
        $defaultSubject = '商品支付';
        $productTitle = !empty($orderInfo['subject']) ? trim($orderInfo['subject']) : $defaultSubject;
        $remark = !empty($orderInfo['body']) ? trim($orderInfo['body']) : $productTitle;
        
        $alipayOrderInfo = [
            'payment_order_number' => $orderInfo['platform_order_no'],
            'product_title' => $productTitle,
            'payment_amount' => number_format($orderInfo['amount'], 2, '.', ''),
            'order_expiry_time' => $orderInfo['expire_time'] ?? '',
            'pid' => $orderInfo['alipay_pid'] ?? '',
            'remark' => $remark,
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
     * @param \app\model\Order $order 订单对象（已加载product和paymentType关联）
     * @param array $notifyParams 通知参数
     * @return array 处理结果
     * @throws Exception
     */
    public static function handlePaymentNotify(\app\model\Order $order, array $notifyParams): array
    {
        try {
            echo "  【5.1】开始处理支付通知\n";
            echo "    - 订单ID: {$order->id}\n";
            echo "    - 订单号: {$order->platform_order_no}\n";
            
            // 验证订单是否已加载产品和支付类型
            if (!$order->product) {
                $errorMsg = "订单未加载产品信息";
                echo "    - 错误: {$errorMsg}\n";
                throw new Exception($errorMsg);
            }
            if (!$order->product->paymentType) {
                $errorMsg = "产品未配置支付类型";
                echo "    - 错误: {$errorMsg}\n";
                throw new Exception($errorMsg);
            }
            
            $product = $order->product;
            echo "    - 产品ID: {$product->id}\n";
            echo "    - 产品代码: {$product->product_code}\n";
            echo "    - 支付类型ID: {$product->payment_type_id}\n";

            // 获取支付配置（优先使用订单中已保存的subject_id，如果没有则查询）
            echo "  【5.2】查询支付主体\n";
            $subject = null;
            if ($order->subject_id) {
                // 优先使用订单中保存的主体ID
                $subject = Subject::where('id', $order->subject_id)
                    ->where('status', Subject::STATUS_ENABLED)
                    ->first();
                if ($subject) {
                    echo "    - 使用订单中保存的主体ID: {$order->subject_id}\n";
                }
            }
            
            // 如果订单中没有主体或主体不可用，则查询可用主体
            if (!$subject) {
                echo "    - 订单中无主体或主体不可用，查询可用主体\n";
                $subject = Subject::where('agent_id', $order->agent_id)
                    ->where('status', Subject::STATUS_ENABLED)
                    ->whereHas('subjectPaymentTypes', function($query) use ($product) {
                        $query->where('payment_type_id', $product->payment_type_id)
                              ->where('status', 1)
                              ->where('is_enabled', 1);
                    })
                    ->first();
            }

            if (!$subject) {
                $errorMsg = "支付主体不存在或不可用";
                echo "    - 错误: {$errorMsg}\n";
                throw new Exception($errorMsg);
            }
            echo "    - 主体ID: {$subject->id}\n";
            echo "    - 主体名称: {$subject->company_name}\n";
            echo "    - 支付宝APPID: {$subject->alipay_app_id}\n";

            echo "  【5.3】获取支付配置\n";
            $paymentConfig = self::getPaymentConfig($subject, $product->paymentType);
            echo "    - 配置获取成功\n";

            // 调用支付宝通知处理
            echo "  【5.4】调用支付宝通知处理服务\n";
            $alipayService = new AlipayService();
            $result = $alipayService->handlePaymentNotify($notifyParams, $paymentConfig);
            echo "  【5.5】支付宝通知处理完成\n";
            echo "    - 处理结果: " . ($result['success'] ? '成功' : '失败') . "\n";
            if (!$result['success']) {
                echo "    - 错误信息: " . ($result['message'] ?? '未知错误') . "\n";
            }
            return $result;

        } catch (Exception $e) {
            echo "  【5.错误】支付通知处理异常\n";
            echo "    - 错误信息: " . $e->getMessage() . "\n";
            echo "    - 错误位置: " . $e->getFile() . ":" . $e->getLine() . "\n";
            Log::error("支付通知处理失败", [
                'order_id' => $order->id ?? null,
                'platform_order_no' => $order->platform_order_no ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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

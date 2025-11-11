<?php
/**
 * 第三方支付系统 - PHP Demo
 * 用于测试订单创建和支付链接生成功能
 */

class PaymentDemo
{
    private $apiKey;
    private $apiSecret;
    private $baseUrl;
    
    public function __construct($apiKey, $apiSecret, $baseUrl = 'http://127.0.0.1:8787')
    {
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->baseUrl = rtrim($baseUrl, '/');
    }
    
    /**
     * 生成签名
     */
    private function generateSign($params)
    {
        // 移除sign字段
        unset($params['sign']);
        
        // 按键名排序
        ksort($params);
        
        // 构建签名字符串
        $signString = '';
        foreach ($params as $key => $value) {
            if ($value !== '' && $value !== null) {
                $signString .= $key . '=' . $value . '&';
            }
        }
        $signString .= 'key=' . $this->apiSecret;
        
        return md5($signString);
    }
    
    /**
     * 发送HTTP请求
     */
    private function sendRequest($url, $data)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: PaymentDemo/1.0'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'error' => 'CURL错误: ' . $error,
                'http_code' => $httpCode
            ];
        }
        
        $responseData = json_decode($response, true);
        return [
            'success' => true,
            'data' => $responseData,
            'http_code' => $httpCode,
            'raw_response' => $response
        ];
    }
    
    /**
     * 创建订单
     */
    public function createOrder($params)
    {
        $url = $this->baseUrl . '/api/v1/merchant/order/create';
        
        // 构建请求参数
        $requestParams = [
            'api_key' => $this->apiKey,
            'merchant_order_no' => $params['merchant_order_no'],
            'product_code' => $params['product_code'],
            'amount' => $params['amount'],
            'subject' => $params['subject'],
        ];
        
        // 添加可选参数
        if (isset($params['notify_url'])) {
            $requestParams['notify_url'] = $params['notify_url'];
        }
        if (isset($params['return_url'])) {
            $requestParams['return_url'] = $params['return_url'];
        }
        
        // 生成签名
        $requestParams['sign'] = $this->generateSign($requestParams);
        
        return $this->sendRequest($url, $requestParams);
    }
    
    /**
     * 查询订单
     */
    public function queryOrder($merchantOrderNo)
    {
        $url = $this->baseUrl . '/api/v1/merchant/order/query';
        
        $requestParams = [
            'api_key' => $this->apiKey,
            'merchant_order_no' => $merchantOrderNo,
        ];
        
        $requestParams['sign'] = $this->generateSign($requestParams);
        
        return $this->sendRequest($url, $requestParams);
    }
    
}

// 使用示例
if (php_sapi_name() === 'cli') {
    echo "=== 第三方支付系统 PHP Demo ===\n\n";
    
    // 配置API密钥
    $apiKey = 'f227cf12fc2450fb8d6ced8c49d7f0d2';
    $apiSecret = 'c8fe2a77ff57f5d9ef9cb615b6d55fb1';
    $baseUrl = 'http://127.0.0.1:8787';
    
    $demo = new PaymentDemo($apiKey, $apiSecret, $baseUrl);
    
    // 测试用例
    $testCases = [
        [
            'name' => '产品9469 - WAP支付',
            'params' => [
                'merchant_order_no' => 'M' . time() . '_001',
                'product_code' => '9469',
                'amount' => '0.01',
                'subject' => '测试商品9469-WAP支付',
                'notify_url' => 'https://your-domain.com/notify',
                'return_url' => 'https://your-domain.com/return',
            ]
        ],
        [
            'name' => '产品9469 - 扫码支付',
            'params' => [
                'merchant_order_no' => 'M' . time() . '_002',
                'product_code' => '9469',
                'amount' => '0.02',
                'subject' => '测试商品9469-扫码支付',
                'notify_url' => 'https://your-domain.com/notify',
                'return_url' => 'https://your-domain.com/return',
            ]
        ],
        [
            'name' => '产品9469 - 条码支付',
            'params' => [
                'merchant_order_no' => 'M' . time() . '_003',
                'product_code' => '9469',
                'amount' => '0.03',
                'subject' => '测试商品9469-条码支付',
                'auth_code' => '123456789012345678',
                'notify_url' => 'https://your-domain.com/notify',
                'return_url' => 'https://your-domain.com/return',
            ]
        ]
    ];
    
    foreach ($testCases as $index => $testCase) {
        echo "测试用例 " . ($index + 1) . ": {$testCase['name']}\n";
        echo str_repeat("-", 50) . "\n";
        
        // 创建订单
        $result = $demo->createOrder($testCase['params']);
        
        if ($result['success']) {
            $responseData = $result['data'];
            
            echo "HTTP状态码: {$result['http_code']}\n";
            echo "响应数据:\n";
            echo json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            
            if (isset($responseData['code']) && $responseData['code'] === 0) {
                echo "✅ 订单创建成功！\n";
                if (isset($responseData['data']['payment_url'])) {
                    echo "🔗 支付链接: " . $responseData['data']['payment_url'] . "\n";
                }
                if (isset($responseData['data']['qr_code'])) {
                    echo "📱 二维码: " . $responseData['data']['qr_code'] . "\n";
                }
                if (isset($responseData['data']['payment_method'])) {
                    echo "💳 支付方式: " . $responseData['data']['payment_method'] . "\n";
                }
                
                // 保存订单号用于后续测试
                $merchantOrderNo = $testCase['params']['merchant_order_no'];
                
                // 等待2秒后查询订单状态
                echo "\n等待2秒后查询订单状态...\n";
                sleep(2);
                
                $queryResult = $demo->queryOrder($merchantOrderNo);
                if ($queryResult['success']) {
                    echo "订单查询结果:\n";
                    echo json_encode($queryResult['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
                }
                
            } else {
                echo "❌ 订单创建失败: " . ($responseData['msg'] ?? '未知错误') . "\n";
                
                // 分析失败原因
                $errorMsg = $responseData['msg'] ?? '';
                if (strpos($errorMsg, '产品不存在') !== false) {
                    echo "💡 提示: 产品9469可能不存在或已禁用\n";
                } elseif (strpos($errorMsg, '支付主体') !== false) {
                    echo "💡 提示: 支付主体可能未配置或证书不完整\n";
                } elseif (strpos($errorMsg, 'API密钥') !== false) {
                    echo "💡 提示: API密钥验证失败\n";
                } elseif (strpos($errorMsg, '签名') !== false) {
                    echo "💡 提示: 签名验证失败\n";
                }
            }
        } else {
            echo "❌ 请求失败: " . $result['error'] . "\n";
        }
        
        echo "\n" . str_repeat("=", 60) . "\n\n";
        
        // 避免请求过快
        sleep(1);
    }
    
    echo "=== 测试完成 ===\n";
    echo "如果订单创建成功，说明支付链接生成功能正常工作！\n";
}

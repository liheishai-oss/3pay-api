<?php

namespace app\controller;

use support\Request;
use support\Response;
use app\model\Order;
use app\model\Subject;
use app\model\Product;
use app\service\payment\PaymentFactory;
use app\service\alipay\AlipayOAuthService;
use app\common\helpers\TraceIdHelper;
use app\service\OrderLogService;
use app\service\OrderAlertService;
use support\Log;
use support\Db;

/**
 * 支付页面控制器
 */
class PaymentPageController
{
    /**
     * OAuth授权回调处理
     * @param Request $request
     * @return Response
     */
    public function oauthCallback(Request $request): Response
    {
        // 获取TraceId
        $traceId = TraceIdHelper::get($request);
        
        $authCode = $request->get('auth_code', '');
        $state = $request->get('state', ''); // state参数就是订单号
        
        // 验证参数
        if (empty($authCode)) {
            Log::error('OAuth回调缺少auth_code参数', [
                'trace_id' => $traceId,
                'query_params' => $request->get()
            ]);
            return $this->error('授权失败，缺少授权码');
        }
        
        if (empty($state)) {
            Log::error('OAuth回调缺少state参数', [
                'trace_id' => $traceId,
                'query_params' => $request->get()
            ]);
            return $this->error('授权失败，缺少订单号');
        }
        
        $orderNumber = $state;
        
        // 节点11：OAuth回调接收
        OrderLogService::log(
            $traceId,
            $orderNumber,
            '', // 此时还没有商户订单号
            'OAuth',
            'INFO',
            '节点11-OAuth回调接收',
            [
                'authorization_code' => substr($authCode, 0, 10) . '...',
                'order_number_state' => $orderNumber,
                'alipay_app_id' => '待查询',
                'callback_time' => date('Y-m-d H:i:s'),
                'authorization_type' => 'auth_user'
            ],
            $request->getRealIp(),
            $request->header('user-agent', '')
        );
        
        try {
            // 查询订单
            $order = Order::where('platform_order_no', $orderNumber)->first();
            if (!$order) {
                return $this->error('订单不存在');
            }
            
            // 查询支付主体
            $subject = Subject::where('id', $order->subject_id)
                ->where('status', Subject::STATUS_ENABLED)
                ->first();
            if (!$subject) {
                return $this->error('支付主体不存在或已禁用');
            }
            
            // 查询产品信息
            $product = Product::with('paymentType')
                ->where('id', $order->product_id)
                ->where('status', Product::STATUS_ENABLED)
                ->first();
            if (!$product) {
                return $this->error('产品不存在或已禁用');
            }
            
            // 处理OAuth回调，获取buyer_id
            $buyerId = $this->handleOAuthCallback($authCode, $subject, $product, $orderNumber);
            
            // 节点12：获取支付宝用户ID
            OrderLogService::log(
                $traceId,
                $orderNumber,
                $order->merchant_order_no,
                'OAuth',
                $buyerId ? 'INFO' : 'ERROR',
                '节点12-获取支付宝用户ID',
                [
                    'authorization_code' => substr($authCode, 0, 10) . '...',
                    'alipay_api_call_duration' => '待实现',
                    'returned_alipay_user_id' => $buyerId ?: '获取失败',
                    'access_token' => '已获取',
                    'error_message' => $buyerId ? '' : '获取用户ID失败'
                ],
                $request->getRealIp(),
                $request->header('user-agent', '')
            );
            
            if ($buyerId) {
                Log::info('OAuth授权成功', [
                    'order_number' => $orderNumber,
                    'buyer_id' => $buyerId,
                    'verify_device' => $subject->verify_device ?? 0
                ]);
                
                // 黑名单检查（必选）
                $blacklistService = new \app\service\AlipayBlacklistService();
                $checkResult = $blacklistService->checkBlacklist($buyerId, null, $request->getRealIp());
                
                // 节点13：黑名单检查
                OrderLogService::log(
                    $traceId,
                    $orderNumber,
                    $order->merchant_order_no,
                    'OAuth',
                    $checkResult['is_blacklisted'] ? 'WARN' : 'INFO',
                    '节点13-黑名单检查',
                    [
                        'alipay_user_id' => $buyerId,
                        'device_code' => $request->header('user-agent', ''),
                        'ip_address' => $request->getRealIp(),
                        'blacklist_check_result' => $checkResult['is_blacklisted'] ? '命中' : '未命中',
                        'blacklist_type' => $checkResult['is_blacklisted'] ? ($checkResult['type'] ?? '未知') : '无',
                        'blacklist_reason' => $checkResult['is_blacklisted'] ? ($checkResult['reason'] ?? '未知') : '无'
                    ],
                    $request->getRealIp(),
                    $request->header('user-agent', '')
                );
                
                if ($checkResult['is_blacklisted']) {
                    Log::warning('黑名单用户尝试支付', [
                        'buyer_id' => $buyerId,
                        'order_number' => $orderNumber,
                        'check_result' => $checkResult
                    ]);
                    
                    return response('检测到风险行为，支付已被拒绝', 403);
                }
                
                // 检查是否需要设备验证
                if (isset($subject->verify_device) && $subject->verify_device == 1) {
                    // 获取最后拉单IP（订单创建时的IP）
                    $lastOrderIp = $order->client_ip ?? '未知';
                    
                    // 获取当前设备码（User-Agent）
                    $deviceCode = $request->header('user-agent', '未知设备');
                    
                    // 显示设备验证信息页面
                    return $this->showDeviceVerificationPage($buyerId, $lastOrderIp, $deviceCode, $orderNumber, $order);
                }
                
                // 检查订单是否过期（在生成支付前）
                $isExpired = $order->expire_time && strtotime($order->expire_time) < time();
                if ($isExpired) {
                    Log::warning('订单已过期，拒绝生成支付', [
                        'order_number' => $orderNumber,
                        'expire_time' => $order->expire_time,
                        'current_time' => date('Y-m-d H:i:s')
                    ]);
                    
                    // 如果订单还未关闭，先关闭订单
                    if ($order->pay_status == Order::PAY_STATUS_CREATED || $order->pay_status == Order::PAY_STATUS_OPENED) {
                        $now = date('Y-m-d H:i:s');
                        $order->pay_status = Order::PAY_STATUS_CLOSED;
                        $order->close_time = $now;
                        $order->save();
                        
                        OrderLogService::log(
                            $traceId,
                            $orderNumber,
                            $order->merchant_order_no,
                            '关闭',
                            'INFO',
                            '节点20-订单关闭',
                            [
                                'action' => '订单关闭',
                                'close_source' => 'OAuth后过期检查',
                                'operator_ip' => $request->getRealIp(),
                                'close_time' => $now
                            ],
                            $request->getRealIp(),
                            $request->header('user-agent', '')
                        );
                    }
                    
                    return $this->error('订单已过期，无法支付');
                }
                
                // OAuth授权成功后，直接生成支付表单并自动提交（不返回支付页面，减少跳转）
                Log::info('OAuth授权成功，开始生成支付', [
                    'order_number' => $orderNumber,
                    'buyer_id' => $buyerId
                ]);
                
                // 先记录开始时间
                $startTime = microtime(true);
                
                try {
                    // 构建订单信息（包含buyer_id）
                    $orderInfo = [
                        'platform_order_no' => $order->platform_order_no,
                        'merchant_order_no' => $order->merchant_order_no,
                        'subject' => $order->subject,
                        'body' => $order->body ?? $order->subject,
                        'amount' => $order->order_amount,
                        'expire_time' => $order->expire_time,
                        'alipay_pid' => $subject->alipay_pid,
                        'client_ip' => $order->client_ip,
                        'notify_url' => $order->notify_url,
                        'return_url' => $order->return_url,
                        'buyer_id' => $buyerId, // 包含buyer_id
                    ];
                    
                    // 节点15：支付接口调用
                    OrderLogService::log(
                        $traceId,
                        $orderNumber,
                        $order->merchant_order_no,
                        '支付',
                        'INFO',
                        '节点15-支付接口调用',
                        [
                            'call_start_time' => date('Y-m-d H:i:s'),
                            'product_code' => $product->product_code,
                            'payment_type' => $product->paymentType->product_code ?? '',
                            'order_info' => $orderInfo,
                            'alipay_user_id' => $buyerId,
                            'call_duration' => '待计算'
                        ],
                        $request->getRealIp(),
                        $request->header('user-agent', '')
                    );
                    
                    Log::info('⏱️ 开始调用支付接口', [
                        'order_number' => $orderNumber,
                        'product_code' => $product->product_code,
                        'has_buyer_id' => true,
                        'start_time' => date('Y-m-d H:i:s')
                    ]);
                    
                    // 调用支付工厂创建支付（这里可能耗时较长）
                    $paymentResult = PaymentFactory::createPayment(
                        $product->product_code,
                        $orderInfo,
                        $order->agent_id
                    );
                    
                    // 记录耗时
                    $duration = round((microtime(true) - $startTime) * 1000, 2);
                    
                    // 更新节点15的日志，添加调用结果
                    OrderLogService::log(
                        $traceId,
                        $orderNumber,
                        $order->merchant_order_no,
                        '支付',
                        'INFO',
                        '节点15-支付接口调用',
                        [
                            'call_start_time' => date('Y-m-d H:i:s'),
                            'product_code' => $product->product_code,
                            'payment_type' => $product->paymentType->product_code ?? '',
                            'order_info' => $orderInfo,
                            'alipay_user_id' => $buyerId,
                            'call_duration' => $duration . 'ms',
                            'call_result' => '成功',
                            'response_time' => $duration > 3000 ? '较慢' : '正常'
                        ],
                        $request->getRealIp(),
                        $request->header('user-agent', '')
                    );
                    
                    Log::info('⏱️ 支付接口调用完成', [
                        'order_number' => $orderNumber,
                        'duration_ms' => $duration,
                        'duration_desc' => $duration > 3000 ? '⚠️ 响应较慢' : '✅ 响应正常'
                    ]);
                    
                    // 节点16：支付宝返回数据解析
                    OrderLogService::log(
                        $traceId,
                        $orderNumber,
                        $order->merchant_order_no,
                        '支付',
                        'INFO',
                        '节点16-支付宝返回数据解析',
                        [
                            'alipay_return_code' => $paymentResult['code'] ?? 'unknown',
                            'return_message' => $paymentResult['msg'] ?? '',
                            'sub_message' => $paymentResult['sub_msg'] ?? '',
                            'payment_form_url' => (!empty($paymentResult['payment_form']) || !empty($paymentResult['payment_url'])) ? '已生成' : '未生成',
                            'qr_code_url' => !empty($paymentResult['qr_code']) ? '已生成' : '未生成',
                            'parse_result' => '成功'
                        ],
                        $request->getRealIp(),
                        $request->header('user-agent', '')
                    );
                    
                    Log::info('支付生成成功', [
                        'order_number' => $orderNumber,
                        'payment_method' => $paymentResult['payment_method'] ?? 'unknown',
                        'buyer_id' => $buyerId,
                        'has_qr_code' => isset($paymentResult['qr_code'])
                    ]);
                    
                    // 判断支付类型：如果是当面付（扫码），显示二维码；否则自动提交表单
                    if (isset($paymentResult['qr_code']) && !empty($paymentResult['qr_code'])) {
                        // 节点17：支付页面渲染（二维码）
                        OrderLogService::log(
                            $traceId,
                            $orderNumber,
                            $order->merchant_order_no,
                            '支付',
                            'INFO',
                            '节点17-支付页面渲染',
                            [
                                'payment_type' => '二维码',
                                'render_method' => '手动点击',
                                'auto_pay' => false,
                                'qr_code_generated' => true
                            ],
                            $request->getRealIp(),
                            $request->header('user-agent', '')
                        );
                        
                        // 当面付：包装二维码为支付宝APP调起协议
                        $qrCodeUrl = $paymentResult['qr_code'];
                        $alipaySchemeUrl = 'alipays://platformapi/startapp?appId=20000067&url=' . urlencode($qrCodeUrl);
                        
                        Log::info('🔲 当面付二维码生成成功', [
                            'order_number' => $orderNumber,
                            'buyer_id' => $buyerId,
                            'original_qr_code' => $qrCodeUrl,
                            'alipay_scheme_url' => $alipaySchemeUrl,
                            'scheme_url_length' => strlen($alipaySchemeUrl),
                            'payment_method' => 'qr_code',
                            '说明' => '扫码后将自动调起支付宝APP'
                        ]);
                        
                        // 记录最终二维码地址（完整版）
                        Log::info('📱 当面付最终调起地址（完整）', [
                            'order_number' => $orderNumber,
                            'final_qr_code_url' => $alipaySchemeUrl
                        ]);
                        
                        // 显示二维码
                        return $this->showQRCodePaymentPage($order, $alipaySchemeUrl, $buyerId);
                    }
                    
                    // WAP支付：获取支付表单
                    $paymentForm = $paymentResult['payment_form'] ?? $paymentResult['payment_url'] ?? '';
                    
                    if (empty($paymentForm)) {
                        throw new \Exception('支付表单生成失败');
                    }
                    
                    // 节点17：支付页面渲染（表单）
                    OrderLogService::log(
                        $traceId,
                        $orderNumber,
                        $order->merchant_order_no,
                        '支付',
                        'INFO',
                        '节点17-支付页面渲染',
                        [
                            'payment_type' => 'WAP表单',
                            'render_method' => '自动提交',
                            'auto_pay' => true,
                            'payment_form_generated' => true
                        ],
                        $request->getRealIp(),
                        $request->header('user-agent', '')
                    );
                    
                    Log::info('WAP支付表单生成成功，直接提交（无需返回支付页面）', [
                        'order_number' => $orderNumber,
                        'buyer_id' => $buyerId
                    ]);
                    
                    // 直接输出包含支付表单的HTML页面，并自动提交（减少一次跳转）
                    $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>正在跳转到支付宝...</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", "Helvetica Neue", Helvetica, Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .loading-container {
            text-align: center;
            padding: 40px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
        }
        .spinner {
            width: 50px;
            height: 50px;
            margin: 0 auto 20px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .loading-text {
            font-size: 16px;
            color: #333;
            margin-bottom: 10px;
        }
        .success-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        .order-info {
            font-size: 14px;
            color: #666;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }
    </style>
</head>
<body>
    <div class="loading-container">
        <div class="success-icon">✅</div>
        <div class="loading-text">授权成功！正在跳转到支付宝...</div>
        <div class="spinner"></div>
        <div class="order-info">
            订单号：{$orderNumber}<br>
            金额：¥{$order->order_amount}
        </div>
    </div>
    
    <!-- 支付表单（自动提交） -->
    {$paymentForm}
    
    <script>
        console.log('🎯 OAuth授权成功，buyer_id: {$buyerId}');
        console.log('🚀 支付表单已生成，准备自动提交...');
        
        // 页面加载后自动提交表单
        window.onload = function() {
            console.log('📝 查找支付表单...');
            
            const form = document.forms["alipaysubmit"] || document.forms[0];
            if (form) {
                console.log('✅ 找到支付表单，准备提交');
                console.log('📌 表单action:', form.action);
                
                // 延迟500ms提交，让用户看到提示
                setTimeout(function() {
                    console.log('🚀 正在提交支付表单到支付宝...');
                    form.submit();
                }, 500);
            } else {
                console.error('❌ 未找到支付表单');
                alert('支付表单加载失败，请返回重试');
            }
        };
    </script>
</body>
</html>
HTML;
                    
                    return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
                    
                } catch (\Exception $e) {
                    Log::error('OAuth回调后生成支付失败', [
                        'order_number' => $orderNumber,
                        'buyer_id' => $buyerId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    
                    return $this->error('生成支付失败：' . $e->getMessage());
                }
                
                // 以下是旧的调试代码，已被重定向逻辑替代
                /*
                $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OAuth授权成功</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 16px;
            padding: 40px;
            max-width: 500px;
            width: 100%;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }
        .success-icon {
            text-align: center;
            font-size: 64px;
            margin-bottom: 20px;
        }
        .title {
            text-align: center;
            color: #28a745;
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 30px;
        }
        .info-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .info-label {
            color: #666;
            font-size: 14px;
            margin-bottom: 5px;
        }
        .info-value {
            color: #333;
            font-size: 16px;
            font-weight: bold;
            word-break: break-all;
        }
        .buyer-id {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 20px;
        }
        .btn {
            width: 100%;
            background: #28a745;
            color: white;
            border: none;
            padding: 15px;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
            margin-top: 20px;
        }
        .btn:hover {
            background: #218838;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="success-icon">✅</div>
        <div class="title">OAuth授权成功</div>
        
        <div class="buyer-id">
            支付宝用户ID<br>
            {$buyerId}
        </div>
        
        <div class="info-item">
            <div class="info-label">订单号</div>
            <div class="info-value">{$orderNumber}</div>
        </div>
        
        <div class="info-item">
            <div class="info-label">授权码（部分）</div>
            <div class="info-value">
HTML
. substr($authCode, 0, 20) . '...' .
<<<HTML
</div>
        </div>
        
        <div class="info-item">
            <div class="info-label">时间</div>
            <div class="info-value">
HTML
. date('Y-m-d H:i:s') .
<<<HTML
</div>
        </div>
        
        <button class="btn" onclick="continuePayment()">继续完成支付</button>
    </div>
    
    <script>
        function continuePayment() {
            window.location.href = '/payment.html?order_number={$orderNumber}&buyer_id={$buyerId}&auto_pay=1';
        }
        
        // 自动复制buyer_id到剪贴板
        navigator.clipboard.writeText('{$buyerId}').then(() => {
            console.log('✅ Buyer ID已复制到剪贴板:', '{$buyerId}');
        }).catch(err => {
            console.log('Buyer ID:', '{$buyerId}');
        });
    </script>
</body>
</html>
HTML;
                
                return response($html, 200, ['Content-Type' => 'text/html']);
                */
            } else {
                // 获取最后一次错误日志以显示给用户
                $lastError = '调用支付宝OAuth接口失败';
                
                Log::error('OAuth授权失败，无法获取buyer_id', [
                    'order_number' => $orderNumber,
                    'auth_code' => substr($authCode, 0, 10) . '...',
                    'full_auth_code' => $authCode,
                    'auth_code_length' => strlen($authCode),
                    'subject_id' => $subject->id,
                    'product_id' => $product->id,
                    'app_id' => $subject->alipay_app_id
                ]);
                
                // 显示详细的错误信息页面（用于调试）
                $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OAuth授权失败</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 16px;
            padding: 40px;
            max-width: 500px;
            width: 100%;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }
        .error-icon {
            text-align: center;
            font-size: 64px;
            margin-bottom: 20px;
        }
        .title {
            text-align: center;
            color: #dc3545;
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 20px;
        }
        .message {
            text-align: center;
            color: #666;
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 20px;
        }
        .debug-info {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #495057;
        }
        .debug-item {
            margin-bottom: 8px;
            word-break: break-all;
        }
        .debug-item strong {
            color: #212529;
        }
        .btn {
            width: 100%;
            background: #007bff;
            color: white;
            border: none;
            padding: 15px;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
            margin-bottom: 10px;
        }
        .btn:hover {
            background: #0056b3;
        }
        .btn-secondary {
            background: #6c757d;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="error-icon">❌</div>
        <div class="title">OAuth授权失败</div>
        
        <div class="message">
            无法获取支付宝用户信息
        </div>
        
        <div class="debug-info">
            <div class="debug-item">
                <strong>错误原因：</strong>{$lastError}
            </div>
            <div class="debug-item">
                <strong>订单号：</strong>{$orderNumber}
            </div>
            <div class="debug-item">
                <strong>授权码长度：</strong>" . strlen($authCode) . " 字符
            </div>
            <div class="debug-item">
                <strong>支付宝AppID：</strong>{$subject->alipay_app_id}
            </div>
            <div class="debug-item" style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #dee2e6;">
                <strong>💡 可能的原因：</strong><br>
                1. 支付宝证书配置不正确<br>
                2. 授权码已过期或无效<br>
                3. AppID与证书不匹配<br>
                4. 网络连接问题
            </div>
        </div>
        
        <button class="btn" onclick="window.location.href='/payment.html?order_number={$orderNumber}'">重新授权</button>
        <button class="btn btn-secondary" onclick="window.history.back()">返回上一页</button>
    </div>
    
    <script>
        console.error('OAuth授权失败');
        console.error('订单号:', '{$orderNumber}');
        console.error('授权码长度:', " . strlen($authCode) . ");
        console.error('AppID:', '{$subject->alipay_app_id}');
        console.error('请检查日志文件获取详细错误信息');
    </script>
</body>
</html>
HTML;
                
                return response($html, 200, ['Content-Type' => 'text/html']);
            }
            
        } catch (\Exception $e) {
            Log::error('OAuth回调处理异常', [
                'order_number' => $orderNumber ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->error('授权处理失败：' . $e->getMessage());
        }
    }
    
    /**
     * H5支付页面
     * @param Request $request
     * @return Response
     */
    public function payment(Request $request): Response
    {
        // 获取TraceId
        $traceId = TraceIdHelper::get($request);
        
        $orderNumber = $request->get('order_number', '');
        
        // 验证订单号参数
        if (empty($orderNumber)) {
            return $this->error('订单号不能为空');
        }
        
        // 检测是否为移动端
        $isMobile = $this->isMobile($request);
        
        // 检测是否为微信浏览器
        $isWeChat = $this->isWeChat($request);
        
        // 检测是否为支付宝浏览器
        $isAlipay = $this->isAlipay($request);
        
        // 节点7：支付页面访问
        OrderLogService::log(
            $traceId,
            $orderNumber,
            '', // 此时还没有商户订单号
            '访问',
            'INFO',
            '节点7-支付页面访问',
            [
                'access_time' => date('Y-m-d H:i:s'),
                'order_number' => $orderNumber,
                'access_source' => $request->getRealIp(),
                'user_agent' => $request->header('user-agent', ''),
                'referer' => $request->header('referer', ''),
                'device_type' => $isMobile ? '移动端' : 'PC端',
                'browser_type' => $isAlipay ? '支付宝' : ($isWeChat ? '微信' : '其他'),
                'is_first_visit' => true // 简化处理，实际应该检查
            ],
            $request->getRealIp(),
            $request->header('user-agent', '')
        );
        
        // 添加调试日志
        Log::info('支付页面访问调试', [
            'trace_id' => $traceId,
            'order_number' => $orderNumber,
            'user_agent' => $request->header('user-agent', ''),
            'is_mobile' => $isMobile,
            'is_wechat' => $isWeChat,
            'is_alipay' => $isAlipay
        ]);
        
        try {
            // 根据订单号查询订单
            $order = Order::where('platform_order_no', $orderNumber)->first();
            if (!$order) {
                return $this->error('订单不存在');
            }
            
            // 节点9：订单状态检查
            $orderStatusText = $this->getPayStatusText($order->pay_status);
            $isExpired = $order->expire_time && strtotime($order->expire_time) < time();
            
            // 如果订单是已创建状态，更新为已打开状态（用户已访问支付页面）
            if ($order->pay_status == Order::PAY_STATUS_CREATED) {
                $order->pay_status = Order::PAY_STATUS_OPENED;
                $order->first_open_ip = $request->getRealIp();
                $order->first_open_time = date('Y-m-d H:i:s');
                $order->save();
                
                OrderLogService::log(
                    $traceId,
                    $orderNumber,
                    $order->merchant_order_no,
                    '访问',
                    'INFO',
                    '节点8-订单状态更新为已打开',
                    [
                        'old_status' => Order::PAY_STATUS_CREATED,
                        'new_status' => Order::PAY_STATUS_OPENED,
                        'first_open_ip' => $order->first_open_ip,
                        'first_open_time' => $order->first_open_time
                    ],
                    $request->getRealIp(),
                    $request->header('user-agent', '')
                );
            }
            
            OrderLogService::log(
                $traceId,
                $orderNumber,
                $order->merchant_order_no,
                '访问',
                'INFO',
                '节点9-订单状态检查',
                [
                    'current_status' => $order->pay_status,
                    'status_text' => $orderStatusText,
                    'expire_time' => $order->expire_time,
                    'is_expired' => $isExpired,
                    'is_payable' => ($order->pay_status == Order::PAY_STATUS_CREATED || $order->pay_status == Order::PAY_STATUS_OPENED) && !$isExpired
                ],
                $request->getRealIp(),
                $request->header('user-agent', '')
            );
            
            // 检查订单状态（允许已创建和已打开状态）
            if ($order->pay_status != Order::PAY_STATUS_CREATED && $order->pay_status != Order::PAY_STATUS_OPENED) {
                $statusMessage = match ($order->pay_status) {
                    Order::PAY_STATUS_PAID => '订单已支付，无需重复操作！',
                    Order::PAY_STATUS_CLOSED => '订单已关闭，无法支付',
                    Order::PAY_STATUS_REFUNDED => '订单已退款，不能再次支付',
                    default => '订单状态异常，无法支付',
                };
                return $this->error($statusMessage);
            }
            
            if ($isExpired) {
                // 如果订单已过期，自动关闭
                if ($order->pay_status == Order::PAY_STATUS_OPENED) {
                    $now = date('Y-m-d H:i:s');
                    $order->pay_status = Order::PAY_STATUS_CLOSED;
                    $order->close_time = $now;
                    $order->save();
                    
                    OrderLogService::log(
                        $traceId,
                        $orderNumber,
                        $order->merchant_order_no,
                        '关闭',
                        'INFO',
                        '节点20-订单关闭',
                        [
                            'action' => '订单关闭',
                            'close_source' => '支付页面过期检查',
                            'operator_ip' => $request->getRealIp(),
                            'close_time' => $now
                        ],
                        $request->getRealIp(),
                        $request->header('user-agent', '')
                    );
                }
                return $this->error('订单已过期，无法支付');
            }
            
            // 查询支付主体
            $subject = Subject::where('id', $order->subject_id)
                ->where('status', Subject::STATUS_ENABLED)
                ->first();
            if (!$subject) {
                return $this->error('支付主体不存在或已禁用');
            }
            
            // 异地IP检测（如果主体禁用了异地拉单）
            if (isset($subject->allow_remote_order) && $subject->allow_remote_order == 0) {
                $currentIp = $request->getRealIp();
                
                // 检查是否是首次打开支付页面
                if (empty($order->first_open_ip)) {
                    // 首次打开，记录IP和时间
                    $order->first_open_ip = $currentIp;
                    $order->first_open_time = date('Y-m-d H:i:s');
                    $order->save();
                    
                    // 节点8：异地IP检测（首次访问）
                    OrderLogService::log(
                        $traceId,
                        $orderNumber,
                        $order->merchant_order_no,
                        '访问',
                        'INFO',
                        '节点8-异地IP检测',
                        [
                            'order_first_open_ip' => $currentIp,
                            'current_access_ip' => $currentIp,
                            'ip_comparison_result' => '首次访问',
                            'subject_remote_order_config' => $subject->allow_remote_order,
                            'interception_result' => '通过'
                        ],
                        $request->getRealIp(),
                        $request->header('user-agent', '')
                    );
                    
                    Log::info('首次打开支付页面，记录IP', [
                        'order_number' => $orderNumber,
                        'subject_id' => $subject->id,
                        'first_open_ip' => $currentIp,
                        'first_open_time' => $order->first_open_time
                    ]);
                } else {
                    // 非首次打开，检测IP是否一致
                    if ($order->first_open_ip !== $currentIp) {
                        // 节点8：异地IP检测（拦截）
                        OrderLogService::log(
                            $traceId,
                            $orderNumber,
                            $order->merchant_order_no,
                            '访问',
                            'WARN',
                            '节点8-异地IP检测',
                            [
                                'order_first_open_ip' => $order->first_open_ip,
                                'current_access_ip' => $currentIp,
                                'ip_comparison_result' => '不一致',
                                'subject_remote_order_config' => $subject->allow_remote_order,
                                'interception_result' => '拦截'
                            ],
                            $request->getRealIp(),
                            $request->header('user-agent', '')
                        );
                        
                        Log::warning('检测到异地访问支付页面', [
                            'order_number' => $orderNumber,
                            'subject_id' => $subject->id,
                            'first_open_ip' => $order->first_open_ip,
                            'current_ip' => $currentIp,
                            'order_no' => $order->platform_order_no
                        ]);
                        
                        return $this->error("检测到异地访问。首次打开IP: {$order->first_open_ip}，当前访问IP: {$currentIp}");
                    } else {
                        // 节点8：异地IP检测（通过）
                        OrderLogService::log(
                            $traceId,
                            $orderNumber,
                            $order->merchant_order_no,
                            '访问',
                            'INFO',
                            '节点8-异地IP检测',
                            [
                                'order_first_open_ip' => $order->first_open_ip,
                                'current_access_ip' => $currentIp,
                                'ip_comparison_result' => '一致',
                                'subject_remote_order_config' => $subject->allow_remote_order,
                                'interception_result' => '通过'
                            ],
                            $request->getRealIp(),
                            $request->header('user-agent', '')
                        );
                    }
                }
            }
            
            // 查询产品信息
            $product = Product::with('paymentType')
                ->where('id', $order->product_id)
                ->where('status', Product::STATUS_ENABLED)
                ->first();
            if (!$product) {
                return $this->error('产品不存在或已禁用');
            }
            
            // 获取buyer_id（从URL参数，OAuth回调后会带上这个参数）
            $buyerId = $request->get('buyer_id', '');
            
            Log::info('用户访问支付页面', [
                'order_number' => $orderNumber,
                'product_code' => $product->product_code ?? 'unknown',
                'amount' => $order->order_amount,
                'has_buyer_id' => !empty($buyerId)
            ]);
            
            // payment.html 页面不自动调用支付接口
            // 只显示订单信息，用户点击"立即支付"后才跳转到 OAuth 授权
            
            // 构建订单信息
            $orderNo = $order->platform_order_no;
            $amount = number_format($order->order_amount, 2, '.', '');
            $orderSubject = is_string($order->subject) ? $order->subject : '商品支付';
            $expireTime = strtotime($order->expire_time);
            
            // 构建 OAuth 授权 URL
            $redirectUri = config('app.app_url', $request->url(true)) . '/oauth/callback';
            $oauthUrl = "https://openauth.alipay.com/oauth2/publicAppAuthorize.htm"
                . "?app_id=" . $subject->alipay_app_id
                . "&scope=auth_user"
                . "&redirect_uri=" . urlencode($redirectUri)
                . "&state=" . $orderNumber;
            
            // 检查是否需要OAuth授权（移动端且没有buyer_id）
            $needOAuth = $isMobile && !$isAlipay && empty($buyerId);
            
            // 节点10：OAuth授权跳转
            OrderLogService::log(
                $traceId,
                $orderNumber,
                $order->merchant_order_no,
                '访问',
                'INFO',
                '节点10-OAuth授权跳转',
                [
                    'need_oauth_authorization' => $needOAuth,
                    'oauth_authorization_url' => $oauthUrl,
                    'alipay_app_id' => $subject->alipay_app_id,
                    'callback_address' => $redirectUri,
                    'order_number_state' => $orderNumber,
                    'authorization_type' => 'auth_user'
                ],
                $request->getRealIp(),
                $request->header('user-agent', '')
            );
            
            Log::info('显示支付页面（未调用支付接口）', [
                'order_number' => $orderNumber,
                'need_oauth' => $needOAuth,
                'oauth_url' => $oauthUrl
            ]);
            
            // 使用原来的 payment 视图，但不传递 paymentForm（不自动调用支付）
            return raw_view('payment', [
                'orderNo' => $orderNo,
                'amount' => $amount,
                'subject' => $orderSubject,
                'expireTime' => $expireTime,
                'paymentForm' => '', // 不传递支付表单，页面不会自动提交
                'scanPayEnabled' => $subject->scan_pay_enabled == 1,
                'isMobile' => $isMobile,
                'isWeChat' => $isWeChat,
                'isAlipay' => $isAlipay,
                'appPayUrl' => null,
                'needOAuth' => $needOAuth,
                'hasBuyerId' => !empty($buyerId),
                'autoPay' => false,  // 不自动触发支付
                'subjectObj' => $subject,
                'oauthUrl' => $oauthUrl  // 传递 OAuth URL 给前端
            ]);
            
        } catch (\Exception $e) {
            Log::error('支付页面异常', [
                'order_number' => $orderNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->error('系统异常，请稍后重试');
        }
    }
    
    /**
     * 显示二维码支付页面（当面付）
     * @param Order $order 订单对象
     * @param string $qrCode 二维码内容
     * @param string $buyerId 支付宝用户ID
     * @return Response
     */
    private function showQRCodePaymentPage($order, string $qrCode, string $buyerId): Response
    {
        $orderNumber = $order->platform_order_no;
        $amount = number_format($order->order_amount, 2, '.', '');
        
        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>扫码支付 - 当面付</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", "Helvetica Neue", Helvetica, Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
        }
        .payment-container {
            text-align: center;
            padding: 40px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            max-width: 500px;
            width: 100%;
        }
        .success-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        .title {
            font-size: 24px;
            color: #333;
            margin-bottom: 10px;
            font-weight: 600;
        }
        .subtitle {
            font-size: 14px;
            color: #666;
            margin-bottom: 30px;
        }
        .qrcode-box {
            background: white;
            padding: 30px;
            border-radius: 12px;
            border: 3px solid #52c41a;
            margin: 20px 0;
            box-shadow: 0 4px 12px rgba(82, 196, 26, 0.2);
        }
        .qrcode-title {
            font-size: 18px;
            color: #52c41a;
            margin-bottom: 20px;
            font-weight: 600;
        }
        #qrcode {
            display: inline-block;
            padding: 10px;
            background: white;
        }
        .order-info {
            background: #f5f5f5;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            text-align: left;
        }
        .order-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .order-item:last-child {
            border-bottom: none;
        }
        .order-item label {
            color: #666;
            font-size: 14px;
        }
        .order-item span {
            color: #333;
            font-size: 14px;
            font-weight: 500;
        }
        .amount {
            font-size: 32px;
            color: #ff6b00;
            font-weight: 700;
            margin: 15px 0;
        }
        .tips {
            background: #e6f7ff;
            border: 1px solid #91d5ff;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
            font-size: 13px;
            color: #0050b3;
            text-align: left;
            line-height: 1.6;
        }
        .tips-title {
            font-weight: 600;
            margin-bottom: 8px;
        }
    </style>
    <script src="https://cdn.bootcdn.net/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" 
            onerror="this.onerror=null; this.src='https://cdn.jsdelivr.net/npm/qrcodejs2@0.0.2/qrcode.min.js'"></script>
</head>
<body>
    <div class="payment-container">
        <div class="success-icon">✅</div>
        <div class="title">授权成功</div>
        <div class="subtitle">请使用支付宝扫描下方二维码完成支付</div>
        
        <div class="qrcode-box">
            <div class="qrcode-title">💰 扫码支付</div>
            <div id="qrcode"></div>
        </div>
        
        <div class="amount">¥{$amount}</div>
        
        <div class="order-info">
            <div class="order-item">
                <label>订单号：</label>
                <span>{$orderNumber}</span>
            </div>
            <div class="order-item">
                <label>订单金额：</label>
                <span>¥{$amount}</span>
            </div>
            <div class="order-item">
                <label>买家ID：</label>
                <span>{$buyerId}</span>
            </div>
        </div>
        
        <div class="tips">
            <div class="tips-title">💡 支付说明</div>
            <div>1. 请使用支付宝APP扫描上方二维码</div>
            <div>2. 扫码后会自动调起支付宝支付页面</div>
            <div>3. 确认订单信息后完成支付</div>
            <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #91d5ff; font-size: 12px; color: #999;">
                ℹ️ 二维码使用支付宝APP调起协议（alipays://）
            </div>
        </div>
    </div>
    
    <script>
        console.log('═══════════════════════════════════════════════════════');
        console.log('🔲 当面付二维码展示页面');
        console.log('═══════════════════════════════════════════════════════');
        console.log('🎯 OAuth授权成功，展示当面付二维码');
        console.log('📝 买家ID:', '{$buyerId}');
        console.log('💰 订单金额:', '¥{$amount}');
        console.log('📦 订单号:', '{$orderNumber}');
        console.log('');
        console.log('🔗 使用支付宝APP调起协议');
        console.log('协议: alipays://platformapi/startapp');
        console.log('AppID: 20000067');
        console.log('');
        
        window.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                if (typeof QRCode === 'undefined') {
                    console.error('❌ QRCode 库未加载');
                    document.getElementById('qrcode').innerHTML = '<div style="color: #ff4d4f; padding: 20px;">二维码库加载失败，请刷新页面</div>';
                    return;
                }
                
                try {
                    const qrcodeContainer = document.getElementById('qrcode');
                    qrcodeContainer.innerHTML = '';
                    
                    const qrCodeData = '{$qrCode}';
                    
                    console.log('───────────────────────────────────────────────────────');
                    console.log('📱 当面付最终调起地址（完整）:');
                    console.log(qrCodeData);
                    console.log('');
                    console.log('地址长度:', qrCodeData.length, '字符');
                    console.log('───────────────────────────────────────────────────────');
                    console.log('');
                    console.log('🔄 开始生成二维码（256x256）...');
                    
                    new QRCode(qrcodeContainer, {
                        text: qrCodeData,
                        width: 256,
                        height: 256,
                        colorDark: '#000000',
                        colorLight: '#ffffff',
                        correctLevel: QRCode.CorrectLevel.H
                    });
                    
                    console.log('✅ 二维码生成成功');
                    console.log('📱 扫码后将自动调起支付宝APP');
                    console.log('═══════════════════════════════════════════════════════');
                } catch (error) {
                    console.error('❌ 二维码生成失败:', error);
                    document.getElementById('qrcode').innerHTML = '<div style="color: #ff4d4f; padding: 20px;">二维码生成失败: ' + error.message + '</div>';
                }
            }, 100);
        });
    </script>
</body>
</html>
HTML;
        
        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
    
    /**
     * 显示设备验证信息页面
     * @param string $buyerId 支付宝用户ID
     * @param string $lastOrderIp 最后拉单IP
     * @param string $deviceCode 设备码
     * @param string $orderNumber 订单号
     * @param Order $order 订单对象
     * @return Response
     */
    private function showDeviceVerificationPage(string $buyerId, string $lastOrderIp, string $deviceCode, string $orderNumber, $order): Response
    {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>设备验证</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 16px;
            padding: 40px;
            max-width: 600px;
            width: 100%;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }
        .icon {
            text-align: center;
            font-size: 64px;
            margin-bottom: 20px;
        }
        .title {
            text-align: center;
            color: #667eea;
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 30px;
        }
        .info-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .info-label {
            color: #666;
            font-size: 14px;
            margin-bottom: 5px;
        }
        .info-value {
            color: #333;
            font-size: 16px;
            font-weight: bold;
            word-break: break-all;
        }
        .highlight {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 20px;
        }
        .btn {
            width: 100%;
            background: #28a745;
            color: white;
            border: none;
            padding: 15px;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
            margin-top: 20px;
        }
        .btn:hover {
            background: #218838;
        }
        .warning {
            background: #fff3cd;
            border: 2px solid #ffc107;
            color: #856404;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🔐</div>
        <div class="title">设备验证信息</div>
        
        <div class="warning">
            <strong>⚠️ 注意：</strong>该主体已开启设备验证，请核对以下信息
        </div>
        
        <div class="highlight">
            支付宝用户ID<br>
            {$buyerId}
        </div>
        
        <div class="info-item">
            <div class="info-label">订单号</div>
            <div class="info-value">{$orderNumber}</div>
        </div>
        
        <div class="info-item">
            <div class="info-label">最后拉单IP</div>
            <div class="info-value">{$lastOrderIp}</div>
        </div>
        
        <div class="info-item">
            <div class="info-label">设备指纹码</div>
            <div class="info-value" id="deviceFingerprintCode" style="color: #999;">正在获取...</div>
        </div>
        
        <div class="info-item">
            <div class="info-label">当前设备码（User-Agent）</div>
            <div class="info-value" style="font-size: 12px; word-break: break-word;">{$deviceCode}</div>
        </div>
        
        <div class="info-item">
            <div class="info-label">订单金额</div>
            <div class="info-value">¥ {$order->order_amount}</div>
        </div>
        
        <div class="info-item">
            <div class="info-label">当前时间</div>
            <div class="info-value">
HTML
. date('Y-m-d H:i:s') .
<<<HTML
</div>
        </div>
        
        <button class="btn" onclick="continuePayment()">确认无误，继续完成支付</button>
    </div>
    
    <script>
        // 动态加载设备指纹库（根据当前页面协议自动选择HTTP或HTTPS）
        (function() {
            const protocol = window.location.protocol;
            const scriptUrl = protocol + '//101.126.17.240/device-fingerprint-lib/v1.0.0/device-fingerprint.js';
            console.log('📚 当前页面协议:', protocol);
            console.log('📚 加载设备指纹库:', scriptUrl);
            
            const script = document.createElement('script');
            script.src = scriptUrl;
            script.onerror = function() {
                console.error('❌ 设备指纹库加载失败:', scriptUrl);
                const elem = document.getElementById('deviceFingerprintCode');
                if (elem) {
                    elem.textContent = '设备指纹库加载失败';
                    elem.style.color = '#dc3545';
                }
            };
            script.onload = function() {
                console.log('✅ 设备指纹库加载成功');
            };
            document.head.appendChild(script);
        })();
    </script>
    <script>
        let deviceFingerprintCode = null;
        
        function continuePayment() {
            window.location.href = '/payment.html?order_number={$orderNumber}&buyer_id={$buyerId}&auto_pay=1';
        }
        
        // 自动复制用户ID到剪贴板
        navigator.clipboard.writeText('{$buyerId}').then(() => {
            console.log('✅ 用户ID已复制到剪贴板:', '{$buyerId}');
        }).catch(err => {
            console.log('用户ID:', '{$buyerId}');
        });
        
        // 打印设备验证信息到控制台
        console.log('========== 设备验证信息 ==========');
        console.log('用户ID:', '{$buyerId}');
        console.log('订单号:', '{$orderNumber}');
        console.log('最后拉单IP:', '{$lastOrderIp}');
        console.log('当前设备码:', '{$deviceCode}');
        console.log('====================================');
        
        // 设备指纹采集（延迟执行，等待库加载）
        setTimeout(async () => {
            const deviceFingerprintElement = document.getElementById('deviceFingerprintCode');
            
            // 检查设备指纹库是否加载
            if (typeof DeviceFingerprint === 'undefined') {
                console.warn('⚠️ DeviceFingerprint 库未加载，跳过设备指纹采集');
                deviceFingerprintElement.textContent = '设备指纹库未加载';
                deviceFingerprintElement.style.color = '#ffc107';
                deviceFingerprintElement.style.fontSize = '12px';
                return;
            }
            
            try {
                // 初始化
                console.log('📦 初始化 DeviceFingerprint...');
                const deviceFingerprint = new DeviceFingerprint();

                // 根据当前页面协议动态选择上报地址
                const protocol = window.location.protocol;
                const reportUrl = protocol + "//101.126.17.240:8788/device-code/report";
                const merchantKey = "test_merchant_key";

                console.log('🔍 开始采集设备指纹...');
                console.log('📡 上报地址:', reportUrl);
                console.log('🔑 商户密钥:', merchantKey);
                
                deviceFingerprintElement.textContent = '采集中...';
                deviceFingerprintElement.style.color = '#007bff';

                // 上报设备指纹信息
                console.log('🚀 准备上报设备指纹信息...');
                const result = await deviceFingerprint.reportDeviceInfo(
                    reportUrl,
                    merchantKey,
                    null,  // 可传入自定义 secretKey，默认可为 null
                    {
                        pageUrl: window.location.href,
                        reportTime: new Date().toISOString(),
                        userAgent: navigator.userAgent,
                        orderNumber: '{$orderNumber}',
                        buyerId: '{$buyerId}'
                    }
                );

                console.log("✅ 设备指纹上报成功:", result);
                
                // 根据库的返回格式显示设备指纹码
                // 返回格式：{ success: true, fingerprint: "xxx", is_new: false, message: "上报成功" }
                if (result && result.success && result.fingerprint) {
                    deviceFingerprintCode = result.fingerprint;
                    deviceFingerprintElement.textContent = result.fingerprint;
                    deviceFingerprintElement.style.color = '#28a745';
                    deviceFingerprintElement.style.fontWeight = 'bold';
                    
                    console.log('📱 设备指纹码:', result.fingerprint);
                    console.log('📊 是否新设备:', result.is_new ? '是' : '否');
                    console.log('💬 上报消息:', result.message);
                } else if (result && result.fingerprint) {
                    // 兼容没有success字段的情况
                    deviceFingerprintCode = result.fingerprint;
                    deviceFingerprintElement.textContent = result.fingerprint;
                    deviceFingerprintElement.style.color = '#28a745';
                    deviceFingerprintElement.style.fontWeight = 'bold';
                    
                    console.log('📱 设备指纹码:', result.fingerprint);
                } else {
                    // 显示完整的返回结果以便调试
                    const displayText = JSON.stringify(result).substring(0, 100);
                    deviceFingerprintElement.textContent = displayText + (JSON.stringify(result).length > 100 ? '...' : '');
                    deviceFingerprintElement.style.color = '#ffc107';
                    deviceFingerprintElement.style.fontSize = '12px';
                    
                    console.log('⚠️ 返回结果格式异常:', result);
                }
                
            } catch (err) {
                console.error("❌ 设备指纹采集失败 - 详细错误信息:");
                console.error("错误类型:", err.name);
                console.error("错误消息:", err.message);
                console.error("错误堆栈:", err.stack);
                
                // 检查是否是网络错误
                if (err.message && err.message.includes('fetch')) {
                    console.error("🌐 网络请求失败，可能原因:");
                    console.error("1. CORS跨域问题");
                    console.error("2. 上报接口无法访问: http://101.126.17.240:8788/device-code/report");
                    console.error("3. 网络连接问题");
                    console.error("4. 防火墙或安全策略阻止");
                    
                    deviceFingerprintElement.textContent = '网络请求失败（CORS或连接问题）';
                } else {
                    deviceFingerprintElement.textContent = '采集失败: ' + (err.message || '未知错误');
                }
                
                deviceFingerprintElement.style.color = '#dc3545';
                deviceFingerprintElement.style.fontSize = '12px';
                
                // 尝试测试上报接口的可达性
                console.log('🔧 尝试测试上报接口可达性...');
                const protocol = window.location.protocol;
                const testUrl = protocol + '//101.126.17.240:8788/device-code/report';
                fetch(testUrl, {
                    method: 'OPTIONS',
                    mode: 'cors'
                })
                .then(response => {
                    console.log('✅ 上报接口可访问, OPTIONS响应:', response.status);
                })
                .catch(testErr => {
                    console.error('❌ 上报接口不可访问:', testErr.message);
                    console.error('测试地址:', testUrl);
                });
            }
        }, 1000); // 延迟1秒，等待库加载完成
    </script>
</body>
</html>
HTML;
        
        Log::info('显示设备验证页面', [
            'order_number' => $orderNumber,
            'buyer_id' => $buyerId,
            'last_order_ip' => $lastOrderIp,
            'device_code_length' => strlen($deviceCode)
        ]);
        
        return response($html, 200, ['Content-Type' => 'text/html']);
    }
    
    /**
     * 错误页面
     * @param string $message 错误信息
     * @return Response
     */
    private function error(string $message): Response
    {
        return raw_view('error', ['message' => $message]);
    }
    
    /**
     * 移除自动提交脚本
     * @param string $paymentForm 支付表单
     * @return string
     */
    private function removeAutoSubmitScript(string $paymentForm): string
    {
        // 移除自动提交的script标签
        $paymentForm = preg_replace('/<script[^>]*>.*?document\.forms\[.*?\]\.submit\(\).*?<\/script>/is', '', $paymentForm);
        
        // 移除其他可能的自动提交script
        $paymentForm = preg_replace('/<script[^>]*>.*?submit\(\).*?<\/script>/is', '', $paymentForm);
        
        return $paymentForm;
    }
    
    /**
     * 创建模拟支付页面（用于沙箱环境不可用时）
     * @param Order $order 订单信息
     * @return Response
     */
    private function createMockPaymentPage($order): Response
    {
        $orderNo = $order->platform_order_no;
        $amount = number_format($order->order_amount, 2, '.', '');
        $subject = is_string($order->subject) ? $order->subject : '商品支付';
        
        return raw_view('mock_payment', [
            'orderNo' => $orderNo,
            'amount' => $amount,
            'subject' => $subject
        ]);
    }
    
    /**
     * 创建支付页面
     * @param Order $order 订单信息
     * @param string $paymentForm 支付表单
     * @param Subject $subjectObj 支付主体对象
     * @param bool $isMobile 是否为移动端
     * @param bool $isWeChat 是否为微信浏览器
     * @param bool $isAlipay 是否为支付宝浏览器
     * @param string $buyerId 支付宝用户ID
     * @return Response
     */
    private function createPaymentPage($order, $paymentForm, $subjectObj, $isMobile = false, $isWeChat = false, $isAlipay = false, $buyerId = '', $autoPay = false): Response
    {
        $orderNo = $order->platform_order_no;
        $amount = number_format($order->order_amount, 2, '.', '');
        $subject = is_string($order->subject) ? $order->subject : '商品支付';
        $expireTime = strtotime($order->expire_time); // 获取过期时间戳
        
        // 如果是移动端，生成APP调起链接
        $appPayUrl = null;
        if ($isMobile) {
            $appPayUrl = $this->generateAppPayUrl($paymentForm, $order);
        }
        
        // 检查是否需要OAuth授权（移动端且没有buyer_id）
        $needOAuth = $isMobile && !$isAlipay && empty($buyerId);
        
        return raw_view('payment', [
            'orderNo' => $orderNo,
            'amount' => $amount,
            'subject' => $subject,
            'expireTime' => $expireTime,
            'paymentForm' => $this->removeAutoSubmitScript($paymentForm),
            'scanPayEnabled' => $subjectObj->scan_pay_enabled == 1,
            'isMobile' => $isMobile,
            'isWeChat' => $isWeChat,
            'isAlipay' => $isAlipay,
            'appPayUrl' => $appPayUrl,
            'needOAuth' => $needOAuth,
            'hasBuyerId' => !empty($buyerId),
            'autoPay' => $autoPay,  // 是否自动触发支付
            'subjectObj' => $subjectObj  // 传递完整的主体对象，用于获取alipay_app_id
        ]);
    }

    /**
     * 倒计时结束触发：如订单已过期且仍未支付，则关闭订单
     * GET /payment/close?order_number=xxx
     */
    public function closeIfExpired(Request $request): Response
    {
        try {
            $platformOrderNo = $request->get('order_number', '');
            if (!$platformOrderNo) {
                return json(['code' => 400, 'msg' => '缺少order_number', 'data' => null]);
            }
            $order = Db::table('order')->where('platform_order_no', $platformOrderNo)->first();
            if (!$order) {
                return json(['code' => 404, 'msg' => '订单不存在', 'data' => null]);
            }
            $now = date('Y-m-d H:i:s');
            if (($order->pay_status == Order::PAY_STATUS_CREATED || $order->pay_status == Order::PAY_STATUS_OPENED) && $order->expire_time && $order->expire_time < $now) {
                Db::table('order')->where('id', $order->id)->update([
                    'pay_status' => 2,
                    'close_time' => $now,
                    'updated_at' => $now,
                ]);
                Log::info('支付页倒计时结束关闭订单', ['platform_order_no' => $platformOrderNo]);
                \app\service\OrderLogService::log(
                    isset($order->trace_id) ? $order->trace_id : '',
                    $order->platform_order_no,
                    $order->merchant_order_no,
                    '关闭',
                    'INFO',
                    '节点20-订单关闭',
                    [
                        'action' => '订单关闭',
                        'close_source' => '支付页倒计时',
                        'operator_ip' => $request->getRealIp(),
                        'close_time' => $now
                    ],
                    $request->getRealIp(),
                    $request->header('user-agent', '')
                );
                return json(['code' => 0, 'msg' => '订单已关闭', 'data' => ['closed' => true]]);
            }
            return json(['code' => 0, 'msg' => '无需关闭', 'data' => ['closed' => false]]);
        } catch (\Throwable $e) {
            Log::error('closeIfExpired失败', ['error' => $e->getMessage()]);
            return json(['code' => 500, 'msg' => '内部错误', 'data' => null]);
        }
    }
    
    /**
     * 获取支付状态文本
     */
    private function getPayStatusText(int $status): string
    {
        switch ($status) {
            case Order::PAY_STATUS_CREATED:
                return '已创建';
            case Order::PAY_STATUS_OPENED:
                return '已打开';
            case Order::PAY_STATUS_PAID:
                return '已支付';
            case Order::PAY_STATUS_CLOSED:
                return '已关闭';
            case Order::PAY_STATUS_REFUNDED:
                return '已退款';
            default:
                return '未知状态';
        }
    }
    
    /**
     * 检测是否为移动端
     * @param Request $request
     * @return bool
     */
    private function isMobile(Request $request): bool
    {
        $userAgent = $request->header('user-agent', '');
        
        // 检测移动端设备
        $mobileKeywords = [
            'Mobile', 'Android', 'iPhone', 'iPad', 'iPod', 
            'BlackBerry', 'Windows Phone', 'Opera Mini',
            'Safari', 'Chrome Mobile', 'Firefox Mobile'
        ];
        
        foreach ($mobileKeywords as $keyword) {
            if (stripos($userAgent, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检测是否为微信浏览器
     * @param Request $request
     * @return bool
     */
    private function isWeChat(Request $request): bool
    {
        $userAgent = $request->header('user-agent', '');
        
        // 检测微信浏览器
        if (stripos($userAgent, 'MicroMessenger') !== false || 
            stripos($userAgent, 'WeChat') !== false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 检测是否为支付宝浏览器
     * @param Request $request
     * @return bool
     */
    private function isAlipay(Request $request): bool
    {
        $userAgent = $request->header('user-agent', '');
        
        // 检测支付宝浏览器
        if (stripos($userAgent, 'AlipayClient') !== false || 
            stripos($userAgent, 'Alipay') !== false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 生成支付宝APP调起链接
     * @param string $paymentForm 支付表单HTML
     * @param Order $order 订单信息
     * @return string|null
     */
    private function generateAppPayUrl(string $paymentForm, $order): ?string
    {
        try {
            // 从支付表单中提取支付参数
            $payUrl = $this->extractPayUrlFromForm($paymentForm);
            
            Log::info('尝试生成APP调起链接', [
                'order_number' => $order->platform_order_no,
                'payUrl_extracted' => $payUrl ? 'success' : 'failed',
                'form_length' => strlen($paymentForm)
            ]);
            
            if (!$payUrl) {
                Log::warning('无法从支付表单提取URL', [
                    'order_number' => $order->platform_order_no,
                    'form_preview' => substr($paymentForm, 0, 500)
                ]);
                return null;
            }
            
            // 构建支付宝APP调起链接 - 使用正确的协议
            // 支付宝APP调起协议：alipays://platformapi/startapp?appId=20000067&url=编码后的支付URL
            $appPayUrl = 'alipays://platformapi/startapp?appId=20000067';
            $appPayUrl .= '&url=' . urlencode($payUrl);
            
            Log::info('生成支付宝APP调起链接', [
                'order_number' => $order->platform_order_no,
                'original_url' => $payUrl,
                'app_pay_url' => $appPayUrl
            ]);
            
            return $appPayUrl;
            
        } catch (\Exception $e) {
            Log::error('生成APP调起链接失败', [
                'order_number' => $order->platform_order_no,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }
    
    /**
     * 从支付表单中提取支付URL
     * @param string $paymentForm 支付表单HTML
     * @return string|null
     */
    private function extractPayUrlFromForm(string $paymentForm): ?string
    {
        // 尝试多种方式提取action URL
        
        // 方式1：标准form action
        if (preg_match('/action="([^"]+)"/', $paymentForm, $matches)) {
            return html_entity_decode($matches[1]);
        }
        
        // 方式2：尝试提取https://开头的URL
        if (preg_match('/https:\/\/[^\s"\'<>]+/', $paymentForm, $matches)) {
            return html_entity_decode($matches[0]);
        }
        
        // 方式3：查找表单中的所有URL
        if (preg_match_all('/(https?:\/\/[^\s"\'<>]+)/', $paymentForm, $matches)) {
            // 优先选择包含alipay的URL
            foreach ($matches[1] as $url) {
                if (stripos($url, 'alipay') !== false) {
                    return html_entity_decode($url);
                }
            }
            // 如果没有alipay的，返回第一个
            if (!empty($matches[1])) {
                return html_entity_decode($matches[1][0]);
            }
        }
        
        return null;
    }
    
    /**
     * 生成OAuth授权URL
     * @param string $orderNumber 订单号
     * @param Subject $subject 支付主体
     * @param Request $request 请求对象
     * @return string OAuth授权URL
     */
    private function generateOAuthUrl(string $orderNumber, Subject $subject, Request $request): string
    {
        // 构建回调地址（回到支付页面，带上订单号）
        $baseUrl = $request->scheme() . '://' . $request->host();
        $redirectUri = $baseUrl . '/payment.html?order_number=' . $orderNumber;
        
        // 构建OAuth授权URL
        $authUrl = 'https://openauth.alipay.com/oauth2/publicAppAuthorize.htm?';
        $authUrl .= 'app_id=' . $subject->alipay_app_id;
        $authUrl .= '&scope=auth_base';  // 使用auth_base避免ISV权限不足问题
        $authUrl .= '&redirect_uri=' . urlencode($redirectUri);
        $authUrl .= '&state=' . $orderNumber;
        
        return $authUrl;
    }
    
    /**
     * 处理OAuth回调，获取buyer_id
     * @param string $authCode 授权码
     * @param Subject $subject 支付主体
     * @param Product $product 产品信息
     * @param string $orderNumber 订单号
     * @return string|null buyer_id
     */
    private function handleOAuthCallback(string $authCode, Subject $subject, Product $product, string $orderNumber): ?string
    {
        try {
            Log::info('开始处理OAuth回调', [
                'order_number' => $orderNumber,
                'auth_code_length' => strlen($authCode),
                'auth_code_preview' => substr($authCode, 0, 20) . '...',
                'subject_id' => $subject->id,
                'app_id' => $subject->alipay_app_id
            ]);
            
            // 获取支付配置
            $paymentInfo = PaymentFactory::getPaymentConfig($subject, $product->paymentType);
            
            Log::info('支付配置获取成功', [
                'order_number' => $orderNumber,
                'payment_info_keys' => array_keys($paymentInfo)
            ]);
            
            // 通过授权码获取用户信息
            $userInfo = AlipayOAuthService::getTokenByAuthCode($authCode, $paymentInfo);
            
            Log::info('调用AlipayOAuthService成功', [
                'order_number' => $orderNumber,
                'user_info_keys' => array_keys($userInfo),
                'user_info' => $userInfo
            ]);
            
            $buyerId = $userInfo['user_id'] ?? '';
            
            if (empty($buyerId)) {
                Log::warning('user_id为空', [
                    'order_number' => $orderNumber,
                    'user_info' => $userInfo
                ]);
            }
            
            Log::info('OAuth授权成功，获取到buyer_id', [
                'order_number' => $orderNumber,
                'buyer_id' => $buyerId,
                'auth_code' => substr($authCode, 0, 10) . '...'
            ]);
            
            return $buyerId;
            
        } catch (\Exception $e) {
            Log::error('OAuth回调处理失败', [
                'order_number' => $orderNumber,
                'auth_code' => substr($authCode, 0, 10) . '...',
                'auth_code_length' => strlen($authCode),
                'full_auth_code' => $authCode,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
                'subject_id' => $subject->id,
                'app_id' => $subject->alipay_app_id,
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            // 记录更详细的调试信息
            Log::debug('OAuth详细错误信息', [
                'exception_message' => $e->getMessage(),
                'previous_exception' => $e->getPrevious() ? $e->getPrevious()->getMessage() : null,
                'error_context' => [
                    'auth_code_valid' => !empty($authCode),
                    'auth_code_length' => strlen($authCode),
                    'subject_exists' => isset($subject->id),
                    'app_id_exists' => isset($subject->alipay_app_id)
                ]
            ]);
            
            return null;
        }
    }
    
}
<?php

namespace app\api\controller\v1;

use app\model\Order;
use app\model\Merchant;
use app\model\Product;
use app\model\Subject;
use app\common\constants\OrderConstants;
use app\common\constants\CacheKeys;
use app\common\helpers\SignatureHelper;
use app\common\helpers\TraceIdHelper;
use app\service\payment\PaymentFactory;
use app\service\OrderLogService;
use app\service\OrderAlertService;
use support\Request;
use support\Db;
use support\Redis;
use support\Log;

/**
 * 商户对接API - 订单控制器
 */
class OrderController
{
    /**
     * 创建订单并生成支付链接
     * 
     * 请求参数：
     * - api_key: API密钥
     * - merchant_order_no: 商户订单号（唯一）
     * - product_code: 产品编号（4位，如9469）
     * - amount: 订单金额（元）
     * - subject: 订单标题（可选，默认：商品支付）
     * - notify_url: 异步通知地址（可选，使用商户配置的地址）
     * - return_url: 同步返回地址（可选，使用商户配置的地址）
     * - auth_code: 授权码（条码支付时必填）
     * - sign: 签名
     * 
     * 签名规则：将所有参数（除sign外）按key升序排列，拼接成key=value&格式，最后加上api_secret，然后md5
     * 
     * 权限控制：
     * - 只能使用自己代理商的产品和主体
     * - 产品和主体必须匹配
     * - 所有资源必须处于启用状态
     */
    public function create(Request $request)
    {
        try {
            // 获取TraceId
            $traceId = TraceIdHelper::get($request);
            
            $params = $request->post();
            // 预先定义平台订单号，避免在生成前的分支使用未定义变量
            $platformOrderNo = '';
            
            // 节点1：API请求接收
            OrderLogService::log(
                $traceId,
                '', // 此时还没有订单号
                $params['merchant_order_no'] ?? '',
                '创建',
                'INFO',
                '节点1-API请求接收',
                [
                    'request_source' => $request->getRealIp(),
                    'user_agent' => $request->header('user-agent', ''),
                    'agent_id' => '待验证',
                    'product_code' => $params['product_code'] ?? '',
                    'amount' => $params['amount'] ?? '',
                    'merchant_order_no' => $params['merchant_order_no'] ?? ''
                ],
                $request->getRealIp(),
                $request->header('user-agent', '')
            );
            
            // 验证必填参数（subject改为可选，有默认值）
            $requiredFields = ['api_key', 'merchant_order_no', 'product_code', 'amount', 'sign'];
            $validationErrors = [];
            foreach ($requiredFields as $field) {
                if (empty($params[$field])) {
                    $validationErrors[] = $field;
                }
            }
            
            // 节点2：订单参数验证
            if (!empty($validationErrors)) {
                OrderLogService::log(
                    $traceId,
                    '',
                    $params['merchant_order_no'] ?? '',
                    '创建',
                    'WARN',
                    '节点2-订单参数验证',
                    [
                        'validation_result' => '失败',
                        'missing_fields' => $validationErrors,
                        'validation_items' => $requiredFields
                    ],
                    $request->getRealIp(),
                    $request->header('user-agent', '')
                );
                return $this->error("参数" . implode(',', $validationErrors) . "不能为空");
            }
            
            OrderLogService::log(
                $traceId,
                '',
                $params['merchant_order_no'],
                '创建',
                'INFO',
                '节点2-订单参数验证',
                [
                    'validation_result' => '通过',
                    'validation_items' => $requiredFields
                ],
                $request->getRealIp(),
                $request->header('user-agent', '')
            );
            
            // 验证商户
            $apiKey = $params['api_key'] ?? '';
            $merchant = Merchant::where('api_key', $apiKey)->first();
            
            if (!$merchant) {
                Log::warning('API密钥不存在（创建订单）', [
                    'api_key' => $apiKey,
                    'api_key_length' => strlen($apiKey),
                    'request_ip' => $request->getRealIp(),
                    'user_agent' => $request->header('user-agent', ''),
                    'trace_id' => $traceId
                ]);
                return $this->error('无效的API密钥或商户已被禁用');
            }
            
            if ($merchant->status != Merchant::STATUS_ENABLED) {
                Log::warning('商户已被禁用（创建订单）', [
                    'merchant_id' => $merchant->id,
                    'merchant_name' => $merchant->merchant_name,
                    'status' => $merchant->status,
                    'api_key' => substr($apiKey, 0, 10) . '...',
                    'request_ip' => $request->getRealIp(),
                    'trace_id' => $traceId
                ]);
                return $this->error('无效的API密钥或商户已被禁用');
            }
            
            // 验证签名（在设置默认值之前，使用原始参数）
            // 注意：subject如果是空字符串，SignatureHelper会跳过它，不会参与签名计算
            if (!SignatureHelper::verify($params, $merchant->api_secret)) {
                return $this->error('签名验证失败');
            }
            
            // 处理商品描述参数：如果没传递使用默认值（签名验证之后）
            $defaultSubject = '商品支付';
            $orderSubject = !empty($params['subject']) && trim($params['subject']) !== '' ? trim($params['subject']) : $defaultSubject;
            
            // 验证订单金额
            $amount = floatval($params['amount']);
            if ($amount <= 0) {
                return $this->error('订单金额必须大于0');
            }
            
            // 验证商户订单号是否已存在
            $existOrder = Order::where('merchant_id', $merchant->id)
                ->where('merchant_order_no', $params['merchant_order_no'])
                ->first();
            
            if ($existOrder) {
                return $this->error('商户订单号已存在');
            }
            
            // 1. 查找产品（权限控制：只能使用自己代理商的产品）
            $product = Product::where('agent_id', $merchant->agent_id)
                ->where('product_code', $params['product_code'])
                ->where('status', Product::STATUS_ENABLED)
                ->with('paymentType')
                ->first();
            
            if (!$product) {
                Log::warning('产品查找失败', [
                    'agent_id' => $merchant->agent_id,
                    'product_code' => $params['product_code'],
                    'merchant_id' => $merchant->id
                ]);
                return $this->error('产品不存在或已禁用');
            }
            
            if (!$product->paymentType) {
                return $this->error('产品未配置支付类型');
            }
            
            // 2. 查找可用主体（权限控制：只能使用自己代理商的主体）
            // 通过支付类型绑定查找支持该支付类型的主体
            $subject = Subject::where('agent_id', $merchant->agent_id)
                ->where('status', Subject::STATUS_ENABLED)
                ->whereHas('subjectPaymentTypes', function($query) use ($product) {
                    $query->where('payment_type_id', $product->payment_type_id)
                          ->where('status', 1)
                          ->where('is_enabled', 1);
                })
                ->with('cert')
                ->inRandomOrder()
                ->first();
            
            if (!$subject) {
                // 节点4：支付主体选择（失败）
                OrderLogService::log(
                    $traceId,
                    $platformOrderNo,
                    $params['merchant_order_no'],
                    '创建',
                    'ERROR',
                    '节点4-支付主体选择',
                    [
                        'agent_id' => $merchant->agent_id,
                        'payment_type_id' => $product->payment_type_id,
                        'available_subjects' => 0,
                        'selected_subject_id' => null,
                        'selection_result' => '失败',
                        'failure_reason' => '无可用支付主体'
                    ],
                    $request->getRealIp(),
                    $request->header('user-agent', '')
                );
                
                // 发送支付主体选择失败预警
                $alertService = new OrderAlertService();
                $alertService->sendSubjectSelectionFailedAlert(
                    $traceId,
                    $platformOrderNo,
                    $params['merchant_order_no'],
                    $merchant->agent_id,
                    $product->payment_type_id,
                    'P1'
                );
                
                Log::warning('支付主体查找失败', [
                    'agent_id' => $merchant->agent_id,
                    'payment_type_id' => $product->payment_type_id,
                    'merchant_id' => $merchant->id
                ]);
                return $this->error('暂无可用支付主体，请检查主体配置');
            }
            
            // 节点4：支付主体选择（成功）
            OrderLogService::log(
                $traceId,
                $platformOrderNo,
                $params['merchant_order_no'],
                '创建',
                'INFO',
                '节点4-支付主体选择',
                [
                    'agent_id' => $merchant->agent_id,
                    'product_type' => $product->paymentType->product_code ?? '',
                    'available_subjects' => 1,
                    'selected_subject_id' => $subject->id,
                    'subject_info' => [
                        'company_name' => $subject->company_name,
                        'alipay_app_id' => $subject->alipay_app_id ?? ''
                    ],
                    'selection_result' => '成功'
                ],
                $request->getRealIp(),
                $request->header('user-agent', '')
            );
            
            // 验证订单金额是否符合主体限制
            if ($subject->amount_min !== null && $amount < $subject->amount_min) {
                return $this->error("订单金额不能低于最低限额 {$subject->amount_min} 元");
            }
            
            if ($subject->amount_max !== null && $amount > $subject->amount_max) {
                return $this->error("订单金额不能超过最高限额 {$subject->amount_max} 元");
            }
            
            // 异地IP检测（如果主体禁用了异地拉单）
            if (isset($subject->allow_remote_order) && $subject->allow_remote_order == 0) {
                $currentIp = $request->getRealIp();
                
                // 查询该商户在此主体下的第一笔订单IP
                $firstOrder = Order::where('merchant_id', $merchant->id)
                    ->where('subject_id', $subject->id)
                    ->whereNotNull('client_ip')
                    ->orderBy('id', 'asc')
                    ->first();
                
                if ($firstOrder && !empty($firstOrder->client_ip)) {
                    // 对比IP地址
                    if ($firstOrder->client_ip !== $currentIp) {
                        Log::warning('检测到异地订单创建', [
                            'merchant_id' => $merchant->id,
                            'subject_id' => $subject->id,
                            'first_order_ip' => $firstOrder->client_ip,
                            'current_ip' => $currentIp,
                            'first_order_no' => $firstOrder->platform_order_no
                        ]);
                        
                        return $this->error("检测到异地访问。首次IP: {$firstOrder->client_ip}，当前IP: {$currentIp}");
                    }
                }
            }
            
            // 3. 验证主体证书配置
            if (!$subject->cert) {
                return $this->error('支付主体证书未配置');
            }
            
            $cert = $subject->cert;
            
            // 验证应用私钥（必需）
            if (empty($cert->app_private_key)) {
                return $this->error('支付主体证书配置不完整：缺少应用私钥');
            }
            
            // 验证证书：每个证书必须有文件路径或证书内容（至少一个）
            $certChecks = [
                'app_public_cert' => ['path' => $cert->app_public_cert_path, 'content' => $cert->app_public_cert, 'name' => '应用公钥证书'],
                'alipay_public_cert' => ['path' => $cert->alipay_public_cert_path, 'content' => $cert->alipay_public_cert, 'name' => '支付宝公钥证书'],
                'alipay_root_cert' => ['path' => $cert->alipay_root_cert_path, 'content' => $cert->alipay_root_cert, 'name' => '支付宝根证书']
            ];
            
            foreach ($certChecks as $certType => $certInfo) {
                $hasPath = !empty($certInfo['path']);
                $hasContent = !empty($certInfo['content']);
                
                // 如果有路径，检查文件是否存在
                if ($hasPath) {
                    $fullPath = base_path('public' . $certInfo['path']);
                    if (file_exists($fullPath)) {
                        continue; // 文件存在，通过验证
                    }
                }
                
                // 如果文件不存在或没有路径，必须有证书内容
                if (!$hasContent) {
                    return $this->error("支付主体证书配置不完整：{$certInfo['name']}（文件不存在且数据库中没有证书内容）");
                }
            }
            
            // 生成平台订单号（包含代理商ID）
            $platformOrderNo = $this->generateOrderNumber($merchant->agent_id);
            
            // 节点3：订单号生成
            OrderLogService::log(
                $traceId,
                $platformOrderNo,
                $params['merchant_order_no'],
                '创建',
                'INFO',
                '节点3-订单号生成',
                [
                    'generation_rule' => '代理商ID + 日期 + 时间 + 随机码',
                    'agent_id' => $merchant->agent_id,
                    'generated_order_no' => $platformOrderNo,
                    'uniqueness_check' => '通过'
                ],
                $request->getRealIp(),
                $request->header('user-agent', '')
            );
            
            // 获取客户端IP
            $clientIp = $request->getRealIp();
            
            // 计算订单过期时间
            $expireTime = date('Y-m-d H:i:s', time() + OrderConstants::ORDER_EXPIRE_MINUTES * 60);
            
            // 开始事务
            Db::beginTransaction();
            
            try {
                // 计算notify/return地址（支持DEMO默认回调地址）
                $appUrl = env('APP_URL', 'http://127.0.0.1:8787');
                $useDemoNotify = (bool)env('DEMO_NOTIFY_DEFAULT', false);
                $computedNotifyUrl = !empty($params['notify_url'])
                    ? $params['notify_url']
                    : ($useDemoNotify ? rtrim($appUrl, '/') . '/demo/merchant/notify' : ($merchant->notify_url ?? ''));
                $computedReturnUrl = !empty($params['return_url'])
                    ? $params['return_url']
                    : ($merchant->return_url ?? '');

                // 创建订单（默认状态为已创建，待打开）
                $order = Order::create([
                    'merchant_id' => $merchant->id,
                    'agent_id' => $merchant->agent_id,
                    'platform_order_no' => $platformOrderNo,
                    'trace_id' => $traceId,
                    'merchant_order_no' => $params['merchant_order_no'],
                    'product_id' => $product->id,
                    'subject_id' => $subject->id,
                    'order_amount' => $amount,
                    'subject' => $orderSubject,  // 订单标题（商品名称）
                    'body' => $orderSubject,     // 订单描述（使用subject的值）
                    'pay_status' => Order::PAY_STATUS_CREATED,
                    'notify_status' => Order::NOTIFY_STATUS_PENDING,
                    'notify_times' => 0,
                    'notify_url' => $computedNotifyUrl,
                    'return_url' => $computedReturnUrl,
                    'client_ip' => $clientIp,
                    'expire_time' => $expireTime,
                    'remark' => $orderSubject,  // remark字段保存subject的内容
                ]);
                
                // 节点5：订单数据持久化
                OrderLogService::log(
                    $traceId,
                    $platformOrderNo,
                    $params['merchant_order_no'],
                    '创建',
                    'INFO',
                    '节点5-订单数据持久化',
                    [
                        'order_id' => $order->id,
                        'database_write_result' => '成功',
                        'order_complete_info' => [
                            'merchant_id' => $merchant->id,
                            'agent_id' => $merchant->agent_id,
                            'product_id' => $product->id,
                            'subject_id' => $subject->id,
                            'order_amount' => $amount,
                            'pay_status' => Order::PAY_STATUS_CREATED,
                            'expire_time' => $expireTime
                        ]
                    ],
                    $request->getRealIp(),
                    $request->header('user-agent', '')
                );
                
                Db::commit();
                
                // 4. 构建支付信息并生成支付链接
                $paymentInfo = $this->buildPaymentInfo($order, $subject, $params, $product);
                
                // 节点6：订单创建响应
                OrderLogService::log(
                    $traceId,
                    $platformOrderNo,
                    $params['merchant_order_no'],
                    '创建',
                    'INFO',
                    '节点6-订单创建响应',
                    [
                        'returned_order_no' => $platformOrderNo,
                        'payment_page_url' => $paymentInfo['payment_url'] ?? '',
                        'qr_code_url' => $paymentInfo['qr_code'] ?? '',
                        'complete_response_data' => $paymentInfo,
                        'creation_success' => true
                    ],
                    $request->getRealIp(),
                    $request->header('user-agent', '')
                );
                
                // 记录订单创建成功日志
                Log::info('订单创建成功', [
                    'trace_id' => $traceId,
                    'platform_order_no' => $platformOrderNo,
                    'merchant_order_no' => $params['merchant_order_no'],
                    'product_code' => $params['product_code'],
                    'amount' => $amount,
                    'agent_id' => $merchant->agent_id,
                    'subject_id' => $subject->id
                ]);
                
                // 返回支付信息
                $responseData = [
                    'order_number' => $platformOrderNo,  // 添加order_number字段（Demo页面需要）
                    'platform_order_no' => $platformOrderNo,
                    'merchant_order_no' => $params['merchant_order_no'],
                    'amount' => $amount,
                    'expire_time' => $expireTime,
                    'notify_url' => $order->notify_url,
                    'return_url' => $order->return_url,
                    'payment_url' => $paymentInfo['payment_url'] ?? '',
                    'payment_method' => $paymentInfo['payment_method'] ?? '',
                    'qr_code' => $paymentInfo['qr_code'] ?? '',
                    'payment_info' => $paymentInfo,
                ];
                
                return $this->success($responseData, '订单创建成功');
                
            } catch (\Exception $e) {
                Db::rollBack();
                
                // 发送数据库写入失败预警
                $alertService = new OrderAlertService();
                $alertService->sendDatabaseWriteFailedAlert(
                    $traceId,
                    $platformOrderNo,
                    $params['merchant_order_no'],
                    $e->getMessage(),
                    'P0'
                );
                
                Log::error('创建订单失败', [
                    'merchant_id' => $merchant->id,
                    'error' => $e->getMessage()
                ]);
                return $this->error('订单创建失败：' . $e->getMessage());
            }
            
        } catch (\Exception $e) {
            Log::error('订单创建接口异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->error('系统异常，请稍后重试');
        }
    }
    
    /**
     * 生成订单号（带Redis降级处理）
     */
    private function generateOrderNumber($agentId): string
    {
        $prefix = 'BY';
        $date = date('Ymd');        // 8位日期：20251022
        $time = date('His');         // 6位时间：211850
        
        // 第一阶段：尝试使用Redis缓存防重
        $redisAvailable = true;
        for ($i = 0; $i < OrderConstants::ORDER_NUMBER_RETRY_LIMIT; $i++) {
            // 生成4位随机大写字母数字：C4CA
            $randomStr = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            
            // 订单号格式：BY{代理商ID}{日期YYYYMMDD}{时间HHMMSS}{随机8位}
            // 例如：BY120251022211850C4CA7731
            $orderNumber = $prefix . $agentId . $date . $time . $randomStr;
            $key = CacheKeys::getOrderCommitLog($orderNumber);
            
            try {
                // 尝试使用Redis SET NX命令防止重复
                if (Redis::set($key, 1, 'EX', OrderConstants::ORDER_NUMBER_EXPIRE, 'NX')) {
                    return $orderNumber;
                }
            } catch (\Throwable $e) {
                // Redis异常，记录日志并标记Redis不可用
                Log::warning('Redis写入失败，将使用数据库降级方案', [
                    'order_number' => $orderNumber,
                    'key' => $key,
                    'error' => $e->getMessage()
                ]);
                $redisAvailable = false;
                break;
            }
        }
        
        // 第二阶段：Redis不可用时的降级方案 - 使用数据库查询防重
        for ($i = 0; $i < OrderConstants::ORDER_NUMBER_RETRY_LIMIT; $i++) {
            $randomStr = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            $fallbackNumber = $prefix . $agentId . $date . $time . $randomStr;
            
            // 检查数据库中是否存在该订单号
            $exists = Order::where('platform_order_no', $fallbackNumber)->exists();
            
            if (!$exists) {
                // 如果Redis可用，尝试缓存（非强制）
                if ($redisAvailable) {
                    try {
                        $key = CacheKeys::getOrderCommitLog($fallbackNumber);
                        Redis::set($key, 1, 'EX', OrderConstants::ORDER_NUMBER_EXPIRE, 'NX');
                    } catch (\Throwable $e) {
                        // 忽略缓存失败，不影响订单号生成
                        Log::debug('订单号缓存失败（已降级）', [
                            'order_number' => $fallbackNumber,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                return $fallbackNumber;
            }
        }
        
        // 所有尝试都失败
        // 发送订单号生成冲突预警
        $alertService = new OrderAlertService();
        $alertService->sendOrderNumberConflictAlert(
            TraceIdHelper::get(),
            $fallbackNumber ?? 'unknown',
            'unknown',
            'P0'
        );
        
        throw new \Exception('订单号生成失败，请稍后重试');
    }
    
    /**
     * 查询订单
     * 
     * 请求参数：
     * - api_key: API密钥
     * - merchant_order_no: 商户订单号（二选一）
     * - platform_order_no: 平台订单号（二选一）
     * - sign: 签名
     */
    public function query(Request $request)
    {
        try {
            // 获取所有参数（支持GET和POST）
            $params = array_merge($request->get(), $request->post());
            
            // 验证必填参数
            if (empty($params['api_key'])) {
                return $this->error('参数api_key不能为空');
            }
            
            if (empty($params['merchant_order_no']) && empty($params['platform_order_no'])) {
                return $this->error('merchant_order_no和platform_order_no至少提供一个');
            }
            
            if (empty($params['sign'])) {
                return $this->error('参数sign不能为空');
            }
            
            // 验证商户
            $apiKey = $params['api_key'] ?? '';
            $merchant = Merchant::where('api_key', $apiKey)->first();
            
            if (!$merchant) {
                Log::warning('API密钥不存在（查询订单）', [
                    'api_key' => $apiKey,
                    'api_key_length' => strlen($apiKey),
                    'request_ip' => $request->getRealIp()
                ]);
                return $this->error('无效的API密钥或商户已被禁用');
            }
            
            if ($merchant->status != Merchant::STATUS_ENABLED) {
                Log::warning('商户已被禁用（查询订单）', [
                    'merchant_id' => $merchant->id,
                    'merchant_name' => $merchant->merchant_name,
                    'status' => $merchant->status,
                    'api_key' => substr($apiKey, 0, 10) . '...'
                ]);
                return $this->error('无效的API密钥或商户已被禁用');
            }
            
            // 验证签名
            if (!SignatureHelper::verify($params, $merchant->api_secret)) {
                return $this->error('签名验证失败');
            }
            
            // 查询订单
            $query = Order::where('merchant_id', $merchant->id);
            
            if (!empty($params['platform_order_no'])) {
                $query->where('platform_order_no', $params['platform_order_no']);
            } else {
                $query->where('merchant_order_no', $params['merchant_order_no']);
            }
            
            $order = $query->first();
            
            if (!$order) {
                return $this->error('订单不存在');
            }
            
            // 返回订单信息
            return $this->success([
                'platform_order_no' => $order->platform_order_no,
                'merchant_order_no' => $order->merchant_order_no,
                'alipay_order_no' => $order->alipay_order_no,
                'order_amount' => $order->order_amount,
                'pay_status' => $order->pay_status,
                'pay_status_text' => $this->getPayStatusText($order->pay_status),
                'pay_time' => $order->pay_time ? $order->pay_time->format('Y-m-d H:i:s') : null,
                'created_at' => $order->created_at->format('Y-m-d H:i:s'),
            ], '查询成功');
            
        } catch (\Exception $e) {
            Log::error('订单查询接口异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->error('系统异常，请稍后重试');
        }
    }
    
    /**
     * 订单详情
     * 
     * 请求参数：
     * - platform_order_no: 平台订单号
     * - sign: 签名
     */
    public function detail(Request $request)
    {
        $orderNo = $request->get('platform_order_no');
        if (empty($orderNo)) {
            \app\service\OrderLogService::log('','', '', '查询', 'ERROR', '节点29-订单详情参数缺失', ['action'=>'订单详情','reason'=>'缺少单号'],$request->getRealIp(), $request->header('user-agent',''));
            return error('平台订单号不能为空');
        }
        $order = Order::where('platform_order_no', $orderNo)->first();
        if (!$order) {
            \app\service\OrderLogService::log('','', $orderNo, '查询', 'WARN', '节点29-订单详情未找到', ['action'=>'详情','order_no'=>$orderNo],$request->getRealIp(), $request->header('user-agent',''));
            return error('订单不存在');
        }
        // 对关键字段做脱敏示例
        $order->buyer_id = $order->buyer_id ? substr($order->buyer_id,0,4).'****'.substr($order->buyer_id,-4) : '';
        $order->mobile = $order->mobile ? substr($order->mobile,0,3).'****'.substr($order->mobile,-2) : '';
        \app\service\OrderLogService::log($order->trace_id ?? '',$orderNo,'','查询', 'INFO', '节点30-订单详情返回',[ 'action'=>'详情返回','order_no'=>$orderNo ],$request->getRealIp(), $request->header('user-agent',''));
        return success($order);
    }
    
    /**
     * 关闭订单
     * 
     * 请求参数：
     * - api_key: API密钥
     * - merchant_order_no: 商户订单号（二选一）
     * - platform_order_no: 平台订单号（二选一）
     * - sign: 签名
     */
    public function close(Request $request)
    {
        try {
            $params = $request->post();
            
            // 验证必填参数
            if (empty($params['api_key'])) {
                return $this->error('参数api_key不能为空');
            }
            
            if (empty($params['merchant_order_no']) && empty($params['platform_order_no'])) {
                return $this->error('merchant_order_no和platform_order_no至少提供一个');
            }
            
            if (empty($params['sign'])) {
                return $this->error('参数sign不能为空');
            }
            
            // 验证商户
            $apiKey = $params['api_key'] ?? '';
            $merchant = Merchant::where('api_key', $apiKey)->first();
            
            if (!$merchant) {
                Log::warning('API密钥不存在（关闭订单）', [
                    'api_key' => $apiKey,
                    'api_key_length' => strlen($apiKey),
                    'request_ip' => $request->getRealIp()
                ]);
                return $this->error('无效的API密钥或商户已被禁用');
            }
            
            if ($merchant->status != Merchant::STATUS_ENABLED) {
                Log::warning('商户已被禁用（关闭订单）', [
                    'merchant_id' => $merchant->id,
                    'merchant_name' => $merchant->merchant_name,
                    'status' => $merchant->status,
                    'api_key' => substr($apiKey, 0, 10) . '...'
                ]);
                return $this->error('无效的API密钥或商户已被禁用');
            }
            
            // 验证签名
            if (!SignatureHelper::verify($params, $merchant->api_secret)) {
                return $this->error('签名验证失败');
            }
            
            // 查询订单
            $query = Order::where('merchant_id', $merchant->id);
            if (!empty($params['platform_order_no'])) {
                $query->where('platform_order_no', $params['platform_order_no']);
            } else {
                $query->where('merchant_order_no', $params['merchant_order_no']);
            }
            $order = $query->first();
            if (!$order) {
                return $this->error('订单不存在');
            }

            // ==== 新增实时查支付宝单号分支 ====
            // 这里只对支付宝通道查远端
            $needAlipayCheck = true; // 如需更精准检测，可加字段、枚举判断
            if ($needAlipayCheck) {
                try {
                    $subject = Subject::find($order->subject_id);
                    $product = \app\model\Product::find($order->product_id);
                    $paymentType = $product ? $product->paymentType : null;
                    // 获取支付宝支付配置（高复用工厂方法）
                    $paymentConfig = \app\service\payment\PaymentFactory::getPaymentConfig($subject, $paymentType);
                    // 查询支付宝真实支付状态
                    $alipayOrder = \app\service\alipay\AlipayQueryService::queryOrder($order->platform_order_no, $paymentConfig);
                    if (!empty($alipayOrder['trade_status']) && in_array($alipayOrder['trade_status'], ['TRADE_SUCCESS','TRADE_FINISHED'])) {
                        // 实际已支付，直接转为已支付、补链路，并调回调
                        $order->pay_status = \app\model\Order::PAY_STATUS_PAID;
                        $order->pay_time = $alipayOrder['gmt_payment'] ?? date('Y-m-d H:i:s');
                        $order->save();
                        \app\service\OrderLogService::log(
                            \app\common\helpers\TraceIdHelper::get($request),
                            $order->platform_order_no,
                            $order->merchant_order_no,
                            '支付同步检查',
                            'INFO',
                            '节点19-支付补同步',
                            [
                                'action' => '查单发现实际已支付',
                                'trade_status' => $alipayOrder['trade_status'],
                                'operator_ip' => $request->getRealIp(),
                            ],
                            $request->getRealIp(),
                            $request->header('user-agent', '')
                        );
                        // 触发后续回调等, 可直接调用 notify controller 或复用正常notify逻辑
                        // ...此处可进一步调用 PaymentFactory::handlePaymentNotify/相关逻辑，实现异步回调与状态更新...
                        return $this->success([], '订单实际已支付');
                    }
                } catch (\Exception $e) {
                    Log::error('查单检测支付宝状态失败', ['error'=>$e->getMessage()]);
                    // 如查不到则继续走后续关单分支
                }
            }
            // ==== 实时同步分支 END ====
            // 检查订单状态（允许已创建和已打开状态）
            if ($order->pay_status != Order::PAY_STATUS_CREATED && $order->pay_status != Order::PAY_STATUS_OPENED) {
                return $this->error('只有已创建或已打开订单才能关闭');
            }
            // 关闭订单
            $order->pay_status = Order::PAY_STATUS_CLOSED;
            $order->close_time = date('Y-m-d H:i:s');
            $order->save();
            // 加链路日志
            \app\service\OrderLogService::log(
                \app\common\helpers\TraceIdHelper::get($request),
                $order->platform_order_no,
                $order->merchant_order_no,
                '关闭',
                'INFO',
                '节点20-订单关闭',
                [
                    'action' => '订单关闭',
                    'close_source' => 'API接口',
                    'operator_ip' => $request->getRealIp(),
                    'close_time' => $order->close_time
                ],
                $request->getRealIp(),
                $request->header('user-agent', '')
            );
            return $this->success([], '订单关闭成功');
            
        } catch (\Exception $e) {
            Log::error('订单关闭接口异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->error('系统异常，请稍后重试');
        }
    }
    
    /**
     * 构建支付信息并生成支付链接
     * @param Order $order 订单信息
     * @param Subject $subject 支付主体
     * @param array $params 请求参数
     * @param Product $product 产品信息
     * @return array 支付信息
     */
    private function buildPaymentInfo($order, $subject, $params, $product): array
    {
        try {
            // 生成统一的支付地址（所有支付方式都返回支付页面URL）
            $baseUrl = env('APP_URL', 'http://127.0.0.1:8787');
            $paymentUrl = $baseUrl . '/payment.html?order_number=' . $order->platform_order_no;
            
            // 如果指定了payment_type，添加到URL参数
            if (isset($params['payment_type'])) {
                $paymentUrl .= '&payment_type=' . urlencode($params['payment_type']);
            }

            // 记录订单创建日志
            Log::info('订单创建完成，生成支付地址', [
                'platform_order_no' => $order->platform_order_no,
                'payment_url' => $paymentUrl,
                'product_code' => $params['product_code'],
                'subject_id' => $subject->id,
                'amount' => $order->order_amount,
                'payment_type' => $params['payment_type'] ?? 'auto'
            ]);

            return [
                'payment_url' => $paymentUrl,
                'payment_method' => $params['payment_type'] ?? ($product->paymentType->product_code ?? ''),
                'qr_code' => '',  // 不在此处生成二维码，由支付页面生成
                'payment_info' => [
                    'order_number' => $order->platform_order_no,
                    'payment_url' => $paymentUrl
                ]
            ];

        } catch (\Exception $e) {
            Log::error('构建支付信息失败', [
                'order_id' => $order->id,
                'product_code' => $params['product_code'],
                'error' => $e->getMessage()
            ]);
            
            // 返回错误信息，但不中断订单创建流程
            return [
                'error' => true,
                'message' => '支付信息构建失败: ' . $e->getMessage(),
                'payment_url' => "https://pay.example.com/error?order_no=" . $order->platform_order_no
            ];
        }
    }
    
    /**
     * 获取支付状态文本
     */
    private function getPayStatusText($status): string
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
     * 成功响应
     */
    private function success($data = [], $message = 'success')
    {
        return json([
            'code' => 0,
            'msg' => $message,
            'data' => $data
        ]);
    }
    
    /**
     * 错误响应
     */
    private function error($message = 'error', $code = 1)
    {
        return json([
            'code' => $code,
            'msg' => $message,
            'data' => null
        ]);
    }
    
}


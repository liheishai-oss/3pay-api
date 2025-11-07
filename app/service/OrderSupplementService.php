<?php

namespace app\service;

use app\model\Order;
use app\model\Subject;
use app\model\Product;
use app\service\alipay\AlipayQueryService;
use app\service\payment\PaymentFactory;
use app\service\MerchantNotifyService;
use app\service\OrderLogService;
use support\Log;
use support\Db;

/**
 * 订单补单服务类
 * 负责查询支付宝订单状态并补单
 */
class OrderSupplementService
{
    /**
     * 补单处理（查询支付宝状态并补单）
     * 
     * @param Order $order 订单对象
     * @param string $operatorIp 操作者IP（用于日志）
     * @param string $operatorAgent 操作者User-Agent（用于日志）
     * @param bool $isManual 是否手动触发
     * @return array ['success' => bool, 'message' => string, 'data' => array]
     */
    public static function supplementOrder(Order $order, string $operatorIp = 'SYSTEM', string $operatorAgent = null, bool $isManual = false): array
    {
        try {
            // 1. 检查订单状态（手动补单时跳过此检查，直接查询支付宝状态）
            if (!$isManual) {
                // 自动补单时才检查本地状态
                if ($order->pay_status == Order::PAY_STATUS_PAID) {
                    return [
                        'success' => false,
                        'message' => '订单已支付，无需补单',
                        'data' => ['order_status' => 'already_paid']
                    ];
                }

                if ($order->pay_status == Order::PAY_STATUS_CLOSED) {
                    return [
                        'success' => false,
                        'message' => '订单已关闭，无法补单',
                        'data' => ['order_status' => 'closed']
                    ];
                }
            }
            // 手动补单时：无论本地订单是什么状态，都进行查询并更新

            // 2. 获取订单相关主体和产品信息
            $subject = Subject::find($order->subject_id);
            if (!$subject) {
                return [
                    'success' => false,
                    'message' => '订单主体不存在',
                    'data' => ['subject_id' => $order->subject_id]
                ];
            }

            $product = Product::find($order->product_id);
            if (!$product) {
                return [
                    'success' => false,
                    'message' => '订单产品不存在',
                    'data' => ['product_id' => $order->product_id]
                ];
            }

            $paymentType = $product->paymentType;
            if (!$paymentType) {
                return [
                    'success' => false,
                    'message' => '产品未配置支付类型',
                    'data' => ['product_id' => $product->id]
                ];
            }

            // 3. 获取支付配置
            try {
                $paymentConfig = PaymentFactory::getPaymentConfig($subject, $paymentType);
            } catch (\Exception $e) {
                Log::error('获取支付配置失败', [
                    'order_id' => $order->id,
                    'subject_id' => $subject->id,
                    'error' => $e->getMessage()
                ]);
                return [
                    'success' => false,
                    'message' => '获取支付配置失败: ' . $e->getMessage(),
                    'data' => []
                ];
            }

            // 4. 查询支付宝订单状态
            // 优先尝试使用支付宝交易号查询（如果存在），避免权限不足问题
            $queryOrderNo = null;
            $useTradeNo = false;
            $queryAttempts = [];
            
            // 策略1：如果有支付宝交易号，优先使用交易号查询（跨应用查询更可靠）
            if (!empty($order->alipay_order_no) || !empty($order->trade_no)) {
                $queryOrderNo = $order->alipay_order_no ?: $order->trade_no;
                $useTradeNo = true;
                $queryAttempts[] = ['type' => 'trade_no', 'value' => $queryOrderNo];
            } else {
                // 策略2：使用平台订单号（商户订单号）
                $queryOrderNo = $order->platform_order_no;
                $queryAttempts[] = ['type' => 'platform_order_no', 'value' => $queryOrderNo];
            }
            
            // 记录开始查询支付宝的链路日志
            OrderLogService::log(
                $order->trace_id ?? '',
                $order->platform_order_no,
                $order->merchant_order_no,
                $isManual ? '手动补单' : '自动补单',
                'INFO',
                '节点30-补单查询开始',
                [
                    'action' => '开始查询支付宝订单状态',
                    'query_order_no' => $queryOrderNo,
                    'query_type' => $useTradeNo ? 'trade_no' : 'platform_order_no',
                    'current_pay_status' => self::getPayStatusText($order->pay_status),
                    'operator_ip' => $operatorIp
                ],
                $operatorIp,
                $operatorAgent
            );
            
            $alipayOrder = null;
            $lastError = null;
            
            try {
                $alipayOrder = AlipayQueryService::queryOrder($queryOrderNo, $paymentConfig, $useTradeNo);
                
                // 记录查询成功的链路日志
                OrderLogService::log(
                    $order->trace_id ?? '',
                    $order->platform_order_no,
                    $order->merchant_order_no,
                    $isManual ? '手动补单' : '自动补单',
                    'INFO',
                    '节点30-补单查询成功',
                    [
                        'action' => '支付宝订单查询成功',
                        'query_order_no' => $queryOrderNo,
                        'trade_status' => $alipayOrder['trade_status'] ?? '',
                        'trade_no' => $alipayOrder['trade_no'] ?? '',
                        'operator_ip' => $operatorIp
                    ],
                    $operatorIp,
                    $operatorAgent
                );
            } catch (\Exception $e) {
                $lastError = $e;
                
                // 如果使用平台订单号查询失败，且错误是权限不足，尝试使用商户订单号
                if (!$useTradeNo && (strpos($e->getMessage(), 'Insufficient Permissions') !== false || 
                                     strpos($e->getMessage(), '权限不足') !== false)) {
                    Log::warning('使用平台订单号查询权限不足，尝试使用商户订单号', [
                        'order_id' => $order->id,
                        'platform_order_no' => $order->platform_order_no,
                        'merchant_order_no' => $order->merchant_order_no
                    ]);
                    
                    try {
                        // 尝试使用商户订单号查询
                        $queryAttempts[] = ['type' => 'merchant_order_no', 'value' => $order->merchant_order_no];
                        $alipayOrder = AlipayQueryService::queryOrder($order->merchant_order_no, $paymentConfig, false);
                        Log::info('使用商户订单号查询成功', [
                            'order_id' => $order->id,
                            'merchant_order_no' => $order->merchant_order_no
                        ]);
                    } catch (\Exception $e2) {
                        $lastError = $e2;
                        Log::warning('使用商户订单号查询也失败', [
                            'order_id' => $order->id,
                            'merchant_order_no' => $order->merchant_order_no,
                            'error' => $e2->getMessage()
                        ]);
                    }
                }
                
                // 如果所有查询都失败
                if ($alipayOrder === null) {
                    Log::warning('支付宝订单查询失败', [
                        'order_id' => $order->id,
                        'platform_order_no' => $order->platform_order_no,
                        'merchant_order_no' => $order->merchant_order_no,
                        'attempts' => $queryAttempts,
                        'error' => $lastError ? $lastError->getMessage() : '未知错误'
                    ]);

                    // 记录补单查询失败日志
                    OrderLogService::log(
                        $order->trace_id ?? '',
                        $order->platform_order_no,
                        $order->merchant_order_no,
                        $isManual ? '手动补单' : '自动补单',
                        'WARN',
                        '节点31-补单查询失败',
                        [
                            'action' => '查询支付宝状态失败',
                            'error' => $lastError ? $lastError->getMessage() : '未知错误',
                            'query_attempts' => $queryAttempts,
                            'operator_ip' => $operatorIp
                        ],
                        $operatorIp,
                        $operatorAgent
                    );

                    return [
                        'success' => false,
                        'message' => '查询支付宝订单状态失败: ' . ($lastError ? $lastError->getMessage() : '未知错误'),
                        'data' => [
                            'query_error' => $lastError ? $lastError->getMessage() : '未知错误',
                            'query_attempts' => $queryAttempts,
                            'suggestion' => '请检查：1) 支付宝应用是否已开通订单查询权限；2) 订单是否属于当前应用；3) 支付宝开放平台应用配置是否正确'
                        ]
                    ];
                }
            }

            // 5. 检查支付宝订单状态
            $tradeStatus = $alipayOrder['trade_status'] ?? '';
            $oldPayStatus = $order->pay_status;
            
            // 如果支付宝订单未支付
            if (empty($tradeStatus) || !in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'])) {
                // 手动补单时：如果本地显示已支付，但支付宝实际未支付，需要纠正状态
                if ($isManual && $oldPayStatus == Order::PAY_STATUS_PAID) {
                    Db::beginTransaction();
                    try {
                        $order->pay_status = Order::PAY_STATUS_UNPAID;
                        $order->pay_time = null;
                        $order->notify_status = Order::NOTIFY_STATUS_PENDING;
                        $order->save();
                        
                        Db::commit();
                        
                        OrderLogService::log(
                            $order->trace_id ?? '',
                            $order->platform_order_no,
                            $order->merchant_order_no,
                            '手动补单',
                            'INFO',
                            '节点31-状态纠正',
                            [
                                'action' => '纠正订单状态：本地已支付但支付宝未支付',
                                'old_status' => '已支付',
                                'new_status' => '待支付',
                                'trade_status' => $tradeStatus,
                                'operator_ip' => $operatorIp
                            ],
                            $operatorIp,
                            $operatorAgent
                        );
                        
                        return [
                            'success' => true,
                            'message' => '状态已纠正：本地显示已支付，但支付宝实际未支付，已纠正为待支付',
                            'data' => [
                                'trade_status' => $tradeStatus,
                                'old_status' => '已支付',
                                'new_status' => '待支付',
                                'status_corrected' => true,
                                'alipay_order' => $alipayOrder
                            ]
                        ];
                    } catch (\Exception $e) {
                        Db::rollBack();
                        Log::error('纠正订单状态失败', [
                            'order_id' => $order->id,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
                // 非手动补单或本地状态正确，返回未支付信息
                OrderLogService::log(
                    $order->trace_id ?? '',
                    $order->platform_order_no,
                    $order->merchant_order_no,
                    $isManual ? '手动补单' : '自动补单',
                    'INFO',
                    '节点31-补单查询结果',
                    [
                        'action' => '支付宝订单未支付',
                        'trade_status' => $tradeStatus,
                        'local_status' => self::getPayStatusText($oldPayStatus),
                        'operator_ip' => $operatorIp
                    ],
                    $operatorIp,
                    $operatorAgent
                );

                return [
                    'success' => false,
                    'message' => '支付宝订单未支付，无需补单',
                    'data' => [
                        'trade_status' => $tradeStatus,
                        'alipay_order' => $alipayOrder
                    ]
                ];
            }

            // 6. 更新订单状态为已支付（手动补单时，无论本地状态如何都更新）
            Db::beginTransaction();
            try {
                // $oldPayStatus 已在上面定义（第192行），这里不再重复定义
                $oldNotifyStatus = $order->notify_status;
                $oldStatusText = self::getPayStatusText($oldPayStatus);
                
                // 记录更新前的状态（链路日志）
                OrderLogService::log(
                    $order->trace_id ?? '',
                    $order->platform_order_no,
                    $order->merchant_order_no,
                    $isManual ? '手动补单' : '自动补单',
                    'INFO',
                    '节点31-补单状态更新前',
                    [
                        'action' => '准备更新订单状态',
                        'old_pay_status' => $oldPayStatus,
                        'old_status_text' => $oldStatusText,
                        'old_notify_status' => $oldNotifyStatus,
                        'trade_status' => $tradeStatus,
                        'trade_no' => $alipayOrder['trade_no'] ?? '',
                        'operator_ip' => $operatorIp
                    ],
                    $operatorIp,
                    $operatorAgent
                );
                
                $order->pay_status = Order::PAY_STATUS_PAID;
                $order->pay_time = $alipayOrder['gmt_payment'] ?? date('Y-m-d H:i:s');
                // 补单时记录操作者IP（如果是手动补单，可能是管理员IP；自动补单则为SYSTEM）
                if (empty($order->pay_ip)) {
                    $order->pay_ip = $operatorIp;
                }
                
                // 更新支付宝交易号（如果存在）
                if (!empty($alipayOrder['trade_no'])) {
                    $order->trade_no = $alipayOrder['trade_no'];
                    $order->alipay_order_no = $alipayOrder['trade_no'];
                }

                // 更新其他信息
                if (!empty($alipayOrder['buyer_id'])) {
                    $order->buyer_id = $alipayOrder['buyer_id'];
                }
                if (!empty($alipayOrder['buyer_logon_id'])) {
                    $order->mobile = $alipayOrder['buyer_logon_id'];
                }

                $order->save();

                // 判断是否纠正了状态
                $isStatusCorrected = ($oldPayStatus != Order::PAY_STATUS_PAID);
                
                // 记录补单成功日志（节点31）
                OrderLogService::log(
                    $order->trace_id ?? '',
                    $order->platform_order_no,
                    $order->merchant_order_no,
                    $isManual ? '手动补单' : '自动补单',
                    'INFO',
                    '节点31-补单成功',
                    [
                        'action' => $isStatusCorrected ? '订单状态已纠正为已支付' : '订单状态已更新为已支付',
                        'old_pay_status' => $oldPayStatus,
                        'old_status_text' => $oldStatusText,
                        'new_status_text' => '已支付',
                        'status_corrected' => $isStatusCorrected,
                        'trade_status' => $tradeStatus,
                        'trade_no' => $alipayOrder['trade_no'] ?? '',
                        'alipay_order_no' => $alipayOrder['trade_no'] ?? '',
                        'gmt_payment' => $alipayOrder['gmt_payment'] ?? '',
                        'pay_time' => $order->pay_time,
                        'buyer_id' => $alipayOrder['buyer_id'] ?? '',
                        'total_amount' => $alipayOrder['total_amount'] ?? '',
                        'operator_ip' => $operatorIp
                    ],
                    $operatorIp,
                    $operatorAgent
                );

                Db::commit();

                // 7. 触发商户回调
                // 手动补单时：只要支付宝已支付，就触发回调（不受本地回调状态限制）
                // 自动补单时：如果订单需要回调且未成功回调过，才触发回调
                $notifyResult = ['success' => false, 'message' => '无需回调'];
                $shouldNotify = false;
                
                if ($isManual) {
                    // 手动补单：只要支付宝已支付且存在回调地址，就触发回调
                    $shouldNotify = !empty($order->notify_url);
                } else {
                    // 自动补单：如果订单需要回调且未成功回调过，才触发回调
                    $shouldNotify = !empty($order->notify_url) && $order->notify_status != Order::NOTIFY_STATUS_SUCCESS;
                }
                
                if ($shouldNotify) {
                    try {
                        $notifyResult = MerchantNotifyService::send(
                            $order,
                            ['trade_no' => $alipayOrder['trade_no'] ?? ''],
                            ['manual' => $isManual]
                        );

                        // 记录回调结果日志
                        OrderLogService::log(
                            $order->trace_id ?? '',
                            $order->platform_order_no,
                            $order->merchant_order_no,
                            $isManual ? '手动补单' : '自动补单',
                            $notifyResult['success'] ? 'INFO' : 'WARN',
                            '节点32-补单回调',
                            [
                                'action' => '补单后触发回调',
                                'callback_result' => $notifyResult['success'] ? '成功' : '失败',
                                'callback_message' => $notifyResult['message'] ?? '',
                                'operator_ip' => $operatorIp
                            ],
                            $operatorIp,
                            $operatorAgent
                        );
                    } catch (\Exception $e) {
                        Log::error('补单后回调失败', [
                            'order_id' => $order->id,
                            'error' => $e->getMessage()
                        ]);
                        $notifyResult = ['success' => false, 'message' => $e->getMessage()];
                    }
                }

                $message = '补单成功';
                if ($isStatusCorrected) {
                    $message = "状态已纠正：从{$oldStatusText}纠正为已支付";
                }
                $message .= ($notifyResult['success'] ? '，回调已触发' : '，回调未触发');
                
                // 补单成功后，如果订单已支付但未分账，触发分账处理
                $royaltyResult = ['success' => false, 'message' => '未处理'];
                try {
                    $order->refresh(); // 刷新订单数据
                    if ($order->needsRoyalty()) {
                        $royaltyResult = \app\service\royalty\RoyaltyService::processRoyalty(
                            $order,
                            $operatorIp,
                            $operatorAgent
                        );
                        
                        // 记录分账结果日志
                        OrderLogService::log(
                            $order->trace_id ?? '',
                            $order->platform_order_no,
                            $order->merchant_order_no,
                            $isManual ? '手动补单' : '自动补单',
                            $royaltyResult['success'] ? 'INFO' : 'WARN',
                            '节点35-补单后分账',
                            [
                                'action' => $royaltyResult['success'] ? '补单后分账成功' : '补单后分账失败',
                                'royalty_result' => $royaltyResult['success'] ? '成功' : $royaltyResult['message'],
                                'operator_ip' => $operatorIp
                            ],
                            $operatorIp,
                            $operatorAgent
                        );
                        
                        if ($royaltyResult['success']) {
                            $message .= '，分账已处理';
                        } else {
                            $message .= '，分账未处理（' . $royaltyResult['message'] . '）';
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('补单后分账处理异常', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage()
                    ]);
                    $royaltyResult = [
                        'success' => false,
                        'message' => '分账处理异常: ' . $e->getMessage()
                    ];
                }
                
                return [
                    'success' => true,
                    'message' => $message,
                    'data' => [
                        'order_id' => $order->id,
                        'platform_order_no' => $order->platform_order_no,
                        'trade_status' => $tradeStatus,
                        'trade_no' => $alipayOrder['trade_no'] ?? '',
                        'pay_time' => $order->pay_time,
                        'old_status' => $oldStatusText,
                        'new_status' => '已支付',
                        'status_corrected' => $isStatusCorrected,
                        'notify_result' => $notifyResult,
                        'royalty_result' => $royaltyResult
                    ]
                ];

            } catch (\Exception $e) {
                Db::rollBack();
                Log::error('补单更新订单状态失败', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage()
                ]);
                return [
                    'success' => false,
                    'message' => '更新订单状态失败: ' . $e->getMessage(),
                    'data' => []
                ];
            }

        } catch (\Exception $e) {
            Log::error('补单处理异常', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'success' => false,
                'message' => '补单处理异常: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }

    /**
     * 批量补单（用于自动补单任务）
     * 
     * @param int $limit 每次处理的订单数量
     * @param int $minutesAgo 查询多少分钟前创建的订单（默认30分钟）
     * @return array ['success_count' => int, 'failed_count' => int, 'skipped_count' => int]
     */
    public static function batchSupplement(int $limit = 50, int $minutesAgo = 30): array
    {
        $successCount = 0;
        $failedCount = 0;
        $skippedCount = 0;

        try {
            // 查询待补单的订单：待支付状态 + 创建时间超过指定分钟
            $timeThreshold = date('Y-m-d H:i:s', time() - $minutesAgo * 60);
            
            $orders = Order::where('pay_status', Order::PAY_STATUS_UNPAID)
                ->where('created_at', '<', $timeThreshold)
                ->limit($limit)
                ->get();

            if ($orders->isEmpty()) {
                return [
                    'success_count' => 0,
                    'failed_count' => 0,
                    'skipped_count' => 0,
                    'total' => 0
                ];
            }

            foreach ($orders as $order) {
                $result = self::supplementOrder($order, 'SYSTEM', null, false);
                
                if ($result['success']) {
                    $successCount++;
                } elseif (isset($result['data']['order_status']) && 
                          in_array($result['data']['order_status'], ['already_paid', 'closed'])) {
                    $skippedCount++;
                } else {
                    $failedCount++;
                }

                // 避免频繁请求，每处理一个订单延迟100ms
                usleep(100000);
            }

            Log::info('批量补单任务执行完成', [
                'total' => $orders->count(),
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'skipped_count' => $skippedCount
            ]);

            return [
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'skipped_count' => $skippedCount,
                'total' => $orders->count()
            ];

        } catch (\Exception $e) {
            Log::error('批量补单任务异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'skipped_count' => $skippedCount,
                'total' => 0,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 获取支付状态文本
     */
    private static function getPayStatusText($status): string
    {
        $statusMap = [
            Order::PAY_STATUS_UNPAID => '待支付',
            Order::PAY_STATUS_PAID => '已支付',
            Order::PAY_STATUS_CLOSED => '已关闭',
            Order::PAY_STATUS_REFUNDED => '已退款'
        ];
        return $statusMap[$status] ?? '未知';
    }
}


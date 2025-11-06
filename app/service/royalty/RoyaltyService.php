<?php

namespace app\service\royalty;

use app\model\Order;
use app\model\OrderRoyalty;
use app\model\Subject;
use app\model\SingleRoyalty;
use app\model\TelegramMessageQueue;
use app\service\alipay\AlipayRoyaltyService;
use app\service\payment\PaymentFactory;
use app\service\OrderLogService;
use app\service\robot\TelegramMessageQueueService;
use app\common\constants\RoyaltyConstants;
use app\common\constants\CacheKeys;
use support\Db;
use support\Log;
use support\Redis;
use Exception;

/**
 * 分账服务类
 */
class RoyaltyService
{
    /**
     * 订单支付成功后触发分账
     * @param Order $order 订单对象
     * @param string $operatorIp 操作IP（可选）
     * @param string $operatorAgent 操作代理（可选）
     * @return array ['success' => bool, 'message' => string, 'data' => array]
     */
    public static function processRoyalty(Order $order, string $operatorIp = 'SYSTEM', string $operatorAgent = null): array
    {
        try {
            // 1. 检查分账条件
            if (!$order->needsRoyalty()) {
                return [
                    'success' => false,
                    'message' => '订单不需要分账',
                    'data' => ['reason' => 'order_not_need_royalty']
                ];
            }

            // 2. 防止重复分账（使用数据库锁）
            if (OrderRoyalty::hasSuccessRoyalty($order->id)) {
                return [
                    'success' => false,
                    'message' => '订单已存在成功分账记录',
                    'data' => ['reason' => 'already_royalized']
                ];
            }

            // 3. 加载主体信息
            $subject = $order->subject;
            if (!$subject) {
                return [
                    'success' => false,
                    'message' => '订单主体不存在',
                    'data' => ['reason' => 'subject_not_found']
                ];
            }

            // 4. 计算分账金额
            $royaltyData = self::calculateRoyaltyAmount($order, $subject);
            
            // 如果不分账，直接返回
            if ($royaltyData['royalty_amount'] <= 0) {
                return [
                    'success' => true,
                    'message' => '订单不分账',
                    'data' => ['royalty_amount' => 0, 'reason' => 'no_royalty']
                ];
            }

            // 5. 创建分账记录
            Db::beginTransaction();
            try {
                $royaltyRecord = OrderRoyalty::create([
                    'order_id' => $order->id,
                    'platform_order_no' => $order->platform_order_no,
                    'trade_no' => $order->trade_no ?? $order->alipay_order_no,
                    'royalty_type' => $subject->royalty_type,
                    'royalty_mode' => $subject->royalty_mode,
                    'royalty_rate' => $subject->royalty_rate,
                    'subject_id' => $subject->id,
                    'subject_amount' => $royaltyData['subject_amount'],
                    'payee_type' => $royaltyData['payee_type'] ?? null,
                    'payee_id' => $royaltyData['payee_id'] ?? null,
                    'payee_name' => $royaltyData['payee_name'] ?? '',
                    'payee_account' => $royaltyData['payee_account'] ?? '',
                    'payee_user_id' => $royaltyData['payee_user_id'] ?? '',
                    'royalty_amount' => $royaltyData['royalty_amount'],
                    'royalty_status' => OrderRoyalty::ROYALTY_STATUS_PENDING,
                ]);

                // 记录分账开始日志
                OrderLogService::log(
                    $order->trace_id ?? '',
                    $order->platform_order_no,
                    $order->merchant_order_no,
                    '分账处理',
                    'INFO',
                    '节点33-分账开始',
                    [
                        'action' => '开始处理分账',
                        'royalty_type' => $subject->royalty_type,
                        'royalty_amount' => $royaltyData['royalty_amount'],
                        'subject_amount' => $royaltyData['subject_amount'],
                        'payee_name' => $royaltyData['payee_name'] ?? '',
                        'operator_ip' => $operatorIp
                    ],
                    $operatorIp,
                    $operatorAgent
                );

            // 6. 验证必要信息
            $tradeNo = $order->trade_no ?? $order->alipay_order_no;
            if (empty($tradeNo)) {
                throw new Exception("订单缺少支付宝交易号，无法进行分账");
            }

            if (empty($royaltyData['payee_user_id'])) {
                throw new Exception("分账收款人支付宝用户ID为空，无法进行分账");
            }

            // 7. 调用支付宝分账接口
            $royaltyRecord->royalty_status = OrderRoyalty::ROYALTY_STATUS_PROCESSING;
            $royaltyRecord->save();

            // 获取支付配置
            $product = $order->product;
            $paymentType = $product ? $product->paymentType : null;
            $paymentConfig = PaymentFactory::getPaymentConfig($subject, $paymentType);

            // 调用支付宝分账接口
            $alipayResult = AlipayRoyaltyService::royalty(
                [
                    'trade_no' => $tradeNo,
                    'platform_order_no' => $order->platform_order_no,
                    'order_amount' => $order->order_amount,
                ],
                [
                    'royalty_amount' => $royaltyData['royalty_amount'],
                    'payee_user_id' => $royaltyData['payee_user_id'],
                    'payee_name' => $royaltyData['payee_name'] ?? '',
                ],
                $paymentConfig
            );

            // 8. 更新分账记录
            if ($alipayResult['success']) {
                $royaltyRecord->royalty_status = OrderRoyalty::ROYALTY_STATUS_SUCCESS;
                $royaltyRecord->royalty_time = date('Y-m-d H:i:s');
                $royaltyRecord->alipay_royalty_no = $alipayResult['data']['royalty_no'] ?? '';
                $royaltyRecord->alipay_result = json_encode($alipayResult['data'], JSON_UNESCAPED_UNICODE);
            } else {
                $royaltyRecord->royalty_status = OrderRoyalty::ROYALTY_STATUS_FAILED;
                $royaltyRecord->royalty_error = $alipayResult['message'] ?? '分账失败';
                $royaltyRecord->alipay_result = json_encode($alipayResult, JSON_UNESCAPED_UNICODE);
                
                // 9. 检查是否需要关闭分账主体（统一使用 RoyaltyConstants 管理错误码）
                $errorMessage = $alipayResult['message'] ?? '';
                $errorData = $alipayResult['data'] ?? [];
                
                // 从多个可能的位置提取 sub_code
                $subCode = null;
                if (isset($errorData['sub_code'])) {
                    $subCode = $errorData['sub_code'];
                } elseif (isset($errorData['royalty_result']['sub_code'])) {
                    $subCode = $errorData['royalty_result']['sub_code'];
                } elseif (isset($errorData['full_response']['alipay_trade_order_settle_response']['sub_code'])) {
                    $subCode = $errorData['full_response']['alipay_trade_order_settle_response']['sub_code'];
                } else {
                    // 从错误消息中提取错误码
                    $subCode = RoyaltyConstants::extractErrorCode($errorMessage);
                }
                
                // 使用统一的方法检查是否需要关闭主体
                if (RoyaltyConstants::shouldDisableSubject($subCode, $errorMessage)) {
                    // 关闭分账主体
                    $subject->status = Subject::STATUS_DISABLED;
                    $subject->save();
                    
                    // 获取实际的错误码（用于日志）
                    $actualErrorCode = $subCode ?: RoyaltyConstants::extractErrorCode($errorMessage) ?: 'UNKNOWN';
                    
                    // 检查是否已推送过通知（使用Redis防止重复推送）
                    $notifyKey = CacheKeys::getSubjectDisabledNotifyKey($subject->id);
                    $hasNotified = false;
                    
                    try {
                        $hasNotified = (bool)Redis::get($notifyKey);
                    } catch (\Throwable $e) {
                        Log::warning('检查主体关闭推送状态失败', [
                            'subject_id' => $subject->id,
                            'error' => $e->getMessage()
                        ]);
                    }
                    
                    // 如果未推送过，则将消息加入数据库队列
                    if (!$hasNotified) {
                        try {
                            $messageContent = self::buildSubjectDisabledMessage($subject, $actualErrorCode, $errorMessage, $order);
                            
                            // 将消息加入数据库队列
                            $queueMessage = TelegramMessageQueueService::addMessage(
                                '🚨 分账主体自动关闭',
                                $messageContent,
                                TelegramMessageQueue::PRIORITY_HIGH, // 高优先级
                                'html', // HTML格式
                                [
                                    'max_retry' => 3, // 最大重试3次
                                ]
                            );
                            
                            if ($queueMessage) {
                                // 加入队列成功，记录到Redis（7天过期，确保只加入一次）
                                try {
                                    Redis::set($notifyKey, 1, 'EX', 7 * 24 * 3600); // 7天
                                } catch (\Throwable $e) {
                                    Log::warning('记录主体关闭推送状态失败', [
                                        'subject_id' => $subject->id,
                                        'error' => $e->getMessage()
                                    ]);
                                }
                                
                                Log::info('主体关闭推送消息已加入队列', [
                                    'subject_id' => $subject->id,
                                    'error_code' => $actualErrorCode,
                                    'message_id' => $queueMessage->id
                                ]);
                            } else {
                                Log::warning('主体关闭推送消息加入队列失败', [
                                    'subject_id' => $subject->id,
                                    'error_code' => $actualErrorCode
                                ]);
                            }
                        } catch (\Throwable $e) {
                            // 加入队列失败不影响主体关闭流程，只记录日志
                            Log::error('主体关闭推送消息加入队列异常', [
                                'subject_id' => $subject->id,
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString()
                            ]);
                        }
                    }
                    
                    // 记录日志
                    OrderLogService::log(
                        $order->trace_id ?? '',
                        $order->platform_order_no,
                        $order->merchant_order_no,
                        '分账处理',
                        'ERROR',
                        '节点34-分账主体自动关闭',
                        [
                            'action' => '分账失败触发主体关闭',
                            'error_code' => $actualErrorCode,
                            'subject_id' => $subject->id,
                            'subject_name' => $subject->company_name ?? '',
                            'reason' => "分账返回错误码 [{$actualErrorCode}]，已自动禁用该主体",
                            'error_message' => $errorMessage,
                            'notified' => !$hasNotified,
                            'operator_ip' => $operatorIp
                        ],
                        $operatorIp,
                        $operatorAgent
                    );
                    
                    Log::warning('分账失败自动关闭主体', [
                        'subject_id' => $subject->id,
                        'subject_name' => $subject->company_name ?? '',
                        'order_id' => $order->id,
                        'platform_order_no' => $order->platform_order_no,
                        'error_code' => $actualErrorCode,
                        'error_message' => $errorMessage,
                        'disable_error_codes' => RoyaltyConstants::getDisableErrorCodes(),
                        'notified' => !$hasNotified
                    ]);
                }
            }
            $royaltyRecord->save();

            Db::commit();

            // 记录分账结果日志
            OrderLogService::log(
                $order->trace_id ?? '',
                $order->platform_order_no,
                $order->merchant_order_no,
                '分账处理',
                $alipayResult['success'] ? 'INFO' : 'WARN',
                '节点34-分账结果',
                [
                    'action' => $alipayResult['success'] ? '分账成功' : '分账失败',
                    'royalty_amount' => $royaltyData['royalty_amount'],
                    'alipay_royalty_no' => $royaltyRecord->alipay_royalty_no,
                    'error' => $alipayResult['success'] ? null : $alipayResult['message'],
                    'operator_ip' => $operatorIp
                ],
                $operatorIp,
                $operatorAgent
            );

            return [
                'success' => $alipayResult['success'],
                'message' => $alipayResult['success'] ? '分账成功' : ('分账失败: ' . ($alipayResult['message'] ?? '未知错误')),
                'data' => [
                    'royalty_id' => $royaltyRecord->id,
                    'royalty_amount' => $royaltyData['royalty_amount'],
                    'subject_amount' => $royaltyData['subject_amount'],
                    'alipay_royalty_no' => $royaltyRecord->alipay_royalty_no,
                    'alipay_result' => $alipayResult
                ]
            ];

            } catch (Exception $e) {
                Db::rollBack();
                throw $e;
            }

        } catch (Exception $e) {
            Log::error('分账处理异常', [
                'order_id' => $order->id,
                'platform_order_no' => $order->platform_order_no,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => '分账处理失败: ' . $e->getMessage(),
                'data' => ['error' => $e->getMessage()]
            ];
        }
    }

    /**
     * 手动触发分账（管理员操作）
     * @param int $orderId 订单ID
     * @param string $operatorIp 操作IP
     * @return array
     */
    public static function manualRoyalty(int $orderId, string $operatorIp): array
    {
        $order = Order::with('subject')->find($orderId);
        
        if (!$order) {
            return [
                'success' => false,
                'message' => '订单不存在',
                'data' => []
            ];
        }

        return self::processRoyalty($order, $operatorIp, 'Admin');
    }

    /**
     * 查询分账状态
     * @param string $platformOrderNo 平台订单号
     * @return array
     */
    public static function queryRoyaltyStatus(string $platformOrderNo): array
    {
        $order = Order::where('platform_order_no', $platformOrderNo)->first();
        
        if (!$order) {
            return [
                'success' => false,
                'message' => '订单不存在',
                'data' => []
            ];
        }

        $royaltyRecord = OrderRoyalty::getOrderRoyalty($order->id);

        return [
            'success' => true,
            'message' => '查询成功',
            'data' => [
                'order_id' => $order->id,
                'platform_order_no' => $order->platform_order_no,
                'royalty_record' => $royaltyRecord ? $royaltyRecord->toArray() : null,
                'has_royalty' => $royaltyRecord !== null,
                'royalty_status' => $royaltyRecord ? $royaltyRecord->getStatusText() : '未分账'
            ]
        ];
    }

    /**
     * 重试失败的分账
     * @param int $royaltyId 分账记录ID
     * @param string $operatorIp 操作IP
     * @return array
     */
    public static function retryRoyalty(int $royaltyId, string $operatorIp): array
    {
        $royaltyRecord = OrderRoyalty::find($royaltyId);
        
        if (!$royaltyRecord) {
            return [
                'success' => false,
                'message' => '分账记录不存在',
                'data' => []
            ];
        }

        // 只有失败的分账才能重试
        if (!$royaltyRecord->isFailed()) {
            return [
                'success' => false,
                'message' => '只能重试失败的分账记录',
                'data' => ['royalty_status' => $royaltyRecord->getStatusText()]
            ];
        }

        $order = $royaltyRecord->order;
        if (!$order) {
            return [
                'success' => false,
                'message' => '订单不存在',
                'data' => []
            ];
        }

        // 删除旧记录，重新创建
        $royaltyRecord->delete();

        return self::processRoyalty($order, $operatorIp, 'Admin-Retry');
    }

    /**
     * 计算分账金额
     * @param Order $order 订单对象
     * @param Subject $subject 主体对象
     * @return array
     * @throws Exception
     */
    private static function calculateRoyaltyAmount(Order $order, Subject $subject): array
    {
        $orderAmount = $order->order_amount;
        $royaltyRate = $subject->royalty_rate ?? 0;
        
        switch ($subject->royalty_type) {
            case Subject::ROYALTY_TYPE_NONE:
                // 不分账
                return [
                    'royalty_amount' => 0,
                    'subject_amount' => $orderAmount,
                ];
                
            case Subject::ROYALTY_TYPE_SINGLE:
                // 单笔分账：按比例分账
                $royaltyAmount = round($orderAmount * ($royaltyRate / 100), 2);
                $subjectAmount = round($orderAmount - $royaltyAmount, 2);
                
                // 从 single_royalty 表获取收款人信息（已存在的表）
                $singleRoyalty = SingleRoyalty::where('agent_id', $subject->agent_id)
                    ->where('status', SingleRoyalty::STATUS_ENABLED)
                    ->first();
                
                if (!$singleRoyalty) {
                    throw new Exception("代理商 {$subject->agent_id} 未配置单笔分账收款账号");
                }
                
                // 验证金额
                if ($royaltyAmount < 0 || $subjectAmount < 0) {
                    throw new Exception("分账金额计算错误：分账金额={$royaltyAmount}, 主体金额={$subjectAmount}");
                }
                
                // 验证总额
                $total = round($royaltyAmount + $subjectAmount, 2);
                if (abs($total - $orderAmount) > 0.01) {
                    throw new Exception("分账总额不匹配：订单金额={$orderAmount}, 分账总额={$total}");
                }
                    
                return [
                    'royalty_amount' => $royaltyAmount,
                    'subject_amount' => $subjectAmount,
                    'payee_type' => OrderRoyalty::PAYEE_TYPE_SINGLE,
                    'payee_id' => $singleRoyalty->id,
                    'payee_name' => $singleRoyalty->payee_name,
                    'payee_account' => $singleRoyalty->payee_account,
                    'payee_user_id' => $singleRoyalty->payee_user_id,
                ];
                
            case Subject::ROYALTY_TYPE_MERCHANT:
                // 商家分账：需要从商户配置获取分账比例
                $merchant = $order->merchant;
                if (!$merchant) {
                    throw new Exception("订单商户不存在");
                }
                
                // 假设商户收款90%，平台（主体）收款10%（可根据实际业务调整）
                $merchantRate = 90;
                $royaltyAmount = round($orderAmount * ((100 - $merchantRate) / 100), 2);
                $subjectAmount = round($orderAmount - $royaltyAmount, 2);
                
                // 验证金额
                if ($royaltyAmount < 0 || $subjectAmount < 0) {
                    throw new Exception("分账金额计算错误：分账金额={$royaltyAmount}, 主体金额={$subjectAmount}");
                }
                
                // 验证总额
                $total = round($royaltyAmount + $subjectAmount, 2);
                if (abs($total - $orderAmount) > 0.01) {
                    throw new Exception("分账总额不匹配：订单金额={$orderAmount}, 分账总额={$total}");
                }
                
                // 检查商户是否配置了收款账号（商家分账需要）
                // 注意：如果商户分账需要在商户表中配置收款信息，这里需要根据实际情况修改
                $payeeAccount = ''; // 从商户配置获取
                $payeeUserId = ''; // 从商户配置获取
                
                if (empty($payeeUserId)) {
                    throw new Exception("商户分账需要配置收款人支付宝用户ID");
                }
                
                return [
                    'royalty_amount' => $royaltyAmount,
                    'subject_amount' => $subjectAmount,
                    'payee_type' => OrderRoyalty::PAYEE_TYPE_MERCHANT,
                    'payee_id' => $merchant->id,
                    'payee_name' => $merchant->merchant_name ?? '',
                    'payee_account' => $payeeAccount,
                    'payee_user_id' => $payeeUserId,
                ];
                
            default:
                throw new Exception("未知的分账类型: {$subject->royalty_type}");
        }
    }
    
    /**
     * 构建主体关闭推送消息
     * 
     * @param Subject $subject 主体对象
     * @param string $errorCode 错误码
     * @param string $errorMessage 错误消息
     * @param Order $order 订单对象
     * @return string HTML格式的消息内容
     */
    private static function buildSubjectDisabledMessage(Subject $subject, string $errorCode, string $errorMessage, Order $order): string
    {
        $subjectName = $subject->company_name ?? "主体ID: {$subject->id}";
        $time = date('Y-m-d H:i:s');
        
        $message = <<<HTML
🚨 <b>分账主体自动关闭</b>

━━━━━━━━━━━━━━━━━━━━━

📋 <b>主体信息</b>
• 主体名称: {$subjectName}

❌ <b>关闭原因</b>
• 错误码: <code>{$errorCode}</code>
• 错误信息: {$errorMessage}

⏰ <b>关闭时间</b>
{$time}

━━━━━━━━━━━━━━━━━━━━━

⚠️ <b>说明</b>
系统检测到分账接口返回错误码 [{$errorCode}]，已自动禁用该分账主体，避免重复失败。请检查主体配置或联系技术支持。
HTML;
        
        return $message;
    }
}


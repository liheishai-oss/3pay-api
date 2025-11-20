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
use app\common\helpers\MoneyHelper;
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
    private const RETRY_MAX_ATTEMPTS = 3;
    private const RETRY_DELAY_SECONDS = 300;
    private const FAILURE_NOTIFY_TTL = 30 * 24 * 3600; // 30天内同一订单只推送一次
    private const RETRYABLE_ERROR_KEYWORDS = [
        'timeout',
        '超时',
        'system busy',
        'SYSTEM_ERROR',
        'ISP.UNKNOW-ERROR',
        'ISP.UNKNOWN_ERROR',
    ];
    private const RETRYABLE_SUB_CODES = [
        'ACQ.SYSTEM_ERROR',
        'ISP.UNKNOWN_ERROR',
        'ISP.UNKNOW_ERROR',
        'ISP.UNKNOW-ERROR',
        'ACQ.ERROR',
    ];
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
            $subject = $order->getSubjectEntity();
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
                    'data' => [
                        'royalty_amount' => MoneyHelper::convertToYuan($royaltyData['royalty_amount']),
                        'subject_amount' => MoneyHelper::convertToYuan($royaltyData['subject_amount']),
                        'reason' => 'no_royalty'
                    ]
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
                    'payee_user_id' => $royaltyData['payee_account'] ?? '', // 使用账号作为用户ID
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
                        'royalty_amount' => MoneyHelper::convertToYuan($royaltyData['royalty_amount']),
                        'subject_amount' => MoneyHelper::convertToYuan($royaltyData['subject_amount']),
                        'payee_name' => $royaltyData['payee_name'] ?? '',
                        'payee_account' => $royaltyData['payee_account'] ?? '',
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

            if (empty($royaltyData['payee_account'])) {
                throw new Exception("分账收款人账号为空，无法进行分账");
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
                    'royalty_amount' => MoneyHelper::convertToYuan($royaltyData['royalty_amount']),
                    'payee_account' => $royaltyData['payee_account'],
                    'payee_name' => $royaltyData['payee_name'] ?? '',
                ],
                $paymentConfig
            );

            $errorMessage = '';
            $subCode = null;

            $errorData = $alipayResult['data'] ?? [];
            $errorMessage = $alipayResult['message'] ?? '';
            $subCode = null;

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

                self::notifyFirstRoyaltyFailure($order, $royaltyRecord, $subCode, $errorMessage, $royaltyData);
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
                    'royalty_amount' => MoneyHelper::convertToYuan($royaltyData['royalty_amount']),
                    'alipay_royalty_no' => $royaltyRecord->alipay_royalty_no,
                    'error' => $alipayResult['success'] ? null : $alipayResult['message'],
                    'operator_ip' => $operatorIp
                ],
                $operatorIp,
                $operatorAgent
            );

            $retryable = $royaltyRecord->royalty_status === OrderRoyalty::ROYALTY_STATUS_FAILED
                ? self::isRetryableFailure($subCode, $errorMessage, $errorData)
                : false;

            return [
                'success' => $alipayResult['success'],
                'message' => $alipayResult['success'] ? '分账成功' : ('分账失败: ' . ($alipayResult['message'] ?? '未知错误')),
                'data' => [
                    'royalty_id' => $royaltyRecord->id,
                    'royalty_amount' => MoneyHelper::convertToYuan($royaltyData['royalty_amount']),
                    'subject_amount' => MoneyHelper::convertToYuan($royaltyData['subject_amount']),
                    'alipay_royalty_no' => $royaltyRecord->alipay_royalty_no,
                    'alipay_result' => $alipayResult,
                    'retryable' => $retryable,
                    'retry_delay' => self::RETRY_DELAY_SECONDS
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
                'data' => [
                    'error' => $e->getMessage(),
                    'retryable' => false,
                    'retry_delay' => self::RETRY_DELAY_SECONDS
                ]
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
        $orderAmountCent = MoneyHelper::convertToCents($order->order_amount);
        $royaltyRate = $subject->royalty_rate ?? 0;
        
        switch ($subject->royalty_type) {
            case Subject::ROYALTY_TYPE_NONE:
                // 不分账
                return [
                    'royalty_amount' => 0,
                    'subject_amount' => $orderAmountCent,
                ];
                
            case Subject::ROYALTY_TYPE_SINGLE:
                // 单笔分账：订单金额先扣除千分之六手续费，再按百分比进行分配
                $handlingFeeCent = (int) floor($orderAmountCent * 6 / 1000); // 0.6%，向下取整
                $netAmountCent = max($orderAmountCent - $handlingFeeCent, 0);
                $royaltyAmountCent = (int) floor($netAmountCent * $royaltyRate / 100);
                $subjectAmountCent = $orderAmountCent - $royaltyAmountCent;
                
                $singleRoyalty = self::getEnabledRoyaltyAccount($subject->agent_id);
                
                // 验证金额
                if ($royaltyAmountCent < 0 || $subjectAmountCent < 0) {
                    throw new Exception("分账金额计算错误：分账金额(分)={$royaltyAmountCent}, 主体金额(分)={$subjectAmountCent}");
                }
                
                // 验证总额
                if (($royaltyAmountCent + $subjectAmountCent) !== $orderAmountCent) {
                    throw new Exception("分账总额不匹配：订单金额(分)={$orderAmountCent}, 分账总额(分)=" . ($royaltyAmountCent + $subjectAmountCent));
                }
                    
                return [
                    'royalty_amount' => $royaltyAmountCent,
                    'subject_amount' => $subjectAmountCent,
                    'payee_type' => OrderRoyalty::PAYEE_TYPE_SINGLE,
                    'payee_id' => $singleRoyalty->id,
                    'payee_name' => $singleRoyalty->payee_name,
                    'payee_account' => $singleRoyalty->payee_account,
                ];
                
            case Subject::ROYALTY_TYPE_MERCHANT:
                // 商家分账：需要从商户配置获取分账比例
                $merchant = $order->merchant;
                if (!$merchant) {
                    throw new Exception("订单商户不存在");
                }
                
                if (!$subject->agent_id) {
                    throw new Exception("主体 {$subject->id} 未关联代理商，无法计算商家分账收款账号");
                }
                
                // 假设商户收款90%，平台（主体）收款10%（可根据实际业务调整）
                $merchantRate = 90;
                $handlingFeeCent = (int) floor($orderAmountCent * 6 / 1000);
                $netAmountCent = max($orderAmountCent - $handlingFeeCent, 0);
                $royaltyAmountCent = (int) floor($netAmountCent * $royaltyRate / 100);
                $subjectAmountCent = $orderAmountCent - $royaltyAmountCent;
                $singleRoyalty = self::getEnabledRoyaltyAccount($subject->agent_id);
                
                // 验证金额
                if ($royaltyAmountCent < 0 || $subjectAmountCent < 0) {
                    throw new Exception("分账金额计算错误：分账金额(分)={$royaltyAmountCent}, 主体金额(分)={$subjectAmountCent}");
                }
                
                // 验证总额
                if (($royaltyAmountCent + $subjectAmountCent) !== $orderAmountCent) {
                    throw new Exception("分账总额不匹配：订单金额(分)={$orderAmountCent}, 分账总额(分)=" . ($royaltyAmountCent + $subjectAmountCent));
                }
                
                return [
                    'royalty_amount' => $royaltyAmountCent,
                    'subject_amount' => $subjectAmountCent,
                    'payee_type' => OrderRoyalty::PAYEE_TYPE_MERCHANT,
                    'payee_id' => $merchant->id,
                    'payee_name' => $singleRoyalty->payee_name,
                    'payee_account' => $singleRoyalty->payee_account,
                ];
                
            default:
                throw new Exception("未知的分账类型: {$subject->royalty_type}");
        }
    }

    /**
     * 获取并校验启用状态的分账账号
     * @param int|null $agentId
     * @return SingleRoyalty
     * @throws Exception
     */
    private static function getEnabledRoyaltyAccount(?int $agentId): SingleRoyalty
    {
        if (empty($agentId)) {
            throw new Exception('主体未绑定代理商，无法获取分账收款账号');
        }

        // 先查询是否有该代理商的分账账号（不管状态）
        $allRoyaltyAccounts = SingleRoyalty::where('agent_id', $agentId)->get();
        
        if ($allRoyaltyAccounts->isEmpty()) {
            throw new Exception("代理商 {$agentId} 未配置分账收款账号，请先在【单笔分账管理】中添加分账账号");
        }

        // 查询启用状态的分账账号
        $singleRoyalty = SingleRoyalty::where('agent_id', $agentId)
            ->where('status', SingleRoyalty::STATUS_ENABLED)
            ->first();

        if (!$singleRoyalty) {
            // 检查是否有禁用状态的账号
            $disabledAccounts = SingleRoyalty::where('agent_id', $agentId)
                ->where('status', SingleRoyalty::STATUS_DISABLED)
                ->count();
            
            if ($disabledAccounts > 0) {
                throw new Exception("代理商 {$agentId} 有 {$disabledAccounts} 个分账账号，但都处于禁用状态，请在【单笔分账管理】中启用分账账号");
            } else {
                throw new Exception("代理商 {$agentId} 未配置启用状态的分账收款账号，请先在【单笔分账管理】中添加并启用分账账号");
            }
        }

        $missingFields = [];
        if (empty($singleRoyalty->payee_account)) {
            $missingFields[] = 'payee_account';
        }
        if (empty($singleRoyalty->payee_name)) {
            $missingFields[] = 'payee_name';
        }

        if (!empty($missingFields)) {
            $fieldText = implode('/', $missingFields);
            throw new Exception("代理商 {$agentId} 分账收款账号信息不完整：缺少 {$fieldText}，请在【单笔分账管理】中完善账号信息");
        }

        return $singleRoyalty;
    }

    /**
     * 判断失败是否可重试
     */
    private static function isRetryableFailure(?string $subCode, string $errorMessage, array $errorData = []): bool
    {
        if (isset($errorData['retryable'])) {
            return (bool)$errorData['retryable'];
        }

        // 特殊错误码：isv.insufficient-isv-permissions 不可重试，只能人工处理
        $specialErrorCode = 'isv.insufficient-isv-permissions';
        if ($subCode && strtolower($subCode) === strtolower($specialErrorCode)) {
            return false;
        }

        if ($subCode) {
            $upperSubCode = strtoupper($subCode);
            if (in_array($upperSubCode, self::RETRYABLE_SUB_CODES, true)) {
                return true;
            }
        }

        if ($errorMessage) {
            $lowerMessage = strtolower($errorMessage);
            foreach (self::RETRYABLE_ERROR_KEYWORDS as $keyword) {
                if (strpos($lowerMessage, strtolower($keyword)) !== false) {
                    return true;
                }
            }
        }

        return false;
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

    /**
     * 首次分账失败通知
     */
    private static function notifyFirstRoyaltyFailure(
        Order $order,
        OrderRoyalty $royaltyRecord,
        ?string $subCode,
        string $errorMessage,
        array $royaltyData
    ): void {
        $subject = $order->getSubjectEntity();
        $subjectId = $subject ? $subject->id : 0;
        
        // 特殊错误码：isv.insufficient-isv-permissions - 同一主体只推送一次
        $specialErrorCode = 'isv.insufficient-isv-permissions';
        $isSpecialError = $subCode && strtolower($subCode) === strtolower($specialErrorCode);
        
        if ($isSpecialError && $subjectId > 0) {
            // 使用主体ID + 错误码作为缓存键，确保同一主体只推送一次
            $notifyKey = CacheKeys::getSubjectErrorNotifyKey($subjectId, $specialErrorCode);
            $shouldNotify = false;
            
            try {
                // 永久标记（不过期），确保同一主体只推送一次
                $shouldNotify = Redis::set($notifyKey, 1, 'NX');
            } catch (\Throwable $e) {
                Log::channel('royalty')->warning('记录主体错误通知状态失败', [
                    'subject_id' => $subjectId,
                    'error_code' => $specialErrorCode,
                    'error' => $e->getMessage()
                ]);
            }
            
            if (!$shouldNotify) {
                // 已经推送过，不再推送
                Log::channel('royalty')->info('主体错误已推送过，跳过推送', [
                    'subject_id' => $subjectId,
                    'error_code' => $specialErrorCode,
                    'order_id' => $order->id,
                    'platform_order_no' => $order->platform_order_no,
                ]);
                return;
            }
        } else {
            // 普通错误：使用订单ID作为缓存键
            $notifyKey = CacheKeys::getRoyaltyFailureNotifyKey($order->id);
            $shouldNotify = false;

            try {
                $shouldNotify = Redis::set($notifyKey, 1, 'EX', self::FAILURE_NOTIFY_TTL, 'NX');
            } catch (\Throwable $e) {
                Log::channel('royalty')->warning('记录分账失败通知状态失败', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage()
                ]);
            }

            if (!$shouldNotify) {
                return;
            }
        }

        $message = self::buildRoyaltyFailureMessage($order, $royaltyRecord, $subCode, $errorMessage, $royaltyData);
        $queueMessage = TelegramMessageQueueService::addMessage(
            '⚠️ 订单分账失败',
            $message,
            TelegramMessageQueue::PRIORITY_HIGH,
            'html',
            [
                'max_retry' => 3,
            ]
        );

        $logData = [
            'order_id' => $order->id,
            'platform_order_no' => $order->platform_order_no,
            'royalty_id' => $royaltyRecord->id,
            'queue_message_id' => $queueMessage->id ?? null,
            'sub_code' => $subCode,
            'error_message' => $errorMessage,
        ];
        
        if ($isSpecialError && $subjectId > 0) {
            $logData['subject_id'] = $subjectId;
            $logData['notify_type'] = 'subject_error_once';
            Log::channel('royalty')->warning('主体特殊错误首次推送（后续不再推送）', $logData);
        } else {
            Log::channel('royalty')->warning('首次分账失败，已推送失败原因', $logData);
        }
    }

    /**
     * 构建分账失败消息
     */
    private static function buildRoyaltyFailureMessage(
        Order $order,
        OrderRoyalty $royaltyRecord,
        ?string $subCode,
        string $errorMessage,
        array $royaltyData
    ): string {
        $subject = $order->getSubjectEntity();
        $subjectName = $subject ? ($subject->company_name ?? "主体ID: {$subject->id}") : '未知主体';
        $merchantOrderNo = $order->merchant_order_no ?: '-';
        $time = date('Y-m-d H:i:s');
        $reason = $errorMessage ?: '未知错误';
        $subCodeText = $subCode ?: 'UNKNOWN';
        $royaltyAmountCent = $royaltyData['royalty_amount'] ?? $royaltyRecord->royalty_amount ?? 0;
        $royaltyAmount = MoneyHelper::convertToYuan($royaltyAmountCent);

        $message = <<<HTML
⚠️ <b>订单分账失败</b>

━━━━━━━━━━━━━━━━━━━━━

📋 <b>订单信息</b>
• 平台订单号：{$order->platform_order_no}
• 商户订单号：{$merchantOrderNo}
• 订单金额：{$order->order_amount}
• 分账金额：{$royaltyAmount}

🏢 <b>主体信息</b>
• 主体：{$subjectName}

❌ <b>失败原因</b>
• 错误码：<code>{$subCodeText}</code>
• 描述：{$reason}

⏰ <b>时间</b>
{$time}
HTML;

        return $message;
    }
}


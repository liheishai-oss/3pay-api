<?php

namespace app\service;

use app\model\Subject;
use app\model\SubjectCert;
use app\model\Order;
use app\service\alipay\AlipayService;
use app\service\alipay\AlipayComplaintService;
use app\service\alipay\AlipayQueryService;
use app\service\payment\PaymentFactory;
use Exception;
use support\Log;
use support\Db;

/**
 * 投诉处理服务类
 */
class ComplaintService
{
    /**
     * 处理投诉
     * 
     * @param int $complaintId 投诉ID
     * @param string $processCode 处理结果码
     * @param string $feedback 处理反馈内容
     * @param float $refundAmount 退款金额
     * @param int $handlerId 处理人ID
     * @param array $detailIds 选中的投诉详情ID列表（为空则处理所有订单）
     * @return array 处理结果
     */
    public static function handleComplaint(
        int $complaintId,
        string $processCode,
        string $feedback,
        float $refundAmount,
        int $handlerId,
        array $detailIds = []
    ): array {
        try {
            // 1. 查询投诉记录
            $complaint = Db::table('alipay_complaint')->where('id', $complaintId)->first();
            if (!$complaint) {
                return [
                    'success' => false,
                    'message' => '投诉记录不存在'
                ];
            }

            // 2. 获取主体信息
            $subject = Subject::find($complaint->subject_id);
            if (!$subject) {
                return [
                    'success' => false,
                    'message' => '主体不存在'
                ];
            }

            // 3. 第一步：查询投诉详情中的订单信息，并查询支付宝订单状态，保存到日志
            Log::info('投诉处理：第一步 - 开始查询订单信息', [
                'complaint_id' => $complaintId,
                'subject_id' => $subject->id
            ]);

            // 3.1 获取投诉详情（订单列表）
            $details = Db::table('alipay_complaint_detail')
                ->where('complaint_id', $complaint->id)
                ->get();

            if (empty($details)) {
                Log::warning('投诉处理：投诉详情不存在', [
                    'complaint_id' => $complaintId
                ]);
                return [
                    'success' => false,
                    'message' => '投诉详情不存在'
                ];
            }

            // 3.2 获取支付配置
            $paymentConfig = self::getPaymentConfigForRefund($subject);
            if (!$paymentConfig) {
                Log::error('投诉处理：获取支付配置失败', [
                    'complaint_id' => $complaintId,
                    'subject_id' => $subject->id
                ]);
                return [
                    'success' => false,
                    'message' => '获取支付配置失败，无法查询订单'
                ];
            }

            // 3.3 查询每个订单的支付宝状态（只查询第一笔订单，使用 complaint_no）
            $firstDetail = $details[0];
            $orderQueryResults = [];

            if (!empty($firstDetail->complaint_no)) {
                $complaintNo = trim($firstDetail->complaint_no);
                
                Log::info('投诉处理：查询支付宝订单信息', [
                    'complaint_id' => $complaintId,
                    'detail_id' => $firstDetail->id ?? 0,
                    'complaint_no' => $complaintNo,
                    'platform_order_no' => $firstDetail->platform_order_no ?? '',
                    'merchant_order_no' => $firstDetail->merchant_order_no ?? '',
                    'note' => '使用 complaint_no 作为商户订单号(out_trade_no)查询'
                ]);

                try {
                    // 查询支付宝订单状态（使用 complaint_no 作为商户订单号）
                    $orderInfo = AlipayQueryService::queryOrder($complaintNo, $paymentConfig);
                    
                    // 保存查询结果到日志
                    Log::info('投诉处理：支付宝订单查询成功', [
                        'complaint_id' => $complaintId,
                        'detail_id' => $firstDetail->id ?? 0,
                        'complaint_no' => $complaintNo,
                        'order_info' => [
                            'order_number' => $orderInfo['order_number'] ?? '',
                            'trade_no' => $orderInfo['trade_no'] ?? '',
                            'trade_status' => $orderInfo['trade_status'] ?? '',
                            'total_amount' => $orderInfo['total_amount'] ?? '0',
                            'receipt_amount' => $orderInfo['receipt_amount'] ?? '0',
                            'buyer_id' => $orderInfo['buyer_id'] ?? '',
                            'buyer_logon_id' => $orderInfo['buyer_logon_id'] ?? '',
                            'seller_id' => $orderInfo['seller_id'] ?? '',
                            'gmt_payment' => $orderInfo['gmt_payment'] ?? '',
                            'gmt_create' => $orderInfo['gmt_create'] ?? '',
                            'send_pay_date' => $orderInfo['send_pay_date'] ?? ''
                        ],
                        'full_order_info' => $orderInfo // 完整订单信息
                    ]);

                    $orderQueryResults[] = [
                        'complaint_no' => $complaintNo,
                        'success' => true,
                        'order_info' => $orderInfo
                    ];

                } catch (Exception $e) {
                    // 查询失败也记录到日志
                    Log::error('投诉处理：支付宝订单查询失败', [
                        'complaint_id' => $complaintId,
                        'detail_id' => $firstDetail->id ?? 0,
                        'complaint_no' => $complaintNo,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);

                    $orderQueryResults[] = [
                        'complaint_no' => $complaintNo,
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                }
            } else {
                Log::warning('投诉处理：complaint_no 为空，无法查询订单', [
                    'complaint_id' => $complaintId,
                    'detail_id' => $firstDetail->id ?? 0,
                    'platform_order_no' => $firstDetail->platform_order_no ?? '',
                    'merchant_order_no' => $firstDetail->merchant_order_no ?? ''
                ]);

                $orderQueryResults[] = [
                    'complaint_no' => '',
                    'success' => false,
                    'error' => 'complaint_no 为空'
                ];
            }

            // 3.4 记录查询结果汇总
            Log::info('投诉处理：第一步完成 - 订单查询结果汇总', [
                'complaint_id' => $complaintId,
                'query_results' => $orderQueryResults,
                'total_details' => count($details),
                'queried_count' => count($orderQueryResults)
            ]);

            // 3.5 处理退款和完结投诉
            $refundResult = null;
            $finishComplaintResult = null;
            
            // 如果退款金额>0，进行退款处理
            if ($refundAmount > 0 && !empty($orderQueryResults)) {
                $firstQueryResult = $orderQueryResults[0];
                if ($firstQueryResult['success'] && !empty($firstQueryResult['order_info'])) {
                    $orderInfo = $firstQueryResult['order_info'];
                    $orderNumber = $orderInfo['order_number'] ?? ''; // 商户订单号
                    
                    if (!empty($orderNumber)) {
                        // 获取订单总金额
                        $totalAmount = floatval($orderInfo['total_amount'] ?? '0');
                        
                        // 注意：由于支付宝的退款查询API需要退款单号，无法直接查询订单的所有退款记录
                        // 所以无法准确获取累计退款金额
                        // 但是，支付宝在退款时会自动检查退款金额是否超过可退款金额
                        // 如果超过，支付宝会返回错误，所以我们可以直接尝试退款
                        
                        Log::info('投诉处理：退款金额检查', [
                            'complaint_id' => $complaintId,
                            'order_number' => $orderNumber,
                            'total_amount' => $totalAmount,
                            'requested_refund_amount' => $refundAmount,
                            'note' => '支付宝会自动检查退款金额是否超过可退款金额，如果超过会返回错误'
                        ]);
                        
                        // 基本检查：退款金额不能超过订单总金额
                        if ($refundAmount > $totalAmount) {
                            Log::warning('投诉处理：请求退款金额超过订单总金额', [
                                'complaint_id' => $complaintId,
                                'order_number' => $orderNumber,
                                'requested_amount' => $refundAmount,
                                'total_amount' => $totalAmount
                            ]);
                            return [
                                'success' => false,
                                'message' => '退款金额不能超过订单总金额',
                                'query_results' => $orderQueryResults,
                                'refund_result' => null
                            ];
                        }
                        
                        // 进行退款（支付宝会自动检查退款金额是否超过可退款金额）
                        Log::info('投诉处理：开始处理退款', [
                            'complaint_id' => $complaintId,
                            'order_number' => $orderNumber,
                            'refund_amount' => $refundAmount,
                            'total_amount' => $totalAmount,
                            'note' => '支付宝会自动检查退款金额是否超过可退款金额'
                        ]);
                        
                        // 直接使用查询到的订单号进行退款，而不是从投诉详情中获取
                        $refundResult = self::processRefundWithOrderNumber($complaint, $orderNumber, $refundAmount, $paymentConfig);
                        
                        if (!$refundResult['success']) {
                            return [
                                'success' => false,
                                'message' => '处理投诉失败: ' . $refundResult['message'],
                                'query_results' => $orderQueryResults,
                                'refund_result' => $refundResult
                            ];
                        }
                        
                        Log::info('投诉处理：退款处理成功', [
                            'complaint_id' => $complaintId,
                            'order_number' => $orderNumber,
                            'refund_result' => $refundResult,
                            'this_refund_amount' => $refundAmount,
                            'total_amount' => $totalAmount
                        ]);
                    } else {
                        Log::warning('投诉处理：订单号为空，无法处理退款', [
                            'complaint_id' => $complaintId,
                            'order_info' => $orderInfo
                        ]);
                    }
                } else {
                    Log::warning('投诉处理：订单查询失败，无法处理退款', [
                        'complaint_id' => $complaintId,
                        'query_results' => $orderQueryResults
                    ]);
                }
            } else {
                if ($refundAmount <= 0) {
                    Log::info('投诉处理：退款金额为0，跳过退款', [
                        'complaint_id' => $complaintId,
                        'refund_amount' => $refundAmount
                    ]);
                    // 退款金额为0，不需要退款，但需要设置refundResult为成功
                    $refundResult = [
                        'success' => true,
                        'message' => '退款金额为0，无需退款',
                        'refund_amount' => 0,
                        'skipped' => true
                    ];
                } else {
                    Log::warning('投诉处理：订单查询失败，无法处理退款', [
                        'complaint_id' => $complaintId,
                        'query_results' => $orderQueryResults
                    ]);
                }
            }
            
            // 3.6 退款成功（或退款金额为0）后，调用支付宝完结投诉API
            // 注意：即使订单查询失败，如果退款金额为0，也应该完结投诉
            // 使用 alipay_complain_id（complaint_list中的id）调用完结投诉API，而不是 alipay_task_id
            $alipayComplainId = $complaint->alipay_complain_id ?? 0;
            if ($alipayComplainId > 0) {
                // 只有在退款成功、退款金额为0，或者退款金额>0但订单查询失败时，才尝试完结投诉
                $shouldFinishComplaint = false;
                if ($refundAmount <= 0) {
                    // 退款金额为0，直接完结投诉
                    $shouldFinishComplaint = true;
                    if (!$refundResult) {
                        $refundResult = [
                            'success' => true,
                            'message' => '退款金额为0，无需退款',
                            'refund_amount' => 0,
                            'skipped' => true
                        ];
                    }
                } elseif ($refundResult && $refundResult['success']) {
                    // 退款成功，完结投诉
                    $shouldFinishComplaint = true;
                }
                
                if ($shouldFinishComplaint) {
                    try {
                        Log::info('投诉处理：开始完结投诉', [
                            'complaint_id' => $complaintId,
                            'alipay_complain_id' => $alipayComplainId,
                            'alipay_task_id' => $complaint->alipay_task_id,
                            'process_code' => $processCode,
                            'feedback' => $feedback,
                            'refund_amount' => $refundAmount,
                            'refund_success' => $refundResult['success'] ?? false
                        ]);
                        
                        // 调用完结投诉API，传入process_code和remark（feedback）
                        $finishResult = AlipayComplaintService::finishComplaint($subject, $alipayComplainId, $complaint->alipay_task_id, $processCode, $feedback);
                        
                        Log::info('投诉处理：完结投诉成功', [
                            'complaint_id' => $complaintId,
                            'alipay_complain_id' => $alipayComplainId,
                            'alipay_task_id' => $complaint->alipay_task_id,
                            'finish_result' => $finishResult
                        ]);
                        
                        // 将完结结果添加到退款结果中
                        if ($refundResult) {
                            $refundResult['finish_complaint'] = [
                                'success' => true,
                                'result' => $finishResult
                            ];
                        } else {
                            $refundResult = [
                                'success' => true,
                                'finish_complaint' => [
                                    'success' => true,
                                    'result' => $finishResult
                                ]
                            ];
                        }
                        $finishComplaintResult = $finishResult;
                        
                    } catch (Exception $e) {
                        // 完结投诉失败，记录详细错误信息
                        $errorMessage = $e->getMessage();
                        $errorDetails = [
                            'complaint_id' => $complaintId,
                            'alipay_complain_id' => $alipayComplainId,
                            'alipay_task_id' => $complaint->alipay_task_id,
                            'subject_id' => $subject->id,
                            'feedback' => $feedback,
                            'refund_amount' => $refundAmount,
                            'error' => $errorMessage,
                            'error_class' => get_class($e),
                            'trace' => $e->getTraceAsString()
                        ];
                        
                        // 尝试从异常消息中提取更详细的信息
                        if (strpos($errorMessage, 'sub_code') !== false) {
                            // 提取sub_code
                            if (preg_match('/sub_code:\s*([^\s\)]+)/', $errorMessage, $matches)) {
                                $errorDetails['alipay_sub_code'] = $matches[1];
                            }
                        }
                        
                        Log::error('投诉处理：完结投诉失败', $errorDetails);
                        
                        // 将完结失败信息添加到退款结果中
                        if ($refundResult) {
                            $refundResult['finish_complaint'] = [
                                'success' => false,
                                'error' => $errorMessage,
                                'error_details' => $errorDetails
                            ];
                        } else {
                            $refundResult = [
                                'success' => false,
                                'finish_complaint' => [
                                    'success' => false,
                                    'error' => $errorMessage,
                                    'error_details' => $errorDetails
                                ]
                            ];
                        }
                        $finishComplaintResult = [
                            'success' => false,
                            'error' => $errorMessage,
                            'error_details' => $errorDetails
                        ];
                        
                        // 注意：完结投诉失败不影响退款结果，但会影响状态更新
                        // 状态更新需要同时满足退款成功（或跳过）和完结投诉成功
                    }
                } else {
                    Log::info('投诉处理：退款未成功或退款金额>0但订单查询失败，跳过完结投诉', [
                        'complaint_id' => $complaintId,
                        'refund_amount' => $refundAmount,
                        'refund_result' => $refundResult,
                        'note' => '只有退款成功或退款金额为0时才完结投诉'
                    ]);
                }
            } else {
                Log::warning('投诉处理：alipay_complain_id为空或无效，无法完结投诉', [
                    'complaint_id' => $complaintId,
                    'alipay_complain_id' => $alipayComplainId,
                    'alipay_task_id' => $complaint->alipay_task_id ?? '',
                    'note' => '需要使用complaint_list中的id字段（alipay_complain_id）调用完结投诉API，请检查数据是否已同步'
                ]);
                // alipay_complain_id为空时，无法完结投诉，不应该更新状态为PROCESSED
                // 设置finishComplaintResult为失败，确保状态不会被更新
                $finishComplaintResult = [
                    'success' => false,
                    'error' => 'alipay_complain_id为空或无效，无法完结投诉'
                ];
            }

            // 返回查询和退款结果
            // 注意：只有退款成功（或跳过）且完结投诉成功时，才返回成功
            $messageParts = [];
            $isReallySuccess = false;
            
            // 订单查询结果
            if (!empty($orderQueryResults)) {
                $successCount = 0;
                foreach ($orderQueryResults as $queryResult) {
                    if ($queryResult['success'] ?? false) {
                        $successCount++;
                    }
                }
                if ($successCount > 0) {
                    $messageParts[] = "成功查询{$successCount}个订单";
                }
            }
            
            // 退款结果
            if ($refundResult) {
                if (isset($refundResult['skipped']) && $refundResult['skipped']) {
                    // 退款金额为0，跳过退款
                    $messageParts[] = '退款金额为0，无需退款';
                } elseif ($refundResult['success']) {
                    // 退款成功
                    $refundAmount = $refundResult['refund_amount'] ?? 0;
                    if ($refundAmount > 0) {
                        $messageParts[] = "退款成功，金额：{$refundAmount}元";
                    } else {
                        $messageParts[] = '退款处理完成';
                    }
                } else {
                    // 退款失败
                    $errorMsg = $refundResult['message'] ?? '未知错误';
                    $messageParts[] = "退款失败：{$errorMsg}";
                }
                
                // 检查完结投诉结果
                if (isset($refundResult['finish_complaint'])) {
                    if ($refundResult['finish_complaint']['success']) {
                        $messageParts[] = '投诉已完结';
                        // 只有退款成功（或跳过）且完结投诉成功时，才算真正成功
                        $refundOk = ($refundResult['success'] ?? false) || (isset($refundResult['skipped']) && $refundResult['skipped']);
                        $finishOk = $refundResult['finish_complaint']['success'] ?? false;
                        $isReallySuccess = $refundOk && $finishOk;
                    } else {
                        $errorMsg = $refundResult['finish_complaint']['error'] ?? '未知错误';
                        $messageParts[] = "投诉完结失败：{$errorMsg}";
                        $isReallySuccess = false;
                    }
                } elseif (!isset($refundResult['skipped'])) {
                    // 如果有退款结果但没有完结投诉结果，且不是跳过退款的情况
                    $messageParts[] = '投诉未完结';
                    $isReallySuccess = false;
                } else {
                    // 退款金额为0，跳过退款，但没有完结投诉结果
                    $isReallySuccess = false;
                }
            } elseif ($refundAmount <= 0) {
                // 退款金额为0，但没有refundResult的情况
                $messageParts[] = '退款金额为0，无需退款';
                // 需要检查是否有完结投诉结果
                if (!empty($finishComplaintResult) && ($finishComplaintResult['success'] ?? false)) {
                    $messageParts[] = '投诉已完结';
                    $isReallySuccess = true;
                } else {
                    $messageParts[] = '投诉未完结';
                    $isReallySuccess = false;
                }
            }
            
            $successMessage = !empty($messageParts) ? implode('；', $messageParts) : '投诉处理完成';
            
            // 如果投诉未真正处理完成，返回失败
            if (!$isReallySuccess && $shouldUpdateStatus === false) {
                $successMessage = '投诉处理失败：' . $successMessage;
            }
            
            // 4. 更新投诉记录状态（处理成功后才更新）
            // 只有在退款成功或退款金额为0，且完结投诉成功的情况下，才更新状态为PROCESSED
            $shouldUpdateStatus = false;
            $updateData = [
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            // 检查是否应该更新状态
            // 只有在退款成功（或退款金额为0）且完结投诉成功的情况下，才更新状态为PROCESSED
            $refundSuccess = false;
            $refundSkipped = false;
            $finishSuccess = false;
            
            // 检查退款结果
            if ($refundResult) {
                $refundSuccess = $refundResult['success'] ?? false;
                $refundSkipped = $refundResult['skipped'] ?? false;
            } elseif ($refundAmount <= 0) {
                // 退款金额为0，视为跳过退款
                $refundSkipped = true;
            }
            
            // 检查完结投诉结果
            if (isset($refundResult['finish_complaint'])) {
                $finishSuccess = $refundResult['finish_complaint']['success'] ?? false;
                // 额外检查complaint_process_success字段（如果存在）
                if ($finishSuccess && isset($refundResult['finish_complaint']['result']['data']['complaint_process_success'])) {
                    $finishSuccess = (bool)$refundResult['finish_complaint']['result']['data']['complaint_process_success'];
                }
            } elseif ($finishComplaintResult) {
                $finishSuccess = $finishComplaintResult['success'] ?? false;
                // 额外检查complaint_process_success字段（如果存在）
                if ($finishSuccess && isset($finishComplaintResult['data']['complaint_process_success'])) {
                    $finishSuccess = (bool)$finishComplaintResult['data']['complaint_process_success'];
                }
            }
            
            Log::info('投诉处理：状态更新条件检查', [
                'complaint_id' => $complaintId,
                'refund_success' => $refundSuccess,
                'refund_skipped' => $refundSkipped,
                'finish_success' => $finishSuccess,
                'refund_amount' => $refundAmount,
                'has_refund_result' => !empty($refundResult),
                'has_finish_complaint_result' => !empty($finishComplaintResult),
                'note' => '只有退款成功（或跳过）且完结投诉成功时才更新状态为PROCESSED'
            ]);
            
            // 如果退款成功（或跳过）且完结投诉成功，则更新状态
            if (($refundSuccess || $refundSkipped) && $finishSuccess) {
                $shouldUpdateStatus = true;
                $updateData['complaint_status'] = 'PROCESSED';
                $updateData['process_code'] = $processCode; // 保存处理结果码
                $updateData['merchant_feedback'] = $feedback;
                $updateData['feedback_time'] = date('Y-m-d H:i:s');
                $updateData['handler_id'] = $handlerId;
                if ($refundAmount > 0 && $refundSuccess) {
                    // 累计退款金额：获取当前退款金额，加上本次退款金额
                    $currentRefundAmount = floatval($complaint->refund_amount ?? 0);
                    $newRefundAmount = $currentRefundAmount + $refundAmount;
                    // 获取投诉总金额（从详情表聚合）
                    $totalComplaintAmount = Db::table('alipay_complaint_detail')
                        ->where('complaint_id', $complaintId)
                        ->sum('complaint_amount');
                    $totalComplaintAmount = floatval($totalComplaintAmount ?? 0);
                    // 确保累计退款金额不超过投诉总金额
                    $newRefundAmount = min($newRefundAmount, $totalComplaintAmount);
                    $updateData['refund_amount'] = $newRefundAmount;
                    
                    Log::info('投诉处理：累计更新退款金额', [
                        'complaint_id' => $complaintId,
                        'current_refund_amount' => $currentRefundAmount,
                        'this_refund_amount' => $refundAmount,
                        'new_refund_amount' => $newRefundAmount,
                        'total_complaint_amount' => $totalComplaintAmount
                    ]);
                }
            } else {
                // 不满足更新条件，记录详细日志
                Log::warning('投诉处理：未满足更新状态条件，投诉未真正处理完成', [
                    'complaint_id' => $complaintId,
                    'refund_success' => $refundSuccess,
                    'refund_skipped' => $refundSkipped,
                    'finish_success' => $finishSuccess,
                    'refund_amount' => $refundAmount,
                    'refund_result' => $refundResult,
                    'finish_complaint_result' => $finishComplaintResult,
                    'note' => '只有退款成功（或退款金额为0）且完结投诉成功时才更新状态为PROCESSED。当前状态：退款=' . ($refundSuccess ? '成功' : ($refundSkipped ? '跳过' : '失败')) . '，完结=' . ($finishSuccess ? '成功' : '失败')
                ]);
            }
            
            // 更新数据库
            if ($shouldUpdateStatus) {
                try {
                    Db::table('alipay_complaint')
                        ->where('id', $complaintId)
                        ->update($updateData);
                    
                    Log::info('投诉处理：更新投诉状态成功', [
                        'complaint_id' => $complaintId,
                        'status' => 'PROCESSED',
                        'refund_amount' => $updateData['refund_amount'] ?? $refundAmount,
                        'current_refund_amount' => $updateData['refund_amount'] ?? 0,
                        'handler_id' => $handlerId
                    ]);
                } catch (Exception $e) {
                    Log::error('投诉处理：更新投诉状态失败', [
                        'complaint_id' => $complaintId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    // 状态更新失败不影响处理结果，只记录日志
                }
            } else {
                Log::warning('投诉处理：未满足更新状态条件', [
                    'complaint_id' => $complaintId,
                    'refund_result' => $refundResult,
                    'finish_complaint_result' => $finishComplaintResult,
                    'refund_amount' => $refundAmount,
                    'note' => '只有退款成功（或退款金额为0）且完结投诉成功时才更新状态'
                ]);
            }
            
            // 返回处理结果
            // 只有退款成功（或跳过）且完结投诉成功时，才返回success: true
            // 如果状态未更新（shouldUpdateStatus = false），说明投诉未真正处理完成，应该返回失败
            $returnSuccess = $shouldUpdateStatus; // 只有状态更新为PROCESSED时，才算真正成功
            
            return [
                'success' => $returnSuccess,
                'message' => $successMessage,
                'query_results' => $orderQueryResults,
                'refund_result' => $refundResult,
                'finish_complaint_result' => $finishComplaintResult,
                'status_updated' => $shouldUpdateStatus,
                'refund_success' => $refundSuccess || $refundSkipped,
                'finish_success' => $finishSuccess
            ];

            /* ========== 以下代码暂时注释，仅用于查询订单状态 ==========
            
            // 4. 查询支付宝投诉状态
            $alipayStatus = null;
            $alipayTradeList = [];
            try {
                $alipayStatusResult = AlipayComplaintService::queryComplaintDetail($subject, $complaint->alipay_task_id);
                if ($alipayStatusResult['success']) {
                    $alipayStatus = $alipayStatusResult['data']['status'] ?? '';
                    $alipayTradeList = $alipayStatusResult['data']['trade_list'] ?? [];
                }
            } catch (Exception $e) {
                Log::warning('查询支付宝投诉状态失败', [
                    'complaint_id' => $complaintId,
                    'alipay_task_id' => $complaint->alipay_task_id,
                    'error' => $e->getMessage()
                ]);
            }

            // 4. 计算最大退款金额
            $maxRefundAmount = 0;
            if (!empty($alipayTradeList)) {
                // 从支付宝返回的订单列表中计算总投诉金额
                foreach ($alipayTradeList as $trade) {
                    $complaintAmount = isset($trade['complaint_amount']) ? floatval($trade['complaint_amount']) : 0;
                    if ($complaintAmount > 0) {
                        $maxRefundAmount += $complaintAmount;
                    } else {
                        $amount = isset($trade['amount']) ? floatval($trade['amount']) : 0;
                        $maxRefundAmount += $amount;
                    }
                }
            } else {
                // 如果没有支付宝订单列表，从本地投诉详情表中计算
                $details = Db::table('alipay_complaint_detail')
                    ->where('complaint_id', $complaint->id)
                    ->get();
                foreach ($details as $detail) {
                    $complaintAmount = isset($detail->complaint_amount) ? floatval($detail->complaint_amount) : 0;
                    if ($complaintAmount > 0) {
                        $maxRefundAmount += $complaintAmount;
                    } else {
                        $orderAmount = isset($detail->order_amount) ? floatval($detail->order_amount) : 0;
                        $maxRefundAmount += $orderAmount;
                    }
                }
            }

            // 限制退款金额不超过最大投诉金额
            $refundAmountToProcess = min($refundAmount, $maxRefundAmount);

            Log::info('投诉处理：开始处理投诉', [
                'complaint_id' => $complaintId,
                'process_code' => $processCode,
                'feedback' => $feedback,
                'refund_amount' => $refundAmount,
                'max_refund_amount' => $maxRefundAmount,
                'refund_amount_to_process' => $refundAmountToProcess,
                'alipay_status' => $alipayStatus,
                'detail_ids' => $detailIds
            ]);

            // 5. 如果需要退款，处理退款
            $refundResult = null;
            if ($refundAmountToProcess > 0) {
                // 检查支付宝状态：如果状态为未处理，才进行退款
                if (empty($alipayStatus) || $alipayStatus === 'WAIT_PROCESS' || $alipayStatus === 'PROCESSING') {
                    $refundResult = self::processRefund($complaint, $refundAmountToProcess, $detailIds);
                    if (!$refundResult['success']) {
                        return [
                            'success' => false,
                            'message' => '处理投诉失败: ' . $refundResult['message']
                        ];
                    }
                } else {
                    Log::info('投诉处理：支付宝状态不允许退款', [
                        'complaint_id' => $complaintId,
                        'alipay_status' => $alipayStatus,
                        'refund_amount' => $refundAmountToProcess
                    ]);
                }
            }

            // 6. 完结支付宝投诉
            try {
                AlipayComplaintService::finishComplaint($subject, $complaint->alipay_task_id, $feedback);
            } catch (Exception $e) {
                Log::warning('完结支付宝投诉失败', [
                    'complaint_id' => $complaintId,
                    'alipay_task_id' => $complaint->alipay_task_id,
                    'error' => $e->getMessage()
                ]);
                // 完结失败不影响处理结果，继续更新本地记录
            }

            // 7. 更新投诉记录
            // 累计更新退款金额
            $currentRefundAmount = floatval($complaint->refund_amount ?? 0);
            $newRefundAmount = $currentRefundAmount;
            if ($refundAmountToProcess > 0 && $refundResult && $refundResult['success']) {
                // 如果退款成功，累计退款金额
                $newRefundAmount = $currentRefundAmount + $refundAmountToProcess;
                // 获取投诉总金额（从详情表聚合）
                $totalComplaintAmount = Db::table('alipay_complaint_detail')
                    ->where('complaint_id', $complaintId)
                    ->sum('complaint_amount');
                $totalComplaintAmount = floatval($totalComplaintAmount ?? 0);
                // 确保累计退款金额不超过投诉总金额
                $newRefundAmount = min($newRefundAmount, $totalComplaintAmount);
                
                Log::info('投诉处理：累计更新退款金额（路径2）', [
                    'complaint_id' => $complaintId,
                    'current_refund_amount' => $currentRefundAmount,
                    'this_refund_amount' => $refundAmountToProcess,
                    'new_refund_amount' => $newRefundAmount,
                    'total_complaint_amount' => $totalComplaintAmount
                ]);
            }
            
            Db::table('alipay_complaint')->where('id', $complaintId)->update([
                'complaint_status' => 'PROCESSED',
                'process_code' => $processCode, // 保存处理结果码
                'refund_amount' => $newRefundAmount,
                'merchant_feedback' => $feedback,
                'feedback_time' => date('Y-m-d H:i:s'),
                'handler_id' => $handlerId,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            Log::info('投诉处理：投诉处理成功', [
                'complaint_id' => $complaintId,
                'process_code' => $processCode,
                'refund_amount' => $refundAmountToProcess,
                'refund_result' => $refundResult
            ]);

            return [
                'success' => true,
                'message' => '处理成功',
                'refund_result' => $refundResult
            ];
            
            ========== 以上代码暂时注释，仅用于查询订单状态 ========== */

        } catch (Exception $e) {
            Log::error('处理投诉失败', [
                'complaint_id' => $complaintId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'success' => false,
                'message' => '处理投诉失败: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 使用指定订单号处理退款（用于投诉处理中的退款）
     * 
     * @param object $complaint 投诉记录
     * @param string $orderNumber 订单号（商户订单号）
     * @param float $refundAmount 退款金额
     * @param array $paymentConfig 支付配置
     * @return array 退款结果
     */
    private static function processRefundWithOrderNumber($complaint, string $orderNumber, float $refundAmount, array $paymentConfig): array
    {
        try {
            if (empty($orderNumber)) {
                return [
                    'success' => false,
                    'message' => '订单号为空，无法退款'
                ];
            }

            if ($refundAmount <= 0) {
                return [
                    'success' => false,
                    'message' => '退款金额必须大于0'
                ];
            }

            $alipayService = new AlipayService();
            
            // 生成退款单号（使用订单号_时间戳_随机数，确保每次退款都有唯一的退款单号）
            // 这样即使有多笔退款，每笔退款都有独立的退款单号，可以分别查询
            $refundNumber = $orderNumber . '_' . time() . '_' . mt_rand(1000, 9999);

            Log::info('投诉退款：准备调用支付宝退款API', [
                'complaint_id' => $complaint->id,
                'order_number' => $orderNumber,
                'refund_amount' => $refundAmount,
                'refund_number' => $refundNumber,
                'note' => '使用查询到的订单号进行退款，退款单号使用唯一标识（订单号_时间戳_随机数）'
            ]);

            $refundInfo = [
                'order_number' => $orderNumber, // out_trade_no: 商户订单号
                'refund_amount' => number_format($refundAmount, 2, '.', ''),
                'refund_number' => $refundNumber, // out_request_no: 退款请求号（使用订单号）
                'refund_reason' => '投诉处理退款：' . ($complaint->complaint_reason ?? '')
            ];

            $refundResult = $alipayService->createRefund($refundInfo, $paymentConfig);
            
            Log::info('投诉退款：支付宝退款API调用成功', [
                'complaint_id' => $complaint->id,
                'order_number' => $orderNumber,
                'refund_result' => $refundResult
            ]);

            // 退款成功后，更新本地订单状态（如果订单存在）
            try {
                $order = Order::where('merchant_order_no', $orderNumber)
                    ->orWhere('platform_order_no', $orderNumber)
                    ->first();

                if ($order) {
                    // 更新订单状态为已退款
                    Order::where('id', $order->id)->update([
                        'pay_status' => Order::PAY_STATUS_REFUNDED,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                    Log::info('投诉退款：本地订单状态更新成功', [
                        'complaint_id' => $complaint->id,
                        'order_id' => $order->id,
                        'order_number' => $orderNumber
                    ]);
                } else {
                    Log::warning('投诉退款：本地订单不存在，但退款已成功', [
                        'complaint_id' => $complaint->id,
                        'order_number' => $orderNumber,
                        'note' => '退款已成功，但本地订单不存在，无法更新订单状态'
                    ]);
                }
            } catch (Exception $e) {
                // 更新本地订单状态失败不影响退款结果
                Log::warning('投诉退款：更新本地订单状态失败，但退款已成功', [
                    'complaint_id' => $complaint->id,
                    'order_number' => $orderNumber,
                    'error' => $e->getMessage()
                ]);
            }

            return [
                'success' => true,
                'message' => '退款处理成功',
                'refund_amount' => $refundAmount,
                'refund_number' => $refundNumber,
                'refund_result' => $refundResult
            ];

        } catch (Exception $e) {
            Log::error('投诉退款：订单退款失败', [
                'complaint_id' => $complaint->id,
                'order_number' => $orderNumber ?? '',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'success' => false,
                'message' => '退款失败: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 处理退款（直接使用投诉详情中的订单号调用退款接口）
     * 
     * @param object $complaint 投诉记录
     * @param float $refundAmount 退款金额
     * @param array $detailIds 选中的投诉详情ID列表（为空则处理所有订单）
     * @return array 退款结果
     */
    private static function processRefund($complaint, float $refundAmount, array $detailIds = []): array
    {
        try {
            // 1. 查询投诉详情（订单列表）
            $query = Db::table('alipay_complaint_detail')
                ->where('complaint_id', $complaint->id);
            
            // 如果指定了选中的订单ID列表，只处理选中的订单
            if (!empty($detailIds) && is_array($detailIds)) {
                $query->whereIn('id', $detailIds);
                Log::info('投诉退款：只处理选中的订单', [
                    'complaint_id' => $complaint->id,
                    'selected_detail_ids' => $detailIds,
                    'selected_count' => count($detailIds)
                ]);
            } else {
                Log::info('投诉退款：处理所有订单', [
                    'complaint_id' => $complaint->id,
                    'note' => '未指定detail_ids，处理所有订单'
                ]);
            }
            
            $details = $query->get();

            if (empty($details)) {
                Log::error('投诉退款：投诉详情不存在', [
                    'complaint_id' => $complaint->id,
                    'subject_id' => $complaint->subject_id
                ]);
                return [
                    'success' => false,
                    'message' => '投诉详情不存在，无法退款'
                ];
            }

            // 2. 获取主体信息
            $subject = Subject::find($complaint->subject_id);
            if (!$subject) {
                return [
                    'success' => false,
                    'message' => '主体不存在，无法退款'
                ];
            }

            // 3. 获取支付配置
            $paymentConfig = self::getPaymentConfigForRefund($subject);
            if (!$paymentConfig) {
                return [
                    'success' => false,
                    'message' => '获取支付配置失败，无法退款'
                ];
            }

            // 4. 计算总投诉金额（用于按比例分配退款金额）
            $totalComplaintAmount = 0;
            foreach ($details as $detail) {
                $complaintAmount = isset($detail->complaint_amount) ? floatval($detail->complaint_amount) : 0;
                if ($complaintAmount > 0) {
                    $totalComplaintAmount += $complaintAmount;
                } else {
                    // 如果投诉金额为0，使用订单金额
                    $orderAmount = isset($detail->order_amount) ? floatval($detail->order_amount) : 0;
                    $totalComplaintAmount += $orderAmount;
                }
            }

            Log::info('投诉退款：开始处理退款（直接使用订单号，只处理第一笔）', [
                'complaint_id' => $complaint->id,
                'refund_amount' => $refundAmount,
                'total_complaint_amount' => $totalComplaintAmount,
                'details_count' => count($details)
            ]);

            // 5. 只处理第一笔订单（不循环处理）
            if (empty($details)) {
                return [
                    'success' => false,
                    'message' => '没有可退款的订单'
                ];
            }

            $detail = $details[0]; // 只取第一笔订单
            $refundResults = [];
            $alipayService = new AlipayService();
            $totalRefunded = 0;
            $successCount = 0;
            $failCount = 0;

            try {
                // 只使用 platform_order_no（平台订单号）
                $tradeNo = '';
                if (!empty($detail->platform_order_no)) {
                    $tradeNo = trim($detail->platform_order_no);
                }

                if (empty($tradeNo)) {
                    Log::warning('投诉退款：platform_order_no 为空', [
                        'complaint_id' => $complaint->id,
                        'detail_id' => $detail->id ?? 0,
                        'platform_order_no' => $detail->platform_order_no ?? ''
                    ]);
                    return [
                        'success' => false,
                        'message' => 'platform_order_no 为空'
                    ];
                }

                // 计算退款金额：直接使用传入的退款金额（不按比例分配，因为只处理单笔）
                $orderRefundAmount = $refundAmount;
                
                // 不能超过订单金额
                $orderAmount = isset($detail->order_amount) ? floatval($detail->order_amount) : 0;
                if ($orderAmount > 0) {
                    $orderRefundAmount = min($orderRefundAmount, $orderAmount);
                }

                if ($orderRefundAmount <= 0) {
                    Log::info('投诉退款：退款金额为0', [
                        'complaint_id' => $complaint->id,
                        'detail_id' => $detail->id ?? 0,
                        'platform_order_no' => $tradeNo,
                        'order_amount' => $orderAmount,
                        'calculated_refund_amount' => $orderRefundAmount
                    ]);
                    return [
                        'success' => false,
                        'message' => '退款金额为0'
                    ];
                }

                // 生成退款单号（使用平台订单号作为退款请求号）
                $refundNumber = $tradeNo; // 用户要求：out_request_no 参数为订单号（使用 platform_order_no）

                // 调用退款接口（直接使用平台订单号）
                Log::info('投诉退款：准备调用支付宝退款API', [
                    'complaint_id' => $complaint->id,
                    'detail_id' => $detail->id ?? 0,
                    'platform_order_no' => $tradeNo,
                    'refund_amount' => $orderRefundAmount,
                    'refund_number' => $refundNumber,
                    'note' => '使用 platform_order_no 作为 out_trade_no 和 out_request_no，只处理单笔订单'
                ]);

                $refundInfo = [
                    'order_number' => $tradeNo, // out_trade_no: 平台订单号（platform_order_no）
                    'refund_amount' => number_format($orderRefundAmount, 2, '.', ''),
                    'refund_number' => $refundNumber, // out_request_no: 退款请求号（使用 platform_order_no）
                    'refund_reason' => '投诉处理退款：' . ($complaint->complaint_reason ?? '')
                ];

                $refundResult = $alipayService->createRefund($refundInfo, $paymentConfig);
                
                Log::info('投诉退款：支付宝退款API调用成功', [
                    'complaint_id' => $complaint->id,
                    'detail_id' => $detail->id ?? 0,
                    'platform_order_no' => $tradeNo,
                    'refund_result' => $refundResult
                ]);

                // 退款成功后，更新本地订单状态（如果订单存在）
                // 尝试查找订单并更新状态，但不影响退款结果
                try {
                    $order = null;
                    // 只通过 platform_order_no 查找
                    if (!empty($tradeNo)) {
                        $order = Order::where('platform_order_no', $tradeNo)->first();
                    }

                    if ($order) {
                        // 更新订单状态为已退款
                        Order::where('id', $order->id)->update([
                            'pay_status' => Order::PAY_STATUS_REFUNDED,
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                        Log::info('投诉退款：本地订单状态更新成功', [
                            'complaint_id' => $complaint->id,
                            'order_id' => $order->id,
                            'platform_order_no' => $tradeNo
                        ]);
                    } else {
                        Log::warning('投诉退款：本地订单不存在，但退款已成功', [
                            'complaint_id' => $complaint->id,
                            'platform_order_no' => $tradeNo,
                            'note' => '退款已成功，但本地订单不存在，无法更新订单状态'
                        ]);
                    }
                } catch (Exception $e) {
                    // 更新本地订单状态失败不影响退款结果
                    Log::warning('投诉退款：更新本地订单状态失败，但退款已成功', [
                        'complaint_id' => $complaint->id,
                        'platform_order_no' => $tradeNo,
                        'error' => $e->getMessage()
                    ]);
                }

                $totalRefunded = $orderRefundAmount;
                $successCount = 1;
                $refundResults[] = [
                    'order_no' => $tradeNo,
                    'success' => true,
                    'refund_amount' => $orderRefundAmount,
                    'refund_number' => $refundNumber,
                    'refund_result' => $refundResult
                ];

                Log::info('投诉退款：订单退款成功', [
                    'complaint_id' => $complaint->id,
                    'detail_id' => $detail->id ?? 0,
                    'platform_order_no' => $tradeNo,
                    'refund_amount' => $orderRefundAmount,
                    'refund_number' => $refundNumber
                ]);

            } catch (Exception $e) {
                Log::error('投诉退款：订单退款失败', [
                    'complaint_id' => $complaint->id,
                    'detail_id' => $detail->id ?? 0,
                    'platform_order_no' => $tradeNo ?? '',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $failCount = 1;
                $refundResults[] = [
                    'order_no' => $tradeNo ?? '',
                    'success' => false,
                    'message' => '退款失败: ' . $e->getMessage()
                ];
            }

            // 6. 返回处理结果
            if ($successCount == 0 && $failCount > 0) {
                return [
                    'success' => false,
                    'message' => '所有订单退款失败',
                    'refund_results' => $refundResults,
                    'success_count' => $successCount,
                    'fail_count' => $failCount,
                    'total_refunded' => $totalRefunded
                ];
            }

            if ($successCount == 0) {
                return [
                    'success' => false,
                    'message' => '没有可退款的订单',
                    'refund_results' => $refundResults,
                    'success_count' => $successCount,
                    'fail_count' => $failCount,
                    'total_refunded' => $totalRefunded
                ];
            }

            return [
                'success' => true,
                'message' => '退款处理完成',
                'refund_results' => $refundResults,
                'total_refunded' => $totalRefunded,
                'success_count' => $successCount,
                'fail_count' => $failCount
            ];

        } catch (Exception $e) {
            Log::error('处理退款失败', [
                'complaint_id' => $complaint->id,
                'refund_amount' => $refundAmount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'success' => false,
                'message' => '处理退款失败: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 获取退款所需的支付配置
     * 
     * @param Subject $subject 主体
     * @return array|null 支付配置
     */
    private static function getPaymentConfigForRefund(Subject $subject): ?array
    {
        try {
            // 获取证书信息
            $cert = SubjectCert::where('subject_id', $subject->id)->first();
            if (!$cert) {
                Log::error('投诉退款：主体证书配置缺失', [
                    'subject_id' => $subject->id
                ]);
                return null;
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

            return $config;

        } catch (Exception $e) {
            Log::error('获取支付配置失败', [
                'subject_id' => $subject->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 获取证书文件路径，如果文件不存在则从数据库内容创建临时文件
     * 
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
}
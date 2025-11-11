<?php

namespace app\admin\controller\v1;

use app\model\Order;
use app\model\Agent;
use app\model\Merchant;
use support\Request;

class OrderController
{
    /**
     * 订单列表
     */
    public function index(Request $request)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;
        
        $page = $request->get('page', 1);
        $limit = $request->get('page_size', $request->get('limit', 20));
        
        // 支持search参数（JSON格式）
        $searchJson = $request->get('search', '');
        $searchParams = [];
        if ($searchJson) {
            $searchParams = json_decode($searchJson, true) ?: [];
        }
        
        // 优先从search参数中获取，否则从直接参数获取
        $platformOrderNo = $searchParams['platform_order_no'] ?? $request->get('platform_order_no', '');
        $merchantOrderNo = $searchParams['merchant_order_no'] ?? $request->get('merchant_order_no', '');
        $alipayOrderNo = $searchParams['alipay_order_no'] ?? $request->get('alipay_order_no', '');
        $merchantId = $searchParams['merchant_id'] ?? $request->get('merchant_id', '');
        $agentId = $searchParams['agent_id'] ?? $request->get('agent_id', '');
        $payStatus = $searchParams['pay_status'] ?? $request->get('pay_status', '');
        $notifyStatus = $searchParams['notify_status'] ?? $request->get('notify_status', '');
        $startTime = $searchParams['start_time'] ?? $request->get('start_time', '');
        $endTime = $searchParams['end_time'] ?? $request->get('end_time', '');

        $query = Order::with(['merchant', 'agent', 'product', 'subject', 'royaltyRecord']);

        // 记录调试信息
        \support\Log::info('订单列表查询', [
            'user_group_id' => $userData['user_group_id'] ?? 'null',
            'isAgent' => $isAgent,
            'agent_id_from_user' => $userData['agent_id'] ?? 'null',
            'agent_id_from_search' => $agentId,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);

        // 代理商只能看自己的数据
//        if ($isAgent) {
//            $query->where('agent_id', $userData['agent_id']);
//        } elseif ($agentId) {
//            $query->where('agent_id', $agentId);
//        }

        if ($platformOrderNo) {
            $query->where('platform_order_no', 'like', '%' . $platformOrderNo . '%');
        }

        if ($merchantOrderNo) {
            $query->where('merchant_order_no', 'like', '%' . $merchantOrderNo . '%');
        }

        if ($alipayOrderNo) {
            $query->where('alipay_order_no', 'like', '%' . $alipayOrderNo . '%');
        }

        if ($merchantId) {
            $query->where('merchant_id', $merchantId);
        }

        if ($payStatus !== '') {
            $query->where('pay_status', $payStatus);
        }

        if ($notifyStatus !== '') {
            $query->where('notify_status', $notifyStatus);
        }

        if ($startTime) {
            $query->where('created_at', '>=', $startTime);
        }

        if ($endTime) {
            $query->where('created_at', '<=', $endTime);
        }

        $total = $query->count();
        
        // 记录SQL查询
        $sql = $query->toSql();
        $bindings = $query->getBindings();
        \support\Log::info('订单查询SQL', [
            'sql' => $sql,
            'bindings' => $bindings,
            'total' => $total
        ]);
        
        $list = $query->orderBy('id', 'desc')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get();

        // 处理分账状态显示
        $list = $list->map(function ($order) {
            $orderArray = $order->toArray();
            
            // 计算分账状态显示文本
            $royaltyStatusText = '-';
            $royaltyRecord = $order->royaltyRecord;
            
            if ($order->pay_status == Order::PAY_STATUS_PAID) {
                // 订单已支付
                if ($royaltyRecord) {
                    // 有分账记录
                if ($royaltyRecord->royalty_status == \app\model\OrderRoyalty::ROYALTY_STATUS_SUCCESS) {
                    // 分账成功
                    if ($order->subject && $order->subject->royalty_type == 'single') {
                        $royaltyStatusText = '单笔分账';
                    } elseif ($order->subject && $order->subject->royalty_type == 'merchant') {
                        $royaltyStatusText = '商家分账';
                    } else {
                        $royaltyStatusText = '分账成功';
                    }
                    } elseif ($royaltyRecord->royalty_status == \app\model\OrderRoyalty::ROYALTY_STATUS_PENDING) {
                        // 待分账
                        $royaltyStatusText = '待分账';
                    } elseif ($royaltyRecord->royalty_status == \app\model\OrderRoyalty::ROYALTY_STATUS_PROCESSING) {
                        // 分账中
                        $royaltyStatusText = '分账中';
                    } elseif ($royaltyRecord->royalty_status == \app\model\OrderRoyalty::ROYALTY_STATUS_FAILED) {
                        // 分账失败
                        $royaltyStatusText = '分账失败';
                    }
                } else {
                    // 没有分账记录，检查是否需要分账
                    if ($order->subject && $order->subject->royalty_type != 'none') {
                        // 需要分账但还没有分账记录，显示待分账
                        $royaltyStatusText = '待分账';
                    }
                }
            }
            
            $orderArray['royalty_status_text'] = $royaltyStatusText;
            $orderArray['royalty_record'] = $royaltyRecord ? $royaltyRecord->toArray() : null;
            
            return $orderArray;
        })->toArray();

        \support\Log::info('订单查询结果', [
            'list_count' => count($list),
            'total' => $total
        ]);

        return success([
            'data' => $list,
            'total' => $total,
            'current_page' => (int)$page,
            'per_page' => (int)$limit
        ]);
    }

    /**
     * 订单详情
     */
    public function detail(Request $request, $id)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;

        $query = Order::with(['merchant', 'agent', 'product', 'subject', 'royaltyRecord']);

        // 代理商只能看自己的数据
        if ($isAgent) {
            $query->where('agent_id', $userData['agent_id']);
        }

        $order = $query->find($id);

        if (!$order) {
            return error('订单不存在');
        }

        $orderArray = $order->toArray();
        
        // 计算分账状态显示文本（与列表逻辑一致）
        $royaltyStatusText = '-';
        $royaltyRecord = $order->royaltyRecord;
        
        if ($order->pay_status == Order::PAY_STATUS_PAID) {
            // 订单已支付
            if ($royaltyRecord) {
                // 有分账记录
                if ($royaltyRecord->royalty_status == \app\model\OrderRoyalty::ROYALTY_STATUS_SUCCESS) {
                    // 分账成功
                    if ($order->subject && $order->subject->royalty_type == 'single') {
                        $royaltyStatusText = '单笔分账';
                    } elseif ($order->subject && $order->subject->royalty_type == 'merchant') {
                        $royaltyStatusText = '商家分账';
                    } else {
                        $royaltyStatusText = '分账成功';
                    }
                } elseif ($royaltyRecord->royalty_status == \app\model\OrderRoyalty::ROYALTY_STATUS_PENDING) {
                    // 待分账
                    $royaltyStatusText = '待分账';
                } elseif ($royaltyRecord->royalty_status == \app\model\OrderRoyalty::ROYALTY_STATUS_PROCESSING) {
                    // 分账中
                    $royaltyStatusText = '分账中';
                } elseif ($royaltyRecord->royalty_status == \app\model\OrderRoyalty::ROYALTY_STATUS_FAILED) {
                    // 分账失败
                    $royaltyStatusText = '分账失败';
                }
            } else {
                // 没有分账记录，检查是否需要分账
                if ($order->subject && $order->subject->royalty_type != 'none') {
                    // 需要分账但还没有分账记录，显示待分账
                    $royaltyStatusText = '待分账';
                }
            }
        }
        
        $orderArray['royalty_status_text'] = $royaltyStatusText;
        $orderArray['royalty_record'] = $royaltyRecord ? $royaltyRecord->toArray() : null;

        return success($orderArray);
    }

    /**
     * 获取代理商列表（供管理员选择）
     */
    public function getAgentList(Request $request)
    {
        $list = Agent::where('status', Agent::STATUS_ENABLED)
            ->select('id', 'agent_name')
            ->orderBy('id', 'desc')
            ->get()
            ->toArray();

        return success($list);
    }

    /**
     * 获取商户列表（根据代理商）
     */
    public function getMerchantList(Request $request)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;
        $agentId = $request->get('agent_id', '');

        $query = Merchant::where('status', Merchant::STATUS_ENABLED);

        // 代理商只能看自己的商户
        if ($isAgent) {
            $query->where('agent_id', $userData['agent_id']);
        } elseif ($agentId) {
            $query->where('agent_id', $agentId);
        }

        $list = $query->select('id', 'merchant_name')
            ->orderBy('id', 'desc')
            ->get()
            ->toArray();

        return success($list);
    }

    /**
     * 订单统计
     */
    public function statistics(Request $request)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;
        
        // 支持search参数（JSON格式）
        $searchJson = $request->get('search', '');
        $searchParams = [];
        if ($searchJson) {
            $searchParams = json_decode($searchJson, true) ?: [];
        }
        
        $agentId = $searchParams['agent_id'] ?? $request->get('agent_id', '');
        $startTime = $searchParams['start_time'] ?? $request->get('start_time', '');
        $endTime = $searchParams['end_time'] ?? $request->get('end_time', '');

        $query = Order::query();

        // 代理商只能看自己的数据
        if ($isAgent) {
            $query->where('agent_id', $userData['agent_id']);
        } elseif ($agentId) {
            $query->where('agent_id', $agentId);
        }

        if ($startTime) {
            $query->where('created_at', '>=', $startTime);
        }

        if ($endTime) {
            $query->where('created_at', '<=', $endTime);
        }

        // 总订单数
        $totalCount = (clone $query)->count();

        // 各状态订单数
        $createdCount = (clone $query)->where('pay_status', Order::PAY_STATUS_CREATED)->count();
        $openedCount = (clone $query)->where('pay_status', Order::PAY_STATUS_OPENED)->count();
        $paidCount = (clone $query)->where('pay_status', Order::PAY_STATUS_PAID)->count();
        $closedCount = (clone $query)->where('pay_status', Order::PAY_STATUS_CLOSED)->count();
        $refundedCount = (clone $query)->where('pay_status', Order::PAY_STATUS_REFUNDED)->count();

        // 总金额统计
        $totalAmount = (clone $query)->sum('order_amount');
        $paidAmount = (clone $query)->where('pay_status', Order::PAY_STATUS_PAID)->sum('order_amount');

        // 成功率计算
        $successRate = $totalCount > 0 ? round(($paidCount / $totalCount) * 100, 2) : 0;

        return success([
            'total_count' => $totalCount,
            'created_count' => $createdCount,
            'opened_count' => $openedCount,
            'paid_count' => $paidCount,
            'closed_count' => $closedCount,
            'refunded_count' => $refundedCount,
            'total_amount' => round($totalAmount, 2),
            'paid_amount' => round($paidAmount, 2),
            'success_rate' => $successRate,
        ]);
    }

    /**
     * 管理员手动补单接口
     * 根据订单主体查询支付宝订单状态并补单
     */
    public function supplement(Request $request)
    {
        $platformOrderNo = $request->post('platform_order_no');
        if (empty($platformOrderNo)) {
            return error('缺少平台订单号');
        }
        
        $order = \app\model\Order::where('platform_order_no', $platformOrderNo)->first();
        if (!$order) {
            return error('订单不存在');
        }
        
        // 调用补单服务
        $result = \app\service\OrderSupplementService::supplementOrder(
            $order,
            $request->getRealIp(),
            $request->header('user-agent', ''),
            true // 手动补单
        );
        
        if ($result['success']) {
            return success($result['data'], $result['message']);
        } else {
            return error($result['message'], 400);
        }
    }

    /**
     * 一键批量补单接口
     * 不跳过任何订单，所有订单都查询支付宝状态，如果支付宝已支付则纠正本地状态
     */
    public function reissue(Request $request)
    {
        $ids = $request->post('ids', []);
        if (empty($ids) || !is_array($ids)) {
            return error('请选择要补单的订单');
        }

        $orders = Order::whereIn('id', $ids)->get();
        if ($orders->isEmpty()) {
            return error('订单不存在');
        }

        $successCount = 0;
        $failedCount = 0;
        $skippedCount = 0;
        $correctedCount = 0; // 状态纠正计数
        $results = [];

        foreach ($orders as $order) {
            // 记录补单前的状态
            $oldPayStatus = $order->pay_status;
            $oldStatusText = $this->getPayStatusText($oldPayStatus);
            
            // 调用补单服务（批量补单时，isManual设为true，不受本地状态限制）
            $result = \app\service\OrderSupplementService::supplementOrder(
                $order,
                $request->getRealIp(),
                $request->header('user-agent', ''),
                true // 手动补单，不受本地状态限制
            );

            if ($result['success']) {
                $successCount++;
                
                // 判断是否纠正了状态
                $order->refresh(); // 刷新订单数据
                $newPayStatus = $order->pay_status;
                $isCorrected = ($oldPayStatus != Order::PAY_STATUS_PAID && $newPayStatus == Order::PAY_STATUS_PAID);
                
                if ($isCorrected) {
                    $correctedCount++;
                }
                
                $results[] = [
                    'order_id' => $order->id,
                    'platform_order_no' => $order->platform_order_no,
                    'status' => 'success',
                    'message' => $result['message'],
                    'old_status' => $oldStatusText,
                    'new_status' => $this->getPayStatusText($newPayStatus),
                    'status_corrected' => $isCorrected
                ];
            } else {
                // 检查是否是支付宝未支付的情况（这是正常的，不算失败）
                $errorMsg = $result['message'] ?? '';
                if (strpos($errorMsg, '支付宝订单未支付') !== false || 
                    strpos($errorMsg, '无需补单') !== false) {
                    $skippedCount++;
                    $results[] = [
                        'order_id' => $order->id,
                        'platform_order_no' => $order->platform_order_no,
                        'status' => 'skipped',
                        'message' => $result['message'],
                        'old_status' => $oldStatusText
                    ];
                } else {
                    $failedCount++;
                    $results[] = [
                        'order_id' => $order->id,
                        'platform_order_no' => $order->platform_order_no,
                        'status' => 'failed',
                        'message' => $result['message'],
                        'old_status' => $oldStatusText
                    ];
                }
            }

            // 避免频繁请求，每处理一个订单延迟100ms
            usleep(100000);
        }

        $summary = "批量补单完成：成功 {$successCount} 条";
        if ($correctedCount > 0) {
            $summary .= "（其中 {$correctedCount} 条已纠正状态）";
        }
        $summary .= "，失败 {$failedCount} 条，跳过 {$skippedCount} 条";

        return success([
            'total' => count($orders),
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'skipped_count' => $skippedCount,
            'corrected_count' => $correctedCount,
            'results' => $results
        ], $summary);
    }
    
    /**
     * 获取支付状态文本
     */
    private function getPayStatusText($status): string
    {
        $statusMap = [
            Order::PAY_STATUS_CREATED => '已创建',
            Order::PAY_STATUS_OPENED => '已打开',
            Order::PAY_STATUS_PAID => '已支付',
            Order::PAY_STATUS_CLOSED => '已关闭',
            Order::PAY_STATUS_REFUNDED => '已退款'
        ];
        return $statusMap[$status] ?? '未知';
    }

    /**
     * 管理员手动回调接口（支持单个和批量）
     */
    public function manualCallback(Request $request)
    {
        // 支持批量回调：传入 ids 数组
        $ids = $request->post('ids', []);
        // 支持单个回调：传入 platform_order_no
        $platformOrderNo = $request->post('platform_order_no', '');
        
        // 批量回调模式
        if (!empty($ids) && is_array($ids)) {
            $orders = Order::whereIn('id', $ids)->get();
            if ($orders->isEmpty()) {
                return error('订单不存在');
            }

            $successCount = 0;
            $failedCount = 0;
            $skippedCount = 0;
            $results = [];

            foreach ($orders as $order) {
                // 跳过无回调地址的订单
                if (empty($order->notify_url)) {
                    $skippedCount++;
                    $results[] = [
                        'order_id' => $order->id,
                        'platform_order_no' => $order->platform_order_no,
                        'status' => 'skipped',
                        'message' => '订单无回调地址，跳过'
                    ];
                    continue;
                }

                try {
                    \app\service\OrderLogService::log(
                        $order->trace_id ?? '',
                        $order->platform_order_no,
                        $order->merchant_order_no,
                        '批量回调',
                        'INFO',
                        '管理员批量回调',
                        ['action' => 'batch callback', 'notify_url' => $order->notify_url],
                        $request->getRealIp(),
                        $request->header('user-agent', '')
                    );
                    $res = \app\service\MerchantNotifyService::send($order, [], ['manual' => true]);
                    
                    if ($res['success']) {
                        $successCount++;
                        $results[] = [
                            'order_id' => $order->id,
                            'platform_order_no' => $order->platform_order_no,
                            'status' => 'success',
                            'message' => '回调成功'
                        ];
                    } else {
                        $failedCount++;
                        $results[] = [
                            'order_id' => $order->id,
                            'platform_order_no' => $order->platform_order_no,
                            'status' => 'failed',
                            'message' => '回调失败：' . $res['message']
                        ];
                    }
                } catch (\Exception $e) {
                    $failedCount++;
                    $results[] = [
                        'order_id' => $order->id,
                        'platform_order_no' => $order->platform_order_no,
                        'status' => 'failed',
                        'message' => '回调异常：' . $e->getMessage()
                    ];
                }

                // 避免频繁请求，每处理一个订单延迟50ms
                usleep(50000);
            }

            return success([
                'total' => count($orders),
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'skipped_count' => $skippedCount,
                'results' => $results
            ], "批量回调完成：成功 {$successCount} 条，失败 {$failedCount} 条，跳过 {$skippedCount} 条");
        }
        
        // 单个回调模式
        if (empty($platformOrderNo)) {
            return error('缺少平台订单号或订单ID列表');
        }
        
        $order = Order::where('platform_order_no', $platformOrderNo)->first();
        if (!$order) {
            return error('订单不存在');
        }
        if (empty($order->notify_url)) {
            return error('订单notify_url未设置');
        }
        // 手动回调逻辑：复用统一商户回调服务
        try {
            \app\service\OrderLogService::log(
                $order->trace_id ?? '',
                $platformOrderNo,
                $order->merchant_order_no,
                '手动回调',
                'INFO',
                '管理员手动回调',
                ['action' => 'manual callback', 'notify_url' => $order->notify_url],
                $request->getRealIp(),
                $request->header('user-agent', '')
            );
            $res = \app\service\MerchantNotifyService::send($order, [], ['manual' => true]);
            if ($res['success']) {
                return success(['notify_url' => $order->notify_url], '手动回调成功');
            }
            return error('手动回调失败：' . $res['message']);
        } catch (\Exception $e) {
            return error('手动回调失败：' . $e->getMessage());
        }
    }
}


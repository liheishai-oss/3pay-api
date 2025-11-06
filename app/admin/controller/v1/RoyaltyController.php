<?php

namespace app\admin\controller\v1;

use app\model\OrderRoyalty;
use app\model\Order;
use app\service\royalty\RoyaltyService;
use support\Request;

class RoyaltyController
{
    /**
     * 分账记录列表
     */
    public function index(Request $request)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;
        
        $page = $request->get('page', 1);
        $limit = $request->get('page_size', $request->get('limit', 20));
        
        // 搜索参数
        $searchJson = $request->get('search', '');
        $searchParams = [];
        if ($searchJson) {
            $searchParams = json_decode($searchJson, true) ?: [];
        }
        
        $platformOrderNo = $searchParams['platform_order_no'] ?? $request->get('platform_order_no', '');
        $tradeNo = $searchParams['trade_no'] ?? $request->get('trade_no', '');
        $royaltyStatus = $searchParams['royalty_status'] ?? $request->get('royalty_status', '');
        $royaltyType = $searchParams['royalty_type'] ?? $request->get('royalty_type', '');
        $startTime = $searchParams['start_time'] ?? $request->get('start_time', '');
        $endTime = $searchParams['end_time'] ?? $request->get('end_time', '');
        $agentId = $searchParams['agent_id'] ?? $request->get('agent_id', '');

        $query = OrderRoyalty::with(['order.agent', 'order.merchant', 'subject']);

        // 代理商只能看自己的数据（通过订单关联）
        if ($isAgent) {
            $query->whereHas('order', function($q) use ($userData) {
                $q->where('agent_id', $userData['agent_id']);
            });
        } elseif ($agentId) {
            $query->whereHas('order', function($q) use ($agentId) {
                $q->where('agent_id', $agentId);
            });
        }

        if ($platformOrderNo) {
            $query->where('platform_order_no', 'like', '%' . $platformOrderNo . '%');
        }

        if ($tradeNo) {
            $query->where('trade_no', 'like', '%' . $tradeNo . '%');
        }

        if ($royaltyStatus !== '') {
            $query->where('royalty_status', $royaltyStatus);
        }

        if ($royaltyType) {
            $query->where('royalty_type', $royaltyType);
        }

        if ($startTime) {
            $query->where('created_at', '>=', $startTime);
        }

        if ($endTime) {
            $query->where('created_at', '<=', $endTime);
        }

        $total = $query->count();
        
        $list = $query->orderBy('id', 'desc')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get()
            ->toArray();

        return success([
            'data' => $list,
            'total' => $total,
            'current_page' => (int)$page,
            'per_page' => (int)$limit
        ]);
    }

    /**
     * 分账记录详情
     */
    public function detail(Request $request, $id)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;

        $query = OrderRoyalty::with(['order.agent', 'order.merchant', 'order.product', 'subject']);

        // 代理商只能看自己的数据
        if ($isAgent) {
            $query->whereHas('order', function($q) use ($userData) {
                $q->where('agent_id', $userData['agent_id']);
            });
        }

        $royalty = $query->find($id);

        if (!$royalty) {
            return error('分账记录不存在');
        }

        // 解析支付宝返回结果
        $alipayResult = [];
        if (!empty($royalty->alipay_result)) {
            $alipayResult = json_decode($royalty->alipay_result, true) ?: [];
        }

        $data = $royalty->toArray();
        $data['alipay_result_parsed'] = $alipayResult;

        return success($data);
    }

    /**
     * 重试分账
     */
    public function retry(Request $request, $id)
    {
        $userData = $request->userData;
        
        $royalty = OrderRoyalty::find($id);
        if (!$royalty) {
            return error('分账记录不存在');
        }

        // 只有失败的分账才能重试
        if (!$royalty->isFailed()) {
            return error('只能重试失败的分账记录，当前状态：' . $royalty->getStatusText());
        }

        $result = RoyaltyService::retryRoyalty($id, $request->getRealIp());

        if ($result['success']) {
            return success($result['data'], '重试成功');
        } else {
            return error($result['message'], 400);
        }
    }

    /**
     * 批量重试分账
     */
    public function batchRetry(Request $request)
    {
        $ids = $request->post('ids', []);
        if (empty($ids) || !is_array($ids)) {
            return error('请选择要重试的分账记录');
        }

        $royalties = OrderRoyalty::whereIn('id', $ids)
            ->where('royalty_status', OrderRoyalty::ROYALTY_STATUS_FAILED)
            ->get();

        if ($royalties->isEmpty()) {
            return error('没有可重试的分账记录');
        }

        $successCount = 0;
        $failedCount = 0;
        $results = [];

        foreach ($royalties as $royalty) {
            $result = RoyaltyService::retryRoyalty($royalty->id, $request->getRealIp());
            
            if ($result['success']) {
                $successCount++;
                $results[] = [
                    'id' => $royalty->id,
                    'platform_order_no' => $royalty->platform_order_no,
                    'status' => 'success',
                    'message' => $result['message']
                ];
            } else {
                $failedCount++;
                $results[] = [
                    'id' => $royalty->id,
                    'platform_order_no' => $royalty->platform_order_no,
                    'status' => 'failed',
                    'message' => $result['message']
                ];
            }

            // 避免频繁请求
            usleep(200000);
        }

        return success([
            'total' => count($royalties),
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'results' => $results
        ], "批量重试完成：成功 {$successCount} 条，失败 {$failedCount} 条");
    }
}




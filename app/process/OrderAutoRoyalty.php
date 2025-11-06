<?php

namespace app\process;

use Workerman\Timer;
use app\model\Order;
use app\model\OrderRoyalty;
use app\service\royalty\RoyaltyService;
use support\Log;

/**
 * 订单自动分账任务
 * 定期扫描已支付但未分账的订单，自动触发分账
 */
class OrderAutoRoyalty
{
    /**
     * 每次处理的订单数量
     */
    const BATCH_SIZE = 50;

    /**
     * 查询多少分钟前支付的订单（避免查询刚支付的订单，等待回调处理）
     */
    const MINUTES_AFTER_PAY = 5;

    /**
     * Worker启动时执行
     */
    public function onWorkerStart(): void
    {
        // 每10分钟执行一次自动分账
        Timer::add(600, function () {
            $this->processAutoRoyalty();
        });

        Log::info('订单自动分账任务已启动', [
            'interval' => '10分钟',
            'batch_size' => self::BATCH_SIZE,
            'minutes_after_pay' => self::MINUTES_AFTER_PAY
        ]);
    }

    /**
     * 处理自动分账
     */
    private function processAutoRoyalty(): void
    {
        try {
            Log::info('开始执行自动分账任务', [
                'batch_size' => self::BATCH_SIZE,
                'minutes_after_pay' => self::MINUTES_AFTER_PAY
            ]);

            // 查询已支付但未分账的订单
            $cutoffTime = date('Y-m-d H:i:s', time() - self::MINUTES_AFTER_PAY * 60);
            
            $orders = Order::with(['subject', 'product'])
                ->where('pay_status', Order::PAY_STATUS_PAID)
                ->where('pay_time', '<=', $cutoffTime)
                ->whereDoesntHave('royaltyRecords', function ($query) {
                    $query->where('royalty_status', OrderRoyalty::ROYALTY_STATUS_SUCCESS);
                })
                ->limit(self::BATCH_SIZE)
                ->get();

            $total = $orders->count();
            $successCount = 0;
            $failedCount = 0;
            $skippedCount = 0;

            foreach ($orders as $order) {
                try {
                    // 检查是否需要分账
                    if (!$order->needsRoyalty()) {
                        $skippedCount++;
                        continue;
                    }

                    // 触发分账
                    $result = RoyaltyService::processRoyalty($order, 'SYSTEM', 'AutoRoyalty');

                    if ($result['success']) {
                        $successCount++;
                        Log::info('自动分账成功', [
                            'order_id' => $order->id,
                            'platform_order_no' => $order->platform_order_no,
                            'royalty_amount' => $result['data']['royalty_amount'] ?? 0
                        ]);
                    } else {
                        $failedCount++;
                        Log::warning('自动分账失败', [
                            'order_id' => $order->id,
                            'platform_order_no' => $order->platform_order_no,
                            'reason' => $result['message'] ?? '未知原因'
                        ]);
                    }

                } catch (\Throwable $e) {
                    $failedCount++;
                    Log::error('自动分账处理异常', [
                        'order_id' => $order->id ?? 0,
                        'platform_order_no' => $order->platform_order_no ?? '',
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }

            Log::info('自动分账任务执行完成', [
                'total' => $total,
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'skipped_count' => $skippedCount
            ]);

        } catch (\Throwable $e) {
            Log::error('自动分账任务执行失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}




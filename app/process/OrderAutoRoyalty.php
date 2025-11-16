<?php

namespace app\process;

use Workerman\Worker;
use Workerman\Crontab\Crontab;
use app\model\Order;
use app\model\OrderRoyalty;
use app\service\royalty\RoyaltyService;
use app\common\constants\CacheKeys;
use support\Log;
use support\Redis;

/**
 * 订单自动分账任务
 * 秒级处理：每1秒检查Redis队列中的分账任务并立即处理
 * 兜底处理：每10分钟扫描一次已支付但未分账的订单（防止队列丢失）
 */
class OrderAutoRoyalty
{
    /**
     * 每次从队列取出的订单数量
     */
    const QUEUE_BATCH_SIZE = 10;

    /**
     * 每次扫描的订单数量（兜底处理）
     */
    const BATCH_SIZE = 50;

    /**
     * 查询多少分钟前支付的订单（兜底处理，避免查询刚支付的订单）
     */
    const MINUTES_AFTER_PAY = 5;

    /**
     * Worker启动时执行
     */
    public function onWorkerStart(Worker $worker)
    {
        // 每1秒检查一次Redis队列（秒级处理）
        new Crontab('* * * * * *', function () {
            $this->processRoyaltyQueue();
        });

        // 每10分钟执行一次兜底扫描（防止队列丢失的情况）
        new Crontab('*/10 * * * *', function () {
            $this->processAutoRoyalty();
        });

        Log::info('订单自动分账任务已启动', [
            'queue_interval' => '每1秒',
            'fallback_interval' => '每10分钟',
            'queue_batch_size' => self::QUEUE_BATCH_SIZE,
            'fallback_batch_size' => self::BATCH_SIZE
        ]);
    }

    /**
     * 处理Redis队列中的分账任务（秒级处理）
     */
    private function processRoyaltyQueue(): void
    {
        try {
            $queueKey = CacheKeys::getRoyaltyQueueKey();
            $processedCount = 0;

            // 从队列中取出订单ID（批量处理）
            for ($i = 0; $i < self::QUEUE_BATCH_SIZE; $i++) {
                $orderIdJson = Redis::rpop($queueKey);
                
                if (empty($orderIdJson)) {
                    break; // 队列为空，退出
                }

                $orderData = json_decode($orderIdJson, true);
                if (!$orderData || !isset($orderData['order_id'])) {
                    Log::warning('分账队列数据格式错误', ['data' => $orderIdJson]);
                    continue;
                }

                $orderId = $orderData['order_id'];
                $operatorIp = $orderData['operator_ip'] ?? 'SYSTEM';
                $operatorAgent = $orderData['operator_agent'] ?? 'RoyaltyQueue';

                // 检查是否正在处理（防止并发）
                $processingKey = CacheKeys::getRoyaltyProcessingKey($orderId);
                if (Redis::get($processingKey)) {
                    // 如果正在处理，将任务重新放回队列
                    Redis::lpush($queueKey, $orderIdJson);
                    continue;
                }

                // 设置处理中标记（5分钟过期）
                Redis::setex($processingKey, 300, '1');

                try {
                    // 加载订单数据
                    $order = Order::with(['subject', 'product'])->find($orderId);
                    if (!$order) {
                        Log::warning('分账队列中的订单不存在', ['order_id' => $orderId]);
                        Redis::del($processingKey);
                        continue;
                    }

                    // 检查是否需要分账
                    if (!$order->needsRoyalty()) {
                        Log::debug('订单不需要分账', [
                            'order_id' => $orderId,
                            'platform_order_no' => $order->platform_order_no
                        ]);
                        Redis::del($processingKey);
                        continue;
                    }

                    // 触发分账
                    $result = RoyaltyService::processRoyalty($order, $operatorIp, $operatorAgent);

                    if ($result['success']) {
                        $processedCount++;
                        Log::channel('royalty')->info('队列分账成功', [
                            'order_id' => $orderId,
                            'platform_order_no' => $order->platform_order_no,
                            'royalty_amount' => $result['data']['royalty_amount'] ?? 0
                        ]);
                    } else {
                        Log::channel('royalty')->warning('队列分账失败', [
                            'order_id' => $orderId,
                            'platform_order_no' => $order->platform_order_no,
                            'reason' => $result['message'] ?? '未知原因'
                        ]);
                    }

                } catch (\Throwable $e) {
                    Log::channel('royalty')->error('队列分账处理异常', [
                        'order_id' => $orderId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                } finally {
                    // 清除处理中标记
                    Redis::del($processingKey);
                }
            }

            if ($processedCount > 0) {
                Log::channel('royalty')->info('分账队列处理完成', ['processed_count' => $processedCount]);
            }

        } catch (\Throwable $e) {
            Log::channel('royalty')->error('分账队列处理失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 处理自动分账（兜底处理，防止队列丢失）
     * 
     * 兜底机制的作用：
     * 1. 防止Redis队列丢失（Redis故障、重启等情况）
     * 2. 防止入队失败（网络抖动、Redis连接失败等）
     * 3. 防止进程重启导致队列任务丢失
     * 4. 确保所有已支付订单最终都能被分账处理
     * 
     * 工作原理：
     * - 每10分钟扫描一次数据库
     * - 查找已支付超过5分钟但未分账的订单
     * - 对这些订单进行分账处理
     * - 延迟5分钟是为了避免与队列处理冲突（队列是秒级处理）
     */
    private function processAutoRoyalty(): void
    {
        try {
            Log::info('开始执行自动分账兜底任务', [
                'batch_size' => self::BATCH_SIZE,
                'minutes_after_pay' => self::MINUTES_AFTER_PAY,
                'note' => '兜底机制：扫描数据库中已支付但未分账的订单，防止队列丢失的情况'
            ]);

            // 查询已支付但未分账的订单（支付时间超过5分钟，避免与队列处理冲突）
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
                        Log::info('兜底分账成功', [
                            'order_id' => $order->id,
                            'platform_order_no' => $order->platform_order_no,
                            'royalty_amount' => $result['data']['royalty_amount'] ?? 0
                        ]);
                    } else {
                        $failedCount++;
                        Log::warning('兜底分账失败', [
                            'order_id' => $order->id,
                            'platform_order_no' => $order->platform_order_no,
                            'reason' => $result['message'] ?? '未知原因'
                        ]);
                    }

                } catch (\Throwable $e) {
                    $failedCount++;
                    Log::error('兜底分账处理异常', [
                        'order_id' => $order->id ?? 0,
                        'platform_order_no' => $order->platform_order_no ?? '',
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }

            if ($total > 0) {
                Log::info('自动分账兜底任务执行完成', [
                    'total' => $total,
                    'success_count' => $successCount,
                    'failed_count' => $failedCount,
                    'skipped_count' => $skippedCount
                ]);
            }

        } catch (\Throwable $e) {
            Log::error('自动分账兜底任务执行失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}




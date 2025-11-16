<?php

namespace app\process;

use Workerman\Timer;
use app\model\Order;
use app\service\MerchantNotifyService;
use app\service\OrderLogService;
use support\Log;
use support\Db;

/**
 * 订单自动补发回调任务
 * 定期扫描已支付但回调失败的订单，自动重试回调
 */
class OrderAutoNotifyRetry
{
    /**
     * 每次处理的订单数量
     */
    const BATCH_SIZE = 50;

    /**
     * Worker启动时执行
     */
    public function onWorkerStart(): void
    {
        // 每3分钟执行一次自动补发回调
        Timer::add(180, function () {
            $this->processAutoNotifyRetry();
        });
    }

    /**
     * 处理自动补发回调
     */
    private function processAutoNotifyRetry(): void
    {
        try {
            Log::info('开始执行自动补发回调任务', [
                'batch_size' => self::BATCH_SIZE
            ]);

            // 查询需要补发回调的订单：
            // 1. 已支付状态
            // 2. 回调状态为失败或待通知
            // 3. 回调次数未达到上限（5次）
            // 4. 有回调地址
            $orders = Order::where('pay_status', Order::PAY_STATUS_PAID)
                ->whereIn('notify_status', [Order::NOTIFY_STATUS_PENDING, Order::NOTIFY_STATUS_FAILED])
                ->where(function($query) {
                    $query->where('notify_times', '<', 5)
                          ->orWhereNull('notify_times');
                })
                ->whereNotNull('notify_url')
                ->where('notify_url', '!=', '')
                ->limit(self::BATCH_SIZE)
                ->get();

            if ($orders->isEmpty()) {
                Log::debug('自动补发回调任务：无待补发订单');
                return;
            }

            $successCount = 0;
            $failedCount = 0;
            $skippedCount = 0;

            foreach ($orders as $order) {
                try {
                    // 检查是否需要跳过（例如熔断状态会在MerchantNotifyService内部判断）
                    $result = MerchantNotifyService::send($order, [], ['manual' => false]);

                    if ($result['success']) {
                        $successCount++;
                        Log::channel('notify')->info('自动补发回调成功', [
                            'order_id' => $order->id,
                            'platform_order_no' => $order->platform_order_no,
                            'notify_url' => $order->notify_url
                        ]);

                        // 记录链路日志
                        OrderLogService::log(
                            $order->trace_id ?? '',
                            $order->platform_order_no,
                            $order->merchant_order_no,
                            '自动补发回调',
                            'INFO',
                            '节点33-自动补发回调成功',
                            [
                                'action' => '自动补发回调成功',
                                'notify_times' => $order->notify_times ?? 0,
                                'operator_ip' => 'SYSTEM'
                            ],
                            'SYSTEM',
                            ''
                        );
                    } else {
                        // 判断是否需要跳过（熔断、超限等）
                        $message = $result['message'] ?? '';
                        if (strpos($message, 'circuit') !== false || 
                            strpos($message, 'retry exceeded') !== false ||
                            strpos($message, 'empty notify_url') !== false) {
                            $skippedCount++;
                            Log::debug('自动补发回调跳过', [
                                'order_id' => $order->id,
                                'reason' => $message
                            ]);
                        } else {
                            $failedCount++;
                            Log::warning('自动补发回调失败', [
                                'order_id' => $order->id,
                                'platform_order_no' => $order->platform_order_no,
                                'error' => $message
                            ]);

                            // 记录链路日志
                            OrderLogService::log(
                                $order->trace_id ?? '',
                                $order->platform_order_no,
                                $order->merchant_order_no,
                                '自动补发回调',
                                'WARN',
                                '节点33-自动补发回调失败',
                                [
                                    'action' => '自动补发回调失败',
                                    'error' => $message,
                                    'notify_times' => $order->notify_times ?? 0,
                                    'operator_ip' => 'SYSTEM'
                                ],
                                'SYSTEM',
                                ''
                            );
                        }
                    }

                    // 避免频繁请求，每处理一个订单延迟100ms
                    usleep(100000);

                } catch (\Throwable $e) {
                    $failedCount++;
                    Log::error('自动补发回调异常', [
                        'order_id' => $order->id,
                        'platform_order_no' => $order->platform_order_no,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }

            $result = [
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'skipped_count' => $skippedCount,
                'total' => $orders->count()
            ];

            Log::info('自动补发回调任务执行完成', $result);

        } catch (\Throwable $e) {
            Log::error('自动补发回调任务执行失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}




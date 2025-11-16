<?php

namespace app\process;

use Workerman\Timer;
use support\Db;
use support\Log;
use app\model\Order;

/**
 * 订单未拉起自动关闭任务
 * 定期扫描超过1小时未拉起的订单（状态为已创建）并关闭
 */
class OrderUnopenedClose
{
    /**
     * Worker启动时执行
     */
    public function onWorkerStart(): void
    {
        Log::info('订单未拉起自动关闭任务进程已启动');
        
        // 立即执行一次（用于测试和快速响应）
        $this->processUnopenedClose();
        
        // 每5分钟检查一次超过1小时未拉起的订单并关闭
        Timer::add(300, function () {
            $this->processUnopenedClose();
        });
    }

    /**
     * 处理未拉起订单关闭
     */
    private function processUnopenedClose(): void
    {
        $now = date('Y-m-d H:i:s');
        $oneHourAgo = date('Y-m-d H:i:s', strtotime('-1 hour'));
        
        try {
            Log::debug('开始扫描未拉起订单', [
                'current_time' => $now,
                'one_hour_ago' => $oneHourAgo,
                'status' => Order::PAY_STATUS_CREATED
            ]);
            
            // 查询状态为已创建且创建时间超过1小时的订单
            $unopenedOrders = Db::table('order')
                ->where('pay_status', Order::PAY_STATUS_CREATED) // 只扫描已创建状态的订单（未拉起）
                ->where('created_at', '<', $oneHourAgo) // 创建时间超过1小时
                ->get();

            if ($unopenedOrders->isEmpty()) {
                Log::debug('未找到符合条件的未拉起订单');
                return;
            }

            Log::info('找到未拉起订单', [
                'count' => $unopenedOrders->count()
            ]);

            $closedCount = 0;
            $skippedCount = 0;

            foreach ($unopenedOrders as $order) {
                // 再次检查订单状态，确保订单仍然是已创建状态（防止并发访问支付页面）
                $currentOrder = Db::table('order')
                    ->where('id', $order->id)
                    ->where('pay_status', Order::PAY_STATUS_CREATED)
                    ->first();

                if (!$currentOrder) {
                    // 订单状态已改变（可能已打开或已支付），跳过
                    $skippedCount++;
                    Log::debug('未拉起订单关闭跳过：订单状态已改变', [
                        'order_id' => $order->id,
                        'platform_order_no' => $order->platform_order_no,
                        'current_status' => Db::table('order')->where('id', $order->id)->value('pay_status')
                    ]);
                    continue;
                }

                // 更新订单状态为已关闭
                $updated = Db::table('order')
                    ->where('id', $order->id)
                    ->where('pay_status', Order::PAY_STATUS_CREATED) // 双重检查，确保状态未变
                    ->update([
                        'pay_status' => Order::PAY_STATUS_CLOSED,
                        'close_time' => $now,
                        'updated_at' => $now,
                    ]);

                if ($updated > 0) {
                    $closedCount++;
                    // 写链路日志
                    \app\service\OrderLogService::log(
                        isset($order->trace_id) ? $order->trace_id : '',
                        $order->platform_order_no,
                        $order->merchant_order_no,
                        '关闭',
                        'INFO',
                        '节点20-订单关闭',
                        [
                            'action' => '订单关闭',
                            'close_source' => 'UnopenedCloseProcess',
                            'operator_ip' => 'SYSTEM',
                            'close_time' => $now,
                            'created_at' => $order->created_at,
                            'reason' => '超过1小时未拉起'
                        ],
                        'SYSTEM',
                        ''
                    );
                } else {
                    $skippedCount++;
                    Log::debug('未拉起订单关闭跳过：状态检查失败', [
                        'order_id' => $order->id,
                        'platform_order_no' => $order->platform_order_no
                    ]);
                }
            }

            if ($closedCount > 0) {
                Log::channel('order')->info('未拉起订单自动关闭任务执行', [
                    'closed_count' => $closedCount,
                    'skipped_count' => $skippedCount,
                    'time' => $now,
                ]);
            }
        } catch (\Throwable $e) {
            Log::channel('order')->error('未拉起订单自动关闭任务失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}


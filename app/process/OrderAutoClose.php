<?php

namespace app\process;

use Workerman\Timer;
use support\Db;
use support\Log;
use app\model\Order;

class OrderAutoClose
{
    public function onWorkerStart(): void
    {
        // 每3秒检查一次超时未支付订单并关闭
        Timer::add(3, function () {
            $now = date('Y-m-d H:i:s');
            try {
                // 查询已打开且已过期的订单
                $expiredOrders = Db::table('order')
                    ->where('pay_status', Order::PAY_STATUS_OPENED) // 只扫描已打开状态的订单
                    ->where('expire_time', '<', $now)
                    ->get();

                if ($expiredOrders->isEmpty()) {
                    return;
                }

                $closedCount = 0;
                $skippedCount = 0;

                foreach ($expiredOrders as $order) {
                    // 再次检查订单状态，确保订单仍然是已打开状态（防止并发支付成功）
                    $currentOrder = Db::table('order')
                        ->where('id', $order->id)
                        ->where('pay_status', Order::PAY_STATUS_OPENED)
                        ->first();

                    if (!$currentOrder) {
                        // 订单状态已改变（可能已支付），跳过
                        $skippedCount++;
                        Log::debug('订单自动关闭跳过：订单状态已改变', [
                            'order_id' => $order->id,
                            'platform_order_no' => $order->platform_order_no,
                            'current_status' => Db::table('order')->where('id', $order->id)->value('pay_status')
                        ]);
                        continue;
                    }

                    // 更新订单状态为已关闭
                    $updated = Db::table('order')
                        ->where('id', $order->id)
                        ->where('pay_status', Order::PAY_STATUS_OPENED) // 双重检查，确保状态未变
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
                                'close_source' => 'AutoCloseProcess',
                                'operator_ip' => 'SYSTEM',
                                'close_time' => $now,
                                'expire_time' => $order->expire_time
                            ],
                            'SYSTEM',
                            null
                        );
                    } else {
                        $skippedCount++;
                        Log::debug('订单自动关闭跳过：状态检查失败', [
                            'order_id' => $order->id,
                            'platform_order_no' => $order->platform_order_no
                        ]);
                    }
                }

                if ($closedCount > 0) {
                    Log::info('订单自动关闭任务执行', [
                        'closed_count' => $closedCount,
                        'skipped_count' => $skippedCount,
                        'time' => $now,
                    ]);

                    // 日统计自动关闭订单，如超20单自动Telegram告警，可用订单表分组count实现，仅主进程每天00:00执行
                    if (date('H:i')==="00:00" && $worker->id===0) {
                        $yesterday = date('Y-m-d',strtotime('-1 day'));
                        $count = Db::table('order')->where('close_time','>=',$yesterday." 00:00:00")
                            ->where('close_time','<=',$yesterday." 23:59:59")
                            ->where('pay_status', Order::PAY_STATUS_CLOSED)->count();
                        if ($count > 20) {
                            \app\service\OrderAlertService::sendAsyncTelegram('订单自动关闭异常高发：昨日关闭'.$count.'单', 'P2');
                            \app\service\OrderLogService::log('','','','自动关闭', 'WARN', '节点32-关闭异常告警', ['action'=>'关闭超限','count'=>$count], 'SYSTEM', null);
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::error('订单自动关闭任务失败', [
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}



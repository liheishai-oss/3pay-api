<?php

namespace app\process;

use Workerman\Timer;
use support\Db;
use support\Log;

class OrderAutoClose
{
    public function onWorkerStart(): void
    {
        // 每3秒检查一次超时未支付订单并关闭
        Timer::add(3, function () {
            $now = date('Y-m-d H:i:s');
            try {
                $affected = Db::table('order')
                    ->where('pay_status', 0) // 待支付
                    ->where('expire_time', '<', $now)
                    ->update([
                        'pay_status' => 2, // 已关闭
                        'close_time' => $now,
                        'updated_at' => $now,
                    ]);

                if ($affected > 0) {
                    // 写链路日志，每单一条
                    $closedOrders = Db::table('order')
                        ->where('pay_status', 2)
                        ->where('close_time', $now)
                        ->get();
                    foreach($closedOrders as $order) {
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
                                'close_time' => $now
                            ],
                            'SYSTEM',
                            null
                        );
                    }
                    Log::info('订单自动关闭任务执行', [
                        'closed_count' => $affected,
                        'time' => $now,
                    ]);

                    // 日统计自动关闭订单，如超20单自动Telegram告警，可用订单表分组count实现，仅主进程每天00:00执行
                    if (date('H:i')==="00:00" && $worker->id===0) {
                        $yesterday = date('Y-m-d',strtotime('-1 day'));
                        $count = Db::table('order')->where('close_time','>=',$yesterday." 00:00:00")
                            ->where('close_time','<=',$yesterday." 23:59:59")
                            ->where('pay_status',2)->count();
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



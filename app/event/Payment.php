<?php

namespace app\event;

use support\Log;

/**
 * 支付相关事件监听器
 */
class Payment
{
    /**
     * 支付成功事件处理
     * @param array $data 事件数据
     */
    public static function success(array $data): void
    {
        $orderNumber = $data['order_number'] ?? '';
        $tradeNo = $data['trade_no'] ?? '';
        $amount = $data['amount'] ?? 0;
        $paymentMethod = $data['payment_method'] ?? 'unknown';
        $payIp = $data['pay_ip'] ?? '';
        $notifyData = $data['notify_data'] ?? [];
        
        Log::info('支付成功事件触发', [
            'order_number' => $orderNumber,
            'trade_no' => $tradeNo,
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'pay_ip' => $payIp,
            'notify_data' => $notifyData
        ]);
        
        // 这里可以添加其他处理逻辑，比如：
        // - 发送通知
        // - 记录统计数据
        // - 触发其他业务逻辑等
    }
}


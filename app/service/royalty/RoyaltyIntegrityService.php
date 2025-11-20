<?php

namespace app\service\royalty;

use app\common\helpers\MoneyHelper;
use app\model\Order;
use app\model\OrderRoyalty;
use app\model\Subject;
use app\service\OrderLogService;
use support\Log;

/**
 * 分账补偿/兜底时的状态修复、同步服务
 */
class RoyaltyIntegrityService
{
    /**
     * 确保订单至少存在一条分账记录快照（用于后台展示或后续状态更新）
     */
    public static function ensureSnapshot(Order $order): ?OrderRoyalty
    {
        try {
            return self::getOrCreateLatestRecord($order);
        } catch (\Throwable $e) {
            Log::channel('royalty')->error('创建分账快照失败', [
                'order_id' => $order->id ?? 0,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * 在无法继续分账时，补写/更新分账记录为失败状态，以便后台可见
     */
    public static function markAsFailed(Order $order, string $reasonCode, string $message): void
    {
        try {
            $record = self::getOrCreateLatestRecord($order);
            if (!$record) {
                Log::channel('royalty')->warning('无法创建分账记录用于标记失败', [
                    'order_id' => $order->id,
                    'reason_code' => $reasonCode,
                    'message' => $message,
                ]);
                return;
            }

            if ($record->royalty_status === OrderRoyalty::ROYALTY_STATUS_SUCCESS) {
                // 已成功的不做覆盖，仅记录日志
                Log::channel('royalty')->info('分账记录已成功，忽略失败标记', [
                    'order_id' => $order->id,
                    'royalty_id' => $record->id,
                    'reason_code' => $reasonCode,
                ]);
                return;
            }

            $record->royalty_status = OrderRoyalty::ROYALTY_STATUS_FAILED;
            $record->royalty_error = $message;
            $record->royalty_time = date('Y-m-d H:i:s');
            $record->save();

            OrderLogService::log(
                $order->trace_id ?? '',
                $order->platform_order_no,
                $order->merchant_order_no,
                '分账处理',
                'WARNING',
                '节点34-分账补偿纠正',
                [
                    'action' => '补偿标记分账失败',
                    'reason_code' => $reasonCode,
                    'message' => $message,
                    'royalty_id' => $record->id,
                ]
            );

            Log::channel('royalty')->warning('分账记录已被补偿标记为失败', [
                'order_id' => $order->id,
                'royalty_id' => $record->id,
                'reason_code' => $reasonCode,
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            Log::channel('royalty')->error('补偿标记分账失败异常', [
                'order_id' => $order->id ?? 0,
                'reason_code' => $reasonCode,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取最近一条分账记录，不存在时创建一条快照（默认待分账）
     */
    private static function getOrCreateLatestRecord(Order $order): ?OrderRoyalty
    {
        $order->loadMissing(['royaltyRecords', 'subject']);
        $record = $order->royaltyRecords()->orderByDesc('id')->first();
        if ($record) {
            return $record;
        }

        return self::createSnapshot($order);
    }

    /**
     * 创建一条用于状态展示的分账记录快照
     */
    private static function createSnapshot(Order $order): ?OrderRoyalty
    {
        $subject = $order->getSubjectEntity();

        $data = [
            'order_id' => $order->id,
            'platform_order_no' => $order->platform_order_no,
            'trade_no' => $order->alipay_order_no ?? '',
            'royalty_type' => $subject->royalty_type ?? Subject::ROYALTY_TYPE_NONE,
            'royalty_mode' => $subject->royalty_mode ?? null,
            'royalty_rate' => $subject->royalty_rate ?? null,
            'subject_id' => $subject->id ?? $order->subject_id,
            'subject_amount' => MoneyHelper::convertToCents($order->order_amount),
            'royalty_amount' => 0,
            'royalty_status' => OrderRoyalty::ROYALTY_STATUS_PENDING,
        ];

        return OrderRoyalty::create($data);
    }
}


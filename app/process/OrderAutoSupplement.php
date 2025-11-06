<?php

namespace app\process;

use Workerman\Timer;
use app\service\OrderSupplementService;
use support\Log;

/**
 * 订单自动补单任务
 * 定期扫描待支付订单，查询支付宝状态并补单
 */
class OrderAutoSupplement
{
    /**
     * 每次处理的订单数量
     */
    const BATCH_SIZE = 50;

    /**
     * 查询多少分钟前创建的订单（避免查询刚创建的订单）
     */
    const MINUTES_AGO = 30;

    /**
     * Worker启动时执行
     */
    public function onWorkerStart(): void
    {
        // 每5分钟执行一次自动补单
        Timer::add(300, function () {
            $this->processAutoSupplement();
        });

        // 立即执行一次（可选）
        // $this->processAutoSupplement();
    }

    /**
     * 处理自动补单
     */
    private function processAutoSupplement(): void
    {
        try {
            Log::info('开始执行自动补单任务', [
                'batch_size' => self::BATCH_SIZE,
                'minutes_ago' => self::MINUTES_AGO
            ]);

            $result = OrderSupplementService::batchSupplement(self::BATCH_SIZE, self::MINUTES_AGO);

            Log::info('自动补单任务执行完成', $result);

            // 如果有成功补单或失败数量较多，记录详细信息
            if ($result['success_count'] > 0 || $result['failed_count'] > 10) {
                Log::info('自动补单任务结果详情', [
                    'success_count' => $result['success_count'],
                    'failed_count' => $result['failed_count'],
                    'skipped_count' => $result['skipped_count'],
                    'total' => $result['total'] ?? 0
                ]);
            }

        } catch (\Throwable $e) {
            Log::error('自动补单任务执行失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}




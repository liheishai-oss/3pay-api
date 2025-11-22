<?php

namespace app\process;

use Workerman\Worker;
use Workerman\Crontab\Crontab;
use app\model\Order;
use app\model\OrderRoyalty;
use app\service\royalty\RoyaltyService;
use app\service\royalty\RoyaltyIntegrityService;
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
     * 最大自动重试次数（累计失败5次后停止自动分账，只能人工触发）
     */
    const MAX_AUTO_RETRY_COUNT = 5;

    /**
     * 队列处理锁的过期时间（秒）
     */
    const PROCESS_LOCK_TTL = 60;

    /**
     * 重试最大次数
     */
    const RETRY_MAX_ATTEMPTS = 3;

    /**
     * 默认重试延迟（秒）
     */
    const RETRY_DEFAULT_DELAY = 300;

    /**
     * 待支付任务默认重新入队延迟（秒）
     */
    const PENDING_REQUEUE_DELAY = 30;

    /**
     * 待支付任务最多补偿次数
     */
    const PENDING_MAX_ATTEMPTS = 5;

    /**
     * Worker启动时执行
     */
    public function onWorkerStart(Worker $worker)
    {
        // 每1秒检查一次Redis队列（秒级处理）
        new Crontab('* * * * * *', function () {
            $this->processRoyaltyQueue();
        });

        // 已关闭兜底扫描（每10分钟执行一次兜底扫描，扫描数据库中的订单）
        // new Crontab('0 */10 * * * *', function () {
        //     $this->processAutoRoyalty();
        // });

        // 每30秒检查一次重试队列（失败分账自动重试，最多3次）
        new Crontab('*/30 * * * * *', function () {
            $this->processRoyaltyRetryQueue();
        });

        // 每15秒处理一次待支付/延迟补偿队列（支付状态未更新时的补偿）
        new Crontab('*/15 * * * * *', function () {
            $this->processRoyaltyPendingQueue();
        });

        Log::info('订单自动分账任务已启动', [
            'queue_interval' => '每1秒',
            'fallback_interval' => '已关闭（数据库扫描兜底机制）',
            'retry_interval' => '每30秒（失败分账自动重试，最多3次）',
            'pending_interval' => '每15秒（待支付补偿队列）',
            'queue_batch_size' => self::QUEUE_BATCH_SIZE,
            'fallback_batch_size' => self::BATCH_SIZE,
            'note' => '数据库扫描兜底机制已关闭，失败分账仍会自动重试'
        ]);
    }

    /**
     * 处理暂不满足分账条件的订单（例如支付状态未更新）
     */
    private function handleRoyaltyPrerequisiteMiss(Order $order, array $orderData, string $reason): void
    {
        $context = [
            'order_id' => $order->id,
            'platform_order_no' => $order->platform_order_no,
            'reason' => $reason,
        ];

        $markFailed = false;
        $failedMessage = '';

        switch ($reason) {
            case 'not_paid':
                Log::channel('royalty')->info('订单支付状态尚未更新为已支付，进入待支付补偿队列', $context);
                $this->schedulePendingRoyalty($order, $orderData, $reason);
                break;
            case 'royalty_disabled':
                Log::channel('royalty')->warning('分账主体未开启分账功能，跳过处理', $context);
                $markFailed = true;
                $failedMessage = '分账主体未开启分账功能';
                break;
            case 'subject_missing':
                Log::channel('royalty')->warning('订单主体不存在或不可用，跳过处理', $context);
                $markFailed = true;
                $failedMessage = '订单主体不存在或已被禁用';
                break;
            case 'already_royalized':
                Log::channel('royalty')->info('订单已存在成功分账记录，跳过', $context);
                break;
            default:
                Log::channel('royalty')->debug('订单暂未满足分账条件', $context);
                $markFailed = true;
                $failedMessage = '分账前置条件未满足';
        }

        if ($markFailed) {
            RoyaltyIntegrityService::markAsFailed($order, $reason, $failedMessage);
        }
    }

    /**
     * 将待支付订单加入补偿队列，等待再次触发
     */
    private function schedulePendingRoyalty(Order $order, array $orderData, string $reason, int $delaySeconds = self::PENDING_REQUEUE_DELAY): void
    {
        $orderId = (int)$order->id;
        if ($orderId <= 0) {
            return;
        }

        $pendingKey = CacheKeys::getRoyaltyPendingQueueKey();
        $pendingDataKey = CacheKeys::getRoyaltyPendingDataKey();
        $nextAt = time() + max($delaySeconds, 10);

        try {
            $payload = Redis::hget($pendingDataKey, (string)$orderId);
            $attempts = 0;
            if ($payload) {
                $decoded = json_decode($payload, true);
                $attempts = (int)($decoded['pending_attempts'] ?? 0);
            }
            $attempts++;

            if ($attempts > self::PENDING_MAX_ATTEMPTS) {
                Log::channel('royalty')->warning('待支付分账补偿超过最大次数，停止入队', [
                    'order_id' => $orderId,
                    'platform_order_no' => $order->platform_order_no,
                    'reason' => $reason,
                ]);
                Redis::hdel($pendingDataKey, (string)$orderId);
                Redis::zrem($pendingKey, (string)$orderId);
                return;
            }

            $orderData['order_id'] = $orderId;
            $orderData['pending_attempts'] = $attempts;
            $orderData['pending_reason'] = $reason;
            $orderData['scheduled_at'] = $nextAt;

            Redis::hset($pendingDataKey, (string)$orderId, json_encode($orderData, JSON_UNESCAPED_UNICODE));
            Redis::zadd($pendingKey, $nextAt, (string)$orderId);
        } catch (\Throwable $e) {
            Log::channel('royalty')->error('待支付分账补偿调度失败', [
                'order_id' => $orderId,
                'platform_order_no' => $order->platform_order_no,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 处理待支付/延迟补偿队列
     */
    private function processRoyaltyPendingQueue(): void
    {
        try {
            $pendingKey = CacheKeys::getRoyaltyPendingQueueKey();
            $pendingDataKey = CacheKeys::getRoyaltyPendingDataKey();
            $queueKey = CacheKeys::getRoyaltyQueueKey();

            $dueOrderIds = Redis::zrangebyscore(
                $pendingKey,
                '-inf',
                time(),
                ['limit' => [0, self::QUEUE_BATCH_SIZE]]
            );

            if (empty($dueOrderIds)) {
                return;
            }

            foreach ($dueOrderIds as $orderIdStr) {
                Redis::zrem($pendingKey, $orderIdStr);
                $payload = Redis::hget($pendingDataKey, $orderIdStr);
                Redis::hdel($pendingDataKey, $orderIdStr);

                $orderPayload = [
                    'order_id' => (int)$orderIdStr,
                    'operator_ip' => 'SYSTEM',
                    'operator_agent' => 'RoyaltyPending',
                    'timestamp' => time()
                ];

                if (is_string($payload)) {
                    $decoded = json_decode($payload, true);
                    if (is_array($decoded)) {
                        $orderPayload = array_merge($orderPayload, [
                            'operator_ip' => $decoded['operator_ip'] ?? $orderPayload['operator_ip'],
                            'operator_agent' => $decoded['operator_agent'] ?? $orderPayload['operator_agent'],
                        ]);
                    }
                }

                Redis::lpush($queueKey, json_encode($orderPayload, JSON_UNESCAPED_UNICODE));
                Log::channel('royalty')->info('待支付订单补偿重新入队', [
                    'order_id' => (int)$orderIdStr,
                    'payload' => $orderPayload
                ]);
            }
        } catch (\Throwable $e) {
            Log::channel('royalty')->error('待支付分账补偿队列处理失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
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
                $lockValue = 'royalty_lock_' . uniqid('', true);
                $lockAcquired = false;
                try {
                    $lockAcquired = Redis::set($processingKey, $lockValue, 'EX', self::PROCESS_LOCK_TTL, 'NX');
                } catch (\Throwable $e) {
                    Log::channel('royalty')->warning('设置分账处理锁失败', [
                        'order_id' => $orderId,
                        'error' => $e->getMessage()
                    ]);
                }

                if (!$lockAcquired) {
                    // 如果正在处理，将任务重新放回队列
                    Redis::lpush($queueKey, $orderIdJson);
                    continue;
                }

                try {
                    // 加载订单数据
                    $order = Order::with(['subject', 'product'])->find($orderId);
                    if (!$order) {
                        Log::warning('分账队列中的订单不存在', ['order_id' => $orderId]);
                        continue;
                    }

                    // 检查分账前置条件
                    $skipReason = null;
                    if (!$order->canProcessRoyalty($skipReason)) {
                        $this->handleRoyaltyPrerequisiteMiss($order, $orderData, $skipReason ?: 'unknown');
                        continue;
                    }

                    // 检查累计失败次数，超过5次则跳过自动分账（只能人工触发）
                    $failureCount = OrderRoyalty::getFailureCount($orderId);
                    if ($failureCount >= self::MAX_AUTO_RETRY_COUNT) {
                        Log::channel('royalty')->warning('订单累计失败次数已达上限，跳过自动分账', [
                            'order_id' => $orderId,
                            'platform_order_no' => $order->platform_order_no,
                            'failure_count' => $failureCount,
                            'max_auto_retry' => self::MAX_AUTO_RETRY_COUNT,
                            'note' => '请通过后台手动触发分账'
                        ]);
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

                        $this->maybeScheduleRetry(
                            $orderId,
                            $result['data']['royalty_id'] ?? 0,
                            $result['data']['retryable'] ?? false,
                            $result['data']['retry_delay'] ?? self::RETRY_DEFAULT_DELAY,
                            1
                        );
                    }

                } catch (\Throwable $e) {
                    Log::channel('royalty')->error('队列分账处理异常', [
                        'order_id' => $orderId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                } finally {
                    $this->releaseProcessingLock($processingKey, $lockValue ?? '');
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
                    RoyaltyIntegrityService::ensureSnapshot($order);

                    // 检查是否需要分账
                    $skipReason = null;
                    if (!$order->canProcessRoyalty($skipReason)) {
                        $skippedCount++;
                        RoyaltyIntegrityService::markAsFailed(
                            $order,
                            $skipReason ?: 'unknown',
                            '兜底分账前置条件未满足'
                        );
                        continue;
                    }

                    // 检查累计失败次数，超过5次则跳过自动分账（只能人工触发）
                    $failureCount = OrderRoyalty::getFailureCount($order->id);
                    if ($failureCount >= self::MAX_AUTO_RETRY_COUNT) {
                        $skippedCount++;
                        Log::channel('royalty')->warning('订单累计失败次数已达上限，跳过兜底自动分账', [
                            'order_id' => $order->id,
                            'platform_order_no' => $order->platform_order_no,
                            'failure_count' => $failureCount,
                            'max_auto_retry' => self::MAX_AUTO_RETRY_COUNT,
                            'note' => '请通过后台手动触发分账'
                        ]);
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

                        $this->maybeScheduleRetry(
                            $order->id,
                            $result['data']['royalty_id'] ?? 0,
                            $result['data']['retryable'] ?? false,
                            $result['data']['retry_delay'] ?? self::RETRY_DEFAULT_DELAY,
                            1
                        );
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

    /**
     * 处理分账重试队列
     */
    private function processRoyaltyRetryQueue(): void
    {
        try {
            $queueKey = CacheKeys::getRoyaltyRetryQueueKey();

            for ($i = 0; $i < self::QUEUE_BATCH_SIZE; $i++) {
                $taskJson = Redis::lpop($queueKey);
                if (empty($taskJson)) {
                    break;
                }

                $task = json_decode($taskJson, true);
                if (!$task || empty($task['royalty_id']) || empty($task['order_id'])) {
                    Log::channel('royalty')->warning('重试队列任务格式错误', ['task' => $taskJson]);
                    continue;
                }

                $nextAt = $task['next_at'] ?? 0;
                if ($nextAt > time()) {
                    Redis::rpush($queueKey, $taskJson);
                    break;
                }

                $retryCount = (int)($task['retry_count'] ?? 1);
                if ($retryCount > self::RETRY_MAX_ATTEMPTS) {
                    Log::channel('royalty')->warning('分账重试超出最大次数', [
                        'order_id' => $task['order_id'],
                        'royalty_id' => $task['royalty_id'],
                        'retry_count' => $retryCount
                    ]);
                    continue;
                }

                // 检查累计失败次数，超过5次则跳过自动重试（只能人工触发）
                $orderId = (int)$task['order_id'];
                $failureCount = OrderRoyalty::getFailureCount($orderId);
                if ($failureCount >= self::MAX_AUTO_RETRY_COUNT) {
                    Log::channel('royalty')->warning('订单累计失败次数已达上限，跳过自动重试', [
                        'order_id' => $orderId,
                        'royalty_id' => $task['royalty_id'],
                        'failure_count' => $failureCount,
                        'max_auto_retry' => self::MAX_AUTO_RETRY_COUNT,
                        'note' => '请通过后台手动触发分账'
                    ]);
                    continue;
                }

                $royaltyId = (int)$task['royalty_id'];
                $result = RoyaltyService::retryRoyalty($royaltyId, 'RetryQueue');

                if ($result['success']) {
                    Log::channel('royalty')->info('分账自动重试成功', [
                        'order_id' => $task['order_id'],
                        'royalty_id' => $result['data']['royalty_id'] ?? $royaltyId,
                        'retry_count' => $retryCount
                    ]);
                    continue;
                }

                $this->maybeScheduleRetry(
                    (int)$task['order_id'],
                    $result['data']['royalty_id'] ?? $royaltyId,
                    $result['data']['retryable'] ?? false,
                    $result['data']['retry_delay'] ?? self::RETRY_DEFAULT_DELAY,
                    $retryCount + 1
                );
            }
        } catch (\Throwable $e) {
            Log::channel('royalty')->error('分账重试队列处理失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 根据返回结果决定是否加入重试队列
     */
    private function maybeScheduleRetry(
        int $orderId,
        int $royaltyId,
        bool $retryable,
        int $delaySeconds,
        int $retryCount
    ): void {
        if (!$retryable || $orderId <= 0 || $royaltyId <= 0) {
            return;
        }

        // 检查累计失败次数，超过5次则不再加入重试队列（只能人工触发）
        $failureCount = OrderRoyalty::getFailureCount($orderId);
        if ($failureCount >= self::MAX_AUTO_RETRY_COUNT) {
            Log::channel('royalty')->warning('订单累计失败次数已达上限，不再加入重试队列', [
                'order_id' => $orderId,
                'royalty_id' => $royaltyId,
                'failure_count' => $failureCount,
                'max_auto_retry' => self::MAX_AUTO_RETRY_COUNT,
                'note' => '请通过后台手动触发分账'
            ]);
            return;
        }

        if ($retryCount > self::RETRY_MAX_ATTEMPTS) {
            Log::channel('royalty')->warning('分账重试已达到最大次数，不再入队', [
                'order_id' => $orderId,
                'royalty_id' => $royaltyId,
                'retry_count' => $retryCount
            ]);
            return;
        }

        $queueKey = CacheKeys::getRoyaltyRetryQueueKey();
        $task = [
            'order_id' => $orderId,
            'royalty_id' => $royaltyId,
            'retry_count' => $retryCount,
            'next_at' => time() + max($delaySeconds, 30),
        ];

        try {
            Redis::rpush($queueKey, json_encode($task, JSON_UNESCAPED_UNICODE));
            Log::channel('royalty')->info('分账失败加入重试队列', $task);
        } catch (\Throwable $e) {
            Log::channel('royalty')->error('分账重试队列入队失败', [
                'task' => $task,
                'error' => $e->getMessage()
            ]);
        }
    }
    /**
     * 释放分账处理锁
     */
    private function releaseProcessingLock(string $processingKey, string $lockValue): void
    {
        if (empty($lockValue)) {
            return;
        }

        try {
            $currentValue = Redis::get($processingKey);
            if ($currentValue === $lockValue) {
                Redis::del($processingKey);
            }
        } catch (\Throwable $e) {
            Log::channel('royalty')->warning('释放分账处理锁失败', [
                'key' => $processingKey,
                'error' => $e->getMessage()
            ]);
        }
    }
}




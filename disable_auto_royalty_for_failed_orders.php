<?php
/**
 * 禁用失败和无需分账订单的自动分账
 * 使用方法：php disable_auto_royalty_for_failed_orders.php
 * 
 * 将现有无需分账或失败状态的订单，全部标记为不再进行自动分账
 */

require_once __DIR__ . '/vendor/autoload.php';

// 加载环境变量
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (($value[0] ?? '') === '"' && ($value[-1] ?? '') === '"') {
                $value = substr($value, 1, -1);
            } elseif (($value[0] ?? '') === "'" && ($value[-1] ?? '') === "'") {
                $value = substr($value, 1, -1);
            }
            if (!getenv($key)) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}

use app\model\Order;
use app\model\OrderRoyalty;
use app\model\Subject;
use app\common\constants\CacheKeys;
use support\Redis;
use support\Log;

// 初始化数据库连接
if (file_exists(__DIR__ . '/support/bootstrap.php')) {
    require_once __DIR__ . '/support/bootstrap.php';
}

try {
    echo "开始禁用失败和无需分账订单的自动分账...\n\n";
    
    // 1. 找出所有无需分账的订单（royalty_type = 'none'）
    $noneRoyaltyOrders = Order::with('subject')
        ->where('pay_status', Order::PAY_STATUS_PAID)
        ->whereHas('subject', function($q) {
            $q->where('royalty_type', Subject::ROYALTY_TYPE_NONE);
        })
        ->whereDoesntHave('royaltyRecords', function($q) {
            $q->where('royalty_status', OrderRoyalty::ROYALTY_STATUS_SUCCESS);
        })
        ->get();
    
    echo "无需分账的订单数量: " . $noneRoyaltyOrders->count() . "\n";
    
    // 2. 找出所有失败次数 >= 5 的订单
    $failedOrders = Order::where('pay_status', Order::PAY_STATUS_PAID)
        ->whereDoesntHave('royaltyRecords', function($q) {
            $q->where('royalty_status', OrderRoyalty::ROYALTY_STATUS_SUCCESS);
        })
        ->get()
        ->filter(function($order) {
            $failureCount = OrderRoyalty::getFailureCount($order->id);
            return $failureCount >= 5;
        });
    
    echo "失败次数 >= 5 的订单数量: " . $failedOrders->count() . "\n\n";
    
    $allOrders = $noneRoyaltyOrders->merge($failedOrders)->unique('id');
    $totalOrders = $allOrders->count();
    
    echo "总计需要禁用的订单数量: {$totalOrders}\n\n";
    
    if ($totalOrders == 0) {
        echo "没有需要禁用的订单。\n";
        exit(0);
    }
    
    // 3. 从队列中移除这些订单
    $queueKey = CacheKeys::getRoyaltyQueueKey();
    $removedFromQueue = 0;
    
    try {
        // 获取队列中的所有任务
        $queueLength = Redis::llen($queueKey);
        echo "当前队列中的任务数: {$queueLength}\n";
        
        if ($queueLength > 0) {
            // 从队列中取出所有任务
            $allQueueItems = [];
            for ($i = 0; $i < $queueLength; $i++) {
                $item = Redis::rpop($queueKey);
                if ($item) {
                    $allQueueItems[] = $item;
                }
            }
            
            // 过滤掉需要禁用的订单，重新入队
            $orderIdsToDisable = $allOrders->pluck('id')->toArray();
            
            foreach ($allQueueItems as $item) {
                $orderData = json_decode($item, true);
                if ($orderData && isset($orderData['order_id'])) {
                    if (in_array($orderData['order_id'], $orderIdsToDisable)) {
                        $removedFromQueue++;
                    } else {
                        // 保留不需要禁用的任务
                        Redis::rpush($queueKey, $item);
                    }
                } else {
                    // 无效数据，丢弃
                    $removedFromQueue++;
                }
            }
        }
        
        echo "从队列中移除的任务数: {$removedFromQueue}\n\n";
    } catch (\Throwable $e) {
        echo "清理队列失败: " . $e->getMessage() . "\n";
    }
    
    // 4. 清理重试队列中的这些订单
    $retryQueueKey = CacheKeys::getRoyaltyRetryQueueKey();
    $removedFromRetryQueue = 0;
    
    try {
        $retryQueueLength = Redis::llen($retryQueueKey);
        echo "当前重试队列中的任务数: {$retryQueueLength}\n";
        
        if ($retryQueueLength > 0) {
            $allRetryItems = [];
            for ($i = 0; $i < $retryQueueLength; $i++) {
                $item = Redis::lpop($retryQueueKey);
                if ($item) {
                    $allRetryItems[] = $item;
                }
            }
            
            $orderIdsToDisable = $allOrders->pluck('id')->toArray();
            
            foreach ($allRetryItems as $item) {
                $task = json_decode($item, true);
                if ($task && isset($task['order_id'])) {
                    if (in_array($task['order_id'], $orderIdsToDisable)) {
                        $removedFromRetryQueue++;
                    } else {
                        Redis::rpush($retryQueueKey, $item);
                    }
                } else {
                    $removedFromRetryQueue++;
                }
            }
        }
        
        echo "从重试队列中移除的任务数: {$removedFromRetryQueue}\n\n";
    } catch (\Throwable $e) {
        echo "清理重试队列失败: " . $e->getMessage() . "\n";
    }
    
    // 5. 清理待支付队列中的这些订单
    $pendingQueueKey = CacheKeys::getRoyaltyPendingQueueKey();
    $removedFromPendingQueue = 0;
    
    try {
        $pendingQueueLength = Redis::zcard($pendingQueueKey);
        echo "当前待支付队列中的任务数: {$pendingQueueLength}\n";
        
        if ($pendingQueueLength > 0) {
            $allPendingItems = Redis::zrange($pendingQueueKey, 0, -1);
            $orderIdsToDisable = $allOrders->pluck('id')->toArray();
            
            foreach ($allPendingItems as $item) {
                $orderData = json_decode($item, true);
                if ($orderData && isset($orderData['order_id'])) {
                    if (in_array($orderData['order_id'], $orderIdsToDisable)) {
                        Redis::zrem($pendingQueueKey, $item);
                        $removedFromPendingQueue++;
                    }
                }
            }
        }
        
        echo "从待支付队列中移除的任务数: {$removedFromPendingQueue}\n\n";
    } catch (\Throwable $e) {
        echo "清理待支付队列失败: " . $e->getMessage() . "\n";
    }
    
    // 6. 清理处理中的锁
    $removedLocks = 0;
    try {
        $orderIdsToDisable = $allOrders->pluck('id')->toArray();
        foreach ($orderIdsToDisable as $orderId) {
            $processingKey = CacheKeys::getRoyaltyProcessingKey($orderId);
            if (Redis::exists($processingKey)) {
                Redis::del($processingKey);
                $removedLocks++;
            }
        }
        echo "清理处理中的锁数量: {$removedLocks}\n\n";
    } catch (\Throwable $e) {
        echo "清理处理锁失败: " . $e->getMessage() . "\n";
    }
    
    echo "✓ 执行完成！\n\n";
    echo "统计信息：\n";
    echo "- 无需分账的订单: " . $noneRoyaltyOrders->count() . "\n";
    echo "- 失败次数 >= 5 的订单: " . $failedOrders->count() . "\n";
    echo "- 总计禁用订单: {$totalOrders}\n";
    echo "- 从队列移除: {$removedFromQueue}\n";
    echo "- 从重试队列移除: {$removedFromRetryQueue}\n";
    echo "- 从待支付队列移除: {$removedFromPendingQueue}\n";
    echo "- 清理处理锁: {$removedLocks}\n\n";
    echo "这些订单将不再进行自动分账，只能通过后台手动触发。\n";
    
} catch (\Exception $e) {
    echo "❌ 执行失败: " . $e->getMessage() . "\n";
    echo "错误位置: " . $e->getFile() . ":" . $e->getLine() . "\n";
    if (method_exists($e, 'getTraceAsString')) {
        echo "堆栈跟踪:\n" . $e->getTraceAsString() . "\n";
    }
    exit(1);
}


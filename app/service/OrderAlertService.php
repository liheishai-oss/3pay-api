<?php

namespace app\service;

use app\model\TelegramMessageQueue;
use app\service\robot\TelegramMessageQueueService;
use support\Log;
use support\Redis;

/**
 * 订单预警服务
 * 用于发送订单相关的预警通知
 */
class OrderAlertService
{
    /**
     * 预警去重缓存键前缀
     */
    private const ALERT_DEDUP_PREFIX = 'order_alert_dedup:';
    
    /**
     * 预警去重时间（秒）
     */
    private const ALERT_DEDUP_TIME = 300; // 5分钟
    
    /**
     * 发送订单创建失败预警
     * 
     * @param string $traceId 链路追踪ID
     * @param string $platformOrderNo 平台订单号
     * @param string $merchantOrderNo 商户订单号
     * @param string $reason 失败原因
     * @param array $context 上下文信息
     * @param string $level 预警级别 P0/P1/P2/P3
     */
    public function sendOrderCreationFailedAlert(
        string $traceId,
        string $platformOrderNo,
        string $merchantOrderNo,
        string $reason,
        array $context = [],
        string $level = 'P1'
    ): void {
        $alertKey = $this->getAlertKey('order_creation_failed', $platformOrderNo);
        
        if ($this->isAlertDeduplicated($alertKey)) {
            return;
        }
        
        $message = $this->buildOrderCreationFailedMessage(
            $traceId,
            $platformOrderNo,
            $merchantOrderNo,
            $reason,
            $context,
            $level
        );
        
        $this->sendTelegramAlert($message, $level);
        $this->setAlertDeduplication($alertKey);
    }
    
    /**
     * 发送支付主体选择失败预警
     * 
     * @param string $traceId 链路追踪ID
     * @param string $platformOrderNo 平台订单号
     * @param string $merchantOrderNo 商户订单号
     * @param int $agentId 代理商ID
     * @param int $paymentTypeId 支付类型ID
     * @param string $level 预警级别
     */
    public function sendSubjectSelectionFailedAlert(
        string $traceId,
        string $platformOrderNo,
        string $merchantOrderNo,
        int $agentId,
        int $paymentTypeId,
        string $level = 'P1'
    ): void {
        $alertKey = $this->getAlertKey('subject_selection_failed', $platformOrderNo);
        
        if ($this->isAlertDeduplicated($alertKey)) {
            return;
        }
        
        $message = $this->buildSubjectSelectionFailedMessage(
            $traceId,
            $platformOrderNo,
            $merchantOrderNo,
            $agentId,
            $paymentTypeId,
            $level
        );
        
        $this->sendTelegramAlert($message, $level);
        $this->setAlertDeduplication($alertKey);
    }
    
    /**
     * 发送数据库写入失败预警
     * 
     * @param string $traceId 链路追踪ID
     * @param string $platformOrderNo 平台订单号
     * @param string $merchantOrderNo 商户订单号
     * @param string $error 错误信息
     * @param string $level 预警级别
     */
    public function sendDatabaseWriteFailedAlert(
        string $traceId,
        string $platformOrderNo,
        string $merchantOrderNo,
        string $error,
        string $level = 'P0'
    ): void {
        $alertKey = $this->getAlertKey('database_write_failed', $platformOrderNo);
        
        if ($this->isAlertDeduplicated($alertKey)) {
            return;
        }
        
        $message = $this->buildDatabaseWriteFailedMessage(
            $traceId,
            $platformOrderNo,
            $merchantOrderNo,
            $error,
            $level
        );
        
        $this->sendTelegramAlert($message, $level);
        $this->setAlertDeduplication($alertKey);
    }
    
    /**
     * 发送订单号生成冲突预警
     * 
     * @param string $traceId 链路追踪ID
     * @param string $platformOrderNo 平台订单号
     * @param string $merchantOrderNo 商户订单号
     * @param string $level 预警级别
     */
    public function sendOrderNumberConflictAlert(
        string $traceId,
        string $platformOrderNo,
        string $merchantOrderNo,
        string $level = 'P0'
    ): void {
        $alertKey = $this->getAlertKey('order_number_conflict', $platformOrderNo);
        
        if ($this->isAlertDeduplicated($alertKey)) {
            return;
        }
        
        $message = $this->buildOrderNumberConflictMessage(
            $traceId,
            $platformOrderNo,
            $merchantOrderNo,
            $level
        );
        
        $this->sendTelegramAlert($message, $level);
        $this->setAlertDeduplication($alertKey);
    }
    
    /**
     * 构建订单创建失败预警消息
     */
    private function buildOrderCreationFailedMessage(
        string $traceId,
        string $platformOrderNo,
        string $merchantOrderNo,
        string $reason,
        array $context,
        string $level
    ): string {
        $time = date('Y-m-d H:i:s');
        $emoji = $this->getLevelEmoji($level);
        
        $message = "{$emoji} <b>【{$level} 预警】订单创建失败</b>\n\n";
        $message .= "⏰ 时间：{$time}\n";
        $message .= "🔍 TraceId：<code>{$traceId}</code>\n";
        $message .= "📦 订单号：<code>{$platformOrderNo}</code>\n";
        $message .= "🏪 商户订单号：<code>{$merchantOrderNo}</code>\n";
        $message .= "❌ 失败原因：{$reason}\n\n";
        
        if (!empty($context)) {
            $message .= "<b>上下文信息：</b>\n";
            foreach ($context as $key => $value) {
                $message .= "• {$key}：{$value}\n";
            }
            $message .= "\n";
        }
        
        $message .= "<b>建议操作：</b>\n";
        $message .= "1. 检查订单参数是否正确\n";
        $message .= "2. 检查商户配置是否正常\n";
        $message .= "3. 联系技术人员处理";
        
        return $message;
    }
    
    /**
     * 构建支付主体选择失败预警消息
     */
    private function buildSubjectSelectionFailedMessage(
        string $traceId,
        string $platformOrderNo,
        string $merchantOrderNo,
        int $agentId,
        int $paymentTypeId,
        string $level
    ): string {
        $time = date('Y-m-d H:i:s');
        $emoji = $this->getLevelEmoji($level);
        
        $message = "{$emoji} <b>【{$level} 预警】支付主体选择失败</b>\n\n";
        $message .= "⏰ 时间：{$time}\n";
        $message .= "🔍 TraceId：<code>{$traceId}</code>\n";
        $message .= "📦 订单号：<code>{$platformOrderNo}</code>\n";
        $message .= "🏪 商户订单号：<code>{$merchantOrderNo}</code>\n";
        $message .= "💳 支付类型ID：{$paymentTypeId}\n\n";
        
        $message .= "<b>建议操作：</b>\n";
        $message .= "1. 检查支付主体配置是否正确\n";
        $message .= "2. 检查支付主体是否被禁用\n";
        $message .= "3. 联系技术人员处理";
        
        return $message;
    }
    
    /**
     * 构建数据库写入失败预警消息
     */
    private function buildDatabaseWriteFailedMessage(
        string $traceId,
        string $platformOrderNo,
        string $merchantOrderNo,
        string $error,
        string $level
    ): string {
        $time = date('Y-m-d H:i:s');
        $emoji = $this->getLevelEmoji($level);
        
        $message = "{$emoji} <b>【{$level} 预警】数据库写入失败</b>\n\n";
        $message .= "⏰ 时间：{$time}\n";
        $message .= "🔍 TraceId：<code>{$traceId}</code>\n";
        $message .= "📦 订单号：<code>{$platformOrderNo}</code>\n";
        $message .= "🏪 商户订单号：<code>{$merchantOrderNo}</code>\n";
        $message .= "❌ 错误信息：<code>{$error}</code>\n\n";
        
        $message .= "<b>建议操作：</b>\n";
        $message .= "1. 检查数据库连接状态\n";
        $message .= "2. 检查数据库表结构\n";
        $message .= "3. 立即联系技术人员处理";
        
        return $message;
    }
    
    /**
     * 构建订单号生成冲突预警消息
     */
    private function buildOrderNumberConflictMessage(
        string $traceId,
        string $platformOrderNo,
        string $merchantOrderNo,
        string $level
    ): string {
        $time = date('Y-m-d H:i:s');
        $emoji = $this->getLevelEmoji($level);
        
        $message = "{$emoji} <b>【{$level} 预警】订单号生成冲突</b>\n\n";
        $message .= "⏰ 时间：{$time}\n";
        $message .= "🔍 TraceId：<code>{$traceId}</code>\n";
        $message .= "📦 订单号：<code>{$platformOrderNo}</code>\n";
        $message .= "🏪 商户订单号：<code>{$merchantOrderNo}</code>\n\n";
        
        $message .= "<b>建议操作：</b>\n";
        $message .= "1. 检查订单号生成算法\n";
        $message .= "2. 检查Redis缓存状态\n";
        $message .= "3. 立即联系技术人员处理";
        
        return $message;
    }
    
    /**
     * 发送Telegram预警（加入数据库队列）
     */
    private function sendTelegramAlert(string $message, string $level): void
    {
        try {
            // 根据预警级别确定优先级
            $priority = TelegramMessageQueue::PRIORITY_NORMAL;
            switch ($level) {
                case 'P0':
                    $priority = TelegramMessageQueue::PRIORITY_CRITICAL;  // 紧急
                    break;
                case 'P1':
                    $priority = TelegramMessageQueue::PRIORITY_HIGH;      // 高
                    break;
                case 'P2':
                    $priority = TelegramMessageQueue::PRIORITY_NORMAL;    // 普通
                    break;
                case 'P3':
                    $priority = TelegramMessageQueue::PRIORITY_LOW;       // 低
                    break;
                default:
                    $priority = TelegramMessageQueue::PRIORITY_NORMAL;
                    break;
            }
            
            $queueMessage = TelegramMessageQueueService::addMessage(
                $this->getAlertTitle($level),
                $message,
                $priority,
                'html',
                [
                    'max_retry' => 3,
                ]
            );
            
            if ($queueMessage) {
                Log::info('订单预警已加入队列', [
                    'level' => $level,
                    'message_id' => $queueMessage->id,
                    'priority' => $priority
                ]);
            } else {
                Log::error('订单预警加入队列失败', [
                    'level' => $level,
                    'message_length' => strlen($message)
                ]);
            }
        } catch (\Exception $e) {
            Log::error('订单预警加入队列异常', [
                'level' => $level,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    /**
     * 获取预警标题
     */
    private function getAlertTitle(string $level): string
    {
        $emoji = $this->getLevelEmoji($level);
        return "{$emoji} 【{$level} 预警】订单系统告警";
    }
    
    /**
     * 获取预警级别对应的表情符号
     */
    private function getLevelEmoji(string $level): string
    {
        switch ($level) {
            case 'P0':
                return '🚨';
            case 'P1':
                return '🚨';
            case 'P2':
                return '⚠️';
            case 'P3':
                return '🟢';
            default:
                return 'ℹ️';
        }
    }
    
    /**
     * 获取预警去重键
     */
    private function getAlertKey(string $type, string $identifier): string
    {
        return self::ALERT_DEDUP_PREFIX . $type . ':' . $identifier;
    }
    
    /**
     * 检查预警是否已去重
     */
    private function isAlertDeduplicated(string $alertKey): bool
    {
        try {
            return Redis::exists($alertKey) > 0;
        } catch (\Exception $e) {
            Log::error('检查预警去重失败', [
                'alert_key' => $alertKey,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * 设置预警去重
     */
    private function setAlertDeduplication(string $alertKey): void
    {
        try {
            Redis::setex($alertKey, self::ALERT_DEDUP_TIME, 1);
        } catch (\Exception $e) {
            Log::error('设置预警去重失败', [
                'alert_key' => $alertKey,
                'error' => $e->getMessage()
            ]);
        }
    }
}

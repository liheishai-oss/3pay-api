<?php

namespace app\process;

use app\model\TelegramMessageQueue;
use app\service\robot\TelegramRobotPush;
use support\Log;
use Workerman\Worker;
use Workerman\Crontab\Crontab;
/**
 * Telegram消息队列监控
 * 
 * 定期从消息队列表中获取消息并推送到Telegram
 */
class TelegramMessageMonitor
{
    /**
     * 每次最多处理的消息数量
     */
    const MAX_BATCH_SIZE = 10;


    public function onWorkerStart(Worker $worker)
    {
        // 每3秒检查一次消息队列
        new Crontab('*/3 * * * * *', function(){
            $this->processMessageQueue();
        });

    }

    /**
     * 处理消息队列
     * 
     * @return void
     */
    private function processMessageQueue()
    {
        try {
            // 获取待发送的消息（按优先级排序）
            $messages = TelegramMessageQueue::where('status', TelegramMessageQueue::STATUS_PENDING)
                ->whereRaw('retry_count < max_retry')
                ->where(function($query) {
                    $query->whereNull('scheduled_at')
                          ->orWhere('scheduled_at', '<=', date('Y-m-d H:i:s'));
                })
                ->orderBy('priority', 'asc')
                ->orderBy('created_at', 'asc')
                ->limit(self::MAX_BATCH_SIZE)
                ->get();
            
            if ($messages->isEmpty()) {
                return;
            }
            
            Log::info("发现 {$messages->count()} 条待发送消息");
            
            // 初始化机器人推送服务
            $robot = new TelegramRobotPush();
            $successCount = 0;
            $failedCount = 0;
            
            foreach ($messages as $message) {
                $this->processMessage($message, $robot, $successCount, $failedCount);
                
                // 避免频繁推送，延迟0.5秒
                usleep(500000);
            }
            
            Log::info('消息队列处理完成', [
                'total' => $messages->count(),
                'success' => $successCount,
                'failed' => $failedCount,
            ]);
            
        } catch (\Exception $e) {
            Log::error('处理消息队列异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * 处理单条消息
     * 
     * @param TelegramMessageQueue $message
     * @param TelegramRobotPush $robot
     * @param int &$successCount
     * @param int &$failedCount
     * @return void
     */
    private function processMessage(
        TelegramMessageQueue $message,
        TelegramRobotPush $robot,
        int &$successCount,
        int &$failedCount
    ): void {
        try {
            // 标记为发送中
            $message->status = TelegramMessageQueue::STATUS_SENDING;
            $message->save();
            
            Log::info('开始发送消息', [
                'message_id' => $message->id,
                'title' => $message->title,
                'priority' => $message->priority,
                'type' => $message->message_type,
            ]);
            
            // 根据消息类型发送
            $result = false;
            
            switch ($message->message_type) {
                case 'text':
                    $result = $robot->sendText($message->content, $message->chat_id);
                    break;
                    
                case 'html':
                    $result = $robot->sendHtml($message->content, $message->chat_id);
                    break;
                    
                case 'template':
                    $result = $robot->sendTemplate(
                        $message->template_name,
                        $message->template_data ?? [],
                        $message->chat_id
                    );
                    break;
                    
                default:
                    throw new \Exception("不支持的消息类型: {$message->message_type}");
            }
            
            if ($result) {
                // 发送成功
                $message->status = TelegramMessageQueue::STATUS_SENT;
                $message->sent_at = date('Y-m-d H:i:s');
                $message->error_message = null;
                $message->save();
                
                $successCount++;
                
                Log::info('消息发送成功', [
                    'message_id' => $message->id,
                    'title' => $message->title,
                ]);
            } else {
                // 发送失败，重试
                $this->handleSendFailure($message, '发送失败（Telegram API返回失败）');
                $failedCount++;
            }
            
        } catch (\Exception $e) {
            // 发送异常，重试
            $this->handleSendFailure($message, $e->getMessage());
            $failedCount++;
            
            Log::error('消息发送异常', [
                'message_id' => $message->id,
                'title' => $message->title,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 处理发送失败
     * 
     * @param TelegramMessageQueue $message
     * @param string $errorMessage
     * @return void
     */
    private function handleSendFailure(TelegramMessageQueue $message, string $errorMessage): void
    {
        $message->retry_count++;
        $message->error_message = $errorMessage;
        
        // 如果超过最大重试次数，标记为失败
        if ($message->retry_count >= $message->max_retry) {
            $message->status = TelegramMessageQueue::STATUS_FAILED;
            
            Log::warning('消息发送失败（已达最大重试次数）', [
                'message_id' => $message->id,
                'title' => $message->title,
                'retry_count' => $message->retry_count,
                'error' => $errorMessage,
            ]);
        } else {
            // 重置为待发送，等待下次重试
            $message->status = TelegramMessageQueue::STATUS_PENDING;
            
            Log::warning('消息发送失败，将重试', [
                'message_id' => $message->id,
                'title' => $message->title,
                'retry_count' => $message->retry_count,
                'max_retry' => $message->max_retry,
                'error' => $errorMessage,
            ]);
        }
        
        $message->save();
    }
}

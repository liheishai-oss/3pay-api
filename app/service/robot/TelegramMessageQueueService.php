<?php

namespace app\service\robot;

use app\model\TelegramMessageQueue;
use support\Log;

/**
 * Telegram消息队列服务
 */
class TelegramMessageQueueService
{
    /**
     * 添加黑名单通知消息到队列
     * 
     * @param array $blacklistData 黑名单数据
     * @return TelegramMessageQueue|null
     */
    public static function addBlacklistMessage(array $blacklistData): ?TelegramMessageQueue
    {
        $action = $blacklistData['action'] ?? 'insert';
        $title = $action === 'insert' ? '🚨 新用户加入黑名单' : '⚠️ 黑名单用户再次触发';

        return self::addMessage(
            $title,
            '', // 内容由模板生成
            TelegramMessageQueue::PRIORITY_HIGH,
            'template',
            [
                'template_name' => 'blacklist',
                'template_data' => $blacklistData,
            ]
        );
    }

    /**
     * 添加消息到队列
     * 
     * @param string $title 消息标题
     * @param string $content 消息内容
     * @param int $priority 优先级（1-10，默认5）
     * @param string $messageType 消息类型（text/html/markdown/template）
     * @param array $options 其他选项（template_name, template_data, chat_id, scheduled_at）
     * @return TelegramMessageQueue|null
     */
    public static function addMessage(
        string $title,
        string $content,
        int $priority = TelegramMessageQueue::PRIORITY_NORMAL,
        string $messageType = 'text',
        array $options = []
    ): ?TelegramMessageQueue {
        try {
            $data = [
                'title' => $title,
                'content' => $content,
                'priority' => max(1, min(10, $priority)), // 限制在1-10之间
                'message_type' => $messageType,
                'status' => TelegramMessageQueue::STATUS_PENDING,
            ];

            // 合并其他选项
            if (isset($options['template_name'])) {
                $data['template_name'] = $options['template_name'];
            }
            if (isset($options['template_data'])) {
                $data['template_data'] = $options['template_data'];
            }
            if (isset($options['chat_id'])) {
                $data['chat_id'] = $options['chat_id'];
            }
            if (isset($options['scheduled_at'])) {
                $data['scheduled_at'] = $options['scheduled_at'];
            }
            if (isset($options['max_retry'])) {
                $data['max_retry'] = $options['max_retry'];
            }

            $message = TelegramMessageQueue::create($data);

            Log::info('消息已加入队列', [
                'message_id' => $message->id,
                'title' => $title,
                'priority' => $priority,
            ]);

            return $message;

        } catch (\Exception $e) {
            Log::error('添加消息到队列失败', [
                'title' => $title,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * 获取待发送消息数量（按优先级分组）
     * 
     * @return array
     */
    public static function getPendingStats(): array
    {
        try {
            $stats = TelegramMessageQueue::where('status', TelegramMessageQueue::STATUS_PENDING)
                ->selectRaw('priority, COUNT(*) as count')
                ->groupBy('priority')
                ->orderBy('priority', 'asc')
                ->get()
                ->toArray();

            return $stats;

        } catch (\Exception $e) {
            Log::error('获取待发送消息统计失败', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}


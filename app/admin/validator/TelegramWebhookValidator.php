<?php

namespace app\admin\validator;

class TelegramWebhookValidator
{
    /**
     * 验证并提取 Telegram Webhook 数据
     * @param array|null $message
     * @return array
     */
    public static function validateAndExtract(?array $message): array
    {
        // 记录原始消息数据到单独的telegram日志文件
        \support\Log::channel('telegram')->info('TelegramWebhookValidator 原始消息', [
            'message' => $message,
            'message_keys' => is_array($message) ? array_keys($message) : []
        ]);

        if (!$message || !is_array($message)) {
            \support\Log::warning('TelegramWebhookValidator 消息为空或非数组');
            return [
                'group_id' => null,
                'sender_id' => null,
                'first_name' => null,
                'username' => null,
                'send_time' => null,
                'message_text' => null,
                'message_type' => 'invalid',
                'group_name' => null,
                'is_valid' => false
            ];
        }

        // 提取群组ID
        $result['group_id'] = self::extractGroupId($message);

        $result['sender_id'] = self::extractSenderId($message);
        $result['first_name'] = self::extractFirstName($message);
        $result['username'] = self::extractUsername($message);

        // 提取发送时间
        $result['send_time'] = self::extractSendTime($message);
        
        // 提取消息内容和类型
        $messageData = self::extractMessageContent($message);
        $result['message_text'] = $messageData['text'];
        $result['message_type'] = $messageData['type'];
        $result['group_name'] = $message['chat']['title'] ?? '';

        // 验证是否包含必要信息
        $result['is_valid'] = self::validateRequiredFields($result);

        \support\Log::info('TelegramWebhookValidator 提取结果', [
            'result' => $result,
            'is_valid' => $result['is_valid']
        ]);

        return $result;
    }



    /**
     * 提取群组ID
     * @param array $message
     * @return int|null
     */
    private static function extractGroupId(array $message): ?int
    {
        if (isset($message['chat']['id'])) {
            return (int) $message['chat']['id'];
        }
        return null;
    }

    /**
     * 提取发送人ID
     * @param array $message
     * @return int|null
     */
    private static function extractSenderId(array $message): ?int
    {
        if (isset($message['from']['id'])) {
            return (int) $message['from']['id'];
        }
        return null;
    }

    /**
     * 提取发送时间
     * @param array $message
     * @return string|null
     */
    private static function extractSendTime(array $message): ?string
    {
        if (isset($message['date']) && is_numeric($message['date'])) {
            return date('Y-m-d H:i:s', (int) $message['date']);
        }
        return null;
    }

    /**
     * 提取消息内容
     * @param array $message
     * @return array
     */
    private static function extractMessageContent(array $message): array
    {
        // 优先提取文字消息
        if (isset($message['text']) && !empty(trim($message['text']))) {
            return [
                'text' => trim($message['text']),
                'type' => 'text'
            ];
        }

        // 提取图片说明文字
        if (isset($message['caption']) && !empty(trim($message['caption']))) {
            return [
                'text' => trim($message['caption']),
                'type' => 'caption'
            ];
        }

        // 其他类型消息
        return [
            'text' => '非文字消息',
            'type' => 'other'
        ];
    }

    /**
     * 验证必要字段
     * @param array $data
     * @return bool
     */
    private static function validateRequiredFields(array $data): bool
    {
        return !is_null($data['group_id']) && 
               !is_null($data['sender_id']) && 
               !is_null($data['message_text']) && 
               !is_null($data['send_time']);
    }

    /**
     * 验证是否为群组消息
     * @param array $message
     * @return bool
     */
    public static function isGroupMessage(array $message): bool
    {
        return isset($message['chat']['type']) && 
               in_array($message['chat']['type'], ['group', 'supergroup']);
    }

    /**
     * 验证是否为文字消息
     * @param array $message
     * @return bool
     */
    public static function isTextMessage(array $message): bool
    {
        return isset($message['text']) || isset($message['caption']);
    }

    private static function extractFirstName(?array $message): string
    {
        return '@'.$message['from']['first_name'];
    }

    private static function extractUsername(?array $message)
    {
        return $message['from']['username'] ?? null;
    }
}

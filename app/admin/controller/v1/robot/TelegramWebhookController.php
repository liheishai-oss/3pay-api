<?php

namespace app\admin\controller\v1\robot;

use support\Request;
use support\Response;
use support\Log;
use app\admin\validator\TelegramWebhookValidator;

class TelegramWebhookController
{
    /**
     * 处理Telegram Webhook
     * @param Request $request
     * @return Response
     */
    public function handleWebhook(Request $request): Response
    {
        try {
            $data = $request->all();
            // 记录原始请求数据到单独的telegram日志文件
            Log::channel('telegram')->info('Telegram Webhook 原始数据', [
                'raw_data' => $data,
                'has_message' => isset($data['message']),
                'message_keys' => isset($data['message']) ? array_keys($data['message']) : []
            ]);

            // 检查是否有message字段
            if (!isset($data['message'])) {
                Log::warning('Telegram Webhook 缺少message字段', ['data' => $data]);
                return success();
            }

            // 使用验证器提取和验证数据
            $extractedData = TelegramWebhookValidator::validateAndExtract($data['message']);
            
            Log::info('Telegram Webhook 数据提取结果', [
                'extracted_data' => $extractedData,
                'message_type' => isset($extractedData['message_type']) ? $extractedData['message_type'] : 'unknown',
                'is_valid' => isset($extractedData['is_valid']) ? $extractedData['is_valid'] : false
            ]);
            
            if($extractedData['message_type'] != 'text') {
                Log::info('非文字消息，跳过处理', ['message_type' => $extractedData['message_type']]);
                return success();
            }

            // 检查数据是否有效
            if (!(isset($extractedData['is_valid']) ? $extractedData['is_valid'] : false)) {
                Log::warning('Telegram消息数据无效', ['extracted_data' => $extractedData]);
                return success();
            }

            Log::info('开始处理Telegram消息', [
                'group_id' => $extractedData['group_id'],
                'sender_id' => $extractedData['sender_id'],
                'message_text' => $extractedData['message_text']
            ]);

            $dispatcher = new TelegramMessageDispatcher();
            $dispatcher->dispatch($extractedData);

            Log::info('Telegram消息处理完成');
            return success();

        } catch (\Exception $e) {
            echo $e->getMessage().$e->getTraceAsString().$e->getFile().$e->getLine().$e->getCode();
            Log::error('处理Telegram Webhook失败', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ]);

            return error('处理Webhook失败: ' . $e->getMessage());
        }
    }


}














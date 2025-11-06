<?php

namespace app\service\robot;

use support\Log;

/**
 * Telegram机器人推送服务
 */
class TelegramRobotPush
{
    /**
     * Telegram Bot Token
     * @var string
     */
    private $botToken;

    /**
     * 聊天ID
     * @var string
     */
    private $chatId;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->botToken = config('telegram.bot_token');
        $this->chatId = config('telegram.chat_id');
    }

    /**
     * 发送文本消息
     * 
     * @param string $content 消息内容
     * @param string|null $chatId 指定聊天ID
     * @return bool
     */
    public function sendText(string $content, ?string $chatId = null): bool
    {
        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
        
        $data = [
            'chat_id' => $chatId ?? $this->chatId,
            'text' => $content,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];
        
        $result = $this->httpRequest($url, $data);
        
        return $result && isset($result['ok']) && $result['ok'] === true;
    }

    /**
     * 发送HTML格式消息
     * 
     * @param string $content HTML内容
     * @param string|null $chatId 指定聊天ID
     * @return bool
     */
    public function sendHtml(string $content, ?string $chatId = null): bool
    {
        return $this->sendText($content, $chatId);
    }

    /**
     * 发送模板消息
     * 
     * @param string $templateName 模板名称
     * @param array $data 模板数据
     * @param string|null $chatId 聊天ID
     * @return bool
     */
    public function sendTemplate(string $templateName, array $data = [], ?string $chatId = null): bool
    {
        $className = "app\\service\\robot\\templates\\" . ucfirst($templateName) . "Template";
        
        if (!class_exists($className)) {
            Log::error('模板类不存在', ['class' => $className]);
            return false;
        }
        
        try {
            $template = new $className();
            $content = $template->render($data);
            
            return $this->sendHtml($content, $chatId);
        } catch (\Exception $e) {
            Log::error('发送模板消息失败', [
                'template' => $templateName,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * HTTP请求
     * @param string $url
     * @param array $data
     * @return array|null
     */
    private function httpRequest(string $url, array $data): ?array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($error) {
            Log::error('Telegram API请求失败', [
                'url' => $url,
                'error' => $error,
            ]);
            return null;
        }
        
        $result = json_decode($response, true);
        
        if ($httpCode !== 200 || !$result) {
            Log::error('Telegram API返回异常', [
                'url' => $url,
                'http_code' => $httpCode,
                'response' => $response,
            ]);
        }
        
        return $result;
    }
}

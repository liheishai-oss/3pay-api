<?php

namespace app\admin\controller\v1\robot;

use app\admin\controller\v1\robot\commands\TelegramCommandProcessor;
use app\common\TelegramCommandEnum;
use app\admin\controller\v1\robot\commands\HelpCommand;
use app\admin\controller\v1\robot\template\ThirdPartyMessageTemplate;
use support\Log;

class TelegramMessageDispatcher
{
    private TelegramCommandProcessor $commandProcessor;

    public function __construct()
    {
        $this->commandProcessor = new TelegramCommandProcessor();
        $this->registerCommands();
    }

    /**
     * 注册所有命令
     */
    private function registerCommands(): void
    {
        // 注册帮助命令
        $this->commandProcessor->registerCommand(new HelpCommand());
        
        Log::info('Telegram命令注册完成', [
            'commands' => $this->commandProcessor->getRegisteredCommands()
        ]);
    }

    public function dispatch(array $message)
    {
        Log::info('收到Telegram消息', [
            'sender_id' => isset($message['sender_id']) ? $message['sender_id'] : null,
            'group_name' => isset($message['group_name']) ? $message['group_name'] : null,
            'message_text' => isset($message['message_text']) ? $message['message_text'] : null
        ]);

        // 仅处理30秒内的消息（避免过期消息被处理）
        try {
            $now = time();
            $msgTs = null;
            if (isset($message['date']) && is_numeric($message['date'])) {
                $msgTs = (int)$message['date'];
            } elseif (!empty($message['send_time'])) {
                $tmp = strtotime($message['send_time']);
                if ($tmp !== false) {
                    $msgTs = $tmp;
                }
            }
            if ($msgTs !== null && ($now - $msgTs) > 30) {
                Log::info('忽略超过30秒的过期消息', [
                    'msg_ts' => $msgTs,
                    'now' => $now,
                    'diff' => $now - $msgTs
                ]);
                return;
            }
        } catch (\Throwable $e) {
            // 时间解析异常不影响后续逻辑
            Log::warning('消息时间解析异常，继续处理', [
                'error' => $e->getMessage()
            ]);
        }

//        /** @var TelegramAdminRepository $adminRepo */
//        $adminRepo = Container::get(TelegramAdminRepository::class);
//        // 检查是否是管理员
//        if (!$adminRepo->isAdmin($message['sender_id'])) {
//            Log::info('非管理员消息舍弃', $message);
//            return;
//        }

        $messageGroupName = isset($message['group_name']) ? $message['group_name'] : '';
        $messageText = isset($message['message_text']) ? $message['message_text'] : '';

        // 非指令消息在入口直接拦截（仅处理以'/'开头的命令）
        if (!isset($message['message_text']) || !is_string($message['message_text']) || !preg_match('/^\//', trim($message['message_text']))) {
            Log::info('非指令消息，入口拦截', [
                'message_text' => $message['message_text'] ?? null
            ]);
            return;
        }

        // 如果不是已注册指令（即使以/开头也拦截）
        $msgText = trim($message['message_text']);
        if (!TelegramCommandEnum::isKnown($msgText) && !$this->commandProcessor->isKnownCommand($msgText)) {
            Log::info('未知指令拦截', [
                'message_text' => $message['message_text']
            ]);
            return;
        }

        // 处理所有命令（通过命令处理器）
        $result = $this->commandProcessor->processMessage($message);
        // 仅当命令返回明确的 message 时才发送，避免非指令消息也提示
        if (isset($result['message']) && $result['message'] !== null && $result['message'] !== '') {
            $this->sendTelegramMessage($message['group_id'], $result['message']);
            return;
        }

        // 处理技术群组的其他命令
        if (str_ends_with($messageGroupName, '[技术]')) {
            Log::info('处理技术群消息', [
                'group_name' => $messageGroupName,
                'message_text' => $messageText
            ]);
            if($messageText == '结算'){
                $this->sendTelegramMessage($message['group_id'], ThirdPartyMessageTemplate::settlement($message));
            }elseif($messageText == '查成率' || $messageText == '成功率'){
                $this->sendTelegramMessage($message['group_id'],ThirdPartyMessageTemplate::successRate($message));
            }elseif($messageText == '帮助' || $messageText == '命令' || $messageText == 'help'){
                $this->sendTelegramMessage($message['group_id'],ThirdPartyMessageTemplate::help());
            }elseif($messageText == '余额' || $messageText == '查余额' || $messageText == '剩余金额') {
                $this->sendTelegramMessage($message['group_id'],ThirdPartyMessageTemplate::balance($message));
            }elseif (str_starts_with($messageText, '预付 ')) {
                // 提取表达式
                $expression = trim(substr($messageText, strlen('预付')));
                // 计算表达式
                $amount = $this->calculateExpression($expression);
                $this->sendTelegramMessage($message['group_id'],ThirdPartyMessageTemplate::prepay([
                    'amount' => $amount,
                    'balance_after' => $amount, //临时
                    'first_name' => $message['first_name'],
                    'message_text' => $message['message_text'],
                ]));
                Log::info('预付计算结果', ['amount' => $amount]);
            }else if (str_starts_with($messageText, '下发 ')) {
                // 提取表达式
                $expression = trim(substr($messageText, strlen('下发')));
                // 计算表达式
                $amount = $this->calculateExpression($expression);
                $this->sendTelegramMessage($message['group_id'],ThirdPartyMessageTemplate::payout([
                    'amount' => $amount,
                    'balance_after' => $amount, //临时
                    'username' => $message['username'],
                    'message_text' => $message['message_text'],
                ]));
                Log::info('下发计算结果', ['amount' => $amount]);
            }
        }

        Log::debug('消息处理完成', [
            'is_tech_group' => str_ends_with($messageGroupName, '[技术]'),
            'group_name' => $messageGroupName
        ]);
        return;

    }
    function sendTelegramMessage(int $chatId, string $text): bool|string
    {
        $botToken = config('telegram.bot_token', '');
        if (!$botToken) {
            Log::error('Telegram机器人Token未配置');
            return false;
        }

        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

        $data = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML', // 可选，支持格式化
        ];

        Log::info('发送Telegram消息', [
            'chat_id' => $chatId,
            'text_length' => strlen($text),
            'url' => $url
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Log::error('Telegram消息发送失败', [
                'chat_id' => $chatId,
                'error' => $error,
                'http_code' => $httpCode
            ]);
            return false;
        }

        if ($httpCode !== 200) {
            Log::error('Telegram API返回错误', [
                'chat_id' => $chatId,
                'http_code' => $httpCode,
                'response' => $response
            ]);
            return false;
        }

        Log::info('Telegram消息发送成功', [
            'chat_id' => $chatId,
            'response' => $response
        ]);

        return $response;
    }
    /**
     * 安全计算数学表达式
     * 只允许数字、括号和 + - * / 运算符
     * 移除所有空格
     */
    function calculateExpression(string $expr): float
    {
        // 移除非法字符，包括空格
        $expr = preg_replace('/[^\d+\-*\/().]/', '', $expr);

        if (empty($expr)) return 0;

        try {
            $result = 0;
            eval('$result = ' . $expr . ';');
            return (float) $result;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
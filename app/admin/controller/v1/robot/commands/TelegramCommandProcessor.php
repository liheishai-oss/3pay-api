<?php

namespace app\admin\controller\v1\robot\commands;

use support\Log;

/**
 * Telegram命令处理器
 * 企业级命令模式实现
 */
class TelegramCommandProcessor
{
    private array $commands = [];

    /**
     * 注册命令
     */
    public function registerCommand(TelegramCommandInterface $command): void
    {
        $this->commands[$command->getCommandName()] = $command;
        
        Log::info('注册Telegram命令', [
            'command_name' => $command->getCommandName(),
            'description' => $command->getDescription()
        ]);
    }

    /**
     * 处理消息
     */
    public function processMessage(array $message): array
    {
        $messageText = isset($message['message_text']) ? $message['message_text'] : '';
        
        Log::info('处理Telegram消息', [
            'message_text' => $messageText,
            'group_id' => isset($message['group_id']) ? $message['group_id'] : null,
            'sender_id' => isset($message['sender_id']) ? $message['sender_id'] : null
        ]);

        // 查找匹配的命令
        foreach ($this->commands as $command) {
            if ($command->matches($messageText)) {
                Log::info('找到匹配的命令', [
                    'command_name' => $command->getCommandName(),
                    'message_text' => $messageText
                ]);

                try {
                    $result = $command->execute($message);
                    
                    Log::info('命令执行完成', [
                        'command_name' => $command->getCommandName(),
                        'success' => isset($result['success']) ? $result['success'] : false,
                        'error_code' => isset($result['error_code']) ? $result['error_code'] : null
                    ]);

                    return $result;
                } catch (\Exception $e) {
                    Log::error('命令执行异常', [
                        'command_name' => $command->getCommandName(),
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);

                    return [
                        'success' => false,
                        'message' => "❌ 命令执行异常：{$e->getMessage()}",
                        'error_code' => 'COMMAND_EXCEPTION'
                    ];
                }
            }
        }

        // 没有找到匹配的命令，检查是否为命令格式
        if (preg_match('/^\/\w+/', trim($messageText))) {
            // 如果是命令格式但没有匹配到，返回未知命令错误
            Log::info('未找到匹配的命令', ['message_text' => $messageText]);
            
            return [
                'success' => false,
                'message' => '❓ 未知命令，请检查命令格式',
                'error_code' => 'UNKNOWN_COMMAND'
            ];
        } else {
            // 如果不是命令格式，直接返回空，不处理
            Log::info('非命令消息，忽略处理', ['message_text' => $messageText]);
            
            return [
                'success' => true,
                'message' => null // 不发送任何消息
            ];
        }
    }

    /**
     * 判断是否为受支持的指令（根据已注册命令的 matches 规则）
     */
    public function isKnownCommand(string $messageText): bool
    {
        foreach ($this->commands as $command) {
            if ($command->matches($messageText)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 获取所有注册的命令
     */
    public function getRegisteredCommands(): array
    {
        return array_map(function($command) {
            return [
                'name' => $command->getCommandName(),
                'description' => $command->getDescription()
            ];
        }, $this->commands);
    }

    /**
     * 获取帮助信息
     */
    public function getHelpMessage(): string
    {
        $commands = $this->getRegisteredCommands();
        
        if (empty($commands)) {
            return '📋 暂无可用命令';
        }

        $help = "📋 可用命令：\n\n";
        foreach ($commands as $command) {
            $help .= "• {$command['description']}\n";
        }
        
        return $help;
    }
}

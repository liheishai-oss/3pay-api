<?php

namespace app\admin\controller\v1\robot\commands;

use support\Log;

/**
 * 帮助命令
 * 格式：/帮助
 */
class HelpCommand implements TelegramCommandInterface
{
    /**
     * 执行命令
     */
    public function execute(array $message): array
    {
        $messageText = isset($message['message_text']) ? $message['message_text'] : '';
        $groupId = isset($message['group_id']) ? $message['group_id'] : null;
        $senderId = isset($message['sender_id']) ? $message['sender_id'] : null;

        Log::info('执行帮助命令', [
            'message_text' => $messageText,
            'group_id' => $groupId,
            'sender_id' => $senderId
        ]);

        try {
            // 检查权限
            if (!$this->hasPermission($message)) {
                return [
                    'success' => false,
                    'message' => '❌ 只有绑定的供应商管理员才能查看帮助信息',
                    'error_code' => 'NO_PERMISSION'
                ];
            }

            // 获取命令处理器实例
            $commandProcessor = new TelegramCommandProcessor();
            
            // 注册所有命令
            $this->registerAllCommands($commandProcessor);
            
            // 获取帮助信息
            $helpMessage = $commandProcessor->getHelpMessage();
            
            // 检查是否为管理员
            $isAdmin = $this->checkIfAdmin($message);
            
            // 添加详细说明
            $detailedHelp = "🤖 支付系统机器人帮助\n\n";
            $detailedHelp .= "📋 可用命令：\n\n";
            
            // 订单查询（所有人都可以看到）
            $detailedHelp .= "📊 订单查询：\n";
            $detailedHelp .= "• /查单 订单号 - 查询订单状态\n\n";
            
            // 余额查询（所有人都可以看到）
            $detailedHelp .= "💰 余额查询：\n";
            $detailedHelp .= "• /查余额 - 查看当前余额\n\n";
            
            // 统计报表（所有人都可以看到）
            $detailedHelp .= "📈 统计报表：\n";
            $detailedHelp .= "• /结算 - 查看结算统计\n";
            $detailedHelp .= "• /查成功率 - 查看成功率统计\n\n";
            
            // 管理员专用命令
            if ($isAdmin) {
                $detailedHelp .= "🔐 管理员专用：\n";
                $detailedHelp .= "• /预付 金额表达式 - 增加预存款\n";
                $detailedHelp .= "• /下发 金额表达式 - 减少预存款\n\n";
            }
            
            $detailedHelp .= "💡 使用说明：\n";
            if ($isAdmin) {
                $detailedHelp .= "• 金额表达式支持：100+200-100*100/100\n";
                $detailedHelp .= "• 管理员可以操作预存款\n";
            }
            $detailedHelp .= "• 所有操作都会记录在余额变动日志中\n\n";
            $detailedHelp .= "❓ 如有问题，请联系系统管理员";

            Log::info('帮助命令执行成功', [
                'group_id' => $groupId,
                'sender_id' => $senderId
            ]);

            return [
                'success' => true,
                'message' => $detailedHelp
            ];

        } catch (\Exception $e) {
            Log::error('帮助命令执行异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => "❌ 获取帮助信息失败：{$e->getMessage()}",
                'error_code' => 'HELP_COMMAND_EXCEPTION'
            ];
        }
    }

    /**
     * 注册所有命令到处理器
     */
    private function registerAllCommands(TelegramCommandProcessor $processor): void
    {
        // 所有命令已被移除
    }

    /**
     * 检查用户是否为管理员
     */
    private function checkIfAdmin(array $message): bool
    {
        // 供应商功能已移除
        return true;
    }

    /**
     * 获取命令名称
     */
    public function getCommandName(): string
    {
        return 'help';
    }

    /**
     * 获取命令描述
     */
    public function getDescription(): string
    {
        return '/帮助 - 显示所有可用命令';
    }

    /**
     * 检查消息是否匹配此命令
     */
    public function matches(string $messageText): bool
    {
        return preg_match('/^\/帮助$/', trim($messageText));
    }

    /**
     * 检查用户权限
     */
    public function hasPermission(array $message): bool
    {
        // 供应商功能已移除
        return true;
    }
}

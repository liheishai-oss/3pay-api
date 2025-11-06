<?php

namespace app\admin\controller\v1\robot\commands;

/**
 * Telegram命令接口
 * 企业级命令模式设计
 */
interface TelegramCommandInterface
{
    /**
     * 执行命令
     * @param array $message Telegram消息数据
     * @return array 执行结果
     */
    public function execute(array $message): array;

    /**
     * 获取命令名称
     * @return string
     */
    public function getCommandName(): string;

    /**
     * 获取命令描述
     * @return string
     */
    public function getDescription(): string;

    /**
     * 验证命令权限
     * @param array $message Telegram消息数据
     * @return bool
     */
    public function hasPermission(array $message): bool;
}


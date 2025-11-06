<?php

namespace app\admin\controller\v1\robot\repository;

use app\model\TelegramAdmin;

class TelegramAdminRepository
{
    /**
     * 判断用户是否是管理员
     * @param int $telegramId Telegram 用户 ID
     * @return bool
     */
    public function isAdmin(int $telegramId): bool
    {
        if (!$telegramId) {
            return false;
        }

        return TelegramAdmin::where('status', 1)
            ->where('telegram_id', $telegramId)
            ->exists();
    }

}
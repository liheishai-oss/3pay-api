<?php

namespace app\repository;

use app\common;
use app\model\Admin;
use app\model\RoleGroup;
use app\model\SystemConfig;
use support\Redis;

class AdminAuthRepository
{

    public function getAdminByUsername(string $username): ?Admin
    {
        return Admin::where('username', $username)->first();
    }

    public function isPasswordValid(Admin $admin, string $plainPassword): bool
    {
        return password_verify($plainPassword, $admin->password);
    }

    public function getGoogle2FASecret(int $userId): ?string
    {
        return SystemConfig::where('config_key', 'google_2fa_secret')
            ->where('merchant_id', $userId)
            ->value('config_value');
    }

    public function getAllGroupIdsIncludingSelf(int $groupId): array
    {
        $allGroupIds = $this->getAllSubGroupIds($groupId);
        $allGroupIds[] = $groupId;
        return $allGroupIds;
    }

    public function persistLoginToken(array $userInfo): string
    {
        $token = bin2hex(random_bytes(16));
        $redisKey = common::LOGIN_TOKEN_PREFIX . "admin:{$token}";
        Redis::hset($redisKey, 'admin_id', $userInfo['admin_id']);
        Redis::hset($redisKey, 'username', $userInfo['username']);
        Redis::hset($redisKey, 'nickname', $userInfo['nickname']);
        Redis::hset($redisKey, 'group_id', $userInfo['group_id']);
        Redis::hset($redisKey, 'user_group_id', $userInfo['user_group_id']);
        Redis::hset($redisKey, 'status', $userInfo['status']);
        Redis::expire($redisKey, 86400 * 7);

        return $token;
    }

    private function getAllSubGroupIds(int $parentGroupId, array &$groupIds = []): array
    {
        if (empty($groupIds)) {
            $groupIds = [];
        }

        $subGroups = RoleGroup::where('parent_id', $parentGroupId)->get();
        foreach ($subGroups as $subGroup) {
            $groupIds[] = $subGroup->id;
            $this->getAllSubGroupIds($subGroup->id, $groupIds);
        }

        return $groupIds;
    }
}




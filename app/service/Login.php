<?php

namespace app\service;

use app\common;
use app\exception\MyBusinessException;
use app\model\Admin;
use app\model\RoleGroup;
use app\model\SystemConfig;
use support\Redis;

class Login
{

    public function login(array $param)
    {
        // 查询用户
        $admin = Admin::where('username', $param['username'])->first();

        if (!$admin) {
            throw new MyBusinessException('用户或者密码错误1');
        }

        if ((int)$admin->status !== 1) {
            throw new MyBusinessException('用户已被禁用');
        }

        if (!password_verify($param['password'], $admin->password)) {
            throw new MyBusinessException('用户或者密码错误2');
        }

        // 检查是否首次登录（在Google Auth验证之前）
        if ($admin->is_first_login == 1) {
            return [
                'need_change_password' => true,
                'admin_id' => $admin->id,
                'username' => $admin->username,
                'message' => '首次登录需要修改密码'
            ];
        }

        $google_code = SystemConfig::where('config_key', 'google_2fa_secret')->where('merchant_id',$admin->id)->value('config_value');
        // Google Auth 验证
        if (!$this->verifyGoogleAuth($param['google_code'], $google_code)) {
            throw new MyBusinessException('Google 验证码错误');
        }

        // 如果找到分组信息
        if ($admin->group_id>0) {
            // 查询当前分组及其所有子分组的 ID
            $allGroupIds = $this->getAllSubGroupIds($admin->group_id);
            $allGroupIds[] = $admin->group_id;
            // 商户功能已移除
            $isMerchantAdmin = false;
            
            // 代理商判断（代理商管理组 group_id = 3）
            $isAgent = $admin->group_id == 3;
            $agentId = null;
            
            // 如果是代理商，查询对应的 agent_id
            if ($isAgent) {
                $agent = \app\model\Agent::where('admin_id', $admin->id)->first();
                if ($agent) {
                    $agentId = $agent->id;
                }
            }
            
            // 登录信息准备
            $userInfo = [
                'admin_id' => $admin->id,
                'username' => $admin->username,
                'nickname' => $admin->nickname,
                'user_group_id' => $admin->group_id,
                'group_id' => json_encode($allGroupIds),
                'status' => $admin->status,
                'is_merchant_admin' => $isMerchantAdmin,
                'is_agent' => $isAgent,
                'agent_id' => $agentId,
            ];

            // 生成唯一的 token
            $token = bin2hex(random_bytes(16)); // 生成一个 32 字符的随机 token

            // 使用 HSET 批量写入用户信息
            $redisKey = common::LOGIN_TOKEN_PREFIX."admin:{$token}";

            // 使用 HSET 批量写入
            Redis::hset($redisKey, 'admin_id', $userInfo['admin_id']);
            Redis::hset($redisKey, 'username', $userInfo['username']);
            Redis::hset($redisKey, 'nickname', $userInfo['nickname']);
            Redis::hset($redisKey, 'group_id', $userInfo['group_id']);
            Redis::hset($redisKey, 'user_group_id', $admin->group_id);
            Redis::hset($redisKey, 'status', $userInfo['status']);
            Redis::hset($redisKey, 'is_merchant_admin', $userInfo['is_merchant_admin'] ? '1' : '0');
            Redis::hset($redisKey, 'is_agent', $userInfo['is_agent'] ? '1' : '0');
            Redis::hset($redisKey, 'agent_id', $userInfo['agent_id'] ?? '');
            // 设置过期时间为7天
            Redis::expire($redisKey, 86400*7);
            return  ['Authorization'=>$token];

        } else {
            throw new MyBusinessException('没有找到关联的分组');
        }
    }
    // 验证 Google Auth 验证码
    private function verifyGoogleAuth($googleCode, $secret)
    {
        $googleAuthenticator = new \Sonata\GoogleAuthenticator\GoogleAuthenticator();

        // 验证 Google Auth 验证码
        return $googleAuthenticator->checkCode($secret, $googleCode);
    }
    private function getAllSubGroupIds($groupId): array
    {
        $groupIds = [];
        $this->getSubGroupIdsRecursive($groupId, $groupIds);
        return $groupIds;
    }
// 递归查询当前分组下的所有子分组
    private function getSubGroupIdsRecursive($parentGroupId, &$groupIds)
    {
        // 获取当前父分组的子分组
        $subGroups = RoleGroup::where('parent_id', $parentGroupId)->get();

        foreach ($subGroups as $subGroup) {
            // 将当前分组ID添加到结果数组中
            $groupIds[] = $subGroup->id;

            // 递归查询子分组
            $this->getSubGroupIdsRecursive($subGroup->id, $groupIds);
        }
    }
}
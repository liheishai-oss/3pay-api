<?php

namespace app\service;

use app\admin\controller\v1\system\validator\LoginDataValidator;
use app\common\helpers\ConfigHelper;
use app\exception\MyBusinessException;
use app\model\SystemConfig;
use app\repository\AdminAuthRepository;

class LoginService
{
    public function __construct(private readonly AdminAuthRepository $repository, private readonly LoginDataValidator $doginDataValidator)
    {
    }

    public function login(array $param): array
    {
        // 验证用户凭据
        $admin = $this->doginDataValidator->validate($param, false);

        // 检查是否开启谷歌验证
        $config = ConfigHelper::getAll();
        $googleEnabled = json_decode($config['admin_login_verify_mode'] ?? '[]', true);
        
        if (is_array($googleEnabled) && in_array('google', $googleEnabled)) {
            // 检查用户是否已绑定谷歌验证码
            $googleSecret = $this->repository->getGoogle2FASecret($admin->id);
            
            if (!$googleSecret) {
                // 未绑定，返回需要绑定的信息
                return [
                    'need_bind_google' => true,
                    'admin_id' => $admin->id,
                    'username' => $admin->username,
                    'message' => '请先绑定谷歌验证码'
                ];
            }
            
            // 已绑定，验证谷歌验证码
            if (empty($param['google_code'])) {
                throw new MyBusinessException('请输入谷歌验证码');
            }
            
            if (!$this->verifyGoogleAuth($param['google_code'], $googleSecret)) {
                throw new MyBusinessException('谷歌验证码错误');
            }
        }

        // 检查是否首次登录（在谷歌验证之后）
        if ($admin->is_first_login == 1) {
            return [
                'need_change_password' => true,
                'admin_id' => $admin->id,
                'username' => $admin->username,
                'message' => '首次登录需要修改密码'
            ];
        }

        if ($admin->group_id <= 0) {
            throw new MyBusinessException('用户信息错误');
        }

        $allGroupIds = $this->repository->getAllGroupIdsIncludingSelf($admin->group_id);
        
        // 判断是否为代理商（代理商管理组 group_id = 3）
        $isAgent = $admin->group_id == 3;
        $agentId = null;

        // 如果是代理商，查询对应的 agent_id
        if ($isAgent) {
            $agent = \app\model\Agent::where('admin_id', $admin->id)->first();

            if ($agent) {
                $agentId = $agent->id;
            }
        }
        
        $userInfo = [
            'admin_id' => $admin->id,
            'username' => $admin->username,
            'nickname' => $admin->nickname,
            'user_group_id' => $admin->group_id,
            'group_id' => json_encode($allGroupIds),
            'status' => $admin->status,
            'is_agent' => $isAgent ? '1' : '0',  // 添加代理商标识
            'agent_id' => $agentId,               // 添加代理商ID
        ];

        $token = $this->repository->persistLoginToken($userInfo);

        return ['Authorization' => $token];
    }

    private function verifyGoogleAuth(string $googleCode, string $secret): bool
    {
        $googleAuthenticator = new \Google\Authenticator\GoogleAuthenticator();
        return $googleAuthenticator->checkCode($secret, $googleCode);
    }
}
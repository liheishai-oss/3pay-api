<?php

namespace app\service;

use app\admin\controller\v1\system\validator\LoginDataValidator;
use app\common\helpers\ConfigHelper;
use app\exception\MyBusinessException;
use app\model\SystemConfig;
use app\repository\AdminAuthRepository;
use support\Request;
use support\Log;

class LoginService
{
    public function __construct(private readonly AdminAuthRepository $repository, private readonly LoginDataValidator $doginDataValidator)
    {
    }

    public function login(array $param, ?Request $request = null): array
    {
        // 验证用户凭据
        $admin = $this->doginDataValidator->validate($param, false);

        // 获取客户端IP和域名
        $clientIp = '0.0.0.0';
        $remoteIp = '0.0.0.0';
        $host = '';
        $hostWithPort = '';
        if ($request) {
            // 获取真实IP（可能经过代理）
            $clientIp = $request->getRealIp();
            // 获取直接连接IP（更准确）
            $remoteIp = $request->getRemoteIp();
            // 获取域名（不包含端口）
            $host = $request->host(true) ?? '';
            // 获取域名（包含端口，用于更全面的检测）
            $hostWithPort = $request->host(false) ?? '';
            // 从header中获取host（备用方案）
            if (empty($host)) {
                $host = $request->header('host') ?? '';
                // 去除端口号
                if ($host && preg_match('/^([^:]+)/', $host, $matches)) {
                    $host = $matches[1];
                }
            }
        }

        // 判断是否为商户（商户管理组 group_id = 4）
        $isMerchant = $admin->group_id == 4;
        // 判断是否为本地IP（支持多种格式：127.0.0.1, ::1, localhost, 0.0.0.0）
        $isLocalIp = $this->isLocalIp($clientIp) || $this->isLocalIp($remoteIp);
        // 判断域名是否包含localhost（检查多个来源）
        $isLocalhostDomain = (!empty($host) && stripos($host, 'localhost') !== false) 
                          || (!empty($hostWithPort) && stripos($hostWithPort, 'localhost') !== false);

        // 商户、本地IP或localhost域名跳过谷歌验证码检查
        $isLocalhost = $isLocalIp || $isLocalhostDomain;

        // 调试日志：记录IP和域名检测信息
        Log::info('登录IP检测', [
            'username' => $admin->username,
            'client_ip' => $clientIp,
            'remote_ip' => $remoteIp,
            'host' => $host,
            'host_with_port' => $hostWithPort,
            'header_host' => $request ? ($request->header('host') ?? '') : '',
            'is_merchant' => $isMerchant,
            'is_local_ip' => $isLocalIp,
            'is_localhost_domain' => $isLocalhostDomain,
            'is_localhost' => $isLocalhost,
            'skip_google_auth' => $isMerchant || $isLocalhost
        ]);

        // 商户或本地访问（IP或域名）跳过谷歌验证码检查，其他用户需要验证
        if (!$isMerchant && !$isLocalhost) {
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
                    // 记录详细信息用于调试
                    Log::warning('需要谷歌验证码', [
                        'username' => $admin->username,
                        'client_ip' => $clientIp,
                        'remote_ip' => $remoteIp,
                        'host' => $host,
                        'is_merchant' => $isMerchant,
                        'is_localhost' => $isLocalhost,
                        'has_google_code' => !empty($param['google_code'])
                    ]);
                    throw new MyBusinessException('请输入谷歌验证码');
                }
                
                if (!$this->verifyGoogleAuth($param['google_code'], $googleSecret)) {
                    throw new MyBusinessException('谷歌验证码错误');
                }
            }
        } else {
            // 记录跳过验证的信息
            Log::info('跳过谷歌验证码', [
                'username' => $admin->username,
                'reason' => $isMerchant ? '商户' : '本地访问',
                'client_ip' => $clientIp,
                'remote_ip' => $remoteIp,
                'host' => $host
            ]);
        }

        // 检查是否首次登录（商户必须修改密码）
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

    /**
     * 判断是否为本地IP地址
     * 
     * @param string $ip IP地址
     * @return bool
     */
    private function isLocalIp(string $ip): bool
    {
        if (empty($ip)) {
            return false;
        }

        // 本地IPv4地址
        $localIpv4 = ['127.0.0.1', 'localhost', '0.0.0.0'];
        if (in_array($ip, $localIpv4, true)) {
            return true;
        }

        // 本地IPv6地址
        if ($ip === '::1' || $ip === '[::1]') {
            return true;
        }

        // 检查是否为127.x.x.x网段
        if (strpos($ip, '127.') === 0) {
            return true;
        }

        return false;
    }
}
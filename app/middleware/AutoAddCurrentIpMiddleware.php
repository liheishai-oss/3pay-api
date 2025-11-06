<?php

namespace app\middleware;

use support\Request;
use support\Response;
use app\model\Server;
use support\Log;

class AutoAddCurrentIpMiddleware
{
    /**
     * 处理请求
     * @param Request $request
     * @param callable $next
     * @return Response
     */
    public function process(Request $request, callable $next): Response
    {
        try {
            // 检查并自动添加当前外网IP
            $this->checkAndAddCurrentIp();
        } catch (\Exception $e) {
            Log::error('自动添加当前IP失败', [
                'error' => $e->getMessage(),
                'request_uri' => $request->uri()
            ]);
        }

        return $next($request);
    }

    /**
     * 检查并自动添加当前外网IP
     * @return array
     */
    private function checkAndAddCurrentIp(): array
    {
        try {
            // 获取当前外网IP
            $currentIp = $this->getCurrentPublicIp();
            if (!$currentIp) {
                return ['success' => false, 'message' => '无法获取当前外网IP'];
            }

            // 检查IP是否已存在
            $existingServer = Server::where('server_ip', $currentIp)->first();
            if ($existingServer) {
                return ['success' => true, 'message' => '当前IP已存在', 'server' => $existingServer];
            }

            // 自动添加当前IP
            $server = new Server();
            $server->server_name = '自动添加-' . $currentIp;
            $server->server_ip = $currentIp;
            $server->server_port = 80;
            $server->server_type = 'web';
            $server->status = 'online';
            $server->is_maintenance = false;
            $server->cpu_usage = 0;
            $server->memory_usage = 0;
            $server->disk_usage = 0;
            $server->network_usage = 0;
            $server->remarks = '系统自动添加的当前服务器IP';
            $server->save();

            Log::info('自动添加当前外网IP到服务器列表', [
                'ip' => $currentIp,
                'server_id' => $server->id
            ]);

            return ['success' => true, 'message' => '已自动添加当前IP', 'server' => $server];
        } catch (\Exception $e) {
            Log::error('检查并添加当前IP失败', [
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => '添加失败: ' . $e->getMessage()];
        }
    }

    /**
     * 获取当前webman服务器的外网IP
     * @return string|null
     */
    private function getCurrentPublicIp(): ?string
    {
        try {
            // 首先尝试从环境变量获取
            $envIp = getenv('SERVER_PUBLIC_IP');
            if ($envIp && filter_var($envIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $envIp;
            }

            // 尝试从配置文件获取
            $configIp = config('app.server_public_ip');
            if ($configIp && filter_var($configIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $configIp;
            }

            // 尝试多个IP检测服务
            $services = [
                'https://api.ipify.org',
                'https://ipinfo.io/ip',
                'https://icanhazip.com',
                'https://ifconfig.me/ip'
            ];

            foreach ($services as $service) {
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 3,
                        'user_agent' => 'Mozilla/5.0 (compatible; WebmanServer/1.0)'
                    ]
                ]);
                
                $ip = @file_get_contents($service, false, $context);
                if ($ip && filter_var(trim($ip), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return trim($ip);
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error('获取webman服务器外网IP失败', ['error' => $e->getMessage()]);
            return null;
        }
    }
}

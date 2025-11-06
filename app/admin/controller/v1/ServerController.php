<?php

namespace app\admin\controller\v1;

use app\model\Server;
use support\Request;
use support\Response;
use support\Log;

class ServerController
{
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
     * 获取当前外网IP
     * @return string|null
     */
    private function getCurrentPublicIp(): ?string
    {
        try {
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
                        'user_agent' => 'Mozilla/5.0 (compatible; ServerManager/1.0)'
                    ]
                ]);
                
                $ip = @file_get_contents($service, false, $context);
                if ($ip && filter_var(trim($ip), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return trim($ip);
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error('获取外网IP失败', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * 获取服务器列表
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        try {
            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 20);
            $search = $request->input('search', []);

            $query = Server::query();

            // 搜索条件
            if (!empty($search['server_name'])) {
                $query->where('server_name', 'like', '%' . $search['server_name'] . '%');
            }
            if (!empty($search['server_ip'])) {
                $query->where('server_ip', 'like', '%' . $search['server_ip'] . '%');
            }
            if (!empty($search['status'])) {
                $query->where('status', $search['status']);
            }
            if (isset($search['is_maintenance']) && $search['is_maintenance'] !== '') {
                $query->where('is_maintenance', $search['is_maintenance']);
            }

            $servers = $query->orderBy('created_at', 'desc')
                           ->paginate($perPage, ['*'], 'page', $page);

            return json([
                'code' => 200,
                'status' => true,
                'message' => '获取成功',
                'data' => $servers
            ]);
        } catch (\Exception $e) {
            Log::error('获取服务器列表失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return json([
                'code' => 500,
                'status' => false,
                'message' => '获取失败: ' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 获取服务器详情
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function show(Request $request, int $id): Response
    {
        try {
            $server = Server::find($id);
            
            if (!$server) {
                return json([
                    'code' => 404,
                    'status' => false,
                    'message' => '服务器不存在',
                    'data' => null
                ]);
            }

            return json([
                'code' => 200,
                'status' => true,
                'message' => '获取成功',
                'data' => $server
            ]);
        } catch (\Exception $e) {
            Log::error('获取服务器详情失败', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return json([
                'code' => 500,
                'status' => false,
                'message' => '获取失败: ' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 创建服务器
     * @param Request $request
     * @return Response
     */
    public function store(Request $request): Response
    {
        try {
            $data = $request->all();
            
            // 验证必填字段
            $required = ['server_name', 'server_ip'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return json([
                        'code' => 400,
                        'status' => false,
                        'message' => "字段 {$field} 不能为空",
                        'data' => null
                    ]);
                }
            }

            // 检查IP和端口是否已存在
            $exists = Server::where('server_ip', $data['server_ip'])
                          ->where('server_port', $data['server_port'] ?? 80)
                          ->exists();
            
            if ($exists) {
                return json([
                    'code' => 400,
                    'status' => false,
                    'message' => '该IP和端口的服务器已存在',
                    'data' => null
                ]);
            }

            $server = Server::create($data);

            return json([
                'code' => 200,
                'status' => true,
                'message' => '创建成功',
                'data' => $server
            ]);
        } catch (\Exception $e) {
            Log::error('创建服务器失败', [
                'data' => $request->all(),
                'error' => $e->getMessage()
            ]);
            
            return json([
                'code' => 500,
                'status' => false,
                'message' => '创建失败: ' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 更新服务器
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function update(Request $request, int $id): Response
    {
        try {
            $server = Server::find($id);
            
            if (!$server) {
                return json([
                    'code' => 404,
                    'status' => false,
                    'message' => '服务器不存在',
                    'data' => null
                ]);
            }

            $data = $request->all();
            $server->update($data);

            return json([
                'code' => 200,
                'status' => true,
                'message' => '更新成功',
                'data' => $server
            ]);
        } catch (\Exception $e) {
            Log::error('更新服务器失败', [
                'id' => $id,
                'data' => $request->all(),
                'error' => $e->getMessage()
            ]);
            
            return json([
                'code' => 500,
                'status' => false,
                'message' => '更新失败: ' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 切换维护状态
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function toggleMaintenance(Request $request, int $id): Response
    {
        try {
            $server = Server::find($id);
            
            if (!$server) {
                return json([
                    'code' => 404,
                    'status' => false,
                    'message' => '服务器不存在',
                    'data' => null
                ]);
            }

            $isMaintenance = $request->input('is_maintenance', !$server->is_maintenance);
            $server->update(['is_maintenance' => $isMaintenance]);

            return json([
                'code' => 200,
                'status' => true,
                'message' => $isMaintenance ? '已开启维护模式' : '已关闭维护模式',
                'data' => $server
            ]);
        } catch (\Exception $e) {
            Log::error('切换维护状态失败', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return json([
                'code' => 500,
                'status' => false,
                'message' => '操作失败: ' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 更新服务器状态
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function updateStatus(Request $request, int $id): Response
    {
        try {
            $server = Server::find($id);
            
            if (!$server) {
                return json([
                    'code' => 404,
                    'status' => false,
                    'message' => '服务器不存在',
                    'data' => null
                ]);
            }

            $status = $request->input('status');
            $metrics = $request->input('metrics', []);

            $updateData = ['status' => $status];
            
            // 更新系统指标
            if (isset($metrics['cpu_usage'])) {
                $updateData['cpu_usage'] = $metrics['cpu_usage'];
            }
            if (isset($metrics['memory_usage'])) {
                $updateData['memory_usage'] = $metrics['memory_usage'];
            }
            if (isset($metrics['disk_usage'])) {
                $updateData['disk_usage'] = $metrics['disk_usage'];
            }
            if (isset($metrics['load_average'])) {
                $updateData['load_average'] = $metrics['load_average'];
            }
            if (isset($metrics['uptime'])) {
                $updateData['uptime'] = $metrics['uptime'];
            }

            $updateData['last_check_time'] = now();
            
            $server->update($updateData);

            return json([
                'code' => 200,
                'status' => true,
                'message' => '状态更新成功',
                'data' => $server
            ]);
        } catch (\Exception $e) {
            Log::error('更新服务器状态失败', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return json([
                'code' => 500,
                'status' => false,
                'message' => '更新失败: ' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 删除服务器（仅允许删除离线状态的服务器）
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function destroy(Request $request, int $id): Response
    {
        try {
            $server = Server::find($id);
            
            if (!$server) {
                return json([
                    'code' => 404,
                    'status' => false,
                    'message' => '服务器不存在',
                    'data' => null
                ]);
            }

            // 只允许删除离线状态的服务器
            if ($server->status !== Server::STATUS_OFFLINE) {
                return json([
                    'code' => 400,
                    'status' => false,
                    'message' => '只能删除离线状态的服务器',
                    'data' => null
                ]);
            }

            $server->delete();

            Log::info('服务器删除成功', [
                'id' => $id,
                'server_name' => $server->server_name,
                'server_ip' => $server->server_ip
            ]);

            return json([
                'code' => 200,
                'status' => true,
                'message' => '删除成功',
                'data' => null
            ]);
        } catch (\Exception $e) {
            Log::error('删除服务器失败', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return json([
                'code' => 500,
                'status' => false,
                'message' => '删除失败: ' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 检查服务器维护状态并返回nginx配置
     * @param Request $request
     * @return Response
     */
    public function checkMaintenanceStatus(Request $request): Response
    {
        try {
            // 获取所有维护中的服务器
            $maintenanceServers = Server::where('is_maintenance', true)
                ->orWhere('status', Server::STATUS_MAINTENANCE)
                ->get();

            if ($maintenanceServers->isEmpty()) {
                return json([
                    'code' => 200,
                    'status' => true,
                    'message' => '没有服务器处于维护状态',
                    'data' => [
                        'maintenance_detected' => false,
                        'nginx_config' => null
                    ]
                ]);
            }

            // 检测到维护状态，输出nginx配置
            $nginxConfig = "proxy_next_upstream error timeout invalid_header http_500 http_502 http_503 http_504;";
            
            $maintenanceInfo = $maintenanceServers->map(function ($server) {
                return [
                    'id' => $server->id,
                    'server_name' => $server->server_name,
                    'server_ip' => $server->server_ip,
                    'server_port' => $server->server_port,
                    'status' => $server->status,
                    'is_maintenance' => $server->is_maintenance,
                    'last_check_time' => $server->last_check_time
                ];
            });

            Log::info('检测到服务器维护状态', [
                'maintenance_servers' => $maintenanceInfo->toArray(),
                'nginx_config' => $nginxConfig
            ]);

            return json([
                'code' => 200,
                'status' => true,
                'message' => '检测到维护状态',
                'data' => [
                    'maintenance_detected' => true,
                    'nginx_config' => $nginxConfig,
                    'maintenance_servers' => $maintenanceInfo,
                    'maintenance_count' => $maintenanceServers->count()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('检查维护状态失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return json([
                'code' => 500,
                'status' => false,
                'message' => '检查失败: ' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 获取nginx负载均衡配置
     * @param Request $request
     * @return Response
     */
    public function getNginxConfig(Request $request): Response
    {
        try {
            // 获取所有在线且非维护状态的服务器
            $activeServers = Server::where('status', Server::STATUS_ONLINE)
                ->where('is_maintenance', false)
                ->get();

            if ($activeServers->isEmpty()) {
                return json([
                    'code' => 200,
                    'status' => true,
                    'message' => '没有可用的服务器',
                    'data' => [
                        'nginx_config' => null,
                        'upstream_servers' => []
                    ]
                ]);
            }

            // 构建nginx upstream配置
            $upstreamServers = $activeServers->map(function ($server) {
                return [
                    'server' => $server->server_ip . ':' . $server->server_port,
                    'weight' => 1,
                    'max_fails' => 3,
                    'fail_timeout' => '30s'
                ];
            });

            $nginxUpstreamConfig = "upstream backend {\n";
            foreach ($upstreamServers as $server) {
                $nginxUpstreamConfig .= "    server {$server['server']} weight={$server['weight']} max_fails={$server['max_fails']} fail_timeout={$server['fail_timeout']};\n";
            }
            $nginxUpstreamConfig .= "}";

            $nginxProxyConfig = "proxy_next_upstream error timeout invalid_header http_500 http_502 http_503 http_504;";

            Log::info('生成nginx配置', [
                'active_servers_count' => $activeServers->count(),
                'nginx_config' => $nginxUpstreamConfig
            ]);

            return json([
                'code' => 200,
                'status' => true,
                'message' => '获取nginx配置成功',
                'data' => [
                    'nginx_upstream_config' => $nginxUpstreamConfig,
                    'nginx_proxy_config' => $nginxProxyConfig,
                    'upstream_servers' => $upstreamServers,
                    'active_servers_count' => $activeServers->count()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('获取nginx配置失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return json([
                'code' => 500,
                'status' => false,
                'message' => '获取配置失败: ' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 部署服务器
     * @param Request $request
     * @return Response
     */
    public function deploy(Request $request): Response
    {
        try {
            $mode = $request->input('mode', 'update_restart');
            $serverIds = $request->input('server_ids', []);
            $description = $request->input('description', '');

            if (empty($serverIds)) {
                return json([
                    'code' => 400,
                    'status' => false,
                    'message' => '请选择要部署的服务器',
                    'data' => null
                ]);
            }

            // 获取服务器信息
            $servers = Server::whereIn('id', $serverIds)->get();
            if ($servers->isEmpty()) {
                return json([
                    'code' => 404,
                    'status' => false,
                    'message' => '未找到指定的服务器',
                    'data' => null
                ]);
            }

            // 生成部署脚本
            $deployScript = $this->generateDeployScript($servers, $mode, $description);
            
            // 保存部署脚本到文件
            $scriptPath = base_path() . '/runtime/deploy_' . date('YmdHis') . '.sh';
            file_put_contents($scriptPath, $deployScript);
            chmod($scriptPath, 0755);

            Log::info('部署脚本已生成', [
                'mode' => $mode,
                'server_count' => $servers->count(),
                'script_path' => $scriptPath,
                'description' => $description
            ]);

            return json([
                'code' => 200,
                'status' => true,
                'message' => '部署脚本已生成',
                'data' => [
                    'script_path' => $scriptPath,
                    'mode' => $mode,
                    'server_count' => $servers->count(),
                    'servers' => $servers->map(function($server) {
                        return [
                            'id' => $server->id,
                            'name' => $server->server_name,
                            'ip' => $server->server_ip
                        ];
                    })
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('部署失败', [
                'error' => $e->getMessage(),
                'mode' => $request->input('mode'),
                'server_ids' => $request->input('server_ids')
            ]);
            
            return json([
                'code' => 500,
                'status' => false,
                'message' => '部署失败: ' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 生成部署脚本
     * @param $servers
     * @param string $mode
     * @param string $description
     * @return string
     */
    private function generateDeployScript($servers, $mode, $description): string
    {
        $script = "#!/bin/bash\n";
        $script .= "# 服务器部署脚本\n";
        $script .= "# 生成时间: " . date('Y-m-d H:i:s') . "\n";
        $script .= "# 部署模式: " . ($mode === 'update_restart' ? '更新并重启' : '只更新') . "\n";
        $script .= "# 部署说明: {$description}\n\n";

        $script .= "# 设置错误时退出\n";
        $script .= "set -e\n\n";

        $script .= "# 颜色定义\n";
        $script .= "RED='\\033[0;31m'\n";
        $script .= "GREEN='\\033[0;32m'\n";
        $script .= "YELLOW='\\033[1;33m'\n";
        $script .= "NC='\\033[0m' # No Color\n\n";

        $script .= "# 日志函数\n";
        $script .= "log_info() {\n";
        $script .= "    echo -e \"\${GREEN}[INFO]\${NC} \$1\"\n";
        $script .= "}\n\n";

        $script .= "log_warn() {\n";
        $script .= "    echo -e \"\${YELLOW}[WARN]\${NC} \$1\"\n";
        $script .= "}\n\n";

        $script .= "log_error() {\n";
        $script .= "    echo -e \"\${RED}[ERROR]\${NC} \$1\"\n";
        $script .= "}\n\n";

        $script .= "# 检查服务器在线状态\n";
        $script .= "check_server_status() {\n";
        $script .= "    local ip=\$1\n";
        $script .= "    local name=\$2\n";
        $script .= "    \n";
        $script .= "    log_info \"检查服务器状态: \$name (\$ip)\"\n";
        $script .= "    \n";
        $script .= "    # 检查ping连通性\n";
        $script .= "    if ping -c 1 -W 3 \$ip > /dev/null 2>&1; then\n";
        $script .= "        log_info \"服务器 \$name (\$ip) 网络连通正常\"\n";
        $script .= "        return 0\n";
        $script .= "    else\n";
        $script .= "        log_error \"服务器 \$name (\$ip) 网络不通\"\n";
        $script .= "        return 1\n";
        $script .= "    fi\n";
        $script .= "}\n\n";

        $script .= "# 检查webman服务状态\n";
        $script .= "check_webman_status() {\n";
        $script .= "    local ip=\$1\n";
        $script .= "    \n";
        $script .= "    log_info \"检查webman服务状态: \$ip\"\n";
        $script .= "    \n";
        $script .= "    # 通过SSH检查systemctl状态\n";
        $script .= "    if ssh -o ConnectTimeout=10 -o StrictHostKeyChecking=no root@\$ip 'systemctl is-active payment-service' > /dev/null 2>&1; then\n";
        $script .= "        log_info \"webman服务运行正常\"\n";
        $script .= "        return 0\n";
        $script .= "    else\n";
        $script .= "        log_warn \"webman服务未运行或状态异常\"\n";
        $script .= "        return 1\n";
        $script .= "    fi\n";
        $script .= "}\n\n";

        $script .= "# 更新代码\n";
        $script .= "update_code() {\n";
        $script .= "    local ip=\$1\n";
        $script .= "    \n";
        $script .= "    log_info \"更新代码: \$ip\"\n";
        $script .= "    \n";
        $script .= "    # 通过SSH执行git pull，从指定仓库拉取代码\n";
        $script .= "    ssh -o ConnectTimeout=10 -o StrictHostKeyChecking=no root@\$ip 'cd /www/api.gflzt.cn && git pull https://github.com/liheishai-oss/4pay-api.git main'\n";
        $script .= "    \n";
        $script .= "    if [ \$? -eq 0 ]; then\n";
        $script .= "        log_info \"代码更新成功\"\n";
        $script .= "    else\n";
        $script .= "        log_error \"代码更新失败\"\n";
        $script .= "        return 1\n";
        $script .= "    fi\n";
        $script .= "}\n\n";

        $script .= "# 重启webman服务\n";
        $script .= "restart_webman() {\n";
        $script .= "    local ip=\$1\n";
        $script .= "    \n";
        $script .= "    log_info \"重启webman服务: \$ip\"\n";
        $script .= "    \n";
        $script .= "    # 通过SSH重启服务\n";
        $script .= "    ssh -o ConnectTimeout=10 -o StrictHostKeyChecking=no root@\$ip 'systemctl restart payment-service'\n";
        $script .= "    \n";
        $script .= "    if [ \$? -eq 0 ]; then\n";
        $script .= "        log_info \"webman服务重启成功\"\n";
        $script .= "        # 等待服务启动\n";
        $script .= "        sleep 5\n";
        $script .= "        check_webman_status \$ip\n";
        $script .= "    else\n";
        $script .= "        log_error \"webman服务重启失败\"\n";
        $script .= "        return 1\n";
        $script .= "    fi\n";
        $script .= "}\n\n";

        $script .= "# 主部署流程\n";
        $script .= "deploy_server() {\n";
        $script .= "    local ip=\$1\n";
        $script .= "    local name=\$2\n";
        $script .= "    \n";
        $script .= "    log_info \"开始部署服务器: \$name (\$ip)\"\n";
        $script .= "    \n";
        $script .= "    # 检查服务器状态\n";
        $script .= "    if ! check_server_status \$ip \"\$name\"; then\n";
        $script .= "        log_error \"服务器 \$name (\$ip) 不可达，跳过部署\"\n";
        $script .= "        return 1\n";
        $script .= "    fi\n";
        $script .= "    \n";
        $script .= "    # 更新代码\n";
        $script .= "    if ! update_code \$ip; then\n";
        $script .= "        log_error \"代码更新失败，跳过部署\"\n";
        $script .= "        return 1\n";
        $script .= "    fi\n";
        $script .= "    \n";
        $script .= "    # 根据模式决定是否重启\n";
        $script .= "    if [ \"$mode\" = \"update_restart\" ]; then\n";
        $script .= "        if ! restart_webman \$ip; then\n";
        $script .= "            log_error \"webman服务重启失败\"\n";
        $script .= "            return 1\n";
        $script .= "        fi\n";
        $script .= "    else\n";
        $script .= "        log_info \"只更新模式，不重启服务\"\n";
        $script .= "    fi\n";
        $script .= "    \n";
        $script .= "    log_info \"服务器 \$name (\$ip) 部署完成\"\n";
        $script .= "    return 0\n";
        $script .= "}\n\n";

        $script .= "# 开始部署\n";
        $script .= "log_info \"开始批量部署，共 " . $servers->count() . " 台服务器\"\n";
        $script .= "log_info \"部署模式: " . ($mode === 'update_restart' ? '更新并重启' : '只更新') . "\"\n\n";

        $successCount = 0;
        $failCount = 0;

        foreach ($servers as $server) {
            $script .= "# 部署服务器: {$server->server_name} ({$server->server_ip})\n";
            $script .= "if deploy_server {$server->server_ip} \"{$server->server_name}\"; then\n";
            $script .= "    log_info \"服务器 {$server->server_name} 部署成功\"\n";
            $script .= "    ((successCount++))\n";
            $script .= "else\n";
            $script .= "    log_error \"服务器 {$server->server_name} 部署失败\"\n";
            $script .= "    ((failCount++))\n";
            $script .= "fi\n\n";
        }

        $script .= "# 部署结果统计\n";
        $script .= "log_info \"部署完成！成功: \$successCount 台，失败: \$failCount 台\"\n";
        $script .= "if [ \$failCount -gt 0 ]; then\n";
        $script .= "    log_warn \"有 \$failCount 台服务器部署失败，请检查日志\"\n";
        $script .= "    exit 1\n";
        $script .= "else\n";
        $script .= "    log_info \"所有服务器部署成功！\"\n";
        $script .= "    exit 0\n";
        $script .= "fi\n";

        return $script;
    }
}

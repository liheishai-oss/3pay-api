<?php

namespace app\middleware;

use support\Request;
use support\Response;
use app\model\Server;
use support\Log;
use Webman\MiddlewareInterface;

/**
 * 维护状态检查中间件
 * 在API请求前检查服务器维护状态，如果检测到维护状态则返回nginx配置
 */
class MaintenanceCheckMiddleware implements MiddlewareInterface
{
    /**
     * 处理请求
     * @param \Webman\Http\Request $request
     * @param callable $handler
     * @return \Webman\Http\Response
     */
    public function process(\Webman\Http\Request $request, callable $handler): \Webman\Http\Response
    {
        try {
            // 只对API请求进行维护状态检查，跳过前端页面请求
            $uri = $request->uri();
            $host = $request->host();
            $serverPort = $_SERVER['SERVER_PORT'] ?? null;
            
            Log::info('维护状态检查中间件开始', [
                'uri' => $uri,
                'host' => $host,
                'server_port' => $serverPort,
                'is_api' => str_starts_with($uri, '/api/'),
                'method' => $request->method()
            ]);
            
            // 排除所有管理员接口，避免管理员无法在维护期间管理服务器状态
            if (str_starts_with($uri, '/api/v1/admin')) {
                Log::info('跳过管理员接口的维护状态检查', [
                    'uri' => $uri
                ]);
                return $handler($request);
            }
            
            // 获取当前服务器IP（从.env文件获取）
            $currentServerIp = $this->getServerIpFromEnv();
            
            // 如果无法获取到IP，直接返回503
            if (empty($currentServerIp)) {
                Log::info('无法获取有效服务器IP，返回503维护状态', [
                    'current_server_ip' => $currentServerIp,
                    'request_uri' => $request->uri(),
                    'request_method' => $request->method()
                ]);
                return new Response(503, [
                    'Content-Type' => 'application/json',
                    'Retry-After' => '300'
                ], json_encode([
                    'code' => 503,
                    'status' => false,
                    'message' => 'Service Unavailable - Maintenance Mode',
                    'data' => null
                ]));
            }
            
            // 检查数据库表是否存在
            try {
                // 查询当前服务器IP的维护状态
                $currentServer = Server::where([
                    'server_ip'=>$currentServerIp,
//                    'is_maintenance'=>1,
//                    'status'=>Server::STATUS_MAINTENANCE,
                ])->first();
            } catch (\Exception $dbError) {
                // 如果数据库表不存在或连接失败，返回503维护状态
                Log::warning('数据库连接失败，返回503维护状态', [
                    'error' => $dbError->getMessage(),
                    'request_uri' => $request->uri(),
                    'current_server_ip' => $currentServerIp
                ]);

                return new Response(503, [
                    'Content-Type' => 'application/json',
                    'Retry-After' => '300'
                ], json_encode([
                    'code' => 503,
                    'status' => false,
                    'message' => 'Service Unavailable - Maintenance Mode',
                    'data' => null
                ]));
            }

            if ($currentServer) {
                if($currentServer->is_maintenance != 1 && $currentServer->status != Server::STATUS_MAINTENANCE){
                    return $handler($request);
                }
                // 检测到当前服务器处于维护状态，直接返回HTTP 503状态码
                Log::info('检测到当前服务器维护状态，返回HTTP 503', [
                    'current_server' => [
                        'id' => $currentServer->id,
                        'server_name' => $currentServer->server_name,
                        'server_ip' => $currentServer->server_ip,
                        'server_port' => $currentServer->server_port,
                        'status' => $currentServer->status,
                        'is_maintenance' => $currentServer->is_maintenance
                    ],
                    'request_uri' => $request->uri(),
                    'request_method' => $request->method(),
                    'current_server_ip' => $currentServerIp
                ]);

                // 直接返回HTTP 503状态码，让nginx知道服务器在维护
                return new Response(503, [
                    'Content-Type' => 'application/json',
                    'Retry-After' => '300'
                ], json_encode([
                    'code' => 503,
                    'status' => false,
                    'message' => 'Service Unavailable - Maintenance Mode',
                    'data' => null
                ]));
            } else {
                // 数据库中不存在该IP的服务器记录，返回503维护状态
                Log::info('数据库中不存在该IP的服务器记录，返回503维护状态', [
                    'current_server_ip' => $currentServerIp,
                    'request_uri' => $request->uri(),
                    'request_method' => $request->method()
                ]);

                return new Response(503, [
                    'Content-Type' => 'application/json',
                    'Retry-After' => '300'
                ], json_encode([
                    'code' => 503,
                    'status' => false,
                    'message' => 'Service Unavailable - Maintenance Mode',
                    'data' => null
                ]));
            }

        } catch (\Exception $e) {
            Log::error('维护状态检查失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_uri' => $request->uri()
            ]);

            // 检查失败时，为了安全起见，继续处理请求
            return $handler($request);
        }
    }

    /**
     * 从.env文件获取服务器IP
     * @return string
     */
    private function getServerIpFromEnv(): string
    {
        // 从.env文件获取服务器IP
        $serverIp = env('SERVER_IP', '');
        
        // 如果.env中没有配置，尝试从系统获取
        if (empty($serverIp)) {
            // 优先使用SERVER_ADDR
            if (!empty($_SERVER['SERVER_ADDR'])) {
                $serverIp = $_SERVER['SERVER_ADDR'];
            } else {
                // 默认返回127.0.0.1
                $serverIp = '127.0.0.1';
            }
        }
        
        return $serverIp;
    }

}

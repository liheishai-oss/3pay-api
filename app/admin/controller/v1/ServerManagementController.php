<?php

namespace app\admin\controller\v1;

use app\service\ServerManagementService;
use support\Request;
use support\Response;
use support\Log;

/**
 * 服务器管理控制器
 */
class ServerManagementController
{
    private ServerManagementService $serverService;
    
    public function __construct()
    {
        $this->serverService = new ServerManagementService();
    }
    
    /**
     * 获取当前服务器状态
     * @param Request $request
     * @return Response
     */
    public function getCurrentServerStatus(Request $request): Response
    {
        try {
            $status = $this->serverService->getServerStatus();
            
            return json([
                'code' => 200,
                'status' => true,
                'message' => '获取成功',
                'data' => $status
            ]);
        } catch (\Exception $e) {
            Log::error('获取当前服务器状态失败', [
                'error' => $e->getMessage()
            ]);
            
            return json([
                'code' => 500,
                'status' => false,
                'message' => '获取失败：' . $e->getMessage(),
                'data' => null
            ]);
        }
    }
    
    /**
     * 获取所有服务器状态
     * @param Request $request
     * @return Response
     */
    public function getAllServersStatus(Request $request): Response
    {
        try {
            $servers = $this->serverService->getAllServersStatus();
            
            return json([
                'code' => 200,
                'status' => true,
                'message' => '获取成功',
                'data' => [
                    'servers' => $servers,
                    'total' => count($servers)
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('获取所有服务器状态失败', [
                'error' => $e->getMessage()
            ]);
            
            return json([
                'code' => 500,
                'status' => false,
                'message' => '获取失败：' . $e->getMessage(),
                'data' => null
            ]);
        }
    }
    
    /**
     * 设置服务器状态
     * @param Request $request
     * @return Response
     */
    public function setServerStatus(Request $request): Response
    {
        try {
            $data = $request->all();
            
            $status = $data['status'] ?? '';
            $serverId = $data['server_id'] ?? null;
            $message = $data['message'] ?? '';
            
            if (!in_array($status, ['enabled', 'disabled', 'testing'])) {
                return json([
                    'code' => 400,
                    'status' => false,
                    'message' => '状态参数错误，支持：enabled(启用), disabled(停用), testing(测试)',
                    'data' => null
                ]);
            }
            
            $result = $this->serverService->setServerStatus($status, $serverId, $message);
            
            if ($result) {
                return json([
                    'code' => 200,
                    'status' => true,
                    'message' => '服务器状态设置成功',
                    'data' => [
                        'server_id' => $serverId ?: 'current',
                        'status' => $status,
                        'message' => $message
                    ]
                ]);
            } else {
                return json([
                    'code' => 500,
                    'status' => false,
                    'message' => '设置失败',
                    'data' => null
                ]);
            }
        } catch (\Exception $e) {
            Log::error('设置服务器状态失败', [
                'error' => $e->getMessage()
            ]);
            
            return json([
                'code' => 500,
                'status' => false,
                'message' => '设置失败：' . $e->getMessage(),
                'data' => null
            ]);
        }
    }
    
    /**
     * 获取服务器健康状态
     * @param Request $request
     * @return Response
     */
    public function getServerHealth(Request $request): Response
    {
        try {
            $serverId = $request->get('server_id');
            $health = $this->serverService->getServerHealth($serverId);
            
            return json([
                'code' => 200,
                'status' => true,
                'message' => '获取成功',
                'data' => $health
            ]);
        } catch (\Exception $e) {
            Log::error('获取服务器健康状态失败', [
                'error' => $e->getMessage()
            ]);
            
            return json([
                'code' => 500,
                'status' => false,
                'message' => '获取失败：' . $e->getMessage(),
                'data' => null
            ]);
        }
    }
    
    /**
     * 批量设置服务器状态
     * @param Request $request
     * @return Response
     */
    public function batchSetServerStatus(Request $request): Response
    {
        try {
            $data = $request->all();
            
            $servers = $data['servers'] ?? [];
            $status = $data['status'] ?? '';
            $message = $data['message'] ?? '';
            
            if (!in_array($status, ['enabled', 'disabled', 'testing'])) {
                return json([
                    'code' => 400,
                    'status' => false,
                    'message' => '状态参数错误，支持：enabled(启用), disabled(停用), testing(测试)',
                    'data' => null
                ]);
            }
            
            $results = [];
            $successCount = 0;
            $failedCount = 0;
            
            foreach ($servers as $serverId) {
                $result = $this->serverService->setServerStatus($status, $serverId, $message);
                $results[] = [
                    'server_id' => $serverId,
                    'success' => $result
                ];
                
                if ($result) {
                    $successCount++;
                } else {
                    $failedCount++;
                }
            }
            
            return json([
                'code' => 200,
                'status' => true,
                'message' => "批量设置完成，成功：{$successCount}，失败：{$failedCount}",
                'data' => [
                    'results' => $results,
                    'success_count' => $successCount,
                    'failed_count' => $failedCount,
                    'total' => count($servers)
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('批量设置服务器状态失败', [
                'error' => $e->getMessage()
            ]);
            
            return json([
                'code' => 500,
                'status' => false,
                'message' => '批量设置失败：' . $e->getMessage(),
                'data' => null
            ]);
        }
    }
    
    /**
     * 删除服务器状态
     * @param Request $request
     * @return Response
     */
    public function removeServer(Request $request): Response
    {
        try {
            $serverId = $request->input('server_id');
            
            if (!$serverId) {
                return json([
                    'code' => 400,
                    'status' => false,
                    'message' => '服务器ID不能为空',
                    'data' => null
                ]);
            }
            
            $result = $this->serverService->removeServer($serverId);
            
            if ($result) {
                return json([
                    'code' => 200,
                    'status' => true,
                    'message' => '服务器状态删除成功',
                    'data' => [
                        'server_id' => $serverId
                    ]
                ]);
            } else {
                return json([
                    'code' => 500,
                    'status' => false,
                    'message' => '删除失败',
                    'data' => null
                ]);
            }
        } catch (\Exception $e) {
            Log::error('删除服务器状态失败', [
                'error' => $e->getMessage()
            ]);
            
            return json([
                'code' => 500,
                'status' => false,
                'message' => '删除失败：' . $e->getMessage(),
                'data' => null
            ]);
        }
    }
    
    /**
     * 获取服务器统计信息
     * @param Request $request
     * @return Response
     */
    public function getServerStats(Request $request): Response
    {
        try {
            $servers = $this->serverService->getAllServersStatus();
            
            $stats = [
                'total_servers' => count($servers),
                'enabled_servers' => 0,
                'disabled_servers' => 0,
                'testing_servers' => 0,
                'unknown_servers' => 0
            ];
            
            foreach ($servers as $server) {
                switch ($server['status']) {
                    case 'enabled':
                        $stats['enabled_servers']++;
                        break;
                    case 'disabled':
                        $stats['disabled_servers']++;
                        break;
                    case 'testing':
                        $stats['testing_servers']++;
                        break;
                    default:
                        $stats['unknown_servers']++;
                        break;
                }
            }
            
            return json([
                'code' => 200,
                'status' => true,
                'message' => '获取成功',
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            Log::error('获取服务器统计信息失败', [
                'error' => $e->getMessage()
            ]);
            
            return json([
                'code' => 500,
                'status' => false,
                'message' => '获取失败：' . $e->getMessage(),
                'data' => null
            ]);
        }
    }
}

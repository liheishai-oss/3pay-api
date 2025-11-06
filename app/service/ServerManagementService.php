<?php

namespace app\service;

use support\Redis;
use support\Log;

/**
 * 服务器管理服务
 * 支持负载均衡环境下的单台服务器状态管理
 */
class ServerManagementService
{
    private const SERVER_STATUS_KEY = 'server_status:';
    private const SERVER_MAINTENANCE_KEY = 'server_maintenance:';
    private const SERVER_INFO_KEY = 'server_info:';
    
    /**
     * 获取当前服务器标识
     * @return string
     */
    private function getCurrentServerId(): string
    {
        // 可以通过环境变量、配置文件或自动检测来设置服务器ID
        $serverId = $_ENV['SERVER_ID'] ?? 
                   $_SERVER['SERVER_ID'] ?? 
                   gethostname() ?? 
                   'server_' . substr(md5($_SERVER['SERVER_ADDR'] ?? '127.0.0.1'), 0, 8);
        
        return $serverId;
    }
    
    /**
     * 获取服务器状态
     * @param string|null $serverId 服务器ID，null表示当前服务器
     * @return array
     */
    public function getServerStatus(?string $serverId = null): array
    {
        $serverId = $serverId ?: $this->getCurrentServerId();
        
        try {
            $status = Redis::get(self::SERVER_STATUS_KEY . $serverId) ?: 'normal';
            $maintenance = Redis::get(self::SERVER_MAINTENANCE_KEY . $serverId) ?: '0';
            $info = Redis::get(self::SERVER_INFO_KEY . $serverId);
            $serverInfo = $info ? json_decode($info, true) : [];
            
            return [
                'server_id' => $serverId,
                'status' => $status,
                'is_maintenance' => $maintenance === '1',
                'maintenance_message' => $serverInfo['maintenance_message'] ?? '',
                'last_update' => $serverInfo['last_update'] ?? date('Y-m-d H:i:s'),
                'server_info' => $serverInfo
            ];
        } catch (\Exception $e) {
            Log::error('获取服务器状态失败', [
                'server_id' => $serverId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'server_id' => $serverId,
                'status' => 'unknown',
                'is_maintenance' => false,
                'maintenance_message' => '',
                'last_update' => date('Y-m-d H:i:s'),
                'server_info' => []
            ];
        }
    }
    
    /**
     * 设置服务器状态
     * @param string $status 状态：enabled(启用), disabled(停用), testing(测试)
     * @param string|null $serverId 服务器ID
     * @param string $message 状态信息
     * @return bool
     */
    public function setServerStatus(string $status, ?string $serverId = null, string $message = ''): bool
    {
        $serverId = $serverId ?: $this->getCurrentServerId();
        
        try {
            // 设置状态
            Redis::set(self::SERVER_STATUS_KEY . $serverId, $status);
            
            // 设置维护状态（只有disabled状态才设置维护）
            $isMaintenance = $status === 'disabled';
            Redis::set(self::SERVER_MAINTENANCE_KEY . $serverId, $isMaintenance ? '1' : '0');
            
            // 更新服务器信息
            $serverInfo = [
                'status' => $status,
                'status_message' => $message,
                'last_update' => date('Y-m-d H:i:s'),
                'server_ip' => $_SERVER['SERVER_ADDR'] ?? '127.0.0.1',
                'server_name' => gethostname() ?: 'unknown',
                'php_version' => PHP_VERSION,
                'webman_version' => '1.0'
            ];
            
            Redis::set(self::SERVER_INFO_KEY . $serverId, json_encode($serverInfo));
            
            Log::info('服务器状态已更新', [
                'server_id' => $serverId,
                'status' => $status,
                'is_maintenance' => $isMaintenance,
                'status_message' => $message
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('设置服务器状态失败', [
                'server_id' => $serverId,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * 获取所有服务器状态
     * @return array
     */
    public function getAllServersStatus(): array
    {
        try {
            $servers = [];
            
            // 获取所有服务器状态键
            $statusKeys = Redis::keys(self::SERVER_STATUS_KEY . '*');
            
            foreach ($statusKeys as $key) {
                $serverId = str_replace(self::SERVER_STATUS_KEY, '', $key);
                $servers[] = $this->getServerStatus($serverId);
            }
            
            return $servers;
        } catch (\Exception $e) {
            Log::error('获取所有服务器状态失败', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * 检查当前服务器是否应该处理请求
     * @return bool
     */
    public function shouldProcessRequest(): bool
    {
        $status = $this->getServerStatus();
        
        // 只有在enabled状态时才处理请求，但normal状态也应该处理请求（向后兼容）
        return ($status['status'] === 'enabled' || $status['status'] === 'normal') && !$status['is_maintenance'];
    }
    
    /**
     * 获取服务器健康状态
     * @param string|null $serverId
     * @return array
     */
    public function getServerHealth(?string $serverId = null): array
    {
        $serverId = $serverId ?: $this->getCurrentServerId();
        $status = $this->getServerStatus($serverId);
        
        return [
            'server_id' => $serverId,
            'status' => $status['status'],
            'is_healthy' => $status['status'] === 'enabled',
            'is_maintenance' => $status['is_maintenance'],
            'can_process_requests' => $this->shouldProcessRequest(),
            'last_update' => $status['last_update'],
            'uptime' => $this->getServerUptime(),
            'memory_usage' => $this->getMemoryUsage(),
            'cpu_usage' => $this->getCpuUsage()
        ];
    }
    
    /**
     * 获取服务器运行时间
     * @return string
     */
    private function getServerUptime(): string
    {
        try {
            $uptime = shell_exec('uptime');
            return trim($uptime) ?: 'unknown';
        } catch (\Exception $e) {
            return 'unknown';
        }
    }
    
    /**
     * 获取内存使用情况
     * @return array
     */
    private function getMemoryUsage(): array
    {
        try {
            $memInfo = shell_exec('free -m');
            $lines = explode("\n", trim($memInfo));
            $memData = [];
            
            foreach ($lines as $line) {
                if (strpos($line, 'Mem:') === 0) {
                    $parts = preg_split('/\s+/', $line);
                    $memData = [
                        'total' => (int)$parts[1],
                        'used' => (int)$parts[2],
                        'free' => (int)$parts[3],
                        'usage_percent' => round((int)$parts[2] / (int)$parts[1] * 100, 2)
                    ];
                    break;
                }
            }
            
            return $memData;
        } catch (\Exception $e) {
            return ['total' => 0, 'used' => 0, 'free' => 0, 'usage_percent' => 0];
        }
    }
    
    /**
     * 获取CPU使用情况
     * @return array
     */
    private function getCpuUsage(): array
    {
        try {
            $loadAvg = sys_getloadavg();
            return [
                'load_1min' => $loadAvg[0],
                'load_5min' => $loadAvg[1],
                'load_15min' => $loadAvg[2]
            ];
        } catch (\Exception $e) {
            return ['load_1min' => 0, 'load_5min' => 0, 'load_15min' => 0];
        }
    }
    
    /**
     * 删除服务器状态（用于服务器下线）
     * @param string|null $serverId
     * @return bool
     */
    public function removeServer(?string $serverId = null): bool
    {
        $serverId = $serverId ?: $this->getCurrentServerId();
        
        try {
            Redis::del(
                self::SERVER_STATUS_KEY . $serverId,
                self::SERVER_MAINTENANCE_KEY . $serverId,
                self::SERVER_INFO_KEY . $serverId
            );
            
            Log::info('服务器状态已删除', [
                'server_id' => $serverId
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('删除服务器状态失败', [
                'server_id' => $serverId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}

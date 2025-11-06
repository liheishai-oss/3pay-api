<?php

namespace app\admin\controller\v1;

use app\model\SystemConfig;
use support\Request;
use support\Response;
use support\Log;

class SystemConfigController
{
    protected array $noNeedLogin = [];
    /**
     * 获取系统配置列表
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        try {
            // 检查权限
            if (!auth()->check('system:config:list')) {
                return json([
                    'code' => 403,
                    'status' => false,
                    'message' => '没有权限访问系统配置',
                    'data' => null
                ]);
            }
            
            $configs = SystemConfig::orderBy('group_key', 'asc')
                ->orderBy('sort', 'asc')
                ->get();
            
            return json([
                'code' => 200,
                'status' => true,
                'message' => '获取成功',
                'data' => $configs
            ]);
        } catch (\Exception $e) {
            Log::error('获取系统配置失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return json([
                'code' => 500,
                'status' => false,
                'message' => '获取系统配置失败: ' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 保存系统配置
     * @param Request $request
     * @return Response
     */
    public function save(Request $request): Response
    {
        try {
            // 检查权限
            if (!auth()->check('system:config:save')) {
                return json([
                    'code' => 403,
                    'status' => false,
                    'message' => '没有权限保存系统配置',
                    'data' => null
                ]);
            }
            
            $configs = $request->input('configs', []);
            
            if (empty($configs)) {
                return json([
                    'code' => 400,
                    'status' => false,
                    'message' => '配置数据不能为空',
                    'data' => null
                ]);
            }
            
            $successCount = 0;
            $errors = [];
            
            foreach ($configs as $config) {
                try {
                    $systemConfig = SystemConfig::find($config['id']);
                    if ($systemConfig) {
                        $systemConfig->config_value = $config['config_value'];
                        $systemConfig->save();
                        $successCount++;
                    } else {
                        $errors[] = "配置项 ID {$config['id']} 不存在";
                    }
                } catch (\Exception $e) {
                    $errors[] = "更新配置项 ID {$config['id']} 失败: " . $e->getMessage();
                }
            }
            
            if ($successCount > 0) {
                Log::info('系统配置更新成功', [
                    'success_count' => $successCount,
                    'total_count' => count($configs)
                ]);
                
                return json([
                    'code' => 200,
                    'status' => true,
                    'message' => "成功更新 {$successCount} 个配置项",
                    'data' => [
                        'success_count' => $successCount,
                        'total_count' => count($configs),
                        'errors' => $errors
                    ]
                ]);
            } else {
                return json([
                    'code' => 500,
                    'status' => false,
                    'message' => '更新失败',
                    'data' => [
                        'errors' => $errors
                    ]
                ]);
            }
        } catch (\Exception $e) {
            Log::error('保存系统配置失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return json([
                'code' => 500,
                'status' => false,
                'message' => '保存系统配置失败: ' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 获取配置分组
     * @return Response
     */
    public function getGroups(): Response
    {
        try {
            // 检查权限
            if (!auth()->check('system:config:groups')) {
                return json([
                    'code' => 403,
                    'status' => false,
                    'message' => '没有权限访问配置分组',
                    'data' => null
                ]);
            }
            
            $groups = SystemConfig::select('group_key')
                ->distinct()
                ->orderBy('group_key', 'asc')
                ->pluck('group_key')
                ->toArray();
            
            return json([
                'code' => 200,
                'status' => true,
                'message' => '获取成功',
                'data' => $groups
            ]);
        } catch (\Exception $e) {
            Log::error('获取配置分组失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return json([
                'code' => 500,
                'status' => false,
                'message' => '获取配置分组失败: ' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 重置配置到默认值
     * @param Request $request
     * @return Response
     */
    public function reset(Request $request): Response
    {
        try {
            // 检查权限
            if (!auth()->check('system:config:reset')) {
                return json([
                    'code' => 403,
                    'status' => false,
                    'message' => '没有权限重置系统配置',
                    'data' => null
                ]);
            }
            
            $groupKey = $request->input('group_key');
            
            if (empty($groupKey)) {
                return json([
                    'code' => 400,
                    'status' => false,
                    'message' => '分组标识不能为空',
                    'data' => null
                ]);
            }
            
            $configs = SystemConfig::where('group_key', $groupKey)->get();
            $resetCount = 0;
            
            foreach ($configs as $config) {
                $config->config_value = $config->default_value;
                $config->save();
                $resetCount++;
            }
            
            Log::info('系统配置重置成功', [
                'group_key' => $groupKey,
                'reset_count' => $resetCount
            ]);
            
            return json([
                'code' => 200,
                'status' => true,
                'message' => "成功重置 {$resetCount} 个配置项",
                'data' => [
                    'reset_count' => $resetCount
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('重置系统配置失败', [
                'group_key' => $request->input('group_key'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return json([
                'code' => 500,
                'status' => false,
                'message' => '重置系统配置失败: ' . $e->getMessage(),
                'data' => null
            ]);
        }
    }
}

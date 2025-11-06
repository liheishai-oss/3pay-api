<?php

namespace app\admin\controller\v1;

use support\Request;
use support\Response;
use support\Db;
use support\Log;

/**
 * 预警控制器
 * 提供预警规则配置、预警历史记录、实时预警等功能
 */
class AlertController
{
    /**
     * 预警配置页面
     */
    public function config(Request $request): Response
    {
        return raw_view('admin/alert/config', [
            'title' => '预警配置'
        ]);
    }
    
    /**
     * 预警历史页面
     */
    public function history(Request $request): Response
    {
        return raw_view('admin/alert/history', [
            'title' => '预警历史'
        ]);
    }
    
    /**
     * 获取预警规则列表
     */
    public function getRules(Request $request): Response
    {
        try {
            $rules = Db::table('alert_rules')
                ->where('status', 1)
                ->orderBy('priority', 'desc')
                ->orderBy('created_at', 'desc')
                ->get()
                ->toArray();
            
            return $this->success($rules, '获取成功');
            
        } catch (\Exception $e) {
            return $this->error('获取预警规则失败：' . $e->getMessage());
        }
    }
    
    /**
     * 创建预警规则
     */
    public function createRule(Request $request): Response
    {
        try {
            $data = $request->post();
            
            // 验证必填字段
            $requiredFields = ['name', 'type', 'condition', 'threshold', 'priority'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return $this->error("字段 {$field} 不能为空");
                }
            }
            
            // 插入预警规则
            $ruleId = Db::table('alert_rules')->insertGetId([
                'name' => $data['name'],
                'description' => $data['description'] ?? '',
                'type' => $data['type'],
                'condition' => $data['condition'],
                'threshold' => $data['threshold'],
                'priority' => $data['priority'],
                'is_enabled' => $data['is_enabled'] ?? 1,
                'notification_channels' => json_encode($data['notification_channels'] ?? ['admin']),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            return $this->success(['id' => $ruleId], '创建成功');
            
        } catch (\Exception $e) {
            return $this->error('创建预警规则失败：' . $e->getMessage());
        }
    }
    
    /**
     * 更新预警规则
     */
    public function updateRule(Request $request): Response
    {
        try {
            $id = $request->post('id');
            $data = $request->post();
            
            if (empty($id)) {
                return $this->error('规则ID不能为空');
            }
            
            // 更新预警规则
            $updateData = [
                'name' => $data['name'],
                'description' => $data['description'] ?? '',
                'type' => $data['type'],
                'condition' => $data['condition'],
                'threshold' => $data['threshold'],
                'priority' => $data['priority'],
                'is_enabled' => $data['is_enabled'] ?? 1,
                'notification_channels' => json_encode($data['notification_channels'] ?? ['admin']),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            $affected = Db::table('alert_rules')
                ->where('id', $id)
                ->update($updateData);
            
            if ($affected > 0) {
                return $this->success([], '更新成功');
            } else {
                return $this->error('规则不存在或更新失败');
            }
            
        } catch (\Exception $e) {
            return $this->error('更新预警规则失败：' . $e->getMessage());
        }
    }
    
    /**
     * 删除预警规则
     */
    public function deleteRule(Request $request): Response
    {
        try {
            $id = $request->post('id');
            
            if (empty($id)) {
                return $this->error('规则ID不能为空');
            }
            
            // 软删除预警规则
            $affected = Db::table('alert_rules')
                ->where('id', $id)
                ->update([
                    'status' => 0,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            
            if ($affected > 0) {
                return $this->success([], '删除成功');
            } else {
                return $this->error('规则不存在或删除失败');
            }
            
        } catch (\Exception $e) {
            return $this->error('删除预警规则失败：' . $e->getMessage());
        }
    }
    
    /**
     * 获取预警历史
     */
    public function getHistory(Request $request): Response
    {
        try {
            $page = (int)$request->get('page', 1);
            $pageSize = (int)$request->get('page_size', 20);
            $type = $request->get('type', '');
            $status = $request->get('status', '');
            $startTime = $request->get('start_time', '');
            $endTime = $request->get('end_time', '');
            
            $query = Db::table('alert_history')
                ->leftJoin('alert_rules', 'alert_history.rule_id', '=', 'alert_rules.id')
                ->select([
                    'alert_history.*',
                    'alert_rules.name as rule_name',
                    'alert_rules.type as rule_type'
                ]);
            
            // 添加筛选条件
            if (!empty($type)) {
                $query->where('alert_rules.type', $type);
            }
            if (!empty($status)) {
                $query->where('alert_history.status', $status);
            }
            if (!empty($startTime)) {
                $query->where('alert_history.created_at', '>=', $startTime);
            }
            if (!empty($endTime)) {
                $query->where('alert_history.created_at', '<=', $endTime);
            }
            
            $total = $query->count();
            $alerts = $query->orderBy('alert_history.created_at', 'desc')
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get()
                ->toArray();
            
            return $this->success([
                'alerts' => $alerts,
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'total_pages' => ceil($total / $pageSize)
            ], '获取成功');
            
        } catch (\Exception $e) {
            return $this->error('获取预警历史失败：' . $e->getMessage());
        }
    }
    
    /**
     * 处理预警
     */
    public function handleAlert(Request $request): Response
    {
        try {
            $id = $request->post('id');
            $action = $request->post('action'); // 'acknowledge', 'resolve', 'ignore'
            $note = $request->post('note', '');
            
            if (empty($id) || empty($action)) {
                return $this->error('参数不完整');
            }
            
            $updateData = [
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            switch ($action) {
                case 'acknowledge':
                    $updateData['status'] = 'acknowledged';
                    $updateData['acknowledged_at'] = date('Y-m-d H:i:s');
                    $updateData['acknowledged_by'] = $request->userData['admin_id'] ?? 0;
                    break;
                case 'resolve':
                    $updateData['status'] = 'resolved';
                    $updateData['resolved_at'] = date('Y-m-d H:i:s');
                    $updateData['resolved_by'] = $request->userData['admin_id'] ?? 0;
                    break;
                case 'ignore':
                    $updateData['status'] = 'ignored';
                    $updateData['ignored_at'] = date('Y-m-d H:i:s');
                    $updateData['ignored_by'] = $request->userData['admin_id'] ?? 0;
                    break;
                default:
                    return $this->error('无效的操作类型');
            }
            
            if (!empty($note)) {
                $updateData['note'] = $note;
            }
            
            $affected = Db::table('alert_history')
                ->where('id', $id)
                ->update($updateData);
            
            if ($affected > 0) {
                return $this->success([], '操作成功');
            } else {
                return $this->error('预警不存在或操作失败');
            }
            
        } catch (\Exception $e) {
            return $this->error('处理预警失败：' . $e->getMessage());
        }
    }
    
    /**
     * 获取实时预警
     */
    public function getRealtimeAlerts(Request $request): Response
    {
        try {
            $alerts = Db::table('alert_history')
                ->leftJoin('alert_rules', 'alert_history.rule_id', '=', 'alert_rules.id')
                ->where('alert_history.status', 'active')
                ->where('alert_history.created_at', '>=', date('Y-m-d H:i:s', strtotime('-24 hours')))
                ->select([
                    'alert_history.*',
                    'alert_rules.name as rule_name',
                    'alert_rules.type as rule_type',
                    'alert_rules.priority'
                ])
                ->orderBy('alert_rules.priority', 'desc')
                ->orderBy('alert_history.created_at', 'desc')
                ->limit(50)
                ->get()
                ->toArray();
            
            return $this->success($alerts, '获取成功');
            
        } catch (\Exception $e) {
            return $this->error('获取实时预警失败：' . $e->getMessage());
        }
    }
    
    /**
     * 测试预警规则
     */
    public function testRule(Request $request): Response
    {
        try {
            $ruleId = $request->post('rule_id');
            
            if (empty($ruleId)) {
                return $this->error('规则ID不能为空');
            }
            
            $rule = Db::table('alert_rules')->where('id', $ruleId)->first();
            if (!$rule) {
                return $this->error('规则不存在');
            }
            
            // 根据规则类型执行测试
            $result = $this->executeRuleTest($rule);
            
            return $this->success($result, '测试完成');
            
        } catch (\Exception $e) {
            return $this->error('测试预警规则失败：' . $e->getMessage());
        }
    }
    
    /**
     * 执行规则测试
     */
    private function executeRuleTest($rule): array
    {
        $type = $rule->type;
        $condition = $rule->condition;
        $threshold = $rule->threshold;
        
        switch ($type) {
            case 'order_error_rate':
                return $this->testOrderErrorRate($condition, $threshold);
            case 'payment_success_rate':
                return $this->testPaymentSuccessRate($condition, $threshold);
            case 'system_response_time':
                return $this->testSystemResponseTime($condition, $threshold);
            case 'database_connection':
                return $this->testDatabaseConnection($condition, $threshold);
            default:
                return [
                    'triggered' => false,
                    'message' => '未知的规则类型',
                    'current_value' => 0,
                    'threshold' => $threshold
                ];
        }
    }
    
    /**
     * 测试订单错误率
     */
    private function testOrderErrorRate($condition, $threshold): array
    {
        $startTime = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $endTime = date('Y-m-d H:i:s');
        
        $totalLogs = Db::table('order_log')
            ->whereBetween('created_at', [$startTime, $endTime])
            ->count();
        
        $errorLogs = Db::table('order_log')
            ->whereBetween('created_at', [$startTime, $endTime])
            ->where('log_level', 'ERROR')
            ->count();
        
        $errorRate = $totalLogs > 0 ? ($errorLogs / $totalLogs) * 100 : 0;
        
        $triggered = $this->evaluateCondition($errorRate, $condition, $threshold);
        
        return [
            'triggered' => $triggered,
            'message' => "订单错误率: {$errorRate}%",
            'current_value' => $errorRate,
            'threshold' => $threshold,
            'details' => [
                'total_logs' => $totalLogs,
                'error_logs' => $errorLogs
            ]
        ];
    }
    
    /**
     * 测试支付成功率
     */
    private function testPaymentSuccessRate($condition, $threshold): array
    {
        $startTime = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $endTime = date('Y-m-d H:i:s');
        
        $totalOrders = Db::table('order')
            ->whereBetween('created_at', [$startTime, $endTime])
            ->count();
        
        $paidOrders = Db::table('order')
            ->whereBetween('created_at', [$startTime, $endTime])
            ->where('pay_status', 1)
            ->count();
        
        $successRate = $totalOrders > 0 ? ($paidOrders / $totalOrders) * 100 : 0;
        
        $triggered = $this->evaluateCondition($successRate, $condition, $threshold);
        
        return [
            'triggered' => $triggered,
            'message' => "支付成功率: {$successRate}%",
            'current_value' => $successRate,
            'threshold' => $threshold,
            'details' => [
                'total_orders' => $totalOrders,
                'paid_orders' => $paidOrders
            ]
        ];
    }
    
    /**
     * 测试系统响应时间
     */
    private function testSystemResponseTime($condition, $threshold): array
    {
        $startTime = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $endTime = date('Y-m-d H:i:s');
        
        $logs = Db::table('order_log')
            ->whereBetween('created_at', [$startTime, $endTime])
            ->where('content', 'like', '%call_duration%')
            ->get();
        
        $durations = [];
        foreach ($logs as $log) {
            $content = json_decode($log->content, true);
            if (isset($content['call_duration'])) {
                $duration = $this->parseDuration($content['call_duration']);
                if ($duration > 0) {
                    $durations[] = $duration;
                }
            }
        }
        
        $avgDuration = count($durations) > 0 ? array_sum($durations) / count($durations) : 0;
        
        $triggered = $this->evaluateCondition($avgDuration, $condition, $threshold);
        
        return [
            'triggered' => $triggered,
            'message' => "平均响应时间: {$avgDuration}ms",
            'current_value' => $avgDuration,
            'threshold' => $threshold,
            'details' => [
                'sample_count' => count($durations),
                'max_duration' => count($durations) > 0 ? max($durations) : 0,
                'min_duration' => count($durations) > 0 ? min($durations) : 0
            ]
        ];
    }
    
    /**
     * 测试数据库连接
     */
    private function testDatabaseConnection($condition, $threshold): array
    {
        try {
            $startTime = microtime(true);
            Db::table('order')->limit(1)->get();
            $endTime = microtime(true);
            
            $responseTime = ($endTime - $startTime) * 1000; // 转换为毫秒
            
            $triggered = $this->evaluateCondition($responseTime, $condition, $threshold);
            
            return [
                'triggered' => $triggered,
                'message' => "数据库响应时间: {$responseTime}ms",
                'current_value' => $responseTime,
                'threshold' => $threshold,
                'details' => [
                    'status' => 'connected',
                    'response_time' => $responseTime
                ]
            ];
        } catch (\Exception $e) {
            return [
                'triggered' => true,
                'message' => "数据库连接失败: " . $e->getMessage(),
                'current_value' => 999999,
                'threshold' => $threshold,
                'details' => [
                    'status' => 'error',
                    'error' => $e->getMessage()
                ]
            ];
        }
    }
    
    /**
     * 评估条件
     */
    private function evaluateCondition($value, $condition, $threshold): bool
    {
        switch ($condition) {
            case 'greater_than':
                return $value > $threshold;
            case 'less_than':
                return $value < $threshold;
            case 'equals':
                return $value == $threshold;
            case 'not_equals':
                return $value != $threshold;
            default:
                return false;
        }
    }
    
    /**
     * 解析持续时间
     */
    private function parseDuration(string $duration): int
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*ms/', $duration, $matches)) {
            return (int)($matches[1]);
        }
        return 0;
    }
    
    /**
     * 成功响应
     */
    private function success($data = [], $message = 'success'): Response
    {
        return json([
            'code' => 0,
            'msg' => $message,
            'data' => $data
        ]);
    }
    
    /**
     * 错误响应
     */
    private function error($message = 'error', $code = 1): Response
    {
        return json([
            'code' => $code,
            'msg' => $message,
            'data' => null
        ]);
    }
}





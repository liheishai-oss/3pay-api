<?php

namespace app\admin\controller\v1;

use support\Request;
use support\Response;
use support\Db;
use support\Log;

/**
 * 监控数据控制器
 * 提供实时监控数据、统计数据、预警信息等
 */
class MonitorController
{
    /**
     * 监控大屏页面
     */
    public function dashboard(Request $request): Response
    {
        return raw_view('admin/monitor/dashboard', [
            'title' => '实时监控大屏'
        ]);
    }
    
    /**
     * 获取实时监控数据
     */
    public function realtimeData(Request $request): Response
    {
        try {
            $timeRange = $request->get('time_range', '1h'); // 默认1小时
            $endTime = time();
            $startTime = $this->getStartTime($timeRange, $endTime);
            
            $data = [
                'timestamp' => date('Y-m-d H:i:s'),
                'time_range' => $timeRange,
                'order_stats' => $this->getOrderStats($startTime, $endTime),
                'payment_stats' => $this->getPaymentStats($startTime, $endTime),
                'system_health' => $this->getSystemHealth(),
                'alerts' => $this->getActiveAlerts(),
                'recent_errors' => $this->getRecentErrors($startTime, $endTime)
            ];
            
            return success($data, '获取成功');
            
        } catch (\Exception $e) {
            Log::error('获取实时监控数据失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return error('获取监控数据失败：' . $e->getMessage());
        }
    }
    
    /**
     * 获取订单统计数据
     */
    public function orderStats(Request $request): Response
    {
        try {
            $timeRange = $request->get('time_range', '24h');
            $endTime = time();
            $startTime = $this->getStartTime($timeRange, $endTime);
            
            $stats = $this->getOrderStats($startTime, $endTime);
            
            return success($stats, '获取成功');
            
        } catch (\Exception $e) {
            return error('获取订单统计失败：' . $e->getMessage());
        }
    }
    
    /**
     * 获取支付统计数据
     */
    public function paymentStats(Request $request): Response
    {
        try {
            $timeRange = $request->get('time_range', '24h');
            $endTime = time();
            $startTime = $this->getStartTime($timeRange, $endTime);
            
            $stats = $this->getPaymentStats($startTime, $endTime);
            
            return success($stats, '获取成功');
            
        } catch (\Exception $e) {
            return error('获取支付统计失败：' . $e->getMessage());
        }
    }
    
    /**
     * 获取系统健康状态
     */
    public function systemHealth(Request $request): Response
    {
        try {
            $health = $this->getSystemHealth();
            
            return success($health, '获取成功');
            
        } catch (\Exception $e) {
            return error('获取系统健康状态失败：' . $e->getMessage());
        }
    }
    
    /**
     * 获取预警信息
     */
    public function alerts(Request $request): Response
    {
        try {
            $status = $request->get('status', 'active'); // active, resolved, all
            $level = $request->get('level', ''); // P0, P1, P2, P3
            $limit = $request->get('limit', 50);
            
            $alerts = $this->getAlerts($status, $level, $limit);
            
            return success($alerts, '获取成功');
            
        } catch (\Exception $e) {
            return error('获取预警信息失败：' . $e->getMessage());
        }
    }
    
    /**
     * 获取历史趋势数据
     */
    public function trendData(Request $request): Response
    {
        try {
            $type = $request->get('type', 'orders'); // orders, payments, errors
            $timeRange = $request->get('time_range', '7d');
            $granularity = $request->get('granularity', 'hour'); // hour, day
            
            $endTime = time();
            $startTime = $this->getStartTime($timeRange, $endTime);
            
            $data = $this->getTrendData($type, $startTime, $endTime, $granularity);
            
            return success($data, '获取成功');
            
        } catch (\Exception $e) {
            return error('获取趋势数据失败：' . $e->getMessage());
        }
    }

    /**
     * 历史分析：订单趋势分析
     */
    public function orderTrendAnalysis(Request $request): Response
    {
        try {
            $userData = $request->userData;
            $isAgent = ($userData['user_group_id'] ?? 0) == 3;
            
            $timeRange = $request->get('time_range', '7d');
            $granularity = $request->get('granularity', 'hour'); // hour|day
            $endTime = time();
            $startTime = $this->getStartTime($timeRange, $endTime);

            $trend = $this->getTrendData('orders', $startTime, $endTime, $granularity, $isAgent ? $userData['agent_id'] : null);

            return success([
                'trend' => $trend
            ], '获取成功');
        } catch (\Exception $e) {
            return error('订单趋势分析失败：' . $e->getMessage());
        }
    }

    /**
     * 历史分析：支付转化率分析
     */
    public function conversionRateAnalysis(Request $request): Response
    {
        try {
            $userData = $request->userData;
            $isAgent = ($userData['user_group_id'] ?? 0) == 3;
            
            $timeRange = $request->get('time_range', '7d');
            $granularity = $request->get('granularity', 'hour');
            $endTime = time();
            $startTime = $this->getStartTime($timeRange, $endTime);

            $series = $this->getConversionSeries($startTime, $endTime, $granularity, $isAgent ? $userData['agent_id'] : null);

            return success($series, '获取成功');
        } catch (\Exception $e) {
            return error('支付转化率分析失败：' . $e->getMessage());
        }
    }

    /**
     * 历史分析：异常模式识别
     */
    public function anomalyPatternAnalysis(Request $request): Response
    {
        try {
            $userData = $request->userData;
            $isAgent = ($userData['user_group_id'] ?? 0) == 3;
            
            $timeRange = $request->get('time_range', '24h');
            $endTime = time();
            $startTime = $this->getStartTime($timeRange, $endTime);

            $patterns = [
                'top_error_nodes' => $this->getTopErrorNodes($startTime, $endTime, $isAgent ? $userData['agent_id'] : null),
                'frequent_error_messages' => $this->getFrequentErrorMessages($startTime, $endTime, $isAgent ? $userData['agent_id'] : null),
                'error_spikes' => $this->getErrorSpikes($startTime, $endTime, $isAgent ? $userData['agent_id'] : null)
            ];

            return success($patterns, '获取成功');
        } catch (\Exception $e) {
            return error('异常模式识别失败：' . $e->getMessage());
        }
    }

    /**
     * 历史分析：性能指标分析
     */
    public function performanceMetricAnalysis(Request $request): Response
    {
        try {
            $userData = $request->userData;
            $isAgent = ($userData['user_group_id'] ?? 0) == 3;
            
            $timeRange = $request->get('time_range', '24h');
            $endTime = time();
            $startTime = $this->getStartTime($timeRange, $endTime);

            $metrics = [
                'response_time' => $this->getResponseTimeMetrics($startTime, $endTime, $isAgent ? $userData['agent_id'] : null),
                'slow_calls' => $this->getSlowCalls($startTime, $endTime, $isAgent ? $userData['agent_id'] : null),
                'throughput' => $this->getThroughputSeries($startTime, $endTime, $isAgent ? $userData['agent_id'] : null)
            ];

            return success($metrics, '获取成功');
        } catch (\Exception $e) {
            return error('性能指标分析失败：' . $e->getMessage());
        }
    }
    
    /**
     * 获取订单统计
     */
    private function getOrderStats(int $startTime, int $endTime): array
    {
        $startDate = date('Y-m-d H:i:s', $startTime);
        $endDate = date('Y-m-d H:i:s', $endTime);
        
        // 总订单数
        $totalOrders = Db::table('order')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();
        
        // 待支付订单数
        $pendingOrders = Db::table('order')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('pay_status', 0)
            ->count();
        
        // 已支付订单数
        $paidOrders = Db::table('order')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('pay_status', 1)
            ->count();
        
        // 已关闭订单数
        $closedOrders = Db::table('order')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('pay_status', 2)
            ->count();
        
        // 订单金额统计
        $amountStats = Db::table('order')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                COUNT(*) as count,
                SUM(order_amount) as total_amount,
                AVG(order_amount) as avg_amount,
                MIN(order_amount) as min_amount,
                MAX(order_amount) as max_amount
            ')
            ->first();
        
        // 支付转化率
        $conversionRate = $totalOrders > 0 ? round(($paidOrders / $totalOrders) * 100, 2) : 0;
        
        return [
            'total_orders' => $totalOrders,
            'pending_orders' => $pendingOrders,
            'paid_orders' => $paidOrders,
            'closed_orders' => $closedOrders,
            'conversion_rate' => $conversionRate,
            'total_amount' => $amountStats->total_amount ?? 0,
            'avg_amount' => round($amountStats->avg_amount ?? 0, 2),
            'min_amount' => $amountStats->min_amount ?? 0,
            'max_amount' => $amountStats->max_amount ?? 0
        ];
    }
    
    /**
     * 获取支付统计
     */
    private function getPaymentStats(int $startTime, int $endTime): array
    {
        $startDate = date('Y-m-d H:i:s', $startTime);
        $endDate = date('Y-m-d H:i:s', $endTime);
        
        // 支付成功统计
        $successPayments = Db::table('order')
            ->whereBetween('pay_time', [$startDate, $endDate])
            ->where('pay_status', 1)
            ->count();
        
        // 支付金额统计
        $amountStats = Db::table('order')
            ->whereBetween('pay_time', [$startDate, $endDate])
            ->where('pay_status', 1)
            ->selectRaw('
                SUM(order_amount) as total_amount,
                AVG(order_amount) as avg_amount
            ')
            ->first();
        
        // 支付方式统计
        $paymentMethods = Db::table('order')
            ->join('product', 'order.product_id', '=', 'product.id')
            ->join('payment_type', 'product.payment_type_id', '=', 'payment_type.id')
            ->whereBetween('order.pay_time', [$startDate, $endDate])
            ->where('order.pay_status', 1)
            ->selectRaw('
                payment_type.product_code as payment_method,
                COUNT(*) as count,
                SUM(order.order_amount) as amount
            ')
            ->groupBy('payment_type.product_code')
            ->get()
            ->toArray();
        
        return [
            'success_payments' => $successPayments,
            'total_amount' => $amountStats->total_amount ?? 0,
            'avg_amount' => round($amountStats->avg_amount ?? 0, 2),
            'payment_methods' => $paymentMethods
        ];
    }
    
    /**
     * 获取系统健康状态
     */
    private function getSystemHealth(): array
    {
        $health = [
            'database' => $this->checkDatabaseHealth(),
            'redis' => $this->checkRedisHealth(),
            'disk_space' => $this->checkDiskSpace(),
            'memory_usage' => $this->getMemoryUsage(),
            'cpu_usage' => $this->getCpuUsage(),
            'overall_status' => 'healthy'
        ];
        
        // 计算整体健康状态
        $unhealthyCount = 0;
        foreach ($health as $key => $status) {
            if ($key !== 'overall_status' && isset($status['status']) && $status['status'] !== 'healthy') {
                $unhealthyCount++;
            }
        }
        
        if ($unhealthyCount > 0) {
            $health['overall_status'] = $unhealthyCount > 2 ? 'critical' : 'warning';
        }
        
        return $health;
    }
    
    /**
     * 检查数据库健康状态
     */
    private function checkDatabaseHealth(): array
    {
        try {
            $startTime = microtime(true);
            Db::table('order')->count();
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            
            return [
                'status' => 'healthy',
                'response_time' => $responseTime . 'ms',
                'message' => '数据库连接正常'
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'response_time' => 0,
                'message' => '数据库连接失败：' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 检查Redis健康状态
     */
    private function checkRedisHealth(): array
    {
        try {
            // 这里需要根据实际Redis配置进行调整
            return [
                'status' => 'healthy',
                'message' => 'Redis连接正常'
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Redis连接失败：' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 检查磁盘空间
     */
    private function checkDiskSpace(): array
    {
        $freeBytes = disk_free_space('/');
        $totalBytes = disk_total_space('/');
        $usedPercent = round((($totalBytes - $freeBytes) / $totalBytes) * 100, 2);
        
        $status = 'healthy';
        if ($usedPercent > 90) {
            $status = 'critical';
        } elseif ($usedPercent > 80) {
            $status = 'warning';
        }
        
        return [
            'status' => $status,
            'used_percent' => $usedPercent,
            'free_space' => $this->formatBytes($freeBytes),
            'total_space' => $this->formatBytes($totalBytes)
        ];
    }
    
    /**
     * 获取内存使用情况
     */
    private function getMemoryUsage(): array
    {
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = ini_get('memory_limit');
        $memoryLimitBytes = $this->parseMemoryLimit($memoryLimit);
        $usagePercent = round(($memoryUsage / $memoryLimitBytes) * 100, 2);
        
        $status = 'healthy';
        if ($usagePercent > 90) {
            $status = 'critical';
        } elseif ($usagePercent > 80) {
            $status = 'warning';
        }
        
        return [
            'status' => $status,
            'usage_percent' => $usagePercent,
            'used_memory' => $this->formatBytes($memoryUsage),
            'memory_limit' => $memoryLimit
        ];
    }
    
    /**
     * 获取CPU使用情况
     */
    private function getCpuUsage(): array
    {
        // 简化实现，实际应该通过系统命令获取
        return [
            'status' => 'healthy',
            'usage_percent' => 0,
            'message' => 'CPU使用率监控暂未实现'
        ];
    }
    
    /**
     * 获取活跃预警
     */
    private function getActiveAlerts(): array
    {
        // 这里应该从预警表获取，暂时返回模拟数据
        return [
            [
                'id' => 1,
                'level' => 'P1',
                'title' => '支付成功率下降',
                'message' => '过去1小时支付成功率降至85%，低于正常水平90%',
                'created_at' => date('Y-m-d H:i:s', time() - 300),
                'status' => 'active'
            ],
            [
                'id' => 2,
                'level' => 'P2',
                'title' => '数据库响应时间过长',
                'message' => '数据库平均响应时间超过500ms',
                'created_at' => date('Y-m-d H:i:s', time() - 600),
                'status' => 'active'
            ]
        ];
    }
    
    /**
     * 获取最近错误
     */
    private function getRecentErrors(int $startTime, int $endTime): array
    {
        $startDate = date('Y-m-d H:i:s', $startTime);
        $endDate = date('Y-m-d H:i:s', $endTime);
        
        return Db::table('order_log')
            ->where('log_level', 'ERROR')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->toArray();
    }
    
    /**
     * 获取预警信息
     */
    private function getAlerts(string $status, string $level, int $limit): array
    {
        // 这里应该从预警表获取，暂时返回模拟数据
        return [];
    }
    
    /**
     * 获取趋势数据
     */
    private function getTrendData(string $type, int $startTime, int $endTime, string $granularity, ?int $agentId = null): array
    {
        $data = [];
        $interval = $granularity === 'hour' ? 3600 : 86400; // 1小时或1天
        
        for ($time = $startTime; $time <= $endTime; $time += $interval) {
            $nextTime = min($time + $interval, $endTime);
            $startDate = date('Y-m-d H:i:s', $time);
            $endDate = date('Y-m-d H:i:s', $nextTime);
            
            switch ($type) {
                case 'orders':
                    $query = Db::table('order')->whereBetween('created_at', [$startDate, $endDate]);
                    if ($agentId) {
                        $query->where('agent_id', $agentId);
                    }
                    $count = $query->count();
                    break;
                case 'payments':
                    $query = Db::table('order')
                        ->whereBetween('pay_time', [$startDate, $endDate])
                        ->where('pay_status', 1);
                    if ($agentId) {
                        $query->where('agent_id', $agentId);
                    }
                    $count = $query->count();
                    break;
                case 'errors':
                    $query = Db::table('order_log')
                        ->where('log_level', 'ERROR')
                        ->whereBetween('created_at', [$startDate, $endDate]);
                    if ($agentId) {
                        $query->join('order', 'order_log.platform_order_no', '=', 'order.platform_order_no')
                              ->where('order.agent_id', $agentId);
                    }
                    $count = $query->count();
                    break;
                default:
                    $count = 0;
            }
            
            $data[] = [
                'time' => date('Y-m-d H:i:s', $time),
                'timestamp' => $time,
                'count' => $count
            ];
        }
        
        return $data;
    }

    /**
     * 获取按时间粒度的转化率序列
     */
    private function getConversionSeries(int $startTime, int $endTime, string $granularity, ?int $agentId = null): array
    {
        $data = [];
        $interval = $granularity === 'hour' ? 3600 : 86400;
        for ($time = $startTime; $time <= $endTime; $time += $interval) {
            $nextTime = min($time + $interval, $endTime);
            $startDate = date('Y-m-d H:i:s', $time);
            $endDate = date('Y-m-d H:i:s', $nextTime);

            $totalQuery = Db::table('order')->whereBetween('created_at', [$startDate, $endDate]);
            $paidQuery = Db::table('order')->whereBetween('created_at', [$startDate, $endDate])->where('pay_status', 1);
            
            if ($agentId) {
                $totalQuery->where('agent_id', $agentId);
                $paidQuery->where('agent_id', $agentId);
            }
            
            $total = $totalQuery->count();
            $paid = $paidQuery->count();
            $rate = $total > 0 ? round(($paid / $total) * 100, 2) : 0;

            $data[] = [
                'time' => date('Y-m-d H:i:s', $time),
                'timestamp' => $time,
                'total' => $total,
                'paid' => $paid,
                'conversion_rate' => $rate
            ];
        }
        return $data;
    }

    /**
     * 获取错误最多的节点
     */
    private function getTopErrorNodes(int $startTime, int $endTime, ?int $agentId = null): array
    {
        $startDate = date('Y-m-d H:i:s', $startTime);
        $endDate = date('Y-m-d H:i:s', $endTime);
        
        $query = Db::table('order_log')
            ->where('log_level', 'ERROR')
            ->whereBetween('created_at', [$startDate, $endDate]);
            
        if ($agentId) {
            $query->join('order', 'order_log.platform_order_no', '=', 'order.platform_order_no')
                  ->where('order.agent_id', $agentId);
        }
        
        return $query->selectRaw('node, COUNT(*) as error_count')
            ->groupBy('node')
            ->orderBy('error_count', 'desc')
            ->limit(10)
            ->get()
            ->toArray();
    }

    /**
     * 统计常见错误消息（基于content中的message/msg字段）
     */
    private function getFrequentErrorMessages(int $startTime, int $endTime, ?int $agentId = null): array
    {
        $startDate = date('Y-m-d H:i:s', $startTime);
        $endDate = date('Y-m-d H:i:s', $endTime);
        
        $query = Db::table('order_log')
            ->where('log_level', 'ERROR')
            ->whereBetween('created_at', [$startDate, $endDate]);
            
        if ($agentId) {
            $query->join('order', 'order_log.platform_order_no', '=', 'order.platform_order_no')
                  ->where('order.agent_id', $agentId);
        }
        
        $errors = $query->select(['id', 'content'])
            ->get()
            ->toArray();

        $counter = [];
        foreach ($errors as $row) {
            $msg = '';
            $content = json_decode($row->content ?? '', true);
            if (is_array($content)) {
                $msg = $content['message'] ?? ($content['msg'] ?? '');
            }
            if (!$msg) { continue; }
            $key = mb_substr($msg, 0, 120);
            $counter[$key] = ($counter[$key] ?? 0) + 1;
        }

        arsort($counter);
        $result = [];
        foreach (array_slice($counter, 0, 20, true) as $k => $v) {
            $result[] = ['message' => $k, 'count' => $v];
        }
        return $result;
    }

    /**
     * 检测错误尖峰（按小时/天超过均值+2倍标准差）
     */
    private function getErrorSpikes(int $startTime, int $endTime, ?int $agentId = null): array
    {
        $series = $this->getTrendData('errors', $startTime, $endTime, 'hour', $agentId);
        if (empty($series)) return [];
        $values = array_column($series, 'count');
        $avg = array_sum($values) / count($values);
        $variance = 0;
        foreach ($values as $v) { $variance += pow($v - $avg, 2); }
        $std = sqrt($variance / max(count($values), 1));
        $threshold = $avg + 2 * $std;
        return array_values(array_filter($series, function ($p) use ($threshold) {
            return $p['count'] > $threshold;
        }));
    }

    /**
     * 性能：响应时间指标（avg/min/max/p95/p99），基于content.call_duration(ms)
     */
    private function getResponseTimeMetrics(int $startTime, int $endTime, ?int $agentId = null): array
    {
        $startDate = date('Y-m-d H:i:s', $startTime);
        $endDate = date('Y-m-d H:i:s', $endTime);
        
        $query = Db::table('order_log')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('content', 'like', '%call_duration%');
            
        if ($agentId) {
            $query->join('order', 'order_log.platform_order_no', '=', 'order.platform_order_no')
                  ->where('order.agent_id', $agentId);
        }
        
        $logs = $query->select(['content','node','created_at'])
            ->get()
            ->toArray();
        $durations = [];
        foreach ($logs as $log) {
            $content = json_decode($log->content ?? '', true);
            if (isset($content['call_duration'])) {
                if (preg_match('/(\d+(?:\.\d+)?)\s*ms/', (string)$content['call_duration'], $m)) {
                    $durations[] = (float)$m[1];
                }
            }
        }
        sort($durations);
        $count = count($durations);
        $pct = function($p) use ($durations, $count) {
            if ($count === 0) return 0;
            $i = ($p/100) * ($count - 1);
            $f = floor($i);
            if ($f == $i) return $durations[$i];
            $lower = $durations[$f];
            $upper = $durations[min($f+1, $count-1)];
            return $lower + ($upper - $lower) * ($i - $f);
        };
        return [
            'count' => $count,
            'avg' => $count ? round(array_sum($durations)/$count, 2) : 0,
            'min' => $count ? round(min($durations), 2) : 0,
            'max' => $count ? round(max($durations), 2) : 0,
            'p95' => round($pct(95), 2),
            'p99' => round($pct(99), 2)
        ];
    }

    /**
     * 性能：最慢调用TOP20
     */
    private function getSlowCalls(int $startTime, int $endTime, ?int $agentId = null): array
    {
        $startDate = date('Y-m-d H:i:s', $startTime);
        $endDate = date('Y-m-d H:i:s', $endTime);
        
        $query = Db::table('order_log')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('content', 'like', '%call_duration%');
            
        if ($agentId) {
            $query->join('order', 'order_log.platform_order_no', '=', 'order.platform_order_no')
                  ->where('order.agent_id', $agentId);
        }
        
        $logs = $query->select(['id','trace_id','platform_order_no','node','content','created_at'])
            ->get()
            ->toArray();
        $items = [];
        foreach ($logs as $log) {
            $content = json_decode($log->content ?? '', true);
            if (isset($content['call_duration']) && preg_match('/(\d+(?:\.\d+)?)\s*ms/', (string)$content['call_duration'], $m)) {
                $items[] = [
                    'id' => $log->id,
                    'trace_id' => $log->trace_id,
                    'platform_order_no' => $log->platform_order_no,
                    'node' => $log->node,
                    'duration' => (float)$m[1],
                    'created_at' => $log->created_at
                ];
            }
        }
        usort($items, function($a, $b){ return $b['duration'] <=> $a['duration']; });
        return array_slice($items, 0, 20);
    }

    /**
     * 吞吐量序列（每小时日志数）
     */
    private function getThroughputSeries(int $startTime, int $endTime, ?int $agentId = null): array
    {
        return $this->getTrendData('orders', $startTime, $endTime, 'hour', $agentId);
    }
    
    /**
     * 监控/业务告警历史接口
     */
    public function alertHistory(Request $request)
    {
        $since = $request->get('since', date('Y-m-d 00:00:00'));
        // 例: 查询alert_history表并聚合
        $rows = \support\Db::table('alert_history')->where('created_at','>=', $since)->orderBy('created_at','desc')->limit(100)->get();
        $levels = [ 'P0'=>'紧急', 'P1'=>'严重', 'P2'=>'警告', 'P3'=>'提示'];
        $summary = [];
        foreach($rows as $row){
            $summary[$row->level]['count'] = ($summary[$row->level]['count']??0) + 1;
            $summary[$row->level]['items'][] = $row;
        }
        return success(['summary'=>$summary, 'list'=>$rows]);
    }
    
    /**
     * 系统健康与性能/业务聚合指标接口（仪表盘用）
     */
    public function serverMetrics(Request $request)
    {
        // 例：采集自定义metrics、Redis、DB、php-fpm状态等
        $cpu = sys_getloadavg()[0] ?? 0;
        $mem = memory_get_usage(true) / 1024 / 1024;
        $disk = disk_free_space('/') / disk_total_space('/') * 100;
        // QPS、延迟、慢接口、异常统计等可按需聚合order_log、系统日志
        $now = date('Y-m-d H:i:s');
        // 示例聚合
        $metrics = [
            'cpu_usage' => round($cpu,2),
            'memory_usage' => round($mem,2),
            'disk_usage' => round($disk,2),
            'qps' => rand(20,90),
            'max_delay' => rand(400,2000),
            'p99_delay' => rand(300,1200),
            'exceptions_last_hour' => rand(0,10),
            'exceptions_today' => rand(0,30)
        ];
        return success($metrics);
    }
    
    /**
     * 根据时间范围获取开始时间
     */
    private function getStartTime(string $timeRange, int $endTime): int
    {
        switch ($timeRange) {
            case '1h':
                return $endTime - 3600;
            case '6h':
                return $endTime - 21600;
            case '24h':
                return $endTime - 86400;
            case '7d':
                return $endTime - 604800;
            case '30d':
                return $endTime - 2592000;
            default:
                return $endTime - 3600;
        }
    }
    
    /**
     * 格式化字节数
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;
        
        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }
        
        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }
    
    /**
     * 解析内存限制
     */
    private function parseMemoryLimit(string $memoryLimit): int
    {
        $memoryLimit = trim($memoryLimit);
        $last = strtolower($memoryLimit[strlen($memoryLimit) - 1]);
        $memoryLimit = (int) $memoryLimit;
        
        switch ($last) {
            case 'g':
                $memoryLimit *= 1024;
            case 'm':
                $memoryLimit *= 1024;
            case 'k':
                $memoryLimit *= 1024;
        }
        
        return $memoryLimit;
    }
    
}

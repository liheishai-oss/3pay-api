<?php

namespace app\admin\controller\v1;

use support\Request;
use support\Response;
use support\Db;
use support\Log;

/**
 * 日志分析控制器
 * 提供链路追踪可视化、性能瓶颈分析、错误根因分析等功能
 */
class LogAnalysisController
{
    /**
     * 日志分析页面
     */
    public function index(Request $request): Response
    {
        return raw_view('admin/log_analysis/index', [
            'title' => '日志分析工具'
        ]);
    }
    
    /**
     * 链路追踪可视化
     */
    public function traceVisualization(Request $request): Response
    {
        try {
            $traceId = $request->get('trace_id', '');
            if (empty($traceId)) {
                return $this->error('TraceId不能为空');
            }
            
            // 获取链路追踪数据
            $traceData = $this->getTraceData($traceId);
            
            return $this->success($traceData, '获取成功');
            
        } catch (\Exception $e) {
            return $this->error('获取链路追踪数据失败：' . $e->getMessage());
        }
    }
    
    /**
     * 性能瓶颈分析
     */
    public function performanceAnalysis(Request $request): Response
    {
        try {
            $timeRange = $request->get('time_range', '1h');
            $endTime = time();
            $startTime = $this->getStartTime($timeRange, $endTime);
            
            $analysis = [
                'response_time_analysis' => $this->getResponseTimeAnalysis($startTime, $endTime),
                'slow_operations' => $this->getSlowOperations($startTime, $endTime),
                'error_rate_analysis' => $this->getErrorRateAnalysis($startTime, $endTime),
                'throughput_analysis' => $this->getThroughputAnalysis($startTime, $endTime)
            ];
            
            return $this->success($analysis, '获取成功');
            
        } catch (\Exception $e) {
            return $this->error('性能分析失败：' . $e->getMessage());
        }
    }
    
    /**
     * 错误根因分析
     */
    public function errorRootCauseAnalysis(Request $request): Response
    {
        try {
            $timeRange = $request->get('time_range', '24h');
            $endTime = time();
            $startTime = $this->getStartTime($timeRange, $endTime);
            
            $analysis = [
                'error_patterns' => $this->getErrorPatterns($startTime, $endTime),
                'error_timeline' => $this->getErrorTimeline($startTime, $endTime),
                'error_correlation' => $this->getErrorCorrelation($startTime, $endTime),
                'error_impact' => $this->getErrorImpact($startTime, $endTime)
            ];
            
            return $this->success($analysis, '获取成功');
            
        } catch (\Exception $e) {
            return $this->error('错误根因分析失败：' . $e->getMessage());
        }
    }
    
    /**
     * 业务指标统计
     */
    public function businessMetrics(Request $request): Response
    {
        try {
            $timeRange = $request->get('time_range', '24h');
            $endTime = time();
            $startTime = $this->getStartTime($timeRange, $endTime);
            
            $metrics = [
                'order_metrics' => $this->getOrderMetrics($startTime, $endTime),
                'payment_metrics' => $this->getPaymentMetrics($startTime, $endTime),
                'conversion_metrics' => $this->getConversionMetrics($startTime, $endTime),
                'user_behavior' => $this->getUserBehaviorMetrics($startTime, $endTime)
            ];
            
            return $this->success($metrics, '获取成功');
            
        } catch (\Exception $e) {
            return $this->error('业务指标统计失败：' . $e->getMessage());
        }
    }
    
    /**
     * 获取链路追踪数据
     */
    private function getTraceData(string $traceId): array
    {
        $logs = Db::table('order_log')
            ->where('trace_id', $traceId)
            ->orderBy('created_at', 'asc')
            ->get()
            ->toArray();
        
        if (empty($logs)) {
            return [
                'trace_id' => $traceId,
                'nodes' => [],
                'duration' => 0,
                'status' => 'not_found'
            ];
        }
        
        $nodes = [];
        $startTime = null;
        $endTime = null;
        
        foreach ($logs as $log) {
            $content = json_decode($log->content, true);
            $nodeTime = strtotime($log->created_at);
            
            if ($startTime === null) {
                $startTime = $nodeTime;
            }
            $endTime = $nodeTime;
            
            $nodes[] = [
                'id' => $log->id,
                'node' => $log->node,
                'log_type' => $log->log_type,
                'log_level' => $log->log_level,
                'timestamp' => $log->created_at,
                'duration' => $this->extractDuration($content),
                'content' => $content,
                'ip' => $log->ip,
                'user_agent' => $log->user_agent
            ];
        }
        
        return [
            'trace_id' => $traceId,
            'nodes' => $nodes,
            'duration' => $endTime - $startTime,
            'start_time' => date('Y-m-d H:i:s', $startTime),
            'end_time' => date('Y-m-d H:i:s', $endTime),
            'status' => 'success'
        ];
    }
    
    /**
     * 获取响应时间分析
     */
    private function getResponseTimeAnalysis(int $startTime, int $endTime): array
    {
        $startDate = date('Y-m-d H:i:s', $startTime);
        $endDate = date('Y-m-d H:i:s', $endTime);
        
        // 按节点统计响应时间
        $nodeStats = Db::table('order_log')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('content', 'like', '%call_duration%')
            ->get()
            ->toArray();
        
        $responseTimes = [];
        foreach ($nodeStats as $log) {
            $content = json_decode($log->content, true);
            if (isset($content['call_duration'])) {
                $duration = $this->parseDuration($content['call_duration']);
                if ($duration > 0) {
                    $responseTimes[] = [
                        'node' => $log->node,
                        'duration' => $duration,
                        'timestamp' => $log->created_at
                    ];
                }
            }
        }
        
        // 计算统计信息
        $durations = array_column($responseTimes, 'duration');
        $stats = [
            'avg_duration' => count($durations) > 0 ? round(array_sum($durations) / count($durations), 2) : 0,
            'min_duration' => count($durations) > 0 ? min($durations) : 0,
            'max_duration' => count($durations) > 0 ? max($durations) : 0,
            'p95_duration' => $this->calculatePercentile($durations, 95),
            'p99_duration' => $this->calculatePercentile($durations, 99)
        ];
        
        return [
            'stats' => $stats,
            'data' => $responseTimes
        ];
    }
    
    /**
     * 获取慢操作
     */
    private function getSlowOperations(int $startTime, int $endTime): array
    {
        $startDate = date('Y-m-d H:i:s', $startTime);
        $endDate = date('Y-m-d H:i:s', $endTime);
        
        $slowOps = Db::table('order_log')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('content', 'like', '%call_duration%')
            ->get()
            ->toArray();
        
        $slowOperations = [];
        foreach ($slowOps as $log) {
            $content = json_decode($log->content, true);
            if (isset($content['call_duration'])) {
                $duration = $this->parseDuration($content['call_duration']);
                if ($duration > 3000) { // 超过3秒的认为是慢操作
                    $slowOperations[] = [
                        'trace_id' => $log->trace_id,
                        'node' => $log->node,
                        'duration' => $duration,
                        'timestamp' => $log->created_at,
                        'platform_order_no' => $log->platform_order_no
                    ];
                }
            }
        }
        
        // 按耗时排序
        usort($slowOperations, function($a, $b) {
            return $b['duration'] - $a['duration'];
        });
        
        return array_slice($slowOperations, 0, 20); // 返回前20个最慢的操作
    }
    
    /**
     * 获取错误率分析
     */
    private function getErrorRateAnalysis(int $startTime, int $endTime): array
    {
        $startDate = date('Y-m-d H:i:s', $startTime);
        $endDate = date('Y-m-d H:i:s', $endTime);
        
        // 按小时统计错误率
        $hourlyStats = Db::table('order_log')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                HOUR(created_at) as hour,
                COUNT(*) as total_logs,
                SUM(CASE WHEN log_level = "ERROR" THEN 1 ELSE 0 END) as error_logs
            ')
            ->groupBy('HOUR(created_at)')
            ->orderBy('hour')
            ->get()
            ->toArray();
        
        $errorRates = [];
        foreach ($hourlyStats as $stat) {
            $errorRate = $stat->total_logs > 0 ? round(($stat->error_logs / $stat->total_logs) * 100, 2) : 0;
            $errorRates[] = [
                'hour' => $stat->hour,
                'total_logs' => $stat->total_logs,
                'error_logs' => $stat->error_logs,
                'error_rate' => $errorRate
            ];
        }
        
        return $errorRates;
    }
    
    /**
     * 获取吞吐量分析
     */
    private function getThroughputAnalysis(int $startTime, int $endTime): array
    {
        $startDate = date('Y-m-d H:i:s', $startTime);
        $endDate = date('Y-m-d H:i:s', $endTime);
        
        // 按小时统计吞吐量
        $hourlyThroughput = Db::table('order_log')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                HOUR(created_at) as hour,
                COUNT(*) as logs_per_hour
            ')
            ->groupBy('HOUR(created_at)')
            ->orderBy('hour')
            ->get()
            ->toArray();
        
        return array_map(function($stat) {
            return [
                'hour' => $stat->hour,
                'throughput' => $stat->logs_per_hour
            ];
        }, $hourlyThroughput);
    }
    
    /**
     * 获取错误模式
     */
    private function getErrorPatterns(int $startTime, int $endTime): array
    {
        $startDate = date('Y-m-d H:i:s', $startTime);
        $endDate = date('Y-m-d H:i:s', $endTime);
        
        // 按节点统计错误
        $errorPatterns = Db::table('order_log')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('log_level', 'ERROR')
            ->selectRaw('
                node,
                COUNT(*) as error_count,
                COUNT(DISTINCT trace_id) as affected_traces
            ')
            ->groupBy('node')
            ->orderBy('error_count', 'desc')
            ->get()
            ->toArray();
        
        return array_map(function($pattern) {
            return [
                'node' => $pattern->node,
                'error_count' => $pattern->error_count,
                'affected_traces' => $pattern->affected_traces
            ];
        }, $errorPatterns);
    }
    
    /**
     * 获取错误时间线
     */
    private function getErrorTimeline(int $startTime, int $endTime): array
    {
        $startDate = date('Y-m-d H:i:s', $startTime);
        $endDate = date('Y-m-d H:i:s', $endTime);
        
        $errors = Db::table('order_log')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('log_level', 'ERROR')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->toArray();
        
        return array_map(function($error) {
            $content = json_decode($error->content, true);
            return [
                'trace_id' => $error->trace_id,
                'node' => $error->node,
                'timestamp' => $error->created_at,
                'platform_order_no' => $error->platform_order_no,
                'content' => $content
            ];
        }, $errors);
    }
    
    /**
     * 获取错误关联性
     */
    private function getErrorCorrelation(int $startTime, int $endTime): array
    {
        $startDate = date('Y-m-d H:i:s', $startTime);
        $endDate = date('Y-m-d H:i:s', $endTime);
        
        // 查找经常一起出现的错误节点
        $errorTraces = Db::table('order_log')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('log_level', 'ERROR')
            ->select('trace_id', 'node')
            ->get()
            ->toArray();
        
        $traceErrors = [];
        foreach ($errorTraces as $error) {
            $traceErrors[$error->trace_id][] = $error->node;
        }
        
        $correlations = [];
        foreach ($traceErrors as $traceId => $nodes) {
            if (count($nodes) > 1) {
                for ($i = 0; $i < count($nodes); $i++) {
                    for ($j = $i + 1; $j < count($nodes); $j++) {
                        $pair = [$nodes[$i], $nodes[$j]];
                        sort($pair);
                        $key = implode('|', $pair);
                        $correlations[$key] = ($correlations[$key] ?? 0) + 1;
                    }
                }
            }
        }
        
        arsort($correlations);
        
        $result = [];
        foreach ($correlations as $pair => $count) {
            $nodes = explode('|', $pair);
            $result[] = [
                'node1' => $nodes[0],
                'node2' => $nodes[1],
                'correlation_count' => $count
            ];
        }
        
        return array_slice($result, 0, 20);
    }
    
    /**
     * 获取错误影响
     */
    private function getErrorImpact(int $startTime, int $endTime): array
    {
        $startDate = date('Y-m-d H:i:s', $startTime);
        $endDate = date('Y-m-d H:i:s', $endTime);
        
        // 统计错误对订单的影响
        $errorImpact = Db::table('order_log')
            ->join('order', 'order_log.platform_order_no', '=', 'order.platform_order_no')
            ->whereBetween('order_log.created_at', [$startDate, $endDate])
            ->where('order_log.log_level', 'ERROR')
            ->selectRaw('
                order_log.node,
                COUNT(DISTINCT order_log.trace_id) as error_traces,
                COUNT(DISTINCT order.id) as affected_orders,
                SUM(order.order_amount) as affected_amount
            ')
            ->groupBy('order_log.node')
            ->orderBy('affected_orders', 'desc')
            ->get()
            ->toArray();
        
        return array_map(function($impact) {
            return [
                'node' => $impact->node,
                'error_traces' => $impact->error_traces,
                'affected_orders' => $impact->affected_orders,
                'affected_amount' => $impact->affected_amount
            ];
        }, $errorImpact);
    }
    
    /**
     * 获取订单指标
     */
    private function getOrderMetrics(int $startTime, int $endTime): array
    {
        $startDate = date('Y-m-d H:i:s', $startTime);
        $endDate = date('Y-m-d H:i:s', $endTime);
        
        $orderStats = Db::table('order')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                COUNT(*) as total_orders,
                SUM(CASE WHEN pay_status = 1 THEN 1 ELSE 0 END) as paid_orders,
                SUM(CASE WHEN pay_status = 0 THEN 1 ELSE 0 END) as pending_orders,
                SUM(CASE WHEN pay_status = 2 THEN 1 ELSE 0 END) as closed_orders,
                SUM(order_amount) as total_amount,
                AVG(order_amount) as avg_amount
            ')
            ->first();
        
        return [
            'total_orders' => $orderStats->total_orders,
            'paid_orders' => $orderStats->paid_orders,
            'pending_orders' => $orderStats->pending_orders,
            'closed_orders' => $orderStats->closed_orders,
            'total_amount' => $orderStats->total_amount,
            'avg_amount' => round($orderStats->avg_amount, 2),
            'conversion_rate' => $orderStats->total_orders > 0 ? 
                round(($orderStats->paid_orders / $orderStats->total_orders) * 100, 2) : 0
        ];
    }
    
    /**
     * 获取支付指标
     */
    private function getPaymentMetrics(int $startTime, int $endTime): array
    {
        $startDate = date('Y-m-d H:i:s', $startTime);
        $endDate = date('Y-m-d H:i:s', $endTime);
        
        $paymentStats = Db::table('order')
            ->whereBetween('pay_time', [$startDate, $endDate])
            ->where('pay_status', 1)
            ->selectRaw('
                COUNT(*) as payment_count,
                SUM(order_amount) as payment_amount,
                AVG(order_amount) as avg_payment_amount
            ')
            ->first();
        
        return [
            'payment_count' => $paymentStats->payment_count,
            'payment_amount' => $paymentStats->payment_amount,
            'avg_payment_amount' => round($paymentStats->avg_payment_amount, 2)
        ];
    }
    
    /**
     * 获取转化率指标
     */
    private function getConversionMetrics(int $startTime, int $endTime): array
    {
        $startDate = date('Y-m-d H:i:s', $startTime);
        $endDate = date('Y-m-d H:i:s', $endTime);
        
        // 按小时统计转化率
        $hourlyConversion = Db::table('order')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                HOUR(created_at) as hour,
                COUNT(*) as total_orders,
                SUM(CASE WHEN pay_status = 1 THEN 1 ELSE 0 END) as paid_orders
            ')
            ->groupBy('HOUR(created_at)')
            ->orderBy('hour')
            ->get()
            ->toArray();
        
        $conversionRates = [];
        foreach ($hourlyConversion as $stat) {
            $conversionRate = $stat->total_orders > 0 ? 
                round(($stat->paid_orders / $stat->total_orders) * 100, 2) : 0;
            $conversionRates[] = [
                'hour' => $stat->hour,
                'total_orders' => $stat->total_orders,
                'paid_orders' => $stat->paid_orders,
                'conversion_rate' => $conversionRate
            ];
        }
        
        return $conversionRates;
    }
    
    /**
     * 获取用户行为指标
     */
    private function getUserBehaviorMetrics(int $startTime, int $endTime): array
    {
        $startDate = date('Y-m-d H:i:s', $startTime);
        $endDate = date('Y-m-d H:i:s', $endTime);
        
        // 统计用户访问模式
        $userBehavior = Db::table('order_log')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('log_type', '访问')
            ->selectRaw('
                COUNT(DISTINCT trace_id) as unique_visits,
                COUNT(*) as total_visits,
                COUNT(DISTINCT ip) as unique_ips
            ')
            ->first();
        
        return [
            'unique_visits' => $userBehavior->unique_visits,
            'total_visits' => $userBehavior->total_visits,
            'unique_ips' => $userBehavior->unique_ips,
            'avg_visits_per_user' => $userBehavior->unique_visits > 0 ? 
                round($userBehavior->total_visits / $userBehavior->unique_visits, 2) : 0
        ];
    }
    
    /**
     * 提取持续时间
     */
    private function extractDuration(array $content): int
    {
        if (isset($content['call_duration'])) {
            return $this->parseDuration($content['call_duration']);
        }
        return 0;
    }
    
    /**
     * 解析持续时间字符串
     */
    private function parseDuration(string $duration): int
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*ms/', $duration, $matches)) {
            return (int)($matches[1]);
        }
        return 0;
    }
    
    /**
     * 计算百分位数
     */
    private function calculatePercentile(array $values, int $percentile): float
    {
        if (empty($values)) {
            return 0;
        }
        
        sort($values);
        $index = ($percentile / 100) * (count($values) - 1);
        
        if (floor($index) == $index) {
            return $values[$index];
        } else {
            $lower = $values[floor($index)];
            $upper = $values[ceil($index)];
            return $lower + ($upper - $lower) * ($index - floor($index));
        }
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





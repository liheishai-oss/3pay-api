<?php

namespace app\admin\controller\v1;

use app\service\OrderLogService;
use support\Request;
use support\Response;
use support\Db;

/**
 * 订单日志查询控制器
 */
class OrderLogController
{
    /**
     * 日志查询页面
     */
    public function index(Request $request): Response
    {
        return raw_view('admin/order_log/index', [
            'title' => '订单日志查询'
        ]);
    }
    
    /**
     * 搜索日志
     * 支持按商户单号、平台单号、TraceId搜索
     */
    public function search(Request $request): Response
    {
        try {
            $userData = $request->userData;
            $isAgent = ($userData['user_group_id'] ?? 0) == 3;
            
            $merchantOrderNo = $request->get('merchant_order_no', '');
            $platformOrderNo = $request->get('platform_order_no', '');
            $traceId = $request->get('trace_id', '');
            $page = (int)$request->get('page', 1);
            $pageSize = (int)$request->get('page_size', 50);
            
            // 验证至少提供一个搜索条件
            if (empty($merchantOrderNo) && empty($platformOrderNo) && empty($traceId)) {
                return error('请至少提供一个搜索条件');
            }
            
            $logs = [];
            $orderInfo = null;
            
            // 按TraceId搜索
            if (!empty($traceId)) {
                $logs = OrderLogService::getLogsByTraceId($traceId);
                if (!empty($logs)) {
                    $orderInfo = $this->getOrderInfoByTraceId($traceId, $isAgent ? $userData['agent_id'] : null);
                }
            }
            // 按平台订单号搜索
            elseif (!empty($platformOrderNo)) {
                $logs = OrderLogService::getLogsByOrderNo($platformOrderNo);
                if (!empty($logs)) {
                    $orderInfo = $this->getOrderInfoByPlatformOrderNo($platformOrderNo, $isAgent ? $userData['agent_id'] : null);
                }
            }
            // 按商户订单号搜索
            elseif (!empty($merchantOrderNo)) {
                $logs = $this->getLogsByMerchantOrderNo($merchantOrderNo, $isAgent ? $userData['agent_id'] : null);
                if (!empty($logs)) {
                    $orderInfo = $this->getOrderInfoByMerchantOrderNo($merchantOrderNo, $isAgent ? $userData['agent_id'] : null);
                }
            }
            
            // 格式化日志数据
            $formattedLogs = $this->formatLogs($logs);
            
            return success([
                'logs' => $formattedLogs,
                'order_info' => $orderInfo,
                'total' => count($formattedLogs),
                'search_params' => [
                    'merchant_order_no' => $merchantOrderNo,
                    'platform_order_no' => $platformOrderNo,
                    'trace_id' => $traceId
                ]
            ], '查询成功');
            
        } catch (\Exception $e) {
            return error('查询失败：' . $e->getMessage());
        }
    }
    
    /**
     * 获取日志详情
     */
    public function detail(Request $request): Response
    {
        try {
            $logId = $request->get('log_id');
            if (empty($logId)) {
                return error('日志ID不能为空');
            }
            
            $log = Db::table('order_log')->where('id', $logId)->first();
            if (!$log) {
                return error('日志不存在');
            }
            
            // 解析日志内容
            $content = json_decode($log->content, true);
            
            return success([
                'log' => [
                    'id' => $log->id,
                    'order_id' => $log->order_id,
                    'platform_order_no' => $log->platform_order_no,
                    'merchant_order_no' => $log->merchant_order_no,
                    'trace_id' => $log->trace_id,
                    'log_type' => $log->log_type,
                    'log_level' => $log->log_level,
                    'node' => $log->node,
                    'content' => $content,
                    'ip' => $log->ip,
                    'user_agent' => $log->user_agent,
                    'created_at' => $log->created_at
                ]
            ], '获取成功');
            
        } catch (\Exception $e) {
            return error('获取失败：' . $e->getMessage());
        }
    }
    
    /**
     * 按商户订单号搜索日志
     */
    private function getLogsByMerchantOrderNo(string $merchantOrderNo, ?int $agentId = null): array
    {
        try {
            $query = Db::table('order_log')
                ->where('merchant_order_no', $merchantOrderNo);
                
            if ($agentId) {
                $query->join('order', 'order_log.platform_order_no', '=', 'order.platform_order_no')
                      ->where('order.agent_id', $agentId);
            }
            
            return $query->orderBy('order_log.id', 'desc')
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * 根据TraceId获取订单信息
     */
    private function getOrderInfoByTraceId(string $traceId, ?int $agentId = null): ?array
    {
        try {
            $query = Db::table('order')
                ->where('trace_id', $traceId);
                
            if ($agentId) {
                $query->where('agent_id', $agentId);
            }
            
            $order = $query->first();
            
            return $order ? $this->formatOrderInfo($order) : null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * 根据平台订单号获取订单信息
     */
    private function getOrderInfoByPlatformOrderNo(string $platformOrderNo, ?int $agentId = null): ?array
    {
        try {
            $query = Db::table('order')
                ->where('platform_order_no', $platformOrderNo);
                
            if ($agentId) {
                $query->where('agent_id', $agentId);
            }
            
            $order = $query->first();
            
            return $order ? $this->formatOrderInfo($order) : null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * 根据商户订单号获取订单信息
     */
    private function getOrderInfoByMerchantOrderNo(string $merchantOrderNo, ?int $agentId = null): ?array
    {
        try {
            $query = Db::table('order')
                ->where('merchant_order_no', $merchantOrderNo);
                
            if ($agentId) {
                $query->where('agent_id', $agentId);
            }
            
            $order = $query->first();
            
            return $order ? $this->formatOrderInfo($order) : null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * 格式化订单信息
     */
    private function formatOrderInfo($order): array
    {
        return [
            'id' => $order->id,
            'platform_order_no' => $order->platform_order_no,
            'merchant_order_no' => $order->merchant_order_no,
            'trace_id' => $order->trace_id,
            'order_amount' => $order->order_amount,
            'pay_status' => $order->pay_status,
            'pay_status_text' => $this->getPayStatusText($order->pay_status),
            'client_ip' => $order->client_ip,
            'first_open_ip' => $order->first_open_ip,
            'created_at' => $order->created_at,
            'pay_time' => $order->pay_time,
            'expire_time' => $order->expire_time
        ];
    }
    
    /**
     * 格式化日志数据
     */
    private function formatLogs(array $logs): array
    {
        $formattedLogs = [];
        
        foreach ($logs as $log) {
            $content = json_decode($log->content, true);
            
            $formattedLogs[] = [
                'id' => $log->id,
                'order_id' => $log->order_id,
                'platform_order_no' => $log->platform_order_no,
                'merchant_order_no' => $log->merchant_order_no,
                'trace_id' => $log->trace_id,
                'log_type' => $log->log_type,
                'log_level' => $log->log_level,
                'node' => $log->node,
                'content' => $content,
                'ip' => $log->ip,
                'user_agent' => $log->user_agent,
                'created_at' => $log->created_at,
                'log_level_class' => $this->getLogLevelClass($log->log_level),
                'node_description' => $this->getNodeDescription($log->node)
            ];
        }
        
        return $formattedLogs;
    }
    
    /**
     * 获取日志级别对应的CSS类
     */
    private function getLogLevelClass(string $level): string
    {
        switch ($level) {
            case 'ERROR':
                return 'text-danger';
            case 'WARN':
                return 'text-warning';
            case 'INFO':
                return 'text-info';
            default:
                return 'text-muted';
        }
    }
    
    /**
     * 获取节点描述
     */
    private function getNodeDescription(string $node): string
    {
        $descriptions = [
            '节点1-API请求接收' => '接收API请求，记录请求来源和参数',
            '节点2-订单参数验证' => '验证订单参数是否完整和有效',
            '节点3-订单号生成' => '生成唯一的平台订单号',
            '节点4-支付主体选择' => '选择合适的支付主体',
            '节点5-订单数据持久化' => '将订单数据保存到数据库',
            '节点6-订单创建响应' => '返回订单创建结果给客户端',
            '节点7-支付页面访问' => '用户访问支付页面',
            '节点8-异地IP检测' => '检测是否为异地访问',
            '节点9-订单状态检查' => '检查订单状态是否允许支付',
            '节点10-OAuth授权跳转' => '跳转到支付宝OAuth授权页面',
            '节点11-OAuth回调接收' => '接收支付宝OAuth授权回调',
            '节点12-获取支付宝用户ID' => '通过授权码获取支付宝用户ID',
            '节点13-黑名单检查' => '检查用户是否在黑名单中',
            '节点15-支付接口调用' => '调用支付宝支付接口',
            '节点16-支付宝返回数据解析' => '解析支付宝接口返回数据',
            '节点17-支付页面渲染' => '渲染支付页面或二维码'
        ];
        
        return $descriptions[$node] ?? $node;
    }
    
    
    /**
     * 获取支付状态文本
     */
    private function getPayStatusText(int $status): string
    {
        switch ($status) {
            case 0:
                return '待支付';
            case 1:
                return '已支付';
            case 2:
                return '已关闭';
            case 3:
                return '已退款';
            default:
                return '未知状态';
        }
    }
    
}


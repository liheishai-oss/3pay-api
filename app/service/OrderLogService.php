<?php

namespace app\service;

use app\model\Order;
use support\Db;
use support\Log;

/**
 * 订单日志服务
 * 用于记录订单全链路日志
 */
class OrderLogService
{
    /**
     * 记录订单日志
     * 
     * @param string $traceId 链路追踪ID
     * @param string $platformOrderNo 平台订单号
     * @param string $merchantOrderNo 商户订单号
     * @param string $logType 日志类型（创建/访问/支付/回调/关闭）
     * @param string $logLevel 日志级别（INFO/WARN/ERROR）
     * @param string $node 流程节点
     * @param array $content 日志内容
     * @param string $ip 操作IP
     * @param string $userAgent 用户代理
     * @return bool
     */
    public static function log(
        string $traceId,
        string $platformOrderNo,
        string $merchantOrderNo,
        string $logType,
        string $logLevel,
        string $node,
        array $content = [],
        string $ip = '',
        string $userAgent = ''
    ): bool {
        try {
            // 统一规范化为IPv4（若为IPv6则尽量提取其中的IPv4，否则置空）
            $ip = self::normalizeIp($ip);
            // 获取订单ID
            $order = Order::where('platform_order_no', $platformOrderNo)->first();
            $orderId = $order ? $order->id : 0;
            
            // 插入日志记录
            $logId = Db::table('order_log')->insertGetId([
                'order_id' => $orderId,
                'platform_order_no' => $platformOrderNo,
                'merchant_order_no' => $merchantOrderNo,
                'trace_id' => $traceId,
                'log_type' => $logType,
                'log_level' => $logLevel,
                'node' => $node,
                'content' => json_encode($content, JSON_UNESCAPED_UNICODE),
                'ip' => $ip,
                'user_agent' => $userAgent,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            // 同时记录到系统日志
            Log::log($logLevel, "订单日志[{$node}]", [
                'trace_id' => $traceId,
                'platform_order_no' => $platformOrderNo,
                'merchant_order_no' => $merchantOrderNo,
                'log_type' => $logType,
                'node' => $node,
                'content' => $content
            ]);
            
            return $logId > 0;
            
        } catch (\Exception $e) {
            Log::error('订单日志记录失败', [
                'trace_id' => $traceId,
                'platform_order_no' => $platformOrderNo,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 规范化IP为IPv4
     * - 如果是IPv6映射（如 ::ffff:1.2.3.4），提取其中的IPv4
     * - 如果是纯IPv6且无法提取IPv4，则返回空字符串
     */
    private static function normalizeIp(string $ip): string
    {
        if ($ip === '') {
            return '';
        }
        // 如果已经是标准IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip;
        }
        // 尝试从字符串中提取IPv4片段（适配IPv6映射或带前缀的形式）
        if (preg_match('/(\d{1,3}(?:\.\d{1,3}){3})/', $ip, $m)) {
            if (filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $m[1];
            }
        }
        // 不是IPv4，丢弃以避免存储IPv6
        return '';
    }
    
    /**
     * 记录订单状态变更
     * 
     * @param string $traceId 链路追踪ID
     * @param int $orderId 订单ID
     * @param string $oldStatus 原状态
     * @param string $newStatus 新状态
     * @param string $operator 操作者
     * @param string $reason 变更原因
     * @param string $remark 备注
     * @return bool
     */
    public static function logStatusChange(
        string $traceId,
        int $orderId,
        string $oldStatus,
        string $newStatus,
        string $operator = 'system',
        string $reason = '',
        string $remark = ''
    ): bool {
        try {
            $logId = Db::table('order_status_history')->insertGetId([
                'order_id' => $orderId,
                'trace_id' => $traceId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'operator' => $operator,
                'reason' => $reason,
                'remark' => $remark,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            return $logId > 0;
            
        } catch (\Exception $e) {
            Log::error('订单状态变更日志记录失败', [
                'trace_id' => $traceId,
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * 根据TraceId查询日志
     * 
     * @param string $traceId 链路追踪ID
     * @return array
     */
    public static function getLogsByTraceId(string $traceId): array
    {
        try {
            return Db::table('order_log')
                ->where('trace_id', $traceId)
                ->orderBy('id', 'desc')
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            Log::error('查询订单日志失败', [
                'trace_id' => $traceId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * 根据订单号查询日志
     * 
     * @param string $platformOrderNo 平台订单号
     * @return array
     */
    public static function getLogsByOrderNo(string $platformOrderNo): array
    {
        try {
            return Db::table('order_log')
                ->where('platform_order_no', $platformOrderNo)
                ->orderBy('id', 'desc')
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            Log::error('查询订单日志失败', [
                'platform_order_no' => $platformOrderNo,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}

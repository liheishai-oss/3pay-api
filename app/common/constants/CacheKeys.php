<?php

namespace app\common\constants;

/**
 * 缓存键管理
 */
class CacheKeys
{
    /**
     * 订单提交日志缓存键
     * @param string $orderNumber
     * @return string
     */
    public static function getOrderCommitLog(string $orderNumber): string
    {
        return "order:commit:{$orderNumber}";
    }
    
    /**
     * 商户订单号缓存键
     * @param int $merchantId
     * @param string $merchantOrderNo
     * @return string
     */
    public static function getMerchantOrderNo(int $merchantId, string $merchantOrderNo): string
    {
        return "order:merchant:{$merchantId}:{$merchantOrderNo}";
    }
    
    /**
     * 主体关闭推送缓存键（用于防止重复推送）
     * @param int $subjectId 主体ID
     * @return string
     */
    public static function getSubjectDisabledNotifyKey(int $subjectId): string
    {
        return "subject:disabled:notify:{$subjectId}";
    }
    
    /**
     * 分账任务队列键
     * @return string
     */
    public static function getRoyaltyQueueKey(): string
    {
        return "royalty:queue";
    }
    
    /**
     * 分账处理中标记键（防止重复处理）
     * @param int $orderId 订单ID
     * @return string
     */
    public static function getRoyaltyProcessingKey(int $orderId): string
    {
        return "royalty:processing:{$orderId}";
    }

    /**
     * 分账重试队列键
     * @return string
     */
    public static function getRoyaltyRetryQueueKey(): string
    {
        return "royalty:retry:queue";
    }

    /**
     * 待支付/延迟分账任务队列（Sorted Set用于定时重试）
     */
    public static function getRoyaltyPendingQueueKey(): string
    {
        return "royalty:pending:queue";
    }

    /**
     * 待支付/延迟分账任务数据存储（Hash记录原始payload）
     */
    public static function getRoyaltyPendingDataKey(): string
    {
        return "royalty:pending:data";
    }

    /**
     * 分账失败通知标记键（防止重复推送）
     * @param int $orderId
     * @return string
     */
    public static function getRoyaltyFailureNotifyKey(int $orderId): string
    {
        return "royalty:failure:notify:{$orderId}";
    }

    /**
     * 主体特定错误码通知标记键（防止重复推送，如 isv.insufficient-isv-permissions）
     * @param int $subjectId 主体ID
     * @param string $errorCode 错误码
     * @return string
     */
    public static function getSubjectErrorNotifyKey(int $subjectId, string $errorCode): string
    {
        return "subject:error:notify:{$subjectId}:" . strtolower($errorCode);
    }
}



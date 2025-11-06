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
}



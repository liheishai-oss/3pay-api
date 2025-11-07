<?php

namespace app\common\constants;

/**
 * 订单常量
 */
class OrderConstants
{
    // 订单号重试次数限制
    const ORDER_NUMBER_RETRY_LIMIT = 10;
    
    // 订单号缓存过期时间（秒）
    const ORDER_NUMBER_EXPIRE = 600; // 10分钟
    
    // 订单状态
    const STATUS_UNPAID = 0;    // 待支付
    const STATUS_PAID = 1;      // 已支付
    const STATUS_CLOSED = 2;    // 已关闭
    const STATUS_REFUNDED = 3;  // 已退款
    
    // 通知状态
    const NOTIFY_STATUS_PENDING = 0;  // 待通知
    const NOTIFY_STATUS_SUCCESS = 1;  // 通知成功
    const NOTIFY_STATUS_FAILED = 2;   // 通知失败
    
    // 订单过期时间（分钟）
    const ORDER_EXPIRE_MINUTES = 10;
}



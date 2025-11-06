<?php

namespace app\service\alipay;

/**
 * 支付宝常量类
 */
class AlipayConstants
{
    // 支付方式
    const PAYMENT_METHOD_WAP = 'wap';           // WAP支付
    const PAYMENT_METHOD_APP = 'app';           // APP支付
    const PAYMENT_METHOD_PAGE = 'page';         // PC网站支付
    const PAYMENT_METHOD_QR = 'qr';             // 扫码支付
    const PAYMENT_METHOD_BAR = 'bar';           // 条码支付
    const PAYMENT_METHOD_PREAUTH = 'preauth';   // 预授权支付
    
    // 交易状态
    const TRADE_STATUS_WAIT_BUYER_PAY = 'WAIT_BUYER_PAY';     // 交易创建，等待买家付款
    const TRADE_STATUS_TRADE_CLOSED = 'TRADE_CLOSED';         // 未付款交易超时关闭，或支付完成后全额退款
    const TRADE_STATUS_TRADE_SUCCESS = 'TRADE_SUCCESS';      // 交易支付成功
    const TRADE_STATUS_TRADE_FINISHED = 'TRADE_FINISHED';     // 交易结束，不可退款
    
    // 退款状态
    const REFUND_STATUS_REFUND_SUCCESS = 'REFUND_SUCCESS';   // 退款成功
    const REFUND_STATUS_REFUND_CLOSED = 'REFUND_CLOSED';     // 退款关闭
    
    // 预授权状态
    const PREAUTH_STATUS_INIT = 'INIT';                       // 初始状态
    const PREAUTH_STATUS_SUCCESS = 'SUCCESS';                 // 预授权成功
    const PREAUTH_STATUS_CLOSED = 'CLOSED';                   // 预授权关闭
    
    // OAuth授权范围
    const OAUTH_SCOPE_AUTH_USER = 'auth_user';                // 获取用户信息
    const OAUTH_SCOPE_AUTH_BASE = 'auth_base';                // 静默授权
    
    // 账单类型
    const BILL_TYPE_TRADE = 'trade';                          // 交易账单
    const BILL_TYPE_SIGNCUSTOMER = 'signcustomer';            // 签约账单
    
    // 签名类型
    const SIGN_TYPE_RSA2 = 'RSA2';                            // RSA2签名
    const SIGN_TYPE_RSA = 'RSA';                              // RSA签名
    
    // 字符集
    const CHARSET_UTF8 = 'UTF-8';                            // UTF-8编码
    
    // 网关地址
    const GATEWAY_PRODUCTION = 'https://openapi.alipay.com/gateway.do';
    const GATEWAY_SANDBOX = 'https://openapi.alipaydev.com/gateway.do';
    
    // 通知类型
    const NOTIFY_TYPE_PAYMENT = 'payment';                    // 支付通知
    const NOTIFY_TYPE_REFUND = 'refund';                      // 退款通知
    const NOTIFY_TYPE_PREAUTH = 'preauth';                    // 预授权通知
    
    // 错误代码
    const ERROR_CODE_INVALID_PARAM = 'INVALID_PARAM';         // 参数错误
    const ERROR_CODE_SIGN_ERROR = 'SIGN_ERROR';               // 签名错误
    const ERROR_CODE_SYSTEM_ERROR = 'SYSTEM_ERROR';           // 系统错误
    const ERROR_CODE_TRADE_NOT_EXIST = 'TRADE_NOT_EXIST';     // 交易不存在
    const ERROR_CODE_TRADE_STATUS_ERROR = 'TRADE_STATUS_ERROR'; // 交易状态错误
    
    // 缓存键前缀
    const CACHE_PREFIX_NOTIFY = 'alipay_notify:';            // 通知缓存前缀
    const CACHE_PREFIX_REFUND_NOTIFY = 'alipay_refund_notify:'; // 退款通知缓存前缀
    const CACHE_PREFIX_OAUTH_TOKEN = 'alipay_oauth_token:'; // OAuth令牌缓存前缀
    const CACHE_PREFIX_BUY_LIMIT = 'buy_user:';              // 购买限制缓存前缀
    
    // 缓存过期时间（秒）
    const CACHE_EXPIRE_NOTIFY = 300;                          // 通知缓存5分钟
    const CACHE_EXPIRE_OAUTH_TOKEN = 3600;                   // OAuth令牌缓存1小时
    const CACHE_EXPIRE_BUY_LIMIT = 86400;                    // 购买限制缓存24小时
    
    // 订单过期时间（分钟）
    const ORDER_EXPIRE_MINUTES = 30;                          // 订单30分钟过期
    
    // 最大重试次数
    const MAX_RETRY_COUNT = 3;                               // 最大重试3次
    
    // 超时时间（秒）
    const TIMEOUT_SECONDS = 30;                              // 请求超时30秒
}

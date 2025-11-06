<?php

namespace app\service\alipay;

use Exception;

/**
 * 支付宝异常类
 */
class AlipayException extends Exception
{
    /**
     * 错误代码
     * @var string
     */
    private $errorCode;
    
    /**
     * 错误详情
     * @var array
     */
    private $errorDetails;
    
    /**
     * 构造函数
     * @param string $message 错误消息
     * @param string $errorCode 错误代码
     * @param array $errorDetails 错误详情
     * @param int $code 异常代码
     * @param Exception|null $previous 前一个异常
     */
    public function __construct(
        string $message = "",
        string $errorCode = "",
        array $errorDetails = [],
        int $code = 0,
        Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->errorCode = $errorCode;
        $this->errorDetails = $errorDetails;
    }
    
    /**
     * 获取错误代码
     * @return string
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
    
    /**
     * 获取错误详情
     * @return array
     */
    public function getErrorDetails(): array
    {
        return $this->errorDetails;
    }
    
    /**
     * 创建配置错误异常
     * @param string $message 错误消息
     * @param array $details 错误详情
     * @return static
     */
    public static function configError(string $message, array $details = []): self
    {
        return new self($message, AlipayConstants::ERROR_CODE_INVALID_PARAM, $details);
    }
    
    /**
     * 创建签名错误异常
     * @param string $message 错误消息
     * @param array $details 错误详情
     * @return static
     */
    public static function signError(string $message, array $details = []): self
    {
        return new self($message, AlipayConstants::ERROR_CODE_SIGN_ERROR, $details);
    }
    
    /**
     * 创建系统错误异常
     * @param string $message 错误消息
     * @param array $details 错误详情
     * @return static
     */
    public static function systemError(string $message, array $details = []): self
    {
        return new self($message, AlipayConstants::ERROR_CODE_SYSTEM_ERROR, $details);
    }
    
    /**
     * 创建交易不存在异常
     * @param string $message 错误消息
     * @param array $details 错误详情
     * @return static
     */
    public static function tradeNotExist(string $message, array $details = []): self
    {
        return new self($message, AlipayConstants::ERROR_CODE_TRADE_NOT_EXIST, $details);
    }
    
    /**
     * 创建交易状态错误异常
     * @param string $message 错误消息
     * @param array $details 错误详情
     * @return static
     */
    public static function tradeStatusError(string $message, array $details = []): self
    {
        return new self($message, AlipayConstants::ERROR_CODE_TRADE_STATUS_ERROR, $details);
    }
    
    /**
     * 创建支付错误异常
     * @param string $message 错误消息
     * @param string $orderNumber 订单号
     * @param array $details 错误详情
     * @return static
     */
    public static function paymentError(string $message, string $orderNumber = '', array $details = []): self
    {
        $details['order_number'] = $orderNumber;
        return new self($message, 'PAYMENT_ERROR', $details);
    }
    
    /**
     * 创建退款错误异常
     * @param string $message 错误消息
     * @param string $orderNumber 订单号
     * @param string $refundNumber 退款单号
     * @param array $details 错误详情
     * @return static
     */
    public static function refundError(string $message, string $orderNumber = '', string $refundNumber = '', array $details = []): self
    {
        $details['order_number'] = $orderNumber;
        $details['refund_number'] = $refundNumber;
        return new self($message, 'REFUND_ERROR', $details);
    }
    
    /**
     * 创建OAuth错误异常
     * @param string $message 错误消息
     * @param string $authCode 授权码
     * @param array $details 错误详情
     * @return static
     */
    public static function oauthError(string $message, string $authCode = '', array $details = []): self
    {
        $details['auth_code'] = $authCode;
        return new self($message, 'OAUTH_ERROR', $details);
    }
    
    /**
     * 创建通知处理错误异常
     * @param string $message 错误消息
     * @param array $notifyData 通知数据
     * @param array $details 错误详情
     * @return static
     */
    public static function notifyError(string $message, array $notifyData = [], array $details = []): self
    {
        $details['notify_data'] = $notifyData;
        return new self($message, 'NOTIFY_ERROR', $details);
    }
    
    /**
     * 转换为数组格式
     * @return array
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode,
            'error_details' => $this->errorDetails,
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
        ];
    }
}

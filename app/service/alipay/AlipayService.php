<?php

namespace app\service\alipay;

use Exception;
use support\Log;

/**
 * 支付宝服务主类 - 统一入口
 */
class AlipayService
{
    /**
     * 支付服务实例
     * @var AlipayPaymentService
     */
    private $paymentService;
    
    /**
     * 通知服务实例
     * @var AlipayNotifyService
     */
    private $notifyService;
    
    /**
     * 查询服务实例
     * @var AlipayQueryService
     */
    private $queryService;
    
    /**
     * 退款服务实例
     * @var AlipayRefundService
     */
    private $refundService;
    
    /**
     * OAuth服务实例
     * @var AlipayOAuthService
     */
    private $oauthService;
    
    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->paymentService = new AlipayPaymentService();
        $this->notifyService = new AlipayNotifyService();
        $this->queryService = new AlipayQueryService();
        $this->refundService = new AlipayRefundService();
        $this->oauthService = new AlipayOAuthService();
    }
    
    /**
     * WAP支付
     * @param array $orderInfo 订单信息
     * @param array $paymentInfo 支付配置信息
     * @return string 支付URL
     * @throws Exception
     */
    public function wapPay(array $orderInfo, array $paymentInfo): string
    {
        return AlipayPaymentService::wapPay($orderInfo, $paymentInfo);
    }
    
    /**
     * APP支付
     * @param array $orderInfo 订单信息
     * @param array $paymentInfo 支付配置信息
     * @return string 支付参数
     * @throws Exception
     */
    public function appPay(array $orderInfo, array $paymentInfo): string
    {
        return AlipayPaymentService::appPay($orderInfo, $paymentInfo);
    }
    
    /**
     * PC网站支付
     * @param array $orderInfo 订单信息
     * @param array $paymentInfo 支付配置信息
     * @return string 支付表单HTML
     * @throws Exception
     */
    public function pagePay(array $orderInfo, array $paymentInfo): string
    {
        return AlipayPaymentService::pagePay($orderInfo, $paymentInfo);
    }
    
    /**
     * 扫码支付
     * @param array $orderInfo 订单信息
     * @param array $paymentInfo 支付配置信息
     * @return string 二维码内容
     * @throws Exception
     */
    public function qrPay(array $orderInfo, array $paymentInfo): string
    {
        return AlipayPaymentService::qrPay($orderInfo, $paymentInfo);
    }
    
    /**
     * 条码支付（当面付）
     * @param array $orderInfo 订单信息
     * @param string $authCode 授权码
     * @param array $paymentInfo 支付配置信息
     * @return array 支付结果
     * @throws Exception
     */
    public function barPay(array $orderInfo, string $authCode, array $paymentInfo): array
    {
        return AlipayPaymentService::barPay($orderInfo, $authCode, $paymentInfo);
    }
    
    /**
     * 预授权支付
     * @param array $orderInfo 订单信息
     * @param array $paymentInfo 支付配置信息
     * @return string 预授权URL
     * @throws Exception
     */
    public function preAuthPay(array $orderInfo, array $paymentInfo): string
    {
        return AlipayPaymentService::preAuthPay($orderInfo, $paymentInfo);
    }
    
    /**
     * 处理支付通知
     * @param array $params 通知参数
     * @param array $paymentInfo 支付配置信息
     * @param string $payIp 支付者IP
     * @return array 处理结果
     * @throws Exception
     */
    public function handlePaymentNotify(array $params, array $paymentInfo, string $payIp = ''): array
    {
        return AlipayNotifyService::handlePaymentNotify($params, $paymentInfo, $payIp);
    }
    
    /**
     * 处理退款通知
     * @param array $params 通知参数
     * @param array $paymentInfo 支付配置信息
     * @return array 处理结果
     * @throws Exception
     */
    public function handleRefundNotify(array $params, array $paymentInfo): array
    {
        return AlipayNotifyService::handleRefundNotify($params, $paymentInfo);
    }
    
    /**
     * 查询订单状态
     * @param string $orderNumber 商户订单号
     * @param array $paymentInfo 支付配置信息
     * @return array 订单信息
     * @throws Exception
     */
    public function queryOrder(string $orderNumber, array $paymentInfo): array
    {
        return AlipayQueryService::queryOrder($orderNumber, $paymentInfo);
    }
    
    /**
     * 查询退款状态
     * @param string $orderNumber 商户订单号
     * @param string $refundNumber 退款单号
     * @param array $paymentInfo 支付配置信息
     * @return array 退款信息
     * @throws Exception
     */
    public function queryRefund(string $orderNumber, string $refundNumber, array $paymentInfo): array
    {
        return AlipayQueryService::queryRefund($orderNumber, $refundNumber, $paymentInfo);
    }
    
    /**
     * 查询账单
     * @param string $billType 账单类型
     * @param string $billDate 账单日期
     * @param array $paymentInfo 支付配置信息
     * @return array 账单信息
     * @throws Exception
     */
    public function queryBill(string $billType, string $billDate, array $paymentInfo): array
    {
        return AlipayQueryService::queryBill($billType, $billDate, $paymentInfo);
    }
    
    /**
     * 关闭订单
     * @param string $orderNumber 商户订单号
     * @param array $paymentInfo 支付配置信息
     * @return bool 是否关闭成功
     * @throws Exception
     */
    public function closeOrder(string $orderNumber, array $paymentInfo): bool
    {
        return AlipayQueryService::closeOrder($orderNumber, $paymentInfo);
    }
    
    /**
     * 创建退款
     * @param array $refundInfo 退款信息
     * @param array $paymentInfo 支付配置信息
     * @return array 退款结果
     * @throws Exception
     */
    public function createRefund(array $refundInfo, array $paymentInfo): array
    {
        return AlipayRefundService::createRefund($refundInfo, $paymentInfo);
    }
    
    /**
     * 批量退款
     * @param array $refundList 退款列表
     * @param array $paymentInfo 支付配置信息
     * @return array 批量退款结果
     * @throws Exception
     */
    public function batchRefund(array $refundList, array $paymentInfo): array
    {
        return AlipayRefundService::batchRefund($refundList, $paymentInfo);
    }
    
    /**
     * 撤销订单
     * @param string $orderNumber 商户订单号
     * @param array $paymentInfo 支付配置信息
     * @return array 撤销结果
     * @throws Exception
     */
    public function cancelOrder(string $orderNumber, array $paymentInfo): array
    {
        return AlipayRefundService::cancelOrder($orderNumber, $paymentInfo);
    }
    
    /**
     * 预授权完成
     * @param array $authInfo 预授权信息
     * @param array $paymentInfo 支付配置信息
     * @return array 预授权完成结果
     * @throws Exception
     */
    public function completePreAuth(array $authInfo, array $paymentInfo): array
    {
        return AlipayRefundService::completePreAuth($authInfo, $paymentInfo);
    }
    
    /**
     * 预授权撤销
     * @param array $authInfo 预授权信息
     * @param array $paymentInfo 支付配置信息
     * @return array 预授权撤销结果
     * @throws Exception
     */
    public function cancelPreAuth(array $authInfo, array $paymentInfo): array
    {
        return AlipayRefundService::cancelPreAuth($authInfo, $paymentInfo);
    }
    
    /**
     * 获取OAuth授权URL
     * @param array $params 授权参数
     * @param array $paymentInfo 支付配置信息
     * @return string 授权URL
     * @throws Exception
     */
    public function getAuthUrl(array $params, array $paymentInfo): string
    {
        return AlipayOAuthService::getAuthUrl($params, $paymentInfo);
    }
    
    /**
     * 通过授权码获取访问令牌
     * @param string $authCode 授权码
     * @param array $paymentInfo 支付配置信息
     * @return array 用户信息
     * @throws Exception
     */
    public function getTokenByAuthCode(string $authCode, array $paymentInfo): array
    {
        return AlipayOAuthService::getTokenByAuthCode($authCode, $paymentInfo);
    }
    
    /**
     * 刷新访问令牌
     * @param string $refreshToken 刷新令牌
     * @param array $paymentInfo 支付配置信息
     * @return array 新的令牌信息
     * @throws Exception
     */
    public function refreshToken(string $refreshToken, array $paymentInfo): array
    {
        return AlipayOAuthService::refreshToken($refreshToken, $paymentInfo);
    }
    
    /**
     * 获取用户信息
     * @param string $accessToken 访问令牌
     * @param array $paymentInfo 支付配置信息
     * @return array 用户信息
     * @throws Exception
     */
    public function getUserInfo(string $accessToken, array $paymentInfo): array
    {
        return AlipayOAuthService::getUserInfo($accessToken, $paymentInfo);
    }
    
    /**
     * 检查购买限制
     * @param string $userId 用户ID
     * @param int $maxNumber 最大购买次数
     * @param string $entityId 实体ID
     * @param string $orderNumber 订单号
     * @return bool 是否允许购买
     * @throws Exception
     */
    public function checkBuyLimit(string $userId, int $maxNumber = 5, string $entityId = '', string $orderNumber = ''): bool
    {
        return AlipayOAuthService::checkBuyLimit($userId, $maxNumber, $entityId, $orderNumber);
    }
    
    /**
     * 验证配置
     * @param array $paymentInfo 支付配置信息
     * @return bool 配置是否有效
     * @throws Exception
     */
    public function validateConfig(array $paymentInfo): bool
    {
        try {
            AlipayConfig::getConfig($paymentInfo);
            return true;
        } catch (Exception $e) {
            Log::error("支付宝配置验证失败", [
                'error' => $e->getMessage(),
                'appid' => $paymentInfo['appid'] ?? ''
            ]);
            throw $e;
        }
    }
}

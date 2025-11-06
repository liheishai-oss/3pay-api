<?php

namespace app\service\alipay;

use Alipay\EasySDK\Kernel\Factory;
use Exception;
use support\Log;

/**
 * 支付宝退款服务类
 */
class AlipayRefundService
{
    /**
     * 创建退款
     * @param array $refundInfo 退款信息
     * @param array $paymentInfo 支付配置信息
     * @return array 退款结果
     * @throws Exception
     */
    public static function createRefund(array $refundInfo, array $paymentInfo): array
    {
        try {
            $config = AlipayConfig::getConfig($paymentInfo);
            
            $result = Factory::setOptions($config)
                ->payment()
                ->common()
                ->refund(
                    $refundInfo['order_number'],
                    $refundInfo['refund_amount'],
                    $refundInfo['refund_number'],
                    $refundInfo['refund_reason'] ?? ''
                );
            
            $response = json_decode($result->body, true);
            
            if (!isset($response['alipay_trade_refund_response'])) {
                throw new Exception("退款响应格式错误");
            }
            
            $refundResponse = $response['alipay_trade_refund_response'];
            
            if ($refundResponse['code'] !== '10000') {
                throw new Exception("退款失败: " . ($refundResponse['msg'] ?? '未知错误'));
            }
            
            Log::info("支付宝退款创建成功", [
                'order_number' => $refundInfo['order_number'],
                'refund_number' => $refundInfo['refund_number'],
                'refund_amount' => $refundInfo['refund_amount']
            ]);
            
            return [
                'order_number' => $refundResponse['out_trade_no'] ?? '',
                'trade_no' => $refundResponse['trade_no'] ?? '',
                'refund_number' => $refundResponse['out_request_no'] ?? '',
                'refund_amount' => $refundResponse['refund_fee'] ?? '0',
                'refund_status' => $refundResponse['refund_status'] ?? '',
                'refund_reason' => $refundResponse['refund_reason'] ?? '',
                'gmt_refund_pay' => $refundResponse['gmt_refund_pay'] ?? '',
                'buyer_id' => $refundResponse['buyer_id'] ?? '',
                'buyer_logon_id' => $refundResponse['buyer_logon_id'] ?? '',
                'seller_id' => $refundResponse['seller_id'] ?? '',
                'seller_email' => $refundResponse['seller_email'] ?? '',
            ];
            
        } catch (Exception $e) {
            Log::error("支付宝退款创建失败", [
                'order_number' => $refundInfo['order_number'],
                'refund_number' => $refundInfo['refund_number'],
                'error' => $e->getMessage()
            ]);
            throw new Exception("退款创建失败: " . $e->getMessage());
        }
    }
    
    /**
     * 批量退款
     * @param array $refundList 退款列表
     * @param array $paymentInfo 支付配置信息
     * @return array 批量退款结果
     * @throws Exception
     */
    public static function batchRefund(array $refundList, array $paymentInfo): array
    {
        try {
            $config = AlipayConfig::getConfig($paymentInfo);
            
            $result = Factory::setOptions($config)
                ->payment()
                ->common()
                ->batchRefund($refundList);
            
            $response = json_decode($result->body, true);
            
            if (!isset($response['alipay_trade_batch_refund_response'])) {
                throw new Exception("批量退款响应格式错误");
            }
            
            $batchRefundResponse = $response['alipay_trade_batch_refund_response'];
            
            if ($batchRefundResponse['code'] !== '10000') {
                throw new Exception("批量退款失败: " . ($batchRefundResponse['msg'] ?? '未知错误'));
            }
            
            Log::info("支付宝批量退款创建成功", [
                'refund_count' => count($refundList)
            ]);
            
            return [
                'batch_no' => $batchRefundResponse['batch_no'] ?? '',
                'refund_status' => $batchRefundResponse['refund_status'] ?? '',
                'refund_details' => $batchRefundResponse['refund_details'] ?? [],
            ];
            
        } catch (Exception $e) {
            Log::error("支付宝批量退款创建失败", [
                'refund_count' => count($refundList),
                'error' => $e->getMessage()
            ]);
            throw new Exception("批量退款创建失败: " . $e->getMessage());
        }
    }
    
    /**
     * 撤销订单
     * @param string $orderNumber 商户订单号
     * @param array $paymentInfo 支付配置信息
     * @return array 撤销结果
     * @throws Exception
     */
    public static function cancelOrder(string $orderNumber, array $paymentInfo): array
    {
        try {
            $config = AlipayConfig::getConfig($paymentInfo);
            
            $result = Factory::setOptions($config)
                ->payment()
                ->common()
                ->cancel($orderNumber);
            
            $response = json_decode($result->body, true);
            
            if (!isset($response['alipay_trade_cancel_response'])) {
                throw new Exception("撤销订单响应格式错误");
            }
            
            $cancelResponse = $response['alipay_trade_cancel_response'];
            
            if ($cancelResponse['code'] !== '10000') {
                throw new Exception("撤销订单失败: " . ($cancelResponse['msg'] ?? '未知错误'));
            }
            
            Log::info("支付宝订单撤销成功", [
                'order_number' => $orderNumber
            ]);
            
            return [
                'order_number' => $cancelResponse['out_trade_no'] ?? '',
                'trade_no' => $cancelResponse['trade_no'] ?? '',
                'action' => $cancelResponse['action'] ?? '',
                'retry_flag' => $cancelResponse['retry_flag'] ?? '',
            ];
            
        } catch (Exception $e) {
            Log::error("支付宝订单撤销失败", [
                'order_number' => $orderNumber,
                'error' => $e->getMessage()
            ]);
            throw new Exception("订单撤销失败: " . $e->getMessage());
        }
    }
    
    /**
     * 预授权完成
     * @param array $authInfo 预授权信息
     * @param array $paymentInfo 支付配置信息
     * @return array 预授权完成结果
     * @throws Exception
     */
    public static function completePreAuth(array $authInfo, array $paymentInfo): array
    {
        try {
            $config = AlipayConfig::getConfig($paymentInfo);
            
            $result = Factory::setOptions($config)
                ->payment()
                ->common()
                ->preAuthComplete(
                    $authInfo['order_number'],
                    $authInfo['auth_no'],
                    $authInfo['amount']
                );
            
            $response = json_decode($result->body, true);
            
            if (!isset($response['alipay_trade_preauth_complete_response'])) {
                throw new Exception("预授权完成响应格式错误");
            }
            
            $authResponse = $response['alipay_trade_preauth_complete_response'];
            
            if ($authResponse['code'] !== '10000') {
                throw new Exception("预授权完成失败: " . ($authResponse['msg'] ?? '未知错误'));
            }
            
            Log::info("支付宝预授权完成成功", [
                'order_number' => $authInfo['order_number'],
                'auth_no' => $authInfo['auth_no'],
                'amount' => $authInfo['amount']
            ]);
            
            return [
                'order_number' => $authResponse['out_trade_no'] ?? '',
                'trade_no' => $authResponse['trade_no'] ?? '',
                'auth_no' => $authResponse['auth_no'] ?? '',
                'amount' => $authResponse['total_amount'] ?? '0',
                'gmt_payment' => $authResponse['gmt_payment'] ?? '',
            ];
            
        } catch (Exception $e) {
            Log::error("支付宝预授权完成失败", [
                'order_number' => $authInfo['order_number'],
                'auth_no' => $authInfo['auth_no'],
                'error' => $e->getMessage()
            ]);
            throw new Exception("预授权完成失败: " . $e->getMessage());
        }
    }
    
    /**
     * 预授权撤销
     * @param array $authInfo 预授权信息
     * @param array $paymentInfo 支付配置信息
     * @return array 预授权撤销结果
     * @throws Exception
     */
    public static function cancelPreAuth(array $authInfo, array $paymentInfo): array
    {
        try {
            $config = AlipayConfig::getConfig($paymentInfo);
            
            $result = Factory::setOptions($config)
                ->payment()
                ->common()
                ->preAuthCancel(
                    $authInfo['order_number'],
                    $authInfo['auth_no']
                );
            
            $response = json_decode($result->body, true);
            
            if (!isset($response['alipay_trade_preauth_cancel_response'])) {
                throw new Exception("预授权撤销响应格式错误");
            }
            
            $authResponse = $response['alipay_trade_preauth_cancel_response'];
            
            if ($authResponse['code'] !== '10000') {
                throw new Exception("预授权撤销失败: " . ($authResponse['msg'] ?? '未知错误'));
            }
            
            Log::info("支付宝预授权撤销成功", [
                'order_number' => $authInfo['order_number'],
                'auth_no' => $authInfo['auth_no']
            ]);
            
            return [
                'order_number' => $authResponse['out_trade_no'] ?? '',
                'trade_no' => $authResponse['trade_no'] ?? '',
                'auth_no' => $authResponse['auth_no'] ?? '',
                'action' => $authResponse['action'] ?? '',
            ];
            
        } catch (Exception $e) {
            Log::error("支付宝预授权撤销失败", [
                'order_number' => $authInfo['order_number'],
                'auth_no' => $authInfo['auth_no'],
                'error' => $e->getMessage()
            ]);
            throw new Exception("预授权撤销失败: " . $e->getMessage());
        }
    }
}

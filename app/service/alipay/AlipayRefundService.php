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
            
            // 支付宝退款接口参数说明：
            // - out_trade_no: 商户订单号（必填，创建订单时使用的订单号）
            // - refund_amount: 退款金额（必填）
            // - out_request_no: 退款请求号（可选，用于标识退款请求，如果不传，支付宝会自动生成）
            // - refund_reason: 退款原因（可选）
            
            // 注意：支付宝EasySDK的 refund 方法只支持 out_trade_no 和 refund_amount 两个参数
            // 如果需要传递 out_request_no 和 refund_reason，需要使用通用API
            
            $outTradeNo = $refundInfo['order_number'] ?? ''; // 商户订单号（out_trade_no）
            $refundAmount = $refundInfo['refund_amount'] ?? '0'; // 退款金额
            $outRequestNo = $refundInfo['refund_number'] ?? ''; // 退款请求号（out_request_no，可选）
            $refundReason = $refundInfo['refund_reason'] ?? ''; // 退款原因（可选）
            
            if (empty($outTradeNo)) {
                throw new Exception("订单号不能为空");
            }
            
            if (empty($refundAmount) || floatval($refundAmount) <= 0) {
                throw new Exception("退款金额必须大于0");
            }
            
            Log::info("支付宝退款：准备调用退款接口", [
                'out_trade_no' => $outTradeNo,
                'refund_amount' => $refundAmount,
                'out_request_no' => $outRequestNo,
                'refund_reason' => $refundReason,
                'note' => '使用通用API调用 alipay.trade.refund，支持传递 out_request_no 和 refund_reason'
            ]);
            
            // 使用通用API调用退款接口，支持传递 out_request_no 和 refund_reason
            $textParams = []; // 文本参数（可选参数，如app_auth_token等）
            $bizParams = [
                'out_trade_no' => $outTradeNo,
                'refund_amount' => $refundAmount
            ];
            
            // 如果提供了退款请求号，添加到参数中
            if (!empty($outRequestNo)) {
                $bizParams['out_request_no'] = $outRequestNo;
            }
            
            // 如果提供了退款原因，添加到参数中
            if (!empty($refundReason)) {
                $bizParams['refund_reason'] = $refundReason;
            }
            
            $result = Factory::setOptions($config)
                ->util()
                ->generic()
                ->execute('alipay.trade.refund', $textParams, $bizParams);
            
            // 检查响应状态
            if ($result->code !== '10000') {
                $errorMsg = $result->msg ?? '未知错误';
                $subMsg = $result->subCode ?? '';
                $subMsgDetail = $result->subMsg ?? '';
                throw new Exception("退款失败: {$errorMsg}" . ($subMsg ? " (sub_code: {$subMsg})" : "") . ($subMsgDetail ? " - {$subMsgDetail}" : ""));
            }
            
            // 解析响应体
            if (empty($result->httpBody)) {
                throw new Exception('支付宝退款响应为空');
            }
            
            $response = json_decode($result->httpBody, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('支付宝退款响应解析失败: ' . json_last_error_msg());
            }
            
            if (!isset($response['alipay_trade_refund_response'])) {
                throw new Exception('支付宝退款响应格式错误');
            }
            
            $refundResponse = $response['alipay_trade_refund_response'];
            
            // 检查响应状态
            if (isset($refundResponse['code']) && $refundResponse['code'] !== '10000') {
                $errorMsg = $refundResponse['msg'] ?? '未知错误';
                $subMsg = $refundResponse['sub_code'] ?? '';
                $subMsgDetail = $refundResponse['sub_msg'] ?? '';
                throw new Exception("退款失败: {$errorMsg}" . ($subMsg ? " (sub_code: {$subMsg})" : "") . ($subMsgDetail ? " - {$subMsgDetail}" : ""));
            }
            
            Log::info("支付宝退款创建成功", [
                'out_trade_no' => $outTradeNo,
                'refund_amount' => $refundAmount,
                'out_request_no' => $outRequestNo,
                'refund_response' => $refundResponse
            ]);
            
            return [
                'order_number' => $refundResponse['out_trade_no'] ?? $outTradeNo,
                'trade_no' => $refundResponse['trade_no'] ?? '',
                'refund_number' => $refundResponse['out_request_no'] ?? $outRequestNo,
                'refund_amount' => $refundResponse['refund_fee'] ?? $refundAmount,
                'refund_status' => $refundResponse['refund_status'] ?? '',
                'refund_reason' => $refundResponse['refund_reason'] ?? $refundReason,
                'gmt_refund_pay' => $refundResponse['gmt_refund_pay'] ?? '',
                'buyer_id' => $refundResponse['buyer_id'] ?? '',
                'buyer_logon_id' => $refundResponse['buyer_logon_id'] ?? '',
                'seller_id' => $refundResponse['seller_id'] ?? '',
                'seller_email' => $refundResponse['seller_email'] ?? '',
            ];
            
        } catch (Exception $e) {
            Log::error("支付宝退款创建失败", [
                'order_number' => $refundInfo['order_number'] ?? '',
                'refund_number' => $refundInfo['refund_number'] ?? '',
                'refund_amount' => $refundInfo['refund_amount'] ?? '',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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

<?php

namespace app\service\alipay;

use Alipay\EasySDK\Kernel\Factory;
use Exception;
use support\Log;

/**
 * 支付宝查询服务类
 */
class AlipayQueryService
{
    /**
     * 查询订单状态
     * @param string $orderNumber 订单号（必须是商户订单号out_trade_no，即创建订单时传递给支付宝的订单号）
     * @param array $paymentInfo 支付配置信息
     * @param bool $useTradeNo 是否使用支付宝交易号查询（已废弃，支付宝EasySDK的query方法只支持out_trade_no）
     * @return array 订单信息
     * @throws Exception
     */
    public static function queryOrder(string $orderNumber, array $paymentInfo, bool $useTradeNo = false): array
    {
        try {
            $config = AlipayConfig::getConfig($paymentInfo);
            
            // 记录查询参数，用于调试
            Log::info('支付宝订单查询：开始查询', [
                'order_number' => $orderNumber,
                'use_trade_no' => $useTradeNo,
                'note' => '支付宝EasySDK的query方法只支持out_trade_no（商户订单号），不支持trade_no',
                'app_id' => $config->appId ?? 'NULL'
            ]);
            
            // 注意：支付宝EasySDK的query方法只支持out_trade_no（商户订单号），不支持trade_no
            // 创建订单时，我们使用platform_order_no作为out_trade_no传递给支付宝
            // 因此查询时也必须使用相同的platform_order_no作为out_trade_no查询
            $result = Factory::setOptions($config)
                ->payment()
                ->common()
                ->query($orderNumber);
            
            // 检查响应body是否存在
            $bodyContent = null;
            if (property_exists($result, 'body') && $result->body !== null) {
                $bodyContent = $result->body;
            } elseif (property_exists($result, 'httpBody') && $result->httpBody !== null) {
                $bodyContent = $result->httpBody;
            } else {
                Log::error("支付宝查询响应body为空", [
                    'order_number' => $orderNumber,
                    'result_class' => get_class($result),
                    'result_properties' => array_keys(get_object_vars($result))
                ]);
                throw new Exception("支付宝查询响应为空，请检查网络连接或配置");
            }
            
            // 检查body是否为空字符串
            if (empty($bodyContent)) {
                Log::error("支付宝查询响应body为空字符串", [
                    'order_number' => $orderNumber
                ]);
                throw new Exception("支付宝查询响应为空，请检查订单号是否正确");
            }
            
            $response = json_decode($bodyContent, true);
            
            // 检查JSON解析是否成功
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("支付宝查询响应JSON解析失败", [
                    'order_number' => $orderNumber,
                    'json_error' => json_last_error_msg(),
                    'body_preview' => substr($bodyContent, 0, 500)
                ]);
                throw new Exception("支付宝查询响应解析失败: " . json_last_error_msg());
            }
            
            if (!isset($response['alipay_trade_query_response'])) {
                Log::error("支付宝查询响应格式错误", [
                    'order_number' => $orderNumber,
                    'response_keys' => array_keys($response ?? []),
                    'body_preview' => substr($bodyContent, 0, 500)
                ]);
                throw new Exception("查询订单响应格式错误");
            }
            
            $queryResponse = $response['alipay_trade_query_response'];
            
            if ($queryResponse['code'] !== '10000') {
                $errorCode = $queryResponse['code'] ?? '';
                $errorMsg = $queryResponse['msg'] ?? '未知错误';
                $subCode = $queryResponse['sub_code'] ?? '';
                $subMsg = $queryResponse['sub_msg'] ?? '';
                
                Log::error("支付宝订单查询返回错误", [
                    'order_number' => $orderNumber,
                    'code' => $errorCode,
                    'msg' => $errorMsg,
                    'sub_code' => $subCode,
                    'sub_msg' => $subMsg,
                    'app_id' => $config->appId ?? 'NULL',
                    'full_response' => $queryResponse,
                    'note' => '如果返回"交易不存在"，请检查：1) 订单号是否正确（创建时使用的out_trade_no）；2) app_id是否匹配（订单创建时使用的app_id）；3) 订单是否已过期被删除'
                ]);
                
                // 特殊处理交易不存在错误
                if ($subCode === 'ACQ.TRADE_NOT_EXIST' || 
                    strpos($subMsg, '交易不存在') !== false || 
                    strpos($subMsg, 'TRADE_NOT_EXIST') !== false ||
                    strpos($errorMsg, '交易不存在') !== false ||
                    strpos($errorMsg, 'TRADE_NOT_EXIST') !== false ||
                    strpos($errorMsg, 'Business Failed') !== false ||
                    ($errorCode === '40004' && strpos($subMsg, '交易不存在') !== false)) {
                    throw new Exception("查询订单失败: 交易不存在。可能原因：1) 订单在支付宝中不存在或已过期被删除；2) 订单属于其他应用无法查询；3) 订单号不正确；4) 订单创建失败。错误详情: {$errorMsg}" . ($subMsg ? " - {$subMsg}" : "") . " (错误码: {$errorCode}" . ($subCode ? ", 子错误码: {$subCode}" : "") . ")");
                }
                
                // 特殊处理权限不足错误
                if ($errorCode === '40004' || 
                    $subCode === 'isv.invalid-app-id' ||
                    strpos($errorMsg, 'Insufficient Permissions') !== false || 
                    strpos($subMsg, 'Insufficient Permissions') !== false ||
                    strpos($errorMsg, '权限不足') !== false ||
                    strpos($subMsg, '权限不足') !== false) {
                    throw new Exception("查询订单失败: 权限不足。可能原因：1) 支付宝应用未开通订单查询权限；2) 订单属于其他应用无法查询；3) 需要使用支付宝交易号(trade_no)查询而非商户订单号。错误详情: {$errorMsg}" . ($subMsg ? " - {$subMsg}" : "") . " (错误码: {$errorCode}" . ($subCode ? ", 子错误码: {$subCode}" : "") . ")");
                }
                
                throw new Exception("查询订单失败: {$errorMsg}" . ($subMsg ? " - {$subMsg}" : "") . " (错误码: {$errorCode}" . ($subCode ? ", 子错误码: {$subCode}" : "") . ")");
            }
            
            // 记录查询成功的详细信息，特别是支付时间相关字段
            Log::info("支付宝订单查询成功", [
                'order_number' => $orderNumber,
                'trade_status' => $queryResponse['trade_status'] ?? '',
                'gmt_payment' => $queryResponse['gmt_payment'] ?? 'NULL',
                'gmt_create' => $queryResponse['gmt_create'] ?? 'NULL',
                'send_pay_date' => $queryResponse['send_pay_date'] ?? 'NULL',
                'all_response_keys' => array_keys($queryResponse),
                'response_preview' => json_encode($queryResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]);
            
            // 优先使用 gmt_payment，如果没有则使用 send_pay_date，最后使用 gmt_create
            $paymentTime = '';
            if (!empty($queryResponse['gmt_payment'])) {
                $paymentTime = $queryResponse['gmt_payment'];
            } elseif (!empty($queryResponse['send_pay_date'])) {
                $paymentTime = $queryResponse['send_pay_date'];
            } elseif (!empty($queryResponse['gmt_create']) && 
                      in_array($queryResponse['trade_status'] ?? '', ['TRADE_SUCCESS', 'TRADE_FINISHED'])) {
                // 如果订单已支付但没有支付时间，使用创建时间（不推荐，但比没有好）
                $paymentTime = $queryResponse['gmt_create'];
                Log::warning("支付宝订单查询：使用gmt_create作为支付时间", [
                    'order_number' => $orderNumber,
                    'trade_status' => $queryResponse['trade_status'] ?? ''
                ]);
            }
            
            // 支付宝返回的buyer_id字段可能是buyer_user_id
            $buyerId = $queryResponse['buyer_id'] ?? $queryResponse['buyer_user_id'] ?? '';
            
            return [
                'order_number' => $queryResponse['out_trade_no'] ?? '',
                'trade_no' => $queryResponse['trade_no'] ?? '',
                'trade_status' => $queryResponse['trade_status'] ?? '',
                'total_amount' => $queryResponse['total_amount'] ?? '0',
                'receipt_amount' => $queryResponse['receipt_amount'] ?? '0',
                'buyer_id' => $buyerId,  // 优先使用buyer_id，如果没有则使用buyer_user_id
                'buyer_logon_id' => $queryResponse['buyer_logon_id'] ?? '',
                'seller_id' => $queryResponse['seller_id'] ?? '',
                'seller_email' => $queryResponse['seller_email'] ?? '',
                'gmt_payment' => $paymentTime,  // 使用处理后的支付时间
                'gmt_create' => $queryResponse['gmt_create'] ?? '',
                'send_pay_date' => $queryResponse['send_pay_date'] ?? '',
                'subject' => $queryResponse['subject'] ?? '',
                'body' => $queryResponse['body'] ?? '',
                'fund_bill_list' => $queryResponse['fund_bill_list'] ?? '',
                'voucher_detail_list' => $queryResponse['voucher_detail_list'] ?? '',
            ];
            
        } catch (Exception $e) {
            Log::error("支付宝订单查询失败", [
                'order_number' => $orderNumber,
                'error' => $e->getMessage()
            ]);
            throw new Exception("订单查询失败: " . $e->getMessage());
        }
    }
    
    /**
     * 查询退款状态
     * @param string $orderNumber 商户订单号
     * @param string $refundNumber 退款单号
     * @param array $paymentInfo 支付配置信息
     * @return array 退款信息
     * @throws Exception
     */
    public static function queryRefund(string $orderNumber, string $refundNumber, array $paymentInfo): array
    {
        try {
            $config = AlipayConfig::getConfig($paymentInfo);
            
            $result = Factory::setOptions($config)
                ->payment()
                ->common()
                ->queryRefund($orderNumber, $refundNumber);
            
            // 检查响应body是否存在
            $bodyContent = null;
            if (property_exists($result, 'body') && $result->body !== null) {
                $bodyContent = $result->body;
            } elseif (property_exists($result, 'httpBody') && $result->httpBody !== null) {
                $bodyContent = $result->httpBody;
            } else {
                Log::error("支付宝退款查询响应body为空", [
                    'order_number' => $orderNumber,
                    'refund_number' => $refundNumber
                ]);
                throw new Exception("支付宝退款查询响应为空");
            }
            
            if (empty($bodyContent)) {
                throw new Exception("支付宝退款查询响应为空");
            }
            
            $response = json_decode($bodyContent, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("支付宝退款查询响应JSON解析失败", [
                    'order_number' => $orderNumber,
                    'json_error' => json_last_error_msg()
                ]);
                throw new Exception("支付宝退款查询响应解析失败: " . json_last_error_msg());
            }
            
            if (!isset($response['alipay_trade_fastpay_refund_query_response'])) {
                throw new Exception("查询退款响应格式错误");
            }
            
            $refundResponse = $response['alipay_trade_fastpay_refund_query_response'];
            
            if ($refundResponse['code'] !== '10000') {
                throw new Exception("查询退款失败: " . ($refundResponse['msg'] ?? '未知错误'));
            }
            
            Log::info("支付宝退款查询成功", [
                'order_number' => $orderNumber,
                'refund_number' => $refundNumber,
                'refund_status' => $refundResponse['refund_status'] ?? ''
            ]);
            
            return [
                'order_number' => $refundResponse['out_trade_no'] ?? '',
                'trade_no' => $refundResponse['trade_no'] ?? '',
                'refund_number' => $refundResponse['out_request_no'] ?? '',
                'refund_amount' => $refundResponse['refund_amount'] ?? '0',
                'refund_status' => $refundResponse['refund_status'] ?? '',
                'refund_reason' => $refundResponse['refund_reason'] ?? '',
                'gmt_refund_pay' => $refundResponse['gmt_refund_pay'] ?? '',
            ];
            
        } catch (Exception $e) {
            Log::error("支付宝退款查询失败", [
                'order_number' => $orderNumber,
                'refund_number' => $refundNumber,
                'error' => $e->getMessage()
            ]);
            throw new Exception("退款查询失败: " . $e->getMessage());
        }
    }
    
    /**
     * 查询账单
     * @param string $billType 账单类型
     * @param string $billDate 账单日期
     * @param array $paymentInfo 支付配置信息
     * @return array 账单信息
     * @throws Exception
     */
    public static function queryBill(string $billType, string $billDate, array $paymentInfo): array
    {
        try {
            $config = AlipayConfig::getConfig($paymentInfo);
            
            $result = Factory::setOptions($config)
                ->util()
                ->generic()
                ->execute('alipay.data.dataservice.bill.downloadurl.query', [
                    'bill_type' => $billType,
                    'bill_date' => $billDate
                ]);
            
            // 检查响应body是否存在
            $bodyContent = null;
            if (property_exists($result, 'body') && $result->body !== null) {
                $bodyContent = $result->body;
            } elseif (property_exists($result, 'httpBody') && $result->httpBody !== null) {
                $bodyContent = $result->httpBody;
            } else {
                throw new Exception("支付宝账单查询响应为空");
            }
            
            if (empty($bodyContent)) {
                throw new Exception("支付宝账单查询响应为空");
            }
            
            $response = json_decode($bodyContent, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("支付宝账单查询响应解析失败: " . json_last_error_msg());
            }
            
            if (!isset($response['alipay_data_dataservice_bill_downloadurl_query_response'])) {
                throw new Exception("查询账单响应格式错误");
            }
            
            $billResponse = $response['alipay_data_dataservice_bill_downloadurl_query_response'];
            
            if ($billResponse['code'] !== '10000') {
                throw new Exception("查询账单失败: " . ($billResponse['msg'] ?? '未知错误'));
            }
            
            Log::info("支付宝账单查询成功", [
                'bill_type' => $billType,
                'bill_date' => $billDate,
                'bill_download_url' => $billResponse['bill_download_url'] ?? ''
            ]);
            
            return [
                'bill_type' => $billType,
                'bill_date' => $billDate,
                'bill_download_url' => $billResponse['bill_download_url'] ?? '',
                'bill_download_url_expire_time' => $billResponse['bill_download_url_expire_time'] ?? '',
            ];
            
        } catch (Exception $e) {
            Log::error("支付宝账单查询失败", [
                'bill_type' => $billType,
                'bill_date' => $billDate,
                'error' => $e->getMessage()
            ]);
            throw new Exception("账单查询失败: " . $e->getMessage());
        }
    }
    
    /**
     * 查询交易关闭订单
     * @param string $orderNumber 商户订单号
     * @param array $paymentInfo 支付配置信息
     * @return bool 是否关闭成功
     * @throws Exception
     */
    public static function closeOrder(string $orderNumber, array $paymentInfo): bool
    {
        try {
            $config = AlipayConfig::getConfig($paymentInfo);
            
            $result = Factory::setOptions($config)
                ->payment()
                ->common()
                ->close($orderNumber);
            
            // 检查响应body是否存在
            $bodyContent = null;
            if (property_exists($result, 'body') && $result->body !== null) {
                $bodyContent = $result->body;
            } elseif (property_exists($result, 'httpBody') && $result->httpBody !== null) {
                $bodyContent = $result->httpBody;
            } else {
                throw new Exception("支付宝关闭订单响应为空");
            }
            
            if (empty($bodyContent)) {
                throw new Exception("支付宝关闭订单响应为空");
            }
            
            $response = json_decode($bodyContent, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("支付宝关闭订单响应解析失败: " . json_last_error_msg());
            }
            
            if (!isset($response['alipay_trade_close_response'])) {
                throw new Exception("关闭订单响应格式错误");
            }
            
            $closeResponse = $response['alipay_trade_close_response'];
            
            if ($closeResponse['code'] !== '10000') {
                throw new Exception("关闭订单失败: " . ($closeResponse['msg'] ?? '未知错误'));
            }
            
            Log::info("支付宝订单关闭成功", [
                'order_number' => $orderNumber
            ]);
            
            return true;
            
        } catch (Exception $e) {
            Log::error("支付宝订单关闭失败", [
                'order_number' => $orderNumber,
                'error' => $e->getMessage()
            ]);
            throw new Exception("订单关闭失败: " . $e->getMessage());
        }
    }
}

<?php

namespace app\service\alipay;

use Alipay\EasySDK\Kernel\Factory;
use Exception;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use support\Log;
use Throwable;

/**
 * 支付宝分账服务类
 * 
 * 注意：需要先确认支付宝分账接口的具体API名称和参数
 * 常见的支付宝分账接口：
 * 1. alipay.trade.order.settle - 统一收单交易结算
 * 2. alipay.trade.royalty.relation.bind - 分账关系绑定
 * 3. alipay.fund.trans.uni.transfer - 单笔转账到支付宝账户
 */
class AlipayRoyaltyService
{
    /**
     * 单笔分账接口
     * 
     * @param array $orderInfo 订单信息
     *   - trade_no: 支付宝交易号
     *   - platform_order_no: 平台订单号
     *   - order_amount: 订单金额
     * @param array $royaltyInfo 分账信息
     *   - royalty_amount: 分账金额
     *   - payee_user_id: 收款人支付宝用户ID
     *   - payee_name: 收款人姓名
     * @param array $paymentConfig 支付配置
     * @return array ['success' => bool, 'message' => string, 'data' => array]
     */
    public static function royalty(array $orderInfo, array $royaltyInfo, array $paymentConfig): array
    {
        try {
            $config = AlipayConfig::getConfig($paymentConfig);
            
            Log::info('开始调用支付宝分账接口', [
                'order_info' => $orderInfo,
                'royalty_info' => $royaltyInfo
            ]);
            
            // 使用统一收单交易结算接口（alipay.trade.order.settle）
            // 该接口用于分账，将订单金额分给指定收款人
            $settleParams = [
                'out_request_no' => $orderInfo['platform_order_no'] . '_' . time(), // 分账请求号（唯一）
                'trade_no' => $orderInfo['trade_no'], // 支付宝交易号
                'royalty_parameters' => [
                    [
                        'royalty_type' => 'transfer', // 分账类型：transfer表示分账
                        'trans_out' => '', // 转出方账户（空表示当前主体账户）
                        'trans_out_type' => 'userId', // 转出方账户类型
                        'trans_in_type' => 'userId', // 转入方账户类型
                        'trans_in' => $royaltyInfo['payee_user_id'], // 转入方支付宝用户ID
                        'amount' => number_format($royaltyInfo['royalty_amount'], 2, '.', ''), // 分账金额
                        'desc' => '订单分账-' . ($royaltyInfo['payee_name'] ?? '收款人'),
                    ]
                ],
                'operator_id' => '', // 操作员ID（可选）
            ];
            
            // 使用通用接口调用
            $result = Factory::setOptions($config)
                ->util()
                ->generic()
                ->execute('alipay.trade.order.settle', $settleParams);
            
            // 检查响应body是否存在
            $bodyContent = null;
            if (property_exists($result, 'body') && $result->body !== null) {
                $bodyContent = $result->body;
            } elseif (property_exists($result, 'httpBody') && $result->httpBody !== null) {
                $bodyContent = $result->httpBody;
            } else {
                Log::error("支付宝分账响应body为空", [
                    'order_info' => $orderInfo,
                    'result_class' => get_class($result),
                ]);
                return self::buildFailureResult(
                    "支付宝分账响应为空",
                    ['code' => 'EMPTY_BODY'],
                    true
                );
            }
            
            if (empty($bodyContent)) {
                return self::buildFailureResult("支付宝分账响应为空", ['code' => 'EMPTY_BODY'], true);
            }
            
            $response = json_decode($bodyContent, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("支付宝分账响应JSON解析失败", [
                    'order_info' => $orderInfo,
                    'json_error' => json_last_error_msg(),
                    'body_preview' => substr($bodyContent, 0, 500)
                ]);
                return self::buildFailureResult(
                    "支付宝分账响应解析失败: " . json_last_error_msg(),
                    ['code' => 'JSON_PARSE_ERROR', 'body_preview' => substr($bodyContent, 0, 200)],
                    true
                );
            }
            
            // 检查响应格式
            if (!isset($response['alipay_trade_order_settle_response'])) {
                Log::error("支付宝分账响应格式错误", [
                    'order_info' => $orderInfo,
                    'response_keys' => array_keys($response ?? []),
                    'body_preview' => substr($bodyContent, 0, 500)
                ]);
                return self::buildFailureResult(
                    "分账响应格式错误",
                    ['code' => 'INVALID_FORMAT', 'body_preview' => substr($bodyContent, 0, 200)],
                    true
                );
            }
            
            $settleResponse = $response['alipay_trade_order_settle_response'];
            
            if ($settleResponse['code'] !== '10000') {
                $errorCode = $settleResponse['code'] ?? '';
                $errorMsg = $settleResponse['msg'] ?? '未知错误';
                $subCode = $settleResponse['sub_code'] ?? '';
                $subMsg = $settleResponse['sub_msg'] ?? '';
                
                Log::error("支付宝分账返回错误", [
                    'order_info' => $orderInfo,
                    'code' => $errorCode,
                    'msg' => $errorMsg,
                    'sub_code' => $subCode,
                    'sub_msg' => $subMsg,
                    'full_response' => $settleResponse
                ]);
                
                // 构造详细的错误信息，包含 sub_code 以便上层服务识别
                $errorDetails = [
                    'code' => $errorCode,
                    'msg' => $errorMsg,
                    'sub_code' => $subCode,
                    'sub_msg' => $subMsg,
                    'full_response' => $settleResponse
                ];
                
                $errorMessage = "分账失败: {$errorMsg}" . ($subMsg ? " - {$subMsg}" : "") . " (错误码: {$errorCode}" . ($subCode ? ", 子错误码: {$subCode}" : "") . ")";
                
                // 如果是 PAYCARD_UNABLE_PAYMENT 错误，在异常消息中也包含，便于识别
                if ($subCode === 'PAYCARD_UNABLE_PAYMENT') {
                    $errorMessage .= " [PAYCARD_UNABLE_PAYMENT]";
                }
                
                return self::buildFailureResult($errorMessage, $errorDetails);
            }
            
            Log::info("支付宝分账成功", [
                'trade_no' => $orderInfo['trade_no'],
                'platform_order_no' => $orderInfo['platform_order_no'],
                'royalty_amount' => $royaltyInfo['royalty_amount'],
                'settle_no' => $settleResponse['settle_no'] ?? ''
            ]);
            
            return [
                'success' => true,
                'message' => '分账成功',
                'data' => [
                    'royalty_no' => $settleResponse['settle_no'] ?? '', // 分账单号
                    'royalty_result' => $settleResponse,
                    'full_response' => $response
                ]
            ];
            
            
        } catch (Throwable $e) {
            $retryable = self::isTimeoutException($e);
            Log::error("支付宝分账失败", [
                'order_info' => $orderInfo,
                'royalty_info' => $royaltyInfo,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'retryable' => $retryable
            ]);
            
            return [
                'success' => false,
                'message' => '支付宝分账失败: ' . $e->getMessage(),
                'data' => [
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                    'retryable' => $retryable,
                    'sub_code' => null
                ]
            ];
        }
    }
    
    /**
     * 查询分账结果
     * 
     * @param string $tradeNo 支付宝交易号
     * @param string $royaltyNo 分账单号
     * @param array $paymentConfig 支付配置
     * @return array
     */
    public static function queryRoyalty(string $tradeNo, string $royaltyNo, array $paymentConfig): array
    {
        try {
            $config = AlipayConfig::getConfig($paymentConfig);
            
            // TODO: 实现查询分账结果的接口
            // $result = Factory::setOptions($config)
            //     ->util()
            //     ->generic()
            //     ->execute('alipay.xxx.royalty.query', [
            //         'trade_no' => $tradeNo,
            //         'royalty_no' => $royaltyNo
            //     ]);
            
            return [
                'success' => false,
                'message' => '查询分账结果接口待实现',
                'data' => []
            ];
            
        } catch (Exception $e) {
            Log::error("查询分账结果失败", [
                'trade_no' => $tradeNo,
                'royalty_no' => $royaltyNo,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => '查询失败: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }

    /**
     * 判断异常是否可重试（网络/超时）
     */
    private static function isTimeoutException(Throwable $e): bool
    {
        if ($e instanceof ConnectException || $e instanceof TransferException || $e instanceof RequestException) {
            return true;
        }

        $message = strtolower($e->getMessage());
        return strpos($message, 'timed out') !== false || strpos($message, 'timeout') !== false;
    }

    /**
     * 构建统一失败返回
     */
    private static function buildFailureResult(string $message, array $details = [], bool $retryable = false): array
    {
        if (!array_key_exists('retryable', $details)) {
            $details['retryable'] = $retryable;
        }

        return [
            'success' => false,
            'message' => $message,
            'data' => $details
        ];
    }
}


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
 * 使用 alipay.fund.trans.uni.transfer 接口进行分账转账
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
     *   - payee_account: 收款人账号（支付宝登录号）
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
            $triggerParams = [
                'order_info' => $orderInfo,
                'royalty_info' => $royaltyInfo,
                'payment_subject' => $paymentConfig['subject_name'] ?? null,
            ];
            Log::info('分账触发原始参数: ' . print_r($triggerParams, true));
            self::consoleDebug('分账触发原始参数', $triggerParams);
            
            // 验证必填参数
            if (empty($royaltyInfo['payee_account'])) {
                throw new Exception("收款方账户不能为空");
            }
            if (empty($royaltyInfo['royalty_amount']) || $royaltyInfo['royalty_amount'] <= 0) {
                throw new Exception("分账金额必须大于0");
            }
            
            // 根据账户形式判断使用 ALIPAY_USERID 还是 ALIPAY_LOGON_ID
            $payeeAccount = $royaltyInfo['payee_account'];
            $payeeType = 'ALIPAY_LOGON_ID';
            if (preg_match('/^2088\d{12}$/', $payeeAccount)) {
                $payeeType = 'ALIPAY_USER_ID';
            }
            Log::info('分账收款账户信息', [
                'out_biz_no' => $orderInfo['platform_order_no'] ?? '',
                'payee_account' => $payeeAccount,
                'payee_name' => $royaltyInfo['payee_name'] ?? '',
                'payee_type' => $payeeType,
            ]);
            
            // 使用支付宝统一转账接口 alipay.fund.trans.uni.transfer
            // 构建业务参数
            $outBizNo = $orderInfo['platform_order_no'] . '_' . time(); // 分账请求号（唯一）
            $bizParams = [
                'out_biz_no' => $outBizNo, // 商户转账唯一订单号
                'trans_amount' => number_format($royaltyInfo['royalty_amount'], 2, '.', ''), // 转账金额
                'product_code' => 'TRANS_ACCOUNT_NO_PWD', // 产品码：单笔无密转账到支付宝账户
                'biz_scene' => 'DIRECT_TRANSFER', // 业务场景：单笔无密转账
                'payee_info' => [
                    'identity' => $payeeAccount, // 收款方账户（手机号、邮箱或用户ID）
                    'identity_type' => $payeeType, // 收款方账户类型：ALIPAY_USERID 或 ALIPAY_LOGON_ID
                ],
            ];
            
            // 如果提供了收款方真实姓名，添加到参数中
            if (!empty($royaltyInfo['payee_name'])) {
                $bizParams['payee_info']['name'] = $royaltyInfo['payee_name'];
            }
            
            // 添加转账备注
            $remark = '订单分账-' . ($orderInfo['platform_order_no'] ?? '');
            if (!empty($royaltyInfo['payee_name'])) {
                $remark .= '-' . $royaltyInfo['payee_name'];
            }
            $bizParams['remark'] = $remark;
            
            // 文本参数（可选参数，如app_auth_token等）
            $textParams = [];
            
            // 使用通用接口调用转账接口（需要3个参数：API名称、textParams、bizParams）
            Log::info('分账请求biz参数: ' . print_r($bizParams, true));
            self::consoleDebug('分账请求biz参数', $bizParams);
            
            $result = Factory::setOptions($config)
                ->util()
                ->generic()
                ->execute('alipay.fund.trans.uni.transfer', $textParams, $bizParams);
            
            Log::info('分账接口原始响应: ' . print_r($result, true));
            self::consoleDebug('分账接口原始响应', $result);
            
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
            if (!isset($response['alipay_fund_trans_uni_transfer_response'])) {
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
            
            $transferResponse = $response['alipay_fund_trans_uni_transfer_response'];
            
            // 检查是否成功
            if ($transferResponse['code'] !== '10000') {
                $errorCode = $transferResponse['code'] ?? '';
                $errorMsg = $transferResponse['msg'] ?? '未知错误';
                $subCode = $transferResponse['sub_code'] ?? '';
                $subMsg = $transferResponse['sub_msg'] ?? $transferResponse['msg'] ?? '';
                
                Log::error("支付宝分账返回错误", [
                    'order_info' => $orderInfo,
                    'code' => $errorCode,
                    'msg' => $errorMsg,
                    'sub_code' => $subCode,
                    'sub_msg' => $subMsg,
                    'full_response' => $transferResponse
                ]);
                
                // 构造详细的错误信息，包含 sub_code 以便上层服务识别
                $errorDetails = [
                    'code' => $errorCode,
                    'msg' => $errorMsg,
                    'sub_code' => $subCode,
                    'sub_msg' => $subMsg,
                    'full_response' => $transferResponse
                ];
                
                $errorMessage = "分账失败: {$errorMsg}" . ($subMsg ? " - {$subMsg}" : "") . " (错误码: {$errorCode}" . ($subCode ? ", 子错误码: {$subCode}" : "") . ")";
                
                // 如果是 isv.insufficient-isv-permissions 错误，在异常消息中也包含，便于识别
                if ($subCode === 'isv.insufficient-isv-permissions') {
                    $errorMessage .= " [isv.insufficient-isv-permissions]";
                }
                
                return self::buildFailureResult($errorMessage, $errorDetails);
            }
            
            // 转账状态：SUCCESS-成功，FAIL-失败，DEALING-处理中
            $status = $transferResponse['status'] ?? '';
            $orderId = $transferResponse['order_id'] ?? ''; // 支付宝转账单据号
            $payFundOrderId = $transferResponse['pay_fund_order_id'] ?? ''; // 支付宝支付资金流水号
            
            if ($status === 'SUCCESS') {
                $transDate = !empty($transferResponse['trans_date'])
                    ? date('Y-m-d H:i:s', strtotime($transferResponse['trans_date']))
                    : date('Y-m-d H:i:s');
                Log::info("支付宝分账成功", [
                    'out_biz_no' => $outBizNo,
                    'trade_no' => $orderInfo['trade_no'] ?? '',
                    'platform_order_no' => $orderInfo['platform_order_no'],
                    'royalty_amount' => $royaltyInfo['royalty_amount'],
                    'order_id' => $orderId,
                    'pay_fund_order_id' => $payFundOrderId,
                    'trans_date' => $transDate
                ]);
                
                return [
                    'success' => true,
                    'message' => '分账成功',
                    'data' => [
                        'royalty_no' => $orderId, // 使用 order_id 作为分账单号
                        'pay_fund_order_id' => $payFundOrderId,
                        'status' => $status,
                        'trans_date' => $transDate,
                        'royalty_result' => $transferResponse,
                        'full_response' => $response
                    ]
                ];
            } elseif ($status === 'DEALING') {
                // 处理中
                Log::info("支付宝分账处理中", [
                    'out_biz_no' => $outBizNo,
                    'order_id' => $orderId,
                    'status' => $status
                ]);
                
                return [
                    'success' => false,
                    'message' => '分账处理中，请稍后查询结果',
                    'data' => [
                        'royalty_no' => $orderId,
                        'pay_fund_order_id' => $payFundOrderId,
                        'status' => $status,
                        'retryable' => true,
                        'royalty_result' => $transferResponse,
                        'full_response' => $response
                    ]
                ];
            } else {
                // 失败
                $errorMsg = $transferResponse['sub_msg'] ?? $transferResponse['msg'] ?? '转账失败';
                $subCode = $transferResponse['sub_code'] ?? '';
                
                Log::error("支付宝分账失败", [
                    'out_biz_no' => $outBizNo,
                    'order_id' => $orderId,
                    'status' => $status,
                    'sub_code' => $subCode,
                    'error_msg' => $errorMsg
                ]);
                
                $errorDetails = [
                    'code' => $transferResponse['code'] ?? '',
                    'msg' => $transferResponse['msg'] ?? '',
                    'sub_code' => $subCode,
                    'sub_msg' => $errorMsg,
                    'status' => $status,
                    'full_response' => $transferResponse
                ];
                
                $errorMessage = "分账失败: {$errorMsg}" . ($subCode ? " (子错误码: {$subCode})" : "");
                
                return self::buildFailureResult($errorMessage, $errorDetails);
            }
            
            
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

    /**
     * 在 CLI 控制台输出调试信息（不影响 Web 输出）
     */
    private static function consoleDebug(string $label, $data = null): void
    {
        if (PHP_SAPI === 'cli') {
            $message = '[AlipayRoyalty] ' . $label;
            if ($data !== null) {
                $message .= ': ' . print_r($data, true);
            }
            fwrite(STDOUT, $message . PHP_EOL);
        }
    }
}


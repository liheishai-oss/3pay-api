<?php

namespace app\service\alipay;

use Alipay\EasySDK\Kernel\Factory;
use app\common\constants\OrderConstants;
use Exception;
use support\Log;
use support\Redis;
use Webman\Event\Event;

/**
 * 支付宝通知处理服务类
 */
class AlipayNotifyService
{
    /**
     * 处理支付通知
     * @param array $params 通知参数
     * @param array $paymentInfo 支付配置信息
     * @param string $payIp 支付者IP
     * @return array 处理结果
     * @throws Exception
     */
    public static function handlePaymentNotify(array $params, array $paymentInfo, string $payIp = ''): array
    {
        try {
            echo "    【5.4.1】准备支付宝配置\n";
            $config = AlipayConfig::getConfig($paymentInfo);
            echo "    【5.4.2】配置准备完成\n";
            
            // 验证签名
            echo "    【5.4.3】开始验证签名\n";
            $verifyResult = Factory::setOptions($config)
                ->payment()
                ->common()
                ->verifyNotify($params);
            
            if (!$verifyResult) {
                echo "    【5.4.3】签名验证失败\n";
                Log::warning("支付宝通知签名验证失败", ['params' => $params]);
                throw new Exception("通知签名验证失败");
            }
            echo "    【5.4.3】签名验证成功\n";
            
            // 解析通知数据
            echo "    【5.4.4】解析通知数据\n";
            $notifyData = self::parseNotifyData($params);
            echo "      - 订单号: {$notifyData['out_trade_no']}\n";
            echo "      - 交易号: {$notifyData['trade_no']}\n";
            echo "      - 交易状态: {$notifyData['trade_status']}\n";
            echo "      - 交易金额: {$notifyData['total_amount']}\n";
            
            // 检查订单状态
            echo "    【5.4.5】检查订单状态\n";
            if ($notifyData['trade_status'] !== 'TRADE_SUCCESS' && 
                $notifyData['trade_status'] !== 'TRADE_FINISHED') {
                echo "      - 订单状态非成功: {$notifyData['trade_status']}\n";
                Log::info("支付宝通知订单状态非成功", [
                    'order_number' => $notifyData['out_trade_no'],
                    'trade_status' => $notifyData['trade_status']
                ]);
                return ['success' => false, 'message' => '订单状态非成功'];
            }
            echo "      - 订单状态检查通过\n";
            
            // 防重复处理
            echo "    【5.4.6】检查是否重复处理\n";
            $cacheKey = "alipay_notify:" . $notifyData['out_trade_no'] . ":" . $notifyData['trade_no'];
            if (Redis::get($cacheKey)) {
                echo "      - 通知已处理过，跳过\n";
                Log::info("支付宝通知已处理过", [
                    'order_number' => $notifyData['out_trade_no'],
                    'trade_no' => $notifyData['trade_no']
                ]);
                return ['success' => true, 'message' => '通知已处理'];
            }
            echo "      - 首次处理，继续\n";
            
            // 设置缓存防止重复处理（5分钟）
            echo "    【5.4.7】设置防重复缓存\n";
            Redis::setex($cacheKey, 300, 1);
            echo "      - 缓存设置成功\n";
            
            // 触发订单支付成功事件（使用 try-catch 确保即使事件失败也不影响后续处理）
            echo "    【5.4.8】触发支付成功事件\n";
            if ($payIp) {
                echo "      - 支付者IP: {$payIp}\n";
            }
            @flush();
            @ob_flush();
            try {
                Event::dispatch('order.payment.success', [
                    'order_number' => $notifyData['out_trade_no'],
                    'trade_no' => $notifyData['trade_no'],
                    'amount' => $notifyData['total_amount'],
                    'payment_method' => 'alipay',
                    'pay_ip' => $payIp, // 传递支付者IP
                    'notify_data' => $notifyData
                ]);
                echo "      - 事件触发成功\n";
                @flush();
                @ob_flush();
            } catch (\Exception $e) {
                // 事件触发失败不影响订单状态更新，只记录日志
                echo "      - 事件触发失败（不影响订单处理）: " . $e->getMessage() . "\n";
                echo "      - 异常位置: " . $e->getFile() . ":" . $e->getLine() . "\n";
                @flush();
                @ob_flush();
                Log::warning("支付成功事件触发失败", [
                    'order_number' => $notifyData['out_trade_no'],
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            } catch (\Throwable $e) {
                // 捕获所有错误，包括致命错误
                echo "      - 事件触发失败（致命错误）: " . $e->getMessage() . "\n";
                echo "      - 异常位置: " . $e->getFile() . ":" . $e->getLine() . "\n";
                @flush();
                @ob_flush();
                Log::error("支付成功事件触发失败（致命错误）", [
                    'order_number' => $notifyData['out_trade_no'],
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
            
            Log::info("支付宝支付通知处理成功", [
                'order_number' => $notifyData['out_trade_no'],
                'trade_no' => $notifyData['trade_no'],
                'amount' => $notifyData['total_amount']
            ]);
            
            echo "    【5.4.9】通知处理完成\n";
            @flush();
            @ob_flush();
            return ['success' => true, 'message' => '通知处理成功'];
            
        } catch (Exception $e) {
            echo "    【6.5.错误】支付宝通知处理异常\n";
            echo "      - 错误信息: " . $e->getMessage() . "\n";
            echo "      - 错误位置: " . $e->getFile() . ":" . $e->getLine() . "\n";
            Log::error("支付宝通知处理失败", [
                'params' => $params,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new Exception("通知处理失败: " . $e->getMessage());
        }
    }
    
    /**
     * 处理退款通知
     * @param array $params 通知参数
     * @param array $paymentInfo 支付配置信息
     * @return array 处理结果
     * @throws Exception
     */
    public static function handleRefundNotify(array $params, array $paymentInfo): array
    {
        try {
            $config = AlipayConfig::getConfig($paymentInfo);
            
            // 验证签名
            $verifyResult = Factory::setOptions($config)
                ->payment()
                ->common()
                ->verifyNotify($params);
            
            if (!$verifyResult) {
                Log::warning("支付宝退款通知签名验证失败", ['params' => $params]);
                throw new Exception("退款通知签名验证失败");
            }
            
            // 解析通知数据
            $notifyData = self::parseRefundNotifyData($params);
            
            // 防重复处理
            $cacheKey = "alipay_refund_notify:" . $notifyData['out_trade_no'] . ":" . $notifyData['out_request_no'];
            if (Redis::get($cacheKey)) {
                Log::info("支付宝退款通知已处理过", [
                    'order_number' => $notifyData['out_trade_no'],
                    'refund_no' => $notifyData['out_request_no']
                ]);
                return ['success' => true, 'message' => '退款通知已处理'];
            }
            
            // 设置缓存防止重复处理（5分钟）
            Redis::setex($cacheKey, 300, 1);
            
            // 触发退款成功事件
            Event::dispatch('order.refund.success', [
                'order_number' => $notifyData['out_trade_no'],
                'refund_no' => $notifyData['out_request_no'],
                'refund_amount' => $notifyData['refund_amount'],
                'payment_method' => 'alipay',
                'notify_data' => $notifyData
            ]);
            
            Log::info("支付宝退款通知处理成功", [
                'order_number' => $notifyData['out_trade_no'],
                'refund_no' => $notifyData['out_request_no'],
                'refund_amount' => $notifyData['refund_amount']
            ]);
            
            return ['success' => true, 'message' => '退款通知处理成功'];
            
        } catch (Exception $e) {
            Log::error("支付宝退款通知处理失败", [
                'params' => $params,
                'error' => $e->getMessage()
            ]);
            throw new Exception("退款通知处理失败: " . $e->getMessage());
        }
    }
    
    /**
     * 解析支付通知数据
     * @param array $params 通知参数
     * @return array 解析后的数据
     */
    private static function parseNotifyData(array $params): array
    {
        return [
            'out_trade_no' => $params['out_trade_no'] ?? '',
            'trade_no' => $params['trade_no'] ?? '',
            'trade_status' => $params['trade_status'] ?? '',
            'total_amount' => $params['total_amount'] ?? '0',
            'receipt_amount' => $params['receipt_amount'] ?? '0',
            'buyer_id' => $params['buyer_id'] ?? '',
            'buyer_logon_id' => $params['buyer_logon_id'] ?? '',
            'seller_id' => $params['seller_id'] ?? '',
            'seller_email' => $params['seller_email'] ?? '',
            'gmt_payment' => $params['gmt_payment'] ?? '',
            'gmt_create' => $params['gmt_create'] ?? '',
            'subject' => $params['subject'] ?? '',
            'body' => $params['body'] ?? '',
            'fund_bill_list' => $params['fund_bill_list'] ?? '',
            'voucher_detail_list' => $params['voucher_detail_list'] ?? '',
        ];
    }
    
    /**
     * 解析退款通知数据
     * @param array $params 通知参数
     * @return array 解析后的数据
     */
    private static function parseRefundNotifyData(array $params): array
    {
        return [
            'out_trade_no' => $params['out_trade_no'] ?? '',
            'trade_no' => $params['trade_no'] ?? '',
            'out_request_no' => $params['out_request_no'] ?? '',
            'refund_amount' => $params['refund_amount'] ?? '0',
            'refund_reason' => $params['refund_reason'] ?? '',
            'refund_status' => $params['refund_status'] ?? '',
            'gmt_refund_pay' => $params['gmt_refund_pay'] ?? '',
        ];
    }
}

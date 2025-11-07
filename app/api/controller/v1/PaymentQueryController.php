<?php

namespace app\api\controller\v1;

use app\model\Order;
use app\model\Product;
use app\service\payment\PaymentFactory;
use support\Request;
use support\Log;

/**
 * 支付查询控制器
 */
class PaymentQueryController
{
    /**
     * 查询订单状态
     * @param Request $request
     * @return \support\Response
     */
    public function queryOrder(Request $request)
    {
        try {
            $params = $request->all();
            
            // 验证必填参数
            if (empty($params['api_key']) || empty($params['order_no'])) {
                return $this->error('缺少必要参数');
            }

            // 验证商户
            $merchant = \app\model\Merchant::where('api_key', $params['api_key'])
                ->where('status', \app\model\Merchant::STATUS_ENABLED)
                ->first();
            
            if (!$merchant) {
                return $this->error('无效的API密钥或商户已被禁用');
            }

            // 验证签名
            if (!\app\common\helpers\SignatureHelper::verify($params, $merchant->api_secret)) {
                return $this->error('签名验证失败');
            }

            // 获取订单信息
            $order = Order::where('merchant_id', $merchant->id)
                ->where(function($query) use ($params) {
                    $query->where('platform_order_no', $params['order_no'])
                          ->orWhere('merchant_order_no', $params['order_no']);
                })
                ->first();

            if (!$order) {
                return $this->error('订单不存在');
            }

            // 获取产品信息
            $product = Product::find($order->product_id);
            if (!$product) {
                return $this->error('产品信息不存在');
            }

            // 如果订单已支付，直接返回本地状态
            if ($order->pay_status === Order::PAY_STATUS_PAID) {
                return $this->success([
                    'order_no' => $order->platform_order_no,
                    'merchant_order_no' => $order->merchant_order_no,
                    'amount' => $order->order_amount,
                    'pay_status' => $order->pay_status,
                    'pay_status_text' => $this->getPayStatusText($order->pay_status),
                    'paid_at' => $order->paid_at,
                    'trade_no' => $order->trade_no,
                    'source' => 'local'
                ]);
            }

            // 如果订单未支付，查询第三方支付状态
            try {
                $paymentResult = PaymentFactory::queryOrder(
                    $product->product_code,
                    $order->platform_order_no,
                    $order->agent_id
                );

                // 更新本地订单状态（传递请求对象以获取IP）
                $this->updateOrderFromPaymentResult($order, $paymentResult, $request);

                return $this->success([
                    'order_no' => $order->platform_order_no,
                    'merchant_order_no' => $order->merchant_order_no,
                    'amount' => $order->order_amount,
                    'pay_status' => $order->pay_status,
                    'pay_status_text' => $this->getPayStatusText($order->pay_status),
                    'paid_at' => $order->paid_at,
                    'trade_no' => $order->trade_no,
                    'source' => 'payment_gateway',
                    'payment_info' => $paymentResult
                ]);

            } catch (\Exception $e) {
                Log::warning('查询第三方支付状态失败', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage()
                ]);

                // 第三方查询失败，返回本地状态
                return $this->success([
                    'order_no' => $order->platform_order_no,
                    'merchant_order_no' => $order->merchant_order_no,
                    'amount' => $order->order_amount,
                    'pay_status' => $order->pay_status,
                    'pay_status_text' => $this->getPayStatusText($order->pay_status),
                    'paid_at' => $order->paid_at,
                    'trade_no' => $order->trade_no,
                    'source' => 'local',
                    'warning' => '第三方查询失败，返回本地状态'
                ]);
            }

        } catch (\Exception $e) {
            Log::error('查询订单状态异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->error('系统异常，请稍后重试');
        }
    }

    /**
     * 根据支付结果更新订单状态
     * @param Order $order 订单
     * @param array $paymentResult 支付结果
     * @param Request $request 请求对象（用于获取IP）
     */
    private function updateOrderFromPaymentResult(Order $order, array $paymentResult, $request = null)
    {
        try {
            $tradeStatus = $paymentResult['trade_status'] ?? '';
            
            if (in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'])) {
                // 支付成功
                // 注意：支付宝查询接口不返回支付者IP，真正的支付者IP应该从支付回调（notify）中获取
                // 如果 pay_ip 已存在（说明已收到回调），则不覆盖；如果为空，则使用查询请求IP作为备选（非真实支付者IP）
                $updateData = [
                    'pay_status' => Order::PAY_STATUS_PAID,
                    'paid_at' => $paymentResult['gmt_payment'] ?? now(),
                    'trade_no' => $paymentResult['trade_no'] ?? '',
                    'buyer_id' => $paymentResult['buyer_id'] ?? '',
                    'buyer_logon_id' => $paymentResult['buyer_logon_id'] ?? '',
                    'receipt_amount' => $paymentResult['receipt_amount'] ?? $order->order_amount,
                ];
                
                // 只有在 pay_ip 为空时才设置（使用查询请求IP作为备选，非真实支付者IP）
                if (empty($order->pay_ip) && $request) {
                    $updateData['pay_ip'] = $request->getRealIp();
                    Log::info('通过查询接口更新订单，使用查询请求IP作为备选（非真实支付者IP）', [
                        'order_id' => $order->id,
                        'query_ip' => $updateData['pay_ip']
                    ]);
                }
                
                $order->update($updateData);

                Log::info('订单状态更新为已支付', [
                    'order_id' => $order->id,
                    'trade_no' => $paymentResult['trade_no'] ?? ''
                ]);

            } elseif ($tradeStatus === 'TRADE_CLOSED') {
                // 交易关闭
                $now = date('Y-m-d H:i:s');
                $order->update([
                    'pay_status' => Order::PAY_STATUS_CLOSED,
                    'close_time' => $now,
                ]);

                Log::info('订单状态更新为已关闭', [
                    'order_id' => $order->id,
                    'close_time' => $now
                ]);
            }

        } catch (\Exception $e) {
            Log::error('更新订单状态失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 获取支付状态文本
     * @param int $status 状态码
     * @return string 状态文本
     */
    private function getPayStatusText(int $status): string
    {
        switch ($status) {
            case Order::PAY_STATUS_CREATED:
                return '已创建';
            case Order::PAY_STATUS_OPENED:
                return '已打开';
            case Order::PAY_STATUS_PAID:
                return '已支付';
            case Order::PAY_STATUS_CLOSED:
                return '已关闭';
            case Order::PAY_STATUS_REFUNDED:
                return '已退款';
            default:
                return '未知状态';
        }
    }

    /**
     * 成功响应
     * @param array $data 数据
     * @param string $message 消息
     * @return \support\Response
     */
    private function success($data = [], $message = 'success')
    {
        return json([
            'code' => 0,
            'msg' => $message,
            'data' => $data
        ]);
    }

    /**
     * 错误响应
     * @param string $message 错误消息
     * @param int $code 错误代码
     * @return \support\Response
     */
    private function error($message = 'error', $code = 1)
    {
        return json([
            'code' => $code,
            'msg' => $message,
            'data' => null
        ]);
    }
}

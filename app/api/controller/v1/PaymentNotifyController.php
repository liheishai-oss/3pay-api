<?php

namespace app\api\controller\v1;

use app\model\Order;
use app\model\Product;
use app\service\payment\PaymentFactory;
use support\Request;
use support\Log;
use support\Db;

/**
 * 支付通知处理控制器
 */
class PaymentNotifyController
{
    /**
     * 支付宝支付通知
     * @param Request $request
     * @return string
     */
    public function alipay(Request $request)
    {
        try {
            $params = $request->post();
            // 测试快速模拟开关：模拟支付宝回调，无需真实验签（仅用于联调/测试）
            // 使用方式：POST /api/v1/payment/notify/alipay  body: simulate=1&out_trade_no=平台订单号
            // debug=1 或 simulate=1 时，跳过渠道验签，直接走后续流程（仅测试联调使用）
            if ((!empty($params['simulate']) && $params['simulate'] == '1') || (!empty($params['debug']) && $params['debug'] == '1')) {
                $outTradeNo = $params['out_trade_no'] ?? '';
                if (empty($outTradeNo)) {
                    Log::warning('模拟回调缺少订单号');
                    return 'fail';
                }
                $order = Order::where('platform_order_no', $outTradeNo)->first();
                if (!$order) {
                    Log::warning('模拟回调订单不存在', ['order_no' => $outTradeNo]);
                    return 'fail';
                }
                \app\service\OrderLogService::log(
                    $order->trace_id ?? '',
                    $order->platform_order_no,
                    $order->merchant_order_no,
                    '支付回调', 'INFO', '节点22-模拟回调',
                    ['action'=>'模拟回调成功','simulate'=>true],
                    $request->getRealIp(), $request->header('user-agent', '')
                );
                // 直接更新订单并触发商户回调
                $this->updateOrderStatus($order, [
                    'trade_no' => $params['trade_no'] ?? ('SIM'.date('His')),
                    'buyer_id' => $params['buyer_id'] ?? '',
                    'buyer_logon_id' => $params['buyer_logon_id'] ?? '',
                    'receipt_amount' => $params['receipt_amount'] ?? $order->order_amount,
                ], $request->getRealIp(), $request->header('user-agent', ''));
                return 'success';
            }
            
            Log::info('收到支付宝支付通知', [
                'params' => $params,
                'ip' => $request->getRealIp()
            ]);

            // 验证必要参数
            if (empty($params['out_trade_no'])) {
                \app\service\OrderLogService::log(
                    $params['trace_id'] ?? '',
                    $params['out_trade_no'] ?? '',
                    '',
                    '支付回调', 'ERROR', '节点20-支付回调',
                    ['action'=>'回调失败', 'reason'=>'参数缺少订单号','params'=>$params],
                    $request->getRealIp(), $request->header('user-agent', '')
                );
                Log::warning('支付宝通知缺少订单号');
                return 'fail';
            }

            // 获取订单信息
            $order = Order::where('platform_order_no', $params['out_trade_no'])->first();
            if (!$order) {
                \app\service\OrderLogService::log(
                    $params['trace_id'] ?? '',
                    $params['out_trade_no'] ?? '', '',
                    '支付回调', 'ERROR', '节点21-支付回调无单',
                    ['action'=>'回调失败', 'reason'=>'订单不存在'],
                    $request->getRealIp(), $request->header('user-agent', '')
                );
                Log::warning('订单不存在', ['order_no' => $params['out_trade_no']]);
                return 'fail';
            }
            
            // 检查订单是否过期（防止过期订单的支付回调被处理）
            $isExpired = $order->expire_time && strtotime($order->expire_time) < time();
            if ($isExpired && ($order->pay_status == Order::PAY_STATUS_CREATED || $order->pay_status == Order::PAY_STATUS_OPENED)) {
                \app\service\OrderLogService::log(
                    $order->trace_id ?? '',
                    $order->platform_order_no,
                    $order->merchant_order_no,
                    '支付回调', 'WARN', '节点21-订单已过期',
                    [
                        'action' => '拒绝过期订单支付回调',
                        'expire_time' => $order->expire_time,
                        'current_time' => date('Y-m-d H:i:s'),
                        'trade_no' => $params['trade_no'] ?? ''
                    ],
                    $request->getRealIp(),
                    $request->header('user-agent', '')
                );
                Log::warning('订单已过期，拒绝支付回调', [
                    'order_no' => $params['out_trade_no'],
                    'expire_time' => $order->expire_time,
                    'trade_no' => $params['trade_no'] ?? ''
                ]);
                
                // 如果订单还未关闭，先关闭订单
                if ($order->pay_status == Order::PAY_STATUS_CREATED || $order->pay_status == Order::PAY_STATUS_OPENED) {
                    $now = date('Y-m-d H:i:s');
                    $order->pay_status = Order::PAY_STATUS_CLOSED;
                    $order->close_time = $now;
                    $order->save();
                }
                
                return 'fail'; // 返回fail，让支付宝知道我们拒绝了这次回调
            }

            // 获取产品信息
            $product = Product::find($order->product_id);
            if (!$product) {
                Log::warning('产品不存在', ['product_id' => $order->product_id]);
                return 'fail';
            }

            // 通过支付工厂处理通知
            $result = PaymentFactory::handlePaymentNotify(
                $product->product_code,
                $params,
                $order->agent_id
            );

            if ($result['success']) {
                \app\service\OrderLogService::log(
                    $order->trace_id ?? '',
                    $order->platform_order_no,
                    $order->merchant_order_no,
                    '支付回调', 'INFO', '节点22-回调处理成功',
                    ['action'=>'支付回调成功','trade_no'=>$params['trade_no']??'','params'=>$params],
                    $request->getRealIp(), $request->header('user-agent', '')
                );
                // 更新订单状态（记录支付IP，从回调请求IP获取）
                $this->updateOrderStatus($order, $params, $request->getRealIp(), $request->header('user-agent', ''));
                Log::info('支付通知处理成功', [
                    'order_no' => $params['out_trade_no'],
                    'trade_no' => $params['trade_no'] ?? ''
                ]);
                return 'success';
            } else {
                \app\service\OrderLogService::log(
                    $order->trace_id ?? '',
                    $order->platform_order_no,
                    $order->merchant_order_no,
                    '支付回调', 'WARN', '节点23-回调处理失败',
                    ['action'=>'支付回调处理失败','message'=>$result['message']??''],
                    $request->getRealIp(), $request->header('user-agent', '')
                );
                Log::warning('支付通知处理失败', [
                    'order_no' => $params['out_trade_no'],
                    'message' => $result['message'] ?? ''
                ]);
                return 'fail';
            }

        } catch (\Exception $e) {
            Log::error('支付通知处理异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 'fail';
        }
    }

    /**
     * 更新订单状态
     * @param Order $order 订单
     * @param array $notifyParams 通知参数
     * @param string $payIp 支付IP
     * @param string $userAgent 用户代理（可选）
     */
    private function updateOrderStatus(Order $order, array $notifyParams, $payIp = '', $userAgent = '')
    {
        try {
            Db::beginTransaction();

            // 更新订单状态
            $updateData = [
                'pay_status' => Order::PAY_STATUS_PAID,
                // 支付确认后仅标记待通知，实际通知成功后再置成功
                'notify_status' => Order::NOTIFY_STATUS_PENDING,
                // 次数由通知发送逻辑自增
                // 'notify_times' => $order->notify_times + 1,
                // 统一使用 pay_time 字段
                'pay_time' => date('Y-m-d H:i:s'),
                'pay_ip' => $payIp, // 记录支付IP
                // 注意：order表中没有trade_no字段，使用alipay_order_no
                'alipay_order_no' => $notifyParams['trade_no'] ?? $order->alipay_order_no ?? '',
                // 'buyer_logon_id' => $notifyParams['buyer_logon_id'] ?? '', // 订单表没有buyer_logon_id字段
                'receipt_amount' => $notifyParams['receipt_amount'] ?? $order->order_amount,
            ];
            
            // 更新购买者UID：只在有值时才更新，如果为空则保留原值（避免覆盖）
            if (!empty($notifyParams['buyer_id'])) {
                $updateData['buyer_id'] = $notifyParams['buyer_id'];
                Log::info('支付回调：更新购买者UID', [
                    'order_id' => $order->id,
                    'platform_order_no' => $order->platform_order_no,
                    'old_buyer_id' => $order->buyer_id,
                    'new_buyer_id' => $notifyParams['buyer_id']
                ]);
            } elseif (empty($order->buyer_id)) {
                // 如果订单中也没有buyer_id，记录警告
                Log::warning('支付回调：buyer_id为空，且订单中也没有buyer_id', [
                    'order_id' => $order->id,
                    'platform_order_no' => $order->platform_order_no,
                    'notify_params_keys' => array_keys($notifyParams)
                ]);
            }
            
            $order->update($updateData);

            // 发送商户通知（成功后回写 SUCCESS），自动回调不绕过熔断
            \app\service\MerchantNotifyService::send($order, $notifyParams, ['manual' => false]);

            Db::commit();

            // 订单支付成功后，将订单加入分账队列（秒级异步处理，不阻塞回调响应）
            try {
                // 刷新订单数据以获取最新状态
                $order->refresh();
                
                // 将订单ID加入Redis分账队列
                $queueKey = \app\common\constants\CacheKeys::getRoyaltyQueueKey();
                $queueData = json_encode([
                    'order_id' => $order->id,
                    'operator_ip' => $payIp,
                    'operator_agent' => $userAgent ?: 'PaymentNotify',
                    'timestamp' => time()
                ]);
                
                \support\Redis::lpush($queueKey, $queueData);
                
                // 记录分账队列日志
                \app\service\OrderLogService::log(
                    $order->trace_id ?? '',
                    $order->platform_order_no,
                    $order->merchant_order_no,
                    '分账处理',
                    'INFO',
                    '节点34-分账入队',
                    [
                        'action' => '支付回调后将订单加入分账队列，将由分账进程秒级处理',
                        'pay_status' => $order->pay_status,
                        'subject_id' => $order->subject_id,
                        'note' => '分账将由OrderAutoRoyalty进程秒级处理（每1秒检查队列），不阻塞回调响应',
                        'operator_ip' => $payIp
                    ],
                    $payIp,
                    $userAgent
                );
                
                Log::info('支付回调后已将订单加入分账队列', [
                    'order_id' => $order->id,
                    'platform_order_no' => $order->platform_order_no,
                    'note' => '分账将由OrderAutoRoyalty进程秒级处理'
                ]);
            } catch (\Exception $e) {
                // 入队失败不影响支付回调结果，只记录日志
                Log::warning('支付回调后加入分账队列失败', [
                    'order_id' => $order->id,
                    'platform_order_no' => $order->platform_order_no,
                    'error' => $e->getMessage()
                ]);
            }

        } catch (\Exception $e) {
            Db::rollBack();
            Log::error('更新订单状态失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

}

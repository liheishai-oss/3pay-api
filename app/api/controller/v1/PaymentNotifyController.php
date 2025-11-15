<?php

namespace app\api\controller\v1;

use app\model\Order;
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
        $startTime = microtime(true);
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "【支付宝回调处理开始】" . date('Y-m-d H:i:s') . "\n";
        echo str_repeat("=", 80) . "\n";
        
        try {
            $params = $request->post();
            echo "【步骤1】接收回调参数\n";
            echo "  - 请求IP: " . $request->getRealIp() . "\n";
            echo "  - 参数数量: " . count($params) . "\n";
            echo "  - 订单号: " . ($params['out_trade_no'] ?? '未提供') . "\n";
            echo "  - 支付宝交易号: " . ($params['trade_no'] ?? '未提供') . "\n";
            echo "  - 交易状态: " . ($params['trade_status'] ?? '未提供') . "\n";
            
            // 测试快速模拟开关：模拟支付宝回调，无需真实验签（仅用于联调/测试）
            // 使用方式：POST /api/v1/payment/notify/alipay  body: simulate=1&out_trade_no=平台订单号
            // debug=1 或 simulate=1 时，跳过渠道验签，直接走后续流程（仅测试联调使用）
            if ((!empty($params['simulate']) && $params['simulate'] == '1') || (!empty($params['debug']) && $params['debug'] == '1')) {
                echo "【步骤2】模拟回调模式（跳过验签）\n";
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
                echo "  - 订单ID: {$order->id}\n";
                echo "  - 订单状态: {$order->pay_status}\n";
                echo "  - 订单金额: {$order->order_amount}\n";
                
                \app\service\OrderLogService::log(
                    $order->trace_id ?? '',
                    $order->platform_order_no,
                    $order->merchant_order_no,
                    '支付回调', 'INFO', '节点22-模拟回调',
                    ['action'=>'模拟回调成功','simulate'=>true],
                    $request->getRealIp(), $request->header('user-agent', '')
                );
                // 直接更新订单并触发商户回调
                echo "【步骤3】更新订单状态（模拟模式）\n";
                $this->updateOrderStatus($order, [
                    'trade_no' => $params['trade_no'] ?? ('SIM'.date('His')),
                    'buyer_id' => $params['buyer_id'] ?? '',
                    'buyer_logon_id' => $params['buyer_logon_id'] ?? '',
                    'receipt_amount' => $params['receipt_amount'] ?? $order->order_amount,
                ], $request->getRealIp(), $request->header('user-agent', ''));
                
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                echo "【完成】模拟回调处理成功，耗时: {$duration}ms\n";
                echo str_repeat("=", 80) . "\n\n";
                return 'success';
            }
            
            echo "【步骤2】正常回调模式\n";
            Log::info('收到支付宝支付通知', [
                'params' => $params,
                'ip' => $request->getRealIp()
            ]);

            // 验证必要参数
            if (empty($params['out_trade_no'])) {
                echo "【错误】缺少订单号参数\n";
                \app\service\OrderLogService::log(
                    $params['trace_id'] ?? '',
                    $params['out_trade_no'] ?? '',
                    '',
                    '支付回调', 'ERROR', '节点20-支付回调',
                    ['action'=>'回调失败', 'reason'=>'参数缺少订单号','params'=>$params],
                    $request->getRealIp(), $request->header('user-agent', '')
                );
                Log::warning('支付宝通知缺少订单号');
                echo str_repeat("=", 80) . "\n\n";
                return 'fail';
            }

            // 获取订单信息
            echo "【步骤3】查询订单信息\n";
            $order = Order::where('platform_order_no', $params['out_trade_no'])->first();
            if (!$order) {
                echo "  - 订单不存在: {$params['out_trade_no']}\n";
                \app\service\OrderLogService::log(
                    $params['trace_id'] ?? '',
                    $params['out_trade_no'] ?? '', '',
                    '支付回调', 'ERROR', '节点21-支付回调无单',
                    ['action'=>'回调失败', 'reason'=>'订单不存在'],
                    $request->getRealIp(), $request->header('user-agent', '')
                );
                Log::warning('订单不存在', ['order_no' => $params['out_trade_no']]);
                echo str_repeat("=", 80) . "\n\n";
                return 'fail';
            }
            
            echo "  - 订单ID: {$order->id}\n";
            echo "  - 订单状态: {$order->pay_status}\n";
            echo "  - 订单金额: {$order->order_amount}\n";
            echo "  - 过期时间: " . ($order->expire_time ?? '未设置') . "\n";
            
            // 注意：如果支付宝回调了TRADE_SUCCESS，说明支付已完成，即使订单过期也应该接受
            // 订单过期检查已在创建订单时处理，回调时不再检查过期（避免拒绝已完成的支付）
            
            // 加载产品信息（使用关联，避免重复查询）
            echo "【步骤4】加载产品信息\n";
            $order->load('product.paymentType');
            if (!$order->product) {
                echo "  - 产品不存在: {$order->product_id}\n";
                Log::warning('产品不存在', ['product_id' => $order->product_id]);
                echo str_repeat("=", 80) . "\n\n";
                return 'fail';
            }
            if (!$order->product->paymentType) {
                echo "  - 产品未配置支付类型\n";
                Log::warning('产品未配置支付类型', ['product_id' => $order->product_id]);
                echo str_repeat("=", 80) . "\n\n";
                return 'fail';
            }
            echo "  - 产品ID: {$order->product->id}\n";
            echo "  - 产品代码: {$order->product->product_code}\n";

            // 通过支付工厂处理通知（直接传递订单对象，避免重复查询）
            echo "【步骤5】验证签名和处理通知\n";
            try {
                $result = PaymentFactory::handlePaymentNotify(
                    $order,
                    $params
                );
                
                echo "  - 处理结果返回\n";
                echo "    - 结果类型: " . gettype($result) . "\n";
                if (is_array($result)) {
                    echo "    - success字段: " . (isset($result['success']) ? ($result['success'] ? 'true' : 'false') : '不存在') . "\n";
                    echo "    - message字段: " . ($result['message'] ?? '不存在') . "\n";
                } else {
                    echo "    - 结果不是数组，值: " . var_export($result, true) . "\n";
                }
            } catch (\Exception $e) {
                echo "  - 处理通知时发生异常\n";
                echo "    - 异常信息: " . $e->getMessage() . "\n";
                echo "    - 异常位置: " . $e->getFile() . ":" . $e->getLine() . "\n";
                throw $e;
            }

            if (isset($result) && is_array($result) && !empty($result['success'])) {
                echo "  - 签名验证成功\n";
                echo "  - 通知处理成功\n";
                
                // 检查是否是重复回调
                $isDuplicate = isset($result['message']) && $result['message'] === '通知已处理';
                
                if ($isDuplicate) {
                    echo "  - 检测到重复回调\n";
                    // 刷新订单数据
                    $order->refresh();
                    // 检查订单状态，如果已经是已支付，则跳过更新
                    if ($order->pay_status == Order::PAY_STATUS_PAID) {
                        echo "  - 订单状态已是已支付，跳过更新\n";
                        \app\service\OrderLogService::log(
                            $order->trace_id ?? '',
                            $order->platform_order_no,
                            $order->merchant_order_no,
                            '支付回调', 'INFO', '节点22-重复回调已跳过',
                            ['action'=>'重复回调，订单已支付','trade_no'=>$params['trade_no']??''],
                            $request->getRealIp(), $request->header('user-agent', '')
                        );
                        $duration = round((microtime(true) - $startTime) * 1000, 2);
                        echo "【完成】支付回调处理成功（重复回调已跳过），耗时: {$duration}ms\n";
                        echo str_repeat("=", 80) . "\n\n";
                        return 'success';
                    } else {
                        echo "  - 订单状态未更新为已支付（状态: {$order->pay_status}），将重新更新\n";
                    }
                }
                
                \app\service\OrderLogService::log(
                    $order->trace_id ?? '',
                    $order->platform_order_no,
                    $order->merchant_order_no,
                    '支付回调', 'INFO', '节点22-回调处理成功',
                    ['action'=>'支付回调成功','trade_no'=>$params['trade_no']??'','params'=>$params, 'is_duplicate'=>$isDuplicate],
                    $request->getRealIp(), $request->header('user-agent', '')
                );
                // 更新订单状态（记录支付IP，从回调请求IP获取）
                echo "【步骤6】更新订单状态\n";
                try {
                    $this->updateOrderStatus($order, $params, $request->getRealIp(), $request->header('user-agent', ''));
                    echo "  - 订单状态更新完成\n";
                } catch (\Exception $e) {
                    echo "  - 更新订单状态时发生异常\n";
                    echo "    - 异常信息: " . $e->getMessage() . "\n";
                    echo "    - 异常位置: " . $e->getFile() . ":" . $e->getLine() . "\n";
                    throw $e;
                }
                Log::info('支付通知处理成功', [
                    'order_no' => $params['out_trade_no'],
                    'trade_no' => $params['trade_no'] ?? '',
                    'is_duplicate' => $isDuplicate
                ]);
                
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                echo "【完成】支付回调处理成功，耗时: {$duration}ms\n";
                echo str_repeat("=", 80) . "\n\n";
                return 'success';
            } else {
                echo "  - 签名验证失败或处理失败\n";
                $errorMsg = '未知错误';
                if (isset($result) && is_array($result)) {
                    $errorMsg = $result['message'] ?? '处理失败但未返回错误信息';
                } elseif (isset($result)) {
                    $errorMsg = '返回结果格式错误: ' . gettype($result);
                } else {
                    $errorMsg = '未返回结果';
                }
                echo "  - 错误信息: {$errorMsg}\n";
                \app\service\OrderLogService::log(
                    $order->trace_id ?? '',
                    $order->platform_order_no,
                    $order->merchant_order_no,
                    '支付回调', 'WARN', '节点23-回调处理失败',
                    ['action'=>'支付回调处理失败','message'=>$errorMsg],
                    $request->getRealIp(), $request->header('user-agent', '')
                );
                Log::warning('支付通知处理失败', [
                    'order_no' => $params['out_trade_no'],
                    'message' => $errorMsg
                ]);
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                echo "【失败】支付回调处理失败，耗时: {$duration}ms\n";
                echo str_repeat("=", 80) . "\n\n";
                return 'fail';
            }

        } catch (\Exception $e) {
            echo "【异常】支付回调处理异常\n";
            echo "  - 错误信息: " . $e->getMessage() . "\n";
            echo "  - 错误位置: " . $e->getFile() . ":" . $e->getLine() . "\n";
            Log::error('支付通知处理异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            echo "【异常】处理耗时: {$duration}ms\n";
            echo str_repeat("=", 80) . "\n\n";
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
            echo "  【6.1】开始事务\n";
            echo "    - 订单ID: {$order->id}\n";
            echo "    - 订单号: {$order->platform_order_no}\n";
            echo "    - 当前支付状态: {$order->pay_status}\n";
            Db::beginTransaction();

            // 更新订单状态
            echo "  【6.2】准备更新订单数据\n";
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
            
            echo "    - 更新数据准备完成\n";
            echo "      - 支付状态: " . Order::PAY_STATUS_PAID . "\n";
            echo "      - 通知状态: " . Order::NOTIFY_STATUS_PENDING . "\n";
            echo "      - 支付时间: " . $updateData['pay_time'] . "\n";
            echo "      - 支付宝交易号: " . ($updateData['alipay_order_no'] ?? '') . "\n";
            
            // 更新购买者UID：只在有值时才更新，如果为空则保留原值（避免覆盖）
            if (!empty($notifyParams['buyer_id'])) {
                $updateData['buyer_id'] = $notifyParams['buyer_id'];
                echo "      - 购买者UID: {$notifyParams['buyer_id']}\n";
                Log::info('支付回调：更新购买者UID', [
                    'order_id' => $order->id,
                    'platform_order_no' => $order->platform_order_no,
                    'old_buyer_id' => $order->buyer_id,
                    'new_buyer_id' => $notifyParams['buyer_id']
                ]);
            } elseif (empty($order->buyer_id)) {
                // 如果订单中也没有buyer_id，记录警告
                echo "      - 警告: buyer_id为空\n";
                Log::warning('支付回调：buyer_id为空，且订单中也没有buyer_id', [
                    'order_id' => $order->id,
                    'platform_order_no' => $order->platform_order_no,
                    'notify_params_keys' => array_keys($notifyParams)
                ]);
            }
            
            echo "  【6.3】执行订单更新\n";
            $updateResult = $order->update($updateData);
            echo "    - 更新结果: " . ($updateResult ? '成功' : '失败') . "\n";
            
            // 刷新订单数据以获取最新状态
            $order->refresh();
            echo "  【6.3】订单状态已更新\n";
            echo "    - 支付状态: {$order->pay_status} (已更新为: " . Order::PAY_STATUS_PAID . ")\n";
            echo "    - 支付宝交易号: " . ($order->alipay_order_no ?? '') . "\n";
            echo "    - 支付时间: " . ($order->pay_time ?? '未设置') . "\n";
            echo "    - 支付IP: " . ($order->pay_ip ?: '未记录') . "\n";

            // 发送商户通知（成功后回写 SUCCESS），自动回调不绕过熔断
            echo "  【6.4】发送商户通知\n";
            $notifyResult = \app\service\MerchantNotifyService::send($order, $notifyParams, ['manual' => false]);
            if ($notifyResult['success']) {
                echo "    - 商户通知发送成功\n";
            } else {
                echo "    - 商户通知发送失败: " . ($notifyResult['message'] ?? '未知错误') . "\n";
            }

            echo "  【6.5】提交事务\n";
            Db::commit();

            // 订单支付成功后，将订单加入分账队列（秒级异步处理，不阻塞回调响应）
            try {
                echo "  【6.6】加入分账队列\n";
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
                echo "    - 订单已加入分账队列\n";
                
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
                echo "    - 加入分账队列失败: " . $e->getMessage() . "\n";
                Log::warning('支付回调后加入分账队列失败', [
                    'order_id' => $order->id,
                    'platform_order_no' => $order->platform_order_no,
                    'error' => $e->getMessage()
                ]);
            }

        } catch (\Exception $e) {
            echo "  【错误】更新订单状态失败\n";
            echo "    - 错误信息: " . $e->getMessage() . "\n";
            Db::rollBack();
            Log::error('更新订单状态失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

}

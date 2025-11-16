<?php

namespace app\service;

use app\model\Order;
use app\model\TelegramMessageQueue;
use support\Db;
use support\Log;
use support\Redis;
use app\common\helpers\SignatureHelper;
use app\service\robot\TelegramMessageQueueService;

class MerchantNotifyService
{
    /**
     * 发送商户通知（幂等+状态回写）
     * 返回 [success=>bool, message=>string]
     */
    public static function send(Order $order, array $notifyParams = [], array $options = []): array
    {
        try {
            $isManual = (bool)($options['manual'] ?? false);
            // 可配置阈值（默认：5次、5分钟）
            $timeoutThreshold = (int)env('NOTIFY_TIMEOUT_THRESHOLD', 5);
            $badRespThreshold = (int)env('NOTIFY_BADRESP_THRESHOLD', 5);
            $circuitSeconds = (int)env('NOTIFY_CIRCUIT_SECONDS', 300);

            if ($order->notify_times !== null && $order->notify_times >= 5) {
                OrderLogService::log(
                    $order->trace_id ?? '',
                    $order->platform_order_no,
                    $order->merchant_order_no,
                    '商户回调', 'WARN', '节点28-回调重试超限',
                    [
                        'action' => '回调重试超限',
                        'notify_times' => $order->notify_times,
                        'notify_url' => $order->notify_url
                    ],
                    '', ''
                );
                Log::warning('商户通知重试超限, 跳过通知', ['order_id'=>$order->id, 'notify_times'=>$order->notify_times]);
                return ['success' => false, 'message' => 'notify retry exceeded'];
            }

            if (empty($order->notify_url)) {
                Log::info('订单无通知地址，跳过商户通知', ['order_id' => $order->id]);
                return ['success' => false, 'message' => 'empty notify_url'];
            }

            // 简易熔断：连续超时/连续非期望响应达到阈值则熔断（人工回调除外）
            $merchantKey = (string)$order->merchant_id;
            $circuitKey = "notify:circuit:{$merchantKey}";
            $timeoutCntKey = "notify:timeout:cnt:{$merchantKey}";
            $badRespCntKey = "notify:badresp:cnt:{$merchantKey}";

            if (!$isManual) {
                $circuitUntil = (int)(Redis::get($circuitKey) ?: 0);
                if ($circuitUntil > time()) {
                    $remain = $circuitUntil - time();
                    Log::warning('商户通知熔断中，跳过自动回调', [
                        'merchant_id'=>$order->merchant_id,
                        'remain_seconds'=>$remain,
                        'circuit_key'=>$circuitKey
                    ]);
                    // 链路日志
                    try { \app\service\OrderLogService::log($order->trace_id ?? '', $order->platform_order_no, $order->merchant_order_no, '商户回调', 'WARN', '节点28-熔断跳过', [ 'merchant_id'=>$order->merchant_id, 'remain_seconds'=>$remain ], '', ''); } catch (\Throwable $e) {}
                    return ['success' => false, 'message' => 'circuit open, wait '.$remain.'s'];
                }
            }

            $notifyData = [
                'platform_order_no' => $order->platform_order_no,
                'merchant_order_no' => $order->merchant_order_no,
                'amount' => $order->order_amount,
                'pay_status' => Order::PAY_STATUS_PAID,
                'trade_no' => $notifyParams['trade_no'] ?? ($order->trade_no ?? ($order->alipay_order_no ?? '')),
                'paid_at' => $order->pay_time,
                'timestamp' => time(),
            ];
            
            // 获取签名字符串（使用SignatureHelper统一逻辑）
            $stringToSign = SignatureHelper::getStringToSign($notifyData, $order->merchant->api_secret, 'standard');
            
            // 记录签名前字符串
            Log::channel('notify')->info('商户回调签名前字符串', [
                'order_id' => $order->id,
                'platform_order_no' => $order->platform_order_no,
                'merchant_order_no' => $order->merchant_order_no,
                'string_to_sign' => $stringToSign,
                'api_secret_length' => strlen($order->merchant->api_secret)
            ]);
            
            $notifyData['sign'] = SignatureHelper::generate($notifyData, $order->merchant->api_secret, 'standard', 'md5');

            // 记录发送的通知数据，方便调试（使用notify日志通道）
            Log::channel('notify')->info('准备发送商户通知', [
                'order_id' => $order->id,
                'platform_order_no' => $order->platform_order_no,
                'merchant_order_no' => $order->merchant_order_no,
                'notify_url' => $order->notify_url,
                'notify_data' => $notifyData,
                'order_expire_time' => $order->expire_time,
                'order_pay_status' => $order->pay_status,
                'order_pay_time' => $order->pay_time
            ]);

            // 标记开始发送：保持 notify_status = FAILED/PENDING 到真正成功
            try {
                // 发送前记录请求信息（使用notify日志通道）
                // notify_data 字段记录的就是实际发送给商户的完整数据
                Log::channel('notify')->info('商户回调请求', [
                    'order_id' => $order->id,
                    'platform_order_no' => $order->platform_order_no,
                    'merchant_order_no' => $order->merchant_order_no,
                    'merchant_id' => $order->merchant_id,
                    'notify_url' => $order->notify_url,
                    'notify_data' => $notifyData, // 实际发送给商户的完整数据（与sendHttpNotify使用的数据一致）
                    'request_time' => date('Y-m-d H:i:s')
                ]);
                
                // 记录实际发送的数据（http_build_query处理后的格式）
                $actualPostData = http_build_query($notifyData);
                Log::channel('notify')->debug('商户回调实际发送数据', [
                    'order_id' => $order->id,
                    'platform_order_no' => $order->platform_order_no,
                    'post_data' => $actualPostData,
                    'notify_data_keys' => array_keys($notifyData),
                    'notify_data' => $notifyData
                ]);
                
                // 发送HTTP通知（使用$notifyData，与日志中的notify_data字段一致）
                $response = self::sendHttpNotify($order->notify_url, $notifyData);

                // 成功：回写 SUCCESS
                $order->notify_status = Order::NOTIFY_STATUS_SUCCESS;
                $order->notify_times = ($order->notify_times ?? 0) + 1;
                $order->notify_time = date('Y-m-d H:i:s');
                $order->save();

                // 成功则重置连续超时计数与熔断
                try {
                    Redis::del($timeoutCntKey);
                    Redis::del($badRespCntKey);
                    Redis::del($circuitKey);
                } catch (\Throwable $e) {
                    // 忽略缓存异常
                }

                // 记录成功回调的详细信息（使用notify日志通道）
                // notify_data 字段记录的就是实际发送给商户的完整数据
                Log::channel('notify')->info('商户回调成功', [
                    'order_id' => $order->id,
                    'platform_order_no' => $order->platform_order_no,
                    'merchant_order_no' => $order->merchant_order_no,
                    'merchant_id' => $order->merchant_id,
                    'notify_url' => $order->notify_url,
                    'notify_data' => $notifyData, // 实际发送给商户的完整数据（与sendHttpNotify使用的数据一致）
                    'response' => $response,
                    'notify_times' => $order->notify_times,
                    'notify_time' => $order->notify_time
                ]);

                return ['success' => true, 'message' => 'SUCCESS'];

            } catch (\Throwable $e) {
                // 失败：回写 FAILED 并自增次数
                $order->notify_status = Order::NOTIFY_STATUS_FAILED;
                $order->notify_times = ($order->notify_times ?? 0) + 1;
                $order->notify_time = date('Y-m-d H:i:s');
                $order->save();

                Log::channel('notify')->error('商户通知发送失败', [
                    'order_id' => $order->id,
                    'notify_url' => $order->notify_url,
                    'error' => $e->getMessage()
                ]);

                // 失败计数：区分“请求超时”与“非期望响应”，用于触发熔断
                // 依据异常消息/代码判断（curl 超时通常为 code=28 或消息包含 timeout）
                $messageLower = strtolower($e->getMessage());
                $isTimeout = (strpos($messageLower, 'timed out') !== false) || (strpos($messageLower, 'timeout') !== false) || ($e->getCode() === 28);
                if (!$isManual) {
                    try {
                        if ($isTimeout) {
                            $cnt = (int)(Redis::get($timeoutCntKey) ?: 0) + 1;
                            Redis::set($timeoutCntKey, (string)$cnt, 'EX', 3600); // 计数保留1小时
                            Log::warning('商户回调超时计数', ['merchant_id'=>$order->merchant_id, 'timeout_count'=>$cnt, 'threshold'=>$timeoutThreshold]);
                            if ($cnt >= $timeoutThreshold) {
                                // 熔断
                                Redis::set($circuitKey, (string)(time() + $circuitSeconds), 'EX', $circuitSeconds);
                                Redis::del($timeoutCntKey);
                                Redis::del($badRespCntKey);
                                Log::warning('商户系统超时累计触发熔断', [
                                    'merchant_id'=>$order->merchant_id,
                                    'threshold'=>$timeoutThreshold,
                                    'duration_seconds'=>$circuitSeconds
                                ]);
                                // 机器人推送与链路日志（3分钟内同一商户同一类型不重复发送）
                                try {
                                    $notifyKey = "notify:circuit:notify:{$merchantKey}:timeout";
                                    $notifySent = Redis::get($notifyKey);
                                    if (!$notifySent) {
                                        TelegramMessageQueueService::addMessage(
                                            '⚠️ 商户回调熔断触发-超时',
                                            "<b>[熔断触发-超时]</b>\n\n商户ID: {$order->merchant_id}\n连续超时≥{$timeoutThreshold}，熔断{$circuitSeconds}s\n订单: <code>{$order->platform_order_no}</code>",
                                            TelegramMessageQueue::PRIORITY_HIGH,
                                            'html'
                                        );
                                        // 标记已发送，3分钟内不重复
                                        Redis::set($notifyKey, '1', 'EX', 180);
                                    }
                                } catch (\Throwable $e) {
                                    Log::warning('商户回调熔断消息加入队列失败', ['error' => $e->getMessage()]);
                                }
                                try { \app\service\OrderLogService::log($order->trace_id ?? '', $order->platform_order_no, $order->merchant_order_no, '商户回调', 'WARN', '节点28-熔断开启', [ 'reason'=>'timeout', 'threshold'=>$timeoutThreshold, 'duration_seconds'=>$circuitSeconds ], '', ''); } catch (\Throwable $e) {}
                            }
                        } else {
                            $bad = (int)(Redis::get($badRespCntKey) ?: 0) + 1;
                            Redis::set($badRespCntKey, (string)$bad, 'EX', 3600);
                            Log::warning('商户回调非期望响应计数', ['merchant_id'=>$order->merchant_id, 'badresp_count'=>$bad, 'threshold'=>$badRespThreshold]);
                            if ($bad >= $badRespThreshold) {
                                Redis::set($circuitKey, (string)(time() + $circuitSeconds), 'EX', $circuitSeconds);
                                Redis::del($timeoutCntKey);
                                Redis::del($badRespCntKey);
                                Log::warning('商户系统非期望响应累计触发熔断', [
                                    'merchant_id'=>$order->merchant_id,
                                    'threshold'=>$badRespThreshold,
                                    'duration_seconds'=>$circuitSeconds
                                ]);
                                // 机器人推送与链路日志（3分钟内同一商户同一类型不重复发送）
                                try {
                                    $notifyKey = "notify:circuit:notify:{$merchantKey}:bad_response";
                                    $notifySent = Redis::get($notifyKey);
                                    if (!$notifySent) {
                                        TelegramMessageQueueService::addMessage(
                                            '⚠️ 商户回调熔断触发-非期望响应',
                                            "<b>[熔断触发-非期望响应]</b>\n\n商户ID: {$order->merchant_id}\n连续非SUCCESS/非200≥{$badRespThreshold}，熔断{$circuitSeconds}s\n订单: <code>{$order->platform_order_no}</code>",
                                            TelegramMessageQueue::PRIORITY_HIGH,
                                            'html'
                                        );
                                        // 标记已发送，3分钟内不重复
                                        Redis::set($notifyKey, '1', 'EX', 180);
                                    }
                                } catch (\Throwable $e) {
                                    Log::warning('商户回调熔断消息加入队列失败', ['error' => $e->getMessage()]);
                                }
                                try { \app\service\OrderLogService::log($order->trace_id ?? '', $order->platform_order_no, $order->merchant_order_no, '商户回调', 'WARN', '节点28-熔断开启', [ 'reason'=>'bad_response', 'threshold'=>$badRespThreshold, 'duration_seconds'=>$circuitSeconds ], '', ''); } catch (\Throwable $e) {}
                            }
                        }
                    } catch (\Throwable $ee) {
                        // 忽略缓存异常
                    }
                }
                return ['success' => false, 'message' => $e->getMessage()];
            }

        } catch (\Throwable $e) {
            Log::error('商户通知处理异常', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'exception: '.$e->getMessage()];
        }
    }

    // 签名统一由 SignatureHelper 生成

    /**
     * 发送HTTP通知
     * @param string $url 通知URL
     * @param array $data 通知数据
     * @return string 商户响应内容
     * @throws \Exception
     */
    private static function sendHttpNotify(string $url, array $data): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Payment-Notify/1.0');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("CURL错误: {$error}");
        }
        if ($httpCode !== 200) {
            // 记录详细的HTTP错误信息，包括响应内容
            $responsePreview = mb_substr((string)$response, 0, 200);
            Log::channel('notify')->warning('商户通知HTTP状态码错误', [
                'url' => $url,
                'http_code' => $httpCode,
                'response_preview' => $responsePreview,
                'notify_data_keys' => array_keys($data)
            ]);
            throw new \Exception("HTTP状态码错误: {$httpCode}" . ($responsePreview ? "，响应: {$responsePreview}" : ''));
        }
        if (strtoupper(trim((string)$response)) !== 'SUCCESS') {
            // 记录商户响应的详细信息，方便调试
            Log::channel('notify')->warning('商户通知响应非SUCCESS', [
                'url' => $url,
                'response' => $response,
                'notify_data' => $data,
                'platform_order_no' => $data['platform_order_no'] ?? '',
                'merchant_order_no' => $data['merchant_order_no'] ?? ''
            ]);
            throw new \Exception("商户响应错误: {$response}");
        }
        
        // 返回响应内容，供调用方记录日志
        return (string)$response;
    }
}



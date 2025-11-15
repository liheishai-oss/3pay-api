<?php

namespace app\service\alipay;

use Alipay\EasySDK\Kernel\Factory;
use app\common\constants\OrderConstants;
use Exception;
use support\Log;
use support\Redis;
use Webman\Event\Event;

/**
 * 支付宝支付服务类
 */
class AlipayPaymentService
{
    /**
     * 格式化并验证 time_expire 参数
     * @param string|null $expireTime 过期时间（格式：Y-m-d H:i:s）
     * @return string|null 格式化后的时间（格式：yyyy-MM-dd HH:mm:ss）或 null
     */
    private static function formatTimeExpire(?string $expireTime): ?string
    {
        if (empty($expireTime)) {
            return null;
        }
        
        // 尝试解析时间
        $timestamp = strtotime($expireTime);
        if ($timestamp === false) {
            Log::warning("订单过期时间格式错误", [
                'expire_time' => $expireTime
            ]);
            return null;
        }
        
        // 检查是否为未来时间
        if ($timestamp <= time()) {
            Log::warning("订单过期时间已过期", [
                'expire_time' => $expireTime,
                'current_time' => date('Y-m-d H:i:s')
            ]);
            return null;
        }
        
        // 格式化为支付宝要求的格式：yyyy-MM-dd HH:mm:ss
        $formatted = date('Y-m-d H:i:s', $timestamp);
        
        return $formatted;
    }
    
    /**
     * WAP支付
     * @param array $orderInfo 订单信息
     * @param array $paymentInfo 支付配置信息
     * @return string 支付URL
     * @throws Exception
     */
    public static function wapPay(array $orderInfo, array $paymentInfo): string
    {
        try {
            $config = AlipayConfig::getConfig($paymentInfo);
            
            // 格式化并验证 time_expire 参数
            $timeExpire = self::formatTimeExpire($orderInfo['order_expiry_time'] ?? null);
            
            $wapPayment = Factory::setOptions($config)
                ->payment()
                ->wap();
            
            // 只在有有效时间时才添加 time_expire 参数
            if ($timeExpire !== null) {
                $wapPayment->optional("time_expire", $timeExpire);
            }
            
            $wapPayment->optional("seller_id", $orderInfo['pid'] ?? '')
                ->optional("quit_url", $orderInfo['quit_url'] ?? '');
            
            // 如果有buyer_id，添加到可选参数（用于指定支付账号）
            if (!empty($orderInfo['buyer_id'])) {
                $wapPayment->optional("buyer_id", $orderInfo['buyer_id']);
                Log::info("WAP支付包含buyer_id", [
                    'order_number' => $orderInfo['payment_order_number'],
                    'buyer_id' => $orderInfo['buyer_id']
                ]);
            }
            
            $result = $wapPayment->pay(
                $orderInfo['remark'] ?? $orderInfo['product_title'],
                $orderInfo['payment_order_number'],
                $orderInfo['payment_amount'],
                $orderInfo['quit_url'] ?? '',
                $orderInfo['return_url'] ?? ''
            );
            
            Log::info("支付宝WAP支付创建成功", [
                'order_number' => $orderInfo['payment_order_number'],
                'amount' => $orderInfo['payment_amount'],
                'has_buyer_id' => !empty($orderInfo['buyer_id'])
            ]);
            
            return $result->body;
            
        } catch (Exception $e) {
            Log::error("支付宝WAP支付失败", [
                'order_number' => $orderInfo['payment_order_number'],
                'error' => $e->getMessage(),
                'has_buyer_id' => !empty($orderInfo['buyer_id'])
            ]);
            throw new Exception("WAP支付创建失败: " . $e->getMessage());
        }
    }
    
    /**
     * APP支付
     * @param array $orderInfo 订单信息
     * @param array $paymentInfo 支付配置信息
     * @return string 支付参数
     * @throws Exception
     */
    public static function appPay(array $orderInfo, array $paymentInfo): string
    {
        try {
            $config = AlipayConfig::getConfig($paymentInfo);
            
            // 格式化并验证 time_expire 参数
            $timeExpire = self::formatTimeExpire($orderInfo['order_expiry_time'] ?? null);
            
            $appPayment = Factory::setOptions($config)
                ->payment()
                ->app();
            
            // 只在有有效时间时才添加 time_expire 参数
            if ($timeExpire !== null) {
                $appPayment->optional("time_expire", $timeExpire);
            }
            
            $result = $appPayment->optional("seller_id", $orderInfo['pid'] ?? '')
                ->pay(
                    $orderInfo['remark'] ?? $orderInfo['product_title'],
                    $orderInfo['payment_order_number'],
                    $orderInfo['payment_amount']
                );
            
            Log::info("支付宝APP支付创建成功", [
                'order_number' => $orderInfo['payment_order_number'],
                'amount' => $orderInfo['payment_amount']
            ]);
            
            return $result->body;
            
        } catch (Exception $e) {
            Log::error("支付宝APP支付失败", [
                'order_number' => $orderInfo['payment_order_number'],
                'error' => $e->getMessage()
            ]);
            throw new Exception("APP支付创建失败: " . $e->getMessage());
        }
    }
    
    /**
     * PC网站支付
     * @param array $orderInfo 订单信息
     * @param array $paymentInfo 支付配置信息
     * @return string 支付表单HTML
     * @throws Exception
     */
    public static function pagePay(array $orderInfo, array $paymentInfo): string
    {
        try {
            $config = AlipayConfig::getConfig($paymentInfo);
            
            // 格式化并验证 time_expire 参数
            $timeExpire = self::formatTimeExpire($orderInfo['order_expiry_time'] ?? null);
            
            $pagePayment = Factory::setOptions($config)
                ->payment()
                ->page();
            
            // 只在有有效时间时才添加 time_expire 参数
            if ($timeExpire !== null) {
                $pagePayment->optional("time_expire", $timeExpire);
            }
            
            $result = $pagePayment->optional("seller_id", $orderInfo['pid'] ?? '')
                ->pay(
                    $orderInfo['remark'] ?? $orderInfo['product_title'],
                    $orderInfo['payment_order_number'],
                    $orderInfo['payment_amount']
                );
            
            Log::info("支付宝PC支付创建成功", [
                'order_number' => $orderInfo['payment_order_number'],
                'amount' => $orderInfo['payment_amount']
            ]);
            
            return $result->body;
            
        } catch (Exception $e) {
            Log::error("支付宝PC支付失败", [
                'order_number' => $orderInfo['payment_order_number'],
                'error' => $e->getMessage()
            ]);
            throw new Exception("PC支付创建失败: " . $e->getMessage());
        }
    }
    
    /**
     * 扫码支付
     * @param array $orderInfo 订单信息
     * @param array $paymentInfo 支付配置信息
     * @return string 二维码内容
     * @throws Exception
     */
    public static function qrPay(array $orderInfo, array $paymentInfo): string
    {
        try {
            $config = AlipayConfig::getConfig($paymentInfo);
            
            // 格式化并验证 time_expire 参数
            $timeExpire = self::formatTimeExpire($orderInfo['order_expiry_time'] ?? null);
            
            // 使用当面付预创建API生成二维码（PRECREATE）
            $faceToFacePayment = Factory::setOptions($config)
                ->payment()
                ->faceToFace();  // 当面付API
            
            // 只在有有效时间时才添加 time_expire 参数
            if ($timeExpire !== null) {
                $faceToFacePayment->optional("time_expire", $timeExpire);
                Log::info("扫码支付设置超时时间", [
                    'order_number' => $orderInfo['payment_order_number'],
                    'time_expire' => $timeExpire
                ]);
            } else {
                Log::warning("扫码支付未设置超时时间", [
                    'order_number' => $orderInfo['payment_order_number'],
                    'original_expire_time' => $orderInfo['order_expiry_time'] ?? 'empty'
                ]);
            }
            
            $result = $faceToFacePayment->optional("seller_id", $orderInfo['pid'] ?? '')
                ->precreate(  // 预创建（生成二维码）
                    $orderInfo['remark'] ?? $orderInfo['product_title'],
                    $orderInfo['payment_order_number'],
                    $orderInfo['payment_amount']
                );
            
            // 检查API调用是否成功（支付宝成功码为 10000）
            if ($result->code !== '10000') {
                $errorMsg = sprintf(
                    '支付宝扫码支付失败: [%s] %s - %s (%s)',
                    $result->code,
                    $result->msg,
                    $result->subMsg ?? '',
                    $result->subCode ?? ''
                );
                
                Log::error("支付宝扫码支付API调用失败", [
                    'order_number' => $orderInfo['payment_order_number'],
                    'code' => $result->code,
                    'msg' => $result->msg,
                    'sub_code' => $result->subCode ?? '',
                    'sub_msg' => $result->subMsg ?? ''
                ]);
                
                throw new Exception($errorMsg);
            }
            
            // 检查二维码是否存在
            if (empty($result->qrCode)) {
                Log::error("支付宝扫码支付返回的二维码为空", [
                    'order_number' => $orderInfo['payment_order_number'],
                    'result' => json_encode($result)
                ]);
                throw new Exception("支付宝返回的二维码为空");
            }
            
            Log::info("支付宝扫码支付创建成功", [
                'order_number' => $orderInfo['payment_order_number'],
                'amount' => $orderInfo['payment_amount'],
                'qr_code_length' => strlen($result->qrCode)
            ]);
            
            return $result->qrCode;
            
        } catch (Exception $e) {
            Log::error("支付宝扫码支付失败", [
                'order_number' => $orderInfo['payment_order_number'],
                'error' => $e->getMessage()
            ]);
            throw new Exception("扫码支付创建失败: " . $e->getMessage());
        }
    }
    
    /**
     * 条码支付（当面付）
     * @param array $orderInfo 订单信息
     * @param string $authCode 授权码
     * @param array $paymentInfo 支付配置信息
     * @return array 支付结果
     * @throws Exception
     */
    public static function barPay(array $orderInfo, string $authCode, array $paymentInfo): array
    {
        try {
            $config = AlipayConfig::getConfig($paymentInfo);
            
            // 格式化并验证 time_expire 参数
            $timeExpire = self::formatTimeExpire($orderInfo['order_expiry_time'] ?? null);
            
            $faceToFacePayment = Factory::setOptions($config)
                ->payment()
                ->faceToFace();
            
            // 只在有有效时间时才添加 time_expire 参数
            if ($timeExpire !== null) {
                $faceToFacePayment->optional("time_expire", $timeExpire);
            }
            
            $result = $faceToFacePayment->optional("seller_id", $orderInfo['pid'] ?? '')
                ->pay(
                    $orderInfo['remark'] ?? $orderInfo['product_title'],
                    $orderInfo['payment_order_number'],
                    $orderInfo['payment_amount'],
                    $authCode
                );
            
            Log::info("支付宝条码支付创建成功", [
                'order_number' => $orderInfo['payment_order_number'],
                'amount' => $orderInfo['payment_amount'],
                'auth_code' => $authCode
            ]);
            
            return json_decode($result->body, true);
            
        } catch (Exception $e) {
            Log::error("支付宝条码支付失败", [
                'order_number' => $orderInfo['payment_order_number'],
                'error' => $e->getMessage()
            ]);
            throw new Exception("条码支付创建失败: " . $e->getMessage());
        }
    }
    
    /**
     * 预授权支付
     * @param array $orderInfo 订单信息
     * @param array $paymentInfo 支付配置信息
     * @return string 预授权URL
     * @throws Exception
     */
    public static function preAuthPay(array $orderInfo, array $paymentInfo): string
    {
        try {
            $config = AlipayConfig::getConfig($paymentInfo);
            
            // 格式化并验证 time_expire 参数
            $timeExpire = self::formatTimeExpire($orderInfo['order_expiry_time'] ?? null);
            
            $preAuthPayment = Factory::setOptions($config)
                ->payment()
                ->preAuth();
            
            // 只在有有效时间时才添加 time_expire 参数
            if ($timeExpire !== null) {
                $preAuthPayment->optional("time_expire", $timeExpire);
            }
            
            $result = $preAuthPayment->optional("seller_id", $orderInfo['pid'] ?? '')
                ->pay(
                    $orderInfo['remark'] ?? $orderInfo['product_title'],
                    $orderInfo['payment_order_number'],
                    $orderInfo['payment_amount']
                );
            
            Log::info("支付宝预授权支付创建成功", [
                'order_number' => $orderInfo['payment_order_number'],
                'amount' => $orderInfo['payment_amount']
            ]);
            
            return $result->body;
            
        } catch (Exception $e) {
            Log::error("支付宝预授权支付失败", [
                'order_number' => $orderInfo['payment_order_number'],
                'error' => $e->getMessage()
            ]);
            throw new Exception("预授权支付创建失败: " . $e->getMessage());
        }
    }
}

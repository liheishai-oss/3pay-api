<?php

namespace app\service\alipay;

use Alipay\EasySDK\Kernel\Factory;
use Exception;
use support\Log;
use support\Redis;
use Webman\Event\Event;

/**
 * 支付宝OAuth服务类
 */
class AlipayOAuthService
{
    /**
     * 获取OAuth授权URL
     * @param array $params 授权参数
     * @param array $paymentInfo 支付配置信息
     * @return string 授权URL
     * @throws Exception
     */
    public static function getAuthUrl(array $params, array $paymentInfo): string
    {
        try {
            $config = AlipayConfig::getConfig($paymentInfo);
            
            $result = Factory::setOptions($config)
                ->base()
                ->oauth()
                ->getAuthUrl(
                    $params['redirect_uri'],
                    $params['scope'] ?? 'auth_user',
                    $params['state'] ?? ''
                );
            
            Log::info("支付宝OAuth授权URL生成成功", [
                'redirect_uri' => $params['redirect_uri'],
                'scope' => $params['scope'] ?? 'auth_user'
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            Log::error("支付宝OAuth授权URL生成失败", [
                'params' => $params,
                'error' => $e->getMessage()
            ]);
            throw new Exception("OAuth授权URL生成失败: " . $e->getMessage());
        }
    }
    
    /**
     * 通过授权码获取访问令牌
     * @param string $authCode 授权码
     * @param array $paymentInfo 支付配置信息
     * @return array 用户信息
     * @throws Exception
     */
    public static function getTokenByAuthCode(string $authCode, array $paymentInfo): array
    {
        try {
            Log::info("开始获取OAuth令牌", [
                'auth_code_length' => strlen($authCode),
                'auth_code_preview' => substr($authCode, 0, 20) . '...',
                'payment_info_keys' => array_keys($paymentInfo)
            ]);
            
            $config = AlipayConfig::getConfig($paymentInfo);
            
            Log::info("AlipayConfig获取成功", [
                'app_id' => $config->appId ?? 'not_set',
                'gateway_host' => $config->gatewayHost ?? 'not_set'
            ]);
            
            $result = Factory::setOptions($config)
                ->base()
                ->oauth()
                ->getToken($authCode);
            
            Log::info("Factory.getToken调用完成", [
                'result_type' => get_class($result),
                'result_properties' => get_object_vars($result)
            ]);
            
            // 统一使用body属性，如果不存在则尝试httpBody
            if (property_exists($result, 'body')) {
                $bodyContent = $result->body;
                Log::info("使用body属性");
            } elseif (property_exists($result, 'httpBody')) {
                $bodyContent = $result->httpBody;
                Log::info("使用httpBody属性（body不存在）");
            } else {
                Log::error("无法获取响应内容", [
                    'available_properties' => array_keys(get_object_vars($result))
                ]);
                throw new Exception("无法获取OAuth响应内容");
            }
            
            Log::info("原始响应内容", [
                'body_length' => strlen($bodyContent),
                'body_preview' => substr($bodyContent, 0, 200)
            ]);
            
            $response = json_decode($bodyContent, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("JSON解析失败", [
                    'json_error' => json_last_error_msg(),
                    'body' => $bodyContent
                ]);
                throw new Exception("JSON解析失败: " . json_last_error_msg());
            }
            
            Log::info("响应JSON解析成功", [
                'response_keys' => array_keys($response ?? [])
            ]);
            
            if (!isset($response['alipay_system_oauth_token_response'])) {
                Log::error("OAuth令牌响应格式错误", [
                    'response' => $response
                ]);
                throw new Exception("OAuth令牌响应格式错误，缺少alipay_system_oauth_token_response字段");
            }
            
            $tokenResponse = $response['alipay_system_oauth_token_response'];
            
            Log::info("tokenResponse内容", [
                'token_response_keys' => array_keys($tokenResponse),
                'has_user_id' => isset($tokenResponse['user_id']),
                'has_access_token' => isset($tokenResponse['access_token'])
            ]);
            
            // 直接获取user_id和access_token（成功响应没有code字段）
            $userId = $tokenResponse['user_id'] ?? '';
            $accessToken = $tokenResponse['access_token'] ?? '';
            
            if (empty($userId)) {
                Log::error("无法从OAuth响应中获取用户ID", [
                    'token_response' => $tokenResponse
                ]);
                throw new Exception("无法从OAuth响应中获取用户ID");
            }
            
            // 缓存访问令牌
            if (!empty($accessToken)) {
                $cacheKey = "alipay_oauth_token:" . $userId;
                Redis::setex($cacheKey, 3600, $accessToken); // 1小时过期
            }
            
            Log::info("支付宝OAuth令牌获取成功", [
                'user_id' => $userId,
                'has_access_token' => !empty($accessToken),
                'access_token_length' => strlen($accessToken)
            ]);
            
            return [
                'user_id' => $userId,
                'access_token' => $accessToken,
                'expires_in' => $tokenResponse['expires_in'] ?? 0,
                'refresh_token' => $tokenResponse['refresh_token'] ?? '',
                're_expires_in' => $tokenResponse['re_expires_in'] ?? 0,
            ];
            
        } catch (Exception $e) {
            Log::error("支付宝OAuth令牌获取失败", [
                'auth_code' => substr($authCode, 0, 20) . '...',
                'full_auth_code' => $authCode,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
    
    /**
     * 刷新访问令牌
     * @param string $refreshToken 刷新令牌
     * @param array $paymentInfo 支付配置信息
     * @return array 新的令牌信息
     * @throws Exception
     */
    public static function refreshToken(string $refreshToken, array $paymentInfo): array
    {
        try {
            $config = AlipayConfig::getConfig($paymentInfo);
            
            $result = Factory::setOptions($config)
                ->base()
                ->oauth()
                ->refreshToken($refreshToken);
            
            $response = json_decode($result->body, true);
            
            if (!isset($response['alipay_system_oauth_token_response'])) {
                throw new Exception("刷新令牌响应格式错误");
            }
            
            $tokenResponse = $response['alipay_system_oauth_token_response'];
            
            if ($tokenResponse['code'] !== '10000') {
                throw new Exception("刷新令牌失败: " . ($tokenResponse['msg'] ?? '未知错误'));
            }
            
            $userId = $tokenResponse['user_id'] ?? '';
            $accessToken = $tokenResponse['access_token'] ?? '';
            
            // 更新缓存
            if (!empty($accessToken) && !empty($userId)) {
                $cacheKey = "alipay_oauth_token:" . $userId;
                Redis::setex($cacheKey, 3600, $accessToken);
            }
            
            Log::info("支付宝OAuth令牌刷新成功", [
                'user_id' => $userId,
                'has_access_token' => !empty($accessToken)
            ]);
            
            return [
                'user_id' => $userId,
                'access_token' => $accessToken,
                'expires_in' => $tokenResponse['expires_in'] ?? 0,
                'refresh_token' => $tokenResponse['refresh_token'] ?? '',
                're_expires_in' => $tokenResponse['re_expires_in'] ?? 0,
            ];
            
        } catch (Exception $e) {
            Log::error("支付宝OAuth令牌刷新失败", [
                'refresh_token' => $refreshToken,
                'error' => $e->getMessage()
            ]);
            throw new Exception("OAuth令牌刷新失败: " . $e->getMessage());
        }
    }
    
    /**
     * 获取用户信息
     * @param string $accessToken 访问令牌
     * @param array $paymentInfo 支付配置信息
     * @return array 用户信息
     * @throws Exception
     */
    public static function getUserInfo(string $accessToken, array $paymentInfo): array
    {
        try {
            $config = AlipayConfig::getConfig($paymentInfo);
            
            $result = Factory::setOptions($config)
                ->base()
                ->oauth()
                ->getUserInfo($accessToken);
            
            $response = json_decode($result->body, true);
            
            if (!isset($response['alipay_user_info_share_response'])) {
                throw new Exception("用户信息响应格式错误");
            }
            
            $userResponse = $response['alipay_user_info_share_response'];
            
            if ($userResponse['code'] !== '10000') {
                throw new Exception("获取用户信息失败: " . ($userResponse['msg'] ?? '未知错误'));
            }
            
            Log::info("支付宝用户信息获取成功", [
                'user_id' => $userResponse['user_id'] ?? '',
                'nick_name' => $userResponse['nick_name'] ?? ''
            ]);
            
            return [
                'user_id' => $userResponse['user_id'] ?? '',
                'nick_name' => $userResponse['nick_name'] ?? '',
                'avatar' => $userResponse['avatar'] ?? '',
                'province' => $userResponse['province'] ?? '',
                'city' => $userResponse['city'] ?? '',
                'gender' => $userResponse['gender'] ?? '',
                'is_certified' => $userResponse['is_certified'] ?? '',
                'is_student_certified' => $userResponse['is_student_certified'] ?? '',
                'user_type' => $userResponse['user_type'] ?? '',
                'user_status' => $userResponse['user_status'] ?? '',
            ];
            
        } catch (Exception $e) {
            Log::error("支付宝用户信息获取失败", [
                'access_token' => $accessToken,
                'error' => $e->getMessage()
            ]);
            throw new Exception("用户信息获取失败: " . $e->getMessage());
        }
    }
    
    /**
     * 检查购买限制
     * @param string $userId 用户ID
     * @param int $maxNumber 最大购买次数
     * @param string $entityId 实体ID
     * @param string $orderNumber 订单号
     * @return bool 是否允许购买
     * @throws Exception
     */
    public static function checkBuyLimit(string $userId, int $maxNumber = 5, string $entityId = '', string $orderNumber = ''): bool
    {
        try {
            $key = "buy_user:{$userId}";
            $buyCount = Redis::get($key) ?: 0;
            
            if ($buyCount >= $maxNumber) {
                Log::warning("用户购买次数超出限制", [
                    'user_id' => $userId,
                    'buy_count' => $buyCount,
                    'max_number' => $maxNumber
                ]);
                return false;
            }
            
            // 触发购买限制检查事件
            Event::dispatch('order.limit.check', [
                'user_id' => $userId,
                'entity_id' => $entityId,
                'max_buy_number' => $maxNumber,
                'payment_order_number' => $orderNumber
            ]);
            
            return true;
            
        } catch (Exception $e) {
            Log::error("检查购买限制失败", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            throw new Exception("检查购买限制失败: " . $e->getMessage());
        }
    }
}

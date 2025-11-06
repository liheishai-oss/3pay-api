<?php

namespace app\api\controller\v1;

use support\Request;
use support\Response;
use app\service\alipay\AlipayOAuthService;
use app\service\payment\PaymentFactory;
use app\model\Subject;
use app\model\Product;
use support\Log;
use app\exception\MyBusinessException;

/**
 * OAuth授权控制器
 */
class OAuthController
{
    /**
     * 通过订单号获取支付宝OAuth授权URL（仅日志输出）
     * @param Request $request
     * @return Response
     */
    public function getAuthUrlByOrder(Request $request): Response
    {
        try {
            $params = $request->all();
            
            // 记录请求日志
            Log::info('OAuth授权请求', [
                'order_number' => $params['order_number'] ?? '未提供',
                'redirect_uri' => $params['redirect_uri'] ?? '未提供',
                'scope' => $params['scope'] ?? 'auth_user',
                'state' => $params['state'] ?? '未提供',
                'user_agent' => $request->header('user-agent', ''),
                'client_ip' => $request->getRealIp(),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            // 验证必要参数
            if (empty($params['order_number'])) {
                Log::warning('OAuth授权请求缺少订单号', ['params' => $params]);
                return $this->error('订单号不能为空');
            }
            
            if (empty($params['redirect_uri'])) {
                Log::warning('OAuth授权请求缺少回调地址', ['params' => $params]);
                return $this->error('回调地址不能为空');
            }
            
            // 通过订单号获取订单信息
            $order = \app\model\Order::where('platform_order_no', $params['order_number'])->first();
            if (!$order) {
                Log::warning('OAuth授权请求订单不存在', ['order_number' => $params['order_number']]);
                return $this->error('订单不存在');
            }
            
            // 记录订单信息
            Log::info('OAuth授权订单信息', [
                'order_id' => $order->id,
                'platform_order_no' => $order->platform_order_no,
                'merchant_order_no' => $order->merchant_order_no,
                'product_id' => $order->product_id,
                'subject_id' => $order->subject_id,
                'order_amount' => $order->order_amount,
                'pay_status' => $order->pay_status
            ]);
            
            // 获取产品信息
            $product = Product::where('id', $order->product_id)
                ->where('status', Product::STATUS_ENABLED)
                ->first();
            
            if (!$product) {
                Log::warning('OAuth授权产品不存在或已禁用', ['product_id' => $order->product_id]);
                return $this->error('产品不存在或已禁用');
            }
            
            // 获取支付主体
            $subject = \app\model\Subject::where('id', $order->subject_id)
                ->where('status', \app\model\Subject::STATUS_ENABLED)
                ->first();
            
            if (!$subject) {
                Log::warning('OAuth授权支付主体不存在或已禁用', ['subject_id' => $order->subject_id]);
                return $this->error('支付主体不存在或已禁用');
            }
            
            // 记录产品和主体信息
            Log::info('OAuth授权配置信息', [
                'product_code' => $product->product_code,
                'product_name' => $product->product_name,
                'subject_company_name' => $subject->company_name,
                'subject_alipay_app_id' => $subject->alipay_app_id
            ]);
            
            // 模拟生成授权URL（实际项目中这里会调用真实的OAuth服务）
            $mockAuthUrl = 'https://openauth.alipay.com/oauth2/publicAppAuthorize.htm?app_id=' . $subject->alipay_app_id . '&scope=auth_user&redirect_uri=' . urlencode($params['redirect_uri']) . '&state=' . urlencode($params['state'] ?? $order->platform_order_no);
            
            Log::info('OAuth授权URL生成完成', [
                'order_number' => $params['order_number'],
                'redirect_uri' => $params['redirect_uri'],
                'mock_auth_url' => $mockAuthUrl,
                'note' => '当前为模拟URL，实际项目中需要调用真实的OAuth服务'
            ]);
            
            return $this->success([
                'auth_url' => $mockAuthUrl,
                'order_number' => $order->platform_order_no,
                'redirect_uri' => $params['redirect_uri'],
                'note' => '当前为模拟授权URL，实际项目中需要调用真实的OAuth服务'
            ]);
            
        } catch (\Exception $e) {
            Log::error('OAuth授权URL生成异常', [
                'params' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->error('授权URL生成失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取支付宝OAuth授权URL（原方法保留）
     * @param Request $request
     * @return Response
     */
    public function getAuthUrl(Request $request): Response
    {
        try {
            $params = $request->all();
            
            // 验证必要参数
            if (empty($params['product_code'])) {
                return $this->error('产品代码不能为空');
            }
            
            if (empty($params['redirect_uri'])) {
                return $this->error('回调地址不能为空');
            }
            
            // 获取产品信息
            $product = Product::where('product_code', $params['product_code'])
                ->where('status', Product::STATUS_ENABLED)
                ->first();
            
            if (!$product) {
                return $this->error('产品不存在或已禁用');
            }
            
            // 获取支付主体
            $subject = PaymentFactory::findAvailableSubject($product->id, $params['agent_id'] ?? 1);
            if (!$subject) {
                return $this->error('没有可用的支付主体');
            }
            
            // 获取支付配置
            $paymentInfo = PaymentFactory::getPaymentConfig($subject, $product->paymentType);
            
            // 生成OAuth授权URL
            $authParams = [
                'redirect_uri' => $params['redirect_uri'],
                'scope' => $params['scope'] ?? 'auth_user',
                'state' => $params['state'] ?? ''
            ];
            
            $authUrl = AlipayOAuthService::getAuthUrl($authParams, $paymentInfo);
            
            Log::info('OAuth授权URL生成成功', [
                'product_code' => $params['product_code'],
                'redirect_uri' => $params['redirect_uri'],
                'auth_url' => $authUrl
            ]);
            
            return $this->success([
                'auth_url' => $authUrl,
                'product_code' => $params['product_code'],
                'redirect_uri' => $params['redirect_uri']
            ]);
            
        } catch (\Exception $e) {
            Log::error('OAuth授权URL生成失败', [
                'params' => $request->all(),
                'error' => $e->getMessage()
            ]);
            
            return $this->error('授权URL生成失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 处理OAuth回调，获取用户ID
     * @param Request $request
     * @return Response
     */
    public function callback(Request $request): Response
    {
        try {
            $params = $request->all();
            
            // 验证必要参数
            if (empty($params['auth_code'])) {
                return $this->error('授权码不能为空');
            }
            
            if (empty($params['product_code'])) {
                return $this->error('产品代码不能为空');
            }
            
            // 获取产品信息
            $product = Product::where('product_code', $params['product_code'])
                ->where('status', Product::STATUS_ENABLED)
                ->first();
            
            if (!$product) {
                return $this->error('产品不存在或已禁用');
            }
            
            // 获取支付主体
            $subject = PaymentFactory::findAvailableSubject($product->id, $params['agent_id'] ?? 1);
            if (!$subject) {
                return $this->error('没有可用的支付主体');
            }
            
            // 获取支付配置
            $paymentInfo = PaymentFactory::getPaymentConfig($subject, $product->paymentType);
            
            // 通过授权码获取用户信息
            $userInfo = AlipayOAuthService::getTokenByAuthCode($params['auth_code'], $paymentInfo);
            
            Log::info('OAuth回调处理成功', [
                'product_code' => $params['product_code'],
                'user_id' => $userInfo['user_id'],
                'has_access_token' => !empty($userInfo['access_token'])
            ]);
            
            return $this->success([
                'user_id' => $userInfo['user_id'],
                'access_token' => $userInfo['access_token'],
                'expires_in' => $userInfo['expires_in'],
                'refresh_token' => $userInfo['refresh_token'],
                're_expires_in' => $userInfo['re_expires_in']
            ]);
            
        } catch (\Exception $e) {
            Log::error('OAuth回调处理失败', [
                'params' => $request->all(),
                'error' => $e->getMessage()
            ]);
            
            return $this->error('OAuth回调处理失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取用户详细信息
     * @param Request $request
     * @return Response
     */
    public function getUserInfo(Request $request): Response
    {
        try {
            $params = $request->all();
            
            // 验证必要参数
            if (empty($params['user_id'])) {
                return $this->error('用户ID不能为空');
            }
            
            if (empty($params['product_code'])) {
                return $this->error('产品代码不能为空');
            }
            
            // 获取产品信息
            $product = Product::where('product_code', $params['product_code'])
                ->where('status', Product::STATUS_ENABLED)
                ->first();
            
            if (!$product) {
                return $this->error('产品不存在或已禁用');
            }
            
            // 获取支付主体
            $subject = PaymentFactory::findAvailableSubject($product->id, $params['agent_id'] ?? 1);
            if (!$subject) {
                return $this->error('没有可用的支付主体');
            }
            
            // 获取支付配置
            $paymentInfo = PaymentFactory::getPaymentConfig($subject, $product->paymentType);
            
            // 获取用户详细信息
            $userInfo = AlipayOAuthService::getUserInfo($params['user_id'], $paymentInfo);
            
            Log::info('用户信息获取成功', [
                'product_code' => $params['product_code'],
                'user_id' => $params['user_id']
            ]);
            
            return $this->success($userInfo);
            
        } catch (\Exception $e) {
            Log::error('用户信息获取失败', [
                'params' => $request->all(),
                'error' => $e->getMessage()
            ]);
            
            return $this->error('用户信息获取失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 刷新访问令牌
     * @param Request $request
     * @return Response
     */
    public function refreshToken(Request $request): Response
    {
        try {
            $params = $request->all();
            
            // 验证必要参数
            if (empty($params['refresh_token'])) {
                return $this->error('刷新令牌不能为空');
            }
            
            if (empty($params['product_code'])) {
                return $this->error('产品代码不能为空');
            }
            
            // 获取产品信息
            $product = Product::where('product_code', $params['product_code'])
                ->where('status', Product::STATUS_ENABLED)
                ->first();
            
            if (!$product) {
                return $this->error('产品不存在或已禁用');
            }
            
            // 获取支付主体
            $subject = PaymentFactory::findAvailableSubject($product->id, $params['agent_id'] ?? 1);
            if (!$subject) {
                return $this->error('没有可用的支付主体');
            }
            
            // 获取支付配置
            $paymentInfo = PaymentFactory::getPaymentConfig($subject, $product->paymentType);
            
            // 刷新令牌
            $tokenInfo = AlipayOAuthService::refreshToken($params['refresh_token'], $paymentInfo);
            
            Log::info('令牌刷新成功', [
                'product_code' => $params['product_code'],
                'user_id' => $tokenInfo['user_id']
            ]);
            
            return $this->success($tokenInfo);
            
        } catch (\Exception $e) {
            Log::error('令牌刷新失败', [
                'params' => $request->all(),
                'error' => $e->getMessage()
            ]);
            
            return $this->error('令牌刷新失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 成功响应
     * @param mixed $data
     * @param string $message
     * @return Response
     */
    private function success($data = null, string $message = 'success'): Response
    {
        return json([
            'code' => 0,
            'msg' => $message,
            'data' => $data
        ]);
    }
    
    /**
     * 错误响应
     * @param string $message
     * @param int $code
     * @return Response
     */
    private function error(string $message, int $code = 1): Response
    {
        return json([
            'code' => $code,
            'msg' => $message,
            'data' => null
        ]);
    }
}

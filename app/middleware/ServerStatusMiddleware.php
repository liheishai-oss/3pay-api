<?php

namespace app\middleware;

use app\service\ServerManagementService;
use support\Request;
use support\Response;

/**
 * 服务器状态检查中间件
 * 检查当前服务器是否应该处理请求
 */
class ServerStatusMiddleware
{
    private ServerManagementService $serverService;
    
    public function __construct()
    {
        $this->serverService = new ServerManagementService();
    }
    
    /**
     * 处理请求
     * @param Request $request
     * @param callable $next
     * @return Response
     */
    public function process(Request $request, callable $next)
    {
        // 排除服务器管理相关的路由，避免管理员无法管理服务器状态
        $excludePaths = [
            '/api/v1/admin/server',
            '/api/v1/admin/maintenance',
            '/api/v1/admin/login',
            '/api/v1/admin/system',
            '/api/v1/admin/merchant-callback-monitor'
        ];
        
        $currentPath = $request->path();
        foreach ($excludePaths as $excludePath) {
            if (strpos($currentPath, $excludePath) === 0) {
                return $next($request);
            }
        }
        
        // 检查当前服务器是否应该处理请求
        if (!$this->serverService->shouldProcessRequest()) {
            $serverStatus = $this->serverService->getServerStatus();
            
            // 根据服务器状态返回不同的响应
            if ($serverStatus['status'] === 'disabled') {
                return $this->getDisabledResponse($request, $serverStatus['maintenance_message']);
            } elseif ($serverStatus['status'] === 'testing') {
                return $this->getTestingResponse($request);
            } else {
                return $this->getServiceUnavailableResponse($request);
            }
        }
        
        return $next($request);
    }
    
    /**
     * 获取停用状态响应
     * @param Request $request
     * @param string $message
     * @return Response
     */
    private function getDisabledResponse(Request $request, string $message)
    {
        if ($request->isAjax() || $request->header('Accept') === 'application/json') {
            // API请求返回JSON
            return json([
                'code' => 503,
                'status' => false,
                'message' => $message ?: '服务器已停用，请稍后再试',
                'data' => null
            ], 503);
        } else {
            // 页面请求返回HTML
            return response($this->getDisabledPage($message), 503);
        }
    }
    
    /**
     * 获取测试状态响应
     * @param Request $request
     * @return Response
     */
    private function getTestingResponse(Request $request)
    {
        if ($request->isAjax() || $request->header('Accept') === 'application/json') {
            return json([
                'code' => 503,
                'status' => false,
                'message' => '服务器测试中，请稍后再试',
                'data' => null
            ], 503);
        } else {
            return response($this->getTestingPage(), 503);
        }
    }
    
    /**
     * 获取服务不可用响应
     * @param Request $request
     * @return Response
     */
    private function getServiceUnavailableResponse(Request $request)
    {
        if ($request->isAjax() || $request->header('Accept') === 'application/json') {
            return json([
                'code' => 503,
                'status' => false,
                'message' => '服务暂时不可用',
                'data' => null
            ], 503);
        } else {
            return response($this->getServiceUnavailablePage(), 503);
        }
    }
    
    /**
     * 生成停用页面HTML
     * @param string $message
     * @return string
     */
    private function getDisabledPage(string $message): string
    {
        return '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>服务器已停用</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .maintenance-container {
            text-align: center;
            background: rgba(255, 255, 255, 0.95);
            padding: 60px 40px;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            max-width: 500px;
            width: 90%;
        }
        .maintenance-icon {
            font-size: 80px;
            margin-bottom: 30px;
            color: #667eea;
        }
        .maintenance-title {
            font-size: 32px;
            font-weight: 600;
            color: #333;
            margin-bottom: 20px;
        }
        .maintenance-message {
            font-size: 18px;
            color: #666;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        .maintenance-time {
            font-size: 14px;
            color: #999;
            margin-top: 20px;
        }
        .refresh-btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 25px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .refresh-btn:hover {
            background: #5a6fd8;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="maintenance-container">
        <div class="maintenance-icon">🔧</div>
        <h1 class="maintenance-title">服务器已停用</h1>
        <p class="maintenance-message">' . htmlspecialchars($message) . '</p>
        <button class="refresh-btn" onclick="location.reload()">刷新页面</button>
        <div class="maintenance-time">停用时间：' . date('Y-m-d H:i:s') . '</div>
    </div>
</body>
</html>';
    }
    
    /**
     * 生成测试页面HTML
     * @return string
     */
    private function getTestingPage(): string
    {
        return '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>服务器测试中</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .offline-container {
            text-align: center;
            background: rgba(255, 255, 255, 0.95);
            padding: 60px 40px;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            max-width: 500px;
            width: 90%;
        }
        .offline-icon {
            font-size: 80px;
            margin-bottom: 30px;
            color: #ff6b6b;
        }
        .offline-title {
            font-size: 32px;
            font-weight: 600;
            color: #333;
            margin-bottom: 20px;
        }
        .offline-message {
            font-size: 18px;
            color: #666;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        .offline-time {
            font-size: 14px;
            color: #999;
            margin-top: 20px;
        }
        .refresh-btn {
            background: #ff6b6b;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 25px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .refresh-btn:hover {
            background: #ff5252;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="offline-container">
        <div class="offline-icon">⚠️</div>
        <h1 class="offline-title">服务器测试中</h1>
        <p class="offline-message">当前服务器正在测试中，请稍后再试</p>
        <button class="refresh-btn" onclick="location.reload()">刷新页面</button>
        <div class="offline-time">测试时间：' . date('Y-m-d H:i:s') . '</div>
    </div>
</body>
</html>';
    }
    
    /**
     * 生成服务不可用页面HTML
     * @return string
     */
    private function getServiceUnavailablePage(): string
    {
        return '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>服务不可用</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #ffa726 0%, #ff9800 100%);
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .unavailable-container {
            text-align: center;
            background: rgba(255, 255, 255, 0.95);
            padding: 60px 40px;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            max-width: 500px;
            width: 90%;
        }
        .unavailable-icon {
            font-size: 80px;
            margin-bottom: 30px;
            color: #ffa726;
        }
        .unavailable-title {
            font-size: 32px;
            font-weight: 600;
            color: #333;
            margin-bottom: 20px;
        }
        .unavailable-message {
            font-size: 18px;
            color: #666;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        .unavailable-time {
            font-size: 14px;
            color: #999;
            margin-top: 20px;
        }
        .refresh-btn {
            background: #ffa726;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 25px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .refresh-btn:hover {
            background: #ff9800;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="unavailable-container">
        <div class="unavailable-icon">🚫</div>
        <h1 class="unavailable-title">服务不可用</h1>
        <p class="unavailable-message">当前服务暂时不可用，请稍后再试</p>
        <button class="refresh-btn" onclick="location.reload()">刷新页面</button>
        <div class="unavailable-time">时间：' . date('Y-m-d H:i:s') . '</div>
    </div>
</body>
</html>';
    }
}

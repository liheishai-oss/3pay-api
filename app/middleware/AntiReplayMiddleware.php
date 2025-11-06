<?php

namespace app\middleware;

use Exception;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;
use app\exception\MyBusinessException;
use support\Redis;

class AntiReplayMiddleware implements MiddlewareInterface
{
    /**
     * 处理防重放攻击
     */
    public function process(Request $request, callable $handler): Response
    {
        // 检查是否需要防重放验证
        if (!$this->needAntiReplayValidation($request)) {
            return $handler($request);
        }
        
        $data = $request->all();
        
        // 移除nonce检查，不再需要防重放验证

        return $handler($request);
    }
    
    /**
     * 检查是否需要防重放验证
     */
    private function needAntiReplayValidation(Request $request): bool
    {
        $controllerClass = $request->controller;
        $action = $request->action;
        
        if (!$controllerClass || !$action) {
            return false;
        }
        
        // 需要防重放验证的接口
        $needAntiReplay = [
            'app\\api\\controller\\v1\\order\\CreateController' => ['create'],
            'app\\api\\controller\\v1\\order\\QueryController' => ['query'],
            'app\\api\\controller\\v1\\merchant\\BalanceController' => ['query']
        ];
        
        return isset($needAntiReplay[$controllerClass]) && 
               in_array($action, $needAntiReplay[$controllerClass]);
    }
}
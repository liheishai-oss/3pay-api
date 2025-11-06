<?php

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class TestMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        \support\Log::info('TestMiddleware 被调用', [
            'uri' => $request->uri(),
            'method' => $request->method()
        ]);
        
        return $handler($request);
    }
}

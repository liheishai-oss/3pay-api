<?php
namespace app\middleware;

use Webman\Http\Response;
use Webman\Http\Request;

class CrossDomain
{
    public function process(Request $request, callable $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // 设置允许跨域的 Origin
        $origin = $request->header('origin', '*');

        $response->withHeaders([
            'Access-Control-Allow-Origin'      => $origin,
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Methods'     => 'GET,POST,PUT,DELETE,OPTIONS',
            'Access-Control-Allow-Headers'     => 'Content-Type,Authorization,X-Requested-With',
            'Access-Control-Expose-Headers'    => 'Authorization,Content-Disposition,X-Custom-Header', // 新增部分
            'Access-Control-Max-Age'           => '86400',
        ]);

        // 如果是预检请求，直接返回 204 无内容
        if ($request->method() === 'OPTIONS') {
            return response('', 204, $response->getHeaders());
        }

        return $response;
    }
}

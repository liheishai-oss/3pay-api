<?php

namespace app\common\helpers;

use Ramsey\Uuid\Uuid;
use support\Context;
use support\Request;

class TraceIdHelper
{
    protected const TRACE_ID_KEY = 'trace_id';

    public static function get(?Request $request = null): string
    {
        // 优先从协程上下文中取
        $traceId = Context::get(self::TRACE_ID_KEY);
        if ($traceId) {
            return $traceId;
        }

        // 如果没有传入请求对象，则使用全局request()
        $request = $request ?: request();

        // 取请求参数中的 trace_id 或者生成一个新UUID
        $traceId = $request->get('trace_id') ?: Uuid::uuid4()->toString();

        // 缓存到协程上下文，避免同协程多次生成
        Context::set(self::TRACE_ID_KEY, $traceId);

        return $traceId;
    }
}
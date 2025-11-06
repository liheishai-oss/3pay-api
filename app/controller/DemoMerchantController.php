<?php

namespace app\controller;

use support\Request;
use support\Log;

class DemoMerchantController
{
    /**
     * 模拟商户回调接收端
     * - 记录收到的通知参数与IP/UA
     * - 返回纯文本 SUCCESS 以表示处理成功
     */
    public function notify(Request $request)
    {
        $params = array_merge($request->post(), $request->get());

        Log::info('【DEMO-商户回调】收到平台通知', [
            'ip' => $request->getRealIp(),
            'ua' => $request->header('user-agent', ''),
            'params' => $params,
        ]);

        // 这里可根据签名等做校验与幂等处理；演示直接返回 SUCCESS
        return response('SUCCESS');
    }
}






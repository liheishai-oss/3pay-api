<?php

namespace app\enums\log;

class LogReason
{
    const ROUTE_ALIPAY_ORDER_CREATE =  '/v1/alipay/order/create';
    const ROUTE_ALIPAY_ORDER_PAYMENT_PAGE = '/v1/alipay/order/payment-page';

    public static function getSceneStageByRoute($route): array
    {
        return match ($route) {
            self::ROUTE_ALIPAY_ORDER_CREATE => ['scene' => '订单', 'stage' => '创建'],
            self::ROUTE_ALIPAY_ORDER_PAYMENT_PAGE => ['scene' => '订单', 'stage' => '待拉起订单详情'],
            default => ['scene' => '未知', 'stage' => '未知'],
        };
    }
}
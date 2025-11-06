<?php

namespace app\common;

/**
 * 统一列出受支持的Telegram指令（便于集中维护与扩展）
 */
enum TelegramCommandEnum: string
{
    // 供应商/商户绑定
    case BIND_SUPPLIER = '/绑定供应商=';
    case BIND_MERCHANT = '/绑定商户=';

    // 订单/财务相关
    case ORDER_QUERY = '/查单';
    case PREPAY = '/预付';
    case BALANCE = '/余额';
    case SETTLEMENT = '/结算';
    case SUCCESS_RATE_A = '/查成率';
    case SUCCESS_RATE_B = '/成功率';

    // 帮助
    case HELP_A = '/帮助';
    case HELP_B = '/命令';
    case HELP_C = '/help';

    /**
     * 是否为受支持的指令（前缀匹配）
     */
    public static function isKnown(string $messageText): bool
    {
        $text = trim($messageText);
        foreach (self::cases() as $case) {
            if (str_starts_with($text, $case->value)) {
                return true;
            }
        }
        return false;
    }
}




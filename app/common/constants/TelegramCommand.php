<?php

namespace app\common\constants;

class TelegramCommand
{
    // 三方命令
    public const THIRD_PARTY = [
        '查余额',
        '帮助',
        '预付',   // 开头匹配
        '下发',   // 开头匹配
        '查成率',
        '结算',
    ];

    // 技术命令
    public const TECH = [
        '查余额',
        '帮助',
        '预付',   // 开头匹配
        '下发',   // 开头匹配
        '查成率',
        '结算',
        '查异常',
    ];
}
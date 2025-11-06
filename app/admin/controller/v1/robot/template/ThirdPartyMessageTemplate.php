<?php

namespace app\admin\controller\v1\robot\template;

class ThirdPartyMessageTemplate
{
    public static function successRate(array $message): string
    {
        $firstName     = isset($message['first_name']) ? $message['first_name'] : '';       // 改为 first_name
        $totalCount    = isset($message['total_count']) ? $message['total_count'] : 0;
        $successCount  = isset($message['success_count']) ? $message['success_count'] : 0;
        $successRate   = isset($message['success_rate']) ? $message['success_rate'] : 0;

        $text = "📈 <b>成功率查询结果</b>\n\n";
        $text .= "操作人: {$firstName}\n";
        $text .= "总订单数: {$totalCount}\n";
        $text .= "成功订单数: {$successCount}\n";
        $text .= "成功率: {$successRate}%\n";
        $text .= "🕒 时间: " . date('Y-m-d H:i:s');

        return $text;
    }

    /**
     * 生成预付操作消息文本
     *
     * @param array $message 消息信息 ['first_name','message_text','balance_after','amount','group_id']
     * @return string
     */
    public static function prepay(array $message): string
    {
        $amount = $message['amount'] ?? 0;
        $balance = $message['balance_after'] ?? 0;

        $text = "💰 预付操作完成(加款)\n";
        $text .= "操作员工: " . ($message['first_name'] ?? '') . "\n";
        $text .= "原始金额: {$amount}\n";
        $text .= "变更金额: {$amount}\n";
        $text .= "剩余金额: {$balance}\n";
        $text .= "备       注: " . ($message['message_text'] ?? '') . "\n";
        $text .= "时       间: " . date('Y-m-d H:i:s');

        return $text;
    }

    /**
     * 生成帮助信息模板
     */
    public static function help(): string
    {
        $text = "📌 <b>三方支付操作帮助</b>\n\n";
        $text .= "• 💰 <b>预付</b>  - 预付100元\n";
        $text .= "• 💸 <b>下发</b>   - 下发50元（扣款）\n";
        $text .= "• 📊 <b>查余额</b>    - 查看当前余额\n";
        $text .= "• ✅ <b>查成率</b>    - 查看成功率\n";
        $text .= "• 🧾 <b>结算</b>      - 结算记录\n";
        $text .= "• 🧾 <b>帮助</b>      - 帮助 命令 help\n";
//        $text .= "• ⚠️ <b>查异常</b>    - 查看异常记录（仅技术群）\n\n";
        $text .= "🕒 时间: " . date('Y-m-d H:i:s') . "\n";
        $text .= "💡 使用示例: 直接发送命令，例如 '预付 100'";

        return $text;
    }

    /**
     * 生成余额查询消息模板
     *
     * @param array $message ['first_name','balance','remark']
     * @return string
     */
    public static function balance(array $message): string
    {
        $balance = $message['balance'] ?? 0;
        $username = $message['first_name'] ?? '';

        $text = "💰 <b>余额查询结果</b>\n\n";
        $text .= "查询人员: {$username}\n";
        $text .= "当日跑量: {$balance}\n";
        $text .= "应得余额: {$balance}\n";
        $text .= "当前预付: {$balance}\n";
        $text .= "剩余预付: {$balance}\n";
        $text .= "统计时间: " . date('Y-m-d H:i:s');

        return $text;
    }
    /**
     * 生成下发操作消息文本（扣款）
     *
     * @param array $message 消息信息 ['first_name','message_text','balance_after','amount','group_id']
     * @return string
     */
    public static function payout(array $message): string
    {
        $amount = $message['amount'] ?? 0;
        $balance = $message['balance_after'] ?? 0;

        $text = "💸 下发操作完成（扣款）\n";
        $text .= "操作人: " . ($message['first_name'] ?? '') . "\n";
        $text .= "金额: -" . $amount . "\n"; // 负号表示扣款
        $text .= "余额: {$balance}\n";
        $text .= "备注: " . ($message['message_text'] ?? '') . "\n";
        $text .= "时间: " . date('Y-m-d H:i:s');

        return $text;
    }
    /**
     * 生成结算操作模板（完整版）
     *
     * @param array $message [
     *     'first_name',      // 操作人
     *     'total_count',     // 总订单数
     *     'success_count',   // 成功订单数
     *     'success_rate',    // 成功率（百分比）
     *     'consumed_amount', // 本次消耗金额
     *     'balance_after',   // 剩余金额
     *     'group_id'
     * ]
     * @return string
     */
    public static function settlement(array $message): string
    {
        $firstName      = $message['first_name'] ?? '';
        $totalCount     = $message['total_count'] ?? 0;
        $successCount   = $message['success_count'] ?? 0;
        $successRate    = $message['success_rate'] ?? 0;
        $consumedAmount = $message['consumed_amount'] ?? 0;
        $balance        = $message['balance_after'] ?? 0;

        $text = "📊 <b>结算结果</b>\n\n";
        $text .= "操作人: {$firstName}\n";
        $text .= "总订单数: {$totalCount}\n";
        $text .= "成功订单数: {$successCount}\n";
        $text .= "成功率: {$successRate}%\n";
        $text .= "消耗金额: {$consumedAmount}\n";
        $text .= "剩余金额: {$balance}\n";
        $text .= "🕒 时间: " . date('Y-m-d H:i:s');

        return $text;
    }

}

<?php

namespace app\common\helpers;

    class MoneyHelper
{
    /**
     * 将金额从元转换为分（整数）
     *
     * @param string|float|int $amount 金额（单位：元）
     * @return int 金额（单位：分）
     */
    public static function convertToCents($amount): int
    {
        if (!is_numeric($amount)) {
            throw new \InvalidArgumentException('支付金额格式错误');
        }
        return (int) bcmul((string) $amount, '100', 0);
    }
    /**
     * 将金额从分转换为元（字符串，保留2位小数）
     *
     * @param int|string $amount 金额（单位：分）
     * @return string 金额（单位：元），如 "12.34"
     */
    public static function convertToYuan($amount): string
    {
        if (!is_numeric($amount)) {
            throw new \InvalidArgumentException('金额格式错误');
        }

        return bcdiv((string) $amount, '100', 2);
    }

    /**
     * 计算扣除手续费后的商户实际收入
     *
     * @param int $amount 金额（单位：分）
     * @param float $feeRate 手续费率（如 0.006）
     * @return string 商户实际收入（保留 2 位小数）
     */
    public static function calculateMerchantEarnings(int $amount, float $feeRate): string
    {
        $feeAmount = bcmul((string) $amount, (string) $feeRate, 10);
        $feeAmountInCents = bcdiv($feeAmount, '1', 2); // 保留 2 位小数
        return bcsub((string) $amount, $feeAmountInCents, 2);
    }

}

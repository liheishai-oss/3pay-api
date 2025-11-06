<?php

namespace app\common\constants;

/**
 * 分账相关常量
 */
class RoyaltyConstants
{
    /**
     * 需要自动关闭分账主体的错误码列表
     * 
     * 当分账接口返回这些错误码时，系统会自动禁用该分账主体，避免重复失败
     * 
     * 错误码说明：
     * 
     * === 账户限制类 ===
     * - BLOCK_USER_FORBBIDEN_RECIEVE: 用户被限制收款（账户被支付宝限制，禁止收款）
     * - BLOCK_USER_FORBBIDEN_SEND: 用户被限制付款（账户被支付宝限制，禁止付款）
     * - NO_ACCOUNT_USER_FORBBIDEN_RECIEVE: 无账户用户禁止收款（用户未开通支付宝账户或账户异常）
     * - ACQ.USER_ACCOUNT_HAD_FREEZEN: 用户账户已冻结（支付宝账户被冻结）
     * - USER_RISK_FREEZE: 用户风险冻结（因风险问题账户被冻结）
     * - JUDICIAL_FREEZE: 司法冻结（账户被司法部门冻结）
     * 
     * === 限额类 ===
     * - EXCEED_LIMIT_DM_AMOUNT: 超出单笔限额（Direct Money，单笔交易金额超限）
     * - EXCEED_LIMIT_DM_MAX_AMOUNT: 超出单笔最大限额（单笔交易最大金额超限）
     * - EXCEED_LIMIT_MM_AMOUNT: 超出月度限额（Monthly Max，月度交易金额超限）
     * - EXCEED_LIMIT_MM_MAX_AMOUNT: 超出月度最大限额（月度交易最大金额超限）
     * - PERM_PAY_CUSTOMER_DAILY_QUOTA_ORG_BALANCE_LIMIT: 客户每日额度/组织余额限制（每日额度或机构余额不足）
     * 
     * === 支付工具类 ===
     * - MONEY_PAY_CLOSED: 余额支付关闭（用户关闭了余额支付功能）
     * - NO_AVAILABLE_PAYMENT_TOOLS: 无可用支付工具（账户无可用支付方式）
     * - PAYCARD_UNABLE_PAYMENT: 支付账户无法进行分账操作（常见原因：账户类型不支持、账户被限制等）
     * 
     * === 权限类 ===
     * - PERMIT_CHECK_PERM_IDENTITY_THEFT: 权限检查：身份盗用（系统检测到身份盗用风险）
     * - PERMIT_CHECK_PERM_LIMITED: 权限检查：权限受限（账户权限受限，无法完成交易）
     * - PERMIT_NON_BANK_LIMIT_PAYEE: 权限：非银行限额收款人（收款方为非银行账户且限额受限）
     * 
     * === 状态类 ===
     * - PAYER_STATUS_ERROR: 付款人状态错误（付款人账户状态异常）
     * - SECURITY_CHECK_FAILED: 安全检查失败（支付宝风控系统拦截）
     * 
     * 注意：添加新的错误码时，需要同时更新错误码说明
     * 
     * @var array<string>
     */
    const SUBJECT_DISABLE_ERROR_CODES = [
        // 账户限制类
        'BLOCK_USER_FORBBIDEN_RECIEVE',        // 用户被限制收款
        'BLOCK_USER_FORBBIDEN_SEND',           // 用户被限制付款
        'NO_ACCOUNT_USER_FORBBIDEN_RECIEVE',   // 无账户用户禁止收款
        'ACQ.USER_ACCOUNT_HAD_FREEZEN',        // 用户账户已冻结
        'USER_RISK_FREEZE',                    // 用户风险冻结
        'JUDICIAL_FREEZE',                     // 司法冻结
        
        // 限额类
        'EXCEED_LIMIT_DM_AMOUNT',              // 超出单笔限额
        'EXCEED_LIMIT_DM_MAX_AMOUNT',          // 超出单笔最大限额
        'EXCEED_LIMIT_MM_AMOUNT',              // 超出月度限额
        'EXCEED_LIMIT_MM_MAX_AMOUNT',          // 超出月度最大限额
        'PERM_PAY_CUSTOMER_DAILY_QUOTA_ORG_BALANCE_LIMIT', // 每日额度/余额限制
        
        // 支付工具类
        'MONEY_PAY_CLOSED',                    // 余额支付关闭
        'NO_AVAILABLE_PAYMENT_TOOLS',          // 无可用支付工具
        'PAYCARD_UNABLE_PAYMENT',              // 支付账户无法进行分账操作
        
        // 权限类
        'PERMIT_CHECK_PERM_IDENTITY_THEFT',    // 权限检查：身份盗用
        'PERMIT_CHECK_PERM_LIMITED',           // 权限检查：权限受限
        'PERMIT_NON_BANK_LIMIT_PAYEE',         // 权限：非银行限额收款人
        
        // 状态类
        'PAYER_STATUS_ERROR',                  // 付款人状态错误
        'SECURITY_CHECK_FAILED',               // 安全检查失败
    ];
    
    /**
     * 检查错误码是否需要关闭分账主体
     * 
     * @param string|null $errorCode 错误码（sub_code）
     * @param string|null $errorMessage 错误消息（用于从消息中提取错误码）
     * @return bool
     */
    public static function shouldDisableSubject(?string $errorCode = null, ?string $errorMessage = null): bool
    {
        // 1. 直接匹配错误码
        if ($errorCode && in_array($errorCode, self::SUBJECT_DISABLE_ERROR_CODES, true)) {
            return true;
        }
        
        // 2. 从错误消息中检查
        if ($errorMessage) {
            foreach (self::SUBJECT_DISABLE_ERROR_CODES as $code) {
                // 检查是否包含错误码（不区分大小写）
                if (stripos($errorMessage, $code) !== false) {
                    return true;
                }
                // 检查是否包含带方括号标记的错误码（如 [PAYCARD_UNABLE_PAYMENT]）
                if (stripos($errorMessage, '[' . $code . ']') !== false) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * 获取需要关闭主体的错误码列表（只读）
     * 
     * @return array<string>
     */
    public static function getDisableErrorCodes(): array
    {
        return self::SUBJECT_DISABLE_ERROR_CODES;
    }
    
    /**
     * 从错误消息中提取错误码
     * 
     * @param string $errorMessage 错误消息
     * @return string|null 提取的错误码，未找到则返回null
     */
    public static function extractErrorCode(string $errorMessage): ?string
    {
        // 1. 尝试匹配带方括号的标记（如 [PAYCARD_UNABLE_PAYMENT]）
        if (preg_match('/\[([A-Z_]+)\]/', $errorMessage, $matches)) {
            return $matches[1];
        }
        
        // 2. 尝试匹配 "子错误码: XXX" 格式
        if (preg_match('/子错误码[:\s]+([A-Z_]+)/i', $errorMessage, $matches)) {
            return $matches[1];
        }
        
        // 3. 在消息中查找是否包含已知的错误码
        foreach (self::SUBJECT_DISABLE_ERROR_CODES as $code) {
            if (stripos($errorMessage, $code) !== false) {
                return $code;
            }
        }
        
        return null;
    }
}


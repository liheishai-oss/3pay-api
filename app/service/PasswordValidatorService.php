<?php

namespace app\service;

use app\exception\MyBusinessException;

class PasswordValidatorService
{
    /**
     * 弱口令列表
     */
    private static $weakPasswords = [
        '123456', 'password', '123456789', '12345678', '12345', '1234567',
        '1234567890', 'qwerty', 'abc123', '111111', '123123', 'admin',
        'letmein', 'welcome', 'monkey', '1234', 'dragon', 'password123',
        '123456a', 'a123456', '123456789a', 'qwerty123', 'admin123',
        'root', 'toor', 'pass', 'test', 'guest', 'user', 'login',
        'master', 'hello', 'love', 'secret', 'fuckyou', 'fuck',
        '123qwe', 'qwe123', 'asd123', 'zxc123', 'qaz123', 'wsx123'
    ];

    /**
     * 验证密码强度
     * @param string $password
     * @throws MyBusinessException
     */
    public static function validatePassword(string $password): void
    {
        // 检查密码长度
        if (strlen($password) < 8) {
            throw new MyBusinessException('密码长度至少8位');
        }

        // 检查是否包含弱口令
        if (in_array(strtolower($password), array_map('strtolower', self::$weakPasswords))) {
            throw new MyBusinessException('密码不能使用弱口令，请设置更复杂的密码');
        }

        // 检查密码复杂度
        $hasLower = preg_match('/[a-z]/', $password);
        $hasUpper = preg_match('/[A-Z]/', $password);
        $hasNumber = preg_match('/[0-9]/', $password);
        $hasSpecial = preg_match('/[^a-zA-Z0-9]/', $password);

        $complexityCount = $hasLower + $hasUpper + $hasNumber + $hasSpecial;

        if ($complexityCount < 3) {
            throw new MyBusinessException('密码必须包含大小写字母、数字、特殊字符中的至少3种');
        }

        // 检查连续字符
        if (self::hasConsecutiveChars($password)) {
            throw new MyBusinessException('密码不能包含连续字符（如123、abc等）');
        }

        // 检查重复字符
        if (self::hasRepeatedChars($password)) {
            throw new MyBusinessException('密码不能包含重复字符（如111、aaa等）');
        }
    }

    /**
     * 检查连续字符
     */
    private static function hasConsecutiveChars(string $password): bool
    {
        $length = strlen($password);
        for ($i = 0; $i < $length - 2; $i++) {
            $char1 = ord($password[$i]);
            $char2 = ord($password[$i + 1]);
            $char3 = ord($password[$i + 2]);
            
            // 检查数字连续
            if (is_numeric($password[$i]) && is_numeric($password[$i + 1]) && is_numeric($password[$i + 2])) {
                if ($char2 == $char1 + 1 && $char3 == $char2 + 1) {
                    return true;
                }
            }
            
            // 检查字母连续
            if (ctype_alpha($password[$i]) && ctype_alpha($password[$i + 1]) && ctype_alpha($password[$i + 2])) {
                if ($char2 == $char1 + 1 && $char3 == $char2 + 1) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 检查重复字符
     */
    private static function hasRepeatedChars(string $password): bool
    {
        $length = strlen($password);
        for ($i = 0; $i < $length - 2; $i++) {
            if ($password[$i] == $password[$i + 1] && $password[$i + 1] == $password[$i + 2]) {
                return true;
            }
        }
        return false;
    }
}

<?php

namespace app\common\helpers;

class EnvHelper
{
    /**
     * 判断当前是否为调试模式（默认关闭）
     *
     * @return bool
     */
    public static function isDebugMode(): bool
    {
        return getenv('DEBUG') == 'true';
    }

    /**
     * 获取调试模式下的 ID（如果有）
     *
     * @return int|null
     */
    public static function getDebugId(): ?int
    {
        $debugId = getenv('DEBUG_ID');

        if (!is_numeric($debugId) || (int)$debugId < 1) {
            return null;
        }
        return (int)$debugId;
    }
}
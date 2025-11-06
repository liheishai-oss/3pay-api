<?php

namespace app\common\helpers;


use support\Context;

class ExceptionContextHelper
{
    public static function set(\Throwable $e): void
    {
        Context::set('exception', $e);
    }

    public static function get(): ?\Throwable
    {
        return Context::get('exception');
    }
}
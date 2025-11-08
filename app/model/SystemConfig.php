<?php

namespace app\model;

use support\Model;

class SystemConfig extends Model
{


    // 指定对应的数据库表名
    protected $table = 'system_config';

    /**
     * 时间格式转换 - 解决新版ORM时间格式问题
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }
}
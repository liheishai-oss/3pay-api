<?php

namespace app\model;

use support\Model;

class AdminLog extends Model
{
    protected $table = 'admin_log'; // 数据表名
    public $timestamps = true;
    protected $fillable = ['admin_id','username','route','method','ip','params'];

    /**
     * 时间格式转换 - 解决新版ORM时间格式问题
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }
}

<?php

namespace app\model;

use support\Model;

class RoleGroup extends Model
{
    // 指定对应的数据库表名
    protected $table = 'role_group';

    // 指定主键
    protected $primaryKey = 'id';

    // 定义可批量赋值的字段
    protected $fillable = [
        'parent_id',
        'name',
        'weight',
        'remark',
        'is_enabled'
    ];

    /**
     * 时间格式转换 - 解决新版ORM时间格式问题
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }
}
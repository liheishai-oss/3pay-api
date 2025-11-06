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

}
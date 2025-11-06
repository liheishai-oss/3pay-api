<?php

namespace app\model;

use support\Model;

class PermissionGroup extends Model
{
    // 设置表名
    protected $table = 'permission_group';

    // 主键
    protected $primaryKey = 'id';

    // 自动维护时间戳
    public $timestamps = true;

    // 可批量赋值字段
    protected $fillable = [
        'permission_id',
        'permission_group_id',
    ];




}

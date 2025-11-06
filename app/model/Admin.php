<?php

namespace app\model;

use support\Model;

class Admin extends Model
{
    // 指定对应的数据库表名
    protected $table = 'admins';

    // 指定主键
    protected $primaryKey = 'id';
    public $timestamps = true;

    // 时间戳字段名
    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    // 可填充字段
    protected $fillable = [
        'username',
        'password',
        'nickname',
        'group_id',
        'status',
        'last_login_at',
        'last_login_ip',
        'token',
        'is_first_login',
        'password_changed_at'
    ];

    /**
     * 时间格式转换 - 解决新版ORM时间格式问题
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }


    public function group()
    {
        return $this->belongsTo(RoleGroup::class, 'group_id', 'id');
    }
}
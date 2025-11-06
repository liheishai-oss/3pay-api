<?php

namespace app\model;

use support\Model;

class AdminLog extends Model
{
    protected $table = 'admin_log'; // 数据表名
    public $timestamps = true;
    protected $fillable = ['admin_id','username','route','method','ip','params'];
}

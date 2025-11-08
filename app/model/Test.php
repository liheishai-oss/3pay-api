<?php

namespace app\model;

use support\Model;

class Test extends Model
{

    protected $table = 'test';


    protected $primaryKey = 'id';


    public $timestamps = false;

    /**
     * 时间格式转换 - 解决新版ORM时间格式问题
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }
}
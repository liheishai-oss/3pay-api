<?php

namespace app\model;

use support\Model;

class Test extends Model
{

    protected $table = 'test';


    protected $primaryKey = 'id';


    public $timestamps = false;
}
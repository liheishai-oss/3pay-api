<?php

namespace app\model;

use support\Model;

/**
 * 单笔分账模型
 * @property int $id 主键ID
 * @property int $agent_id 代理商ID
 * @property string $payee_name 收款人姓名
 * @property string $payee_account 收款人账号
 * @property string $payee_user_id 收款人用户ID
 * @property int $status 状态：1启用 0禁用
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 */
class SingleRoyalty extends Model
{
    protected $table = 'single_royalty';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $fillable = [
        'agent_id',
        'payee_name',
        'payee_account',
        'payee_user_id',
        'status'
    ];

    protected $casts = [
        'id' => 'integer',
        'agent_id' => 'integer',
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    const STATUS_DISABLED = 0; // 禁用
    const STATUS_ENABLED = 1;  // 启用

    public function agent()
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'id');
    }
}



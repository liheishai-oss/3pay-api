<?php

namespace app\model;

use support\Model;

/**
 * 转账订单模型
 * @property int $id 主键ID
 * @property int $subject_id 转出主体ID
 * @property string $payee_name 收款人姓名
 * @property string $payee_account 收款人账号
 * @property float $amount 转账金额
 * @property string $transfer_time 转账时间
 * @property string $transfer_no 转账单号
 * @property string $remark 备注信息
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 */
class TransferOrder extends Model
{
    protected $table = 'transfer_order';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $fillable = [
        'subject_id',
        'payee_name',
        'payee_account',
        'amount',
        'transfer_time',
        'transfer_no',
        'remark'
    ];

    protected $casts = [
        'id' => 'integer',
        'subject_id' => 'integer',
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'transfer_time' => 'datetime',
    ];

    /**
     * 关联主体
     */
    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id', 'id');
    }

    /**
     * 时间格式转换 - 解决新版ORM时间格式问题
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }
}



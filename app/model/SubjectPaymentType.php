<?php

namespace app\model;

use support\Model;

/**
 * 主体支付类型关联模型
 * @property int $id 主键ID
 * @property int $subject_id 主体ID
 * @property int $payment_type_id 支付类型ID
 * @property int $status 状态：1启用 0禁用
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 */
class SubjectPaymentType extends Model
{
    protected $table = 'subject_payment_type';
    
    protected $fillable = [
        'subject_id',
        'payment_type_id',
        'status',
        'is_enabled'
    ];
    
    protected $casts = [
        'subject_id' => 'integer',
        'payment_type_id' => 'integer',
        'status' => 'integer',
        'is_enabled' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];
    
    // 状态常量
    const STATUS_DISABLED = 0;
    const STATUS_ENABLED = 1;
    
    /**
     * 关联主体模型
     */
    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id', 'id');
    }
    
    /**
     * 关联支付类型模型
     */
    public function paymentType()
    {
        return $this->belongsTo(PaymentType::class, 'payment_type_id', 'id');
    }
    
    /**
     * 获取主体的所有支付类型
     * @param int $subjectId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getSubjectPaymentTypes($subjectId)
    {
        return self::with('paymentType')
            ->where('subject_id', $subjectId)
            ->where('status', self::STATUS_ENABLED)
            ->get();
    }
    
    /**
     * 绑定支付类型到主体
     * @param int $subjectId
     * @param int $paymentTypeId
     * @return bool
     */
    public static function bindPaymentType($subjectId, $paymentTypeId)
    {
        return self::updateOrCreate(
            ['subject_id' => $subjectId, 'payment_type_id' => $paymentTypeId],
            ['status' => self::STATUS_ENABLED]
        );
    }
    
    /**
     * 解绑支付类型
     * @param int $subjectId
     * @param int $paymentTypeId
     * @return bool
     */
    public static function unbindPaymentType($subjectId, $paymentTypeId)
    {
        return self::where('subject_id', $subjectId)
            ->where('payment_type_id', $paymentTypeId)
            ->update(['status' => self::STATUS_DISABLED]);
    }

    /**
     * 时间格式转换 - 解决新版ORM时间格式问题
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }
}

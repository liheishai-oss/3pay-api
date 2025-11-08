<?php

namespace app\model;

use support\Model;

/**
 * 主体产品关联模型
 * @property int $id 主键ID
 * @property int $subject_id 主体ID
 * @property int $product_id 产品ID
 * @property int $status 状态：1启用 0禁用
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 */
class SubjectProduct extends Model
{
    protected $table = 'subject_product';
    
    protected $fillable = [
        'subject_id',
        'product_id',
        'status'
    ];
    
    protected $casts = [
        'subject_id' => 'integer',
        'product_id' => 'integer',
        'status' => 'integer',
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
     * 关联产品模型
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }
    
    /**
     * 获取主体的所有产品
     * @param int $subjectId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getSubjectProducts($subjectId)
    {
        return self::with('product')
            ->where('subject_id', $subjectId)
            ->where('status', self::STATUS_ENABLED)
            ->get();
    }
    
    /**
     * 绑定产品到主体
     * @param int $subjectId
     * @param int $productId
     * @return bool
     */
    public static function bindProduct($subjectId, $productId)
    {
        return self::updateOrCreate(
            ['subject_id' => $subjectId, 'product_id' => $productId],
            ['status' => self::STATUS_ENABLED]
        );
    }
    
    /**
     * 解绑产品
     * @param int $subjectId
     * @param int $productId
     * @return bool
     */
    public static function unbindProduct($subjectId, $productId)
    {
        return self::where('subject_id', $subjectId)
            ->where('product_id', $productId)
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












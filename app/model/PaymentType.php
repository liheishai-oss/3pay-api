<?php

namespace app\model;

use support\Model;

/**
 * 支付类型模型
 * @property int $id 主键ID
 * @property string $product_name 产品名称
 * @property string $product_code 产品代码
 * @property string $class_name 推荐的PHP类名
 * @property string $description 产品描述
 * @property int $status 状态：1启用 0禁用
 * @property int $sort_order 排序权重
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 */
class PaymentType extends Model
{
    /**
     * 表名
     * @var string
     */
    protected $table = 'payment_type';

    /**
     * 主键
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * 指示是否自动维护时间戳
     * @var bool
     */
    public $timestamps = true;

    /**
     * 模型日期字段的存储格式
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:s';

    /**
     * 创建时间字段
     * @var string
     */
    const CREATED_AT = 'created_at';

    /**
     * 更新时间字段
     * @var string
     */
    const UPDATED_AT = 'updated_at';

    /**
     * 可以批量赋值的属性
     * @var array
     */
    protected $fillable = [
        'product_name',
        'product_code',
        'class_name',
        'description',
        'status',
        'sort_order'
    ];

    /**
     * 属性类型转换
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'status' => 'integer',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 状态常量
     */
    const STATUS_DISABLED = 0; // 禁用
    const STATUS_ENABLED = 1;  // 启用

    /**
     * 获取启用的支付类型
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function getEnabled()
    {
        return self::where('status', self::STATUS_ENABLED)
            ->orderBy('sort_order', 'desc');
    }

    /**
     * 根据产品代码获取支付类型
     * @param string $productCode
     * @return PaymentType|null
     */
    public static function getByProductCode($productCode)
    {
        return self::where('product_code', $productCode)->first();
    }

    /**
     * 判断是否启用
     * @return bool
     */
    public function isEnabled()
    {
        return $this->status === self::STATUS_ENABLED;
    }

    /**
     * 切换状态
     * @return bool
     */
    public function toggleStatus()
    {
        $this->status = $this->status === self::STATUS_ENABLED 
            ? self::STATUS_DISABLED 
            : self::STATUS_ENABLED;
        return $this->save();
    }
}



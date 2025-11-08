<?php

namespace app\model;

use support\Model;

/**
 * 产品模型
 * @property int $id 主键ID
 * @property int $agent_id 代理商ID
 * @property int $payment_type_id 支付类型ID
 * @property string $product_name 产品名称
 * @property string $product_code 产品编号（4位数字）
 * @property int $status 状态：1启用 0禁用
 * @property string $remark 备注
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 */
class Product extends Model
{
    /**
     * 表名
     * @var string
     */
    protected $table = 'product';

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
        'agent_id',
        'payment_type_id',
        'product_name',
        'product_code',
        'status',
        'remark'
    ];

    /**
     * 属性类型转换
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'agent_id' => 'integer',
        'payment_type_id' => 'integer',
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 状态常量
     */
    const STATUS_DISABLED = 0; // 禁用
    const STATUS_ENABLED = 1;  // 启用

    /**
     * 关联agent表
     */
    public function agent()
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'id');
    }

    /**
     * 关联payment_type表
     */
    public function paymentType()
    {
        return $this->belongsTo(PaymentType::class, 'payment_type_id', 'id');
    }

    /**
     * 获取启用的产品
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function getEnabled()
    {
        return self::where('status', self::STATUS_ENABLED);
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

    /**
     * 生成唯一的4位数产品编号
     * @return string
     */
    public static function generateProductCode()
    {
        $maxAttempts = 100;
        $attempts = 0;

        while ($attempts < $maxAttempts) {
            // 生成1000-9999之间的随机数
            $code = str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
            
            // 检查是否已存在
            if (!self::where('product_code', $code)->exists()) {
                return $code;
            }
            
            $attempts++;
        }

        // 如果随机生成失败，使用顺序生成
        $lastProduct = self::orderBy('product_code', 'desc')->first();
        if ($lastProduct) {
            $nextCode = intval($lastProduct->product_code) + 1;
            if ($nextCode <= 9999) {
                return str_pad($nextCode, 4, '0', STR_PAD_LEFT);
            }
        }

        // 从1000开始查找
        for ($i = 1000; $i <= 9999; $i++) {
            $code = str_pad($i, 4, '0', STR_PAD_LEFT);
            if (!self::where('product_code', $code)->exists()) {
                return $code;
            }
        }

        throw new \Exception('无法生成产品编号，所有编号已被使用');
    }

    /**
     * 时间格式转换 - 解决新版ORM时间格式问题
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }
}



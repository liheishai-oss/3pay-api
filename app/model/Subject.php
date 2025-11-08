<?php

namespace app\model;

use support\Model;

/**
 * 支付宝主体模型（仅企业主体）
 * @property int $id 主键ID
 * @property int $agent_id 代理商ID
 * @property string $royalty_type 分账方式：none=不分账, single=单笔, merchant=商家
 * @property string $royalty_mode 分账模式：normal=普通, master_sub=主子
 * @property float $royalty_rate 分账比例（百分比）
 * @property int $allow_remote_order 是否支持异地拉单：1=支持 0=不支持
 * @property int $verify_device 是否验证设备：1=是 0=否
 * @property int $scan_pay_enabled 扫码支付：1=开启 0=关闭
 * @property int $transaction_limit 交易笔数限制（每日）
 * @property float $amount_min 单笔最小交易金额
 * @property float $amount_max 单笔最大交易金额
 * @property string $company_name 企业名称
 * @property string $alipay_app_id 支付宝应用APPID
 * @property string $alipay_pid 支付宝合作伙伴ID
 * @property int $status 状态：1启用 0禁用
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 */
class Subject extends Model
{
    /**
     * 表名
     * @var string
     */
    protected $table = 'subject';

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
        'royalty_type',
        'royalty_mode',
        'royalty_rate',
        'allow_remote_order',
        'verify_device',
        'scan_pay_enabled',
        'transaction_limit',
        'amount_min',
        'amount_max',
        'company_name',
        'alipay_app_id',
        'alipay_pid',
        'custom_product_title',
        'status'
    ];

    /**
     * 属性类型转换
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'agent_id' => 'integer',
        'product_id' => 'integer',
        'royalty_rate' => 'float',
        'allow_remote_order' => 'integer',
        'verify_device' => 'integer',
        'scan_pay_enabled' => 'integer',
        'transaction_limit' => 'integer',
        'amount_min' => 'float',
        'amount_max' => 'float',
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 状态常量
     */
    const STATUS_DISABLED = 0; // 禁用
    const STATUS_ENABLED = 1;  // 启用

    // 分账方式常量
    const ROYALTY_TYPE_NONE = 'none';       // 不分账
    const ROYALTY_TYPE_SINGLE = 'single';   // 单笔
    const ROYALTY_TYPE_MERCHANT = 'merchant'; // 商家

    // 分账模式常量
    const ROYALTY_MODE_NORMAL = 'normal';        // 普通
    const ROYALTY_MODE_MASTER_SUB = 'master_sub'; // 主子

    /**
     * 关联agent表
     */
    public function agent()
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'id');
    }

    /**
     * 关联证书表（一对一）
     */
    public function cert()
    {
        return $this->hasOne(SubjectCert::class, 'subject_id', 'id');
    }

    /**
     * 关联支付类型表（多对多）
     */
    public function paymentTypes()
    {
        return $this->belongsToMany(PaymentType::class, 'subject_payment_type', 'subject_id', 'payment_type_id')
            ->wherePivot('status', SubjectPaymentType::STATUS_ENABLED)
            ->wherePivot('is_enabled', 1)
            ->withTimestamps();
    }

    /**
     * 关联主体支付类型关联表
     */
    public function subjectPaymentTypes()
    {
        return $this->hasMany(SubjectPaymentType::class, 'subject_id', 'id');
    }


    /**
     * 获取启用的主体
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
     * 时间格式转换 - 解决新版ORM时间格式问题
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }
}


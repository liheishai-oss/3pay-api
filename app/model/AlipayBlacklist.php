<?php

namespace app\model;

use support\Model;

/**
 * 支付宝黑名单模型
 * 
 * @property int $id 主键ID
 * @property string $alipay_user_id 支付宝用户ID
 * @property string|null $device_code 设备码
 * @property string|null $ip_address 用户拉单IP
 * @property int $risk_count 风险触发次数
 * @property string|null $last_risk_time 最后一次触发风险时间
 * @property string|null $remark 备注信息
 * @property string|null $created_at 创建时间
 * @property string|null $updated_at 更新时间
 */
class AlipayBlacklist extends Model
{
    /**
     * 表名
     * @var string
     */
    protected $table = 'alipay_blacklist';

    /**
     * 主键
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * 指示模型是否使用时间戳
     * @var bool
     */
    public $timestamps = true;

    /**
     * 可批量赋值的属性
     * @var array
     */
    protected $fillable = [
        'alipay_user_id',
        'device_code',
        'ip_address',
        'risk_count',
        'last_risk_time',
        'remark'
    ];

    /**
     * 属性类型转换
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'risk_count' => 'integer',
        'last_risk_time' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 获取日期格式
     * @return string
     */
    public function getDateFormat()
    {
        return 'Y-m-d H:i:s';
    }
}


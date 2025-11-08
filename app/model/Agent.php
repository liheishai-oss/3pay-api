<?php

namespace app\model;

use support\Model;

/**
 * 代理商模型
 * @property int $id 主键ID
 * @property int $admin_id 关联的admin管理员ID
 * @property string $agent_name 代理商名称
 * @property int $status 状态：1启用 0禁用
 * @property string $remark 备注
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 */
class Agent extends Model
{
    /**
     * 表名
     * @var string
     */
    protected $table = 'agent';

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
        'admin_id',
        'agent_name',
        'status',
        'remark'
    ];

    /**
     * 属性类型转换
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'admin_id' => 'integer',
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
     * 关联admin表
     */
    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id', 'id');
    }

    /**
     * 获取启用的代理商
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


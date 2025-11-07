<?php

namespace app\model;

use support\Model;

/**
 * 商户模型
 * @property int $id 主键ID
 * @property int $agent_id 代理商ID
 * @property int $admin_id 关联的admin管理员ID
 * @property string $username 商户登录账号
 * @property string $merchant_name 商户名称
 * @property string $api_key API密钥
 * @property string $api_secret API密钥Secret
 * @property string $ip_whitelist IP白名单
 * @property int $status 状态：1启用 0禁用
 * @property string $remark 备注
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 */
class Merchant extends Model
{
    protected $table = 'merchant';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $fillable = [
        'agent_id',
        'admin_id',
        'username',
        'merchant_name',
        'api_key',
        'api_secret',
        'ip_whitelist',
        'status',
        'remark'
    ];

    protected $casts = [
        'id' => 'integer',
        'agent_id' => 'integer',
        'admin_id' => 'integer',
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

    /**
     * 生成API密钥
     */
    public static function generateApiKey()
    {
        return md5(uniqid('api_key_', true) . microtime());
    }

    /**
     * 生成API密钥Secret
     */
    public static function generateApiSecret()
    {
        return hash('sha256', uniqid('api_secret_', true) . microtime());
    }
}


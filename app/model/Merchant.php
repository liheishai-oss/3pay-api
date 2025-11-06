<?php

namespace app\model;

use support\Model;

/**
 * 商户模型
 * @property int $id 主键ID
 * @property int $agent_id 代理商ID
 * @property string $merchant_name 商户名称
 * @property string $contact_email 联系邮箱
 * @property string $api_key API密钥
 * @property string $api_secret API密钥Secret
 * @property string $notify_url 回调通知地址
 * @property string $return_url 同步返回地址
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
        'merchant_name',
        'contact_email',
        'api_key',
        'api_secret',
        'notify_url',
        'return_url',
        'ip_whitelist',
        'status',
        'remark'
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


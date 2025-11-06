<?php

namespace app\model;

use support\Model;

class TelegramAdmin extends Model
{
    protected $table = 'telegram_admin';
    
    protected $fillable = [
        'telegram_id',
        'nickname',
        'username',
        'status',
        'remark'
    ];

    protected $casts = [
        'telegram_id' => 'integer',
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * 时间格式转换 - 解决新版ORM时间格式问题
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }

    // 状态常量
    const STATUS_DISABLED = 0;
    const STATUS_ENABLED = 1;

    /**
     * 获取状态文本
     */
    public function getStatusTextAttribute(): string
    {
        return $this->status === self::STATUS_ENABLED ? '启用' : '禁用';
    }

    /**
     * 按状态查询
     */
    public function scopeEnabled($query)
    {
        return $query->where('status', self::STATUS_ENABLED);
    }
}

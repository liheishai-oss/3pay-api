<?php

namespace app\model;

use support\Model;

class Server extends Model
{
    // 指定数据库表名
    protected $table = 'server';

    // 指定主键
    protected $primaryKey = 'id';

    // 使用时间戳
    public $timestamps = true;

    // 时间戳字段名
    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    // 可填充字段
    protected $fillable = [
        'server_name',
        'server_ip',
        'server_port',
        'server_type',
        'status',
        'is_maintenance',
        'cpu_usage',
        'memory_usage',
        'disk_usage',
        'load_average',
        'uptime',
        'php_version',
        'webman_version',
        'os_info',
        'remark',
        'last_check_time'
    ];

    // 字段类型转换
    protected $casts = [
        'server_port' => 'integer',
        'is_maintenance' => 'boolean',
        'cpu_usage' => 'decimal:2',
        'memory_usage' => 'decimal:2',
        'disk_usage' => 'decimal:2',
        'uptime' => 'integer',
        'last_check_time' => 'datetime',
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
    const STATUS_ONLINE = 'online';
    const STATUS_OFFLINE = 'offline';
    const STATUS_MAINTENANCE = 'maintenance';

    // 服务器类型常量
    const TYPE_WEB = 'web';
    const TYPE_API = 'api';
    const TYPE_DATABASE = 'database';
    const TYPE_CACHE = 'cache';
    const TYPE_LOAD_BALANCER = 'load_balancer';

    /**
     * 查询在线服务器
     */
    public function scopeOnline($query)
    {
        return $query->where('status', self::STATUS_ONLINE);
    }

    /**
     * 查询离线服务器
     */
    public function scopeOffline($query)
    {
        return $query->where('status', self::STATUS_OFFLINE);
    }

    /**
     * 查询维护中的服务器
     */
    public function scopeMaintenance($query)
    {
        return $query->where('status', self::STATUS_MAINTENANCE);
    }

    /**
     * 查询正常运行的服务器（在线且非维护状态）
     */
    public function scopeNormal($query)
    {
        return $query->where('status', self::STATUS_ONLINE)
                    ->where('is_maintenance', false);
    }

    /**
     * 查询维护模式的服务器
     */
    public function scopeInMaintenance($query)
    {
        return $query->where('is_maintenance', true);
    }

    /**
     * 获取状态文本
     */
    public function getStatusTextAttribute()
    {
        $statusMap = [
            self::STATUS_ONLINE => '在线',
            self::STATUS_OFFLINE => '离线',
            self::STATUS_MAINTENANCE => '维护中'
        ];
        return $statusMap[$this->status] ?? '未知';
    }

    /**
     * 获取服务器类型文本
     */
    public function getTypeTextAttribute()
    {
        $typeMap = [
            self::TYPE_WEB => 'Web服务器',
            self::TYPE_API => 'API服务器',
            self::TYPE_DATABASE => '数据库服务器',
            self::TYPE_CACHE => '缓存服务器',
            self::TYPE_LOAD_BALANCER => '负载均衡器'
        ];
        return $typeMap[$this->server_type] ?? '未知类型';
    }

    /**
     * 检查服务器是否健康
     */
    public function isHealthy()
    {
        return $this->status === self::STATUS_ONLINE && 
               $this->cpu_usage < 90 && 
               $this->memory_usage < 90 && 
               $this->disk_usage < 90;
    }

    /**
     * 获取服务器完整地址
     */
    public function getFullAddressAttribute()
    {
        return $this->server_ip . ':' . $this->server_port;
    }
}

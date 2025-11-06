<?php

namespace app\model;

use support\Model;

class OperationLog extends Model
{
    protected $table = 'operation_log';
    
    protected $fillable = [
        'admin_id',
        'module',
        'action',
        'target_type',
        'target_id',
        'description',
        'request_data',
        'response_data',
        'ip_address',
        'user_agent'
    ];

    protected $casts = [
        'request_data' => 'array',
        'response_data' => 'array',
        'admin_id' => 'integer',
        'target_id' => 'integer'
    ];

    // 模块常量
    const MODULE_ORDER = 'order';
    const MODULE_MERCHANT = 'merchant';
    const MODULE_PRODUCT = 'product';
    const MODULE_CHANNEL = 'channel';
    const MODULE_SUPPLIER = 'supplier';
    const MODULE_SYSTEM = 'system';
    const MODULE_FINANCE = 'finance';

    // 操作动作常量
    const ACTION_CREATE = 'create';
    const ACTION_UPDATE = 'update';
    const ACTION_DELETE = 'delete';
    const ACTION_VIEW = 'view';
    const ACTION_LOGIN = 'login';
    const ACTION_LOGOUT = 'logout';
    const ACTION_EXPORT = 'export';
    const ACTION_IMPORT = 'import';

    // 目标类型常量
    const TARGET_TYPE_ORDER = 'order';
    const TARGET_TYPE_MERCHANT = 'merchant';
    const TARGET_TYPE_PRODUCT = 'product';
    const TARGET_TYPE_CHANNEL = 'channel';
    const TARGET_TYPE_SUPPLIER = 'supplier';
    const TARGET_TYPE_ADMIN = 'admin';

    // 关联管理员
    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id', 'id');
    }

    // 获取模块文本
    public function getModuleTextAttribute()
    {
        $moduleMap = [
            self::MODULE_ORDER => '订单管理',
            self::MODULE_MERCHANT => '商户管理',
            self::MODULE_PRODUCT => '产品管理',
            self::MODULE_CHANNEL => '通道管理',
            self::MODULE_SUPPLIER => '供应商管理',
            self::MODULE_SYSTEM => '系统管理',
            self::MODULE_FINANCE => '财务管理'
        ];
        return $moduleMap[$this->module] ?? $this->module;
    }

    // 获取操作动作文本
    public function getActionTextAttribute()
    {
        $actionMap = [
            self::ACTION_CREATE => '创建',
            self::ACTION_UPDATE => '更新',
            self::ACTION_DELETE => '删除',
            self::ACTION_VIEW => '查看',
            self::ACTION_LOGIN => '登录',
            self::ACTION_LOGOUT => '登出',
            self::ACTION_EXPORT => '导出',
            self::ACTION_IMPORT => '导入'
        ];
        return $actionMap[$this->action] ?? $this->action;
    }

    // 获取目标类型文本
    public function getTargetTypeTextAttribute()
    {
        $targetMap = [
            self::TARGET_TYPE_ORDER => '订单',
            self::TARGET_TYPE_MERCHANT => '商户',
            self::TARGET_TYPE_PRODUCT => '产品',
            self::TARGET_TYPE_CHANNEL => '通道',
            self::TARGET_TYPE_SUPPLIER => '供应商',
            self::TARGET_TYPE_ADMIN => '管理员'
        ];
        return $targetMap[$this->target_type] ?? $this->target_type;
    }
}

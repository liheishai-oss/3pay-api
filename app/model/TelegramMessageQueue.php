<?php

namespace app\model;

use support\Model;

/**
 * Telegram消息队列模型
 * 
 * @property int $id 消息ID
 * @property string $title 消息标题
 * @property string $content 消息内容
 * @property int $priority 优先级（1-10，数字越小优先级越高）
 * @property string $status 消息状态
 * @property string $message_type 消息类型
 * @property string $template_name 模板名称
 * @property array $template_data 模板数据
 * @property string $chat_id 指定聊天ID
 * @property int $retry_count 重试次数
 * @property int $max_retry 最大重试次数
 * @property string $error_message 错误信息
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 * @property string $sent_at 发送时间
 * @property string $scheduled_at 计划发送时间
 */
class TelegramMessageQueue extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'telegram_message_queue';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The name of the "created at" column.
     *
     * @var string
     */
    const CREATED_AT = 'created_at';

    /**
     * The name of the "updated at" column.
     *
     * @var string
     */
    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'title',
        'content',
        'priority',
        'status',
        'message_type',
        'template_name',
        'template_data',
        'chat_id',
        'retry_count',
        'max_retry',
        'error_message',
        'sent_at',
        'scheduled_at',
    ];

    protected $casts = [
        'id' => 'integer',
        'priority' => 'integer',
        'retry_count' => 'integer',
        'max_retry' => 'integer',
        'template_data' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'sent_at' => 'datetime',
        'scheduled_at' => 'datetime',
    ];

    /**
     * 状态常量
     */
    const STATUS_PENDING = 'pending';   // 待发送
    const STATUS_SENDING = 'sending';   // 发送中
    const STATUS_SENT = 'sent';         // 已发送
    const STATUS_FAILED = 'failed';     // 发送失败

    /**
     * 优先级常量
     */
    const PRIORITY_CRITICAL = 1;  // 紧急
    const PRIORITY_HIGH = 3;      // 高
    const PRIORITY_NORMAL = 5;    // 普通
    const PRIORITY_LOW = 7;       // 低
    const PRIORITY_LOWEST = 9;    // 最低
}


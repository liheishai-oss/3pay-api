<?php

namespace app\model;

use support\Model;

/**
 * 订单模型
 * @property int $id 主键ID
 * @property int $merchant_id 商户ID
 * @property int $agent_id 代理商ID
 * @property string $platform_order_no 平台订单号
 * @property string $trace_id 链路追踪ID
 * @property string $merchant_order_no 商户订单号
 * @property string $alipay_order_no 支付宝订单号
 * @property int $product_id 产品ID
 * @property int $subject_id 主体ID
 * @property float $order_amount 订单金额
 * @property int $pay_status 支付状态
 * @property int $notify_status 通知状态
 * @property int $notify_times 通知次数
 * @property string $notify_url 异步通知地址
 * @property string $return_url 同步返回地址
 * @property string $client_ip 客户端IP
 * @property string $first_open_ip 首次打开支付页面的IP
 * @property string $first_open_time 首次打开支付页面的时间
 * @property string $pay_ip 支付时IP地址
 * @property string $pay_time 支付时间
 * @property string $notify_time 通知时间
 * @property string $close_time 关闭时间
 * @property string $expire_time 过期时间
 * @property string $subject 订单标题（商品名称）
 * @property string $body 订单描述（商品描述）
 * @property string $remark 备注
 * @property string $buyer_id 购买者UID
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 */
class Order extends Model
{
    protected $table = 'order';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $fillable = [
        'merchant_id',
        'agent_id',
        'platform_order_no',
        'trace_id',
        'merchant_order_no',
        'alipay_order_no',
        'product_id',
        'subject_id',
        'order_amount',
        'subject',
        'body',
        'pay_status',
        'notify_status',
        'notify_times',
        'notify_url',
        'return_url',
        'client_ip',
        'first_open_ip',
        'first_open_time',
        'pay_ip',
        'pay_time',
        'notify_time',
        'close_time',
        'expire_time',
        'remark',
        'buyer_id'
    ];

    protected $casts = [
        'id' => 'integer',
        'merchant_id' => 'integer',
        'agent_id' => 'integer',
        'product_id' => 'integer',
        'subject_id' => 'integer',
        'order_amount' => 'float',
        'pay_status' => 'integer',
        'notify_status' => 'integer',
        'notify_times' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'first_open_time' => 'datetime',
        'pay_time' => 'datetime',
        'notify_time' => 'datetime',
        'close_time' => 'datetime',
        'expire_time' => 'datetime',
    ];

    // 支付状态常量
    const PAY_STATUS_CREATED = 0;    // 已创建，待打开
    const PAY_STATUS_PAID = 1;      // 已支付
    const PAY_STATUS_CLOSED = 2;    // 已关闭
    const PAY_STATUS_REFUNDED = 3;  // 已退款
    const PAY_STATUS_OPENED = 4;    // 已打开（用户已访问支付页面，待支付）

    // 通知状态常量
    const NOTIFY_STATUS_PENDING = 0;  // 未通知
    const NOTIFY_STATUS_SUCCESS = 1;  // 通知成功
    const NOTIFY_STATUS_FAILED = 2;   // 通知失败

    public function merchant()
    {
        return $this->belongsTo(Merchant::class, 'merchant_id', 'id');
    }

    public function agent()
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id', 'id');
    }

    /**
     * 关联分账记录（一对多）
     */
    public function royaltyRecords()
    {
        return $this->hasMany(OrderRoyalty::class, 'order_id', 'id');
    }

    /**
     * 获取最新的分账记录
     */
    public function royaltyRecord()
    {
        return $this->hasOne(OrderRoyalty::class, 'order_id', 'id')
            ->orderBy('id', 'desc');
    }

    /**
     * 判断是否已分账
     */
    public function hasRoyalty(): bool
    {
        return $this->royaltyRecords()
            ->where('royalty_status', OrderRoyalty::ROYALTY_STATUS_SUCCESS)
            ->exists();
    }

    /**
     * 判断是否需要分账
     */
    public function needsRoyalty(): bool
    {
        // 订单已支付
        if ($this->pay_status !== self::PAY_STATUS_PAID) {
            return false;
        }

        // 已存在成功分账记录，不需要重复分账
        if ($this->hasRoyalty()) {
            return false;
        }

        // 检查主体是否配置了分账
        if (!$this->subject) {
            return false;
        }

        // 分账方式不为"不分账"
        return $this->subject->royalty_type !== Subject::ROYALTY_TYPE_NONE;
    }

    /**
     * 生成平台订单号
     * 格式：P + 年月日(8位) + 时分秒(6位) + 微秒(6位) + 随机数(4位)
     */
    public static function generatePlatformOrderNo()
    {
        $microtime = explode(' ', microtime());
        $microsecond = substr($microtime[0], 2, 6); // 微秒
        
        do {
            $orderNo = 'P' 
                . date('Ymd') 
                . date('His') 
                . $microsecond
                . str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
            $exists = self::where('platform_order_no', $orderNo)->exists();
        } while ($exists);

        return $orderNo;
    }

    /**
     * 获取支付状态文本
     */
    public function getPayStatusTextAttribute()
    {
        $statusTexts = [
            self::PAY_STATUS_CREATED => '已创建',
            self::PAY_STATUS_PAID => '已支付',
            self::PAY_STATUS_CLOSED => '已关闭',
            self::PAY_STATUS_REFUNDED => '已退款',
            self::PAY_STATUS_OPENED => '已打开',
        ];
        return $statusTexts[$this->pay_status] ?? '未知';
    }

    /**
     * 获取通知状态文本
     */
    public function getNotifyStatusTextAttribute()
    {
        $statusTexts = [
            self::NOTIFY_STATUS_PENDING => '未通知',
            self::NOTIFY_STATUS_SUCCESS => '通知成功',
            self::NOTIFY_STATUS_FAILED => '通知失败',
        ];
        return $statusTexts[$this->notify_status] ?? '未知';
    }

    /**
     * 时间格式转换 - 解决新版ORM时间格式问题
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }
}


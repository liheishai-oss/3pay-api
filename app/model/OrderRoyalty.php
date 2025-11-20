<?php

namespace app\model;

use app\common\helpers\MoneyHelper;
use support\Model;

/**
 * 订单分账记录模型
 * @property int $id 主键ID
 * @property int $order_id 订单ID
 * @property string $platform_order_no 平台订单号
 * @property string $trade_no 支付宝交易号
 * @property string $royalty_type 分账方式：none/single/merchant
 * @property string $royalty_mode 分账模式：normal/master_sub
 * @property float $royalty_rate 分账比例（百分比）
 * @property int $subject_id 主体ID
 * @property int $subject_amount 主体收款金额（分）
 * @property string $payee_type 收款人类型：agent/merchant/single
 * @property int $payee_id 收款人ID
 * @property string $payee_name 收款人名称
 * @property string $payee_account 收款人账号
 * @property string $payee_user_id 收款人支付宝用户ID
 * @property int $royalty_amount 分账金额（分）
 * @property int $royalty_status 分账状态：0=待分账, 1=分账中, 2=分账成功, 3=分账失败
 * @property string $royalty_time 分账时间
 * @property string $royalty_error 分账失败原因
 * @property string $alipay_royalty_no 支付宝分账单号
 * @property string $alipay_result 支付宝分账返回结果
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 */
class OrderRoyalty extends Model
{
    protected $table = 'order_royalty';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $fillable = [
        'order_id',
        'platform_order_no',
        'trade_no',
        'royalty_type',
        'royalty_mode',
        'royalty_rate',
        'subject_id',
        'subject_amount',
        'payee_type',
        'payee_id',
        'payee_name',
        'payee_account',
        'payee_user_id',
        'royalty_amount',
        'royalty_status',
        'royalty_time',
        'royalty_error',
        'alipay_royalty_no',
        'alipay_result'
    ];

    protected $casts = [
        'id' => 'integer',
        'order_id' => 'integer',
        'subject_id' => 'integer',
        'payee_id' => 'integer',
        'royalty_rate' => 'float',
        'subject_amount' => 'integer',
        'royalty_amount' => 'integer',
        'royalty_status' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
        'royalty_time' => 'datetime:Y-m-d H:i:s',
    ];

    protected $appends = [
        'subject_amount_yuan',
        'royalty_amount_yuan',
    ];

    // 分账状态常量
    const ROYALTY_STATUS_PENDING = 0;      // 待分账
    const ROYALTY_STATUS_PROCESSING = 1;    // 分账中
    const ROYALTY_STATUS_SUCCESS = 2;       // 分账成功
    const ROYALTY_STATUS_FAILED = 3;        // 分账失败

    // 收款人类型常量
    const PAYEE_TYPE_AGENT = 'agent';       // 代理商
    const PAYEE_TYPE_MERCHANT = 'merchant'; // 商户
    const PAYEE_TYPE_SINGLE = 'single';     // 单笔分账

    /**
     * 关联订单
     */
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    /**
     * 关联主体
     */
    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id', 'id');
    }

    /**
     * 判断是否待分账
     */
    public function isPending(): bool
    {
        return $this->royalty_status === self::ROYALTY_STATUS_PENDING;
    }

    /**
     * 判断是否分账中
     */
    public function isProcessing(): bool
    {
        return $this->royalty_status === self::ROYALTY_STATUS_PROCESSING;
    }

    /**
     * 判断是否分账成功
     */
    public function isSuccess(): bool
    {
        return $this->royalty_status === self::ROYALTY_STATUS_SUCCESS;
    }

    /**
     * 判断是否分账失败
     */
    public function isFailed(): bool
    {
        return $this->royalty_status === self::ROYALTY_STATUS_FAILED;
    }

    /**
     * 获取分账状态文本
     */
    public function getStatusText(): string
    {
        $statusMap = [
            self::ROYALTY_STATUS_PENDING => '待分账',
            self::ROYALTY_STATUS_PROCESSING => '分账中',
            self::ROYALTY_STATUS_SUCCESS => '分账成功',
            self::ROYALTY_STATUS_FAILED => '分账失败',
        ];
        return $statusMap[$this->royalty_status] ?? '未知';
    }

    /**
     * 检查订单是否已有成功分账记录
     * @param int $orderId 订单ID
     * @return bool
     */
    public static function hasSuccessRoyalty(int $orderId): bool
    {
        return self::where('order_id', $orderId)
            ->where('royalty_status', self::ROYALTY_STATUS_SUCCESS)
            ->exists();
    }

    /**
     * 获取订单的分账记录
     * @param int $orderId 订单ID
     * @return OrderRoyalty|null
     */
    public static function getOrderRoyalty(int $orderId): ?self
    {
        return self::where('order_id', $orderId)
            ->orderBy('id', 'desc')
            ->first();
    }

    /**
     * 获取订单的累计失败次数
     * @param int $orderId 订单ID
     * @return int 失败次数
     */
    public static function getFailureCount(int $orderId): int
    {
        return self::where('order_id', $orderId)
            ->where('royalty_status', self::ROYALTY_STATUS_FAILED)
            ->count();
    }

    public function getSubjectAmountYuanAttribute(): string
    {
        $amount = $this->attributes['subject_amount'] ?? 0;
        return MoneyHelper::convertToYuan($amount);
    }

    public function getRoyaltyAmountYuanAttribute(): string
    {
        $amount = $this->attributes['royalty_amount'] ?? 0;
        return MoneyHelper::convertToYuan($amount);
    }
}




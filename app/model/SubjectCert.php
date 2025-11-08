<?php

namespace app\model;

use support\Model;

/**
 * 支付宝主体证书模型
 * @property int $id 主键ID
 * @property int $subject_id 主体ID
 * @property string $app_private_key 应用私钥(RSA私钥)
 * @property string $app_private_key_path 应用私钥文件路径
 * @property string $app_cert_public_key 应用公钥证书(appCertPublicKey)
 * @property string $app_cert_public_key_path 应用公钥证书文件路径
 * @property string $alipay_cert_public_key 支付宝公钥证书(alipayCertPublicKey)
 * @property string $alipay_cert_public_key_path 支付宝公钥证书文件路径
 * @property string $alipay_root_cert 支付宝根证书(alipayRootCert)
 * @property string $alipay_root_cert_path 支付宝根证书文件路径
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 */
class SubjectCert extends Model
{
    /**
     * 表名
     * @var string
     */
    protected $table = 'subject_cert';

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
        'subject_id',
        'app_private_key',
        'app_public_cert',           // 临时使用旧字段名
        'app_public_cert_path',      // 临时使用旧字段名
        'alipay_public_cert',        // 临时使用旧字段名
        'alipay_public_cert_path',   // 临时使用旧字段名
        'alipay_root_cert',
        'alipay_root_cert_path'
    ];

    /**
     * 属性类型转换
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'subject_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 关联subject表
     */
    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id', 'id');
    }

    /**
     * 时间格式转换 - 解决新版ORM时间格式问题
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }
}



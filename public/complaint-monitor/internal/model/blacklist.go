package model

import "time"

// AlipayBlacklist 支付宝黑名单模型
// 注意：此模型匹配现有的数据库表结构
// 唯一索引：uniq_blacklist (alipay_user_id, device_code, ip_address)
type AlipayBlacklist struct {
	ID           uint       `gorm:"column:id;primaryKey" json:"id"`
	AlipayUserID string     `gorm:"column:alipay_user_id;not null;size:64;uniqueIndex:uniq_blacklist,priority:1;index:idx_alipay_user_id" json:"alipay_user_id"`
	DeviceCode   *string    `gorm:"column:device_code;size:128;uniqueIndex:uniq_blacklist,priority:2;index:idx_device_code" json:"device_code"` // 使用指针类型，空值时存储NULL
	IPAddress    *string    `gorm:"column:ip_address;size:64;uniqueIndex:uniq_blacklist,priority:3;index:idx_ip_address" json:"ip_address"`     // 使用指针类型，空值时存储NULL
	RiskCount    int        `gorm:"column:risk_count;default:1" json:"risk_count"`                                                              // 风险触发次数
	LastRiskTime *time.Time `gorm:"column:last_risk_time;index:idx_last_risk_time" json:"last_risk_time"`                                       // 最后一次触发风险时间
	Remark       string     `gorm:"column:remark;size:255" json:"remark"`                                                                       // 备注信息
	CreatedAt    time.Time  `gorm:"column:created_at" json:"created_at"`
	UpdatedAt    time.Time  `gorm:"column:updated_at" json:"updated_at"`
}

// TableName 指定表名
func (AlipayBlacklist) TableName() string {
	return "alipay_blacklist"
}

// IncrementRiskCount 增加风险触发次数
func (b *AlipayBlacklist) IncrementRiskCount() {
	b.RiskCount++
	now := time.Now()
	b.LastRiskTime = &now
}

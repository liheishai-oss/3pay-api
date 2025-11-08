package model

import "time"

// AlipayBlacklist 支付宝黑名单模型
type AlipayBlacklist struct {
	ID                  uint       `gorm:"column:id;primaryKey" json:"id"`
	SubjectID           int        `gorm:"column:subject_id;not null;uniqueIndex:uniq_subject_alipay_user" json:"subject_id"`
	AlipayUserID        string     `gorm:"column:alipay_user_id;not null;size:64;uniqueIndex:uniq_subject_alipay_user" json:"alipay_user_id"`
	DeviceCode          string     `gorm:"column:device_code;size:128;index:idx_device_code" json:"device_code"`
	IPAddress           string     `gorm:"column:ip_address;size:45;index:idx_ip_address" json:"ip_address"`
	BlacklistType       string     `gorm:"column:blacklist_type;size:20;default:complaint" json:"blacklist_type"` // complaint-投诉 manual-手动
	RiskLevel           string     `gorm:"column:risk_level;size:20;default:low;index:idx_risk_level" json:"risk_level"` // low,medium,high,critical
	ComplaintCount      int        `gorm:"column:complaint_count;default:0" json:"complaint_count"`
	LastComplaintTime   *time.Time `gorm:"column:last_complaint_time" json:"last_complaint_time"`
	Remark              string     `gorm:"column:remark;type:text" json:"remark"`
	CreatedAt           time.Time  `gorm:"column:created_at" json:"created_at"`
	UpdatedAt           time.Time  `gorm:"column:updated_at" json:"updated_at"`
}

// TableName 指定表名
func (AlipayBlacklist) TableName() string {
	return "alipay_blacklist"
}

// IsLowRisk 是否低风险
func (b *AlipayBlacklist) IsLowRisk() bool {
	return b.RiskLevel == "low"
}

// IsMediumRisk 是否中风险
func (b *AlipayBlacklist) IsMediumRisk() bool {
	return b.RiskLevel == "medium"
}

// IsHighRisk 是否高风险
func (b *AlipayBlacklist) IsHighRisk() bool {
	return b.RiskLevel == "high"
}

// IsCriticalRisk 是否极高风险
func (b *AlipayBlacklist) IsCriticalRisk() bool {
	return b.RiskLevel == "critical"
}

// IncrementComplaintCount 增加投诉次数
func (b *AlipayBlacklist) IncrementComplaintCount() {
	b.ComplaintCount++
	now := time.Now()
	b.LastComplaintTime = &now
}

// UpdateRiskLevel 更新风险等级
func (b *AlipayBlacklist) UpdateRiskLevel(level string) {
	b.RiskLevel = level
}


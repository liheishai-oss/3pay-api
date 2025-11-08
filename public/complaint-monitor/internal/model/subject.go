package model

import "time"

// Subject 主体模型
type Subject struct {
	ID               int       `gorm:"column:id;primaryKey" json:"id"`
	AgentID          int       `gorm:"column:agent_id" json:"agent_id"`
	CompanyName      string    `gorm:"column:company_name" json:"company_name"`
	AlipayAppID      string    `gorm:"column:alipay_app_id" json:"alipay_app_id"`
	AlipayPID        string    `gorm:"column:alipay_pid" json:"alipay_pid"`
	Status           int       `gorm:"column:status;default:1" json:"status"`
	VerifyDevice     int       `gorm:"column:verify_device;default:0" json:"verify_device"`
	AllowRemoteOrder int       `gorm:"column:allow_remote_order;default:1" json:"allow_remote_order"`
	ScanPayEnabled   int       `gorm:"column:scan_pay_enabled;default:1" json:"scan_pay_enabled"`
	RoyaltyType      string    `gorm:"column:royalty_type" json:"royalty_type"`
	CreatedAt        time.Time `gorm:"column:created_at" json:"created_at"`
	UpdatedAt        time.Time `gorm:"column:updated_at" json:"updated_at"`

	// 关联的证书信息（不存储到数据库）
	Cert *SubjectCert `gorm:"-" json:"cert,omitempty"`
}

// TableName 指定表名
func (Subject) TableName() string {
	return "subject"
}

// IsActive 是否激活
func (s *Subject) IsActive() bool {
	return s.Status == 1
}

// HasCert 是否有证书关联
func (s *Subject) HasCert() bool {
	return s.Cert != nil && s.Cert.HasCertContent()
}

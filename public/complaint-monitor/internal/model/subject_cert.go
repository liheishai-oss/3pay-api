package model

import "time"

// SubjectCert 主体证书模型
type SubjectCert struct {
	ID               int       `gorm:"column:id;primaryKey" json:"id"`
	SubjectID        int       `gorm:"column:subject_id;not null;index:idx_subject_id" json:"subject_id"`
	AppPrivateKey    string    `gorm:"column:app_private_key;type:text" json:"-"` // 不输出到JSON
	AppPublicCert    string    `gorm:"column:app_public_cert;type:text" json:"-"`
	AlipayRootCert   string    `gorm:"column:alipay_root_cert;type:text" json:"-"`
	AlipayPublicCert string    `gorm:"column:alipay_public_cert;type:text" json:"-"`
	CreatedAt        time.Time `gorm:"column:created_at" json:"created_at"`
	UpdatedAt        time.Time `gorm:"column:updated_at" json:"updated_at"`
}

// TableName 指定表名
func (SubjectCert) TableName() string {
	return "subject_cert"
}

// HasCertContent 是否有证书内容
func (sc *SubjectCert) HasCertContent() bool {
	return sc.AppPrivateKey != "" &&
		sc.AppPublicCert != "" &&
		sc.AlipayRootCert != "" &&
		sc.AlipayPublicCert != ""
}

// UseDatabaseCert 是否使用数据库存储的证书（始终返回true，因为都存在数据库中）
func (sc *SubjectCert) UseDatabaseCert() bool {
	return true
}

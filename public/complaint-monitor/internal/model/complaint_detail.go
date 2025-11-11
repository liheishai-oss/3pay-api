package model

import "time"

// ComplaintDetail 投诉详情模型（订单维度）
type ComplaintDetail struct {
	ID              uint       `gorm:"column:id;primaryKey" json:"id"`
	ComplaintID     uint       `gorm:"column:complaint_id;not null;index:idx_complaint_id;uniqueIndex:uniq_complaint_order,priority:1" json:"complaint_id"`
	SubjectID       int        `gorm:"column:subject_id;not null;index:idx_subject_id" json:"subject_id"`
	AgentID         int        `gorm:"column:agent_id;index:idx_agent_id" json:"agent_id"`                                                                                         // 代理商ID（从订单号提取）
	ComplaintNo     string     `gorm:"column:complaint_no;not null;size:64;index:idx_complaint_no" json:"complaint_no"`                                                            // 被投诉的订单号（OutTradeNo）
	MerchantOrderNo string     `gorm:"column:merchant_order_no;not null;size:64;uniqueIndex:uniq_complaint_order,priority:2;index:idx_merchant_order_no" json:"merchant_order_no"` // 商户订单号
	PlatformOrderNo string     `gorm:"column:platform_order_no;size:64" json:"platform_order_no"`
	OrderAmount     float64    `gorm:"column:order_amount;type:decimal(10,2)" json:"order_amount"`
	ComplaintAmount float64    `gorm:"column:complaint_amount;type:decimal(10,2)" json:"complaint_amount"` // 投诉金额（用户申请退款的金额）
	IsPushed        int        `gorm:"column:is_pushed;default:0;index:idx_is_pushed" json:"is_pushed"`    // 0-未推送 1-已推送
	PushedAt        *time.Time `gorm:"column:pushed_at" json:"pushed_at"`
	CreatedAt       time.Time  `gorm:"column:created_at" json:"created_at"`
	UpdatedAt       time.Time  `gorm:"column:updated_at" json:"updated_at"`
}

// TableName 指定表名
func (ComplaintDetail) TableName() string {
	return "alipay_complaint_detail"
}

// IsPushedStatus 是否已推送
func (c *ComplaintDetail) IsPushedStatus() bool {
	return c.IsPushed == 1
}

// MarkAsPushed 标记为已推送
func (c *ComplaintDetail) MarkAsPushed() {
	c.IsPushed = 1
	now := time.Now()
	c.PushedAt = &now
}

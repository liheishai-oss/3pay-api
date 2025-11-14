package model

import "time"

// Order 订单模型（对应PHP的order表）
type Order struct {
	ID              uint       `gorm:"column:id;primaryKey" json:"id"`
	PlatformOrderNo string     `gorm:"column:platform_order_no;size:64;uniqueIndex:uk_platform_order_no" json:"platform_order_no"`
	MerchantOrderNo string     `gorm:"column:merchant_order_no;size:64;index:idx_merchant_order_no" json:"merchant_order_no"`
	AlipayOrderNo   string     `gorm:"column:alipay_order_no;size:64;index:idx_alipay_order_no" json:"alipay_order_no"`
	BuyerID         string     `gorm:"column:buyer_id;size:64;index:idx_buyer_id" json:"buyer_id"`
	OrderAmount     float64    `gorm:"column:order_amount;type:decimal(15,2)" json:"order_amount"`
	PayStatus       int        `gorm:"column:pay_status;index:idx_pay_status" json:"pay_status"`
	PayTime         *time.Time `gorm:"column:pay_time;index:idx_pay_time" json:"pay_time"`
	FirstOpenIP     string     `gorm:"column:first_open_ip;size:45" json:"first_open_ip"`
	PayIP           string     `gorm:"column:pay_ip;size:45" json:"pay_ip"`
	CreatedAt       time.Time  `gorm:"column:created_at" json:"created_at"`
	UpdatedAt       time.Time  `gorm:"column:updated_at" json:"updated_at"`
}

// TableName 指定表名
func (Order) TableName() string {
	return "order"
}

// HasBuyerID 检查是否有购买者UID
func (o *Order) HasBuyerID() bool {
	return o.BuyerID != ""
}

// IsPaid 检查订单是否已支付
func (o *Order) IsPaid() bool {
	// PHP中 PAY_STATUS_PAID = 1
	return o.PayStatus == 1
}




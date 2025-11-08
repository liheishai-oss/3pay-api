package model

import (
	"regexp"
	"strconv"
	"time"
)

// 投诉处理状态常量
const (
	ComplaintStatusWaitProcess          = "WAIT_PROCESS"           // 待处理
	ComplaintStatusProcessing           = "PROCESSING"             // 处理中
	ComplaintStatusProcessed            = "PROCESSED"              // 处理完成
	ComplaintStatusOverdue              = "OVERDUE"                // 超时未处理
	ComplaintStatusOverdueProcessed     = "OVERDUE_PROCESSED"      // 超时处理完成
	ComplaintStatusPartOverdue          = "PART_OVERDUE"           // 部分超时未处理
	ComplaintStatusDropComplain         = "DROP_COMPLAIN"          // 用户撤诉
	ComplaintStatusDropProcessed        = "DROP_PROCESSED"         // 处理完成用户撤诉
	ComplaintStatusDropOverdueComplain  = "DROP_OVERDUE_COMPLAIN"  // 超时后用户撤诉
	ComplaintStatusDropOverdueProcessed = "DROP_OVERDUE_PROCESSED" // 超时处理完成用户撤诉
)

// Complaint 投诉主表模型
type Complaint struct {
	ID               uint       `gorm:"column:id;primaryKey" json:"id"`
	SubjectID        int        `gorm:"column:subject_id;not null;index:idx_subject_id" json:"subject_id"`
	AgentID          int        `gorm:"column:agent_id;index:idx_agent_id" json:"agent_id"` // 代理商ID（从订单号提取）
	ComplaintNo      string     `gorm:"column:complaint_no;not null;size:64;uniqueIndex:uniq_subject_complaint" json:"complaint_no"`
	ComplaintStatus  string     `gorm:"column:complaint_status;size:20;index:idx_status" json:"complaint_status"`
	ComplainantID    string     `gorm:"column:complainant_id;size:64;index:idx_complainant_id" json:"complainant_id"` // 投诉人支付宝用户ID
	ComplaintTime    *time.Time `gorm:"column:complaint_time;index:idx_complaint_time" json:"complaint_time"`
	ComplaintReason  string     `gorm:"column:complaint_reason;type:text" json:"complaint_reason"`
	RefundAmount     float64    `gorm:"column:refund_amount;type:decimal(10,2);default:0" json:"refund_amount"` // 已退款金额（商家处理投诉时的实际退款金额）
	MerchantFeedback string     `gorm:"column:merchant_feedback;type:text" json:"merchant_feedback"`            // 商家反馈内容
	FeedbackImages   string     `gorm:"column:feedback_images;type:text" json:"feedback_images"`                // 反馈凭证图片JSON
	FeedbackTime     *time.Time `gorm:"column:feedback_time;index:idx_feedback_time" json:"feedback_time"`      // 商家反馈时间
	HandlerID        int        `gorm:"column:handler_id;index:idx_handler_id" json:"handler_id"`               // 处理人ID
	GmtCreate        string     `gorm:"column:gmt_create;size:32" json:"gmt_create"`
	GmtModified      string     `gorm:"column:gmt_modified;size:32" json:"gmt_modified"`
	CreatedAt        time.Time  `gorm:"column:created_at" json:"created_at"`
	UpdatedAt        time.Time  `gorm:"column:updated_at" json:"updated_at"`

	// 关联的订单详情（不存储到数据库）
	Details []ComplaintDetail `gorm:"-" json:"details,omitempty"`
}

// TableName 指定表名
func (Complaint) TableName() string {
	return "alipay_complaint"
}

// GetComplaintKey 获取投诉唯一键（用于锁）
func (c *Complaint) GetComplaintKey() string {
	return c.ComplaintNo
}

// IsProcessing 是否处理中（待处理或处理中）
func (c *Complaint) IsProcessing() bool {
	return c.ComplaintStatus == ComplaintStatusWaitProcess ||
		c.ComplaintStatus == ComplaintStatusProcessing
}

// IsProcessed 是否已处理完成
func (c *Complaint) IsProcessed() bool {
	return c.ComplaintStatus == ComplaintStatusProcessed ||
		c.ComplaintStatus == ComplaintStatusOverdueProcessed
}

// IsDropped 是否已撤诉
func (c *Complaint) IsDropped() bool {
	return c.ComplaintStatus == ComplaintStatusDropComplain ||
		c.ComplaintStatus == ComplaintStatusDropProcessed ||
		c.ComplaintStatus == ComplaintStatusDropOverdueComplain ||
		c.ComplaintStatus == ComplaintStatusDropOverdueProcessed
}

// IsOverdue 是否超时
func (c *Complaint) IsOverdue() bool {
	return c.ComplaintStatus == ComplaintStatusOverdue ||
		c.ComplaintStatus == ComplaintStatusOverdueProcessed ||
		c.ComplaintStatus == ComplaintStatusPartOverdue ||
		c.ComplaintStatus == ComplaintStatusDropOverdueComplain ||
		c.ComplaintStatus == ComplaintStatusDropOverdueProcessed
}

// ExtractAgentIDFromOrderNo 从订单号中提取代理商ID
// 订单号格式：BY{代理商ID}{日期YYYYMMDD}{其他}
// 例如：BY120251022211850C4CA7731
// - BY: 前缀
// - 1: 代理商ID
// - 20251022: 日期
// - 211850C4CA7731: 其他部分
func ExtractAgentIDFromOrderNo(orderNo string) int {
	if len(orderNo) < 12 { // 至少需要 BY + 1位代理商ID + 8位日期
		return 0
	}

	// 匹配订单号格式：BY开头，后面跟数字，然后是8位日期
	// 提取BY后面、日期前面的数字
	re := regexp.MustCompile(`^BY(\d+)(20\d{6})`)
	matches := re.FindStringSubmatch(orderNo)

	if len(matches) >= 2 {
		agentID, err := strconv.Atoi(matches[1])
		if err == nil {
			return agentID
		}
	}

	return 0
}

// SetAgentIDFromOrderNo 从投诉详情的订单号中提取并设置代理商ID
func (c *Complaint) SetAgentIDFromOrderNo() {
	// 如果已经有代理商ID，则不重复提取
	if c.AgentID > 0 {
		return
	}

	// 从关联的订单详情中提取
	if len(c.Details) > 0 {
		agentID := ExtractAgentIDFromOrderNo(c.Details[0].MerchantOrderNo)
		if agentID > 0 {
			c.AgentID = agentID
		}
	}
}

package service

import (
	"encoding/json"
	"fmt"
	"time"

	"complaint-monitor/internal/model"

	"go.uber.org/zap"
	"gorm.io/gorm"
)

// NotificationService 通知服务
type NotificationService struct {
	db     *gorm.DB
	logger *zap.Logger
}

// NewNotificationService 创建通知服务
func NewNotificationService(db *gorm.DB, logger *zap.Logger) *NotificationService {
	return &NotificationService{
		db:     db,
		logger: logger,
	}
}

// TelegramMessageQueue Telegram消息队列模型
// 匹配数据库表 telegram_message_queue 结构
type TelegramMessageQueue struct {
	ID           uint            `gorm:"column:id;primaryKey" json:"id"`
	Title        string          `gorm:"column:title;not null;size:255" json:"title"`
	Content      string          `gorm:"column:content;type:text" json:"content"`
	Priority     int             `gorm:"column:priority;default:5" json:"priority"`
	Status       string          `gorm:"column:status;type:enum('pending','sending','sent','failed');default:'pending'" json:"status"`
	MessageType  string          `gorm:"column:message_type;default:'text';size:50" json:"message_type"`
	TemplateName *string         `gorm:"column:template_name;size:100" json:"template_name"`
	TemplateData json.RawMessage `gorm:"column:template_data;type:json" json:"template_data"`
	ChatID       *string         `gorm:"column:chat_id;size:100" json:"chat_id"`
	RetryCount   int             `gorm:"column:retry_count;default:0" json:"retry_count"`
	MaxRetry     int             `gorm:"column:max_retry;default:3" json:"max_retry"`
	ErrorMessage *string         `gorm:"column:error_message;type:text" json:"error_message"`
	CreatedAt    time.Time       `gorm:"column:created_at" json:"created_at"`
	UpdatedAt    time.Time       `gorm:"column:updated_at" json:"updated_at"`
	SentAt       *time.Time      `gorm:"column:sent_at" json:"sent_at"`
	ScheduledAt  *time.Time      `gorm:"column:scheduled_at" json:"scheduled_at"`
}

// TableName 指定表名
func (TelegramMessageQueue) TableName() string {
	return "telegram_message_queue"
}

// ComplaintNotificationData 投诉通知数据
type ComplaintNotificationData struct {
	SubjectID             int      `json:"subject_id"`
	SubjectName           string   `json:"subject_name"`
	ComplaintNo           string   `json:"complaint_no"`
	ComplainantID         string   `json:"complainant_id"`
	ComplaintTime         string   `json:"complaint_time"`
	ComplaintReason       string   `json:"complaint_reason"`
	OrderCount            int      `json:"order_count"`
	TotalAmount           float64  `json:"total_amount"`
	MerchantOrderNos      []string `json:"merchant_order_nos"`
	IsAutoBlacklist       bool     `json:"is_auto_blacklist"`
	RiskLevel             string   `json:"risk_level"`
	HistoryComplaintCount int      `json:"history_complaint_count"`
}

// BlacklistNotificationData 黑名单通知数据
// 参考 PHP 的 TelegramMessageQueueService::addBlacklistMessage 方法
type BlacklistNotificationData struct {
	Action       string  `json:"action"`         // 'insert' 或 'update'
	ID           uint    `json:"id"`             // 黑名单记录ID
	AlipayUserID string  `json:"alipay_user_id"` // 支付宝用户ID
	DeviceCode   *string `json:"device_code"`    // 设备码（可能为NULL）
	IPAddress    *string `json:"ip_address"`     // IP地址（可能为NULL）
	RiskCount    int     `json:"risk_count"`     // 风险触发次数
	LastRiskTime string  `json:"last_risk_time"` // 最后风险时间
	Remark       string  `json:"remark"`         // 备注信息
	ComplaintNo  string  `json:"complaint_no"`   // 投诉单号
	SubjectID    int     `json:"subject_id"`     // 主体ID（用于日志）
	SubjectName  string  `json:"subject_name"`   // 主体名称（用于日志）
	Message      string  `json:"message"`        // 处理消息
}

// PushComplaintNotification 推送投诉通知
func (s *NotificationService) PushComplaintNotification(
	complaint *model.Complaint,
	details []*model.ComplaintDetail,
	subject *model.Subject,
	riskLevel string,
	historyCount int,
) error {
	// 构建通知数据
	merchantOrderNos := make([]string, 0, len(details))
	var totalAmount float64
	for _, detail := range details {
		merchantOrderNos = append(merchantOrderNos, detail.MerchantOrderNo)
		totalAmount += detail.OrderAmount
	}

	data := ComplaintNotificationData{
		SubjectID:             subject.ID,
		SubjectName:           subject.CompanyName,
		ComplaintNo:           complaint.ComplaintNo,
		ComplainantID:         complaint.ComplainantID,
		ComplaintTime:         complaint.ComplaintTime.Format("2006-01-02 15:04:05"),
		ComplaintReason:       complaint.ComplaintReason,
		OrderCount:            len(details),
		TotalAmount:           totalAmount,
		MerchantOrderNos:      merchantOrderNos,
		IsAutoBlacklist:       true, // 所有投诉都触发拉黑
		RiskLevel:             riskLevel,
		HistoryComplaintCount: historyCount,
	}

	// 序列化为JSON
	jsonData, err := json.Marshal(data)
	if err != nil {
		return fmt.Errorf("序列化投诉通知数据失败: %w", err)
	}

	// 根据风险等级设置优先级
	priority := s.getPriorityByRiskLevel(riskLevel)

	// 写入消息队列
	templateName := "complaint"
	message := &TelegramMessageQueue{
		Title:        "📋 新投诉通知",
		Content:      "", // 内容由模板生成
		Priority:     priority,
		Status:       "pending",
		MessageType:  "template",
		TemplateName: &templateName,
		TemplateData: jsonData,
		MaxRetry:     3,
		RetryCount:   0,
	}

	if err := s.db.Create(message).Error; err != nil {
		return fmt.Errorf("写入投诉通知队列失败: %w", err)
	}

	s.logger.Info("投诉通知已加入队列",
		zap.String("complaint_no", complaint.ComplaintNo),
		zap.String("risk_level", riskLevel),
		zap.Int("priority", priority))

	return nil
}

// PushBlacklistNotification 推送黑名单通知
// 参考 PHP 的 TelegramMessageQueueService::addBlacklistMessage 方法
func (s *NotificationService) PushBlacklistNotification(
	blacklist *model.AlipayBlacklist,
	subject *model.Subject,
	complaintNo string,
	action string, // 'insert' 或 'update'
	message string, // 处理消息
) error {
	// 构建通知数据（参考 PHP 实现）
	var title string
	if action == "insert" {
		title = "🚨 新用户加入黑名单"
	} else {
		title = "⚠️ 黑名单用户再次触发"
	}

	// 格式化最后风险时间
	lastRiskTimeStr := ""
	if blacklist.LastRiskTime != nil {
		lastRiskTimeStr = blacklist.LastRiskTime.Format("2006-01-02 15:04:05")
	}

	// 构建模板数据
	data := BlacklistNotificationData{
		Action:       action,
		ID:           blacklist.ID,
		AlipayUserID: blacklist.AlipayUserID,
		DeviceCode:   blacklist.DeviceCode,
		IPAddress:    blacklist.IPAddress,
		RiskCount:    blacklist.RiskCount,
		LastRiskTime: lastRiskTimeStr,
		Remark:       blacklist.Remark,
		ComplaintNo:  complaintNo,
		SubjectID:    subject.ID,
		SubjectName:  subject.CompanyName,
		Message:      message,
	}

	// 序列化为JSON
	jsonData, err := json.Marshal(data)
	if err != nil {
		return fmt.Errorf("序列化黑名单通知数据失败: %w", err)
	}

	// 设置模板名称
	templateName := "blacklist"

	// 写入消息队列（参考 PHP 实现）
	// PHP: TelegramMessageQueue::PRIORITY_HIGH = 3
	msg := &TelegramMessageQueue{
		Title:        title,
		Content:      "", // 内容由模板生成
		Priority:     3,  // PRIORITY_HIGH
		Status:       "pending",
		MessageType:  "template",
		TemplateName: &templateName,
		TemplateData: jsonData,
		MaxRetry:     3,
		RetryCount:   0,
	}

	if err := s.db.Create(msg).Error; err != nil {
		return fmt.Errorf("写入黑名单通知队列失败: %w", err)
	}

	s.logger.Info("黑名单通知已加入队列",
		zap.Uint("message_id", msg.ID),
		zap.String("title", title),
		zap.String("alipay_user_id", blacklist.AlipayUserID),
		zap.String("action", action),
		zap.Int("priority", msg.Priority))

	return nil
}

// getPriorityByRiskLevel 根据风险等级获取优先级
func (s *NotificationService) getPriorityByRiskLevel(riskLevel string) int {
	switch riskLevel {
	case "critical":
		return 1 // 最高优先级
	case "high":
		return 2
	case "medium":
		return 5
	case "low":
		return 7
	default:
		return 5 // 默认中等优先级
	}
}

// GetPendingCount 获取待推送消息数量
func (s *NotificationService) GetPendingCount() (int64, error) {
	var count int64
	err := s.db.Model(&TelegramMessageQueue{}).Where("status = ?", 0).Count(&count).Error
	if err != nil {
		return 0, err
	}
	return count, nil
}

package service

import (
	"encoding/json"
	"fmt"

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
type TelegramMessageQueue struct {
	ID           uint            `gorm:"column:id;primaryKey"`
	TemplateType string          `gorm:"column:template_type;not null"`
	TemplateData json.RawMessage `gorm:"column:template_data;type:json"`
	Status       int             `gorm:"column:status;default:0"`
	Priority     int             `gorm:"column:priority;default:5"`
	RetryCount   int             `gorm:"column:retry_count;default:0"`
	ErrorMessage string          `gorm:"column:error_message;type:text"`
	PushedAt     *string         `gorm:"column:pushed_at"`
	CreatedAt    string          `gorm:"column:created_at"`
	UpdatedAt    string          `gorm:"column:updated_at"`
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
type BlacklistNotificationData struct {
	SubjectID      int    `json:"subject_id"`
	SubjectName    string `json:"subject_name"`
	AlipayUserID   string `json:"alipay_user_id"`
	DeviceCode     string `json:"device_code"`
	IPAddress      string `json:"ip_address"`
	RiskLevel      string `json:"risk_level"`
	ComplaintCount int    `json:"complaint_count"`
	ComplaintNo    string `json:"complaint_no"`
	OrderCount     int    `json:"order_count"`
	ComplaintTime  string `json:"complaint_time"`
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
	message := &TelegramMessageQueue{
		TemplateType: "complaint",
		TemplateData: jsonData,
		Status:       0, // 待推送
		Priority:     priority,
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
func (s *NotificationService) PushBlacklistNotification(
	blacklist *model.AlipayBlacklist,
	subject *model.Subject,
	complaintNo string,
	orderCount int,
	complaintTime string,
) error {
	// 构建通知数据
	data := BlacklistNotificationData{
		SubjectID:      subject.ID,
		SubjectName:    subject.CompanyName,
		AlipayUserID:   blacklist.AlipayUserID,
		DeviceCode:     blacklist.DeviceCode,
		IPAddress:      blacklist.IPAddress,
		RiskLevel:      blacklist.RiskLevel,
		ComplaintCount: blacklist.ComplaintCount,
		ComplaintNo:    complaintNo,
		OrderCount:     orderCount,
		ComplaintTime:  complaintTime,
	}

	// 序列化为JSON
	jsonData, err := json.Marshal(data)
	if err != nil {
		return fmt.Errorf("序列化黑名单通知数据失败: %w", err)
	}

	// 根据风险等级设置优先级
	priority := s.getPriorityByRiskLevel(blacklist.RiskLevel)

	// 写入消息队列
	message := &TelegramMessageQueue{
		TemplateType: "blacklist",
		TemplateData: jsonData,
		Status:       0, // 待推送
		Priority:     priority,
	}

	if err := s.db.Create(message).Error; err != nil {
		return fmt.Errorf("写入黑名单通知队列失败: %w", err)
	}

	s.logger.Info("黑名单通知已加入队列",
		zap.String("alipay_user_id", blacklist.AlipayUserID),
		zap.String("risk_level", blacklist.RiskLevel),
		zap.Int("priority", priority))

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

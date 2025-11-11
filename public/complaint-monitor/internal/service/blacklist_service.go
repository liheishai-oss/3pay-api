package service

import (
	"fmt"
	"time"

	"complaint-monitor/internal/model"
	"complaint-monitor/internal/repository"

	"go.uber.org/zap"
)

// BlacklistService 黑名单服务
type BlacklistService struct {
	blacklistRepo       *repository.BlacklistRepository
	complaintRepo       *repository.ComplaintRepository
	notificationService *NotificationService
	logger              *zap.Logger
}

// NewBlacklistService 创建黑名单服务
func NewBlacklistService(
	blacklistRepo *repository.BlacklistRepository,
	complaintRepo *repository.ComplaintRepository,
	notificationService *NotificationService,
	logger *zap.Logger,
) *BlacklistService {
	return &BlacklistService{
		blacklistRepo:       blacklistRepo,
		complaintRepo:       complaintRepo,
		notificationService: notificationService,
		logger:              logger,
	}
}

// RiskLevel 风险等级
type RiskLevel string

const (
	RiskLevelLow      RiskLevel = "low"      // 低风险：1次投诉
	RiskLevelMedium   RiskLevel = "medium"   // 中风险：24h内2-3次或金额500-1000元
	RiskLevelHigh     RiskLevel = "high"     // 高风险：24h内4+次或金额>1000元
	RiskLevelCritical RiskLevel = "critical" // 极高风险：历史5+次或涉及10+订单
)

// AddToBlacklist 添加到黑名单（所有投诉都触发拉黑）
// 注意：现有表结构使用 (alipay_user_id, device_code, ip_address) 作为唯一键
// 根据购买者ID、设备码、IP判断是否已经拉黑过，防止重复拉黑
// 仅首次拉黑时写入消息队列到 telegram_message_queue 表，重复触发不写入消息队列
func (s *BlacklistService) AddToBlacklist(
	subject *model.Subject, // 主体信息（用于消息通知）
	alipayUserID string,
	deviceCode string,
	ipAddress string,
	complaintNo string,
) error {
	subjectID := subject.ID
	// 1. 先检查是否已经存在（根据购买者ID、设备码、IP）
	exists, existingBlacklist, err := s.CheckBlacklistByUniqueKey(alipayUserID, deviceCode, ipAddress)
	if err != nil {
		s.logger.Error("检查黑名单是否存在失败",
			zap.Int("subject_id", subjectID),
			zap.String("alipay_user_id", alipayUserID),
			zap.String("device_code", deviceCode),
			zap.String("ip_address", ipAddress),
			zap.Error(err))
		return fmt.Errorf("检查黑名单是否存在失败: %w", err)
	}

	// 2. 如果已存在，更新风险计数和最后风险时间
	if exists && existingBlacklist != nil {
		s.logger.Info("黑名单记录已存在，更新风险计数",
			zap.Int("subject_id", subjectID),
			zap.String("alipay_user_id", alipayUserID),
			zap.String("device_code", deviceCode),
			zap.String("ip_address", ipAddress),
			zap.Int("current_risk_count", existingBlacklist.RiskCount),
			zap.String("complaint_no", complaintNo))

		// 更新风险计数（累加）
		err = s.IncrementRiskCount(alipayUserID, deviceCode, ipAddress)
		if err != nil {
			s.logger.Error("更新风险计数失败",
				zap.Int("subject_id", subjectID),
				zap.String("alipay_user_id", alipayUserID),
				zap.Error(err))
			return fmt.Errorf("更新风险计数失败: %w", err)
		}

		// 重复触发：只更新风险计数，不写入消息队列
		s.logger.Info("黑名单记录已存在（重复触发），仅更新风险计数，不写入消息队列",
			zap.Int("subject_id", subjectID),
			zap.String("alipay_user_id", alipayUserID),
			zap.String("device_code", deviceCode),
			zap.String("ip_address", ipAddress),
			zap.Int("current_risk_count", existingBlacklist.RiskCount),
			zap.String("complaint_no", complaintNo))

		return nil
	}

	// 3. 如果不存在，插入新记录
	// 查询历史投诉次数（用于计算风险计数）
	historyCount, err := s.complaintRepo.CountByComplainant(subjectID, alipayUserID)
	if err != nil {
		s.logger.Error("查询历史投诉次数失败", zap.Error(err))
		historyCount = 1 // 默认为1
	}

	// 构建黑名单记录
	// 注意：现有表结构没有 subject_id, blacklist_type, risk_level 字段
	// 使用 risk_count 存储投诉次数，last_risk_time 存储最后投诉时间
	// 唯一索引是 (alipay_user_id, device_code, ip_address)
	// 如果 device_code 或 ip_address 为空，使用 NULL（通过指针类型实现）
	blacklist := &model.AlipayBlacklist{
		AlipayUserID: alipayUserID,
		RiskCount:    int(historyCount), // 使用风险触发次数存储投诉次数
		LastRiskTime: timePtr(time.Now()),
		Remark:       fmt.Sprintf("投诉触发自动拉黑，投诉单号：%s", complaintNo),
	}

	// 设置设备码（如果为空，使用nil，存储为NULL）
	if deviceCode != "" {
		blacklist.DeviceCode = &deviceCode
	} else {
		blacklist.DeviceCode = nil // 存储为NULL
	}

	// 设置IP地址（如果为空，使用nil，存储为NULL）
	if ipAddress != "" {
		blacklist.IPAddress = &ipAddress
	} else {
		blacklist.IPAddress = nil // 存储为NULL
	}

	// 插入新记录
	if err := s.blacklistRepo.Create(blacklist); err != nil {
		s.logger.Error("插入黑名单失败",
			zap.Int("subject_id", subjectID),
			zap.String("alipay_user_id", alipayUserID),
			zap.String("device_code", deviceCode),
			zap.String("ip_address", ipAddress),
			zap.Error(err))
		return fmt.Errorf("插入黑名单失败: %w", err)
	}

	s.logger.Info("新增黑名单记录成功",
		zap.Int("subject_id", subjectID),
		zap.String("alipay_user_id", alipayUserID),
		zap.String("device_code", deviceCode),
		zap.String("ip_address", ipAddress),
		zap.Int("risk_count", blacklist.RiskCount),
		zap.Int64("history_count", historyCount),
		zap.String("complaint_no", complaintNo))

	// 写入消息队列（参考 PHP 实现）
	if s.notificationService != nil {
		err = s.notificationService.PushBlacklistNotification(
			blacklist,
			subject,
			complaintNo,
			"insert",
			"用户首次命中风险，已新增黑名单记录",
		)
		if err != nil {
			s.logger.Error("写入消息队列失败",
				zap.Int("subject_id", subjectID),
				zap.String("alipay_user_id", alipayUserID),
				zap.Error(err))
			// 消息队列写入失败不影响主流程，只记录错误日志
		}
	}

	return nil
}

// CheckBlacklist 检查是否在黑名单中（仅检查 alipay_user_id）
func (s *BlacklistService) CheckBlacklist(alipayUserID string) (bool, *model.AlipayBlacklist, error) {
	exists, err := s.blacklistRepo.Exists(alipayUserID)
	if err != nil {
		return false, nil, err
	}

	if !exists {
		return false, nil, nil
	}

	blacklist, err := s.blacklistRepo.FindByAlipayUserID(alipayUserID)
	if err != nil {
		return true, nil, err
	}

	return true, blacklist, nil
}

// CheckBlacklistByUniqueKey 检查是否在黑名单中（使用唯一键）
func (s *BlacklistService) CheckBlacklistByUniqueKey(alipayUserID, deviceCode, ipAddress string) (bool, *model.AlipayBlacklist, error) {
	exists, err := s.blacklistRepo.ExistsByUniqueKey(alipayUserID, deviceCode, ipAddress)
	if err != nil {
		return false, nil, err
	}

	if !exists {
		return false, nil, nil
	}

	blacklist, err := s.blacklistRepo.FindByUniqueKey(alipayUserID, deviceCode, ipAddress)
	if err != nil {
		return true, nil, err
	}

	return true, blacklist, nil
}

// IncrementRiskCount 增加风险触发次数
func (s *BlacklistService) IncrementRiskCount(alipayUserID, deviceCode, ipAddress string) error {
	err := s.blacklistRepo.IncrementRiskCount(alipayUserID, deviceCode, ipAddress)
	if err != nil {
		return fmt.Errorf("增加风险触发次数失败: %w", err)
	}

	s.logger.Debug("增加风险触发次数成功",
		zap.String("alipay_user_id", alipayUserID),
		zap.String("device_code", deviceCode),
		zap.String("ip_address", ipAddress))

	return nil
}

// GetBlacklistStats 获取黑名单统计
func (s *BlacklistService) GetBlacklistStats() (map[string]interface{}, error) {
	// 统计总数
	totalCount, err := s.blacklistRepo.CountAll()
	if err != nil {
		return nil, err
	}

	return map[string]interface{}{
		"total": totalCount,
	}, nil
}

// timePtr 返回时间指针
func timePtr(t time.Time) *time.Time {
	return &t
}

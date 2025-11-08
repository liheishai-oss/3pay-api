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
	blacklistRepo *repository.BlacklistRepository
	complaintRepo *repository.ComplaintRepository
	logger        *zap.Logger
}

// NewBlacklistService 创建黑名单服务
func NewBlacklistService(
	blacklistRepo *repository.BlacklistRepository,
	complaintRepo *repository.ComplaintRepository,
	logger *zap.Logger,
) *BlacklistService {
	return &BlacklistService{
		blacklistRepo: blacklistRepo,
		complaintRepo: complaintRepo,
		logger:        logger,
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
func (s *BlacklistService) AddToBlacklist(
	subjectID int,
	alipayUserID string,
	deviceCode string,
	ipAddress string,
	complaintNo string,
) error {
	// 评估风险等级
	riskLevel, err := s.AssessRisk(subjectID, alipayUserID)
	if err != nil {
		s.logger.Error("风险评估失败",
			zap.Int("subject_id", subjectID),
			zap.String("alipay_user_id", alipayUserID),
			zap.Error(err))
		return err
	}

	// 查询历史投诉次数
	historyCount, err := s.complaintRepo.CountByComplainant(subjectID, alipayUserID)
	if err != nil {
		s.logger.Error("查询历史投诉次数失败", zap.Error(err))
		historyCount = 1 // 默认为1
	}

	// 构建黑名单记录
	blacklist := &model.AlipayBlacklist{
		SubjectID:         subjectID,
		AlipayUserID:      alipayUserID,
		DeviceCode:        deviceCode,
		IPAddress:         ipAddress,
		BlacklistType:     "complaint",
		RiskLevel:         string(riskLevel),
		ComplaintCount:    int(historyCount),
		LastComplaintTime: timePtr(time.Now()),
		Remark:            fmt.Sprintf("投诉触发自动拉黑，投诉单号：%s", complaintNo),
	}

	// Upsert黑名单
	if err := s.blacklistRepo.Upsert(blacklist); err != nil {
		s.logger.Error("添加黑名单失败",
			zap.Int("subject_id", subjectID),
			zap.String("alipay_user_id", alipayUserID),
			zap.Error(err))
		return err
	}

	s.logger.Info("添加黑名单成功",
		zap.Int("subject_id", subjectID),
		zap.String("alipay_user_id", alipayUserID),
		zap.String("risk_level", string(riskLevel)),
		zap.Int64("history_count", historyCount))

	return nil
}

// AssessRisk 评估风险等级
func (s *BlacklistService) AssessRisk(subjectID int, alipayUserID string) (RiskLevel, error) {
	// 查询历史投诉次数
	historyCount, err := s.complaintRepo.CountByComplainant(subjectID, alipayUserID)
	if err != nil {
		return RiskLevelLow, fmt.Errorf("查询历史投诉次数失败: %w", err)
	}

	// 根据历史投诉次数评估风险等级
	switch {
	case historyCount >= 5:
		return RiskLevelCritical, nil // 极高风险：历史5+次
	case historyCount >= 4:
		return RiskLevelHigh, nil // 高风险：4+次
	case historyCount >= 2:
		return RiskLevelMedium, nil // 中风险：2-3次
	default:
		return RiskLevelLow, nil // 低风险：1次
	}

	// TODO: 可以根据更多维度评估风险
	// - 24小时内投诉次数
	// - 投诉金额
	// - 投诉类型
	// - 涉及订单数量
}

// CheckBlacklist 检查是否在黑名单中
func (s *BlacklistService) CheckBlacklist(subjectID int, alipayUserID string) (bool, *model.AlipayBlacklist, error) {
	exists, err := s.blacklistRepo.Exists(subjectID, alipayUserID)
	if err != nil {
		return false, nil, err
	}

	if !exists {
		return false, nil, nil
	}

	blacklist, err := s.blacklistRepo.FindByAlipayUserID(subjectID, alipayUserID)
	if err != nil {
		return true, nil, err
	}

	return true, blacklist, nil
}

// UpdateRiskLevel 更新风险等级
func (s *BlacklistService) UpdateRiskLevel(subjectID int, alipayUserID string, riskLevel RiskLevel) error {
	err := s.blacklistRepo.UpdateRiskLevel(subjectID, alipayUserID, string(riskLevel))
	if err != nil {
		return fmt.Errorf("更新风险等级失败: %w", err)
	}

	s.logger.Info("更新风险等级成功",
		zap.Int("subject_id", subjectID),
		zap.String("alipay_user_id", alipayUserID),
		zap.String("risk_level", string(riskLevel)))

	return nil
}

// IncrementComplaintCount 增加投诉次数
func (s *BlacklistService) IncrementComplaintCount(subjectID int, alipayUserID string) error {
	err := s.blacklistRepo.IncrementComplaintCount(subjectID, alipayUserID)
	if err != nil {
		return fmt.Errorf("增加投诉次数失败: %w", err)
	}

	s.logger.Debug("增加投诉次数成功",
		zap.Int("subject_id", subjectID),
		zap.String("alipay_user_id", alipayUserID))

	return nil
}

// GetBlacklistStats 获取黑名单统计
func (s *BlacklistService) GetBlacklistStats(subjectID int) (map[string]interface{}, error) {
	// 统计总数
	totalCount, err := s.blacklistRepo.CountBySubject(subjectID)
	if err != nil {
		return nil, err
	}

	// 统计各风险等级数量
	riskCounts, err := s.blacklistRepo.CountByRiskLevel(subjectID)
	if err != nil {
		return nil, err
	}

	return map[string]interface{}{
		"total":    totalCount,
		"low":      riskCounts["low"],
		"medium":   riskCounts["medium"],
		"high":     riskCounts["high"],
		"critical": riskCounts["critical"],
	}, nil
}

// timePtr 返回时间指针
func timePtr(t time.Time) *time.Time {
	return &t
}

package repository

import (
	"fmt"
	"time"

	"complaint-monitor/internal/model"

	"go.uber.org/zap"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// BlacklistRepository 黑名单仓库
type BlacklistRepository struct {
	*BaseRepository
}

// NewBlacklistRepository 创建黑名单仓库
func NewBlacklistRepository(db *gorm.DB, logger *zap.Logger) *BlacklistRepository {
	return &BlacklistRepository{
		BaseRepository: NewBaseRepository(db, logger),
	}
}

// Create 创建黑名单记录
func (r *BlacklistRepository) Create(blacklist *model.AlipayBlacklist) error {
	err := r.db.Create(blacklist).Error
	if err != nil {
		return fmt.Errorf("创建黑名单记录失败: %w", err)
	}
	return nil
}

// FindByAlipayUserID 根据支付宝用户ID查找
func (r *BlacklistRepository) FindByAlipayUserID(subjectID int, alipayUserID string) (*model.AlipayBlacklist, error) {
	var blacklist model.AlipayBlacklist
	err := r.db.Where("subject_id = ? AND alipay_user_id = ?", subjectID, alipayUserID).First(&blacklist).Error
	if err != nil {
		if err == gorm.ErrRecordNotFound {
			return nil, nil // 不存在返回nil
		}
		return nil, fmt.Errorf("查询黑名单失败: %w", err)
	}
	return &blacklist, nil
}

// Exists 检查是否在黑名单中
func (r *BlacklistRepository) Exists(subjectID int, alipayUserID string) (bool, error) {
	var count int64
	err := r.db.Model(&model.AlipayBlacklist{}).
		Where("subject_id = ? AND alipay_user_id = ?", subjectID, alipayUserID).
		Count(&count).Error
	if err != nil {
		return false, fmt.Errorf("检查黑名单是否存在失败: %w", err)
	}
	return count > 0, nil
}

// Upsert 插入或更新（使用ON DUPLICATE KEY UPDATE）
func (r *BlacklistRepository) Upsert(blacklist *model.AlipayBlacklist) error {
	// 使用Clauses实现ON DUPLICATE KEY UPDATE
	err := r.db.Clauses(clause.OnConflict{
		Columns: []clause.Column{{Name: "subject_id"}, {Name: "alipay_user_id"}},
		DoUpdates: clause.AssignmentColumns([]string{
			"device_code",
			"ip_address",
			"blacklist_type",
			"risk_level",
			"complaint_count",
			"last_complaint_time",
			"remark",
		}),
	}).Create(blacklist).Error
	
	if err != nil {
		return fmt.Errorf("插入或更新黑名单失败: %w", err)
	}
	return nil
}

// Update 更新黑名单
func (r *BlacklistRepository) Update(blacklist *model.AlipayBlacklist) error {
	err := r.db.Save(blacklist).Error
	if err != nil {
		return fmt.Errorf("更新黑名单失败: %w", err)
	}
	return nil
}

// IncrementComplaintCount 增加投诉次数
func (r *BlacklistRepository) IncrementComplaintCount(subjectID int, alipayUserID string) error {
	err := r.db.Model(&model.AlipayBlacklist{}).
		Where("subject_id = ? AND alipay_user_id = ?", subjectID, alipayUserID).
		Updates(map[string]interface{}{
			"complaint_count":      gorm.Expr("complaint_count + 1"),
			"last_complaint_time":  time.Now(),
		}).Error
	if err != nil {
		return fmt.Errorf("增加投诉次数失败: %w", err)
	}
	return nil
}

// UpdateRiskLevel 更新风险等级
func (r *BlacklistRepository) UpdateRiskLevel(subjectID int, alipayUserID, riskLevel string) error {
	err := r.db.Model(&model.AlipayBlacklist{}).
		Where("subject_id = ? AND alipay_user_id = ?", subjectID, alipayUserID).
		Update("risk_level", riskLevel).Error
	if err != nil {
		return fmt.Errorf("更新风险等级失败: %w", err)
	}
	return nil
}

// FindByRiskLevel 根据风险等级查找
func (r *BlacklistRepository) FindByRiskLevel(subjectID int, riskLevel string) ([]*model.AlipayBlacklist, error) {
	var blacklists []*model.AlipayBlacklist
	err := r.db.Where("subject_id = ? AND risk_level = ?", subjectID, riskLevel).
		Find(&blacklists).Error
	if err != nil {
		return nil, fmt.Errorf("查询风险等级黑名单失败: %w", err)
	}
	return blacklists, nil
}

// CountBySubject 统计主体的黑名单数量
func (r *BlacklistRepository) CountBySubject(subjectID int) (int64, error) {
	var count int64
	err := r.db.Model(&model.AlipayBlacklist{}).
		Where("subject_id = ?", subjectID).
		Count(&count).Error
	if err != nil {
		return 0, fmt.Errorf("统计黑名单数量失败: %w", err)
	}
	return count, nil
}

// CountByRiskLevel 统计各风险等级的数量
func (r *BlacklistRepository) CountByRiskLevel(subjectID int) (map[string]int64, error) {
	type Result struct {
		RiskLevel string
		Count     int64
	}
	
	var results []Result
	err := r.db.Model(&model.AlipayBlacklist{}).
		Select("risk_level, COUNT(*) as count").
		Where("subject_id = ?", subjectID).
		Group("risk_level").
		Scan(&results).Error
		
	if err != nil {
		return nil, fmt.Errorf("统计风险等级数量失败: %w", err)
	}
	
	counts := make(map[string]int64)
	for _, result := range results {
		counts[result.RiskLevel] = result.Count
	}
	
	return counts, nil
}

// Delete 删除黑名单
func (r *BlacklistRepository) Delete(id uint) error {
	err := r.db.Delete(&model.AlipayBlacklist{}, id).Error
	if err != nil {
		return fmt.Errorf("删除黑名单失败: %w", err)
	}
	return nil
}


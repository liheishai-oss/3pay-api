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
// 注意：现有表结构使用 (alipay_user_id, device_code, ip_address) 作为唯一键
func (r *BlacklistRepository) FindByAlipayUserID(alipayUserID string) (*model.AlipayBlacklist, error) {
	var blacklist model.AlipayBlacklist
	err := r.db.Where("alipay_user_id = ?", alipayUserID).First(&blacklist).Error
	if err != nil {
		if err == gorm.ErrRecordNotFound {
			return nil, nil // 不存在返回nil
		}
		return nil, fmt.Errorf("查询黑名单失败: %w", err)
	}
	return &blacklist, nil
}

// FindByUniqueKey 根据唯一键查找（alipay_user_id, device_code, ip_address）
// 注意：device_code 和 ip_address 可能为 NULL（空字符串会被转换为 NULL）
func (r *BlacklistRepository) FindByUniqueKey(alipayUserID, deviceCode, ipAddress string) (*model.AlipayBlacklist, error) {
	var blacklist model.AlipayBlacklist
	query := r.db.Where("alipay_user_id = ?", alipayUserID)

	// 处理 device_code（可能为 NULL）
	if deviceCode == "" {
		query = query.Where("device_code IS NULL")
	} else {
		query = query.Where("device_code = ?", deviceCode)
	}

	// 处理 ip_address（可能为 NULL）
	if ipAddress == "" {
		query = query.Where("ip_address IS NULL")
	} else {
		query = query.Where("ip_address = ?", ipAddress)
	}

	err := query.First(&blacklist).Error
	if err != nil {
		if err == gorm.ErrRecordNotFound {
			return nil, nil // 不存在返回nil
		}
		return nil, fmt.Errorf("查询黑名单失败: %w", err)
	}
	return &blacklist, nil
}

// Exists 检查是否在黑名单中（仅检查 alipay_user_id）
func (r *BlacklistRepository) Exists(alipayUserID string) (bool, error) {
	var count int64
	err := r.db.Model(&model.AlipayBlacklist{}).
		Where("alipay_user_id = ?", alipayUserID).
		Count(&count).Error
	if err != nil {
		return false, fmt.Errorf("检查黑名单是否存在失败: %w", err)
	}
	return count > 0, nil
}

// ExistsByUniqueKey 检查是否在黑名单中（使用唯一键）
// 注意：device_code 和 ip_address 可能为 NULL（空字符串会被转换为 NULL）
func (r *BlacklistRepository) ExistsByUniqueKey(alipayUserID, deviceCode, ipAddress string) (bool, error) {
	var count int64
	query := r.db.Model(&model.AlipayBlacklist{}).
		Where("alipay_user_id = ?", alipayUserID)

	// 处理 device_code（可能为 NULL）
	if deviceCode == "" {
		query = query.Where("device_code IS NULL")
	} else {
		query = query.Where("device_code = ?", deviceCode)
	}

	// 处理 ip_address（可能为 NULL）
	if ipAddress == "" {
		query = query.Where("ip_address IS NULL")
	} else {
		query = query.Where("ip_address = ?", ipAddress)
	}

	err := query.Count(&count).Error
	if err != nil {
		return false, fmt.Errorf("检查黑名单是否存在失败: %w", err)
	}
	return count > 0, nil
}

// Upsert 插入或更新（使用ON DUPLICATE KEY UPDATE）
// 注意：唯一键是 (alipay_user_id, device_code, ip_address)
func (r *BlacklistRepository) Upsert(blacklist *model.AlipayBlacklist) error {
	// 使用Clauses实现ON DUPLICATE KEY UPDATE
	err := r.db.Clauses(clause.OnConflict{
		Columns: []clause.Column{
			{Name: "alipay_user_id"},
			{Name: "device_code"},
			{Name: "ip_address"},
		},
		DoUpdates: clause.AssignmentColumns([]string{
			"risk_count",
			"last_risk_time",
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

// IncrementRiskCount 增加风险触发次数
// 注意：device_code 和 ip_address 可能为 NULL（空字符串会被转换为 NULL）
func (r *BlacklistRepository) IncrementRiskCount(alipayUserID, deviceCode, ipAddress string) error {
	query := r.db.Model(&model.AlipayBlacklist{}).
		Where("alipay_user_id = ?", alipayUserID)

	// 处理 device_code（可能为 NULL）
	if deviceCode == "" {
		query = query.Where("device_code IS NULL")
	} else {
		query = query.Where("device_code = ?", deviceCode)
	}

	// 处理 ip_address（可能为 NULL）
	if ipAddress == "" {
		query = query.Where("ip_address IS NULL")
	} else {
		query = query.Where("ip_address = ?", ipAddress)
	}

	err := query.Updates(map[string]interface{}{
		"risk_count":     gorm.Expr("risk_count + 1"),
		"last_risk_time": time.Now(),
	}).Error
	if err != nil {
		return fmt.Errorf("增加风险触发次数失败: %w", err)
	}
	return nil
}

// CountAll 统计所有黑名单数量
func (r *BlacklistRepository) CountAll() (int64, error) {
	var count int64
	err := r.db.Model(&model.AlipayBlacklist{}).
		Count(&count).Error
	if err != nil {
		return 0, fmt.Errorf("统计黑名单数量失败: %w", err)
	}
	return count, nil
}

// Delete 删除黑名单
func (r *BlacklistRepository) Delete(id uint) error {
	err := r.db.Delete(&model.AlipayBlacklist{}, id).Error
	if err != nil {
		return fmt.Errorf("删除黑名单失败: %w", err)
	}
	return nil
}

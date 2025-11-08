package repository

import (
	"fmt"

	"complaint-monitor/internal/model"

	"go.uber.org/zap"
	"gorm.io/gorm"
)

// ComplaintRepository 投诉仓库
type ComplaintRepository struct {
	*BaseRepository
}

// NewComplaintRepository 创建投诉仓库
func NewComplaintRepository(db *gorm.DB, logger *zap.Logger) *ComplaintRepository {
	return &ComplaintRepository{
		BaseRepository: NewBaseRepository(db, logger),
	}
}

// Create 创建投诉记录
func (r *ComplaintRepository) Create(complaint *model.Complaint) error {
	err := r.db.Create(complaint).Error
	if err != nil {
		return fmt.Errorf("创建投诉记录失败: %w", err)
	}
	return nil
}

// CreateDetail 创建投诉详情
func (r *ComplaintRepository) CreateDetail(detail *model.ComplaintDetail) error {
	err := r.db.Create(detail).Error
	if err != nil {
		return fmt.Errorf("创建投诉详情失败: %w", err)
	}
	return nil
}

// FindByComplaintNo 根据投诉单号查找
func (r *ComplaintRepository) FindByComplaintNo(subjectID int, complaintNo string) (*model.Complaint, error) {
	var complaint model.Complaint
	err := r.db.Where("subject_id = ? AND complaint_no = ?", subjectID, complaintNo).First(&complaint).Error
	if err != nil {
		if err == gorm.ErrRecordNotFound {
			return nil, nil // 不存在返回nil
		}
		return nil, fmt.Errorf("查询投诉失败: %w", err)
	}
	return &complaint, nil
}

// FindDetailsByComplaintID 根据投诉ID查找详情列表
func (r *ComplaintRepository) FindDetailsByComplaintID(complaintID uint) ([]*model.ComplaintDetail, error) {
	var details []*model.ComplaintDetail
	err := r.db.Where("complaint_id = ?", complaintID).Find(&details).Error
	if err != nil {
		return nil, fmt.Errorf("查询投诉详情失败: %w", err)
	}
	return details, nil
}

// FindUnpushedDetails 查找未推送的投诉详情
func (r *ComplaintRepository) FindUnpushedDetails(limit int) ([]*model.ComplaintDetail, error) {
	var details []*model.ComplaintDetail
	query := r.db.Where("is_pushed = ?", 0).Order("created_at ASC")
	if limit > 0 {
		query = query.Limit(limit)
	}
	err := query.Find(&details).Error
	if err != nil {
		return nil, fmt.Errorf("查询未推送详情失败: %w", err)
	}
	return details, nil
}

// MarkDetailAsPushed 标记详情为已推送
func (r *ComplaintRepository) MarkDetailAsPushed(detailID uint) error {
	err := r.db.Model(&model.ComplaintDetail{}).Where("id = ?", detailID).Updates(map[string]interface{}{
		"is_pushed": 1,
		"pushed_at": gorm.Expr("NOW()"),
	}).Error
	if err != nil {
		return fmt.Errorf("标记为已推送失败: %w", err)
	}
	return nil
}

// UpdateComplaint 更新投诉
func (r *ComplaintRepository) UpdateComplaint(complaint *model.Complaint) error {
	err := r.db.Save(complaint).Error
	if err != nil {
		return fmt.Errorf("更新投诉失败: %w", err)
	}
	return nil
}

// ExistsDetail 检查投诉详情是否存在
func (r *ComplaintRepository) ExistsDetail(subjectID int, complaintNo, merchantOrderNo string) (bool, error) {
	var count int64
	err := r.db.Model(&model.ComplaintDetail{}).
		Where("subject_id = ? AND complaint_no = ? AND merchant_order_no = ?", 
			subjectID, complaintNo, merchantOrderNo).
		Count(&count).Error
	if err != nil {
		return false, fmt.Errorf("检查投诉详情是否存在失败: %w", err)
	}
	return count > 0, nil
}

// CreateWithDetails 创建投诉和详情（事务）
func (r *ComplaintRepository) CreateWithDetails(complaint *model.Complaint, details []*model.ComplaintDetail) error {
	return r.db.Transaction(func(tx *gorm.DB) error {
		// 创建投诉主记录
		if err := tx.Create(complaint).Error; err != nil {
			return fmt.Errorf("创建投诉主记录失败: %w", err)
		}

		// 创建投诉详情
		for _, detail := range details {
			detail.ComplaintID = complaint.ID
			if err := tx.Create(detail).Error; err != nil {
				return fmt.Errorf("创建投诉详情失败: %w", err)
			}
		}

		return nil
	})
}

// CountBySubject 统计主体的投诉数量
func (r *ComplaintRepository) CountBySubject(subjectID int) (int64, error) {
	var count int64
	err := r.db.Model(&model.Complaint{}).Where("subject_id = ?", subjectID).Count(&count).Error
	if err != nil {
		return 0, fmt.Errorf("统计投诉数量失败: %w", err)
	}
	return count, nil
}

// CountByComplainant 统计投诉人的投诉次数
func (r *ComplaintRepository) CountByComplainant(subjectID int, complainantID string) (int64, error) {
	var count int64
	err := r.db.Model(&model.Complaint{}).
		Where("subject_id = ? AND complainant_id = ?", subjectID, complainantID).
		Count(&count).Error
	if err != nil {
		return 0, fmt.Errorf("统计投诉人投诉次数失败: %w", err)
	}
	return count, nil
}


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

// FindByComplaintNo 根据被投诉订单号查找（已废弃，使用FindByAlipayTaskId）
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

// FindByAlipayTaskId 根据支付宝投诉单号（TaskId）查找
func (r *ComplaintRepository) FindByAlipayTaskId(subjectID int, alipayTaskId string) (*model.Complaint, error) {
	var complaint model.Complaint
	err := r.db.Where("subject_id = ? AND alipay_task_id = ?", subjectID, alipayTaskId).First(&complaint).Error
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
// complaintID: 投诉主表ID
// merchantOrderNo: 商户订单号
func (r *ComplaintRepository) ExistsDetail(complaintID uint, merchantOrderNo string) (bool, error) {
	var count int64
	err := r.db.Model(&model.ComplaintDetail{}).
		Where("complaint_id = ? AND merchant_order_no = ?",
			complaintID, merchantOrderNo).
		Count(&count).Error
	if err != nil {
		return false, fmt.Errorf("检查投诉详情是否存在失败: %w", err)
	}
	return count > 0, nil
}

// CreateWithDetails 创建投诉和详情（事务）
func (r *ComplaintRepository) CreateWithDetails(complaint *model.Complaint, details []*model.ComplaintDetail) error {
	return r.db.Transaction(func(tx *gorm.DB) error {
		// 记录保存前的complaint对象，用于调试
		r.logger.Info("准备创建投诉主记录",
			zap.Int64("AlipayComplainId", complaint.AlipayComplainId),
			zap.String("AlipayTaskId", complaint.AlipayTaskId),
			zap.String("ComplaintNo", complaint.ComplaintNo),
			zap.Int("SubjectID", complaint.SubjectID),
		)
		
		// 创建投诉主记录
		// 注意：即使alipay_complain_id为0，也要保存（0表示数据异常，但仍需记录）
		// 使用Select明确指定要保存alipay_complain_id字段，确保即使值为0也能保存
		// 注意：GORM默认会忽略零值字段，但因为我们设置了default:0，应该会保存
		// 但为了确保，我们使用Select明确指定要保存的字段
		result := tx.Select(
			"subject_id", "agent_id", "complaint_no", "alipay_task_id", 
			"alipay_complain_id", "complaint_status", "complainant_id", 
			"complaint_time", "complaint_reason", "refund_amount", 
			"merchant_feedback", "feedback_images", "feedback_time", 
			"handler_id", "gmt_create", "gmt_modified", "created_at", "updated_at",
		).Create(complaint)
		
		if result.Error != nil {
			r.logger.Error("创建投诉主记录失败",
				zap.Int64("AlipayComplainId", complaint.AlipayComplainId),
				zap.String("AlipayTaskId", complaint.AlipayTaskId),
				zap.String("ComplaintNo", complaint.ComplaintNo),
				zap.Int("SubjectID", complaint.SubjectID),
				zap.Error(result.Error),
			)
			return fmt.Errorf("创建投诉主记录失败: %w", result.Error)
		}
		
		// 记录保存后的complaint对象，确认AlipayComplainId是否保存成功
		r.logger.Info("投诉主记录创建成功",
			zap.Uint("ID", complaint.ID),
			zap.Int64("AlipayComplainId", complaint.AlipayComplainId),
			zap.String("AlipayTaskId", complaint.AlipayTaskId),
			zap.Int64("RowsAffected", result.RowsAffected),
		)
		
		// 立即查询数据库确认字段是否真的写入了
		var savedComplaint model.Complaint
		if err := tx.Where("id = ?", complaint.ID).First(&savedComplaint).Error; err == nil {
			r.logger.Info("查询保存后的投诉记录，确认alipay_complain_id字段值",
				zap.Uint("ID", savedComplaint.ID),
				zap.Int64("AlipayComplainId", savedComplaint.AlipayComplainId),
				zap.Int64("ExpectedAlipayComplainId", complaint.AlipayComplainId),
				zap.String("AlipayTaskId", savedComplaint.AlipayTaskId),
			)
			if savedComplaint.AlipayComplainId != complaint.AlipayComplainId {
				r.logger.Error("alipay_complain_id值不匹配！字段可能未正确保存",
					zap.Int64("expected", complaint.AlipayComplainId),
					zap.Int64("actual", savedComplaint.AlipayComplainId),
					zap.Uint("complaint_id", complaint.ID),
				)
			} else {
				r.logger.Info("alipay_complain_id字段保存成功，值与预期一致",
					zap.Int64("alipay_complain_id", savedComplaint.AlipayComplainId),
				)
			}
		} else {
			r.logger.Warn("查询保存后的投诉记录失败", zap.Error(err))
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

package repository

import (
	"fmt"

	"complaint-monitor/internal/model"

	"go.uber.org/zap"
	"gorm.io/gorm"
)

// SubjectRepository 主体仓库
type SubjectRepository struct {
	*BaseRepository
}

// NewSubjectRepository 创建主体仓库
func NewSubjectRepository(db *gorm.DB, logger *zap.Logger) *SubjectRepository {
	return &SubjectRepository{
		BaseRepository: NewBaseRepository(db, logger),
	}
}

// FindByID 根据ID查找主体
func (r *SubjectRepository) FindByID(id int) (*model.Subject, error) {
	var subject model.Subject
	err := r.db.Where("id = ?", id).First(&subject).Error
	if err != nil {
		if err == gorm.ErrRecordNotFound {
			return nil, fmt.Errorf("主体不存在: id=%d", id)
		}
		return nil, fmt.Errorf("查询主体失败: %w", err)
	}
	return &subject, nil
}

// FindByAppID 根据AppID查找主体
func (r *SubjectRepository) FindByAppID(appID string) (*model.Subject, error) {
	var subject model.Subject
	err := r.db.Where("app_id = ?", appID).First(&subject).Error
	if err != nil {
		if err == gorm.ErrRecordNotFound {
			return nil, fmt.Errorf("主体不存在: app_id=%s", appID)
		}
		return nil, fmt.Errorf("查询主体失败: %w", err)
	}
	return &subject, nil
}

// FindAllActive 查找所有激活的主体
func (r *SubjectRepository) FindAllActive() ([]*model.Subject, error) {
	var subjects []*model.Subject
	err := r.db.Where("status = ?", 1).Find(&subjects).Error
	if err != nil {
		return nil, fmt.Errorf("查询激活主体失败: %w", err)
	}
	return subjects, nil
}

// FindAll 查找所有主体
func (r *SubjectRepository) FindAll() ([]*model.Subject, error) {
	var subjects []*model.Subject
	err := r.db.Find(&subjects).Error
	if err != nil {
		return nil, fmt.Errorf("查询所有主体失败: %w", err)
	}
	return subjects, nil
}

// FindActiveWithCert 查找所有激活且有证书的主体
func (r *SubjectRepository) FindActiveWithCert() ([]*model.Subject, error) {
	var subjects []*model.Subject
	err := r.db.Where("status = ?", 1).Find(&subjects).Error
	if err != nil {
		return nil, fmt.Errorf("查询激活主体失败: %w", err)
	}

	// 加载证书信息
	for _, subject := range subjects {
		cert, err := r.FindCertBySubjectID(subject.ID)
		if err != nil {
			r.logger.Warn("加载主体证书失败",
				zap.Int("subject_id", subject.ID),
				zap.Error(err))
			continue
		}
		subject.Cert = cert
	}

	// 过滤掉没有证书的主体
	validSubjects := make([]*model.Subject, 0)
	for _, subject := range subjects {
		if subject.HasCert() {
			validSubjects = append(validSubjects, subject)
		}
	}

	return validSubjects, nil
}

// FindCertBySubjectID 根据主体ID查找证书
func (r *SubjectRepository) FindCertBySubjectID(subjectID int) (*model.SubjectCert, error) {
	var cert model.SubjectCert
	err := r.db.Where("subject_id = ?", subjectID).First(&cert).Error
	if err != nil {
		if err == gorm.ErrRecordNotFound {
			return nil, fmt.Errorf("证书不存在: subject_id=%d", subjectID)
		}
		return nil, fmt.Errorf("查询证书失败: %w", err)
	}
	return &cert, nil
}

// LoadSubjectWithCert 加载主体和证书
func (r *SubjectRepository) LoadSubjectWithCert(subjectID int) (*model.Subject, error) {
	subject, err := r.FindByID(subjectID)
	if err != nil {
		return nil, err
	}

	cert, err := r.FindCertBySubjectID(subjectID)
	if err != nil {
		return nil, err
	}

	subject.Cert = cert
	return subject, nil
}

// Count 统计主体数量
func (r *SubjectRepository) Count() (int64, error) {
	var count int64
	err := r.db.Model(&model.Subject{}).Count(&count).Error
	if err != nil {
		return 0, fmt.Errorf("统计主体数量失败: %w", err)
	}
	return count, nil
}

// CountActive 统计激活的主体数量
func (r *SubjectRepository) CountActive() (int64, error) {
	var count int64
	err := r.db.Model(&model.Subject{}).Where("status = ?", 1).Count(&count).Error
	if err != nil {
		return 0, fmt.Errorf("统计激活主体数量失败: %w", err)
	}
	return count, nil
}

// Update 更新主体
func (r *SubjectRepository) Update(subject *model.Subject) error {
	err := r.db.Save(subject).Error
	if err != nil {
		return fmt.Errorf("更新主体失败: %w", err)
	}
	return nil
}

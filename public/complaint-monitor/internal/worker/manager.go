package worker

import (
	"context"
	"sync"
	"time"

	"complaint-monitor/internal/cert"
	"complaint-monitor/internal/config"
	"complaint-monitor/internal/lock"
	"complaint-monitor/internal/model"
	"complaint-monitor/internal/repository"
	"complaint-monitor/internal/service"

	"go.uber.org/zap"
)

// Manager Worker管理器
type Manager struct {
	cfg              *config.Config
	subjectRepo      *repository.SubjectRepository
	complaintRepo    *repository.ComplaintRepository
	blacklistRepo    *repository.BlacklistRepository
	certManager      *cert.CertManager
	lockManager      *lock.DistributedLock
	alipayService    *service.AlipayService
	blacklistService *service.BlacklistService
	logger           *zap.Logger

	workers       map[int]*SubjectWorker // subject_id -> worker
	workersMutex  sync.RWMutex
	refreshTicker *time.Ticker
	stopChan      chan struct{}
}

// NewManager 创建Worker管理器
func NewManager(
	cfg *config.Config,
	subjectRepo *repository.SubjectRepository,
	complaintRepo *repository.ComplaintRepository,
	blacklistRepo *repository.BlacklistRepository,
	certManager *cert.CertManager,
	lockManager *lock.DistributedLock,
	alipayService *service.AlipayService,
	blacklistService *service.BlacklistService,
	logger *zap.Logger,
) *Manager {
	return &Manager{
		cfg:              cfg,
		subjectRepo:      subjectRepo,
		complaintRepo:    complaintRepo,
		blacklistRepo:    blacklistRepo,
		certManager:      certManager,
		lockManager:      lockManager,
		alipayService:    alipayService,
		blacklistService: blacklistService,
		logger:           logger,
		workers:          make(map[int]*SubjectWorker),
		stopChan:         make(chan struct{}),
	}
}

// Start 启动管理器
func (m *Manager) Start(ctx context.Context) {
	m.logger.Info("Worker管理器启动")

	// 初始加载主体
	if err := m.refreshWorkers(ctx); err != nil {
		m.logger.Error("初始加载主体失败", zap.Error(err))
	}

	// 定期刷新主体列表
	m.refreshTicker = time.NewTicker(m.cfg.Worker.GetRefreshInterval())
	defer m.refreshTicker.Stop()

	for {
		select {
		case <-ctx.Done():
			m.logger.Info("Worker管理器收到停止信号")
			m.stopAllWorkers()
			return

		case <-m.stopChan:
			m.logger.Info("Worker管理器被手动停止")
			m.stopAllWorkers()
			return

		case <-m.refreshTicker.C:
			if err := m.refreshWorkers(ctx); err != nil {
				m.logger.Error("刷新主体列表失败", zap.Error(err))
			}
		}
	}
}

// refreshWorkers 刷新Worker列表
func (m *Manager) refreshWorkers(ctx context.Context) error {
	m.logger.Debug("开始刷新主体列表")

	// 查询所有激活且有证书的主体
	subjects, err := m.subjectRepo.FindActiveWithCert()
	if err != nil {
		return err
	}

	m.logger.Info("查询到激活主体", zap.Int("count", len(subjects)))

	// 构建当前应该存在的主体ID集合
	currentSubjectIDs := make(map[int]bool)
	for _, subject := range subjects {
		currentSubjectIDs[subject.ID] = true
	}

	m.workersMutex.Lock()
	defer m.workersMutex.Unlock()

	// 停止已不存在或不活跃的Worker
	for subjectID, worker := range m.workers {
		if !currentSubjectIDs[subjectID] {
			m.logger.Info("停止Worker（主体已禁用或删除）", zap.Int("subject_id", subjectID))
			worker.Stop()
			delete(m.workers, subjectID)
		}
	}

	// 启动新的Worker
	for _, subject := range subjects {
		if _, exists := m.workers[subject.ID]; !exists {
			m.logger.Info("启动新Worker", zap.Int("subject_id", subject.ID))
			worker := m.createWorker(subject)
			m.workers[subject.ID] = worker
			go worker.Run(ctx)
		}
	}

	m.logger.Info("主体列表刷新完成",
		zap.Int("active_workers", len(m.workers)),
		zap.Int("total_subjects", len(subjects)))

	return nil
}

// createWorker 创建Worker
func (m *Manager) createWorker(subject *model.Subject) *SubjectWorker {
	return NewSubjectWorker(
		subject,
		m.subjectRepo,
		m.complaintRepo,
		m.blacklistRepo,
		m.certManager,
		m.lockManager,
		m.alipayService,
		m.blacklistService,
		m.cfg.Worker.GetFetchInterval(),
		m.cfg.Worker.Restartable,
		m.logger,
	)
}

// stopAllWorkers 停止所有Worker
func (m *Manager) stopAllWorkers() {
	m.workersMutex.Lock()
	defer m.workersMutex.Unlock()

	m.logger.Info("正在停止所有Worker", zap.Int("count", len(m.workers)))

	for subjectID, worker := range m.workers {
		m.logger.Debug("停止Worker", zap.Int("subject_id", subjectID))
		worker.Stop()
	}

	m.workers = make(map[int]*SubjectWorker)
	m.logger.Info("所有Worker已停止")
}

// Stop 停止管理器
func (m *Manager) Stop() {
	m.logger.Info("正在停止Worker管理器...")
	close(m.stopChan)
}

// GetWorkerCount 获取Worker数量
func (m *Manager) GetWorkerCount() int {
	m.workersMutex.RLock()
	defer m.workersMutex.RUnlock()
	return len(m.workers)
}

// GetWorkerStats 获取Worker统计信息
func (m *Manager) GetWorkerStats() map[string]interface{} {
	m.workersMutex.RLock()
	defer m.workersMutex.RUnlock()

	workerIDs := make([]int, 0, len(m.workers))
	for subjectID := range m.workers {
		workerIDs = append(workerIDs, subjectID)
	}

	return map[string]interface{}{
		"total_workers": len(m.workers),
		"worker_ids":    workerIDs,
	}
}

package worker

import (
	"context"
	"fmt"
	"runtime/debug"
	"time"

	"complaint-monitor/internal/cert"
	"complaint-monitor/internal/lock"
	"complaint-monitor/internal/model"
	"complaint-monitor/internal/repository"
	"complaint-monitor/internal/service"

	"go.uber.org/zap"
)

// SubjectWorker 主体Worker（负责单个主体的投诉监控）
type SubjectWorker struct {
	subject       *model.Subject
	subjectRepo   *repository.SubjectRepository
	complaintRepo *repository.ComplaintRepository
	blacklistRepo *repository.BlacklistRepository
	certManager   *cert.CertManager
	lockManager   *lock.DistributedLock
	alipayService *service.AlipayService
	blacklistSvc  *service.BlacklistService
	fetchInterval time.Duration
	restartable   bool
	logger        *zap.Logger
	stopChan      chan struct{}
}

// NewSubjectWorker 创建主体Worker
func NewSubjectWorker(
	subject *model.Subject,
	subjectRepo *repository.SubjectRepository,
	complaintRepo *repository.ComplaintRepository,
	blacklistRepo *repository.BlacklistRepository,
	certManager *cert.CertManager,
	lockManager *lock.DistributedLock,
	alipayService *service.AlipayService,
	blacklistSvc *service.BlacklistService,
	fetchInterval time.Duration,
	restartable bool,
	logger *zap.Logger,
) *SubjectWorker {
	return &SubjectWorker{
		subject:       subject,
		subjectRepo:   subjectRepo,
		complaintRepo: complaintRepo,
		blacklistRepo: blacklistRepo,
		certManager:   certManager,
		lockManager:   lockManager,
		alipayService: alipayService,
		blacklistSvc:  blacklistSvc,
		fetchInterval: fetchInterval,
		restartable:   restartable,
		logger:        logger.With(zap.Int("subject_id", subject.ID), zap.String("app_id", subject.AlipayAppID)),
		stopChan:      make(chan struct{}),
	}
}

// Run 运行Worker（带Panic恢复）
func (w *SubjectWorker) Run(ctx context.Context) {
	w.logger.Info("Worker启动")

	// 主循环Panic恢复
	defer func() {
		if r := recover(); r != nil {
			w.logger.Error("Worker主循环发生Panic",
				zap.Any("panic", r),
				zap.String("stack", string(debug.Stack())),
			)

			// 如果允许重启，尝试重启
			if w.restartable {
				w.logger.Info("尝试重启Worker...")
				time.Sleep(5 * time.Second)
				go w.Run(ctx) // 重新启动
			}
		}
	}()

	ticker := time.NewTicker(w.fetchInterval)
	defer ticker.Stop()

	// 立即执行一次
	w.processOnce(ctx)

	for {
		select {
		case <-ctx.Done():
			w.logger.Info("Worker收到停止信号")
			return

		case <-w.stopChan:
			w.logger.Info("Worker被手动停止")
			return

		case <-ticker.C:
			w.processOnce(ctx)
		}
	}
}

// processOnce 单次处理（带Panic恢复和超时控制）
func (w *SubjectWorker) processOnce(ctx context.Context) {
	// 单次处理的Panic恢复
	defer func() {
		if r := recover(); r != nil {
			w.logger.Error("处理过程发生Panic",
				zap.Any("panic", r),
				zap.String("stack", string(debug.Stack())),
			)
		}
	}()

	// 设置超时上下文
	processCtx, cancel := context.WithTimeout(ctx, 60*time.Second)
	defer cancel()

	// 加载证书
	client, err := w.certManager.LoadCert(w.subject)
	if err != nil {
		w.logger.Error("加载证书失败", zap.Error(err))
		return
	}

	// 获取投诉列表
	// TODO: 实际调用支付宝API
	w.logger.Debug("开始获取投诉列表")

	// 模拟处理投诉
	_ = client
	_ = processCtx

	w.logger.Debug("投诉处理完成")
}

// Stop 停止Worker
func (w *SubjectWorker) Stop() {
	w.logger.Info("正在停止Worker...")
	close(w.stopChan)
}

// GetSubjectID 获取主体ID
func (w *SubjectWorker) GetSubjectID() int {
	return w.subject.ID
}

// processComplaint 处理单个投诉
func (w *SubjectWorker) processComplaint(ctx context.Context, complaintNo string) error {
	// 获取分布式锁
	lockKey := fmt.Sprintf("complaint:lock:%s", complaintNo)
	lockResult, err := w.lockManager.AcquireLock(ctx, lockKey, 1)
	if err != nil {
		return fmt.Errorf("获取锁失败: %w", err)
	}

	if !lockResult.IsAcquired() {
		w.logger.Debug("投诉正在被其他Worker处理", zap.String("complaint_no", complaintNo))
		return nil
	}

	// 确保释放锁
	defer func() {
		if err := w.lockManager.ReleaseLock(ctx, lockResult); err != nil {
			w.logger.Error("释放锁失败",
				zap.String("complaint_no", complaintNo),
				zap.Error(err))
		}
	}()

	w.logger.Info("开始处理投诉", zap.String("complaint_no", complaintNo))

	// TODO: 实现投诉处理逻辑
	// 1. 查询投诉详情
	// 2. 拆分多个订单
	// 3. 保存到数据库
	// 4. 触发黑名单检查
	// 5. 推送通知

	return nil
}

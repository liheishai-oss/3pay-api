package worker

import (
	"context"
	"fmt"
	"runtime/debug"
	"strconv"
	"time"

	"complaint-monitor/internal/cert"
	"complaint-monitor/internal/lock"
	"complaint-monitor/internal/model"
	"complaint-monitor/internal/repository"
	"complaint-monitor/internal/service"

	"github.com/smartwalle/alipay/v3"
	"go.uber.org/zap"
)

// 配置常量（参考代码）
const (
	QueryDaysBack  = 10  // 查询过去N天的投诉数据
	AlipayPageSize = 200 // 支付宝投诉列表每页数量（最大200）
)

// SubjectWorker 主体Worker（负责单个主体的投诉监控）
type SubjectWorker struct {
	subject       *model.Subject
	subjectRepo   *repository.SubjectRepository
	complaintRepo *repository.ComplaintRepository
	blacklistRepo *repository.BlacklistRepository
	orderRepo     *repository.OrderRepository
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
	orderRepo *repository.OrderRepository,
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
		orderRepo:     orderRepo,
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

	// 计算查询时间范围（参考代码：过去N天到明天）
	// 参考代码：calculateQueryTimeRange()
	// 计算开始时间（N天前）
	now := time.Now()
	beginTime := now.AddDate(0, 0, -QueryDaysBack)
	// 计算结束时间（明天）
	endTime := now.AddDate(0, 0, 1)

	// 格式化时间（格式：yyyy-MM-dd HH:mm:ss）
	beginTimeStr := beginTime.Format("2006-01-02 15:04:05")
	endTimeStr := endTime.Format("2006-01-02 15:04:05")

	// 打印详细的查询条件（控制台输出 + 日志）
	fmt.Printf("\n=== 开始获取投诉列表 ===\n")
	fmt.Printf("主体ID: %d\n", w.subject.ID)
	fmt.Printf("AppID: %s\n", w.subject.AlipayAppID)
	fmt.Printf("当前时间: %s\n", now.Format("2006-01-02 15:04:05"))
	fmt.Printf("查询天数: %d 天前\n", QueryDaysBack)
	fmt.Printf("查询开始时间: %s (计算: 当前时间 - %d 天)\n", beginTimeStr, QueryDaysBack)
	fmt.Printf("查询结束时间: %s (计算: 当前时间 + 1 天)\n", endTimeStr)
	fmt.Printf("时间范围: %s 至 %s\n", beginTimeStr, endTimeStr)
	fmt.Printf("页大小: %d\n", AlipayPageSize)
	fmt.Printf("========================\n\n")

	w.logger.Info("开始获取投诉列表",
		zap.Int("subject_id", w.subject.ID),
		zap.String("app_id", w.subject.AlipayAppID),
		zap.String("current_time", now.Format("2006-01-02 15:04:05")),
		zap.Int("query_days_back", QueryDaysBack),
		zap.String("begin_time", beginTimeStr),
		zap.String("end_time", endTimeStr),
		zap.String("time_range", fmt.Sprintf("%s 至 %s", beginTimeStr, endTimeStr)),
		zap.Int("page_size", AlipayPageSize),
	)

	// 分页查询投诉列表
	// 根据参考代码，使用较大的页大小以提高效率
	pageNum := 1
	pageSize := AlipayPageSize // 使用参考代码中的最大页大小（200）
	totalProcessed := 0
	totalFailed := 0

	for {
		// 构建请求
		listReq := service.ComplaintListRequest{
			BeginTime: beginTimeStr,
			EndTime:   endTimeStr,
			PageNum:   pageNum,
			PageSize:  pageSize,
		}

		// 调用投诉列表API
		listResp, err := w.alipayService.FetchComplaintList(client, listReq)
		if err != nil {
			w.logger.Error("获取投诉列表失败",
				zap.Int("page_num", pageNum),
				zap.Error(err),
			)
			return // API调用失败，等待下次重试
		}

		// 处理投诉列表
		if listResp == nil || len(listResp.ComplaintList) == 0 {
			if listResp != nil {
				w.logger.Info("没有更多投诉数据",
					zap.Int("page_num", pageNum),
					zap.Int64("total", listResp.Total),
				)
			} else {
				w.logger.Info("投诉列表为空",
					zap.Int("page_num", pageNum),
				)
			}
			break
		}

		w.logger.Info("获取到投诉列表",
			zap.Int("page_num", pageNum),
			zap.Int("count", len(listResp.ComplaintList)),
			zap.Int64("total", listResp.Total),
		)

		// 处理每个投诉
		for _, complaintItem := range listResp.ComplaintList {
			// 使用投诉主表主键ID（ComplaintID）查询详情和保存
			// ComplaintEventID 是支付宝投诉单号（TaskId）
			// 注意：ComplaintID 是 complaint_list 中的 id 字段，必须保存到数据库的 alipay_complain_id 字段
			alipayComplainId := complaintItem.ComplaintID // 支付宝投诉主表ID（complaint_list中的id）
			complaintID := fmt.Sprintf("%d", alipayComplainId) // 转换为字符串，用于查询详情
			alipayTaskId := complaintItem.ComplaintEventID // 支付宝投诉单号（TaskId）

			// 记录从API获取的投诉ID值
			w.logger.Info("处理投诉项",
				zap.Int64("complaint_id_from_api", alipayComplainId),
				zap.String("complaint_id_string", complaintID),
				zap.String("alipay_task_id", alipayTaskId),
				zap.String("status", complaintItem.Status),
			)

			// 如果ComplaintID为0，记录警告但继续处理（可能数据有问题）
			if alipayComplainId == 0 {
				w.logger.Warn("投诉ID为0，可能数据异常",
					zap.Int64("complaint_id", alipayComplainId),
					zap.String("complaint_event_id", complaintItem.ComplaintEventID),
				)
				// 继续处理，但alipay_complain_id会保存为0
			}

			// 如果支付宝投诉单号为空，跳过
			if alipayTaskId == "" {
				w.logger.Warn("支付宝投诉单号为空，跳过",
					zap.Int64("complaint_id", alipayComplainId),
				)
				continue
			}

			err := w.processComplaint(processCtx, client, complaintID, alipayTaskId)
			if err != nil {
				w.logger.Error("处理投诉失败",
					zap.Int64("complaint_id", complaintItem.ComplaintID),
					zap.String("alipay_task_id", alipayTaskId),
					zap.Error(err),
				)
				totalFailed++
			} else {
				totalProcessed++
			}
		}

		// 如果返回的数量小于pageSize，说明是最后一页
		if len(listResp.ComplaintList) < pageSize {
			break
		}

		// 继续查询下一页
		pageNum++
	}

	w.logger.Info("投诉处理完成",
		zap.Int("total_processed", totalProcessed),
		zap.Int("total_failed", totalFailed),
	)

	// TODO: 更新最后查询时间到Redis
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

// processComplaint 处理单个投诉（仅入库，不处理业务逻辑）
// complaintID: 投诉主表主键ID（用于查询详情API）
// alipayTaskId: 支付宝投诉单号（TaskId，用于去重和唯一标识）
func (w *SubjectWorker) processComplaint(ctx context.Context, client *alipay.Client, complaintID string, alipayTaskId string) error {
	// 获取分布式锁（使用支付宝投诉单号作为锁的key）
	lockKey := fmt.Sprintf("complaint:lock:%s", alipayTaskId)
	lockResult, err := w.lockManager.AcquireLock(ctx, lockKey, 30)
	if err != nil {
		return fmt.Errorf("获取锁失败: %w", err)
	}

	if !lockResult.IsAcquired() {
		w.logger.Debug("投诉正在被其他Worker处理", zap.String("alipay_task_id", alipayTaskId))
		return nil
	}

	// 确保释放锁
	defer func() {
		if err := w.lockManager.ReleaseLock(ctx, lockResult); err != nil {
			w.logger.Error("释放锁失败",
				zap.String("alipay_task_id", alipayTaskId),
				zap.Error(err))
		}
	}()

	w.logger.Info("开始处理投诉",
		zap.String("complaint_id", complaintID),
		zap.String("alipay_task_id", alipayTaskId),
	)

	// 1. 检查是否已存在（去重，使用支付宝投诉单号）
	existing, err := w.complaintRepo.FindByAlipayTaskId(w.subject.ID, alipayTaskId)
	if err != nil {
		return fmt.Errorf("查询投诉失败: %w", err)
	}
	if existing != nil {
		w.logger.Debug("投诉已存在，跳过", zap.String("alipay_task_id", alipayTaskId))
		return nil
	}

	// 2. 获取投诉详情（使用投诉主表主键ID）
	detailReq := service.ComplaintDetailRequest{
		ComplaintEventID: complaintID, // 这里传入的是投诉主表主键ID
	}

	detailResp, err := w.alipayService.FetchComplaintDetail(client, detailReq)
	if err != nil {
		return fmt.Errorf("获取投诉详情失败: %w", err)
	}

	// 3. 解析投诉时间
	var complaintTime *time.Time
	if detailResp.GmtCreate != "" {
		parsedTime, err := time.Parse("2006-01-02 15:04:05", detailResp.GmtCreate)
		if err != nil {
			w.logger.Warn("解析投诉时间失败，使用当前时间",
				zap.String("gmt_create", detailResp.GmtCreate),
				zap.Error(err),
			)
			now := time.Now()
			complaintTime = &now
		} else {
			complaintTime = &parsedTime
		}
	} else {
		now := time.Now()
		complaintTime = &now
	}

	// 4. 获取被投诉的订单号（使用第一个订单号作为主订单号）
	if len(detailResp.TargetOrderList) == 0 {
		return fmt.Errorf("投诉详情中没有订单信息，无法入库")
	}

	// 使用第一个订单号作为被投诉的订单号
	firstOrderNo := detailResp.TargetOrderList[0].OutTradeNo
	if firstOrderNo == "" {
		return fmt.Errorf("第一个订单号为空，无法入库")
	}

	// 将complaintID转换为int64（complaint_list中的id）
	var alipayComplainId int64
	if complaintID != "" {
		if id, err := strconv.ParseInt(complaintID, 10, 64); err == nil {
			alipayComplainId = id
		} else {
			w.logger.Warn("解析alipay_complain_id失败，使用0",
				zap.String("complaint_id", complaintID),
				zap.Error(err),
			)
		}
	}
	
	// 记录alipay_complain_id的值，用于调试
	w.logger.Info("准备保存投诉数据，alipay_complain_id值",
		zap.String("complaint_id", complaintID),
		zap.Int64("alipay_complain_id", alipayComplainId),
		zap.String("alipay_task_id", alipayTaskId),
	)

	// 5. 构建投诉主记录
	complaint := &model.Complaint{
		SubjectID:        w.subject.ID,
		ComplaintNo:      firstOrderNo,     // 被投诉的订单号（OutTradeNo）
		AlipayTaskId:     alipayTaskId,     // 支付宝投诉单号（TaskId）
		AlipayComplainId: alipayComplainId, // 支付宝投诉主表ID（complaint_list中的id，用于调用完结投诉API）
		ComplaintStatus:  detailResp.Status,
		ComplainantID:    detailResp.ComplainantID,
		ComplaintTime:    complaintTime,
		ComplaintReason:  detailResp.ComplaintReason,
		GmtCreate:        detailResp.GmtCreate,
		GmtModified:      detailResp.GmtModified,
	}
	
	// 记录保存前的complaint对象，确认AlipayComplainId字段值
	w.logger.Debug("保存前的complaint对象",
		zap.Int64("AlipayComplainId", complaint.AlipayComplainId),
		zap.String("AlipayTaskId", complaint.AlipayTaskId),
		zap.String("ComplaintNo", complaint.ComplaintNo),
	)

	// 6. 构建投诉详情记录（订单维度）
	details := make([]*model.ComplaintDetail, 0)
	var firstAgentID int

	for _, orderItem := range detailResp.TargetOrderList {
		// 提取代理商ID
		agentID := model.ExtractAgentIDFromOrderNo(orderItem.OutTradeNo)
		if agentID > 0 && firstAgentID == 0 {
			firstAgentID = agentID
		}

		detail := &model.ComplaintDetail{
			SubjectID:       w.subject.ID,
			ComplaintNo:     orderItem.OutTradeNo, // 被投诉的订单号（OutTradeNo）
			MerchantOrderNo: orderItem.OutTradeNo,
			PlatformOrderNo: orderItem.TradeNo,
			OrderAmount:     orderItem.Amount,
			ComplaintAmount: orderItem.ComplaintAmount,
			AgentID:         agentID,
		}
		details = append(details, detail)
	}

	// 设置投诉主记录的代理商ID（从第一个订单提取）
	if firstAgentID > 0 {
		complaint.AgentID = firstAgentID
	}

	// 6. 保存到数据库（事务）
	err = w.complaintRepo.CreateWithDetails(complaint, details)
	if err != nil {
		return fmt.Errorf("保存投诉数据失败: %w", err)
	}

	w.logger.Info("投诉数据保存成功",
		zap.String("alipay_task_id", alipayTaskId),
		zap.Int64("alipay_complain_id", alipayComplainId),
		zap.String("complaint_no", firstOrderNo),
		zap.Uint("complaint_id", complaint.ID),
		zap.Int("detail_count", len(details)),
	)

	// 如果alipayComplainId为0，记录警告
	if alipayComplainId == 0 {
		w.logger.Warn("投诉数据保存成功，但alipay_complain_id为0，可能无法调用完结投诉API",
			zap.String("alipay_task_id", alipayTaskId),
			zap.String("complaint_id", complaintID),
		)
	}

	// 7. 根据订单号查询订单，获取购买者UID并拉黑
	err = w.processBlacklistFromOrders(detailResp.TargetOrderList, alipayTaskId)
	if err != nil {
		// 拉黑失败不影响投诉数据保存，只记录错误日志
		w.logger.Error("处理拉黑失败",
			zap.String("alipay_task_id", alipayTaskId),
			zap.String("complaint_no", firstOrderNo),
			zap.Error(err),
		)
	}

	return nil
}

// processBlacklistFromOrders 根据订单列表处理拉黑
// alipayTaskId: 支付宝投诉单号（TaskId），用于日志和回退查询
func (w *SubjectWorker) processBlacklistFromOrders(orderList []service.OrderItem, alipayTaskId string) error {
	if len(orderList) == 0 {
		w.logger.Debug("投诉订单列表为空，跳过拉黑",
			zap.String("alipay_task_id", alipayTaskId),
		)
		return nil
	}

	// 1. 提取订单号列表
	merchantOrderNos := make([]string, 0)
	platformOrderNos := make([]string, 0)

	for _, orderItem := range orderList {
		if orderItem.OutTradeNo != "" {
			merchantOrderNos = append(merchantOrderNos, orderItem.OutTradeNo)
		}
		if orderItem.TradeNo != "" {
			platformOrderNos = append(platformOrderNos, orderItem.TradeNo)
		}
	}

	// 2. 查询订单，获取购买者UID列表
	buyerIDs, err := w.orderRepo.GetBuyerIDsByOrderNos(merchantOrderNos, platformOrderNos)
	if err != nil {
		w.logger.Warn("查询订单失败，回退使用ComplainantID",
			zap.String("alipay_task_id", alipayTaskId),
			zap.Error(err),
		)
		// 回退到使用ComplainantID（需要从投诉记录中获取）
		return w.fallbackToComplainantID(alipayTaskId)
	}

	// 3. 如果查询不到buyer_id，回退使用ComplainantID
	if len(buyerIDs) == 0 {
		w.logger.Warn("未查询到购买者UID，回退使用ComplainantID",
			zap.String("alipay_task_id", alipayTaskId),
			zap.Strings("merchant_order_nos", merchantOrderNos),
			zap.Strings("platform_order_nos", platformOrderNos),
		)
		return w.fallbackToComplainantID(alipayTaskId)
	}

	// 4. 对每个购买者UID进行拉黑
	// 注意：需要根据订单号获取该用户的支付IP和设备码
	successCount := 0
	failedCount := 0

	// 先查询所有相关订单，建立 buyer_id 到订单的映射
	orders, err := w.orderRepo.FindByOrderNos(merchantOrderNos, platformOrderNos)
	if err != nil {
		w.logger.Warn("批量查询订单失败，回退使用ComplainantID",
			zap.String("alipay_task_id", alipayTaskId),
			zap.Error(err),
		)
		return w.fallbackToComplainantID(alipayTaskId)
	}

	// 建立 buyer_id 到订单的映射（一个buyer_id可能对应多个订单）
	buyerOrderMap := make(map[string][]*model.Order)
	for _, order := range orders {
		if order.IsPaid() && order.HasBuyerID() {
			buyerOrderMap[order.BuyerID] = append(buyerOrderMap[order.BuyerID], order)
		}
	}

	for _, buyerID := range buyerIDs {
		if buyerID == "" {
			continue // 跳过空的buyer_id
		}

		// 从订单表中获取该用户的支付IP和设备码
		// 根据用户要求：IP和设备码都来自于订单表
		// - 支付IP：从订单表的 pay_ip 字段获取（优先），如果没有则使用 first_open_ip
		// - 设备码：订单表中暂时没有设备码字段，使用空字符串
		ipAddress := ""
		deviceCode := "" // 订单表中暂时没有设备码字段，使用空字符串

		// 从该buyer_id对应的订单中获取IP地址（优先使用支付IP）
		if buyerOrders, exists := buyerOrderMap[buyerID]; exists && len(buyerOrders) > 0 {
			// 遍历该用户的所有订单，优先使用支付IP（pay_ip）
			// 如果所有订单都没有支付IP，则使用第一个订单的首次打开IP（first_open_ip）
			for _, order := range buyerOrders {
				// 优先使用支付IP（pay_ip）
				if order.PayIP != "" {
					ipAddress = order.PayIP
					break // 找到支付IP就退出
				}
			}
			// 如果没有找到支付IP，使用首次打开IP（first_open_ip）
			if ipAddress == "" {
				for _, order := range buyerOrders {
					if order.FirstOpenIP != "" {
						ipAddress = order.FirstOpenIP
						break // 找到首次打开IP就退出
					}
				}
			}
		} else {
			// 如果没有找到对应的订单，尝试从订单号列表查询（使用第一个订单号）
			if len(merchantOrderNos) > 0 {
				orderIP, _, err := w.orderRepo.GetOrderIPAndDevice(merchantOrderNos[0], "")
				if err == nil && orderIP != "" {
					ipAddress = orderIP
				}
			}
		}

		w.logger.Info("准备拉黑用户",
			zap.String("alipay_task_id", alipayTaskId),
			zap.String("buyer_id", buyerID),
			zap.String("pay_ip", ipAddress),
			zap.String("device_code", deviceCode),
		)

		// 调用拉黑服务
		err := w.blacklistSvc.AddToBlacklist(
			w.subject, // 主体信息（用于消息通知）
			buyerID,
			deviceCode, // 设备码（订单表中暂时没有，使用空字符串）
			ipAddress,  // 支付IP（从订单表的pay_ip字段获取）
			alipayTaskId,
		)
		if err != nil {
			failedCount++
			w.logger.Error("拉黑失败",
				zap.String("alipay_task_id", alipayTaskId),
				zap.String("buyer_id", buyerID),
				zap.String("pay_ip", ipAddress),
				zap.Error(err),
			)
			// 继续处理其他buyer_id，不中断流程
			continue
		}

		successCount++
		w.logger.Info("拉黑成功",
			zap.String("alipay_task_id", alipayTaskId),
			zap.String("buyer_id", buyerID),
			zap.String("pay_ip", ipAddress),
			zap.String("device_code", deviceCode),
		)
	}

	w.logger.Info("投诉拉黑处理完成",
		zap.String("alipay_task_id", alipayTaskId),
		zap.Int("total_buyer_ids", len(buyerIDs)),
		zap.Int("success_count", successCount),
		zap.Int("failed_count", failedCount),
	)

	return nil
}

// fallbackToComplainantID 回退使用ComplainantID进行拉黑
// alipayTaskId: 支付宝投诉单号（TaskId）
func (w *SubjectWorker) fallbackToComplainantID(alipayTaskId string) error {
	// 查询投诉记录获取ComplainantID（使用支付宝投诉单号查询）
	complaint, err := w.complaintRepo.FindByAlipayTaskId(w.subject.ID, alipayTaskId)
	if err != nil {
		return fmt.Errorf("查询投诉记录失败: %w", err)
	}

	if complaint == nil {
		return fmt.Errorf("投诉记录不存在: alipay_task_id=%s", alipayTaskId)
	}

	if complaint.ComplainantID == "" {
		w.logger.Warn("ComplainantID为空，无法拉黑",
			zap.String("alipay_task_id", alipayTaskId),
		)
		return nil
	}

	// 使用ComplainantID进行拉黑
	err = w.blacklistSvc.AddToBlacklist(
		w.subject, // 主体信息（用于消息通知）
		complaint.ComplainantID,
		"", // 设备码为空
		"", // IP地址为空
		alipayTaskId,
	)
	if err != nil {
		return fmt.Errorf("使用ComplainantID拉黑失败: %w", err)
	}

	w.logger.Info("使用ComplainantID拉黑成功",
		zap.String("alipay_task_id", alipayTaskId),
		zap.String("complainant_id", complaint.ComplainantID),
	)

	return nil
}

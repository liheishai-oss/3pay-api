package service

import (
	"context"
	"encoding/json"
	"fmt"
	"strconv"
	"time"

	"github.com/smartwalle/alipay/v3"
	"go.uber.org/zap"
)

// AlipayService 支付宝API服务
type AlipayService struct {
	logger *zap.Logger
}

// NewAlipayService 创建支付宝API服务
func NewAlipayService(logger *zap.Logger) *AlipayService {
	return &AlipayService{
		logger: logger,
	}
}

// ComplaintListRequest 投诉列表请求参数
type ComplaintListRequest struct {
	BeginTime string // 查询开始时间（必填，格式：yyyy-MM-dd HH:mm:ss）
	EndTime   string // 查询结束时间（必填，格式：yyyy-MM-dd HH:mm:ss）
	PageNum   int    // 页码（从1开始）
	PageSize  int    // 每页数量（默认20，最大100）
}

// ComplaintListResponse 投诉列表响应
type ComplaintListResponse struct {
	Total         int64           `json:"total"`          // 总数量
	ComplaintList []ComplaintItem `json:"complaint_list"` // 投诉列表
}

// ComplaintItem 投诉项
type ComplaintItem struct {
	ComplaintID      int64  `json:"complaint_id"`       // 投诉主表主键ID（用于查询详情）
	ComplaintEventID string `json:"complaint_event_id"` // 投诉单号（TaskId）
	Status           string `json:"status"`             // 投诉状态
	ComplainantID    string `json:"complainant_id"`     // 投诉人ID（OppositePid）
	GmtCreate        string `json:"gmt_create"`         // 创建时间（GmtComplain）
	GmtModified      string `json:"gmt_modified"`       // 修改时间（GmtProcess）
}

// ComplaintDetailRequest 投诉详情请求参数
type ComplaintDetailRequest struct {
	ComplaintEventID string // 投诉单号（必填）
}

// ComplaintDetailResponse 投诉详情响应
type ComplaintDetailResponse struct {
	ComplaintEventID string      `json:"complaint_event_id"` // 投诉单号
	Status           string      `json:"status"`             // 投诉状态
	ComplainantID    string      `json:"complainant_id"`     // 投诉人ID
	ComplainantName  string      `json:"complainant_name"`   // 投诉人姓名
	ComplaintReason  string      `json:"complaint_reason"`   // 投诉原因
	GmtCreate        string      `json:"gmt_create"`         // 创建时间
	GmtModified      string      `json:"gmt_modified"`       // 修改时间
	TargetOrderList  []OrderItem `json:"target_order_list"`  // 订单列表
}

// OrderItem 订单项
type OrderItem struct {
	TradeNo         string  `json:"trade_no"`         // 支付宝订单号
	OutTradeNo      string  `json:"out_trade_no"`     // 商户订单号
	Amount          float64 `json:"amount"`           // 订单金额
	ComplaintAmount float64 `json:"complaint_amount"` // 投诉金额
}

// FetchComplaintList 获取投诉列表
// 使用SDK提供的 SecurityRiskComplaintInfoBatchQuery 方法
func (s *AlipayService) FetchComplaintList(client *alipay.Client, req ComplaintListRequest) (*ComplaintListResponse, error) {
	startTime := time.Now()

	s.logger.Info("开始调用支付宝投诉列表API",
		zap.String("begin_time", req.BeginTime),
		zap.String("end_time", req.EndTime),
		zap.Int("page_num", req.PageNum),
		zap.Int("page_size", req.PageSize),
	)

	// 参数验证
	// 注意：时间参数可以为空，如果为空则不按时间过滤
	// 但为了性能和准确性，建议设置合理的时间范围
	if req.PageNum < 1 {
		req.PageNum = 1
	}
	if req.PageSize < 1 {
		req.PageSize = 20
	}
	if req.PageSize > 200 {
		req.PageSize = 200 // 根据参考代码，最大支持200
	}

	// 注意：时间参数可以为空，如果为空则不按时间过滤（查询所有数据）
	// 如果设置了时间范围，则使用设置的值
	// 如果没有设置，可以选择不传时间参数（让API返回所有数据）或使用默认值
	// 这里不设置默认值，允许不传时间参数

	// 构建请求参数（使用SDK提供的结构体）
	// 参考代码：直接设置所有参数，时间参数必须设置
	payload := alipay.SecurityRiskComplaintInfoBatchQueryReq{
		CurrentPageNum: int64(req.PageNum),
		PageSize:       int64(req.PageSize),
	}

	// 设置时间参数（参考代码中总是设置）
	// 如果时间为空，则不设置（SDK的omitempty标签会忽略空字符串）
	// 但为了与参考代码保持一致，这里总是设置时间参数
	payload.GmtComplaintStart = req.BeginTime // 投诉时间范围下界（格式：yyyy-MM-dd HH:mm:ss）
	payload.GmtComplaintEnd = req.EndTime     // 投诉时间范围上界（格式：yyyy-MM-dd HH:mm:ss）

	// 打印完整的查询条件（JSON格式，便于调试）
	payloadJSON, _ := json.Marshal(payload)
	s.logger.Info("=== 支付宝投诉列表API查询条件 ===",
		zap.String("api_name", "alipay.security.risk.complaint.info.batchquery"),
		zap.String("payload_json", string(payloadJSON)),
		zap.String("gmt_complaint_start", payload.GmtComplaintStart),
		zap.String("gmt_complaint_end", payload.GmtComplaintEnd),
		zap.Int64("current_page_num", payload.CurrentPageNum),
		zap.Int64("page_size", payload.PageSize),
	)
	fmt.Printf("=== 支付宝投诉列表API查询条件 ===\n")
	fmt.Printf("API名称: alipay.security.risk.complaint.info.batchquery\n")
	fmt.Printf("查询开始时间: %s\n", payload.GmtComplaintStart)
	fmt.Printf("查询结束时间: %s\n", payload.GmtComplaintEnd)
	fmt.Printf("当前页码: %d\n", payload.CurrentPageNum)
	fmt.Printf("每页数量: %d\n", payload.PageSize)
	fmt.Printf("完整Payload JSON: %s\n\n", string(payloadJSON))

	// 调用支付宝API（使用SDK提供的方法）
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()

	result, err := client.SecurityRiskComplaintInfoBatchQuery(ctx, payload)
	if err != nil {
		duration := time.Since(startTime)
		s.logger.Error("调用支付宝投诉列表API失败",
			zap.Error(err),
			zap.String("error_type", fmt.Sprintf("%T", err)),
			zap.Duration("duration", duration),
		)
		return nil, fmt.Errorf("调用投诉列表API失败: %w", err)
	}

	// 打印原始响应（用于调试）
	responseJSON, _ := json.Marshal(result)
	fmt.Printf("=== 支付宝API响应结果 ===\n")
	fmt.Printf("响应码: %s\n", string(result.Code))
	fmt.Printf("响应消息: %s\n", result.Msg)
	fmt.Printf("子响应码: %s\n", result.SubCode)
	fmt.Printf("子响应消息: %s\n", result.SubMsg)
	fmt.Printf("总记录数: %d\n", result.TotalSize)
	fmt.Printf("当前页记录数: %d\n", len(result.ComplaintList))
	fmt.Printf("完整响应JSON: %s\n", string(responseJSON))
	fmt.Printf("========================\n\n")

	s.logger.Info("支付宝API原始响应",
		zap.String("response", string(responseJSON)),
		zap.String("code", string(result.Code)),
		zap.String("msg", result.Msg),
		zap.String("sub_code", result.SubCode),
		zap.String("sub_msg", result.SubMsg),
		zap.Int64("total_size", result.TotalSize),
		zap.Int("complaint_count", len(result.ComplaintList)),
	)

	// 检查响应状态
	if result.IsFailure() {
		duration := time.Since(startTime)
		errorMsg := result.Msg
		if result.SubMsg != "" {
			errorMsg = fmt.Sprintf("%s - %s", result.Msg, result.SubMsg)
		}
		s.logger.Error("支付宝投诉列表API返回错误",
			zap.String("code", string(result.Code)),
			zap.String("msg", result.Msg),
			zap.String("sub_code", result.SubCode),
			zap.String("sub_msg", result.SubMsg),
			zap.String("raw_response", string(responseJSON)),
			zap.Duration("duration", duration),
		)
		return nil, fmt.Errorf("API返回错误: %s - %s (sub_code: %s, sub_msg: %s)",
			string(result.Code), errorMsg, result.SubCode, result.SubMsg)
	}

	// 转换投诉列表数据
	complaintList := make([]ComplaintItem, 0, len(result.ComplaintList))
	for _, item := range result.ComplaintList {
		// 提取投诉单号（使用TaskId）
		complaintEventID := item.TaskId
		if complaintEventID == "" {
			// 如果TaskId为空，使用ID作为备用
			complaintEventID = fmt.Sprintf("%d", item.Id)
		}

		complaintList = append(complaintList, ComplaintItem{
			ComplaintID:      item.Id,          // 投诉主表主键ID（用于查询详情）
			ComplaintEventID: complaintEventID, // 投诉单号（TaskId）
			Status:           item.Status,      // 投诉状态
			ComplainantID:    item.OppositePid, // 被投诉人PID
			GmtCreate:        item.GmtComplain, // 投诉时间
			GmtModified:      item.GmtProcess,  // 处理时间
		})
	}

	duration := time.Since(startTime)
	s.logger.Info("支付宝投诉列表API调用成功",
		zap.Int64("total", result.TotalSize),
		zap.Int("count", len(complaintList)),
		zap.Duration("duration", duration),
	)

	return &ComplaintListResponse{
		Total:         result.TotalSize,
		ComplaintList: complaintList,
	}, nil
}

// 注意：现在使用 alipay.Payload 类型，不再需要自定义 Param 实现
// MerchantTradecomplainBatchqueryParam 和 MerchantTradecomplainQueryParam 已移除

// FetchComplaintDetail 获取投诉详情
// 使用SDK提供的 SecurityRiskComplaintInfoQuery 方法
// 注意：complaint_event_id 应该是投诉主表的主键ID（int64），而不是投诉单号
func (s *AlipayService) FetchComplaintDetail(client *alipay.Client, req ComplaintDetailRequest) (*ComplaintDetailResponse, error) {
	startTime := time.Now()

	s.logger.Info("开始调用支付宝投诉详情API",
		zap.String("complaint_event_id", req.ComplaintEventID),
	)

	// 参数验证
	if req.ComplaintEventID == "" {
		return nil, fmt.Errorf("投诉单号不能为空")
	}

	// 将complaint_event_id转换为int64（投诉主表的主键ID）
	// 注意：如果complaint_event_id是字符串格式的ID，需要转换
	var complainID int64
	if _, err := fmt.Sscanf(req.ComplaintEventID, "%d", &complainID); err != nil {
		// 如果无法转换为int64，尝试查找对应的投诉ID
		// 这里可能需要从数据库查询，暂时返回错误
		return nil, fmt.Errorf("投诉单号格式错误，需要是数字ID: %s", req.ComplaintEventID)
	}

	// 构建请求参数（使用SDK提供的结构体）
	payload := alipay.SecurityRiskComplaintInfoQueryReq{
		ComplainId: complainID,
	}

	// 记录请求参数
	s.logger.Info("支付宝投诉详情API请求参数",
		zap.String("api_name", "alipay.security.risk.complaint.info.query"),
		zap.Int64("complain_id", complainID),
	)

	// 调用支付宝API（使用SDK提供的方法）
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()

	result, err := client.SecurityRiskComplaintInfoQuery(ctx, payload)
	if err != nil {
		duration := time.Since(startTime)
		s.logger.Error("调用支付宝投诉详情API失败",
			zap.String("complaint_event_id", req.ComplaintEventID),
			zap.Int64("complain_id", complainID),
			zap.Error(err),
			zap.String("error_type", fmt.Sprintf("%T", err)),
			zap.Duration("duration", duration),
		)
		return nil, fmt.Errorf("调用投诉详情API失败: %w", err)
	}

	// 打印原始响应（完整的JSON，用于检查是否有SDK未映射的字段）
	responseJSON, _ := json.Marshal(result)
	fmt.Printf("\n=== 支付宝投诉详情API原始响应 ===\n")
	fmt.Printf("响应码: %s\n", string(result.Code))
	fmt.Printf("响应消息: %s\n", result.Msg)
	fmt.Printf("完整响应JSON: %s\n", string(responseJSON))
	fmt.Printf("投诉单号(TaskId): %s\n", result.TaskId)
	fmt.Printf("投诉状态: %s\n", result.Status)
	fmt.Printf("投诉总金额(ComplainAmount): %s\n", result.ComplainAmount)
	fmt.Printf("投诉内容: %s\n", result.ComplainContent)
	fmt.Printf("投诉人PID: %s\n", result.OppositePid)
	fmt.Printf("投诉人姓名: %s\n", result.OppositeName)
	fmt.Printf("订单数量: %d\n", len(result.ComplaintTradeInfoList))
	fmt.Printf("===============================\n\n")

	s.logger.Info("支付宝投诉详情API原始响应",
		zap.String("response", string(responseJSON)),
		zap.String("code", string(result.Code)),
		zap.String("msg", result.Msg),
		zap.String("task_id", result.TaskId),
		zap.String("status", result.Status),
		zap.String("complain_amount", result.ComplainAmount),
		zap.Int("trade_info_count", len(result.ComplaintTradeInfoList)),
	)

	// 检查响应状态
	if result.IsFailure() {
		duration := time.Since(startTime)
		errorMsg := result.Msg
		if result.SubMsg != "" {
			errorMsg = fmt.Sprintf("%s - %s", result.Msg, result.SubMsg)
		}
		s.logger.Error("支付宝投诉详情API返回错误",
			zap.String("complaint_event_id", req.ComplaintEventID),
			zap.Int64("complain_id", complainID),
			zap.String("code", string(result.Code)),
			zap.String("msg", result.Msg),
			zap.String("sub_code", result.SubCode),
			zap.String("sub_msg", result.SubMsg),
			zap.String("raw_response", string(responseJSON)),
			zap.Duration("duration", duration),
		)
		return nil, fmt.Errorf("API返回错误: %s - %s (sub_code: %s, sub_msg: %s)",
			string(result.Code), errorMsg, result.SubCode, result.SubMsg)
	}

	// 转换订单列表数据
	targetOrderList := make([]OrderItem, 0, len(result.ComplaintTradeInfoList))

	// 记录完整的投诉详情响应，用于调试
	s.logger.Info("支付宝投诉详情API响应详细信息",
		zap.String("complaint_event_id", req.ComplaintEventID),
		zap.Int64("complain_id", complainID),
		zap.String("task_id", result.TaskId),
		zap.String("status", result.Status),
		zap.String("complain_amount", result.ComplainAmount), // 投诉单涉及交易总金额
		zap.String("complain_content", result.ComplainContent),
		zap.String("opposite_pid", result.OppositePid),
		zap.String("opposite_name", result.OppositeName),
		zap.Int("trade_info_count", len(result.ComplaintTradeInfoList)),
	)

	// 打印每个订单的详细信息
	for i, tradeInfo := range result.ComplaintTradeInfoList {
		s.logger.Info("投诉订单详情",
			zap.Int("index", i),
			zap.String("trade_no", tradeInfo.TradeNo),
			zap.String("out_no", tradeInfo.OutNo),
			zap.String("amount", tradeInfo.Amount),
			zap.String("status", tradeInfo.Status),
			zap.String("status_description", tradeInfo.StatusDescription),
			zap.String("gmt_trade", tradeInfo.GmtTrade),
			zap.String("gmt_refund", tradeInfo.GmtRefund),
		)
	}

	for _, tradeInfo := range result.ComplaintTradeInfoList {
		// 解析订单金额
		amount := 0.0
		if tradeInfo.Amount != "" {
			if parsedAmount, err := strconv.ParseFloat(tradeInfo.Amount, 64); err == nil {
				amount = parsedAmount
			}
		}

		// 注意：根据支付宝API文档，每个订单的投诉金额(complaint_amount)可能等于订单金额(amount)
		// 如果订单有部分退款，投诉金额可能小于订单金额
		// 但是SDK返回的SecurityRiskComplaintTradeInfo结构中没有单独的complaint_amount字段
		// 因此这里使用订单金额作为投诉金额
		// 如果后续API返回了单独的complaint_amount字段，需要更新SDK结构或手动解析
		complaintAmount := amount

		targetOrderList = append(targetOrderList, OrderItem{
			TradeNo:         tradeInfo.TradeNo,
			OutTradeNo:      tradeInfo.OutNo,
			Amount:          amount,
			ComplaintAmount: complaintAmount, // 使用订单金额作为投诉金额（如果API返回了单独的complaint_amount，需要更新）
		})
	}

	// 构建响应
	detailResponse := &ComplaintDetailResponse{
		ComplaintEventID: result.TaskId, // 使用TaskId作为投诉单号
		Status:           result.Status,
		ComplainantID:    result.OppositePid, // 被投诉人PID
		ComplainantName:  result.OppositeName,
		ComplaintReason:  result.ComplainContent,
		GmtCreate:        result.GmtComplain,
		GmtModified:      result.GmtProcess,
		TargetOrderList:  targetOrderList,
	}

	if detailResponse.TargetOrderList == nil {
		detailResponse.TargetOrderList = []OrderItem{}
	}

	duration := time.Since(startTime)
	s.logger.Info("支付宝投诉详情API调用成功",
		zap.String("complaint_event_id", req.ComplaintEventID),
		zap.Int64("complain_id", complainID),
		zap.Int("order_count", len(detailResponse.TargetOrderList)),
		zap.Duration("duration", duration),
	)

	return detailResponse, nil
}

// 注意：现在使用 alipay.Payload 类型，不再需要自定义 Param 实现

// FinishComplaint 完结投诉
func (s *AlipayService) FinishComplaint(client *alipay.Client, complaintEventID string) error {
	s.logger.Debug("调用支付宝完结投诉API",
		zap.String("complaint_event_id", complaintEventID),
	)

	// TODO: 实际调用支付宝API

	return fmt.Errorf("API未实现")
}

// ReplyComplaint 回复投诉
func (s *AlipayService) ReplyComplaint(client *alipay.Client, complaintEventID, replyContent string) error {
	s.logger.Debug("调用支付宝回复投诉API",
		zap.String("complaint_event_id", complaintEventID),
		zap.String("reply_content", replyContent),
	)

	// TODO: 实际调用支付宝API

	return fmt.Errorf("API未实现")
}

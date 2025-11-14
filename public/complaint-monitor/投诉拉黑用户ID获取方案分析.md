# 投诉拉黑用户ID获取方案分析

## 一、当前实现分析

### 1. 当前实现方式

**数据来源**：直接从支付宝投诉详情API的 `ComplainantID` 字段获取

**流程**：
```
支付宝投诉详情API
    ↓
alipay.merchant.tradecomplain.query
    ↓
响应字段：complainant_id
    ↓
subject_worker.go::processComplaint()
    ↓
complaint.ComplainantID = detailResp.ComplainantID
    ↓
blacklistService.AddToBlacklist(complainantID, ...)
    ↓
alipay_blacklist表
```

**代码位置**：
- `internal/worker/subject_worker.go:302`：设置 `ComplainantID`
- `internal/service/blacklist_service.go:44`：使用 `ComplainantID` 进行拉黑

### 2. 当前实现的问题

#### 问题1：`ComplainantID` 字段可能不准确
- **不确定性问题**：`complainant_id` 字段可能不是投诉人的真实支付宝用户ID
- **数据来源不明**：不清楚支付宝API返回的 `complainant_id` 具体是什么含义
- **可靠性问题**：如果该字段不准确，拉黑会失效或拉黑错误的用户

#### 问题2：无法处理多订单场景
- **单一用户ID**：只能获取一个 `ComplainantID`，无法处理涉及多个订单的投诉
- **订单关联缺失**：投诉详情中包含多个订单（`TargetOrderList`），但只使用了一个用户ID

#### 问题3：数据不一致
- **订单数据未利用**：投诉详情中已经包含了订单信息（`OutTradeNo`、`TradeNo`），但未用于查询本地订单数据
- **本地数据未关联**：本地订单表（`order`表）中有真实的 `buyer_id`（支付时的用户ID），但未使用

---

## 二、建议方案分析

### 1. 方案描述

**核心思路**：根据投诉详情里的订单号，查询本地订单表，获取真实的购买者UID（`buyer_id`），然后进行拉黑

**流程**：
```
支付宝投诉详情API
    ↓
alipay.merchant.tradecomplain.query
    ↓
响应字段：target_order_list[]
    ↓
遍历订单列表
    ↓
根据订单号查询本地order表
    ↓
获取订单的buyer_id（支付时的真实用户ID）
    ↓
对每个buyer_id进行拉黑
    ↓
alipay_blacklist表
```

### 2. 方案优势

#### 优势1：数据准确性
- **真实用户ID**：使用订单表中的 `buyer_id`，这是支付时的真实支付宝用户ID
- **数据一致性**：与订单支付数据保持一致，不依赖支付宝API返回的可能不准确的字段
- **可靠性高**：订单表中的 `buyer_id` 是在支付回调或补单时从支付宝返回的数据中更新的

#### 优势2：支持多订单场景
- **批量处理**：可以处理涉及多个订单的投诉
- **多用户拉黑**：如果一个投诉涉及多个订单，且订单的购买者不同，可以拉黑所有相关的购买者
- **数据完整性**：每个订单都有独立的 `buyer_id`，确保拉黑的准确性

#### 优势3：数据关联性强
- **本地数据利用**：充分利用本地订单表中的数据
- **数据追溯**：可以通过订单号追溯订单的完整信息（包括购买者、支付时间、支付金额等）
- **数据一致性**：与订单支付流程保持一致

#### 优势4：容错性强
- **订单不存在处理**：如果订单不存在，可以记录日志并跳过
- **buyer_id为空处理**：如果订单的 `buyer_id` 为空，可以记录警告并跳过
- **部分成功处理**：如果部分订单查询失败，其他订单仍可正常处理

### 3. 方案劣势

#### 劣势1：性能开销
- **数据库查询**：需要查询本地订单表，增加数据库查询次数
- **批量查询优化**：如果涉及多个订单，需要进行多次查询或批量查询
- **解决方案**：使用批量查询（`WHERE IN`）或 `JOIN` 查询优化性能

#### 劣势2：依赖本地数据
- **订单数据缺失**：如果订单表中没有对应的订单数据，无法获取 `buyer_id`
- **数据同步问题**：如果订单数据未及时同步，可能无法获取最新的 `buyer_id`
- **解决方案**：记录日志，对于无法查询到的订单，可以回退到使用 `ComplainantID` 或记录警告

#### 劣势3：实现复杂度
- **代码改动**：需要创建 `OrderRepository`，添加订单查询逻辑
- **错误处理**：需要处理订单不存在、`buyer_id` 为空等多种情况
- **解决方案**：分步骤实现，先实现基本功能，再优化错误处理

---

## 三、技术实现方案

### 1. 数据库模型

#### 订单表结构（`order`表）
```sql
CREATE TABLE `order` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `platform_order_no` varchar(64) NOT NULL COMMENT '平台订单号',
  `merchant_order_no` varchar(64) NOT NULL COMMENT '商户订单号',
  `alipay_order_no` varchar(64) DEFAULT NULL COMMENT '支付宝订单号',
  `buyer_id` varchar(64) DEFAULT NULL COMMENT '购买者UID（支付宝用户ID）',
  -- ... 其他字段
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_order_no` (`platform_order_no`),
  KEY `idx_merchant_order_no` (`merchant_order_no`),
  KEY `idx_alipay_order_no` (`alipay_order_no`)
);
```

#### 查询字段映射
- **商户订单号**（`merchant_order_no`）：对应投诉详情中的 `out_trade_no`
- **平台订单号**（`platform_order_no`）：对应投诉详情中的 `trade_no`（支付宝订单号）
- **购买者UID**（`buyer_id`）：支付时的真实支付宝用户ID

### 2. 代码实现

#### 步骤1：创建Order模型

**文件**：`internal/model/order.go`
```go
package model

import "time"

// Order 订单模型
type Order struct {
    ID               uint      `gorm:"column:id;primaryKey" json:"id"`
    PlatformOrderNo  string    `gorm:"column:platform_order_no;size:64;uniqueIndex" json:"platform_order_no"`
    MerchantOrderNo  string    `gorm:"column:merchant_order_no;size:64;index" json:"merchant_order_no"`
    AlipayOrderNo    string    `gorm:"column:alipay_order_no;size:64;index" json:"alipay_order_no"`
    BuyerID          string    `gorm:"column:buyer_id;size:64;index" json:"buyer_id"`
    OrderAmount      float64   `gorm:"column:order_amount;type:decimal(15,2)" json:"order_amount"`
    PayStatus        int       `gorm:"column:pay_status" json:"pay_status"`
    PayTime          *time.Time `gorm:"column:pay_time" json:"pay_time"`
    CreatedAt        time.Time `gorm:"column:created_at" json:"created_at"`
    UpdatedAt        time.Time `gorm:"column:updated_at" json:"updated_at"`
}

// TableName 指定表名
func (Order) TableName() string {
    return "order"
}
```

#### 步骤2：创建OrderRepository

**文件**：`internal/repository/order_repo.go`
```go
package repository

import (
    "fmt"
    "complaint-monitor/internal/model"
    "go.uber.org/zap"
    "gorm.io/gorm"
)

// OrderRepository 订单仓库
type OrderRepository struct {
    *BaseRepository
}

// NewOrderRepository 创建订单仓库
func NewOrderRepository(db *gorm.DB, logger *zap.Logger) *OrderRepository {
    return &OrderRepository{
        BaseRepository: NewBaseRepository(db, logger),
    }
}

// FindByMerchantOrderNo 根据商户订单号查询订单
func (r *OrderRepository) FindByMerchantOrderNo(merchantOrderNo string) (*model.Order, error) {
    var order model.Order
    err := r.db.Where("merchant_order_no = ?", merchantOrderNo).First(&order).Error
    if err != nil {
        if err == gorm.ErrRecordNotFound {
            return nil, nil // 订单不存在，返回nil
        }
        return nil, fmt.Errorf("查询订单失败: %w", err)
    }
    return &order, nil
}

// FindByPlatformOrderNo 根据平台订单号（支付宝订单号）查询订单
func (r *OrderRepository) FindByPlatformOrderNo(platformOrderNo string) (*model.Order, error) {
    var order model.Order
    err := r.db.Where("alipay_order_no = ?", platformOrderNo).First(&order).Error
    if err != nil {
        if err == gorm.ErrRecordNotFound {
            return nil, nil // 订单不存在，返回nil
        }
        return nil, fmt.Errorf("查询订单失败: %w", err)
    }
    return &order, nil
}

// FindByOrderNos 批量查询订单（根据商户订单号或平台订单号）
func (r *OrderRepository) FindByOrderNos(merchantOrderNos []string, platformOrderNos []string) ([]*model.Order, error) {
    var orders []*model.Order
    query := r.db.Model(&model.Order{})
    
    if len(merchantOrderNos) > 0 && len(platformOrderNos) > 0 {
        // 同时查询商户订单号和平台订单号
        query = query.Where("merchant_order_no IN ? OR alipay_order_no IN ?", merchantOrderNos, platformOrderNos)
    } else if len(merchantOrderNos) > 0 {
        query = query.Where("merchant_order_no IN ?", merchantOrderNos)
    } else if len(platformOrderNos) > 0 {
        query = query.Where("alipay_order_no IN ?", platformOrderNos)
    } else {
        return orders, nil // 没有查询条件，返回空数组
    }
    
    err := query.Find(&orders).Error
    if err != nil {
        return nil, fmt.Errorf("批量查询订单失败: %w", err)
    }
    return orders, nil
}

// GetBuyerIDsByOrderNos 根据订单号列表获取购买者UID列表（去重）
func (r *OrderRepository) GetBuyerIDsByOrderNos(merchantOrderNos []string, platformOrderNos []string) ([]string, error) {
    orders, err := r.FindByOrderNos(merchantOrderNos, platformOrderNos)
    if err != nil {
        return nil, err
    }
    
    // 使用map去重
    buyerIDMap := make(map[string]bool)
    for _, order := range orders {
        if order.BuyerID != "" {
            buyerIDMap[order.BuyerID] = true
        }
    }
    
    // 转换为切片
    buyerIDs := make([]string, 0, len(buyerIDMap))
    for buyerID := range buyerIDMap {
        buyerIDs = append(buyerIDs, buyerID)
    }
    
    return buyerIDs, nil
}
```

#### 步骤3：修改Worker处理逻辑

**文件**：`internal/worker/subject_worker.go`

**修改点1**：添加OrderRepository
```go
type SubjectWorker struct {
    subject       *model.Subject
    subjectRepo   *repository.SubjectRepository
    complaintRepo *repository.ComplaintRepository
    blacklistRepo *repository.BlacklistRepository
    orderRepo     *repository.OrderRepository  // 新增
    certManager   *cert.CertManager
    lockManager   *lock.DistributedLock
    alipayService *service.AlipayService
    blacklistSvc  *service.BlacklistService
    fetchInterval time.Duration
    restartable   bool
    logger        *zap.Logger
    stopChan      chan struct{}
}
```

**修改点2**：修改processComplaint方法
```go
func (w *SubjectWorker) processComplaint(complaintNo string) error {
    // ... 现有的投诉详情获取逻辑 ...
    
    // 1. 获取投诉详情
    detailResp, err := w.alipayService.FetchComplaintDetail(client, req)
    if err != nil {
        return fmt.Errorf("获取投诉详情失败: %w", err)
    }
    
    // 2. 从订单列表中提取订单号
    merchantOrderNos := make([]string, 0)
    platformOrderNos := make([]string, 0)
    for _, orderItem := range detailResp.TargetOrderList {
        if orderItem.OutTradeNo != "" {
            merchantOrderNos = append(merchantOrderNos, orderItem.OutTradeNo)
        }
        if orderItem.TradeNo != "" {
            platformOrderNos = append(platformOrderNos, orderItem.TradeNo)
        }
    }
    
    // 3. 查询订单，获取购买者UID列表
    buyerIDs, err := w.orderRepo.GetBuyerIDsByOrderNos(merchantOrderNos, platformOrderNos)
    if err != nil {
        w.logger.Warn("查询订单失败，回退使用ComplainantID",
            zap.String("complaint_no", complaintNo),
            zap.Error(err),
        )
        // 回退到使用ComplainantID
        buyerIDs = []string{detailResp.ComplainantID}
    }
    
    // 4. 如果查询不到buyer_id，回退使用ComplainantID
    if len(buyerIDs) == 0 {
        w.logger.Warn("未查询到购买者UID，使用ComplainantID",
            zap.String("complaint_no", complaintNo),
            zap.String("complainant_id", detailResp.ComplainantID),
            zap.Strings("merchant_order_nos", merchantOrderNos),
            zap.Strings("platform_order_nos", platformOrderNos),
        )
        if detailResp.ComplainantID != "" {
            buyerIDs = []string{detailResp.ComplainantID}
        }
    }
    
    // 5. 对每个购买者UID进行拉黑
    for _, buyerID := range buyerIDs {
        if buyerID == "" {
            continue // 跳过空的buyer_id
        }
        
        // 获取设备码和IP地址（可以从订单中获取，如果有的话）
        deviceCode := "" // TODO: 从订单中获取设备码
        ipAddress := ""  // TODO: 从订单中获取IP地址
        
        // 调用拉黑服务
        err := w.blacklistSvc.AddToBlacklist(
            w.subject.ID,
            buyerID,
            deviceCode,
            ipAddress,
            complaintNo,
        )
        if err != nil {
            w.logger.Error("拉黑失败",
                zap.String("complaint_no", complaintNo),
                zap.String("buyer_id", buyerID),
                zap.Error(err),
            )
            // 继续处理其他buyer_id，不中断流程
            continue
        }
        
        w.logger.Info("拉黑成功",
            zap.String("complaint_no", complaintNo),
            zap.String("buyer_id", buyerID),
        )
    }
    
    // 6. 保存投诉数据（现有逻辑）
    // ... 现有的投诉数据保存逻辑 ...
    
    return nil
}
```

#### 步骤4：修改Manager初始化

**文件**：`cmd/main.go`
```go
// 初始化仓库层
db := database.GetDB()
subjectRepo := repository.NewSubjectRepository(db, log)
complaintRepo := repository.NewComplaintRepository(db, log)
blacklistRepo := repository.NewBlacklistRepository(db, log)
orderRepo := repository.NewOrderRepository(db, log)  // 新增

// 初始化Worker管理器
workerManager := worker.NewManager(
    cfg,
    subjectRepo,
    complaintRepo,
    blacklistRepo,
    orderRepo,  // 新增
    certManager,
    lockManager,
    alipayService,
    blacklistService,
    log,
)
```

### 3. 优化建议

#### 优化1：批量查询性能
- **使用IN查询**：使用 `WHERE IN` 批量查询订单，减少数据库查询次数
- **索引优化**：确保 `merchant_order_no` 和 `alipay_order_no` 字段有索引
- **查询缓存**：对于频繁查询的订单，可以使用Redis缓存

#### 优化2：错误处理
- **部分失败处理**：如果部分订单查询失败，其他订单仍可正常处理
- **回退机制**：如果查询不到订单，回退到使用 `ComplainantID`
- **日志记录**：记录详细的日志，便于排查问题

#### 优化3：数据完整性
- **设备码和IP地址**：如果订单表中有设备码和IP地址字段，可以从订单中获取
- **订单状态检查**：只拉黑已支付订单的购买者，避免拉黑未支付订单的用户

---

## 四、方案对比

| 对比项 | 当前方案（ComplainantID） | 建议方案（订单查询） |
|--------|-------------------------|---------------------|
| **数据准确性** | ⚠️ 不确定（依赖API返回） | ✅ 高（使用订单表数据） |
| **数据一致性** | ⚠️ 可能与订单数据不一致 | ✅ 与订单数据一致 |
| **多订单支持** | ❌ 不支持 | ✅ 支持 |
| **多用户支持** | ❌ 不支持 | ✅ 支持 |
| **性能开销** | ✅ 低（无额外查询） | ⚠️ 中等（需要查询订单表） |
| **实现复杂度** | ✅ 简单 | ⚠️ 中等（需要创建Repository） |
| **容错性** | ⚠️ 低（依赖API数据） | ✅ 高（有回退机制） |
| **数据追溯** | ❌ 无法追溯 | ✅ 可以追溯订单信息 |

---

## 五、实施建议

### 1. 分阶段实施

#### 阶段1：基础实现（推荐优先实施）
- 创建 `Order` 模型
- 创建 `OrderRepository`
- 修改 `processComplaint` 方法，使用订单查询获取 `buyer_id`
- 添加回退机制，如果查询不到订单，使用 `ComplainantID`

#### 阶段2：优化改进
- 优化批量查询性能
- 添加设备码和IP地址的获取逻辑
- 添加订单状态检查
- 添加查询缓存

#### 阶段3：监控和告警
- 添加监控指标（查询成功率、拉黑成功率等）
- 添加告警机制（订单查询失败率过高时告警）
- 添加日志分析（分析查询失败的原因）

### 2. 风险评估

#### 风险1：订单数据缺失
- **风险**：如果订单表中没有对应的订单数据，无法获取 `buyer_id`
- ** mitigation**：使用回退机制，如果查询不到订单，使用 `ComplainantID`

#### 风险2：性能问题
- **风险**：批量查询订单可能影响性能
- **mitigation**：使用批量查询（`WHERE IN`），添加索引，使用查询缓存

#### 风险3：数据不一致
- **风险**：如果订单数据未及时同步，可能无法获取最新的 `buyer_id`
- **mitigation**：记录日志，对于无法查询到的订单，使用 `ComplainantID` 或记录警告

### 3. 测试建议

#### 测试场景1：单订单投诉
- 测试单个订单的投诉，验证能否正确获取 `buyer_id` 并拉黑

#### 测试场景2：多订单投诉
- 测试多个订单的投诉，验证能否正确获取所有订单的 `buyer_id` 并拉黑

#### 测试场景3：订单不存在
- 测试订单不存在的情况，验证回退机制是否正常工作

#### 测试场景4：buyer_id为空
- 测试订单的 `buyer_id` 为空的情况，验证是否能正确处理

#### 测试场景5：性能测试
- 测试批量查询订单的性能，验证是否满足性能要求

---

## 六、总结

### 1. 方案合理性评估

**结论**：✅ **建议方案更合理**

**理由**：
1. **数据准确性更高**：使用订单表中的 `buyer_id`，这是支付时的真实支付宝用户ID
2. **支持多订单场景**：可以处理涉及多个订单的投诉，拉黑所有相关的购买者
3. **数据一致性更好**：与订单支付流程保持一致，不依赖API返回的可能不准确的字段
4. **容错性更强**：有回退机制，即使查询不到订单，仍可使用 `ComplainantID`

### 2. 实施优先级

**优先级**：🔴 **高优先级**

**原因**：
1. **数据准确性至关重要**：拉黑错误的用户会导致业务问题
2. **当前实现存在不确定性**：`ComplainantID` 字段的含义不明确，可能不准确
3. **实施难度适中**：需要创建 `OrderRepository` 和修改 `processComplaint` 方法，但难度不大

### 3. 下一步行动

1. **创建Order模型和Repository**：实现订单查询功能
2. **修改processComplaint方法**：使用订单查询获取 `buyer_id`
3. **添加回退机制**：如果查询不到订单，使用 `ComplainantID`
4. **测试验证**：测试各种场景，确保功能正常
5. **监控和告警**：添加监控指标和告警机制

---

## 七、参考文档

- [订单表结构](./third_party_payment_2025-11-06.sql)
- [投诉详情API文档](https://opendocs.alipay.com/apis/api_50/alipay.merchant.tradecomplain.query)
- [黑名单服务实现](./internal/service/blacklist_service.go)
- [Worker实现](./internal/worker/subject_worker.go)





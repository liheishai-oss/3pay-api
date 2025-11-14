# 黑名单用户ID获取说明

## 一、用户ID获取来源

### 1. 投诉监控系统（Go服务）中的拉黑

**用户ID来源**：从支付宝投诉详情API获取

#### 流程说明：

1. **调用支付宝投诉详情API**
   - API：`alipay.merchant.tradecomplain.query`
   - 文件：`3pay-api/public/complaint-monitor/internal/service/alipay_service.go`
   - 方法：`FetchComplaintDetail()`

2. **解析响应数据**
   - 响应字段：`complainant_id`（投诉人支付宝用户ID）
   - 文件：`3pay-api/public/complaint-monitor/internal/service/alipay_service.go:241`
   ```go
   ComplainantID    string `json:"complainant_id"`     // 投诉人ID
   ```

3. **构建投诉记录**
   - 文件：`3pay-api/public/complaint-monitor/internal/worker/subject_worker.go:302`
   ```go
   complaint := &model.Complaint{
       ComplainantID:   detailResp.ComplainantID,  // 从API响应获取
       // ...
   }
   ```

4. **触发拉黑**
   - 文件：`3pay-api/public/complaint-monitor/internal/service/blacklist_service.go:44`
   - 方法：`AddToBlacklist()`
   ```go
   func (s *BlacklistService) AddToBlacklist(
       subjectID int,
       alipayUserID string,  // 这里传入的就是 ComplainantID
       deviceCode string,
       ipAddress string,
       complaintNo string,
   ) error
   ```

**总结**：投诉监控系统中的用户ID直接来自支付宝投诉详情API的 `complainant_id` 字段。

---

### 2. PHP业务系统中的拉黑

**用户ID来源**：从订单表（`order`表）的 `buyer_id` 字段获取

#### buyer_id字段的更新来源：

##### 来源1：支付回调时更新
- **文件**：`3pay-api/app/api/controller/v1/PaymentNotifyController.php`
- **方法**：`updateOrderStatus()`
- **数据来源**：支付宝支付回调参数 `buyer_id`
- **更新逻辑**：第209-216行
  ```php
  if (!empty($notifyParams['buyer_id'])) {
      $updateData['buyer_id'] = $notifyParams['buyer_id'];
      // 更新订单的buyer_id字段
  }
  ```

##### 来源2：补单时更新
- **文件**：`3pay-api/app/service/OrderSupplementService.php`
- **方法**：`supplementOrder()`
- **数据来源**：支付宝订单查询API返回的 `buyer_id`
- **更新逻辑**：第526-545行
  ```php
  if (!empty($alipayOrder['buyer_id'])) {
      $order->buyer_id = $alipayOrder['buyer_id'];
      // 更新订单的buyer_id字段
  }
  ```

##### 来源3：OAuth授权时获取（但未直接更新到订单）
- **文件**：`3pay-api/app/controller/PaymentPageController.php`
- **方法**：`handleOAuthCallback()`
- **数据来源**：支付宝OAuth API返回的用户信息
- **用途**：用于支付时传递给支付宝，但不会直接更新到订单表的 `buyer_id` 字段
- **注意**：OAuth获取的 `buyer_id` 只在支付时使用，订单支付成功后，回调会更新 `buyer_id`

#### PHP业务系统中拉黑的使用场景：

##### 场景1：异常订单检测
- **文件**：`3pay-api/黑名单技术方案.md:537`
- **代码示例**：
  ```php
  $blacklistId = AlipayBlacklistService::addToBlacklist(
      $order->buyer_id,  // 从订单表获取
      $order->device_code,
      $order->client_ip,
      '检测到异常支付行为'
  );
  ```

##### 场景2：订单创建前检查
- **文件**：`3pay-api/黑名单技术方案.md:567`
- **代码示例**：
  ```php
  $buyerId = $request->input('buyer_id');
  if (AlipayBlacklistService::isInBlacklist($buyerId)) {
      // 拒绝订单创建
  }
  ```

##### 场景3：OAuth回调后检查
- **文件**：`3pay-api/ALIPAY_BLACKLIST_USAGE.md:133`
- **代码示例**：
  ```php
  $buyerId = $this->handleOAuthCallback($authCode, $subject, $product, $orderNumber);
  $checkResult = $blacklistService->checkBlacklist($buyerId, null, $currentIp);
  ```

**总结**：PHP业务系统中的用户ID来自订单表的 `buyer_id` 字段，该字段在支付回调或补单时从支付宝返回的数据中更新。

---

## 二、当前问题分析

### 问题：补单或回调后，购买者UID没更新

#### 原因分析：

1. **数据库字段缺失**
   - 初始数据库结构（`third_party_payment_2025-11-06.sql`）中，`order` 表没有 `buyer_id` 字段
   - 但代码中已经在使用这个字段

2. **更新逻辑问题**
   - 虽然代码中已经实现了更新逻辑，但如果数据库字段不存在，更新会失败
   - 或者字段存在但更新条件不满足（例如 `buyer_id` 为空）

#### 解决方案：

1. **执行SQL迁移文件**
   - 文件：`3pay-api/add_order_buyer_id_field.sql`
   - 执行该SQL文件，为 `order` 表添加 `buyer_id` 字段

2. **验证更新逻辑**
   - 支付回调：确保支付宝回调参数中包含 `buyer_id`
   - 补单：确保支付宝订单查询API返回 `buyer_id`
   - 日志：查看更新日志，确认是否成功更新

3. **检查数据源**
   - 支付回调：检查 `PaymentNotifyController::updateOrderStatus()` 是否接收到 `buyer_id`
   - 补单：检查 `AlipayQueryService::queryOrder()` 是否返回 `buyer_id`
   - 支付宝API：确认支付宝API是否返回了 `buyer_id` 字段

---

## 三、数据流向图

### 投诉监控系统（Go服务）

```
支付宝投诉详情API
    ↓
alipay.merchant.tradecomplain.query
    ↓
响应字段：complainant_id
    ↓
subject_worker.go::processComplaint()
    ↓
complaint.ComplainantID
    ↓
blacklistService.AddToBlacklist()
    ↓
alipay_blacklist表
```

### PHP业务系统

```
订单支付/补单
    ↓
支付宝API返回 buyer_id
    ↓
PaymentNotifyController::updateOrderStatus()
   或
OrderSupplementService::supplementOrder()
    ↓
order表.buyer_id字段
    ↓
AlipayBlacklistService::addToBlacklist()
    ↓
alipay_blacklist表
```

---

## 四、关键代码位置

### 1. 投诉监控系统

| 文件 | 行数 | 说明 |
|------|------|------|
| `internal/service/alipay_service.go` | 241 | 定义 `ComplainantID` 字段 |
| `internal/service/alipay_service.go` | 280 | 从API响应获取 `ComplainantID` |
| `internal/worker/subject_worker.go` | 302 | 设置投诉记录的 `ComplainantID` |
| `internal/service/blacklist_service.go` | 44-97 | 拉黑方法，接收 `alipayUserID` 参数 |

### 2. PHP业务系统

| 文件 | 行数 | 说明 |
|------|------|------|
| `app/model/Order.php` | 33, 68 | 定义 `buyer_id` 字段 |
| `app/api/controller/v1/PaymentNotifyController.php` | 209-216 | 支付回调时更新 `buyer_id` |
| `app/service/OrderSupplementService.php` | 526-545 | 补单时更新 `buyer_id` |
| `app/service/AlipayBlacklistService.php` | 27 | 拉黑方法，接收 `alipayUserId` 参数 |
| `app/service/alipay/AlipayQueryService.php` | 163 | 订单查询API返回 `buyer_id` |

---

## 五、注意事项

1. **数据库字段**
   - 确保 `order` 表存在 `buyer_id` 字段
   - 字段类型：`varchar(64) DEFAULT NULL`
   - 字段位置：在 `remark` 字段之后

2. **数据完整性**
   - 支付回调时，确保支付宝回调参数包含 `buyer_id`
   - 补单时，确保支付宝订单查询API返回 `buyer_id`
   - 如果 `buyer_id` 为空，不会更新（避免覆盖已有值）

3. **日志记录**
   - 支付回调：记录 `buyer_id` 更新日志（`PaymentNotifyController.php:211`）
   - 补单：记录 `buyer_id` 更新日志（`OrderSupplementService.php:530`）
   - 如果 `buyer_id` 为空，记录警告日志

4. **拉黑时机**
   - 投诉监控系统：投诉发生后立即拉黑
   - PHP业务系统：检测到异常行为时拉黑
   - 两者都需要 `buyer_id` 才能正常工作

---

## 六、排查步骤

如果拉黑时无法获取用户ID，按以下步骤排查：

1. **检查数据库字段**
   ```sql
   SHOW COLUMNS FROM `order` LIKE 'buyer_id';
   ```
   - 如果字段不存在，执行 `add_order_buyer_id_field.sql`

2. **检查订单数据**
   ```sql
   SELECT id, platform_order_no, buyer_id, pay_status, pay_time 
   FROM `order` 
   WHERE id = ?;
   ```
   - 确认 `buyer_id` 是否有值

3. **检查支付回调日志**
   - 查看 `PaymentNotifyController.php` 的日志
   - 确认是否接收到 `buyer_id` 参数
   - 确认是否成功更新

4. **检查补单日志**
   - 查看 `OrderSupplementService.php` 的日志
   - 确认支付宝订单查询API是否返回 `buyer_id`
   - 确认是否成功更新

5. **检查支付宝API响应**
   - 支付回调：检查回调参数中是否有 `buyer_id`
   - 订单查询：检查查询响应中是否有 `buyer_id`
   - 投诉详情：检查投诉详情响应中是否有 `complainant_id`

---

## 七、总结

- **投诉监控系统**：用户ID直接来自支付宝投诉详情API的 `complainant_id` 字段
- **PHP业务系统**：用户ID来自订单表的 `buyer_id` 字段，该字段在支付回调或补单时从支付宝返回的数据中更新
- **关键问题**：确保数据库字段存在，确保更新逻辑正确执行，确保支付宝API返回了 `buyer_id` 字段





# 支付宝黑名单服务使用说明

## 一、数据库准备

### 1. 执行SQL创建表
```bash
mysql -u your_user -p your_database < create_alipay_blacklist_table.sql
```

### 2. 表结构说明
- `alipay_user_id`: 支付宝用户ID（必填）
- `device_code`: 设备码（可选）
- `ip_address`: IP地址（可选）
- `risk_count`: 风险触发次数（自动计数）
- `last_risk_time`: 最后一次触发风险时间
- `remark`: 备注信息

---

## 二、服务类使用

### 1. 基本用法

```php
use app\service\AlipayBlacklistService;

$blacklistService = new AlipayBlacklistService();

// 添加到黑名单
$result = $blacklistService->addToBlacklist(
    '2088xxxxxxxxxxxxxxxx',  // 支付宝用户ID
    'device_fingerprint_xxx', // 设备码
    '192.168.1.100',         // IP地址
    '异常订单行为'            // 备注
);

// 返回示例：
// [
//     'action' => 'insert',  // 或 'update'
//     'id' => 1,
//     'message' => '用户首次命中风险，已新增黑名单记录'
// ]
```

### 2. 检查是否在黑名单

#### 方法A：简单检查（精确匹配）
```php
$isBlacklisted = $blacklistService->isInBlacklist(
    '2088xxxxxxxxxxxxxxxx',
    'device_fingerprint_xxx',
    '192.168.1.100'
);

if ($isBlacklisted) {
    // 用户在黑名单中，拒绝操作
    return $this->error('您的账户存在风险，无法继续操作');
}
```

#### 方法B：综合检查（详细信息）
```php
$checkResult = $blacklistService->checkBlacklist(
    '2088xxxxxxxxxxxxxxxx',
    'device_fingerprint_xxx',
    '192.168.1.100'
);

// 返回示例：
// [
//     'is_blacklisted' => true,        // 综合判断是否在黑名单
//     'user_in_blacklist' => true,     // 用户ID是否在黑名单
//     'device_in_blacklist' => false,  // 设备是否在黑名单
//     'ip_in_blacklist' => false,      // IP是否在黑名单
//     'records' => [...]               // 黑名单记录详情
// ]

if ($checkResult['is_blacklisted']) {
    Log::warning('黑名单用户尝试操作', $checkResult);
    return $this->error('检测到风险行为，操作已被拒绝');
}
```

### 3. 检查设备或IP（规则⑤）
```php
// 检查设备或IP是否在黑名单中（不同用户共用设备/网络）
$isDangerousDevice = $blacklistService->isDeviceOrIpInBlacklist(
    'device_fingerprint_xxx',
    '192.168.1.100'
);

if ($isDangerousDevice) {
    // 该设备或IP曾被其他用户使用且在黑名单中
    Log::warning('检测到高风险设备/IP');
}
```

### 4. 获取用户的所有黑名单记录
```php
$records = $blacklistService->getUserBlacklistRecords('2088xxxxxxxxxxxxxxxx');

foreach ($records as $record) {
    echo "设备: {$record->device_code}, IP: {$record->ip_address}, 次数: {$record->risk_count}\n";
}
```

### 5. 从黑名单中移除
```php
$removed = $blacklistService->removeFromBlacklist(1); // 传入记录ID

if ($removed) {
    echo "已从黑名单中移除";
}
```

---

## 三、实际应用场景

### 场景1：在OAuth回调中添加黑名单

```php
// PaymentPageController.php - oauthCallback 方法中

public function oauthCallback(Request $request): Response
{
    $authCode = $request->get('auth_code', '');
    $state = $request->get('state', '');
    $orderNumber = $state;
    
    // ... 获取订单、主体等信息 ...
    
    // 处理OAuth回调，获取buyer_id
    $buyerId = $this->handleOAuthCallback($authCode, $subject, $product, $orderNumber);
    
    if ($buyerId) {
        // 如果开启设备验证，在设备验证页面会采集设备指纹和IP
        // 这里可以先做一个简单的黑名单检查
        $blacklistService = new \app\service\AlipayBlacklistService();
        
        // 获取当前IP
        $currentIp = $request->getRealIp();
        
        // 检查是否在黑名单
        $checkResult = $blacklistService->checkBlacklist($buyerId, null, $currentIp);
        
        if ($checkResult['is_blacklisted']) {
            Log::warning('黑名单用户尝试支付', [
                'buyer_id' => $buyerId,
                'order_number' => $orderNumber,
                'check_result' => $checkResult
            ]);
            
            return response('检测到风险行为，支付已被拒绝', 403);
        }
        
        // ... 继续正常流程 ...
    }
}
```

### 场景2：在设备验证页面添加黑名单

```php
// 设备验证页面获取到设备指纹后，进行黑名单检查和记录

public function deviceVerification(Request $request): Response
{
    $buyerId = $request->get('buyer_id');
    $deviceFingerprint = $request->get('device_fingerprint');
    $ipAddress = $request->getRealIp();
    $orderNumber = $request->get('order_number');
    
    $blacklistService = new \app\service\AlipayBlacklistService();
    
    // 综合检查
    $checkResult = $blacklistService->checkBlacklist(
        $buyerId,
        $deviceFingerprint,
        $ipAddress
    );
    
    if ($checkResult['is_blacklisted']) {
        // 记录风险行为
        Log::warning('检测到黑名单用户', [
            'buyer_id' => $buyerId,
            'device_fingerprint' => $deviceFingerprint,
            'ip_address' => $ipAddress,
            'order_number' => $orderNumber,
            'check_result' => $checkResult
        ]);
        
        return $this->error('检测到风险行为，支付已被拒绝');
    }
    
    // 继续支付流程...
}
```

### 场景3：风险订单处理时添加到黑名单

```php
// 当检测到风险订单时，将用户加入黑名单

public function handleRiskyOrder($order, $buyerId, $deviceCode, $ipAddress)
{
    $blacklistService = new \app\service\AlipayBlacklistService();
    
    // 添加到黑名单
    $result = $blacklistService->addToBlacklist(
        $buyerId,
        $deviceCode,
        $ipAddress,
        "风险订单: {$order->platform_order_no}, 原因: 异常支付行为"
    );
    
    Log::warning('用户已加入黑名单', [
        'buyer_id' => $buyerId,
        'order_number' => $order->platform_order_no,
        'result' => $result
    ]);
    
    // 标记订单为风险订单
    $order->status = 'risk';
    $order->save();
}
```

---

## 四、逻辑规则说明

### 规则①：用户首次命中风险
- **条件**：黑名单中不存在该 `alipay_user_id`
- **处理**：新增一条黑名单记录

### 规则②：补全不完整记录
- **条件**：黑名单中存在该 `alipay_user_id`，但其 `device_code` 或 `ip_address` 为空
- **处理**：更新该记录，补全信息

### 规则③：同一设备重复触发
- **条件**：黑名单中存在相同 `alipay_user_id`、`device_code` 和 `ip_address`
- **处理**：不新增记录，更新 `risk_count` 和 `updated_at`

### 规则④：用户更换设备/IP
- **条件**：黑名单中存在该 `alipay_user_id`，但本次出现新的 `device_code` 或新的 `ip_address`
- **处理**：新增一条黑名单记录

### 规则⑤：不同用户共用设备
- **条件**：黑名单中不存在该 `alipay_user_id`，但存在相同 `device_code` 或 `ip_address`
- **处理**：可通过 `isDeviceOrIpInBlacklist()` 方法检测

---

## 五、管理后台集成（可选）

可以在管理后台添加黑名单管理功能：

1. 黑名单列表查询
2. 手动添加黑名单
3. 手动移除黑名单
4. 查看用户的所有黑名单记录
5. 黑名单统计分析

---

## 六、注意事项

1. **唯一索引冲突**：由于设置了唯一索引 `(alipay_user_id, device_code, ip_address)`，相同的三元组只能存在一条记录
2. **NULL值处理**：`device_code` 和 `ip_address` 允许为NULL，但NULL值在唯一索引中是特殊处理的
3. **性能优化**：对于高并发场景，建议使用Redis缓存黑名单信息
4. **日志记录**：所有黑名单操作都会记录详细日志，便于追踪和审计
5. **定期清理**：建议定期清理长时间未触发的黑名单记录（可根据业务需求设置）

---

## 七、测试示例

```php
// 测试黑名单功能
$service = new \app\service\AlipayBlacklistService();

// 测试1：首次添加
$result1 = $service->addToBlacklist('2088001', 'device001', '192.168.1.1', '测试用户1');
var_dump($result1); // action: insert

// 测试2：相同信息重复添加
$result2 = $service->addToBlacklist('2088001', 'device001', '192.168.1.1', '测试用户1');
var_dump($result2); // action: update, risk_count增加

// 测试3：相同用户，不同设备
$result3 = $service->addToBlacklist('2088001', 'device002', '192.168.1.2', '测试用户1换设备');
var_dump($result3); // action: insert, 新增记录

// 测试4：检查黑名单
$check = $service->checkBlacklist('2088001', 'device001', '192.168.1.1');
var_dump($check['is_blacklisted']); // true

// 测试5：获取用户所有记录
$records = $service->getUserBlacklistRecords('2088001');
var_dump(count($records)); // 2条记录
```


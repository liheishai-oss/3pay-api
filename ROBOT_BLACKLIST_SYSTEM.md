# 机器人黑名单推送系统

## 📋 系统概述

本系统实现了支付宝黑名单自动监控和Telegram机器人推送通知功能，包括：

1. **黑名单管理系统**：记录和追踪风险用户
2. **机器人推送服务**：支持Telegram等多种推送渠道
3. **定时监控任务**：自动检测并推送黑名单告警
4. **消息模板系统**：灵活的消息格式化

---

## 🗂️ 文件结构

```
third_party_payment_api/
├── app/
│   ├── model/
│   │   └── AlipayBlacklist.php              # 黑名单数据模型
│   ├── service/
│   │   └── AlipayBlacklistService.php       # 黑名单业务逻辑
│   ├── robot/
│   │   ├── RobotPushService.php             # 机器人推送基类
│   │   ├── TelegramRobotPush.php            # Telegram实现
│   │   └── templates/
│   │       ├── BaseTemplate.php             # 模板基类
│   │       └── BlacklistTemplate.php        # 黑名单消息模板
│   └── process/
│       └── BlacklistMonitor.php             # 黑名单监控定时任务
│
├── config/
│   ├── process.php                          # 进程配置
│   └── telegram.php                         # Telegram配置
│
├── create_alipay_blacklist_table.sql        # 数据库迁移
├── test_robot_push.php                      # 推送测试脚本
│
├── ALIPAY_BLACKLIST_USAGE.md               # 黑名单使用文档
├── ROBOT_PUSH_USAGE.md                     # 机器人推送文档
└── ROBOT_BLACKLIST_SYSTEM.md               # 本文档
```

---

## 🚀 快速开始

### 1. 创建数据库表

```bash
cd /Users/apple/dnmp/www/3pay/third_party_payment_api
mysql -u your_user -p your_database < create_alipay_blacklist_table.sql
```

### 2. 配置Telegram机器人

#### 获取Bot Token：
1. 在Telegram中搜索 `@BotFather`
2. 发送 `/newbot` 创建机器人
3. 获得Bot Token

#### 获取Chat ID：
1. 在Telegram中搜索 `@userinfobot`
2. 发送任意消息获得Chat ID

#### 配置环境变量：

编辑 `.env` 文件：
```env
TELEGRAM_BOT_TOKEN=123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11
TELEGRAM_CHAT_ID=123456789
TELEGRAM_ENABLED=true
```

### 3. 测试机器人推送

```bash
php test_robot_push.php
```

### 4. 启动定时监控

```bash
# 启动webman（会自动启动黑名单监控进程）
php start.php start

# 查看进程状态
php start.php status
```

---

## 💡 核心功能

### 1. 黑名单管理

#### 添加到黑名单：

```php
use app\service\AlipayBlacklistService;

$service = new AlipayBlacklistService();

$result = $service->addToBlacklist(
    '2088xxxxxxxxxxxxxxxx',  // 支付宝用户ID
    'device_fingerprint_xxx', // 设备码
    '192.168.1.100',         // IP地址
    '异常订单行为'            // 备注
);
```

#### 检查黑名单：

```php
$checkResult = $service->checkBlacklist(
    '2088xxxxxxxxxxxxxxxx',
    'device_fingerprint_xxx',
    '192.168.1.100'
);

if ($checkResult['is_blacklisted']) {
    // 用户在黑名单中
    return '检测到风险行为';
}
```

### 2. 机器人推送

#### 发送文本消息：

```php
use app\robot\TelegramRobotPush;

$robot = new TelegramRobotPush();
$robot->sendText('这是一条通知消息');
```

#### 使用模板发送：

```php
$robot->sendTemplate('blacklist', [
    'action' => 'insert',
    'alipay_user_id' => '2088xxxxxxxxxxxxxxxx',
    'device_code' => 'device_xxx',
    'ip_address' => '192.168.1.100',
    'risk_count' => 1,
    'remark' => '风险行为描述'
]);
```

### 3. 定时监控

监控进程每5分钟自动检查：
- 检测最近5分钟内的黑名单变动
- 自动推送Telegram通知
- 记录详细日志

---

## 📊 黑名单逻辑规则

| 规则 | 条件 | 处理方式 |
|:--:|:--|:--|
| ① | 用户首次命中风险 | 新增黑名单记录 |
| ② | 用户信息不完整 | 补全设备码和IP |
| ③ | 同一设备重复触发 | 更新风险次数 |
| ④ | 用户更换设备/IP | 新增黑名单记录 |
| ⑤ | 不同用户共用设备 | 标记高风险设备 |

---

## 🔧 实际应用场景

### 场景1：在订单支付时检查黑名单

```php
// PaymentPageController.php

public function payment(Request $request): Response
{
    $orderNumber = $request->get('order_number');
    $buyerId = $request->get('buyer_id');
    $deviceCode = $request->get('device_code');
    $ipAddress = $request->getRealIp();
    
    // 检查黑名单
    $blacklistService = new AlipayBlacklistService();
    $checkResult = $blacklistService->checkBlacklist($buyerId, $deviceCode, $ipAddress);
    
    if ($checkResult['is_blacklisted']) {
        Log::warning('黑名单用户尝试支付', $checkResult);
        
        // 发送告警
        $robot = new TelegramRobotPush();
        $robot->sendTemplate('blacklist', [
            'action' => 'alert',
            'alipay_user_id' => $buyerId,
            'device_code' => $deviceCode,
            'ip_address' => $ipAddress,
            'risk_count' => count($checkResult['records']),
            'order_number' => $orderNumber,
            'message' => '黑名单用户尝试进行支付操作'
        ]);
        
        return $this->error('检测到风险行为，支付已被拒绝');
    }
    
    // 继续支付流程...
}
```

### 场景2：风险订单自动加入黑名单

```php
// OrderController.php

public function handleRiskyOrder($order)
{
    $blacklistService = new AlipayBlacklistService();
    
    // 添加到黑名单
    $result = $blacklistService->addToBlacklist(
        $order->buyer_id,
        $order->device_code,
        $order->client_ip,
        "风险订单: {$order->platform_order_no}, 原因: {$order->risk_reason}"
    );
    
    // 自动推送通知（通过定时任务）
    Log::info('风险订单已加入黑名单', $result);
}
```

### 场景3：后台手动加入黑名单

```php
// Admin Controller

public function addBlacklist(Request $request)
{
    $alipayUserId = $request->post('alipay_user_id');
    $deviceCode = $request->post('device_code');
    $ipAddress = $request->post('ip_address');
    $remark = $request->post('remark');
    
    $blacklistService = new AlipayBlacklistService();
    $result = $blacklistService->addToBlacklist(
        $alipayUserId,
        $deviceCode,
        $ipAddress,
        $remark
    );
    
    // 立即推送通知
    $robot = new TelegramRobotPush();
    $robot->sendTemplate('blacklist', array_merge($result, [
        'alipay_user_id' => $alipayUserId,
        'device_code' => $deviceCode,
        'ip_address' => $ipAddress,
        'remark' => $remark,
        'message' => '管理员手动添加黑名单'
    ]));
    
    return json(['code' => 0, 'msg' => '添加成功', 'data' => $result]);
}
```

---

## 📱 消息效果示例

### 黑名单新增通知

```
🆕 黑名单告警

📋 操作类型：新增黑名单记录
👤 支付宝用户ID：2088001234567890123
📱 设备码：device_fingerprint_abc123...
🌐 IP地址：192.168.1.100
⚡ 风险次数：1
🧾 订单号：BY20251028133748C4CA7515
🆔 黑名单ID：1

💬 备注：
异常订单行为

📝 详情：
检测到新用户加入黑名单

━━━━━━━━━━━━━━━
🕒 2025-10-28 14:30:00
```

### 黑名单更新通知

```
🔄 黑名单告警

📋 操作类型：更新黑名单记录
👤 支付宝用户ID：2088001234567890123
📱 设备码：device_fingerprint_abc123...
🌐 IP地址：192.168.1.100
🔥 风险次数：8
🧾 订单号：BY20251028145623C4CA9876

💬 备注：
频繁异常操作

📝 详情：
黑名单用户再次触发风险行为

━━━━━━━━━━━━━━━
🕒 2025-10-28 15:30:00
```

---

## 🔍 监控和调试

### 查看日志

```bash
# 查看实时日志
tail -f runtime/logs/webman-$(date +%Y-%m-%d).log

# 搜索黑名单相关日志
grep "黑名单" runtime/logs/webman-$(date +%Y-%m-%d).log

# 搜索机器人推送日志
grep "机器人" runtime/logs/webman-$(date +%Y-%m-%d).log
```

### 查看进程状态

```bash
php start.php status

# 输出示例：
# Webman start success
# -----------------------------------------------PROCESS STATUS---------------------------------------------------
# pid     memory  listening                worker_name       connections send_fail timers  total_request qps    status
# 12345   4M      http://0.0.0.0:8787      webman:0          0           0         0       0             0      [OK]
# 12346   2M      none                     blacklist-monitor 0           0         1       0             0      [OK]
```

### 手动触发检查

修改 `app/process/BlacklistMonitor.php`，减小检查间隔：

```php
// 改为每1分钟检查一次（测试用）
Timer::add(60, function() {
    $this->checkRecentBlacklist();
});
```

---

## ⚙️ 高级配置

### 1. 调整监控频率

编辑 `app/process/BlacklistMonitor.php`：

```php
// 每5分钟 = 300秒
Timer::add(300, function() {
    $this->checkRecentBlacklist();
});
```

### 2. 调整检查时间范围

```php
// 检查最近10分钟
$recentRecords = AlipayBlacklist::where('updated_at', '>', date('Y-m-d H:i:s', strtotime('-10 minutes')))
```

### 3. 调整推送数量限制

```php
// 最多推送50条
->limit(50)
```

### 4. 禁用定时监控

编辑 `config/process.php`，注释掉黑名单监控配置：

```php
// 'blacklist-monitor' => [
//     'handler' => app\process\BlacklistMonitor::class,
//     'reloadable' => true,
//     'count' => 1,
// ]
```

---

## 🛡️ 安全建议

1. **保护Bot Token**：不要将Token提交到代码仓库
2. **限制访问**：只允许特定IP访问管理接口
3. **日志审计**：定期检查黑名单操作日志
4. **数据备份**：定期备份黑名单数据
5. **权限控制**：后台操作需要严格的权限验证

---

## 📚 相关文档

- [黑名单功能使用说明](ALIPAY_BLACKLIST_USAGE.md)
- [机器人推送使用说明](ROBOT_PUSH_USAGE.md)

---

## ❓ 常见问题

### Q1: 消息没有收到？
**A:** 检查以下项目：
1. Bot Token和Chat ID是否正确
2. 查看日志文件是否有错误
3. 网络是否能访问Telegram API
4. 运行测试脚本 `php test_robot_push.php`

### Q2: 定时任务不执行？
**A:** 检查以下项目：
1. webman是否正在运行：`php start.php status`
2. 查看进程列表是否有 `blacklist-monitor`
3. 查看日志是否有启动信息

### Q3: 如何扩展其他推送渠道？
**A:** 参考 `TelegramRobotPush.php`，创建新的推送类继承 `RobotPushService`

---

## 🎯 功能特性

✅ 支付宝用户黑名单管理  
✅ 设备码和IP追踪  
✅ 自动化风险监控  
✅ Telegram实时推送  
✅ 灵活的消息模板系统  
✅ 完整的日志记录  
✅ 可扩展的推送渠道  
✅ Webman定时任务集成  

---

## 📝 更新日志

- **v1.0.0** (2025-10-28)
  - ✨ 初始版本发布
  - ✨ 实现黑名单管理系统
  - ✨ 实现Telegram机器人推送
  - ✨ 实现定时监控任务
  - ✨ 实现黑名单消息模板


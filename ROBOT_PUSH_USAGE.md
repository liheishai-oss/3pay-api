# 机器人推送系统使用说明

## 一、系统架构

```
app/robot/
├── RobotPushService.php         # 机器人推送基类
├── TelegramRobotPush.php        # Telegram机器人实现
└── templates/                   # 消息模板目录
    ├── BaseTemplate.php         # 模板基类
    └── BlacklistTemplate.php    # 黑名单消息模板

app/process/
└── BlacklistMonitor.php         # 黑名单监控定时任务

config/
└── process.php                  # 进程配置（已添加黑名单监控）
```

---

## 二、配置Telegram机器人

### 1. 获取Bot Token和Chat ID

#### 获取Bot Token：
1. 在Telegram中找到 `@BotFather`
2. 发送 `/newbot` 创建机器人
3. 按提示设置机器人名称和用户名
4. 获得Bot Token（格式：`123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11`）

#### 获取Chat ID：
1. 在Telegram中找到 `@userinfobot`
2. 发送任意消息
3. 获得你的Chat ID（格式：`123456789`）

### 2. 配置Telegram

编辑 `config/telegram.php`（如果不存在则创建）：

```php
<?php

return [
    // Telegram Bot Token
    'bot_token' => env('TELEGRAM_BOT_TOKEN', 'YOUR_BOT_TOKEN'),
    
    // 默认聊天ID
    'chat_id' => env('TELEGRAM_CHAT_ID', 'YOUR_CHAT_ID'),
];
```

### 3. 在 `.env` 文件中添加配置

```env
TELEGRAM_BOT_TOKEN=123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11
TELEGRAM_CHAT_ID=123456789
```

---

## 三、使用方法

### 1. 直接发送文本消息

```php
use app\robot\TelegramRobotPush;

$robot = new TelegramRobotPush();

// 发送简单文本
$robot->sendText('这是一条测试消息');

// 发送HTML格式消息
$robot->sendHtml('<b>粗体</b> <i>斜体</i> <code>代码</code>');
```

### 2. 使用模板发送消息

```php
use app\robot\TelegramRobotPush;

$robot = new TelegramRobotPush();

// 发送黑名单通知
$robot->sendTemplate('blacklist', [
    'action' => 'insert',
    'alipay_user_id' => '2088xxxxxxxxxxxxxxxx',
    'device_code' => 'device_fingerprint_xxx',
    'ip_address' => '192.168.1.100',
    'risk_count' => 1,
    'remark' => '异常订单行为',
    'order_number' => 'BY20251028xxxxxx',
    'blacklist_id' => 1,
    'message' => '检测到新用户加入黑名单'
]);
```

### 3. 在黑名单服务中集成推送

修改 `app/service/AlipayBlacklistService.php`：

```php
use app\robot\TelegramRobotPush;

public function addToBlacklist(string $alipayUserId, ?string $deviceCode = null, ?string $ipAddress = null, ?string $remark = null): array
{
    try {
        // ... 原有黑名单逻辑 ...
        
        // 处理完成后发送通知
        $robot = new TelegramRobotPush();
        $robot->sendTemplate('blacklist', [
            'action' => $result['action'],
            'alipay_user_id' => $alipayUserId,
            'device_code' => $deviceCode,
            'ip_address' => $ipAddress,
            'risk_count' => $blacklist->risk_count ?? 1,
            'remark' => $remark,
            'blacklist_id' => $result['id'],
            'message' => $result['message']
        ]);
        
        return $result;
        
    } catch (Exception $e) {
        // ... error handling ...
    }
}
```

---

## 四、定时任务说明

### 1. 黑名单监控进程

黑名单监控进程已配置在 `config/process.php` 中：

```php
'blacklist-monitor' => [
    'handler' => app\process\BlacklistMonitor::class,
    'reloadable' => true,
    'count' => 1, // 仅启动1个进程
]
```

### 2. 监控逻辑

- **执行频率**：每5分钟检查一次
- **检查范围**：最近5分钟内新增或更新的黑名单记录
- **推送限制**：每次最多推送20条记录
- **推送间隔**：每条消息间隔1秒，避免频繁推送

### 3. 启动/停止监控

```bash
# 启动webman（会自动启动所有进程包括黑名单监控）
php start.php start

# 重启webman
php start.php restart

# 停止webman
php start.php stop

# 查看进程状态
php start.php status
```

### 4. 调整监控频率

编辑 `app/process/BlacklistMonitor.php`：

```php
// 将300秒（5分钟）改为其他值
Timer::add(300, function() {
    $this->checkRecentBlacklist();
});
```

---

## 五、创建自定义消息模板

### 1. 创建模板类

在 `app/robot/templates/` 目录下创建新模板：

```php
<?php

namespace app\robot\templates;

class OrderAlertTemplate extends BaseTemplate
{
    public function render(array $data): array
    {
        $orderNumber = $data['order_number'] ?? '未知';
        $amount = $data['amount'] ?? 0;
        $status = $data['status'] ?? '未知';
        
        $content = "🔔 <b>订单告警</b>\n\n";
        $content .= "🧾 订单号: <code>{$this->escapeHtml($orderNumber)}</code>\n";
        $content .= "💰 金额: ¥{$amount}\n";
        $content .= "📊 状态: {$this->escapeHtml($status)}\n";
        $content .= "🕒 时间: " . $this->formatTime(null);
        
        return [
            'type' => 'text',
            'content' => $content,
            'options' => [
                'parse_mode' => 'HTML'
            ]
        ];
    }
}
```

### 2. 使用自定义模板

```php
$robot = new TelegramRobotPush();
$robot->sendTemplate('orderAlert', [
    'order_number' => 'BY20251028xxxxxx',
    'amount' => 100.00,
    'status' => '支付成功'
]);
```

---

## 六、消息格式示例

### 黑名单通知消息效果

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

---

## 七、扩展其他机器人

### 1. 创建钉钉机器人

```php
<?php

namespace app\robot;

class DingTalkRobotPush extends RobotPushService
{
    private $webhook;
    private $secret;

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->webhook = $config['webhook'] ?? '';
        $this->secret = $config['secret'] ?? '';
    }

    public function sendText(string $content, array $options = []): bool
    {
        $data = [
            'msgtype' => 'text',
            'text' => [
                'content' => $content
            ]
        ];
        
        $result = $this->httpRequest($this->webhook, $data);
        return $result && isset($result['errcode']) && $result['errcode'] === 0;
    }

    public function sendMarkdown(string $title, string $content, array $options = []): bool
    {
        $data = [
            'msgtype' => 'markdown',
            'markdown' => [
                'title' => $title,
                'text' => $content
            ]
        ];
        
        $result = $this->httpRequest($this->webhook, $data);
        return $result && isset($result['errcode']) && $result['errcode'] === 0;
    }
}
```

### 2. 创建企业微信机器人

```php
<?php

namespace app\robot;

class WeWorkRobotPush extends RobotPushService
{
    private $webhook;

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->webhook = $config['webhook'] ?? '';
    }

    public function sendText(string $content, array $options = []): bool
    {
        $data = [
            'msgtype' => 'text',
            'text' => [
                'content' => $content
            ]
        ];
        
        $result = $this->httpRequest($this->webhook, $data);
        return $result && isset($result['errcode']) && $result['errcode'] === 0;
    }

    public function sendMarkdown(string $title, string $content, array $options = []): bool
    {
        $data = [
            'msgtype' => 'markdown',
            'markdown' => [
                'content' => $content
            ]
        ];
        
        $result = $this->httpRequest($this->webhook, $data);
        return $result && isset($result['errcode']) && $result['errcode'] === 0;
    }
}
```

---

## 八、测试示例

### 1. 测试机器人连接

创建测试文件 `test_robot.php`：

```php
<?php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/support/bootstrap.php';

use app\robot\TelegramRobotPush;

// 测试发送消息
$robot = new TelegramRobotPush();

// 测试1：发送简单文本
$result1 = $robot->sendText('🤖 机器人测试消息');
var_dump('测试1（文本）:', $result1);

// 测试2：发送HTML格式
$result2 = $robot->sendHtml('<b>粗体测试</b> <i>斜体测试</i>');
var_dump('测试2（HTML）:', $result2);

// 测试3：发送黑名单模板
$result3 = $robot->sendTemplate('blacklist', [
    'action' => 'insert',
    'alipay_user_id' => '2088测试用户',
    'device_code' => 'test_device_123',
    'ip_address' => '127.0.0.1',
    'risk_count' => 1,
    'remark' => '测试黑名单通知',
    'message' => '这是一条测试消息'
]);
var_dump('测试3（模板）:', $result3);
```

运行测试：
```bash
php test_robot.php
```

---

## 九、常见问题

### 1. 消息发送失败

**检查清单：**
- Bot Token是否正确
- Chat ID是否正确
- 网络是否能访问Telegram API
- 查看日志文件 `runtime/logs/webman-*.log`

### 2. 定时任务不执行

**检查清单：**
- webman是否正在运行：`php start.php status`
- 检查进程配置：`config/process.php`
- 查看日志是否有报错

### 3. HTML格式不正确

Telegram支持的HTML标签：
- `<b>粗体</b>`
- `<i>斜体</i>`
- `<code>代码</code>`
- `<pre>预格式化文本</pre>`
- `<a href="URL">链接</a>`

---

## 十、安全建议

1. **敏感信息**：不要在消息中发送完整的密钥、密码等敏感信息
2. **Token保护**：将Bot Token存储在`.env`文件中，不要提交到代码仓库
3. **访问控制**：限制哪些用户可以接收通知
4. **频率限制**：避免频繁推送，建议设置最小推送间隔

---

## 十一、性能优化

1. **异步推送**：对于非关键消息，可以使用队列异步推送
2. **批量推送**：多条消息可以合并为一条发送
3. **缓存机制**：避免重复推送相同的消息
4. **失败重试**：对于推送失败的消息，可以实现重试机制


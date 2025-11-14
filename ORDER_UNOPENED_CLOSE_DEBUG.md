# 订单未拉起自动关闭任务 - 调试指南

## 功能说明

该任务会自动关闭超过1小时未拉起的订单（状态为"已创建"）。

## 检查步骤

### 1. 检查进程是否启动

```bash
# 进入项目目录
cd /path/to/3pay-api

# 查看进程状态
php start.php status

# 应该能看到类似这样的输出：
# order-unopened-close   [OK]
```

### 2. 检查日志

```bash
# 查看今天的日志
tail -f runtime/logs/webman-$(date +%Y-%m-%d).log | grep -i "未拉起\|unopened"

# 或者查看所有相关日志
grep -i "未拉起\|unopened" runtime/logs/webman-*.log
```

应该能看到：
- `订单未拉起自动关闭任务进程已启动`
- `开始扫描未拉起订单`
- `找到未拉起订单` 或 `未找到符合条件的未拉起订单`

### 3. 检查数据库

```sql
-- 查看状态为"已创建"且创建时间超过1小时的订单
SELECT 
    id, 
    platform_order_no, 
    merchant_order_no,
    pay_status,
    created_at,
    TIMESTAMPDIFF(HOUR, created_at, NOW()) as hours_ago
FROM `order` 
WHERE pay_status = 0  -- PAY_STATUS_CREATED
  AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
ORDER BY created_at DESC
LIMIT 10;
```

### 4. 手动测试

如果需要立即测试，可以临时修改检查间隔：

编辑 `app/process/OrderUnopenedClose.php`：

```php
// 改为每10秒检查一次（仅用于测试）
Timer::add(10, function () {
    $this->processUnopenedClose();
});
```

然后重启服务：
```bash
php start.php restart
```

### 5. 重启服务

如果进程没有启动，需要重启服务：

```bash
# 停止服务
php start.php stop

# 启动服务
php start.php start

# 或者直接重启
php start.php restart
```

## 常见问题

### 问题1: 进程没有启动

**原因**: 服务没有重启，新进程配置未生效

**解决**: 重启服务
```bash
php start.php restart
```

### 问题2: 没有符合条件的订单

**原因**: 所有"已创建"状态的订单都是1小时内创建的

**解决**: 等待1小时后再次检查，或者修改时间条件进行测试

### 问题3: 订单状态不是0（已创建）

**原因**: 订单可能已经被打开或支付

**解决**: 检查订单的 `pay_status` 字段，确保为 `0`

### 问题4: 日志中没有相关信息

**原因**: 
- 进程没有启动
- 日志级别设置问题

**解决**: 
1. 检查进程状态
2. 查看完整的日志文件
3. 检查日志配置

## 配置说明

- **检查间隔**: 每5分钟（300秒）
- **关闭条件**: 状态为"已创建"（pay_status=0）且创建时间超过1小时
- **进程数量**: 1个进程

## 修改配置

如果需要修改检查间隔或时间条件，编辑 `app/process/OrderUnopenedClose.php`：

```php
// 修改检查间隔（秒）
Timer::add(300, function () {  // 改为你需要的秒数
    $this->processUnopenedClose();
});

// 修改时间条件（小时）
$oneHourAgo = date('Y-m-d H:i:s', strtotime('-1 hour'));  // 改为你需要的时长
```








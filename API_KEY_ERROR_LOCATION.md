# API密钥错误提示位置

## 错误信息
```
"无效的API密钥或商户已被禁用"
```

## 错误返回位置

### 1. 订单创建接口
- **文件**: `app/api/controller/v1/OrderController.php`
- **方法**: `create()`
- **行号**: 第128行（修改后在第127-142行）
- **接口**: `POST /api/v1/merchant/order/create`

### 2. 订单查询接口
- **文件**: `app/api/controller/v1/OrderController.php`
- **方法**: `query()`
- **行号**: 第597行（修改后在第596-611行）
- **接口**: `POST /api/v1/merchant/order/query`

### 3. 订单关闭接口
- **文件**: `app/api/controller/v1/OrderController.php`
- **方法**: `close()`
- **行号**: 第700行（修改后在第699-714行）
- **接口**: `POST /api/v1/merchant/order/close`

### 4. 支付查询接口
- **文件**: `app/api/controller/v1/PaymentQueryController.php`
- **方法**: `queryOrder()`
- **行号**: 第37行（修改后在第36-52行）
- **接口**: `POST /api/v1/merchant/payment/query`

## 错误原因

错误会在以下情况返回：

1. **API密钥不存在**
   - 数据库中找不到对应的 `api_key`
   - 日志会记录：`API密钥不存在（创建订单/查询订单/关闭订单/支付查询）`

2. **商户已被禁用**
   - 商户的 `status` 不等于 `1`（STATUS_ENABLED）
   - 日志会记录：`商户已被禁用（创建订单/查询订单/关闭订单/支付查询）`

## 验证逻辑

```php
// 验证商户
$apiKey = $params['api_key'] ?? '';
$merchant = Merchant::where('api_key', $apiKey)->first();

if (!$merchant) {
    // API密钥不存在
    Log::warning('API密钥不存在', [...]);
    return $this->error('无效的API密钥或商户已被禁用');
}

if ($merchant->status != Merchant::STATUS_ENABLED) {
    // 商户已被禁用
    Log::warning('商户已被禁用', [...]);
    return $this->error('无效的API密钥或商户已被禁用');
}
```

## 调试方法

1. **查看日志**
   ```bash
   tail -f runtime/logs/*.log | grep -i "API密钥\|商户已被禁用"
   ```

2. **检查数据库**
   ```sql
   SELECT id, merchant_name, api_key, status FROM merchant WHERE api_key = '你的API密钥';
   ```

3. **检查商户状态**
   - `status = 1` 表示启用
   - `status = 0` 表示禁用

## 修改说明

已添加详细的日志记录，现在会区分：
- API密钥不存在的情况
- 商户已被禁用的情况

这样可以帮助快速定位问题。











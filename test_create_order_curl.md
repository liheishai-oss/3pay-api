# 创建订单 curl 测试指南

## 方法一：使用 PHP 脚本（推荐）

```bash
# 1. 编辑配置文件
php test_create_order.php

# 2. 修改脚本中的配置参数：
#    - api_key: 你的API密钥
#    - api_secret: 你的API密钥
#    - api_url: API地址
#    - product_code: 产品代码（如 9469）

# 3. 运行脚本
php test_create_order.php
```

## 方法二：使用 Shell 脚本

```bash
# 1. 编辑配置文件
vim test_create_order.sh

# 2. 修改脚本中的配置参数
# 3. 运行脚本
./test_create_order.sh
```

## 方法三：手动 curl 命令

### 步骤1：准备参数

```bash
API_KEY="your_api_key"
API_SECRET="your_api_secret"
API_URL="http://127.0.0.1:8787/api/v1/merchant/order/create"
PRODUCT_CODE="9469"
AMOUNT="1.00"
MERCHANT_ORDER_NO="TEST$(date +%s)"
SUBJECT="测试订单"
```

### 步骤2：计算签名

使用 PHP 计算签名（推荐）：

```bash
php -r "
\$params = [
    'api_key' => '$API_KEY',
    'merchant_order_no' => '$MERCHANT_ORDER_NO',
    'product_code' => '$PRODUCT_CODE',
    'amount' => '$AMOUNT',
    'subject' => '$SUBJECT',
];
unset(\$params['sign']);
\$params = array_filter(\$params);
ksort(\$params);
\$signString = '';
foreach (\$params as \$k => \$v) {
    \$signString .= \$k . '=' . \$v . '&';
}
\$signString .= 'key=' . '$API_SECRET';
echo strtoupper(md5(\$signString));
"
```

### 步骤3：发送 curl 请求

```bash
SIGN="上面计算出的签名值"

curl -X POST "$API_URL" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "api_key=$API_KEY" \
  -d "merchant_order_no=$MERCHANT_ORDER_NO" \
  -d "product_code=$PRODUCT_CODE" \
  -d "amount=$AMOUNT" \
  -d "subject=$SUBJECT" \
  -d "sign=$SIGN"
```

## 完整的 curl 命令示例

假设你已经计算好签名 `SIGN_VALUE`：

```bash
curl -X POST "http://127.0.0.1:8787/api/v1/merchant/order/create" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "api_key=YOUR_API_KEY" \
  -d "merchant_order_no=TEST20250122123456" \
  -d "product_code=9469" \
  -d "amount=1.00" \
  -d "subject=测试订单" \
  -d "sign=SIGN_VALUE"
```

## 签名计算说明

签名规则：
1. 移除 `sign` 参数
2. 移除空值参数
3. 按键名升序排序
4. 拼接成 `key=value&key2=value2&...` 格式
5. 最后加上 `&key=API_SECRET`
6. 计算 MD5 并转为大写

示例：
```
参数：
- api_key: test123
- merchant_order_no: TEST001
- product_code: 9469
- amount: 1.00
- subject: 测试

签名字符串：
amount=1.00&api_key=test123&merchant_order_no=TEST001&product_code=9469&subject=测试&key=your_secret_key

签名（MD5并转大写）：
SIGN_VALUE
```

## 常见错误

1. **签名验证失败**
   - 检查 API_SECRET 是否正确
   - 检查参数排序是否正确
   - 检查参数值是否包含特殊字符需要URL编码

2. **暂无可用支付主体**
   - 检查产品是否已绑定到主体
   - 检查支付类型是否已绑定到主体
   - 查看错误日志中的诊断信息

3. **产品不存在或已禁用**
   - 检查产品代码是否正确
   - 检查产品状态是否为启用

## 响应示例

成功响应：
```json
{
    "code": 0,
    "msg": "订单创建成功",
    "data": {
        "platform_order_no": "BY20250122153045A1B21234",
        "merchant_order_no": "TEST20250122123456",
        "amount": "1.00",
        "expire_time": "2025-01-22 16:00:45",
        "notify_url": "https://your-domain.com/notify",
        "return_url": "https://your-domain.com/return",
        "payment_url": "https://pay.example.com/payment?order_no=...",
        "qr_code": "...",
        "payment_info": {...}
    }
}
```

失败响应：
```json
{
    "code": 1,
    "msg": "错误信息",
    "data": null
}
```









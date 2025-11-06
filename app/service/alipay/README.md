# 支付宝企业级支付服务

这是一个企业级的支付宝支付服务封装，提供了完整的支付宝支付功能，包括各种支付方式、订单查询、退款、OAuth授权等功能。

## 功能特性

- ✅ **多种支付方式支持**
  - WAP支付（手机网页支付）
  - APP支付（移动应用支付）
  - PC网站支付
  - 扫码支付
  - 条码支付（当面付）
  - 预授权支付

- ✅ **完整的订单管理**
  - 订单查询
  - 订单关闭
  - 订单撤销

- ✅ **退款功能**
  - 单笔退款
  - 批量退款
  - 退款查询
  - 预授权完成/撤销

- ✅ **OAuth授权**
  - 获取授权URL
  - 通过授权码获取用户信息
  - 令牌刷新
  - 用户信息获取

- ✅ **通知处理**
  - 支付通知处理
  - 退款通知处理
  - 签名验证
  - 防重复处理

- ✅ **企业级特性**
  - 完善的错误处理
  - 详细的日志记录
  - 配置验证
  - 缓存管理
  - 安全防护

## 目录结构

```
app/service/alipay/
├── AlipayConfig.php          # 配置管理类
├── AlipayPaymentService.php  # 支付服务类
├── AlipayNotifyService.php   # 通知处理服务类
├── AlipayQueryService.php    # 查询服务类
├── AlipayRefundService.php   # 退款服务类
├── AlipayOAuthService.php    # OAuth服务类
├── AlipayService.php         # 主服务类（统一入口）
├── AlipayConstants.php       # 常量定义
├── AlipayException.php       # 异常处理类
├── AlipayUtils.php           # 工具类
└── AlipayServiceExample.php  # 使用示例
```

## 快速开始

### 1. 配置信息

首先准备支付宝的配置信息：

```php
$paymentInfo = [
    'appid' => 'your_app_id',                    // 应用ID
    'AppPrivateKey' => 'your_private_key',        // 应用私钥
    'alipayCertPublicKey' => 'path/to/alipay_cert_public_key.crt',  // 支付宝公钥证书
    'alipayRootCert' => 'path/to/alipay_root_cert.crt',            // 支付宝根证书
    'appCertPublicKey' => 'path/to/app_cert_public_key.crt',       // 应用公钥证书
    'notify_url' => 'https://your-domain.com/alipay/notify',       // 异步通知地址
    'sandbox' => false,                          // 是否沙箱环境
];
```

### 2. 基本使用

```php
use app\service\alipay\AlipayService;

$alipayService = new AlipayService();

// WAP支付
$orderInfo = [
    'payment_order_number' => 'ORDER_' . time(),
    'product_title' => '测试商品',
    'payment_amount' => '0.01',
    'order_expiry_time' => date('Y-m-d H:i:s', time() + 1800),
    'pid' => 'your_pid',
];

$paymentUrl = $alipayService->wapPay($orderInfo, $paymentInfo);
```

### 3. 支付方式

#### WAP支付
```php
$paymentUrl = $alipayService->wapPay($orderInfo, $paymentInfo);
```

#### APP支付
```php
$paymentParams = $alipayService->appPay($orderInfo, $paymentInfo);
```

#### PC网站支付
```php
$paymentForm = $alipayService->pagePay($orderInfo, $paymentInfo);
```

#### 扫码支付
```php
$qrCode = $alipayService->qrPay($orderInfo, $paymentInfo);
```

#### 条码支付
```php
$authCode = 'user_auth_code';
$result = $alipayService->barPay($orderInfo, $authCode, $paymentInfo);
```

### 4. 订单查询

```php
$orderInfo = $alipayService->queryOrder($orderNumber, $paymentInfo);
```

### 5. 退款处理

```php
$refundInfo = [
    'order_number' => 'ORDER_123456789',
    'refund_number' => 'REFUND_' . time(),
    'refund_amount' => '0.01',
    'refund_reason' => '用户申请退款',
];

$refundResult = $alipayService->createRefund($refundInfo, $paymentInfo);
```

### 6. OAuth授权

```php
// 获取授权URL
$authParams = [
    'redirect_uri' => 'https://your-domain.com/alipay/callback',
    'scope' => 'auth_user',
    'state' => 'test_state',
];
$authUrl = $alipayService->getAuthUrl($authParams, $paymentInfo);

// 通过授权码获取用户信息
$tokenInfo = $alipayService->getTokenByAuthCode($authCode, $paymentInfo);
$userInfo = $alipayService->getUserInfo($tokenInfo['access_token'], $paymentInfo);
```

### 7. 通知处理

```php
// 处理支付通知
$result = $alipayService->handlePaymentNotify($notifyParams, $paymentInfo);

// 处理退款通知
$result = $alipayService->handleRefundNotify($notifyParams, $paymentInfo);
```

## 错误处理

服务使用自定义异常类 `AlipayException` 来处理各种错误：

```php
use app\service\alipay\AlipayException;

try {
    $result = $alipayService->wapPay($orderInfo, $paymentInfo);
} catch (AlipayException $e) {
    echo "错误代码: " . $e->getErrorCode();
    echo "错误消息: " . $e->getMessage();
    echo "错误详情: " . json_encode($e->getErrorDetails());
}
```

## 常量定义

服务提供了丰富的常量定义，位于 `AlipayConstants` 类中：

```php
use app\service\alipay\AlipayConstants;

// 支付方式
AlipayConstants::PAYMENT_METHOD_WAP
AlipayConstants::PAYMENT_METHOD_APP
AlipayConstants::PAYMENT_METHOD_PAGE

// 交易状态
AlipayConstants::TRADE_STATUS_TRADE_SUCCESS
AlipayConstants::TRADE_STATUS_TRADE_FINISHED

// OAuth授权范围
AlipayConstants::OAUTH_SCOPE_AUTH_USER
AlipayConstants::OAUTH_SCOPE_AUTH_BASE
```

## 工具类

`AlipayUtils` 类提供了各种实用工具方法：

```php
use app\service\alipay\AlipayUtils;

// 格式化金额
$amount = AlipayUtils::formatAmount(100); // "1.00"

// 验证订单号
$isValid = AlipayUtils::validateOrderNumber('ORDER_123456');

// 生成随机字符串
$randomString = AlipayUtils::generateRandomString(16);

// 记录操作日志
AlipayUtils::logOperation('payment_create', $orderInfo);
```

## 安全特性

1. **签名验证**: 所有通知都会进行签名验证
2. **防重复处理**: 使用Redis缓存防止重复处理通知
3. **敏感信息脱敏**: 日志记录时自动脱敏敏感信息
4. **配置验证**: 启动时验证配置完整性
5. **证书管理**: 支持证书文件路径验证

## 日志记录

服务会自动记录详细的操作日志，包括：

- 支付创建日志
- 通知处理日志
- 查询操作日志
- 退款操作日志
- 错误异常日志

日志级别包括：info、warning、error、debug

## 缓存管理

服务使用Redis进行缓存管理：

- 通知防重复处理缓存（5分钟）
- OAuth令牌缓存（1小时）
- 购买限制缓存（24小时）

## 环境支持

- 支持生产环境和沙箱环境
- 自动环境检测和配置切换
- 环境标识记录

## 依赖要求

- PHP >= 8.1
- alipaysdk/easysdk >= 2.2
- webman/redis >= 2.1
- webman/log >= 2.1

## 注意事项

1. 确保证书文件路径正确且文件存在
2. 配置信息中的私钥和证书要匹配
3. 通知地址必须是HTTPS且可访问
4. 订单号要保证唯一性
5. 金额格式要正确（最多2位小数）
6. 生产环境使用前请充分测试

## 示例代码

完整的使用示例请参考 `AlipayServiceExample.php` 文件，其中包含了所有功能的详细使用示例。

## 技术支持

如有问题，请查看日志文件或联系技术支持团队。

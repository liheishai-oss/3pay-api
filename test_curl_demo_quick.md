# 使用Demo配置快速测试

## Demo配置信息

以下配置来自 `DemoGeneratorController`：

```
API Key: 05a28411d8a2a1c689971996b966d44f
API Secret: 7cbcbf5cd3a784496c4f260f86153c9500682dfd0c2927808e1418a8af6b8471
Base URL: http://127.0.0.1:8787
```

## 方法一：使用PHP脚本（推荐）

```bash
cd /Users/apple/dnmp/www/3pay/3pay-api
php test_create_order_demo.php
```

## 方法二：使用Shell脚本

```bash
cd /Users/apple/dnmp/www/3pay/3pay-api
./test_curl_demo.sh
```

## 方法三：直接curl命令

### 1. 准备参数

```bash
API_KEY="05a28411d8a2a1c689971996b966d44f"
API_SECRET="7cbcbf5cd3a784496c4f260f86153c9500682dfd0c2927808e1418a8af6b8471"
API_URL="http://127.0.0.1:8787/api/v1/merchant/order/create"
MERCHANT_ORDER_NO="TEST$(date +%s)"
PRODUCT_CODE="9469"
AMOUNT="1.00"
SUBJECT="测试订单"
```

### 2. 计算签名

```bash
SIGN=$(php -r "
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
echo md5(\$signString);
")
```

### 3. 发送请求

```bash
curl -X POST "$API_URL" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "api_key=$API_KEY" \
  -d "merchant_order_no=$MERCHANT_ORDER_NO" \
  -d "product_code=$PRODUCT_CODE" \
  -d "amount=$AMOUNT" \
  -d "subject=$SUBJECT" \
  -d "sign=$SIGN"
```

## 完整的一行命令

```bash
curl -X POST "http://127.0.0.1:8787/api/v1/merchant/order/create" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "api_key=05a28411d8a2a1c689971996b966d44f" \
  -d "merchant_order_no=TEST$(date +%s)" \
  -d "product_code=9469" \
  -d "amount=1.00" \
  -d "subject=测试订单" \
  -d "sign=$(php -r "\$p=['api_key'=>'05a28411d8a2a1c689971996b966d44f','merchant_order_no'=>'TEST' . time(),'product_code'=>'9469','amount'=>'1.00','subject'=>'测试订单'];unset(\$p['sign']);\$p=array_filter(\$p);ksort(\$p);\$s='';foreach(\$p as \$k=>\$v)\$s.=\$k.'='.\$v.'&';\$s.='key=7cbcbf5cd3a784496c4f260f86153c9500682dfd0c2927808e1418a8af6b8471';echo md5(\$s);")"
```

## 注意事项

1. **确保服务运行**：确保 `http://127.0.0.1:8787` 服务正在运行
2. **产品绑定**：确保产品代码 `9469` 已绑定到主体
3. **支付类型绑定**：确保对应的支付类型已绑定到主体
4. **签名计算**：签名使用MD5，注意大小写（PaymentDemo使用小写）

## 测试Web界面

你也可以直接访问Web界面进行测试：

```
http://127.0.0.1:8787/demo
```

这个页面已经配置好了API密钥，可以直接使用。




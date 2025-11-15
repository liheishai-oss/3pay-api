#!/bin/bash

# 使用Demo路由配置的curl测试脚本
# 使用方法：./test_curl_demo.sh

# ==================== Demo路由配置 ====================
API_KEY="05a28411d8a2a1c689971996b966d44f"
API_SECRET="7cbcbf5cd3a784496c4f260f86153c9500682dfd0c2927808e1418a8af6b8471"
API_URL="http://127.0.0.1:8787/api/v1/merchant/order/create"
BASE_URL="http://127.0.0.1:8787"

# ==================== 请求参数 ====================
MERCHANT_ORDER_NO="TEST$(date +%Y%m%d%H%M%S)$(date +%N | cut -b1-3)"
PRODUCT_CODE="9469"
AMOUNT="1.00"
SUBJECT="测试订单-$(date +%Y-%m-%d\ %H:%M:%S)"

# ==================== 计算签名（使用PHP） ====================
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

# ==================== 显示请求信息 ====================
echo "========== Demo配置信息 =========="
echo "API Key: ${API_KEY:0:20}..."
echo "API URL: $API_URL"
echo ""
echo "========== 请求参数 =========="
echo "api_key: $API_KEY"
echo "merchant_order_no: $MERCHANT_ORDER_NO"
echo "product_code: $PRODUCT_CODE"
echo "amount: $AMOUNT"
echo "subject: $SUBJECT"
echo "sign: $SIGN"
echo ""

# ==================== 发送curl请求 ====================
echo "========== 发送请求 =========="
echo "URL: $API_URL"
echo ""

RESPONSE=$(curl -s -w "\nHTTP_CODE:%{http_code}" -X POST "$API_URL" \
    -H "Content-Type: application/x-www-form-urlencoded" \
    -d "api_key=$API_KEY" \
    -d "merchant_order_no=$MERCHANT_ORDER_NO" \
    -d "product_code=$PRODUCT_CODE" \
    -d "amount=$AMOUNT" \
    -d "subject=$SUBJECT" \
    -d "sign=$SIGN")

# 提取HTTP状态码和响应体
HTTP_CODE=$(echo "$RESPONSE" | grep "HTTP_CODE" | cut -d: -f2)
BODY=$(echo "$RESPONSE" | sed '/HTTP_CODE/d')

# ==================== 显示响应结果 ====================
echo "========== 响应结果 =========="
echo "HTTP Code: $HTTP_CODE"
echo ""

# 尝试格式化JSON输出
if command -v python3 &> /dev/null; then
    echo "$BODY" | python3 -m json.tool 2>/dev/null || echo "$BODY"
else
    echo "$BODY"
fi

echo ""

# 检查响应
if echo "$BODY" | grep -q '"code":0'; then
    echo "✓ 订单创建成功！"
    
    # 提取支付链接
    PAYMENT_URL=$(echo "$BODY" | grep -o '"payment_url":"[^"]*' | cut -d'"' -f4)
    if [ ! -z "$PAYMENT_URL" ]; then
        # 如果支付链接不是完整URL，补充完整
        if [[ ! "$PAYMENT_URL" =~ ^https?:// ]]; then
            PAYMENT_URL="${BASE_URL}/${PAYMENT_URL#/}"
        fi
        echo "支付链接: $PAYMENT_URL"
    fi
    
    # 提取平台订单号
    PLATFORM_ORDER_NO=$(echo "$BODY" | grep -o '"platform_order_no":"[^"]*' | cut -d'"' -f4)
    if [ ! -z "$PLATFORM_ORDER_NO" ]; then
        echo "平台订单号: $PLATFORM_ORDER_NO"
    fi
else
    echo "✗ 订单创建失败"
    ERROR_MSG=$(echo "$BODY" | grep -o '"msg":"[^"]*' | cut -d'"' -f4)
    if [ ! -z "$ERROR_MSG" ]; then
        echo "错误信息: $ERROR_MSG"
    fi
fi












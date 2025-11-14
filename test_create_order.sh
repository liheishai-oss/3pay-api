#!/bin/bash

# 创建订单测试脚本
# 使用方法：./test_create_order.sh

# ==================== 配置参数 ====================
# 修改以下参数为你的实际值
API_KEY="your_api_key_here"
API_SECRET="your_api_secret_here"
API_URL="http://127.0.0.1:8787/api/v1/merchant/order/create"
PRODUCT_CODE="9469"
AMOUNT="1.00"
MERCHANT_ORDER_NO="TEST$(date +%s)"

# ==================== 签名计算函数 ====================
calculate_sign() {
    local params="$1"
    local secret="$2"
    
    # 移除sign参数，按键名排序，拼接成 key=value& 格式
    local sign_string=$(echo "$params" | sed 's/&sign=[^&]*//' | tr '&' '\n' | sort | tr '\n' '&' | sed 's/&$//')
    
    # 最后加上 &key=secret
    sign_string="${sign_string}&key=${secret}"
    
    # 计算MD5并转大写
    echo -n "$sign_string" | md5sum | awk '{print toupper($1)}'
}

# ==================== 构建请求参数 ====================
params="api_key=${API_KEY}&merchant_order_no=${MERCHANT_ORDER_NO}&product_code=${PRODUCT_CODE}&amount=${AMOUNT}"
params="${params}&subject=测试订单-$(date +%Y%m%d%H%M%S)"

# 计算签名
sign=$(calculate_sign "$params" "$API_SECRET")
params="${params}&sign=${sign}"

# ==================== 发送请求 ====================
echo "========== 请求参数 =========="
echo "$params"
echo ""
echo "========== 签名 =========="
echo "$sign"
echo ""
echo "========== 发送请求 =========="
echo "URL: $API_URL"
echo ""

response=$(curl -s -X POST "$API_URL" \
    -H "Content-Type: application/x-www-form-urlencoded" \
    -d "$params")

echo "========== 响应结果 =========="
echo "$response" | python3 -m json.tool 2>/dev/null || echo "$response"
echo ""

# 检查响应
if echo "$response" | grep -q '"code":0'; then
    echo "✓ 订单创建成功！"
    payment_url=$(echo "$response" | grep -o '"payment_url":"[^"]*' | cut -d'"' -f4)
    if [ ! -z "$payment_url" ]; then
        echo "支付链接: $payment_url"
    fi
else
    echo "✗ 订单创建失败"
    error_msg=$(echo "$response" | grep -o '"msg":"[^"]*' | cut -d'"' -f4)
    if [ ! -z "$error_msg" ]; then
        echo "错误信息: $error_msg"
    fi
fi






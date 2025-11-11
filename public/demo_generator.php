<?php
/**
 * 支付Demo生成器 - Web版
 * 基于 demo/php/payment_demo.php 的Web界面
 */

// 引入PaymentDemo类
require_once __DIR__ . '/../../demo/php/payment_demo.php';

// 配置API密钥
$apiKey = 'f227cf12fc2450fb8d6ced8c49d7f0d2';
$apiSecret = 'c8fe2a77ff57f5d9ef9cb615b6d55fb1';
$baseUrl = 'http://127.0.0.1:8787';

// 处理表单提交
$result = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $demo = new PaymentDemo($apiKey, $apiSecret, $baseUrl);
        
        if ($_POST['action'] === 'create_order') {
            // 创建订单
            $params = [
                'merchant_order_no' => $_POST['merchant_order_no'] ?? 'M' . time(),
                'product_code' => $_POST['product_code'] ?? '9469',
                'amount' => $_POST['amount'] ?? '1.00',
                'subject' => $_POST['subject'] ?? '测试商品',
            ];
            
            if (!empty($_POST['notify_url'])) {
                $params['notify_url'] = $_POST['notify_url'];
            }
            if (!empty($_POST['return_url'])) {
                $params['return_url'] = $_POST['return_url'];
            }
            if (!empty($_POST['auth_code'])) {
                $params['auth_code'] = $_POST['auth_code'];
            }
            
            $response = $demo->createOrder($params);
            
            if ($response['success']) {
                $result = $response['data'];
                
                // 如果支付链接不是完整URL，补充完整
                if (isset($result['data']['payment_url'])) {
                    $paymentUrl = $result['data']['payment_url'];
                    if (!preg_match('/^https?:\/\//', $paymentUrl)) {
                        $result['data']['payment_url_full'] = $baseUrl . '/' . ltrim($paymentUrl, '/');
                    } else {
                        $result['data']['payment_url_full'] = $paymentUrl;
                    }
                }
            } else {
                $error = $response['error'] ?? '请求失败';
            }
            
        } elseif ($_POST['action'] === 'query_order') {
            // 查询订单
            $merchantOrderNo = $_POST['query_order_no'] ?? '';
            if (empty($merchantOrderNo)) {
                $error = '请输入订单号';
            } else {
                $response = $demo->queryOrder($merchantOrderNo);
                if ($response['success']) {
                    $result = $response['data'];
                } else {
                    $error = $response['error'] ?? '查询失败';
                }
            }
            
        } elseif ($_POST['action'] === 'close_order') {
            // 关闭订单
            $merchantOrderNo = $_POST['close_order_no'] ?? '';
            if (empty($merchantOrderNo)) {
                $error = '请输入订单号';
            } else {
                $response = $demo->closeOrder($merchantOrderNo);
                if ($response['success']) {
                    $result = $response['data'];
                } else {
                    $error = $response['error'] ?? '关闭失败';
                }
            }
        }
    } catch (Exception $e) {
        $error = '系统异常: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>支付Demo生成器</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei', 'Helvetica Neue', Helvetica, Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            text-align: center;
            color: white;
            margin-bottom: 30px;
        }
        
        .header h1 {
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .header p {
            font-size: 16px;
            opacity: 0.9;
        }
        
        .content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .content {
                grid-template-columns: 1fr;
            }
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            padding: 25px;
        }
        
        .card-title {
            font-size: 20px;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #1677ff;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            font-size: 14px;
            color: #666;
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #1677ff;
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 60px;
        }
        
        .form-group small {
            display: block;
            color: #999;
            font-size: 12px;
            margin-top: 3px;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #1677ff;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
        }
        
        .btn:hover {
            background: #4096ff;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(22, 119, 255, 0.4);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .btn-success {
            background: #52c41a;
        }
        
        .btn-success:hover {
            background: #73d13d;
        }
        
        .btn-danger {
            background: #ff4d4f;
        }
        
        .btn-danger:hover {
            background: #ff7875;
        }
        
        .btn-block {
            width: 100%;
            text-align: center;
        }
        
        .result-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            padding: 25px;
            margin-bottom: 20px;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #f6ffed;
            border: 1px solid #b7eb8f;
            color: #52c41a;
        }
        
        .alert-error {
            background: #fff2f0;
            border: 1px solid #ffccc7;
            color: #ff4d4f;
        }
        
        .result-item {
            background: #f5f5f5;
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 10px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            word-break: break-all;
        }
        
        .result-item strong {
            color: #333;
            display: inline-block;
            min-width: 120px;
        }
        
        .payment-link {
            display: inline-block;
            background: #1677ff;
            color: white;
            padding: 12px 24px;
            border-radius: 6px;
            text-decoration: none;
            margin-top: 10px;
            margin-right: 10px;
            transition: all 0.3s;
        }
        
        .payment-link:hover {
            background: #4096ff;
            transform: translateY(-2px);
        }
        
        .json-viewer {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 15px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            overflow-x: auto;
            max-height: 400px;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-top: 20px;
        }
        
        @media (max-width: 768px) {
            .quick-actions {
                grid-template-columns: 1fr;
            }
        }
        
        .config-info {
            background: #e6f7ff;
            border: 1px solid #91d5ff;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 13px;
        }
        
        .config-info strong {
            color: #0050b3;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 支付Demo生成器</h1>
            <p>快速创建测试订单并生成支付链接</p>
        </div>
        
        <?php if ($result): ?>
        <div class="result-card">
            <div class="card-title">✅ 操作成功</div>
            
            <?php if (isset($result['code']) && $result['code'] === 0): ?>
                <div class="alert alert-success">
                    订单创建成功！
                </div>
                
                <?php if (isset($result['data'])): ?>
                    <div class="result-item">
                        <strong>订单号:</strong> <?= htmlspecialchars($result['data']['order_number'] ?? 'N/A') ?>
                    </div>
                    
                    <?php if (isset($result['data']['payment_url_full'])): ?>
                        <div class="result-item">
                            <strong>支付地址:</strong> <?= htmlspecialchars($result['data']['payment_url_full']) ?>
                        </div>
                        
                        <a href="<?= htmlspecialchars($result['data']['payment_url_full']) ?>" 
                           class="payment-link" 
                           target="_blank">
                            🔗 打开支付页面
                        </a>
                        
                        <button class="payment-link" onclick="copyToClipboard('<?= htmlspecialchars($result['data']['payment_url_full']) ?>')">
                            📋 复制支付链接
                        </button>
                    <?php endif; ?>
                    
                    <?php if (isset($result['data']['notify_url'])): ?>
                        <div class="result-item">
                            <strong>异步通知地址:</strong> <?= htmlspecialchars($result['data']['notify_url']) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($result['data']['payment_method'])): ?>
                        <div class="result-item">
                            <strong>支付方式:</strong> <?= htmlspecialchars($result['data']['payment_method']) ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
            <?php else: ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($result['msg'] ?? '操作失败') ?>
                </div>
            <?php endif; ?>
            
            <details style="margin-top: 20px;">
                <summary style="cursor: pointer; color: #1677ff; font-weight: 500;">查看完整响应</summary>
                <div class="json-viewer">
                    <?= htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>
                </div>
            </details>
        </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="result-card">
            <div class="alert alert-error">
                ❌ 错误: <?= htmlspecialchars($error) ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="config-info">
            <strong>当前配置:</strong> 
            API Key: <?= substr($apiKey, 0, 8) ?>...*** | 
            Base URL: <?= htmlspecialchars($baseUrl) ?>
        </div>
        
        <div class="content">
            <!-- 创建订单 -->
            <div class="card">
                <div class="card-title">📝 创建订单</div>
                
                <form method="POST">
                    <input type="hidden" name="action" value="create_order">
                    
                    <div class="form-group">
                        <label>商户订单号</label>
                        <input type="text" name="merchant_order_no" value="M<?= time() ?>" required>
                        <small>唯一的商户订单号，建议使用时间戳</small>
                    </div>
                    
                    <div class="form-group">
                        <label>产品代码</label>
                        <select name="product_code" required>
                            <option value="9469">9469 - 支付宝WAP支付</option>
                            <option value="9470">9470 - 支付宝扫码支付</option>
                            <option value="9471">9471 - 支付宝条码支付</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>支付金额 (元)</label>
                        <input type="text" name="amount" value="1.00" required>
                        <small>最低支付金额1元，建议使用1.00元进行测试</small>
                    </div>
                    
                    <div class="form-group">
                        <label>订单标题</label>
                        <input type="text" name="subject" value="测试商品-<?= date('His') ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>异步通知地址 (可选)</label>
                        <input type="url" name="notify_url" value="<?= htmlspecialchars($baseUrl . '/demo/merchant/notify') ?>" placeholder="https://your-domain.com/notify">
                        <small>默认使用内置模拟商户回调地址：/demo/merchant/notify</small>
                    </div>
                    
                    <div class="form-group">
                        <label>同步返回地址 (可选)</label>
                        <input type="url" name="return_url" placeholder="https://your-domain.com/return">
                    </div>
                    
                    <div class="form-group">
                        <label>付款码 (条码支付时必填)</label>
                        <input type="text" name="auth_code" placeholder="扫描用户付款码获得">
                        <small>仅条码支付需要</small>
                    </div>
                    
                    <button type="submit" class="btn btn-block">🚀 创建订单并生成支付链接</button>
                </form>
                
                <div class="quick-actions">
                    <button class="btn btn-success" onclick="fillQuickTest('1.00')">快速测试 ¥1.00</button>
                    <button class="btn btn-success" onclick="fillQuickTest('2.00')">快速测试 ¥2.00</button>
                    <button class="btn btn-success" onclick="fillQuickTest('5.00')">快速测试 ¥5.00</button>
                </div>
            </div>
            
            <!-- 查询和关闭订单 -->
            <div class="card">
                <div class="card-title">🔍 查询订单</div>
                
                <form method="POST">
                    <input type="hidden" name="action" value="query_order">
                    
                    <div class="form-group">
                        <label>商户订单号</label>
                        <input type="text" name="query_order_no" placeholder="输入要查询的订单号" required>
                    </div>
                    
                    <button type="submit" class="btn btn-block">🔍 查询订单状态</button>
                </form>
                
                <hr style="margin: 25px 0; border: none; border-top: 1px solid #e0e0e0;">
                
                <div class="card-title" style="margin-top: 25px;">🚫 关闭订单</div>
                
                <form method="POST">
                    <input type="hidden" name="action" value="close_order">
                    
                    <div class="form-group">
                        <label>商户订单号</label>
                        <input type="text" name="close_order_no" placeholder="输入要关闭的订单号" required>
                    </div>
                    
                    <button type="submit" class="btn btn-danger btn-block">🚫 关闭订单</button>
                </form>
                
                <hr style="margin: 25px 0; border: none; border-top: 1px solid #e0e0e0;">
                
                <div class="card-title" style="margin-top: 25px;">📚 使用说明</div>
                
                <div style="font-size: 13px; line-height: 1.8; color: #666;">
                    <p><strong>1. 创建订单:</strong></p>
                    <ul style="margin-left: 20px; margin-bottom: 10px;">
                        <li>填写订单信息，点击"创建订单"</li>
                        <li>系统会返回支付链接</li>
                        <li>点击"打开支付页面"进行支付</li>
                    </ul>
                    
                    <p><strong>2. 查询订单:</strong></p>
                    <ul style="margin-left: 20px; margin-bottom: 10px;">
                        <li>输入商户订单号</li>
                        <li>查看订单状态和支付信息</li>
                    </ul>
                    
                    <p><strong>3. 关闭订单:</strong></p>
                    <ul style="margin-left: 20px;">
                        <li>输入商户订单号</li>
                        <li>关闭未支付的订单</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // 复制到剪贴板
        function copyToClipboard(text) {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function() {
                    alert('✅ 支付链接已复制到剪贴板！');
                });
            } else {
                const textArea = document.createElement('textarea');
                textArea.value = text;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                alert('✅ 支付链接已复制到剪贴板！');
            }
        }
        
        // 快速测试
        function fillQuickTest(amount) {
            const timestamp = Date.now();
            document.querySelector('input[name="merchant_order_no"]').value = 'M' + timestamp;
            document.querySelector('input[name="amount"]').value = amount;
            document.querySelector('input[name="subject"]').value = '快速测试商品-' + amount + '元';
            alert('✅ 已自动填充测试数据，金额: ¥' + amount);
        }
        
        // 自动滚动到结果区域
        <?php if ($result || $error): ?>
        window.addEventListener('DOMContentLoaded', function() {
            document.querySelector('.result-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        <?php endif; ?>
    </script>
</body>
</html>



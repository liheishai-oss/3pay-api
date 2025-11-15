<?php

namespace app\controller;

use support\Request;

class DemoGeneratorController
{
    public function index(Request $request)
    {
        // 引入PaymentDemo类
        $demoFile = base_path() . '/public/demo/payment_demo.php';
        if (!file_exists($demoFile)) {
            return response('Demo文件不存在: ' . $demoFile, 404);
        }
        require_once $demoFile;
        
        // 配置API密钥 - 默认值，用户可以在表单中自定义
        $defaultApiKey = '5e38a3bfee6b755adf13d95d99b345e5';
        $defaultApiSecret = '985e44395d1022a2da8e924d05c1e518571296a1302f5d2ebe76febc73b63d11';
        
        // 从表单获取或使用默认值
        $apiKey = $request->post('api_key', $request->get('api_key', $defaultApiKey));
        $apiSecret = $request->post('api_secret', $request->get('api_secret', $defaultApiSecret));
        $baseUrl = 'http://127.0.0.1:8787';
        
        // 处理表单提交
        $result = null;
        $error = null;
        
        if ($request->method() === 'POST' && $request->post('action')) {
            try {
                // 从POST请求中获取API密钥（如果用户提交了自定义值）
                $postApiKey = $request->post('api_key', $apiKey);
                $postApiSecret = $request->post('api_secret', $apiSecret);
                $demo = new \PaymentDemo($postApiKey, $postApiSecret, $baseUrl);
                
                $action = $request->post('action');
                
                if ($action === 'create_order') {
                    // 创建订单
                    $params = [
                        'merchant_order_no' => $request->post('merchant_order_no', 'M' . time()),
                        'product_code' => $request->post('product_code', '9469'),
                        'amount' => $request->post('amount', '1'),
                        'subject' => $request->post('subject', '测试商品'),
                    ];
                    
                    if ($request->post('notify_url')) {
                        $params['notify_url'] = $request->post('notify_url');
                    }
                    if ($request->post('return_url')) {
                        $params['return_url'] = $request->post('return_url');
                    }
                    if ($request->post('auth_code')) {
                        $params['auth_code'] = $request->post('auth_code');
                    }
                    
                    $response = $demo->createOrder($params);
                    
                    if ($response['success']) {
                        $result = $response['data'];
                        
                        // 检查是否是API密钥错误
                        if (isset($result['msg']) && strpos($result['msg'], '无效的API密钥') !== false) {
                            $error = '无效的API密钥或商户已被禁用。请确保：<br>1. API Key 在系统中存在<br>2. 对应的商户状态为启用<br>3. API Secret 正确';
                        } else {
                            // 如果支付链接不是完整URL，补充完整
                            if (isset($result['data']['payment_url'])) {
                                $paymentUrl = $result['data']['payment_url'];
                                if (!preg_match('/^https?:\/\//', $paymentUrl)) {
                                    $result['data']['payment_url_full'] = $baseUrl . '/' . ltrim($paymentUrl, '/');
                                } else {
                                    $result['data']['payment_url_full'] = $paymentUrl;
                                }
                            }
                            
                            // 不再在此处生成当面付二维码，所有支付方式统一展示支付页面URL二维码
                            
                            // 保存产品代码，用于区分支付方式
                            $result['product_code'] = $request->post('product_code');
                        }
                    } else {
                        $error = $response['error'] ?? '请求失败';
                    }
                    
                } elseif ($action === 'query_order') {
                    // 查询订单
                    $merchantOrderNo = $request->post('query_order_no', '');
                    if (empty($merchantOrderNo)) {
                        $error = '请输入订单号';
                    } else {
                        $response = $demo->queryOrder($merchantOrderNo);
                        if ($response['success']) {
                            $result = $response['data'];
                            // 检查是否是API密钥错误
                            if (isset($result['msg']) && strpos($result['msg'], '无效的API密钥') !== false) {
                                $error = '无效的API密钥或商户已被禁用。请确保：<br>1. API Key 在系统中存在<br>2. 对应的商户状态为启用<br>3. API Secret 正确';
                            }
                        } else {
                            $error = $response['error'] ?? '查询失败';
                        }
                    }
                }
            } catch (\Exception $e) {
                $error = '系统异常: ' . $e->getMessage();
            }
        }
        
        // 渲染HTML
        return $this->renderHtml($apiKey, $apiSecret, $defaultApiKey, $defaultApiSecret, $baseUrl, $result, $error);
    }
    
    private function renderHtml($apiKey, $apiSecret, $defaultApiKey, $defaultApiSecret, $baseUrl, $result, $error)
    {
        ob_start();
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
    <!-- 引入二维码生成库 (使用多个CDN备选) -->
    <script src="https://cdn.bootcdn.net/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" 
            onerror="this.onerror=null; this.src='https://cdn.jsdelivr.net/npm/qrcodejs2@0.0.2/qrcode.min.js'"></script>
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
                        
                        <!-- 支付链接二维码展示区域 -->
                        <div style="background: white; padding: 20px; border-radius: 8px; text-align: center; margin: 20px 0;">
                            <div style="font-size: 16px; color: #333; margin-bottom: 15px; font-weight: 500;">
                                📱 扫描二维码访问支付页面
                            </div>
                            <div id="qrcode" style="display: inline-block;"></div>
                            <div style="font-size: 12px; color: #999; margin-top: 10px;">
                                使用手机扫描即可打开支付页面
                            </div>
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
                ❌ 错误: <?= $error ?>
            </div>
            <?php if (strpos($error, '无效的API密钥') !== false): ?>
            <div style="background: #fff7e6; border: 1px solid #ffd591; border-radius: 6px; padding: 15px; margin-top: 15px; font-size: 13px; line-height: 1.8;">
                <strong>💡 提示：</strong>
                <ul style="margin: 10px 0 0 20px;">
                    <li>请检查输入的 API Key 是否在系统中存在</li>
                    <li>确认对应的商户状态为"启用"</li>
                    <li>验证 API Secret 是否正确</li>
                    <li>如果使用自定义配置，请确保该商户已在后台管理系统中创建并启用</li>
                </ul>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <div class="config-info">
            <strong>当前配置:</strong> 
            API Key: <?= substr($apiKey, 0, 8) ?>...*** | 
            Base URL: <?= htmlspecialchars($baseUrl) ?>
        </div>
        
        <div class="content">
            <!-- 配置商户Key和密钥 -->
            <div class="card" style="grid-column: 1 / -1; margin-bottom: 20px;">
                <div class="card-title">🔑 商户配置</div>
                
                <form method="GET" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>商户Key</label>
                        <input type="text" name="api_key" value="<?= htmlspecialchars($apiKey) ?>" placeholder="<?= htmlspecialchars($defaultApiKey) ?>">
                        <small>默认: <?= htmlspecialchars($defaultApiKey) ?></small>
                    </div>
                    
                    <div class="form-group">
                        <label>商户密钥</label>
                        <input type="text" name="api_secret" value="<?= htmlspecialchars($apiSecret) ?>" placeholder="<?= htmlspecialchars($defaultApiSecret) ?>">
                        <small>默认: <?= substr($defaultApiSecret, 0, 16) ?>...***</small>
                    </div>
                    
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <button type="submit" class="btn">🔧 更新配置</button>
                        <button type="button" class="btn" onclick="resetConfig()" style="margin-left: 10px;">🔄 重置为默认</button>
                    </div>
                </form>
            </div>
            
            <!-- 创建订单 -->
            <div class="card">
                <div class="card-title">📝 创建订单</div>
                
                <form method="POST">
                    <input type="hidden" name="action" value="create_order">
                    <input type="hidden" name="api_key" value="<?= htmlspecialchars($apiKey) ?>">
                    <input type="hidden" name="api_secret" value="<?= htmlspecialchars($apiSecret) ?>">
                    
                    <div class="form-group">
                        <label>商户订单号</label>
                        <input type="text" name="merchant_order_no" value="M<?= time() ?>" required>
                        <small>唯一的商户订单号，建议使用时间戳</small>
                    </div>
                    
                    <div class="form-group">
                        <label>产品代码</label>
                        <input type="text" name="product_code" value="9469" placeholder="请输入产品代码" required>
                        <small>常用代码：9469(支付宝WAP支付)、9470(支付宝扫码支付)、9471(支付宝条码支付)、2215(当面付)</small>
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
                        <input type="url" name="notify_url" value="<?= htmlspecialchars($baseUrl . '/demo/merchant/notify') ?>" placeholder="<?= htmlspecialchars($baseUrl . '/demo/merchant/notify') ?>">
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
            
            <!-- 查询订单 -->
            <div class="card">
                <div class="card-title">🔍 查询订单</div>
                
                <form method="POST">
                    <input type="hidden" name="action" value="query_order">
                    <input type="hidden" name="api_key" value="<?= htmlspecialchars($apiKey) ?>">
                    <input type="hidden" name="api_secret" value="<?= htmlspecialchars($apiSecret) ?>">
                    
                    <div class="form-group">
                        <label>商户订单号</label>
                        <input type="text" name="query_order_no" placeholder="输入要查询的订单号" required>
                    </div>
                    
                    <button type="submit" class="btn btn-block">🔍 查询订单状态</button>
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
                    <ul style="margin-left: 20px;">
                        <li>输入商户订单号</li>
                        <li>查看订单状态和支付信息</li>
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
        
        // 重置配置为默认值
        function resetConfig() {
            document.querySelector('input[name="api_key"]').value = '<?= htmlspecialchars($defaultApiKey) ?>';
            document.querySelector('input[name="api_secret"]').value = '<?= htmlspecialchars($defaultApiSecret) ?>';
            alert('✅ 已重置为默认配置');
        }
        
        // 生成支付页面URL二维码（所有支付方式统一）
        <?php if ($result && isset($result['data']['payment_url_full'])): ?>
        window.addEventListener('DOMContentLoaded', function() {
            // 延迟生成二维码，确保库已加载
            setTimeout(function() {
                const qrcodeContainer = document.getElementById('qrcode');
                if (!qrcodeContainer) {
                    console.error('❌ 二维码容器未找到');
                    return;
                }
                
                // 检查 QRCode 库是否加载
                if (typeof QRCode === 'undefined') {
                    console.error('❌ QRCode 库未加载');
                    qrcodeContainer.innerHTML = '<div style="color: #ff4d4f; padding: 20px;">二维码库加载失败，请刷新页面重试</div>';
                    return;
                }
                
                try {
                    // 清空二维码容器
                    qrcodeContainer.innerHTML = '';
                    
                    // 生成支付页面URL二维码
                    const paymentUrl = '<?= addslashes($result['data']['payment_url_full']) ?>';
                    console.log('🔄 开始生成支付页面二维码:', paymentUrl);
                    
                    new QRCode(qrcodeContainer, {
                        text: paymentUrl,
                        width: 200,
                        height: 200,
                        colorDark: '#000000',
                        colorLight: '#ffffff',
                        correctLevel: QRCode.CorrectLevel.H
                    });
                    
                    console.log('✅ 二维码生成成功');
                } catch (error) {
                    console.error('❌ 二维码生成失败:', error);
                    qrcodeContainer.innerHTML = '<div style="color: #ff4d4f; padding: 20px;">二维码生成失败: ' + error.message + '</div>';
                }
            }, 100); // 延迟100ms确保DOM和库都已加载
        });
        <?php endif; ?>
        
        // 自动滚动到结果区域
        <?php if ($result || $error): ?>
        window.addEventListener('DOMContentLoaded', function() {
            const resultCard = document.querySelector('.result-card');
            if (resultCard) {
                resultCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
        <?php
        return ob_get_clean();
    }
}



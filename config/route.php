<?php

use app\admin\controller\v1\system\LoginController;
use Webman\Route;

// 定义后台管理路由
Route::group('/api/v1/admin', function() {
    // 生成谷歌验证码二维码
    Route::add(['GET', 'OPTIONS'], '/google-auth/qrcode/{secret}', [app\admin\controller\v1\GoogleAuthController::class, 'generateQrCode']);
    Route::add(['GET', 'OPTIONS'], '/system/rule', [app\admin\controller\v1\MenuRuleController::class, 'rule']);

    // 测试路由
    Route::add(['GET', 'OPTIONS'], '/test/server-list', [app\admin\controller\v1\TestController::class, 'serverList']);
    
    // 订单日志查询
    Route::group('/order-log', function () {
        Route::add(['GET', 'OPTIONS'], '', [app\admin\controller\v1\OrderLogController::class, 'index']);
        Route::add(['GET', 'OPTIONS'], '/search', [app\admin\controller\v1\OrderLogController::class, 'search']);
        Route::add(['GET', 'OPTIONS'], '/detail', [app\admin\controller\v1\OrderLogController::class, 'detail']);
    });
    
    // 监控管理
    Route::group('/monitor', function () {
        Route::add(['GET', 'OPTIONS'], '/dashboard', [app\admin\controller\v1\MonitorController::class, 'dashboard']);
        Route::add(['GET', 'OPTIONS'], '/realtime-data', [app\admin\controller\v1\MonitorController::class, 'realtimeData']);
        Route::add(['GET', 'OPTIONS'], '/order-stats', [app\admin\controller\v1\MonitorController::class, 'orderStats']);
        Route::add(['GET', 'OPTIONS'], '/payment-stats', [app\admin\controller\v1\MonitorController::class, 'paymentStats']);
        Route::add(['GET', 'OPTIONS'], '/system-health', [app\admin\controller\v1\MonitorController::class, 'systemHealth']);
        Route::add(['GET', 'OPTIONS'], '/alerts', [app\admin\controller\v1\MonitorController::class, 'alerts']);
        Route::add(['GET', 'OPTIONS'], '/trend-data', [app\admin\controller\v1\MonitorController::class, 'trendData']);

        // 历史分析API
        Route::add(['GET', 'OPTIONS'], '/analysis/order-trend', [app\admin\controller\v1\MonitorController::class, 'orderTrendAnalysis']);
        Route::add(['GET', 'OPTIONS'], '/analysis/conversion', [app\admin\controller\v1\MonitorController::class, 'conversionRateAnalysis']);
        Route::add(['GET', 'OPTIONS'], '/analysis/anomalies', [app\admin\controller\v1\MonitorController::class, 'anomalyPatternAnalysis']);
        Route::add(['GET', 'OPTIONS'], '/analysis/performance', [app\admin\controller\v1\MonitorController::class, 'performanceMetricAnalysis']);
    });

    // 预警管理
    Route::group('/alert', function () {
        Route::add(['GET', 'OPTIONS'], '/config', [app\admin\controller\v1\AlertController::class, 'config']);
        Route::add(['GET', 'OPTIONS'], '/history', [app\admin\controller\v1\AlertController::class, 'history']);
        Route::add(['GET', 'OPTIONS'], '/rules', [app\admin\controller\v1\AlertController::class, 'getRules']);
        Route::add(['POST', 'OPTIONS'], '/rules/create', [app\admin\controller\v1\AlertController::class, 'createRule']);
        Route::add(['POST', 'OPTIONS'], '/rules/update', [app\admin\controller\v1\AlertController::class, 'updateRule']);
        Route::add(['POST', 'OPTIONS'], '/rules/delete', [app\admin\controller\v1\AlertController::class, 'deleteRule']);
        Route::add(['GET', 'OPTIONS'], '/history/list', [app\admin\controller\v1\AlertController::class, 'getHistory']);
        Route::add(['POST', 'OPTIONS'], '/handle', [app\admin\controller\v1\AlertController::class, 'handleAlert']);
        Route::add(['GET', 'OPTIONS'], '/realtime', [app\admin\controller\v1\AlertController::class, 'getRealtimeAlerts']);
        Route::add(['POST', 'OPTIONS'], '/test-rule', [app\admin\controller\v1\AlertController::class, 'testRule']);
    });
    
    
    // 菜单规则管理
    Route::group('/menu-rule', function () {
        // 获取菜单规则列表
        Route::add(['GET', 'OPTIONS'], '', [app\admin\controller\v1\MenuRuleController::class, 'index']);
        // 获取子节点
        Route::add(['GET', 'OPTIONS'], '/children/{rule_id}', [app\admin\controller\v1\MenuRuleController::class, 'children']);

//        // 获取单个菜单规则详情
//
//
//        // 创建菜单规则
        Route::add(['POST', 'OPTIONS'], '/add', [app\admin\controller\v1\MenuRuleController::class, 'store']);
//
//        // 更新菜单规则
        Route::add(['POST', 'OPTIONS'], '/edit', [app\admin\controller\v1\MenuRuleController::class, 'edit']);
//
//        // 删除菜单规则
        Route::add(['POST', 'OPTIONS'], '/delete', [app\admin\controller\v1\MenuRuleController::class, 'destroy']);
//
        // 获取下拉菜单数据
        Route::add(['GET', 'OPTIONS'], '/dropdown/{group_id}', [app\admin\controller\v1\MenuRuleController::class, 'dropdown']);
    });

    // 系统日志
    Route::group('/system/log', function () {
        // 获取日志表
        Route::add(['GET', 'OPTIONS'], '', [app\admin\controller\v1\AdminLogController::class, 'index']);
        // 删除日志
        Route::add(['POST', 'OPTIONS'], '/delete', [app\admin\controller\v1\AdminLogController::class, 'destroy']);
    });

    // 系统配置
    Route::group('/system/config', function () {
        // 获取配置列表
        Route::add(['GET', 'OPTIONS'], '/list', [app\admin\controller\v1\SystemConfigController::class, 'index']);
        // 保存配置
        Route::add(['POST', 'OPTIONS'], '/save', [app\admin\controller\v1\SystemConfigController::class, 'save']);
        // 获取配置分组
        Route::add(['GET', 'OPTIONS'], '/groups', [app\admin\controller\v1\SystemConfigController::class, 'getGroups']);
        // 重置配置
        Route::add(['POST', 'OPTIONS'], '/reset', [app\admin\controller\v1\SystemConfigController::class, 'reset']);
    });




    // 系统菜单
    Route::add(['GET', 'OPTIONS'],'/menu', [app\admin\controller\v1\AdminController::class, 'menu']);
    Route::group('/user', function () {
        Route::add(['GET', 'OPTIONS'], '/info', [app\admin\controller\v1\AdminController::class, 'info']);
        Route::add(['GET', 'OPTIONS'],'/index', [app\admin\controller\v1\AdminController::class, 'index']);
        Route::add(['POST', 'OPTIONS'], '/add', [app\admin\controller\v1\AdminController::class, 'store']);
        Route::add(['POST', 'OPTIONS'], '/edit', [app\admin\controller\v1\AdminController::class, 'store']);
        Route::add(['GET', 'OPTIONS'], '/detail/{id}', [app\admin\controller\v1\AdminController::class, 'detail']);
        Route::add(['POST', 'OPTIONS'], '/delete', [app\admin\controller\v1\AdminController::class, 'destroy']);
        Route::add(['POST', 'OPTIONS'], '/switch', [app\admin\controller\v1\AdminController::class, 'switch']);
        // 系统登录
//        Route::add(['POST', 'OPTIONS'],'/login', [app\admin\controller\v1\AdminController::class, 'login']);
        Route::add(['POST', 'OPTIONS'],'/login', [LoginController::class, 'login']);
        Route::group('/group', function () {
            Route::add(['GET', 'OPTIONS'],'', [app\admin\controller\v1\AdminGroupController::class, 'index']);
            Route::add(['GET', 'OPTIONS'],'/dropdown/{group_id}', [app\admin\controller\v1\AdminGroupController::class, 'dropdown']);
            Route::add(['POST', 'OPTIONS'], '/add', [app\admin\controller\v1\AdminGroupController::class, 'store']);
            Route::add(['POST', 'OPTIONS'], '/edit', [app\admin\controller\v1\AdminGroupController::class, 'store']);
            Route::add(['GET', 'OPTIONS'], '/detail/{id}', [app\admin\controller\v1\AdminGroupController::class, 'detail']);
            Route::add(['POST', 'OPTIONS'], '/delete', [app\admin\controller\v1\AdminGroupController::class, 'destroy']);
        });
    });

    Route::group('/telegram-admin', function () {
        // 获取列表
        Route::add(['GET', 'OPTIONS'], '', [app\admin\controller\v1\telegram\admin\IndexController::class, 'index']);
        // 获取详情
        Route::add(['GET', 'OPTIONS'], '/detail/{id}', [app\admin\controller\v1\telegram\admin\DetailController::class, 'show']);
        // 新增
        Route::add(['POST', 'OPTIONS'], '/add', [app\admin\controller\v1\telegram\admin\StoreController::class, 'store']);
        // 编辑
        Route::add(['POST', 'OPTIONS'], '/edit', [app\admin\controller\v1\telegram\admin\EditAdminController::class, 'update']);
        // 删除
        Route::add(['POST', 'OPTIONS'], '/delete', [app\admin\controller\v1\telegram\admin\DestroyController::class, 'destroy']);
        // 状态开关
        Route::add(['POST', 'OPTIONS'], '/status-switch', [app\admin\controller\v1\telegram\admin\StatusSwitchController::class, 'toggle']);
    });

    // 支付类型管理路由
    Route::group('/payment-type', function () {
        Route::add(['GET', 'OPTIONS'], '/list', [app\admin\controller\v1\PaymentTypeController::class, 'index']);
        Route::add(['GET', 'OPTIONS'], '/detail/{id}', [app\admin\controller\v1\PaymentTypeController::class, 'detail']);
        Route::add(['POST', 'OPTIONS'], '/add', [app\admin\controller\v1\PaymentTypeController::class, 'store']);
        Route::add(['POST', 'OPTIONS'], '/edit', [app\admin\controller\v1\PaymentTypeController::class, 'store']);
        Route::add(['POST', 'OPTIONS'], '/delete', [app\admin\controller\v1\PaymentTypeController::class, 'destroy']);
        Route::add(['POST', 'OPTIONS'], '/switch', [app\admin\controller\v1\PaymentTypeController::class, 'switch']);
    });

    // 代理商管理路由
    Route::group('/agent', function () {
        Route::add(['GET', 'OPTIONS'], '/list', [app\admin\controller\v1\AgentController::class, 'index']);
        Route::add(['GET', 'OPTIONS'], '/detail/{id}', [app\admin\controller\v1\AgentController::class, 'detail']);
        Route::add(['POST', 'OPTIONS'], '/add', [app\admin\controller\v1\AgentController::class, 'store']);
        Route::add(['POST', 'OPTIONS'], '/edit', [app\admin\controller\v1\AgentController::class, 'store']);
        Route::add(['POST', 'OPTIONS'], '/delete', [app\admin\controller\v1\AgentController::class, 'destroy']);
        Route::add(['POST', 'OPTIONS'], '/switch', [app\admin\controller\v1\AgentController::class, 'switch']);
    });

    // 主体管理路由
    Route::group('/subject', function () {
        Route::add(['GET', 'OPTIONS'], '/list', [app\admin\controller\v1\SubjectController::class, 'index']);
        Route::add(['GET', 'OPTIONS'], '/detail/{id}', [app\admin\controller\v1\SubjectController::class, 'detail']);
        Route::add(['POST', 'OPTIONS'], '/add', [app\admin\controller\v1\SubjectController::class, 'store']);
        Route::add(['POST', 'OPTIONS'], '/edit', [app\admin\controller\v1\SubjectController::class, 'store']);
        Route::add(['POST', 'OPTIONS'], '/delete', [app\admin\controller\v1\SubjectController::class, 'destroy']);
        Route::add(['POST', 'OPTIONS'], '/switch', [app\admin\controller\v1\SubjectController::class, 'switch']);
        Route::add(['GET', 'OPTIONS'], '/agent-list', [app\admin\controller\v1\SubjectController::class, 'getAgentList']);
        Route::add(['GET', 'OPTIONS'], '/product-list', [app\admin\controller\v1\SubjectController::class, 'getProductList']);
    });

    // 主体支付类型管理路由
    Route::group('/subject-payment-type', function () {
        Route::add(['POST', 'GET', 'OPTIONS'], '/list', [app\admin\controller\v1\SubjectPaymentTypeController::class, 'getSubjectPaymentTypes']);
        Route::add(['POST', 'OPTIONS'], '/toggle', [app\admin\controller\v1\SubjectPaymentTypeController::class, 'togglePaymentType']);
        Route::add(['POST', 'OPTIONS'], '/bind', [app\admin\controller\v1\SubjectPaymentTypeController::class, 'bindPaymentType']);
        Route::add(['POST', 'OPTIONS'], '/unbind', [app\admin\controller\v1\SubjectPaymentTypeController::class, 'unbindPaymentType']);
        Route::add(['POST', 'OPTIONS'], '/batch-bind', [app\admin\controller\v1\SubjectPaymentTypeController::class, 'batchBindPaymentTypes']);
    });

    // 文件上传路由
    Route::group('/upload', function () {
        Route::add(['POST', 'OPTIONS'], '/cert', [app\admin\controller\v1\UploadController::class, 'uploadCert']);
        Route::add(['POST', 'OPTIONS'], '/delete-cert', [app\admin\controller\v1\UploadController::class, 'deleteCert']);
    });

    // 产品管理路由
    Route::group('/product', function () {
        Route::add(['GET', 'OPTIONS'], '/list', [app\admin\controller\v1\ProductController::class, 'index']);
        Route::add(['GET', 'OPTIONS'], '/detail/{id}', [app\admin\controller\v1\ProductController::class, 'detail']);
        Route::add(['POST', 'OPTIONS'], '/add', [app\admin\controller\v1\ProductController::class, 'store']);
        Route::add(['POST', 'OPTIONS'], '/edit', [app\admin\controller\v1\ProductController::class, 'store']);
        Route::add(['POST', 'OPTIONS'], '/delete', [app\admin\controller\v1\ProductController::class, 'destroy']);
        Route::add(['POST', 'OPTIONS'], '/switch', [app\admin\controller\v1\ProductController::class, 'switch']);
        Route::add(['GET', 'OPTIONS'], '/payment-type-list', [app\admin\controller\v1\ProductController::class, 'getPaymentTypeList']);
        Route::add(['GET', 'OPTIONS'], '/agent-list', [app\admin\controller\v1\ProductController::class, 'getAgentList']);
    });

    // 单笔分账路由
    Route::group('/single-royalty', function () {
        Route::add(['GET', 'OPTIONS'], '/list', [app\admin\controller\v1\SingleRoyaltyController::class, 'index']);
        Route::add(['GET', 'OPTIONS'], '/detail/{id}', [app\admin\controller\v1\SingleRoyaltyController::class, 'detail']);
        Route::add(['POST', 'OPTIONS'], '/add', [app\admin\controller\v1\SingleRoyaltyController::class, 'store']);
        Route::add(['POST', 'OPTIONS'], '/edit', [app\admin\controller\v1\SingleRoyaltyController::class, 'store']);
        Route::add(['POST', 'OPTIONS'], '/delete', [app\admin\controller\v1\SingleRoyaltyController::class, 'destroy']);
        Route::add(['POST', 'OPTIONS'], '/switch', [app\admin\controller\v1\SingleRoyaltyController::class, 'switch']);
        Route::add(['GET', 'OPTIONS'], '/agent-list', [app\admin\controller\v1\SingleRoyaltyController::class, 'getAgentList']);
    });

    // 商户管理路由
    Route::group('/merchant', function () {
        Route::add(['GET', 'OPTIONS'], '/list', [app\admin\controller\v1\MerchantController::class, 'index']);
        Route::add(['GET', 'OPTIONS'], '/detail/{id}', [app\admin\controller\v1\MerchantController::class, 'detail']);
        Route::add(['POST', 'OPTIONS'], '/add', [app\admin\controller\v1\MerchantController::class, 'store']);
        Route::add(['POST', 'OPTIONS'], '/edit', [app\admin\controller\v1\MerchantController::class, 'store']);
        Route::add(['POST', 'OPTIONS'], '/delete', [app\admin\controller\v1\MerchantController::class, 'destroy']);
        Route::add(['POST', 'OPTIONS'], '/switch', [app\admin\controller\v1\MerchantController::class, 'switch']);
        Route::add(['POST', 'OPTIONS'], '/reset-api-key', [app\admin\controller\v1\MerchantController::class, 'resetApiKey']);
        Route::add(['GET', 'OPTIONS'], '/agent-list', [app\admin\controller\v1\MerchantController::class, 'getAgentList']);
        Route::add(['POST', 'OPTIONS'], '/clear-circuit', [app\admin\controller\v1\MerchantController::class, 'clearCircuit']);
        Route::add(['GET', 'OPTIONS'], '/circuit-status/{id}', [app\admin\controller\v1\MerchantController::class, 'getCircuitStatus']);
    });

    // 订单管理路由
    Route::group('/order', function () {
        Route::add(['GET', 'OPTIONS'], '/list', [app\admin\controller\v1\OrderController::class, 'index']);
        Route::add(['GET', 'OPTIONS'], '/detail/{id}', [app\admin\controller\v1\OrderController::class, 'detail']);
        Route::add(['GET', 'OPTIONS'], '/agent-list', [app\admin\controller\v1\OrderController::class, 'getAgentList']);
        Route::add(['GET', 'OPTIONS'], '/merchant-list', [app\admin\controller\v1\OrderController::class, 'getMerchantList']);
        Route::add(['GET', 'OPTIONS'], '/statistics', [app\admin\controller\v1\OrderController::class, 'statistics']);
        Route::add(['POST', 'OPTIONS'], '/supplement', [app\admin\controller\v1\OrderController::class, 'supplement']);
        Route::add(['POST', 'OPTIONS'], '/reissue', [app\admin\controller\v1\OrderController::class, 'reissue']);
        Route::add(['POST', 'OPTIONS'], '/callback', [app\admin\controller\v1\OrderController::class, 'manualCallback']);
    });

// 投诉管理路由
Route::group('/complaint', function () {
    Route::add(['GET', 'OPTIONS'], '/list', [app\admin\controller\v1\ComplaintController::class, 'list']);
    Route::add(['GET', 'OPTIONS'], '/detail', [app\admin\controller\v1\ComplaintController::class, 'detail']);
    Route::add(['GET', 'OPTIONS'], '/subject-list', [app\admin\controller\v1\ComplaintController::class, 'subjectList']);
    Route::add(['POST', 'OPTIONS'], '/handle', [app\admin\controller\v1\ComplaintController::class, 'handle']);
});

    // 服务器管理路由
    Route::group('/server', function () {
        // 服务器CRUD操作
        Route::add(['GET', 'OPTIONS'], '/list', [app\admin\controller\v1\ServerController::class, 'index']);
        Route::add(['GET', 'OPTIONS'], '/detail/{id}', [app\admin\controller\v1\ServerController::class, 'show']);
        Route::add(['POST', 'OPTIONS'], '/add', [app\admin\controller\v1\ServerController::class, 'store']);
        Route::add(['POST', 'OPTIONS'], '/update/{id}', [app\admin\controller\v1\ServerController::class, 'update']);
        Route::add(['POST', 'OPTIONS'], '/toggle-maintenance/{id}', [app\admin\controller\v1\ServerController::class, 'toggleMaintenance']);
        Route::add(['POST', 'OPTIONS'], '/update-status/{id}', [app\admin\controller\v1\ServerController::class, 'updateStatus']);
        Route::add(['DELETE', 'OPTIONS'], '/delete/{id}', [app\admin\controller\v1\ServerController::class, 'destroy']);
        Route::add(['GET', 'OPTIONS'], '/check-maintenance', [app\admin\controller\v1\ServerController::class, 'checkMaintenanceStatus']);
        Route::add(['GET', 'OPTIONS'], '/nginx-config', [app\admin\controller\v1\ServerController::class, 'getNginxConfig']);
        Route::add(['POST', 'OPTIONS'], '/deploy', [app\admin\controller\v1\ServerController::class, 'deploy']);
        
        // 原有服务器状态管理路由
        Route::add(['GET', 'OPTIONS'], '/status', [app\admin\controller\v1\ServerManagementController::class, 'getCurrentServerStatus']);
        Route::add(['GET', 'OPTIONS'], '/all', [app\admin\controller\v1\ServerManagementController::class, 'getAllServersStatus']);
        Route::add(['POST', 'OPTIONS'], '/set-status', [app\admin\controller\v1\ServerManagementController::class, 'setServerStatus']);
        Route::add(['POST', 'OPTIONS'], '/batch-set-status', [app\admin\controller\v1\ServerManagementController::class, 'batchSetServerStatus']);
        Route::add(['GET', 'OPTIONS'], '/health', [app\admin\controller\v1\ServerManagementController::class, 'getServerHealth']);
        Route::add(['POST', 'OPTIONS'], '/remove', [app\admin\controller\v1\ServerManagementController::class, 'removeServer']);
        Route::add(['GET', 'OPTIONS'], '/stats', [app\admin\controller\v1\ServerManagementController::class, 'getServerStats']);
    });

    // 权限测试路由
    Route::add(['GET', 'OPTIONS'], '/test/permissions', [app\admin\controller\v1\TestController::class, 'getPermissions']);
    Route::add(['GET', 'OPTIONS'], '/test/check-permissions', [app\admin\controller\v1\TestController::class, 'checkPermissions']);
    
    // 谷歌验证码路由
    Route::add(['GET', 'OPTIONS'], '/google-auth/qr-code', [app\admin\controller\v1\GoogleAuthController::class, 'generateQrCode']);
    Route::add(['POST', 'OPTIONS'], '/google-auth/bind', [app\admin\controller\v1\GoogleAuthController::class, 'bindGoogleAuth']);
    Route::add(['GET', 'OPTIONS'], '/google-auth/check', [app\admin\controller\v1\GoogleAuthController::class, 'checkBinding']);

    // 密码修改路由
    Route::add(['POST', 'OPTIONS'], '/change-password', [app\admin\controller\v1\ChangePasswordController::class, 'changePassword']);
    Route::add(['POST', 'OPTIONS'], '/update-password', [app\admin\controller\v1\ChangePasswordController::class, 'updatePassword']);




})->middleware([app\middleware\Auth::class]);


// Telegram Webhook路由
Route::group('/telegram', function () {
    // 处理Telegram Webhook
    Route::add(['POST', 'OPTIONS'], '/webhook', [app\admin\controller\v1\robot\TelegramWebhookController::class, 'handleWebhook']);
});
// DEMO: 模拟商户回调接收端
Route::group('/demo/merchant', function () {
    Route::add(['POST', 'GET', 'OPTIONS'], '/notify', [app\controller\DemoMerchantController::class, 'notify']);
});
Route::group('/notify', function () {
    // 处理Telegram Webhook
    Route::add(['POST', 'OPTIONS'], '', [app\admin\controller\v1\robot\TestNotifyController::class, 'index']);
});

// 商户对接API路由
Route::group('/api/v1/merchant', function() {
    // 创建订单
    Route::add(['POST', 'OPTIONS'], '/order/create', [app\api\controller\v1\OrderController::class, 'create']);
    
    // 查询订单
    Route::add(['POST', 'GET', 'OPTIONS'], '/order/query', [app\api\controller\v1\OrderController::class, 'query']);
    
    // 订单关闭
    Route::add(['POST', 'OPTIONS'], '/order/close', [app\api\controller\v1\OrderController::class, 'close']);
});

// 支付相关API路由
Route::group('/api/v1/payment', function() {
    // 支付通知
    Route::add(['POST', 'OPTIONS'], '/notify/alipay', [app\api\controller\v1\PaymentNotifyController::class, 'alipay']);
    
    // 支付查询
    Route::add(['POST', 'GET', 'OPTIONS'], '/query', [app\api\controller\v1\PaymentQueryController::class, 'queryOrder']);
});

// OAuth授权相关API路由
Route::group('/api/v1/oauth', function() {
    // 通过订单号获取授权URL
    Route::add(['POST', 'GET', 'OPTIONS'], '/auth-url-by-order', [app\api\controller\v1\OAuthController::class, 'getAuthUrlByOrder']);
    
    // 获取授权URL（原方法）
    Route::add(['POST', 'GET', 'OPTIONS'], '/auth-url', [app\api\controller\v1\OAuthController::class, 'getAuthUrl']);
    
    // OAuth回调处理
    Route::add(['POST', 'GET', 'OPTIONS'], '/callback', [app\api\controller\v1\OAuthController::class, 'callback']);
    
    // 获取用户信息
    Route::add(['POST', 'GET', 'OPTIONS'], '/user-info', [app\api\controller\v1\OAuthController::class, 'getUserInfo']);
    
    // 刷新令牌
    Route::add(['POST', 'OPTIONS'], '/refresh-token', [app\api\controller\v1\OAuthController::class, 'refreshToken']);
});

// 支付页面路由
Route::get('/payment.html', [app\controller\PaymentPageController::class, 'payment']);
Route::get('/payment/close', [app\controller\PaymentPageController::class, 'closeIfExpired']);

// OAuth授权回调路由
Route::get('/oauth/callback', [app\controller\PaymentPageController::class, 'oauthCallback']);

// Demo生成器路由
Route::any('/demo', [app\controller\DemoGeneratorController::class, 'index']);
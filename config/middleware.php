<?php
return [
    '' => [
//        app\middleware\MaintenanceCheckMiddleware::class, // 维护状态检查 - 暂时禁用
        app\middleware\CrossDomain::class,//跨域请求
//        app\middleware\ServerStatusMiddleware::class, // 服务器状态检查（优先级最高）
//        app\middleware\Debug::class,
//        app\middleware\AutoAddCurrentIpMiddleware::class, // 自动添加当前外网IP - 临时禁用
//        app\middleware\Auth::class
    ],


];
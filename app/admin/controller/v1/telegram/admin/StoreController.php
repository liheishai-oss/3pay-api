<?php

namespace app\admin\controller\v1\telegram\admin;

use app\admin\controller\v1\telegram\admin\validator\TelegramAdminValidator;
use app\service\TelegramAdminService;
use support\Request;
use support\Response;
use Throwable;

class StoreController
{

    public function __construct(
        private readonly TelegramAdminValidator $validator,
        private readonly TelegramAdminService $telegramAdminService
    ) {
    }

    /**
     * 创建管理员
     */
    public function store(Request $request): Response
    {
        try {
            $data = $request->all();
            
            // 参数校验
            $this->validator->validateCreate($data);
            
            // 创建管理员
            $this->telegramAdminService->create($data);
            
            return success([], '创建管理员成功');
        } catch (Throwable $e) {
            return error($e->getMessage(), (int)($e->getCode() ?: 400));
        }
    }
}
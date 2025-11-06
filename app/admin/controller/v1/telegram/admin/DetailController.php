<?php

namespace app\admin\controller\v1\telegram\admin;

use app\service\TelegramAdminService;
use support\Request;
use support\Response;
use Throwable;

class DetailController
{

    public function __construct(private readonly TelegramAdminService $telegramAdminService)
    {
    }

    /**
     * 获取管理员详情
     */
    public function show(Request $request, int $id): Response
    {
        try {
            if (!$id) {
                return error('ID不能为空', 400);
            }

            $admin = $this->telegramAdminService->getDetail($id);
            
            return success($admin, '获取详情成功');
        } catch (Throwable $e) {
            return error($e->getMessage(), (int)($e->getCode() ?: 400));
        }
    }
}
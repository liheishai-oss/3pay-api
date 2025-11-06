<?php

namespace app\admin\controller\v1\telegram\admin;

use app\service\TelegramAdminService;
use support\Request;
use support\Response;
use Throwable;

class StatusSwitchController
{

    public function __construct(private readonly TelegramAdminService $telegramAdminService)
    {
    }

    /**
     * 切换管理员状态
     */
    public function toggle(Request $request): Response
    {
        try {
            $id = $request->input('id');
            if (!$id) {
                return error('ID不能为空', 400);
            }

            $admin = $this->telegramAdminService->toggleStatus($id);
            
            $statusText = $admin->status === 1 ? '启用' : '禁用';
            return success([], "管理员状态已切换为：{$statusText}");
        } catch (Throwable $e) {
            return error($e->getMessage(), (int)($e->getCode() ?: 400));
        }
    }
}
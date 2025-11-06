<?php

namespace app\admin\controller\v1\telegram\admin;

use app\admin\controller\v1\telegram\admin\validator\TelegramAdminValidator;
use app\service\TelegramAdminService;
use support\Request;
use support\Response;
use Throwable;

class EditAdminController
{

    public function __construct(
        private readonly TelegramAdminValidator $validator,
        private readonly TelegramAdminService $telegramAdminService
    ) {
    }

    /**
     * 更新管理员
     */
    public function update(Request $request): Response
    {
        try {
            $id = $request->input('id');
            if (!$id) {
                return error('ID不能为空', 400);
            }

            $data = $request->all();
            unset($data['id']); // 移除ID字段
            
            // 参数校验
            $this->validator->validateUpdate($data);
            
            // 更新管理员
             $this->telegramAdminService->update($id, $data);
            
            return success([], '更新管理员成功');
        } catch (Throwable $e) {
            return error($e->getMessage(), (int)($e->getCode() ?: 400));
        }
    }

    /**
     * 批量更新状态
     */
    public function batchUpdateStatus(Request $request): Response
    {
        try {
            $data = $request->all();
            
            // 参数校验
            $this->validator->validateBatch($data);
            
            $status = $request->input('status', 1);
            if (!in_array($status, [0, 1])) {
                return error('状态值无效', 400);
            }
            
            // 批量更新状态
            $count = $this->telegramAdminService->batchUpdateStatus($data['ids'], $status);
            
            return success(['count' => $count], "批量更新状态成功，共更新{$count}条记录");
        } catch (Throwable $e) {
            return error($e->getMessage(), (int)($e->getCode() ?: 400));
        }
    }
}
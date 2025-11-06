<?php

namespace app\admin\controller\v1\telegram\admin;

use app\admin\controller\v1\telegram\admin\validator\TelegramAdminValidator;
use app\exception\MyBusinessException;
use app\service\TelegramAdminService;
use support\Request;
use support\Response;
use Throwable;

class DestroyController
{

    public function __construct(
        private readonly TelegramAdminValidator $validator,
        private readonly TelegramAdminService $telegramAdminService
    ) {
    }

    /**
     * 删除单个管理员
     */
    public function destroy(Request $request): Response
    {
        try {
            $ids = $request->post('ids'); // 接收前端传来的 'ids' 数组

            if (empty($ids)) {
                return error('请选择要删除的数据');
            }

            if (!is_array($ids)) {
                throw new MyBusinessException('参数错误，缺少要删除的ID列表');
            }

            $result = $this->telegramAdminService->delete($ids);
            
            if ($result) {
                return success([], '删除管理员成功');
            } else {
                return error('删除失败', 500);
            }
        } catch (Throwable $e) {
            return error($e->getMessage(), (int)($e->getCode() ?: 400));
        }
    }

    /**
     * 批量删除管理员
     */
    public function batchDestroy(Request $request): Response
    {
        try {
            $data = $request->all();
            
            // 参数校验
            $this->validator->validateBatch($data);
            
            // 批量删除
            $count = $this->telegramAdminService->batchDelete($data['ids']);
            
            return success(['count' => $count], "批量删除成功，共删除{$count}条记录");
        } catch (Throwable $e) {
            return error($e->getMessage(), (int)($e->getCode() ?: 400));
        }
    }
}
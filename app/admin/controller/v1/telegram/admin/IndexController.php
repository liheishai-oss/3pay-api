<?php

namespace app\admin\controller\v1\telegram\admin;

use app\service\TelegramAdminService;
use support\Request;
use support\Response;
use Throwable;

class IndexController
{

    public function __construct(private readonly TelegramAdminService $telegramAdminService)
    {
    }

    /**
     * 获取管理员列表
     */
    public function index(Request $request): Response
    {
        try {
            $params = $request->all();
            $result = $this->telegramAdminService->getList($params);
            return success($result, '获取列表成功');
        } catch (Throwable $e) {
            return error($e->getMessage(), (int)($e->getCode() ?: 400));
        }
    }

    /**
     * 获取统计信息
     */
    public function statistics(): Response
    {
        try {
            $statistics = $this->telegramAdminService->getStatistics();
            return success($statistics, '获取统计信息成功');
        } catch (Throwable $e) {
            return error($e->getMessage(), (int)($e->getCode() ?: 400));
        }
    }
}
<?php

namespace app\admin\controller\v1;

use app\model\AdminLog;
use support\Request;
use support\Response;

class AdminLogController
{
    public function index(Request $request): Response
    {
        $param = $request->all();

//        $productNumber = $request->get('product_number');
//        $name = $request->get('name');

        $query = AdminLog::query();

//        if ($productNumber) {
//            $query->where('product_number', 'like', "%{$productNumber}%");
//        }
//
//        if ($name) {
//            $query->where('name', 'like', "%{$name}%");
//        }
        $list = $query->orderByDesc('id')->paginate($param['page_size'])->toArray();

        return success($list);
    }
    public function detail(Request $request,int $id): Response
    {
        try{
            $data = ProductType::find($id);
            return success($data->toArray());
        }catch (\Throwable $e){
            return error($e->getMessage());
        }
    }

    public function destroy(Request $request): Response
    {
        try {
            $ids = $request->post('ids');
            if (empty($ids)) {
                return error('缺少ID');
            }

            if (empty($ids)) {
                return error('无效的ID');
            }

            // 查询是否存在这些 ID
            $count = ProductType::whereIn('id', $ids)->where('is_deleted', 0)->count();
            if ($count === 0) {
                return error('未找到任何可删除的数据');
            }
            // 执行软删除
            ProductType::whereIn('id', $ids)->update(['is_deleted' => 1]);

            return success([],'删除成功');
        } catch (\Throwable $e) {
            return error($e->getMessage());
        }
    }
}
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
            
            if (empty($ids) || !is_array($ids)) {
                return error('参数错误，缺少要删除的ID列表');
            }

            // 查询是否存在这些 ID
            $count = AdminLog::whereIn('id', $ids)->count();
            if ($count === 0) {
                return error('未找到任何可删除的数据');
            }
            
            // 执行删除
            AdminLog::whereIn('id', $ids)->delete();

            return success([], '删除成功，共删除' . $count . '条记录');
        } catch (\Throwable $e) {
            return error($e->getMessage());
        }
    }
}
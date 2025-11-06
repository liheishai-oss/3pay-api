<?php

namespace app\admin\controller\v1;

use app\exception\MyBusinessException;
use app\model\PaymentType;
use support\Request;
use support\Response;

/**
 * 支付类型管理控制器
 */
class PaymentTypeController
{
    /**
     * 列表查询
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $param = $request->all();
        $search = json_decode($param['search'] ?? '{}', true);

        // 处理嵌套的search对象
        if (isset($search['search']) && is_array($search['search'])) {
            $search = $search['search'];
        }

        // 构建查询
        $query = PaymentType::query();

        // 搜索条件
        if (!empty($search['product_name'])) {
            $query->where('product_name', 'like', "%" . trim($search['product_name']) . "%");
        }

        if (!empty($search['product_code'])) {
            $query->where('product_code', 'like', "%" . trim($search['product_code']) . "%");
        }

        if (isset($search['status']) && $search['status'] !== '') {
            $query->where('status', $search['status']);
        }

        // 分页获取数据
        $data = $query->orderBy('sort_order', 'desc')
            ->paginate($param['page_size'] ?? 10)
            ->toArray();

        return success($data);
    }

    /**
     * 详情
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function detail(Request $request, int $id): Response
    {
        $paymentType = PaymentType::find($id);
        
        if (!$paymentType) {
            throw new MyBusinessException('支付类型不存在');
        }

        return success($paymentType->toArray());
    }

    /**
     * 添加/编辑
     * @param Request $request
     * @return Response
     */
    public function store(Request $request): Response
    {
        // 权限验证：代理商（group_id=3）不允许添加/编辑
        $this->checkNotAgent($request);
        
        $param = $request->post();
        
        try {
            // 验证必填字段
            if (empty($param['product_name'])) {
                throw new MyBusinessException('产品名称不能为空');
            }
            
            if (empty($param['product_code'])) {
                throw new MyBusinessException('产品代码不能为空');
            }
            
            if (empty($param['class_name'])) {
                throw new MyBusinessException('PHP类名不能为空');
            }

            $isEdit = !empty($param['id']);

            if ($isEdit) {
                // 编辑
                $paymentType = PaymentType::find($param['id']);
                if (!$paymentType) {
                    throw new MyBusinessException('支付类型不存在');
                }
                
                // 检查产品代码是否重复（排除自己）
                $exists = PaymentType::where('product_code', $param['product_code'])
                    ->where('id', '!=', $param['id'])
                    ->exists();
                if ($exists) {
                    throw new MyBusinessException('产品代码已存在');
                }
                
                $paymentType->fill($param);
                $paymentType->save();
            } else {
                // 新增
                // 检查产品代码是否重复
                $exists = PaymentType::where('product_code', $param['product_code'])->exists();
                if ($exists) {
                    throw new MyBusinessException('产品代码已存在');
                }
                
                PaymentType::create($param);
            }

            return success([], $isEdit ? '编辑成功' : '创建成功');
        } catch (MyBusinessException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new MyBusinessException('系统异常：' . $e->getMessage());
        }
    }

    /**
     * 删除
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request): Response
    {
        // 权限验证：代理商（group_id=3）不允许删除
        $this->checkNotAgent($request);
        
        $ids = $request->post('ids');

        try {
            if (empty($ids) || !is_array($ids)) {
                throw new MyBusinessException('参数错误，缺少要删除的ID列表');
            }

            $count = PaymentType::whereIn('id', $ids)->count();
            
            if ($count === 0) {
                throw new MyBusinessException('未找到对应的支付类型记录');
            }

            PaymentType::whereIn('id', $ids)->delete();

            return success([], '删除成功');
        } catch (\Throwable $e) {
            throw new MyBusinessException('系统异常：' . $e->getMessage());
        }
    }

    /**
     * 状态切换
     * @param Request $request
     * @return Response
     */
    public function switch(Request $request): Response
    {
        // 权限验证：代理商（group_id=3）不允许切换状态
        $this->checkNotAgent($request);
        
        $id = $request->post('id');

        if (!$id) {
            throw new MyBusinessException('参数错误');
        }

        $paymentType = PaymentType::find($id);
        if (!$paymentType) {
            throw new MyBusinessException('支付类型不存在');
        }

        $paymentType->toggleStatus();

        return success([], '切换成功');
    }
    
    /**
     * 检查用户是否为代理商，代理商不允许执行此操作
     * @param Request $request
     * @throws MyBusinessException
     */
    private function checkNotAgent(Request $request): void
    {
        $userData = $request->userData ?? [];
        $userGroupId = $userData['user_group_id'] ?? 0;
        
        // 代理商管理组 group_id = 3
        if ($userGroupId == 3) {
            throw new MyBusinessException('代理商没有权限执行此操作');
        }
    }
}


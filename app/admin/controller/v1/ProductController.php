<?php

namespace app\admin\controller\v1;

use app\exception\MyBusinessException;
use app\model\Product;
use app\model\PaymentType;
use app\model\Agent;
use support\Request;
use support\Response;

/**
 * 产品管理控制器
 */
class ProductController
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

        $userData = $request->userData;
        $isAgent = $userData['is_agent'] ?? false;
        $agentId = $userData['agent_id'] ?? null;

        // 构建查询
        $query = Product::with(['agent', 'paymentType']);

        // 代理商只能查看自己的产品
        if ($isAgent && $agentId) {
            $query->where('agent_id', $agentId);
        }

        // 管理员可以按代理商筛选
        if (!$isAgent && !empty($search['agent_id'])) {
            $query->where('agent_id', $search['agent_id']);
        }

        // 搜索条件
        if (!empty($search['product_name'])) {
            $query->where('product_name', 'like', "%" . trim($search['product_name']) . "%");
        }

        if (!empty($search['product_code'])) {
            $query->where('product_code', 'like', "%" . trim($search['product_code']) . "%");
        }

        if (!empty($search['payment_type_id'])) {
            $query->where('payment_type_id', $search['payment_type_id']);
        }

        if (isset($search['status']) && $search['status'] !== '') {
            $query->where('status', $search['status']);
        }

        // 分页获取数据
        $data = $query->orderBy('id', 'desc')
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
        $userData = $request->userData;
        $isAgent = $userData['is_agent'] ?? false;
        $agentId = $userData['agent_id'] ?? null;

        $query = Product::with(['agent', 'paymentType']);

        // 代理商只能查看自己的产品
        if ($isAgent && $agentId) {
            $query->where('agent_id', $agentId);
        }

        $product = $query->find($id);
        
        if (!$product) {
            throw new MyBusinessException('产品不存在或无权访问');
        }

        return success($product->toArray());
    }

    /**
     * 添加/编辑
     * @param Request $request
     * @return Response
     */
    public function store(Request $request): Response
    {
        $param = $request->post();
        $userData = $request->userData;
        $isAgent = $userData['is_agent'] ?? false;
        $agentId = $userData['agent_id'] ?? null;
        
        try {
            // 验证必填字段
            if (empty($param['product_name'])) {
                throw new MyBusinessException('产品名称不能为空');
            }
            
            if (empty($param['payment_type_id'])) {
                throw new MyBusinessException('请选择支付类型');
            }

            // 验证支付类型是否存在
            $paymentType = PaymentType::find($param['payment_type_id']);
            if (!$paymentType) {
                throw new MyBusinessException('支付类型不存在');
            }

            $isEdit = !empty($param['id']);

            // 如果是代理商，自动设置agent_id
            if ($isAgent) {
                if (!$agentId) {
                    throw new MyBusinessException('代理商信息不完整');
                }
                $param['agent_id'] = $agentId;
            } else {
                // 管理员必须选择代理商
                if (empty($param['agent_id'])) {
                    throw new MyBusinessException('请选择代理商');
                }
                
                // 验证代理商是否存在
                $agent = Agent::find($param['agent_id']);
                if (!$agent) {
                    throw new MyBusinessException('代理商不存在');
                }
            }

            if ($isEdit) {
                // 编辑
                $query = Product::query();
                
                // 代理商只能编辑自己的产品
                if ($isAgent && $agentId) {
                    $query->where('agent_id', $agentId);
                }
                
                $product = $query->find($param['id']);
                if (!$product) {
                    throw new MyBusinessException('产品不存在或无权编辑');
                }
                
                $product->product_name = $param['product_name'];
                $product->payment_type_id = $param['payment_type_id'];
                $product->status = $param['status'] ?? 1;
                $product->remark = $param['remark'] ?? null;
                $product->save();
            } else {
                // 新增 - 自动生成产品编号
                $productCode = Product::generateProductCode();
                
                Product::create([
                    'agent_id' => $param['agent_id'],
                    'payment_type_id' => $param['payment_type_id'],
                    'product_name' => $param['product_name'],
                    'product_code' => $productCode,
                    'status' => $param['status'] ?? 1,
                    'remark' => $param['remark'] ?? null,
                ]);
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
        $ids = $request->post('ids');
        $userData = $request->userData;
        $isAgent = $userData['is_agent'] ?? false;
        $agentId = $userData['agent_id'] ?? null;

        try {
            if (empty($ids) || !is_array($ids)) {
                throw new MyBusinessException('参数错误，缺少要删除的ID列表');
            }

            $query = Product::whereIn('id', $ids);
            
            // 代理商只能删除自己的产品
            if ($isAgent && $agentId) {
                $query->where('agent_id', $agentId);
            }

            $count = $query->count();
            
            if ($count === 0) {
                throw new MyBusinessException('未找到对应的产品记录或无权删除');
            }

            $query->delete();

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
        $id = $request->post('id');
        $userData = $request->userData;
        $isAgent = $userData['is_agent'] ?? false;
        $agentId = $userData['agent_id'] ?? null;

        if (!$id) {
            throw new MyBusinessException('参数错误');
        }

        $query = Product::query();
        
        // 代理商只能切换自己的产品状态
        if ($isAgent && $agentId) {
            $query->where('agent_id', $agentId);
        }

        $product = $query->find($id);
        if (!$product) {
            throw new MyBusinessException('产品不存在或无权操作');
        }

        $product->toggleStatus();

        return success([], '切换成功');
    }

    /**
     * 获取支付类型列表（给选择用）
     * @param Request $request
     * @return Response
     */
    public function getPaymentTypeList(Request $request): Response
    {
        $paymentTypes = PaymentType::where('status', PaymentType::STATUS_ENABLED)
            ->select(['id', 'product_name', 'class_name'])
            ->orderBy('sort_order', 'asc')
            ->get()
            ->toArray();

        return success($paymentTypes);
    }

    /**
     * 获取代理商列表（给管理员选择用）
     * @param Request $request
     * @return Response
     */
    public function getAgentList(Request $request): Response
    {
        $agents = Agent::where('status', Agent::STATUS_ENABLED)
            ->select(['id', 'agent_name'])
            ->get()
            ->toArray();

        return success($agents);
    }
}



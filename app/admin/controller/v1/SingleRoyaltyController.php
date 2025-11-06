<?php

namespace app\admin\controller\v1;

use app\model\SingleRoyalty;
use app\model\Agent;
use support\Request;
use support\Db;

class SingleRoyaltyController
{
    /**
     * 单笔分账列表
     */
    public function index(Request $request)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;
        
        $page = $request->get('page', 1);
        $limit = $request->get('limit', 20);
        $payeeName = $request->get('payee_name', '');
        $payeeAccount = $request->get('payee_account', '');
        $status = $request->get('status', '');
        $agentId = $request->get('agent_id', '');

        $query = SingleRoyalty::with(['agent']);

        // 代理商只能看自己的数据
        if ($isAgent) {
            $query->where('agent_id', $userData['agent_id']);
        } elseif ($agentId) {
            $query->where('agent_id', $agentId);
        }

        if ($payeeName) {
            $query->where('payee_name', 'like', '%' . $payeeName . '%');
        }

        if ($payeeAccount) {
            $query->where('payee_account', 'like', '%' . $payeeAccount . '%');
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        $total = $query->count();
        $list = $query->orderBy('id', 'desc')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get()
            ->toArray();

        return success([
            'list' => $list,
            'total' => $total,
            'page' => (int)$page,
            'limit' => (int)$limit
        ]);
    }

    /**
     * 单笔分账详情
     */
    public function detail(Request $request, $id)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;

        $query = SingleRoyalty::with(['agent']);

        // 代理商只能看自己的数据
        if ($isAgent) {
            $query->where('agent_id', $userData['agent_id']);
        }

        $singleRoyalty = $query->find($id);

        if (!$singleRoyalty) {
            return error('单笔分账不存在');
        }

        return success($singleRoyalty->toArray());
    }

    /**
     * 添加/编辑单笔分账
     */
    public function store(Request $request)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;
        
        $param = $request->post();
        $id = $param['id'] ?? 0;

        // 验证必填字段
        if (empty($param['payee_name'])) {
            return error('请输入收款人姓名');
        }
        if (empty($param['payee_account'])) {
            return error('请输入收款人账号');
        }
        if (empty($param['payee_user_id'])) {
            return error('请输入收款人用户ID');
        }

        // 代理商自动使用自己的agent_id
        if ($isAgent) {
            $param['agent_id'] = $userData['agent_id'];
        } else {
            // 管理员必须选择代理商
            if (empty($param['agent_id'])) {
                return error('请选择代理商');
            }
        }

        try {
            Db::beginTransaction();

            if ($id > 0) {
                // 编辑
                $query = SingleRoyalty::where('id', $id);
                
                // 代理商只能编辑自己的数据
                if ($isAgent) {
                    $query->where('agent_id', $userData['agent_id']);
                }
                
                $singleRoyalty = $query->first();
                
                if (!$singleRoyalty) {
                    Db::rollBack();
                    return error('单笔分账不存在或无权限操作');
                }

                $singleRoyalty->payee_name = $param['payee_name'];
                $singleRoyalty->payee_account = $param['payee_account'];
                $singleRoyalty->payee_user_id = $param['payee_user_id'];
                $singleRoyalty->status = $param['status'] ?? 1;
                $singleRoyalty->save();

                Db::commit();
                return success([], '编辑成功');
            } else {
                // 新增
                SingleRoyalty::create([
                    'agent_id' => $param['agent_id'],
                    'payee_name' => $param['payee_name'],
                    'payee_account' => $param['payee_account'],
                    'payee_user_id' => $param['payee_user_id'],
                    'status' => $param['status'] ?? 1,
                ]);

                Db::commit();
                return success([], '添加成功');
            }
        } catch (\Exception $e) {
            Db::rollBack();
            return error('操作失败：' . $e->getMessage());
        }
    }

    /**
     * 删除单笔分账
     */
    public function destroy(Request $request)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;
        
        $id = $request->post('id');

        if (!$id) {
            return error('参数错误');
        }

        $query = SingleRoyalty::where('id', $id);
        
        // 代理商只能删除自己的数据
        if ($isAgent) {
            $query->where('agent_id', $userData['agent_id']);
        }
        
        $singleRoyalty = $query->first();

        if (!$singleRoyalty) {
            return error('单笔分账不存在或无权限操作');
        }

        $singleRoyalty->delete();

        return success([], '删除成功');
    }

    /**
     * 切换状态
     */
    public function switch(Request $request)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;
        
        $id = $request->post('id');
        $status = $request->post('status');

        if (!$id || !isset($status)) {
            return error('参数错误');
        }

        $query = SingleRoyalty::where('id', $id);
        
        // 代理商只能切换自己的数据
        if ($isAgent) {
            $query->where('agent_id', $userData['agent_id']);
        }
        
        $singleRoyalty = $query->first();

        if (!$singleRoyalty) {
            return error('单笔分账不存在或无权限操作');
        }

        $singleRoyalty->status = $status;
        $singleRoyalty->save();

        return success([], '操作成功');
    }

    /**
     * 获取代理商列表（供管理员选择）
     */
    public function getAgentList(Request $request)
    {
        $list = Agent::where('status', Agent::STATUS_ENABLED)
            ->select('id', 'agent_name')
            ->orderBy('id', 'desc')
            ->get()
            ->toArray();

        return success($list);
    }
}



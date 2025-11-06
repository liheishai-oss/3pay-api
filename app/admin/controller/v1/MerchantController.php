<?php

namespace app\admin\controller\v1;

use app\model\Merchant;
use app\model\Agent;
use support\Request;
use support\Db;
use support\Redis;
use support\Log;

class MerchantController
{
    /**
     * 商户列表
     */
    public function index(Request $request)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;
        
        $page = $request->get('page', 1);
        $limit = $request->get('limit', 20);
        $merchantName = $request->get('merchant_name', '');
        $status = $request->get('status', '');
        $agentId = $request->get('agent_id', '');

        $query = Merchant::with(['agent']);

        // 代理商只能看自己的数据
        if ($isAgent) {
            $query->where('agent_id', $userData['agent_id']);
        } elseif ($agentId) {
            $query->where('agent_id', $agentId);
        }

        if ($merchantName) {
            $query->where('merchant_name', 'like', '%' . $merchantName . '%');
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
     * 商户详情
     */
    public function detail(Request $request, $id)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;

        $query = Merchant::with(['agent']);

        // 代理商只能看自己的数据
        if ($isAgent) {
            $query->where('agent_id', $userData['agent_id']);
        }

        $merchant = $query->find($id);

        if (!$merchant) {
            return error('商户不存在');
        }

        return success($merchant->toArray());
    }

    /**
     * 添加/编辑商户
     */
    public function store(Request $request)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;
        
        $param = $request->post();
        $id = $param['id'] ?? 0;

        // 验证必填字段
        if (empty($param['merchant_name'])) {
            return error('请输入商户名称');
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
                $query = Merchant::where('id', $id);
                
                // 代理商只能编辑自己的数据
                if ($isAgent) {
                    $query->where('agent_id', $userData['agent_id']);
                }
                
                $merchant = $query->first();
                
                if (!$merchant) {
                    Db::rollBack();
                    return error('商户不存在或无权限操作');
                }

                $merchant->merchant_name = $param['merchant_name'];
                $merchant->contact_email = $param['contact_email'] ?? null;
                $merchant->notify_url = $param['notify_url'] ?? null;
                $merchant->return_url = $param['return_url'] ?? null;
                $merchant->ip_whitelist = $param['ip_whitelist'] ?? null;
                $merchant->status = $param['status'] ?? 1;
                $merchant->remark = $param['remark'] ?? null;
                $merchant->save();

                Db::commit();
                return success([], '编辑成功');
            } else {
                // 新增 - 生成API密钥
                $apiKey = Merchant::generateApiKey();
                $apiSecret = Merchant::generateApiSecret();

                Merchant::create([
                    'agent_id' => $param['agent_id'],
                    'merchant_name' => $param['merchant_name'],
                    'contact_email' => $param['contact_email'] ?? null,
                    'api_key' => $apiKey,
                    'api_secret' => $apiSecret,
                    'notify_url' => $param['notify_url'] ?? null,
                    'return_url' => $param['return_url'] ?? null,
                    'ip_whitelist' => $param['ip_whitelist'] ?? null,
                    'status' => $param['status'] ?? 1,
                    'remark' => $param['remark'] ?? null,
                ]);

                Db::commit();
                return success([
                    'api_key' => $apiKey,
                    'api_secret' => $apiSecret
                ], '添加成功，请妥善保管API密钥信息');
            }
        } catch (\Exception $e) {
            Db::rollBack();
            return error('操作失败：' . $e->getMessage());
        }
    }

    /**
     * 删除商户
     */
    public function destroy(Request $request)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;
        
        $id = $request->post('id');

        if (!$id) {
            return error('参数错误');
        }

        $query = Merchant::where('id', $id);
        
        // 代理商只能删除自己的数据
        if ($isAgent) {
            $query->where('agent_id', $userData['agent_id']);
        }
        
        $merchant = $query->first();

        if (!$merchant) {
            return error('商户不存在或无权限操作');
        }

        $merchant->delete();

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

        $query = Merchant::where('id', $id);
        
        // 代理商只能切换自己的数据
        if ($isAgent) {
            $query->where('agent_id', $userData['agent_id']);
        }
        
        $merchant = $query->first();

        if (!$merchant) {
            return error('商户不存在或无权限操作');
        }

        $merchant->status = $status;
        $merchant->save();

        return success([], '操作成功');
    }

    /**
     * 重置API密钥
     */
    public function resetApiKey(Request $request)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;
        
        $id = $request->post('id');

        if (!$id) {
            return error('参数错误');
        }

        $query = Merchant::where('id', $id);
        
        // 代理商只能重置自己的数据
        if ($isAgent) {
            $query->where('agent_id', $userData['agent_id']);
        }
        
        $merchant = $query->first();

        if (!$merchant) {
            return error('商户不存在或无权限操作');
        }

        // 生成新的密钥
        $apiKey = Merchant::generateApiKey();
        $apiSecret = Merchant::generateApiSecret();

        $merchant->api_key = $apiKey;
        $merchant->api_secret = $apiSecret;
        $merchant->save();

        return success([
            'api_key' => $apiKey,
            'api_secret' => $apiSecret
        ], '重置成功，请妥善保管新的API密钥');
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

    /**
     * 解除商户熔断
     */
    public function clearCircuit(Request $request)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;
        
        $merchantId = $request->post('merchant_id');

        if (!$merchantId) {
            return error('商户ID不能为空');
        }

        $query = Merchant::where('id', $merchantId);
        
        // 代理商只能解除自己的商户熔断
        if ($isAgent) {
            $query->where('agent_id', $userData['agent_id']);
        }
        
        $merchant = $query->first();

        if (!$merchant) {
            return error('商户不存在或无权限操作');
        }

        try {
            $merchantKey = (string)$merchantId;
            $circuitKey = "notify:circuit:{$merchantKey}";
            $timeoutCntKey = "notify:timeout:cnt:{$merchantKey}";
            $badRespCntKey = "notify:badresp:cnt:{$merchantKey}";
            
            // 清除熔断相关键
            Redis::del($circuitKey);
            Redis::del($timeoutCntKey);
            Redis::del($badRespCntKey);
            
            Log::info('商户熔断已解除', [
                'merchant_id' => $merchantId,
                'operator' => $userData['admin_id'] ?? $userData['id'] ?? 'unknown',
                'is_agent' => $isAgent
            ]);
            
            return success([], '熔断已成功解除');
        } catch (\Exception $e) {
            Log::error('解除商户熔断失败', [
                'merchant_id' => $merchantId,
                'error' => $e->getMessage()
            ]);
            return error('解除熔断失败：' . $e->getMessage());
        }
    }

    /**
     * 获取商户熔断状态
     */
    public function getCircuitStatus(Request $request, $id)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;

        $query = Merchant::where('id', $id);
        
        // 代理商只能查看自己的商户
        if ($isAgent) {
            $query->where('agent_id', $userData['agent_id']);
        }
        
        $merchant = $query->first();

        if (!$merchant) {
            return error('商户不存在或无权限操作');
        }

        try {
            $merchantKey = (string)$id;
            $circuitKey = "notify:circuit:{$merchantKey}";
            $timeoutCntKey = "notify:timeout:cnt:{$merchantKey}";
            $badRespCntKey = "notify:badresp:cnt:{$merchantKey}";
            
            $circuitUntil = (int)(Redis::get($circuitKey) ?: 0);
            $timeoutCount = (int)(Redis::get($timeoutCntKey) ?: 0);
            $badRespCount = (int)(Redis::get($badRespCntKey) ?: 0);
            
            $isCircuitOpen = $circuitUntil > time();
            $remainSeconds = $isCircuitOpen ? ($circuitUntil - time()) : 0;
            
            return success([
                'is_circuit_open' => $isCircuitOpen,
                'remain_seconds' => $remainSeconds,
                'timeout_count' => $timeoutCount,
                'bad_resp_count' => $badRespCount,
                'circuit_until' => $circuitUntil > 0 ? date('Y-m-d H:i:s', $circuitUntil) : null
            ]);
        } catch (\Exception $e) {
            Log::error('获取商户熔断状态失败', [
                'merchant_id' => $id,
                'error' => $e->getMessage()
            ]);
            return error('获取熔断状态失败：' . $e->getMessage());
        }
    }
}


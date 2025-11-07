<?php

namespace app\admin\controller\v1;

use app\model\Merchant;
use app\model\Agent;
use app\model\Admin;
use app\exception\MyBusinessException;
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
        $limit = $request->get('page_size', $request->get('limit', 20));
        
        // 支持search参数（JSON格式）
        $searchJson = $request->get('search', '');
        $searchParams = [];
        if ($searchJson) {
            $searchParams = json_decode($searchJson, true) ?: [];
        }
        
        // 优先从search参数中获取，否则从直接参数获取
        $merchantName = $searchParams['merchant_name'] ?? $request->get('merchant_name', '');
        $status = $searchParams['status'] ?? $request->get('status', '');
        $agentId = $searchParams['agent_id'] ?? $request->get('agent_id', '');
        
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
            'data' => $list,
            'total' => $total,
            'current_page' => (int)$page,
            'per_page' => (int)$limit,
            'admin' => false
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
        
        // 调试信息
        Log::info('创建商户 - 用户信息', [
            'userData' => $userData,
            'isAgent' => $isAgent,
            'agent_id' => $userData['agent_id'] ?? 'null'
        ]);
        
        $param = $request->post();
        $id = $param['id'] ?? 0;

        // 验证必填字段
        if (empty($param['merchant_name'])) {
            return error('请输入商户名称');
        }

        // 新增时验证账号
        if ($id == 0) {
            if (empty($param['username'])) {
                return error('请输入账号');
            }
            
            // 验证账号格式（只能包含字母、数字和下划线）
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $param['username'])) {
                return error('账号只能包含字母、数字和下划线');
            }
            
            // 验证账号长度
            if (strlen($param['username']) < 3 || strlen($param['username']) > 50) {
                return error('账号长度必须在3到50个字符之间');
            }
            
            // 检查用户名是否已存在
            if (Admin::where('username', $param['username'])->exists()) {
                return error('账号已存在，请使用其他账号');
            }
        }

        // 处理 agent_id：从 userData（登录信息）中获取，而不是依赖前端传递
        // userData 由中间件 Auth 注入，每次请求都会包含完整的用户信息
        if ($isAgent) {
            // 代理商：从 userData 中获取自己的 agent_id（中间件已注入）
            // 完全忽略前端传递的 agent_id，确保安全性
            $agentId = $userData['agent_id'] ?? null;
            
            // 如果 userData 中没有 agent_id，尝试从数据库查询
            if (empty($agentId)) {
                $agent = Agent::where('admin_id', $userData['admin_id'])->first();
                if ($agent) {
                    $agentId = $agent->id;
                    Log::info('从数据库查询到 agent_id', ['admin_id' => $userData['admin_id'], 'agent_id' => $agentId]);
                }
            }
            
            if (empty($agentId)) {
                Log::error('无法获取代理商ID', [
                    'userData' => $userData,
                    'admin_id' => $userData['admin_id'] ?? 'null'
                ]);
                return error('代理商信息错误，无法从登录信息中获取代理商ID，请检查代理商账号配置');
            }
            $param['agent_id'] = (int)$agentId;
        } else {
            // 管理员：必须从表单中选择代理商
            if (empty($param['agent_id'])) {
                return error('请选择代理商');
            }
            $param['agent_id'] = (int)$param['agent_id'];
        }

        // 最终验证 agent_id 是否存在且有效
        if (empty($param['agent_id']) || $param['agent_id'] <= 0) {
            return error('代理商ID无效');
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
                $merchant->ip_whitelist = $param['ip_whitelist'] ?? null;
                $merchant->status = $param['status'] ?? 1;
                $merchant->remark = $param['remark'] ?? null;
                $merchant->save();

                // 同步更新关联的admin账号状态和昵称
                if ($merchant->admin_id) {
                    $admin = Admin::find($merchant->admin_id);
                    if ($admin) {
                        $admin->nickname = $param['merchant_name'];
                        $admin->status = $param['status'] ?? 1;
                        $admin->save();
                    }
                }

                Db::commit();
                return success([], '编辑成功');
            } else {
                // 新增 - 生成API密钥
                $apiKey = Merchant::generateApiKey();
                $apiSecret = Merchant::generateApiSecret();

                // 使用前端传入的账号
                $username = $param['username'];

                // 创建admin管理员账号，归属于商户管理组（group_id=4）
                $admin = new Admin();
                $admin->username = $username;
                $admin->nickname = $param['merchant_name']; // 商户名称作为昵称
                $admin->password = password_hash('123456', PASSWORD_DEFAULT); // 默认密码123456
                $admin->group_id = 4; // 商户管理组
                $admin->status = $param['status'] ?? 1;
                $admin->is_first_login = 1; // 首次登录需要修改密码
                $admin->save();

                // 创建商户，关联admin_id和username
                Merchant::create([
                    'agent_id' => $param['agent_id'],
                    'admin_id' => $admin->id,
                    'username' => $username,
                    'merchant_name' => $param['merchant_name'],
                    'api_key' => $apiKey,
                    'api_secret' => $apiSecret,
                    'ip_whitelist' => $param['ip_whitelist'] ?? null,
                    'status' => $param['status'] ?? 1,
                    'remark' => $param['remark'] ?? null,
                ]);

                Db::commit();
                return success([
                    'api_key' => $apiKey,
                    'api_secret' => $apiSecret,
                    'username' => $username
                ], '添加成功，请妥善保管API密钥信息。商户账号：' . $username . '，默认密码：123456，首次登录需修改密码');
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

        try {
            Db::beginTransaction();

            // 删除关联的admin账号
            if ($merchant->admin_id) {
                $admin = Admin::find($merchant->admin_id);
                if ($admin) {
                    $admin->delete();
                }
            }

            // 删除商户
            $merchant->delete();

            Db::commit();
            return success([], '删除成功');
        } catch (\Exception $e) {
            Db::rollBack();
            return error('删除失败：' . $e->getMessage());
        }
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

        // 同步更新关联的admin账号状态
        if ($merchant->admin_id) {
            $admin = Admin::find($merchant->admin_id);
            if ($admin) {
                $admin->status = $status;
                $admin->save();
            }
        }

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


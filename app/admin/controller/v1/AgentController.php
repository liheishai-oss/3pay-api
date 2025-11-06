<?php

namespace app\admin\controller\v1;

use app\exception\MyBusinessException;
use app\model\Agent;
use app\model\Admin;
use support\Db;
use support\Request;
use support\Response;

/**
 * 代理商管理控制器
 */
class AgentController
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

        // 构建查询，关联admin表获取用户名
        $query = Agent::with('admin:id,username,nickname');

        // 搜索条件
        if (!empty($search['agent_name'])) {
            $query->where('agent_name', 'like', "%" . trim($search['agent_name']) . "%");
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
        $agent = Agent::with('admin')->find($id);
        
        if (!$agent) {
            throw new MyBusinessException('代理商不存在');
        }

        return success($agent->toArray());
    }

    /**
     * 添加/编辑
     * @param Request $request
     * @return Response
     */
    public function store(Request $request): Response
    {
        $param = $request->post();
        
        Db::beginTransaction();
        try {
            // 验证必填字段
            if (empty($param['agent_name'])) {
                throw new MyBusinessException('代理商名称不能为空');
            }

            $isEdit = !empty($param['id']);

            if ($isEdit) {
                // 编辑
                $agent = Agent::find($param['id']);
                if (!$agent) {
                    throw new MyBusinessException('代理商不存在');
                }
                
                // 更新代理商信息
                $agent->fill($param);
                $agent->save();
                
                // 联动更新admin管理员
                $admin = Admin::find($agent->admin_id);
                if ($admin) {
                    // 代理商名称对应管理员昵称
                    $admin->nickname = $param['agent_name'];
                    // 编辑时不修改密码
                    $admin->status = $agent->status;
                    $admin->save();
                }
                
                Db::commit();
                return success([], '编辑成功');
            } else {
                // 新增
                // 验证用户名
                if (empty($param['username'])) {
                    throw new MyBusinessException('用户名不能为空');
                }
                
                // 检查用户名是否已存在
                $existsAdmin = Admin::where('username', $param['username'])->exists();
                if ($existsAdmin) {
                    throw new MyBusinessException('用户名已存在');
                }
                
                // 创建admin管理员，归属于代理商管理组（group_id=3）
                $admin = new Admin();
                $admin->username = $param['username'];
                $admin->nickname = $param['agent_name']; // 代理商名称对应管理员昵称
                $admin->password = password_hash('123456', PASSWORD_DEFAULT); // 默认密码123456
                $admin->group_id = 3; // 代理商管理组
                $admin->status = $param['status'] ?? 1;
                $admin->save();
                
                // 创建代理商，关联admin_id
                $param['admin_id'] = $admin->id;
                Agent::create($param);
                
                Db::commit();
                return success([], '创建成功，默认密码：123456');
            }
        } catch (MyBusinessException $e) {
            Db::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            Db::rollBack();
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

        Db::beginTransaction();
        try {
            if (empty($ids) || !is_array($ids)) {
                throw new MyBusinessException('参数错误，缺少要删除的ID列表');
            }

            // 查找所有代理商
            $agents = Agent::whereIn('id', $ids)->get();
            
            if ($agents->isEmpty()) {
                throw new MyBusinessException('未找到对应的代理商记录');
            }

            // 收集所有关联的admin_id
            $adminIds = $agents->pluck('admin_id')->toArray();
            
            // 删除代理商
            Agent::whereIn('id', $ids)->delete();
            
            // 联动删除对应的admin管理员
            if (!empty($adminIds)) {
                Admin::whereIn('id', $adminIds)->delete();
            }

            Db::commit();
            return success([], '删除成功');
        } catch (\Throwable $e) {
            Db::rollBack();
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

        Db::beginTransaction();
        try {
            if (!$id) {
                throw new MyBusinessException('参数错误');
            }

            $agent = Agent::find($id);
            if (!$agent) {
                throw new MyBusinessException('代理商不存在');
            }

            // 切换代理商状态
            $agent->toggleStatus();
            
            // 联动更新admin管理员状态
            $admin = Admin::find($agent->admin_id);
            if ($admin) {
                $admin->status = $agent->status;
                $admin->save();
            }

            Db::commit();
            return success([], '切换成功');
        } catch (\Throwable $e) {
            Db::rollBack();
            throw new MyBusinessException('系统异常：' . $e->getMessage());
        }
    }
}


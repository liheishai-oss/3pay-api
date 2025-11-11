<?php
namespace app\admin\controller\v1;

use app\common;
use app\common\config\OrderConfig;
use app\exception\MyBusinessException;
use app\model\Admin;
use app\model\AdminRule;
use app\model\PermissionGroup;
use app\service\Admin as AdminService;
use app\service\Login;
use Respect\Validation\Exceptions\ValidationException;
use Respect\Validation\Validator;
use support\Log;
use support\Redis;
use support\Request;
use support\Response;
use Throwable;

class AdminController
{

   public function index(Request $request): Response
{
    $param = $request->all();
    $search = json_decode($param['search'] ?? '{}', true);

    // 处理嵌套的search对象
    if (isset($search['search']) && is_array($search['search'])) {
        $search = $search['search'];
    }

    // 检查用户数据
    if (!isset($request->userData) || !is_array($request->userData)) {
        return error('用户未登录或登录信息无效', 401);
    }
    
    $user_group_id = $request->userData['user_group_id'] ?? null;
    
    // 构建基础查询
    $query = Admin::with('group');
    
    // 超级管理员（group_id=1）可以看到所有管理员
    // 普通管理员只能看到自己组及子组的管理员
    if ($user_group_id != 1) {
        $group_id = $request->userData['group_id'] ?? null;
        if (!$group_id) {
            return error('用户组信息不完整', 401);
        }
        $query->whereIn('group_id', $group_id);
    }

    // 添加搜索条件
    if (!empty($search['nickname'])) {
        $query->where('nickname', 'like', "%" . trim($search['nickname']) . "%");
    }

    if (!empty($search['username'])) {
        $query->where('username', 'like', "%" . trim($search['username']) . "%");
    }

    // 分页获取数据
    $data = $query->orderBy('id', 'desc')->paginate($param['page_size'] ?? 10)->toArray();

    return success($data);
}



    public function menu(Request $request): Response
    {
            // 检查用户数据是否存在
            if (!isset($request->userData) || !is_array($request->userData)) {
                return error('用户未登录或登录信息无效', 401);
            }
            
            $userId  = $request->userData['admin_id'] ?? null;
            $groupId = $request->userData['user_group_id'] ?? null;
            
            if (!$userId || !$groupId) {
                return error('用户信息不完整', 401);
            }

            // 商户功能已移除
            $isMerchantAdmin = false;
            
            Log::info('获取菜单数据', [
                'user_id' => $userId,
                'group_id' => $groupId,
                'is_super_admin' => $userId == Common::ADMIN_USER_ID,
                'is_merchant_admin' => $isMerchantAdmin
            ]);

            // 直接从数据库获取菜单数据，不使用缓存
            $baseQuery = AdminRule::where([
                'is_menu' => 1,
                'status'  => 1
            ])->select(['id', 'title', 'icon', 'path', 'parent_id']);
            
            if ($userId == Common::ADMIN_USER_ID) {
                Log::info('超级管理员，获取所有菜单');
            } else {
                // 所有非超级管理员（包括商户管理员）都根据用户组权限获取菜单
                Log::info('根据用户组权限获取菜单', [
                    'user_type' => $isMerchantAdmin ? 'merchant_admin' : 'normal_admin'
                ]);
                
                // 获取分组权限id
                $ruleIds = PermissionGroup::where('permission_group_id', $groupId)
                    ->pluck('permission_id')->toArray();

                Log::info('用户组权限ID', [
                    'group_id' => $groupId,
                    'rule_ids' => $ruleIds
                ]);

                // 获取这些权限的所有父级（包括多级）
                $allRuleIds = $this->getRuleWithParents($ruleIds);

                Log::info('包含父级的权限ID', [
                    'original_rule_ids' => $ruleIds,
                    'all_rule_ids' => $allRuleIds
                ]);

                $baseQuery->whereIn('id', $allRuleIds);
            }

            $menus = $baseQuery->orderBy('weight', 'desc')->get()->toArray();
            
            Log::info('查询到的菜单数据', [
                'menus_count' => count($menus),
                'menus' => $menus
            ]);

            $tree = $this->buildMenuTree($menus);

            Log::info('构建的菜单树', [
                'tree_count' => count($tree),
                'tree' => $tree
            ]);

            return success($tree);
    }
private function getRuleWithParents(array $ruleIds): array
{
    $allIds = $ruleIds;
    $parentIds = AdminRule::whereIn('id', $ruleIds)
        ->pluck('parent_id')
        ->unique()
        ->filter(fn($id) => $id != 0)
        ->values()
        ->all();

    while (!empty($parentIds)) {
        $newParents = AdminRule::whereIn('id', $parentIds)
            ->pluck('parent_id')
            ->unique()
            ->filter(fn($id) => $id != 0 && !in_array($id, $allIds))
            ->values()
            ->all();

        $allIds = array_merge($allIds, $parentIds);
        $parentIds = $newParents;
    }

    return array_unique($allIds);
}

    private function buildMenuTree(array $menus, int $parentId = 0): array
    {
        $tree = [];
        foreach ($menus as $menu) {
            if ($menu['parent_id'] == $parentId) {
                $children = $this->buildMenuTree($menus, $menu['id']);
                if (!empty($children)) {
                    $menu['children'] = $children;
                }else{
                    $menu['children'] = [];
                }
                $tree[] = $menu;
            }
        }
        return $tree;
    }
    public function store(Request $request): Response
    {
        try {
            $param = $request->post();

            $isEdit = !empty($param['id']);

            // 验证参数（控制器层只负责校验）
            $rules = [
                'username' => Validator::notEmpty()->setName('用户名'),
            ];
            if (!$isEdit) {
                $rules['password'] = Validator::notEmpty()->setName('密码');
            }

            Validator::input($param, $rules);

            // 调用服务处理创建/编辑
            $service = new AdminService();
            $service->save($param);

            return success([], $isEdit ? '编辑成功' : '创建成功');
        } catch (ValidationException $e) {
            throw new MyBusinessException($e->getMessages());
        } catch (\Throwable $e) {
            throw new MyBusinessException('系统异常：' . $e->getMessage());
        }
    }
    public function detail(Request $request, int $id): Response
    {
        try {
            // 查找分组基础信息
            $admin = Admin::find($id);
            if (!$admin) {
                throw new MyBusinessException('分组不存在');
            }

            // 拼接返回数据
            $data = $admin->toArray();
            return success($data);
        } catch (\Throwable $e) {
            throw new MyBusinessException('系统异常：' . $e->getMessage());
        }
    }
    public function destroy(Request $request): Response
    {
        $ids = $request->post('ids');

        try {
            if (empty($ids) || !is_array($ids)) {
                throw new MyBusinessException('参数错误，缺少要删除的ID列表');
            }

            // 查找所有匹配的管理员
            $admins = Admin::whereIn('id', $ids)->get();

            if ($admins->isEmpty()) {
                throw new MyBusinessException('未找到对应的管理员记录');
            }

            // 执行批量删除
            Admin::whereIn('id', $ids)->delete();
            // 商户功能已移除

            return success([], '删除成功');
        } catch (\Throwable $e) {
            throw new MyBusinessException('系统异常：' . $e->getMessage());
        }
    }
    public function switch(Request $request): Response
    {
        $id = $request->post('id');

        if (!$id) {
            throw new MyBusinessException('参数错误');
        }

        $admin = Admin::find($id);
        if (!$admin) {
            throw new MyBusinessException('管理员不存在');
        }

        // 切换状态
        $admin->status = $admin->status == 1 ? 0 : 1;
        $admin->save();
        // 商户功能已移除
        
        return success([],'切换成功');
    }

    public function info(Request $request)
    {
        $userData = $request->userData;
        
        // 商户功能已移除
        $userData['is_merchant_admin'] = false;
        
        // 添加代理商标识和代理商ID（代理商管理组 group_id = 3）
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;
        $userData['is_agent'] = $isAgent;
        
        // 如果是代理商，确保有 agent_id
        if ($isAgent) {
            // 如果 userData 中没有 agent_id，从数据库查询
            if (empty($userData['agent_id'])) {
                $agent = \app\model\Agent::where('admin_id', $userData['admin_id'])->first();
                $userData['agent_id'] = $agent ? $agent->id : null;
            }
        } else {
            $userData['agent_id'] = $userData['agent_id'] ?? null;
        }
        
        return success($userData);
    }


}


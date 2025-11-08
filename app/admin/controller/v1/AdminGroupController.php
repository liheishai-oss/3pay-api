<?php

namespace app\admin\controller\v1;

use app\exception\MyBusinessException;
use app\model\PermissionGroup;
use app\model\RoleGroup;
use app\service\AdminGroup;
use Respect\Validation\Exceptions\ValidationException;
use Respect\Validation\Validator;
use support\Db;
use support\Request;
use support\Response;

class AdminGroupController
{
    public function index(Request $request): Response
    {
        $param = $request->all();
        $groupId = $request->userData['user_group_id'] ?? 0;

        $query = RoleGroup::query();

        if ($groupId != 1) {
            $query->where('id', $request->userData['user_group_id']);
        }else{
            $query->where('parent_id', 0);
        }
        $list = $query->paginate($param['page_size'])
            ->toArray();
        if(!empty($list['data'])){
            foreach ($list['data'] as $key => &$value) {
                $value['children'] = $this->getChildren($value['id']);
                if(!empty($value['children'])){
                    $value['has_children'] = true;
                }

            }
            unset($value);
        }
        return success($list);
    }

    public function dropdown(Request $request,int $group_id = 0): Response
    {
        $query = RoleGroup::query();
        if ($group_id != 1) {
            $query->where('id', $request->userData['user_group_id']);
        }else{
            $query->where('parent_id', 0);
        }

        $data = $query->select(['id','name'])->get()->map(function($item) {
            $item->children =  RoleGroup::where('parent_id',$item->id)->select(['id','name'])->get()->map(function($item) {
                $item->children =  RoleGroup::where('parent_id',$item->id)->select(['id','name'])->get();
                return $item;
            });
            return $item;
        })->toArray();
        return success($data);
    }

    public function store(Request $request): Response
    {
        try {
            $param = $request->post(); // 获取提交参数

            // 校验参数
            $data = Validator::input($param, [
                'name'  => Validator::notEmpty()->setName('分组名称'),
//                'rule_ids'  => Validator::notEmpty()->setName('权限'),
            ]);
            $param['is_enabled'] = $param['is_enabled'] ?? ($param['is_enabled'] ?? 0);
            $param['weight'] = $param['weight'] ?? 0;
            $param['remark'] = $param['remark'] ?? '';

            // 调用业务服务添加规则
            $service = new AdminGroup();
           $service->save($param);

            return success([],'操作成功');
        } catch (ValidationException $e) {
            throw new MyBusinessException($e->getMessage());
        } catch (\Throwable $e) {
            throw new MyBusinessException('系统异常：' . $e->getMessage());
        }
    }

    public function detail(Request $request, int $id): Response
    {
        try {
            // 查找分组基础信息
            $group = RoleGroup::find($id);
            if (!$group) {
                throw new MyBusinessException('分组不存在');
            }
            if($id == 1){
                 // 超级管理员获取所有权限 ID
                $permissionIds = \app\model\AdminRule::pluck('id')->toArray();
            }else{
                 // 获取关联权限 ID 列表
                $permissionIds = PermissionGroup::where('permission_group_id', $id)
                    ->pluck('permission_id')
                    ->toArray();
            }


            // 拼接返回数据
            $data = $group->toArray();
            $data['rule_ids'] = $permissionIds;

            return success($data);
        } catch (\Throwable $e) {
            throw new MyBusinessException('系统异常：' . $e->getMessage());
        }
    }

    public function destroy(Request $request): Response
    {
        $ids = $request->post('ids');

        if (empty($ids) || !is_array($ids)) {
            throw new MyBusinessException('参数错误，缺少要删除的ID列表');
        }


        try {
            Db::beginTransaction();

            // 删除权限绑定记录
            PermissionGroup::whereIn('permission_group_id', $ids)->delete();

            // 删除分组
            RoleGroup::whereIn('id', $ids)->delete();

            Db::commit();
            return success([], '删除成功');
        } catch (\Throwable $e) {
            Db::rollback();
            throw new MyBusinessException('删除失败：' . $e->getMessage());
        }
    }


    /**
     * 递归获取子节点
     */
    protected function getChildren($parentId): array
    {
        $children = RoleGroup::where('parent_id', $parentId)->get()->map(function ($child) {
            $child->children = $this->getChildren($child->id);
            $child->has_children = count($child->children) > 0;
            return $child;
        })->toArray();

        return $children;
    }

}
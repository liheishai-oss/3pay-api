<?php

namespace app\service;

use app\model\PermissionGroup;
use app\model\RoleGroup;
use support\Db;

class AdminGroup
{
    public function save(array $param)
    {
        // 判断是否是编辑
        $groupId = $param['id'] ?? null;

        // 开启事务
        Db::beginTransaction();
        try {
            if ($groupId) {
                // 编辑：更新分组
                RoleGroup::where('id', $groupId)->update([
                    'parent_id' => $param['parent_id'],
                    'name' => $param['name'],
                    'is_enabled' => $param['is_enabled'],
                    'weight' => $param['weight'],
                    'remark' => $param['remark'],
                ]);

                // 清除旧的权限关联
                PermissionGroup::where('permission_group_id', $groupId)->delete();

            } else {
                // 新增
                $groupId = RoleGroup::insertGetId([
                    'parent_id' => $param['parent_id'],
                    'name' => $param['name'],
                    'is_enabled' => $param['is_enabled'],
                    'weight' => $param['weight'],
                    'remark' => $param['remark']
                ]);
            }

            // 插入新的权限

            if(!empty($param['rule_ids'])) {
                foreach ($param['rule_ids'] as $permissionId) {

                    $ruleInsert = [
                        'permission_group_id' => $groupId,
                        'permission_id' => $permissionId
                    ];
                    PermissionGroup::insert($ruleInsert);
                }
            }


            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }


}
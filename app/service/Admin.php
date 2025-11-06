<?php
namespace app\service;

use app\exception\MyBusinessException;
use app\model\Admin as AdminModel;
use app\model\AdminGroup as AdminGroupModel;

class Admin
{
    /**
     * 创建或编辑管理员
     */
    public function save(array $param): void
    {
        $isEdit = !empty($param['id']);

        if ($isEdit) {
            $admin = AdminModel::find($param['id']);
            if (!$admin) {
                throw new MyBusinessException('用户不存在');
            }

            if (!empty($param['password'])) {
                $admin->password = password_hash($param['password'], PASSWORD_BCRYPT);
            }
        } else {
            if (AdminModel::where('username', $param['username'])->exists()) {
                throw new MyBusinessException('用户名已存在');
            }

            $admin = new AdminModel();
            $admin->password = password_hash($param['password'], PASSWORD_BCRYPT);
        }

        $admin->username = $param['username'];
        $admin->group_id = $param['group_id'];
        $admin->nickname = $param['nickname'] ?? '';
        $admin->status = isset($param['status']) ? (int)$param['status'] : 1;

        $admin->save();

    }
}

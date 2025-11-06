<?php

namespace app\admin\controller\v1;

use app\exception\MyBusinessException;
use app\model\Admin;
use app\service\PasswordValidatorService;
use support\Request;
use support\Response;

class ChangePasswordController
{
    /**
     * 不需要登录的方法
     */
    public static $noNeedLogin = ['changePassword'];
    /**
     * 修改密码（首次登录强制修改）
     */
    public function changePassword(Request $request): Response
    {
        try {
            $adminId = $request->post('admin_id');
            $newPassword = $request->post('new_password');
            $confirmPassword = $request->post('confirm_password');

            \support\Log::info('密码修改请求', [
                'admin_id' => $adminId,
                'has_new_password' => !empty($newPassword),
                'has_confirm_password' => !empty($confirmPassword)
            ]);

            if (empty($adminId) || empty($newPassword) || empty($confirmPassword)) {
                throw new MyBusinessException('参数不能为空');
            }

            if ($newPassword !== $confirmPassword) {
                throw new MyBusinessException('两次输入的密码不一致');
            }

            // 验证密码强度
            PasswordValidatorService::validatePassword($newPassword);

            // 查找用户
            $admin = Admin::find($adminId);
            if (!$admin) {
                throw new MyBusinessException('用户不存在');
            }

            // 检查是否首次登录
            if ($admin->is_first_login != 1) {
                throw new MyBusinessException('只有首次登录用户才能使用此接口');
            }

            // 更新密码
            $admin->password = password_hash($newPassword, PASSWORD_BCRYPT);
            $admin->is_first_login = 0; // 标记为非首次登录
            $admin->password_changed_at = date('Y-m-d H:i:s');
            $admin->save();

            return success([], '密码修改成功，请重新登录');
        } catch (\Exception $e) {
            return error($e->getMessage(), 400);
        }
    }

    /**
     * 普通修改密码（需要验证旧密码）
     */
    public function updatePassword(Request $request): Response
    {
        try {
            $adminId = $request->userData['admin_id'];
            $oldPassword = $request->post('old_password');
            $newPassword = $request->post('new_password');
            $confirmPassword = $request->post('confirm_password');

            if (empty($oldPassword) || empty($newPassword) || empty($confirmPassword)) {
                throw new MyBusinessException('参数不能为空');
            }

            if ($newPassword !== $confirmPassword) {
                throw new MyBusinessException('两次输入的密码不一致');
            }

            // 验证密码强度
            PasswordValidatorService::validatePassword($newPassword);

            // 查找用户
            $admin = Admin::find($adminId);
            if (!$admin) {
                throw new MyBusinessException('用户不存在');
            }

            // 验证旧密码
            if (!password_verify($oldPassword, $admin->password)) {
                throw new MyBusinessException('旧密码错误');
            }

            // 更新密码
            $admin->password = password_hash($newPassword, PASSWORD_BCRYPT);
            $admin->password_changed_at = date('Y-m-d H:i:s');
            $admin->save();

            return success([], '密码修改成功');
        } catch (\Exception $e) {
            return error($e->getMessage(), 400);
        }
    }
}

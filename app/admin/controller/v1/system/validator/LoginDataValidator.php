<?php

namespace app\admin\controller\v1\system\validator;

use app\exception\MyBusinessException;
use app\repository\AdminAuthRepository;

class LoginDataValidator
{
    public function __construct(private readonly AdminAuthRepository $repository){}
    /**
     * 验证登录相关的数据
     */
    public function validate(array $param, bool $googleEnabled = false): object
    {
        if ($googleEnabled && (!isset($data['google_code']) || !preg_match('/^\d{6}$/', $data['google_code']))) {
            throw new MyBusinessException('谷歌验证码必须是6位数字');
        }

        $admin = $this->repository->getAdminByUsername($param['username']);
        if (!$admin) throw new MyBusinessException('用户或者密码错误');
        if ((int)$admin->status !== 1) throw new MyBusinessException('用户已被禁用');
        if (!$this->repository->isPasswordValid($admin, $param['password'])) {
            throw new MyBusinessException('用户或者密码错误');
        }

        return $admin;
    }
}
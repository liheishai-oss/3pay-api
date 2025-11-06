<?php

namespace app\admin\controller\v1\system\validator;
use app\exception\MyBusinessException;
class LoginRequestValidator
{
    /**
     * 登录参数校验
     *
     * @param array $data
     * @param bool $googleEnabled
     */
    public function validate(array $data): void {
        if (empty($data['username'])) throw new MyBusinessException('用户名不能为空');
        if (empty($data['password'])) throw new MyBusinessException('密码不能为空');

    }
}

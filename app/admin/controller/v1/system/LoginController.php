<?php

    namespace app\admin\controller\v1\system;

    use app\admin\controller\v1\system\validator\LoginRequestValidator;
    use app\service\LoginService;
    use support\Request;
    use support\Response;
    use Throwable;

    class LoginController
    {

        protected array $noNeedLogin = ['login'];
        public function __construct(private readonly LoginRequestValidator $validator, private readonly LoginService $loginService)
        {
        }
        public function login(Request $request): Response
        {
            try{
                $param = $request->all();
                // 参数校验
                $this->validator->validate($param);

                // 调用服务类
                $data = $this->loginService->login($param);
                // 从返回的数据中提取 token 设置到响应头
                $token = $data['Authorization'] ?? null;
                $headers = $token ? ['Authorization' => $token] : [];
                return success($data, '登录成功',200,$headers);
            } catch (Throwable $e) {
                return error($e->getMessage(), (int)($e->getCode() ?: 400));
            }
        }
    }
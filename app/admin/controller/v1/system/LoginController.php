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

                // 调用服务类，传递Request对象以获取客户端IP
                $data = $this->loginService->login($param, $request);
                return success($data, '登录成功',200,$data);
            } catch (Throwable $e) {
                return error($e->getMessage(), (int)($e->getCode() ?: 400));
            }
        }
    }
<?php

namespace app\exception;

use support\exception\BusinessException;
use Webman\Http\Request;
use Webman\Http\Response;

class MyBusinessException extends BusinessException
{
    public function render(Request $request): ?Response
    {
        return json(['code' => $this->getCode() ?: 500,'status'=>false, 'message' => $this->getMessage(),'data'=>(object)[]]);
    }
}


<?php

namespace app\admin\controller\v1\telegram\admin\validator;

use app\exception\MyBusinessException;
use Respect\Validation\Exceptions\NestedValidationException;
use Respect\Validation\Validator as v;

class TelegramAdminValidator
{
    /**
     * 创建管理员参数校验
     */
    public function validateCreate(array $data): void
    {
        try {
            v::key('telegram_id', v::intVal()->notEmpty()->setName('Telegram ID'))
                ->key('nickname', v::stringType()->notEmpty()->length(1, 128)->setName('昵称'))
                ->key('username', v::optional(v::stringType()->length(1, 64))->setName('用户名'))
                ->key('status', v::optional(v::intVal()->in([0, 1]))->setName('状态'))
                ->key('remark', v::optional(v::stringType()->length(0, 255))->setName('备注'))
                ->assert($data);
        } catch (NestedValidationException $e) {
            $messages = $e->getMessages([
                'notEmpty' => '{{name}}不能为空',
                'intVal' => '{{name}}必须是整数',
                'stringType' => '{{name}}必须是字符串',
                'length' => '{{name}}长度必须在{{minValue}}到{{maxValue}}之间',
                'in' => '{{name}}值无效',
            ]);
            $messages = array_filter($messages);
            throw new MyBusinessException(implode('; ', $messages));
        }
    }

    /**
     * 更新管理员参数校验
     */
    public function validateUpdate(array $data): void
    {
        try {
            v::key('telegram_id', v::optional(v::intVal()->notEmpty())->setName('Telegram ID'))
                ->key('nickname', v::optional(v::stringType()->notEmpty()->length(1, 128))->setName('昵称'))
                ->key('username', v::optional(v::stringType()->length(1, 64))->setName('用户名'))
                ->key('status', v::optional(v::intVal()->in([0, 1]))->setName('状态'))
                ->key('remark', v::optional(v::stringType()->length(0, 255))->setName('备注'))
                ->assert($data);
        } catch (NestedValidationException $e) {
            $messages = $e->getMessages([
                'notEmpty' => '{{name}}不能为空',
                'intVal' => '{{name}}必须是整数',
                'stringType' => '{{name}}必须是字符串',
                'length' => '{{name}}长度必须在{{minValue}}到{{maxValue}}之间',
                'in' => '{{name}}值无效',
            ]);
            $messages = array_filter($messages);
            throw new MyBusinessException(implode('; ', $messages));
        }
    }

    /**
     * 批量操作参数校验
     */
    public function validateBatch(array $data): void
    {
        try {
            v::key('ids', v::arrayType()->notEmpty()->setName('ID列表'))
                ->assert($data);
            
            // 验证每个ID都是正整数
            foreach ($data['ids'] as $id) {
                if (!is_numeric($id) || $id <= 0) {
                    throw new MyBusinessException('ID列表包含无效值');
                }
            }
        } catch (NestedValidationException $e) {
            $messages = $e->getMessages([
                'notEmpty' => '{{name}}不能为空',
                'arrayType' => '{{name}}必须是数组',
            ]);
            $messages = array_filter($messages);
            throw new MyBusinessException(implode('; ', $messages));
        }
    }
}

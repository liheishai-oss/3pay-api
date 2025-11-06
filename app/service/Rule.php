<?php
namespace app\service;

use app\model\AdminRule;

class Rule
{
    public function addRule(array $data): AdminRule
    {
        // 1. 检查 rule 是否重复
        if (AdminRule::where('rule', $data['rule'])->exists()) {
            throw new \Exception('规则标识（rule）已存在');
        }

        // 2. 如果是菜单，检查 path 是否重复
        if ($data['is_menu'] == 1 && AdminRule::where('path', $data['path'])->exists()) {
            throw new \Exception('菜单路径（path）已存在');
        }

        $rule = new AdminRule();
        $rule->title = $data['title'];
        $rule->rule = $data['rule'];
        $rule->parent_id = isset($data['parent_id']) && $data['parent_id'] !== '' ? (int)$data['parent_id'] : 0;
        $rule->is_menu = $data['is_menu'];
        $rule->weight = $data['weight'];
        $rule->status = $data['status'];
        $rule->remark = $data['remark'];
        $rule->path = $data['path'];
        $rule->icon = $data['icon'];
        $rule->has_children = 0;
        print_r($data);
        $rule->save();

        // 更新父级的 has_children 字段
        if ($rule->parent_id > 0) {
            AdminRule::where('id', $rule->parent_id)->update(['has_children' => 1]);
        }

        return $rule;
    }

    public function edit(array $data): array
    {
        // 先查出当前记录
        $rule = AdminRule::find($data['id']);
        if (!$rule) {
            throw new \Exception('要编辑的权限规则不存在');
        }
        $parent_id = $rule->parent_id;
        print_r($parent_id);
        // 判断是否修改了 rule，且值已存在
        if (
            isset($data['rule']) &&
            $data['rule'] !== $rule->rule &&
            AdminRule::where('rule', $data['rule'])->exists()
        ) {
            throw new \Exception('规则标识（rule）已存在');
        }

        // 判断是否是菜单并且 path 有修改且已存在
        if (
            isset($data['path']) &&
            $data['is_menu'] == 1 &&
            $data['path'] !== $rule->path &&
            AdminRule::where('path', $data['path'])->exists()
        ) {
            throw new \Exception('菜单路径（path）已存在');
        }

        // 更新字段
        $rule->title = $data['title'];
        $rule->rule = $data['rule'];
        $rule->parent_id = isset($data['parent_id']) && $data['parent_id'] !== '' ? (int)$data['parent_id'] : 0;
        $rule->is_menu = $data['is_menu'];
        $rule->weight = $data['weight'];
        $rule->status = $data['status'];
        $rule->remark = $data['remark'];
        $rule->path = $data['path'];
        $rule->icon = $data['icon'];

        $rule->save();

        // 更新父级的 has_children 字段
        if ($rule->parent_id > 0) {
            AdminRule::where('id', $rule->parent_id)->update(['has_children' => 1]);
        }
        // 检查原父级是否还存在其他子节点，如果没有则更新为 has_children = 0
        if ($parent_id > 0) {
            $hasOtherChildren = AdminRule::where('parent_id', $parent_id)->exists();
            if (!$hasOtherChildren) {
                AdminRule::where('id', $parent_id)->update(['has_children' => 0]);
            }
        }
        return [];
    }

}

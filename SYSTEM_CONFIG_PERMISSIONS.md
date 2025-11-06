# 系统配置权限说明

## 权限结构

系统配置功能包含以下权限：

### 主权限
- `system:config` - 系统配置主权限（菜单权限）

### 子权限
- `system:config:list` - 查看配置
- `system:config:save` - 保存配置  
- `system:config:reset` - 重置配置
- `system:config:groups` - 配置分组

## 权限分配

### 超级管理员
- 自动拥有所有系统配置权限
- 可以查看、修改、重置所有配置

### 普通管理员
- 需要手动分配相应权限
- 建议分配权限：
  - 查看配置：`system:config:list`
  - 保存配置：`system:config:save`
  - 重置配置：`system:config:reset`

### 只读用户
- 仅分配查看权限：`system:config:list`
- 无法修改或重置配置

## 权限检查

### 后端权限检查
所有API接口都包含权限检查：

```php
// 检查权限示例
if (!auth()->check('system:config:list')) {
    return json([
        'code' => 403,
        'status' => false,
        'message' => '没有权限访问系统配置',
        'data' => null
    ]);
}
```

### 前端权限控制
前端页面会根据用户权限显示/隐藏相应功能：

- 无查看权限：无法访问系统配置页面
- 无保存权限：隐藏保存按钮
- 无重置权限：隐藏重置按钮

## 数据库权限表

### 权限规则表 (fourth_party_payment_permission_rule)
```sql
-- 主权限
INSERT INTO fourth_party_payment_permission_rule 
(rule, name, parent_id, weight, is_menu, icon, component, path, status) 
VALUES 
('system:config', '系统配置', 76, 80, 1, 'el-icon-setting', 'system/config', '/system/config', 1);

-- 子权限
INSERT INTO fourth_party_payment_permission_rule 
(rule, name, parent_id, weight, is_menu, status) 
VALUES 
('system:config:list', '查看配置', @system_config_id, 90, 0, 1),
('system:config:save', '保存配置', @system_config_id, 85, 0, 1),
('system:config:reset', '重置配置', @system_config_id, 80, 0, 1),
('system:config:groups', '配置分组', @system_config_id, 75, 0, 1);
```

### 用户组权限表 (fourth_party_payment_admin_group_rule)
```sql
-- 为超级管理员组分配权限
INSERT INTO fourth_party_payment_admin_group_rule 
(group_id, rule_id) 
SELECT 1, id FROM fourth_party_payment_permission_rule 
WHERE rule LIKE 'system:config%';
```

## 使用说明

### 1. 执行权限SQL
```bash
mysql -u username -p database_name < system_config_permissions.sql
```

### 2. 分配用户权限
- 登录管理后台
- 进入"系统管理" -> "权限分组管理"
- 选择用户组，分配相应的系统配置权限

### 3. 权限验证
- 使用不同权限的用户登录
- 验证系统配置页面的功能访问权限
- 确认权限控制是否生效

## 安全建议

1. **最小权限原则**：只分配用户实际需要的权限
2. **定期审查**：定期检查权限分配是否合理
3. **操作日志**：记录配置修改操作，便于审计
4. **备份配置**：重要配置修改前先备份

## 故障排查

### 权限问题
1. 检查用户是否属于正确的用户组
2. 确认用户组是否分配了相应权限
3. 验证权限标识是否正确

### 功能问题
1. 检查API接口权限检查逻辑
2. 确认前端权限控制代码
3. 验证数据库权限表数据

## 更新日志

- v1.0.0: 初始版本，包含基础权限控制
- 后续版本将根据需求增加更多权限细分


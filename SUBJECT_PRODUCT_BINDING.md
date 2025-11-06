# 主体产品绑定功能说明

## 功能概述

主体产品绑定功能允许一个支付主体绑定多个产品，实现更灵活的产品管理。

## 数据库结构

### 主体产品关联表 (subject_product)

```sql
CREATE TABLE `subject_product` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `subject_id` int(11) NOT NULL COMMENT '主体ID',
    `product_id` int(11) NOT NULL COMMENT '产品ID',
    `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：1启用 0禁用',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_subject_product` (`subject_id`, `product_id`),
    KEY `idx_subject_id` (`subject_id`),
    KEY `idx_product_id` (`product_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='主体产品关联表';
```

## 后端实现

### 1. 模型文件

- `app/model/SubjectProduct.php` - 主体产品关联模型
- 更新 `app/model/Subject.php` - 添加产品关联关系

### 2. 控制器

- `app/admin/controller/v1/SubjectProductController.php` - 主体产品管理API

### 3. API接口

#### 获取主体产品列表
```
POST /sys/api/v1/admin/subject-product/list
```

参数：
- `subject_id` (int) - 主体ID

返回：
- `bound_products` - 已绑定的产品列表
- `available_products` - 可绑定的产品列表

#### 绑定产品
```
POST /sys/api/v1/admin/subject-product/bind
```

参数：
- `subject_id` (int) - 主体ID
- `product_id` (int) - 产品ID

#### 解绑产品
```
POST /sys/api/v1/admin/subject-product/unbind
```

参数：
- `subject_id` (int) - 主体ID
- `product_id` (int) - 产品ID

#### 批量绑定产品
```
POST /sys/api/v1/admin/subject-product/batch-bind
```

参数：
- `subject_id` (int) - 主体ID
- `product_ids` (array) - 产品ID数组

## 前端实现

### 1. 组件文件

- `src/components/SubjectProductBinding.vue` - 产品绑定管理组件

### 2. 页面集成

- 更新 `src/pages/subject/edit.vue` - 集成产品绑定功能

### 3. 功能特性

- 显示已绑定的产品列表
- 搜索可绑定的产品
- 单个产品绑定/解绑
- 批量产品绑定
- 实时更新绑定状态

## 使用说明

### 1. 数据库初始化

执行SQL文件创建关联表：
```bash
mysql -u username -p database_name < create_subject_product_table.sql
```

### 2. 前端使用

1. 打开主体编辑页面
2. 在编辑模式下，会显示"产品绑定管理"卡片
3. 点击"绑定产品"按钮打开绑定对话框
4. 搜索并选择要绑定的产品
5. 支持单个绑定或批量绑定
6. 在已绑定列表中可以进行解绑操作

### 3. 权限控制

- 只能绑定同一代理商下的产品
- 需要相应的管理员权限
- 支持软删除（状态控制）

## 测试

使用测试脚本验证功能：
```bash
php test_subject_product_binding.php
```

## 注意事项

1. 产品绑定后，主体可以处理该产品的支付请求
2. 解绑产品不会删除历史订单数据
3. 批量操作会显示成功和失败的详细信息
4. 搜索功能支持产品名称和代码模糊匹配












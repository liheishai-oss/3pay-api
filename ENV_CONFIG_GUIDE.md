# 环境变量配置指南

## 📋 当前配置状态

### ⚠️ 发现的问题

1. **缺少 `.env` 文件** - 项目中没有找到 `.env` 文件
2. **数据库配置硬编码** - `config/database.php` 中直接写死了数据库配置
3. **Redis配置硬编码** - `config/redis.php` 中直接写死了Redis配置
4. **缺少Telegram配置** - 新增的机器人功能需要Telegram配置

---

## ✅ 必需的配置项

### 1. Telegram机器人配置（新增功能必需）

```env
# Telegram Bot Token (从 @BotFather 获取)
TELEGRAM_BOT_TOKEN=123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11

# Telegram Chat ID (从 @userinfobot 获取)
TELEGRAM_CHAT_ID=123456789

# 是否启用Telegram推送（可选，默认true）
TELEGRAM_ENABLED=true
```

### 2. 应用基础配置

```env
# 应用URL（用于生成支付链接）
APP_URL=http://127.0.0.1:8787

# 服务器公网IP（用于IP白名单检测，留空自动检测）
SERVER_IP=
```

### 3. 商户对接配置

```env
# 回调IP地址（多个IP用逗号分隔，用于商户配置白名单）
MERCHANT_CALLBACK_IPS=34.92.49.193,34.150.65.167

# API网关地址
MERCHANT_API_GATEWAY=https://api.baiyi-pay.com

# API文档地址
MERCHANT_API_DOCS=https://www.baiyi-pay.com/docs.html
```

### 4. 数据库配置（建议配置）

虽然当前在 `config/database.php` 中硬编码，但建议改为使用环境变量：

```env
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=third_party_payment
DB_USERNAME=third_party_payment
DB_PASSWORD=rA8f@D2kLmZx!3pQ
```

### 5. Redis配置（建议配置）

虽然当前在 `config/redis.php` 中硬编码，但建议改为使用环境变量：

```env
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DATABASE=0
```

---

## 🔧 可选的配置项

### 1. 调试相关

```env
# 调试模式
DEBUG=false
DEBUG_ID=

# XHProf性能分析
XHPROF_ENABLED=false
XHPROF_OUTPUT_DIR=
```

### 2. Telegram进阶配置

```env
# Telegram API基础URL（使用代理时修改）
TELEGRAM_API_BASE_URL=https://api.telegram.org

# Telegram Webhook地址（可选）
TELEGRAM_WEBHOOK=
```

---

## 📝 完整的 .env 文件模板

创建文件 `.env`（复制以下内容）：

\`\`\`env
# ============================================
# 应用配置
# ============================================
APP_URL=http://127.0.0.1:8787
SERVER_IP=

# ============================================
# 商户对接配置
# ============================================
MERCHANT_CALLBACK_IPS=34.92.49.193,34.150.65.167
MERCHANT_API_GATEWAY=https://api.baiyi-pay.com
MERCHANT_API_DOCS=https://www.baiyi-pay.com/docs.html

# ============================================
# 数据库配置（当前在config/database.php中硬编码）
# ============================================
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=third_party_payment
DB_USERNAME=third_party_payment
DB_PASSWORD=rA8f@D2kLmZx!3pQ

# ============================================
# Redis配置（当前在config/redis.php中硬编码）
# ============================================
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DATABASE=0

# ============================================
# Telegram机器人配置（必需配置）
# ============================================
TELEGRAM_ENABLED=true
TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=
TELEGRAM_API_BASE_URL=https://api.telegram.org

# ============================================
# 调试配置（可选）
# ============================================
DEBUG=false
DEBUG_ID=
XHPROF_ENABLED=false
XHPROF_OUTPUT_DIR=

# ============================================
# Webhook配置（可选）
# ============================================
TELEGRAM_WEBHOOK=
\`\`\`

---

## 🔨 建议的配置文件优化

### 1. 优化 database.php

当前配置是硬编码的，建议修改为：

\`\`\`php
<?php

return  [
    'default' => env('DB_CONNECTION', 'mysql'),
    'connections' => [
        'mysql' => [
            'driver'      => 'mysql',
            'host'        => env('DB_HOST', 'mysql'),
            'port'        => env('DB_PORT', '3306'),
            'database'    => env('DB_DATABASE', 'third_party_payment'),
            'username'    => env('DB_USERNAME', 'third_party_payment'),
            'password'    => env('DB_PASSWORD', 'rA8f@D2kLmZx!3pQ'),
            'charset'     => env('DB_CHARSET', 'utf8mb4'),
            'collation'   => env('DB_COLLATION', 'utf8mb4_general_ci'),
            'prefix'      => env('DB_PREFIX', ''),
            'strict'      => true,
            'engine'      => null,
            'options'   => [
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
            'pool' => [
                'max_connections' => env('DB_POOL_MAX_CONNECTIONS', 100),
                'min_connections' => env('DB_POOL_MIN_CONNECTIONS', 20),
                'wait_timeout' => env('DB_POOL_WAIT_TIMEOUT', 10),
                'idle_timeout' => env('DB_POOL_IDLE_TIMEOUT', 300),
                'heartbeat_interval' => env('DB_POOL_HEARTBEAT_INTERVAL', 30),
            ],
        ],
    ],
];
\`\`\`

### 2. 优化 redis.php

当前配置是硬编码的，建议修改为：

\`\`\`php
<?php

return [
    'default' => [
        'password' => env('REDIS_PASSWORD', ''),
        'host' => env('REDIS_HOST', 'redis'),
        'port' => env('REDIS_PORT', 6379),
        'database' => env('REDIS_DATABASE', 0),
        'pool' => [
            'max_connections' => env('REDIS_POOL_MAX_CONNECTIONS', 50),
            'min_connections' => env('REDIS_POOL_MIN_CONNECTIONS', 10),
            'wait_timeout' => env('REDIS_POOL_WAIT_TIMEOUT', 10),
            'idle_timeout' => env('REDIS_POOL_IDLE_TIMEOUT', 300),
            'heartbeat_interval' => env('REDIS_POOL_HEARTBEAT_INTERVAL', 30),
        ],
    ]
];
\`\`\`

---

## 🚀 快速配置步骤

### 方法1：创建最小化配置（仅必需项）

创建 `.env` 文件，只配置Telegram：

\`\`\`env
# Telegram机器人配置
TELEGRAM_BOT_TOKEN=你的Bot Token
TELEGRAM_CHAT_ID=你的Chat ID
\`\`\`

### 方法2：创建完整配置（推荐）

1. 复制上面的"完整的 .env 文件模板"
2. 填写Telegram配置
3. 其他配置保持默认值

---

## ⚡ 当前可以不配置的项

由于数据库和Redis配置都在配置文件中硬编码，以下配置**暂时可以不在.env中配置**：

- ❌ `DB_HOST`、`DB_PORT` 等数据库配置（已在config/database.php中硬编码）
- ❌ `REDIS_HOST`、`REDIS_PORT` 等Redis配置（已在config/redis.php中硬编码）
- ❌ `DEBUG`、`XHPROF_ENABLED` 等调试配置（可选功能）

**但强烈建议**按照上面的建议优化配置文件，改用环境变量！

---

## 📊 配置优先级

1. **必须立即配置**（否则机器人推送功能无法使用）
   - `TELEGRAM_BOT_TOKEN`
   - `TELEGRAM_CHAT_ID`

2. **建议配置**（提高配置灵活性）
   - `APP_URL`
   - 数据库配置改用env
   - Redis配置改用env

3. **可选配置**（根据需要）
   - `DEBUG`
   - `XHPROF_ENABLED`
   - `TELEGRAM_WEBHOOK`

---

## 🔒 安全提醒

1. **不要提交.env文件到Git仓库**
   - `.env` 文件已在 `.gitignore` 中
   - 仅提交 `.env.example` 作为模板

2. **敏感信息保护**
   - 数据库密码
   - Redis密码
   - Telegram Bot Token
   - API密钥等

3. **生产环境配置**
   - 关闭DEBUG模式
   - 使用强密码
   - 定期更新凭证

---

## 📞 获取Telegram配置

### 获取Bot Token：
1. 在Telegram中搜索 `@BotFather`
2. 发送 `/newbot`
3. 按提示创建机器人
4. 获得Token（格式：`123456:ABC-DEF...`）

### 获取Chat ID：
1. 在Telegram中搜索 `@userinfobot`
2. 发送任意消息
3. 获得Chat ID（格式：`123456789`）

---

## ✅ 验证配置

创建 `.env` 后，运行测试脚本：

\`\`\`bash
php test_robot_push.php
\`\`\`

如果收到Telegram消息，说明配置成功！

---

## 📝 总结

### 当前状态
- ❌ 缺少 `.env` 文件
- ❌ 缺少 Telegram 配置（机器人功能必需）
- ⚠️ 数据库配置硬编码（建议优化）
- ⚠️ Redis配置硬编码（建议优化）

### 立即需要做的
1. 创建 `.env` 文件
2. 配置 `TELEGRAM_BOT_TOKEN` 和 `TELEGRAM_CHAT_ID`
3. 测试机器人推送功能

### 建议优化的
1. 修改 `config/database.php` 使用环境变量
2. 修改 `config/redis.php` 使用环境变量
3. 创建 `.env.example` 作为配置模板


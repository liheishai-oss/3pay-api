# 支付宝投诉监控系统技术方案（Golang版）

## 📋 目录

- [1. 方案概述](#1-方案概述)
- [2. 技术选型](#2-技术选型)
- [3. 数据库设计](#3-数据库设计)
- [4. 系统架构](#4-系统架构)
- [5. 并发模型](#5-并发模型)
- [6. 证书管理方案](#6-证书管理方案)
- [7. 核心流程](#7-核心流程)
- [8. 防重复机制](#8-防重复机制)
- [9. 内存管理策略](#9-内存管理策略)
- [10. 日志优化方案](#10-日志优化方案)
- [11. 黑名单集成](#11-黑名单集成)
- [12. 消息推送](#12-消息推送)
- [13. 配置说明](#13-配置说明)
- [14. 实施步骤](#14-实施步骤)
- [15. 监控告警](#15-监控告警)

---

## 1. 方案概述

### 1.1 需求说明

- **目标**：实时监控支付宝投诉，及时推送到Telegram
- **并发模式**：多主体并发处理，每个主体独立协程
- **线程数**：**动态线程数** = 数据库中主体数量
- **频率**：每1-2秒轮询一次投诉列表
- **特点**：一个投诉可能包含多个订单，需要拆分处理
- **防重**：**每个appid只取一条**投诉，同一订单号只推送一次

### 1.2 核心设计理念

> **动态协程池 + 按主体隔离 + 内存可控 + 日志精简**

- ✅ **动态协程数**：根据主体数量自动调整（N个主体 = N个协程）
- ✅ **资源隔离**：每个主体独立协程，互不影响
- ✅ **内存可控**：协程生命周期管理，及时释放资源
- ✅ **日志精简**：只记录关键事件，避免日志爆炸
- ✅ **限流策略**：每个appid只取1条，避免重复处理

### 1.3 为什么选择Golang

| 维度 | Golang | PHP/Webman |
|-----|--------|-----------|
| **并发模型** | 原生Goroutine，单进程数千协程 | 多进程/Swoole协程，资源消耗大 |
| **内存占用** | 单协程仅2KB，自动GC | 单进程数MB起步 |
| **动态扩展** | 协程数可动态调整 | 进程数需重启配置 |
| **性能** | 编译型，执行效率高 | 解释型，相对较慢 |
| **资源控制** | 精细的协程池管理 | 进程管理粗糙 |
| **适用场景** | **高并发、多主体场景** | 中低并发场景 |

---

## 2. 技术选型

### 2.1 核心技术栈

| 组件 | 技术选型 | 版本 | 用途 |
|-----|---------|------|-----|
| 开发语言 | **Golang** | 1.21+ | 核心服务 |
| 数据库 | MySQL | 5.7+ | 存储投诉数据 |
| 缓存 | Redis | 6.0+ | 防重判断、分布式锁 |
| ORM | GORM | v1.25+ | 数据库操作 |
| 日志 | Zap | v1.26+ | 高性能日志 |
| 配置 | Viper | v1.18+ | 配置管理 |
| 协程池 | ants | v2.9+ | 协程池管理 |
| HTTP客户端 | Resty | v2.11+ | API调用 |

### 2.2 依赖库清单

```go
require (
    github.com/go-redis/redis/v8 v8.11.5
    gorm.io/gorm v1.25.5
    gorm.io/driver/mysql v1.5.2
    go.uber.org/zap v1.26.0
    github.com/spf13/viper v1.18.0
    github.com/panjf2000/ants/v2 v2.9.0
    github.com/go-resty/resty/v2 v2.11.0
    gopkg.in/natefinch/lumberjack.v2 v2.2.1  // 日志轮转
)
```

---

## 3. 数据库设计

### 3.1 投诉订单明细表

```sql
CREATE TABLE `alipay_complaint_detail` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    
    -- 主体标识
    `subject_id` INT NOT NULL COMMENT '主体ID',
    `app_id` VARCHAR(64) NOT NULL COMMENT '支付宝AppID',
    
    -- 投诉标识
    `complaint_no` VARCHAR(64) NOT NULL COMMENT '投诉单号',
    
    -- 订单标识
    `order_no` VARCHAR(64) NOT NULL COMMENT '平台订单号',
    `merchant_order_no` VARCHAR(64) COMMENT '商户订单号',
    
    -- 投诉信息
    `complaint_type` VARCHAR(50) COMMENT '投诉类型',
    `complaint_status` VARCHAR(20) COMMENT '投诉状态',
    `complaint_reason` TEXT COMMENT '投诉原因',
    `complaint_amount` DECIMAL(10,2) COMMENT '投诉总金额',
    `complainant_name` VARCHAR(100) COMMENT '投诉人',
    `complaint_time` DATETIME COMMENT '投诉时间',
    
    -- 订单信息
    `order_amount` DECIMAL(10,2) COMMENT '订单金额',
    
    -- 推送控制
    `is_pushed` TINYINT DEFAULT 0 COMMENT '是否已推送',
    `pushed_at` DATETIME NULL COMMENT '推送时间',
    
    -- 简化原始数据（仅保留必要字段）
    `raw_data` TEXT COMMENT '精简JSON（可选）',
    
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- 索引优化
    UNIQUE KEY `uniq_subject_complaint_order` (`subject_id`, `complaint_no`, `order_no`),
    KEY `idx_app_id` (`app_id`),
    KEY `idx_is_pushed` (`is_pushed`, `created_at`),
    KEY `idx_complaint_time` (`complaint_time`)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='投诉订单明细表';
```

**设计要点：**
- `subject_id` + `complaint_no` + `order_no` 三者组合唯一
- 索引设计考虑查询性能
- `raw_data` 字段可选，避免存储过大JSON

---

## 4. 系统架构

### 4.1 整体架构图

```
┌────────────────────────────────────────────────────────────────┐
│              支付宝投诉监控系统 (Golang Service)                │
└────────────────────────────────────────────────────────────────┘
                              │
                              ▼
                    ┌──────────────────┐
                    │  主协程 (Main)    │
                    │  - 初始化配置     │
                    │  - 连接数据库     │
                    │  - 连接Redis     │
                    └──────────────────┘
                              │
                              ▼
                    ┌──────────────────┐
                    │  SubjectLoader    │
                    │  - 定期加载主体   │
                    │  - 动态调整协程   │
                    └──────────────────┘
                              │
                    ┌─────────┴─────────┐
                    │                   │
                    ▼                   ▼
        ┌───────────────────┐   ┌───────────────────┐
        │  SubjectWorker 1  │   │  SubjectWorker N  │
        │  (协程池)          │   │  (协程池)          │
        └───────────────────┘   └───────────────────┘
                    │                   │
                    ▼                   ▼
        ┌───────────────────┐   ┌───────────────────┐
        │  投诉监控循环     │   │  投诉监控循环     │
        │  - 拉取投诉列表   │   │  - 拉取投诉列表   │
        │  - 只取1条       │   │  - 只取1条       │
        │  - 获取详情       │   │  - 获取详情       │
        │  - 拆分订单       │   │  - 拆分订单       │
        │  - 入库         │   │  - 入库         │
        │  - 防重判断       │   │  - 防重判断       │
        │  - 加入消息队列   │   │  - 加入消息队列   │
        └───────────────────┘   └───────────────────┘
                    │                   │
                    └─────────┬─────────┘
                              ▼
                    ┌──────────────────┐
                    │  MySQL队列表      │
                    │  (复用PHP系统)   │
                    └──────────────────┘
                              │
                              ▼
                    ┌──────────────────┐
                    │  PHP监控进程      │
                    │  TelegramMonitor │
                    └──────────────────┘
                              │
                              ▼
                          Telegram
```

### 4.2 模块划分

```
complaint-monitor/          # 项目根目录
├── main.go                 # 入口文件
├── config/                 # 配置模块
│   ├── config.go          # 配置加载
│   └── config.yaml        # 配置文件
├── model/                  # 数据模型
│   ├── complaint.go       # 投诉模型
│   └── subject.go         # 主体模型
├── service/                # 业务逻辑
│   ├── alipay_api.go      # 支付宝API封装
│   ├── complaint_svc.go   # 投诉业务逻辑
│   ├── blacklist_svc.go   # 黑名单服务
│   ├── risk_assess.go     # 风险评估服务
│   └── message_queue.go   # 消息队列服务
├── worker/                 # 工作协程
│   ├── subject_loader.go  # 主体加载器
│   ├── subject_worker.go  # 主体工作协程
│   └── worker_pool.go     # 协程池管理
├── utils/                  # 工具类
│   ├── logger.go          # 日志工具
│   ├── redis.go           # Redis工具
│   └── db.go              # 数据库工具
└── go.mod                  # 依赖管理
```

---

## 5. 并发模型（含安全保护）

### 5.1 动态协程池设计

**核心原则：N个主体 = N个协程 + Panic恢复**

```
时刻T1: 数据库中有5个主体
   → 启动5个协程，每个处理1个主体

时刻T2: 新增了3个主体，总共8个
   → 动态启动3个新协程

时刻T3: 禁用了2个主体，剩余6个
   → 优雅关闭2个协程，释放资源
```

### 5.2 协程生命周期管理（带panic恢复）

```
主协程启动
    ↓
SubjectLoader 启动（独立协程）+ defer recover
    ├─ 每60秒查询一次数据库
    ├─ 对比当前主体列表
    ├─ 新增主体 → 启动新协程
    ├─ 删除主体 → 发送关闭信号
    └─ 通过 Channel 通信
    ↓
SubjectWorker 协程池 + defer recover
    ├─ 每个协程监听自己的 stopChan
    ├─ 使用 context.WithTimeout 防止泄漏
    ├─ 收到关闭信号 → 完成当前任务 → 退出
    └─ panic后自动恢复并重启
```

### 5.3 Panic恢复机制（P0修复）

```go
package worker

import (
    "context"
    "fmt"
    "runtime/debug"
    "time"
    
    "go.uber.org/zap"
)

// SubjectWorker 主体工作协程
type SubjectWorker struct {
    subjectID   int
    stopChan    chan struct{}
    ctx         context.Context
    cancel      context.CancelFunc
    logger      *zap.Logger
    restartable bool  // 是否允许自动重启
}

// Run 运行协程（带panic恢复）
func (w *SubjectWorker) Run() {
    // 🔴 P0修复：添加panic恢复机制
    defer func() {
        if r := recover(); r != nil {
            // 记录panic详细信息
            stack := string(debug.Stack())
            w.logger.Error("协程panic",
                zap.Int("subject_id", w.subjectID),
                zap.Any("panic", r),
                zap.String("stack", stack))
            
            // 发送告警通知
            w.sendPanicAlert(fmt.Sprintf("%v", r), stack)
            
            // 如果允许自动重启
            if w.restartable {
                w.logger.Info("5秒后尝试重启协程",
                    zap.Int("subject_id", w.subjectID))
                time.Sleep(5 * time.Second)
                go w.Run()  // 重启协程
            }
        }
    }()
    
    w.logger.Info("协程启动", zap.Int("subject_id", w.subjectID))
    
    // 主循环
    ticker := time.NewTicker(2 * time.Second)
    defer ticker.Stop()
    
    for {
        select {
        case <-w.ctx.Done():
            w.logger.Info("收到停止信号，协程退出",
                zap.Int("subject_id", w.subjectID))
            return
            
        case <-w.stopChan:
            w.logger.Info("收到关闭信号，协程退出",
                zap.Int("subject_id", w.subjectID))
            return
            
        case <-ticker.C:
            // 🔴 每次迭代都有独立的panic保护
            w.processOnce()
        }
    }
}

// processOnce 单次处理（带独立panic保护）
func (w *SubjectWorker) processOnce() {
    // 🔴 单次处理的panic不影响整个循环
    defer func() {
        if r := recover(); r != nil {
            w.logger.Warn("单次处理panic（已恢复）",
                zap.Int("subject_id", w.subjectID),
                zap.Any("panic", r))
            // 不重启，继续下一次循环
        }
    }()
    
    // 🔴 使用context.WithTimeout防止协程泄漏
    ctx, cancel := context.WithTimeout(w.ctx, 30*time.Second)
    defer cancel()
    
    // 处理投诉
    if err := w.fetchAndProcessComplaint(ctx); err != nil {
        w.logger.Error("处理投诉失败",
            zap.Int("subject_id", w.subjectID),
            zap.Error(err))
    }
}

// fetchAndProcessComplaint 获取并处理投诉（带超时控制）
func (w *SubjectWorker) fetchAndProcessComplaint(ctx context.Context) error {
    // 使用channel接收结果，防止goroutine泄漏
    resultChan := make(chan error, 1)
    
    go func() {
        // API调用
        complaints, err := w.fetchComplaintList(ctx)
        if err != nil {
            resultChan <- err
            return
        }
        
        // 处理投诉
        for _, complaint := range complaints {
            if err := w.processComplaint(ctx, complaint); err != nil {
                resultChan <- err
                return
            }
        }
        
        resultChan <- nil
    }()
    
    // 等待结果或超时
    select {
    case err := <-resultChan:
        return err
    case <-ctx.Done():
        return ctx.Err()
    }
}

// sendPanicAlert 发送panic告警
func (w *SubjectWorker) sendPanicAlert(panicMsg, stack string) {
    // TODO: 集成Telegram/Sentry等告警系统
    // 这里简化实现
    w.logger.Error("🚨 PANIC告警",
        zap.Int("subject_id", w.subjectID),
        zap.String("message", panicMsg),
        zap.String("stack_preview", stack[:min(500, len(stack))]))
}

func min(a, b int) int {
    if a < b {
        return a
    }
    return b
}
```

### 5.4 资源隔离

| 资源 | 隔离方式 | 安全保护 |
|-----|---------|---------|
| HTTP Client | 每个协程独立Client实例 | 带超时控制 |
| Redis连接 | 连接池，支持并发 | 自动重连 |
| MySQL连接 | 连接池，GORM自动管理 | 心跳检测 |
| 内存缓存 | sync.Map 线程安全 | defer清理 |
| 日志 | Zap支持并发写入 | 异步刷盘 |

---

## 6. 证书管理方案（内存加载 - 安全方案）

### 6.1 问题描述

**场景：** 支付宝API调用需要证书文件，但证书可能存储在数据库中，本地不存在证书文件。

**涉及证书：**
1. **应用私钥** (`app_private_key`) - TEXT字段
2. **应用公钥证书** (`app_cert_content`) - TEXT/BLOB字段
3. **支付宝根证书** (`alipay_root_cert_content`) - TEXT/BLOB字段
4. **支付宝公钥证书** (`alipay_cert_content`) - TEXT/BLOB字段

**⚠️ 安全优先原则：禁止使用临时文件方案，采用内存加载**

### 6.2 数据库设计（证书字段）

```sql
-- 主体表扩展（假设已存在）
ALTER TABLE `subject` 
ADD COLUMN `app_private_key` TEXT COMMENT '应用私钥（PKCS8格式）',
ADD COLUMN `app_cert_content` TEXT COMMENT '应用公钥证书内容',
ADD COLUMN `alipay_root_cert_content` TEXT COMMENT '支付宝根证书内容',
ADD COLUMN `alipay_cert_content` TEXT COMMENT '支付宝公钥证书内容',
ADD COLUMN `cert_storage_type` ENUM('file', 'database') DEFAULT 'database' COMMENT '证书存储方式';

-- 或者独立的证书表
CREATE TABLE `subject_cert` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `subject_id` INT NOT NULL UNIQUE,
    `app_private_key` TEXT COMMENT '应用私钥',
    `app_cert_content` TEXT COMMENT '应用公钥证书',
    `alipay_root_cert_content` TEXT COMMENT '支付宝根证书',
    `alipay_cert_content` TEXT COMMENT '支付宝公钥证书',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_subject_id` (`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='主体证书表';
```

### 6.3 安全解决方案（内存加载 - 强制要求）

#### 方案：内存加载（推荐且强制）⭐⭐⭐⭐⭐

**核心原则：证书内容永不落盘**

**流程：**
```
从数据库读取加密的证书内容
    ↓
在内存中解密
    ↓
直接传递证书内容给SDK（字符串格式）
    ↓
SDK在内存中解析证书
    ↓
使用完毕后，显式清除内存引用
```

**优势：**
- ✅ **零文件IO** - 无磁盘写入，无泄露风险
- ✅ **高性能** - 无磁盘IO开销
- ✅ **高安全** - 证书不会残留在磁盘
- ✅ **易管理** - 无临时文件清理问题
- ✅ **可扩展** - 支持证书热更新

**实现要点：**
```go
// 支付宝Golang SDK已支持内存加载
// 使用 smartwalle/alipay v3 库

import "github.com/smartwalle/alipay/v3"

// 方法1：使用证书内容初始化（推荐）
client, err := alipay.New(
    appID,
    privateKeyContent,  // 私钥内容（字符串）
    true,               // 是否生产环境
)

// 设置证书内容（不使用文件路径）
err = client.LoadAppCertPublicKeyFromData([]byte(appCertContent))
err = client.LoadAliPayRootCertFromData([]byte(alipayRootCertContent))
err = client.LoadAliPayPublicCertFromData([]byte(alipayCertContent))
```

### 6.4 证书管理器实现

```go
package cert

import (
    "crypto/aes"
    "crypto/cipher"
    "encoding/base64"
    "fmt"
    "sync"
    "time"
    
    "github.com/smartwalle/alipay/v3"
    "go.uber.org/zap"
)

// CertManager 证书管理器
type CertManager struct {
    cache          map[int]*CachedCert  // subject_id -> cert
    mu             sync.RWMutex
    cacheTTL       time.Duration
    encryptionKey  []byte               // AES加密密钥
    logger         *zap.Logger
}

// CachedCert 缓存的证书
type CachedCert struct {
    SubjectID      int
    AlipayClient   *alipay.Client
    LoadedAt       time.Time
    ExpiresAt      time.Time
    Version        int  // 证书版本号
}

// NewCertManager 创建证书管理器
func NewCertManager(encryptionKey []byte, cacheTTL time.Duration, logger *zap.Logger) *CertManager {
    return &CertManager{
        cache:         make(map[int]*CachedCert),
        cacheTTL:      cacheTTL,
        encryptionKey: encryptionKey,
        logger:        logger,
    }
}

// LoadCert 加载证书并创建支付宝客户端（内存加载）
func (cm *CertManager) LoadCert(subject *Subject) (*alipay.Client, error) {
    // 检查缓存
    if client := cm.getFromCache(subject.ID, subject.CertVersion); client != nil {
        return client, nil
    }
    
    cm.mu.Lock()
    defer cm.mu.Unlock()
    
    // 双重检查
    if client := cm.getFromCacheUnsafe(subject.ID, subject.CertVersion); client != nil {
        return client, nil
    }
    
    // 从数据库解密证书
    privateKey, err := cm.decrypt(subject.EncryptedPrivateKey)
    if err != nil {
        return nil, fmt.Errorf("解密私钥失败: %w", err)
    }
    
    appCert, err := cm.decrypt(subject.EncryptedAppCert)
    if err != nil {
        return nil, fmt.Errorf("解密应用证书失败: %w", err)
    }
    
    alipayRootCert, err := cm.decrypt(subject.EncryptedAlipayRootCert)
    if err != nil {
        return nil, fmt.Errorf("解密根证书失败: %w", err)
    }
    
    alipayCert, err := cm.decrypt(subject.EncryptedAlipayCert)
    if err != nil {
        return nil, fmt.Errorf("解密支付宝证书失败: %w", err)
    }
    
    // 创建支付宝客户端（内存加载）
    client, err := alipay.New(
        subject.AppID,
        privateKey,
        true,  // 生产环境
    )
    if err != nil {
        return nil, fmt.Errorf("创建支付宝客户端失败: %w", err)
    }
    
    // 加载证书内容（不使用文件）
    if err := client.LoadAppCertPublicKeyFromData([]byte(appCert)); err != nil {
        return nil, fmt.Errorf("加载应用证书失败: %w", err)
    }
    
    if err := client.LoadAliPayRootCertFromData([]byte(alipayRootCert)); err != nil {
        return nil, fmt.Errorf("加载根证书失败: %w", err)
    }
    
    if err := client.LoadAliPayPublicCertFromData([]byte(alipayCert)); err != nil {
        return nil, fmt.Errorf("加载支付宝证书失败: %w", err)
    }
    
    // 缓存
    cachedCert := &CachedCert{
        SubjectID:    subject.ID,
        AlipayClient: client,
        LoadedAt:     time.Now(),
        ExpiresAt:    time.Now().Add(cm.cacheTTL),
        Version:      subject.CertVersion,
    }
    
    cm.cache[subject.ID] = cachedCert
    
    cm.logger.Info("证书加载成功（内存模式）",
        zap.Int("subject_id", subject.ID),
        zap.Int("version", subject.CertVersion))
    
    // 清除敏感信息引用（帮助GC）
    privateKey = ""
    appCert = ""
    alipayRootCert = ""
    alipayCert = ""
    
    return client, nil
}

// decrypt AES解密
func (cm *CertManager) decrypt(encryptedData string) (string, error) {
    ciphertext, err := base64.StdEncoding.DecodeString(encryptedData)
    if err != nil {
        return "", err
    }
    
    block, err := aes.NewCipher(cm.encryptionKey)
    if err != nil {
        return "", err
    }
    
    if len(ciphertext) < aes.BlockSize {
        return "", fmt.Errorf("密文太短")
    }
    
    iv := ciphertext[:aes.BlockSize]
    ciphertext = ciphertext[aes.BlockSize:]
    
    stream := cipher.NewCFBDecrypter(block, iv)
    stream.XORKeyStream(ciphertext, ciphertext)
    
    return string(ciphertext), nil
}

// getFromCache 从缓存获取（带锁）
func (cm *CertManager) getFromCache(subjectID, version int) *alipay.Client {
    cm.mu.RLock()
    defer cm.mu.RUnlock()
    return cm.getFromCacheUnsafe(subjectID, version)
}

// getFromCacheUnsafe 从缓存获取（无锁）
func (cm *CertManager) getFromCacheUnsafe(subjectID, version int) *alipay.Client {
    cached, exists := cm.cache[subjectID]
    if !exists {
        return nil
    }
    
    // 检查是否过期
    if time.Now().After(cached.ExpiresAt) {
        delete(cm.cache, subjectID)
        return nil
    }
    
    // 检查版本号
    if cached.Version != version {
        delete(cm.cache, subjectID)
        cm.logger.Info("证书版本变更，清除缓存",
            zap.Int("subject_id", subjectID),
            zap.Int("old_version", cached.Version),
            zap.Int("new_version", version))
        return nil
    }
    
    return cached.AlipayClient
}

// CleanExpired 清理过期缓存
func (cm *CertManager) CleanExpired() {
    cm.mu.Lock()
    defer cm.mu.Unlock()
    
    now := time.Now()
    count := 0
    
    for id, cached := range cm.cache {
        if now.After(cached.ExpiresAt) {
            delete(cm.cache, id)
            count++
        }
    }
    
    if count > 0 {
        cm.logger.Info("清理过期证书缓存", zap.Int("count", count))
    }
}

// InvalidateCache 使缓存失效
func (cm *CertManager) InvalidateCache(subjectID int) {
    cm.mu.Lock()
    defer cm.mu.Unlock()
    
    delete(cm.cache, subjectID)
    cm.logger.Info("证书缓存已失效", zap.Int("subject_id", subjectID))
}

// GetCacheStats 获取缓存统计
func (cm *CertManager) GetCacheStats() map[string]interface{} {
    cm.mu.RLock()
    defer cm.mu.RUnlock()
    
    return map[string]interface{}{
        "total_cached":  len(cm.cache),
        "cache_ttl_sec": cm.cacheTTL.Seconds(),
    }
}
```

### 6.5 数据库表添加版本字段

```sql
-- 添加证书版本号字段
ALTER TABLE `subject` 
ADD COLUMN `cert_version` INT DEFAULT 1 COMMENT '证书版本号（更新证书时递增）' AFTER `cert_storage_type`;

-- 创建触发器：证书更新时自动递增版本号
DELIMITER $$

CREATE TRIGGER `subject_cert_version_update`
BEFORE UPDATE ON `subject`
FOR EACH ROW
BEGIN
    IF NEW.app_private_key <> OLD.app_private_key 
       OR NEW.app_cert_content <> OLD.app_cert_content 
       OR NEW.alipay_root_cert_content <> OLD.alipay_root_cert_content 
       OR NEW.alipay_cert_content <> OLD.alipay_cert_content THEN
        SET NEW.cert_version = OLD.cert_version + 1;
    END IF;
END$$

DELIMITER ;
```

### 6.6 安全考虑

**1. 加密存储（数据库）** 🔒
```sql
-- 证书内容使用AES-256-CBC加密存储
-- 加密密钥从环境变量或KMS获取
INSERT INTO subject (
    app_private_key, 
    app_cert_content
) VALUES (
    AES_ENCRYPT(?, @encryption_key),
    AES_ENCRYPT(?, @encryption_key)
);
```

**2. 内存安全** 🧹
```go
// 证书使用完毕后，显式清除内存引用
defer func() {
    privateKey = ""
    appCert = ""
    // 帮助GC回收
    runtime.GC()
}()
```

**3. 访问控制** 🔐
```
- 数据库证书字段只允许特定服务账号访问
- 加密密钥存储在KMS或环境变量
- 定期轮换加密密钥
```

### 6.7 监控指标

| 指标 | 说明 | 告警阈值 |
|-----|------|---------|
| **证书缓存命中率** | 缓存命中次数/总请求次数 | < 80% |
| **证书加载耗时** | 从数据库加载证书的平均耗时 | > 100ms |
| **证书加载失败率** | 加载失败次数/总次数 | > 1% |
| **证书版本变更次数** | 每天证书更新的次数 | > 10次/天 |
| **缓存数量** | 当前缓存的证书数量 | > 1000 |

---

## 7. 核心流程

### 6.1 主流程

```
┌─ SubjectWorker 启动
│
├─ 步骤0: 加载主体证书
│   ├─ 从数据库读取证书信息
│   ├─ 判断存储类型（file/database）
│   ├─ database类型 → 创建临时证书文件
│   └─ 初始化支付宝API Client
│
├─ 步骤1: 调用支付宝投诉列表API
│   └─ 参数: app_id, page_size=1  (⚠️ 只取1条)
│
├─ 步骤2: 判断是否有投诉
│   ├─ 无投诉 → sleep 1-2秒 → 返回步骤1
│   └─ 有投诉 → 继续
│
├─ 步骤3: Redis防重检查
│   ├─ key = "complaint:processing:{complaint_no}"
│   ├─ 如果存在 → 跳过（其他协程正在处理）
│   └─ 否则 → SETNX 加锁（TTL 60秒）
│
├─ 步骤4: 调用投诉详情API
│   └─ 返回: 投诉信息 + 订单列表
│
├─ 步骤5: 拆分订单并入库
│   ├─ 遍历订单列表
│   ├─ 检查: (subject_id, complaint_no, order_no) 是否存在
│   ├─ 不存在 → INSERT
│   └─ 已存在 → 更新状态（如有变化）
│
├─ 步骤6: 查询未推送订单
│   └─ WHERE subject_id=? AND is_pushed=0
│
├─ 步骤7: 加入消息队列
│   ├─ INSERT INTO telegram_message_queue
│   ├─ 使用投诉模板渲染
│   └─ 标记: is_pushed = 1
│
├─ 步骤8: Redis解锁
│   └─ DEL "complaint:processing:{complaint_no}"
│
└─ 步骤9: sleep 1-2秒 → 返回步骤1
```

### 6.2 限流策略

**每个appid只取1条投诉**

- API参数: `page_size=1`
- 目的: 避免单次请求返回过多数据
- 优势: 降低内存占用，快速处理

---

## 8. 防重复机制（含安全修复）

### 8.1 四层防护

**第1层：API层限流**
```
每个appid只取1条投诉（page_size=1）
```

**第2层：Redis分布式锁（P0修复）**
```
key: complaint:processing:{complaint_no}
value: UUID（唯一标识）
TTL: 动态调整（基于订单数量）
用途: 防止多个协程同时处理同一投诉
⚠️ 使用Lua脚本保证原子性
```

**第3层：数据库唯一索引**
```sql
UNIQUE KEY `uniq_subject_complaint_order` 
(`subject_id`, `complaint_no`, `order_no`)
```

**第4层：is_pushed字段**
```sql
WHERE is_pushed = 0  -- 只查询未推送记录
UPDATE SET is_pushed = 1  -- 推送后标记
```

### 8.2 Redis分布式锁安全实现（P0修复）

```go
package lock

import (
    "context"
    "crypto/rand"
    "encoding/hex"
    "fmt"
    "time"
    
    "github.com/go-redis/redis/v8"
    "go.uber.org/zap"
)

// DistributedLock 分布式锁
type DistributedLock struct {
    redis   *redis.Client
    logger  *zap.Logger
}

// LockResult 锁结果
type LockResult struct {
    Key      string
    Value    string  // UUID
    acquired bool
}

// NewDistributedLock 创建分布式锁管理器
func NewDistributedLock(redisClient *redis.Client, logger *zap.Logger) *DistributedLock {
    return &DistributedLock{
        redis:  redisClient,
        logger: logger,
    }
}

// AcquireLock 获取锁（安全版本）
func (dl *DistributedLock) AcquireLock(ctx context.Context, key string, orderCount int) (*LockResult, error) {
    // 生成唯一UUID作为锁的值
    lockValue := generateUUID()
    
    // 🔴 P0修复：根据订单数量动态调整TTL
    ttl := calculateLockTTL(orderCount)
    
    // 尝试获取锁
    acquired, err := dl.redis.SetNX(ctx, key, lockValue, ttl).Result()
    if err != nil {
        return nil, fmt.Errorf("获取锁失败: %w", err)
    }
    
    if !acquired {
        return &LockResult{
            Key:      key,
            acquired: false,
        }, nil
    }
    
    dl.logger.Debug("获取锁成功",
        zap.String("key", key),
        zap.String("value", lockValue),
        zap.Duration("ttl", ttl))
    
    return &LockResult{
        Key:      key,
        Value:    lockValue,
        acquired: true,
    }, nil
}

// ReleaseLock 释放锁（安全版本 - 使用Lua脚本）
func (dl *DistributedLock) ReleaseLock(ctx context.Context, lockResult *LockResult) error {
    if lockResult == nil || !lockResult.acquired {
        return nil
    }
    
    // 🔴 P0修复：使用Lua脚本保证原子性
    // 只有持有锁的协程才能释放锁
    luaScript := `
        if redis.call("get", KEYS[1]) == ARGV[1] then
            return redis.call("del", KEYS[1])
        else
            return 0
        end
    `
    
    result, err := dl.redis.Eval(ctx, luaScript, []string{lockResult.Key}, lockResult.Value).Result()
    if err != nil {
        return fmt.Errorf("释放锁失败: %w", err)
    }
    
    if result.(int64) == 1 {
        dl.logger.Debug("释放锁成功", zap.String("key", lockResult.Key))
    } else {
        dl.logger.Warn("锁已被其他协程释放或已过期",
            zap.String("key", lockResult.Key),
            zap.String("value", lockResult.Value))
    }
    
    return nil
}

// RenewLock 续期锁（防止长时间处理超时）
func (dl *DistributedLock) RenewLock(ctx context.Context, lockResult *LockResult, ttl time.Duration) error {
    if lockResult == nil || !lockResult.acquired {
        return nil
    }
    
    // 🔴 使用Lua脚本续期（只有持有锁的协程才能续期）
    luaScript := `
        if redis.call("get", KEYS[1]) == ARGV[1] then
            return redis.call("expire", KEYS[1], ARGV[2])
        else
            return 0
        end
    `
    
    result, err := dl.redis.Eval(ctx, luaScript, []string{lockResult.Key}, lockResult.Value, int(ttl.Seconds())).Result()
    if err != nil {
        return fmt.Errorf("续期锁失败: %w", err)
    }
    
    if result.(int64) == 1 {
        dl.logger.Debug("续期锁成功",
            zap.String("key", lockResult.Key),
            zap.Duration("ttl", ttl))
    } else {
        return fmt.Errorf("锁已丢失或已过期")
    }
    
    return nil
}

// AutoRenewLock 自动续期（后台协程）
func (dl *DistributedLock) AutoRenewLock(ctx context.Context, lockResult *LockResult, ttl time.Duration, stopChan chan struct{}) {
    ticker := time.NewTicker(ttl / 2)
    defer ticker.Stop()
    
    for {
        select {
        case <-ctx.Done():
            return
        case <-stopChan:
            return
        case <-ticker.C:
            if err := dl.RenewLock(ctx, lockResult, ttl); err != nil {
                dl.logger.Error("自动续期失败",
                    zap.String("key", lockResult.Key),
                    zap.Error(err))
                return
            }
        }
    }
}

// generateUUID 生成UUID
func generateUUID() string {
    b := make([]byte, 16)
    rand.Read(b)
    return hex.EncodeToString(b)
}

// calculateLockTTL 根据订单数量动态计算TTL
func calculateLockTTL(orderCount int) time.Duration {
    // 基础TTL: 60秒
    baseTTL := 60 * time.Second
    
    // 每个订单额外增加500ms
    additionalTTL := time.Duration(orderCount) * 500 * time.Millisecond
    
    // 最大TTL: 5分钟
    maxTTL := 5 * time.Minute
    
    ttl := baseTTL + additionalTTL
    if ttl > maxTTL {
        ttl = maxTTL
    }
    
    return ttl
}
```

### 8.3 使用示例

```go
// 获取锁
lockResult, err := distributedLock.AcquireLock(ctx, 
    fmt.Sprintf("complaint:processing:%s", complaintNo),
    len(complaint.Orders))  // 传入订单数量

if err != nil {
    return fmt.Errorf("获取锁失败: %w", err)
}

if !lockResult.acquired {
    log.Info("投诉正在被其他协程处理，跳过", 
        zap.String("complaint_no", complaintNo))
    return nil
}

// 🔴 P0修复：确保一定会释放锁
defer func() {
    if err := distributedLock.ReleaseLock(context.Background(), lockResult); err != nil {
        log.Error("释放锁失败", zap.Error(err))
    }
}()

// 🔴 如果处理时间较长，启动自动续期
if len(complaint.Orders) > 50 {
    stopRenew := make(chan struct{})
    defer close(stopRenew)
    
    go distributedLock.AutoRenewLock(ctx, lockResult, 60*time.Second, stopRenew)
}

// 处理投诉
processComplaint(complaint)
```

### 8.4 防止重复推送

**场景：同一订单在多个投诉中出现**

```
投诉A (COM001) → 订单 2024102801
投诉B (COM002) → 订单 2024102801 (相同订单)
```

**解决方案：**
```sql
-- 查询逻辑
SELECT * FROM alipay_complaint_detail
WHERE order_no = '2024102801'
  AND is_pushed = 1

-- 如果存在 → 跳过推送
-- 否则 → 推送并标记
```

---

## 9. 内存管理策略

### 8.1 内存泄漏预防

| 风险点 | 预防措施 |
|-------|---------|
| **HTTP Response Body** | 每次请求后显式关闭: `defer resp.Body.Close()` |
| **协程泄漏** | 使用 `context.WithCancel` 控制生命周期 |
| **Channel阻塞** | 使用 buffered channel 或 select timeout |
| **大对象缓存** | 避免长期持有大对象，及时释放 |
| **数据库连接** | 使用连接池，设置 `MaxIdleConns`、`MaxOpenConns` |

### 8.2 内存优化措施

**1. 简化JSON存储**
```
❌ 完整存储API返回 (可能数MB)
✅ 只存储必要字段  (几KB)
```

**2. 避免全量加载**
```
❌ 一次性加载所有投诉到内存
✅ 分页查询，逐条处理
```

**3. 及时释放资源**
```
- 处理完一条投诉后，立即清理临时变量
- 使用 sync.Pool 复用对象
- 定期触发 runtime.GC()（可选）
```

**4. 限制协程数量**
```
使用 ants 协程池:
  - 设置最大协程数
  - 避免无限制创建
```

### 8.3 内存监控

```
运行时监控指标:
  - runtime.NumGoroutine()  // 协程数量
  - runtime.MemStats.Alloc  // 已分配内存
  - runtime.MemStats.Sys    // 系统内存
  - runtime.GC().NumGC      // GC次数
```

---

## 10. 日志优化方案

### 9.1 日志过大问题

**原因分析：**
- API请求/响应记录过详细
- 每条订单都记录完整信息
- 未做日志分级和过滤
- 未做日志轮转和压缩

### 9.2 优化策略

**1. 日志分级**
```
级别      用途                   记录内容
────────────────────────────────────────────────
ERROR    严重错误               API调用失败、数据库异常
WARN     警告                  投诉状态异常、数据不完整
INFO     关键事件              启动/停止、主体变更、推送成功
DEBUG    调试信息（可选）       详细的API请求/响应
```

**生产环境：只记录 INFO 及以上级别**

**2. 日志轮转**
```yaml
logging:
  level: info
  file: /var/log/complaint-monitor/app.log
  max_size: 100       # 单文件最大100MB
  max_backups: 7      # 保留7个备份
  max_age: 7          # 保留7天
  compress: true      # 压缩旧日志
```

**3. 精简日志内容**

**❌ 不推荐：**
```
记录完整API响应（可能数KB）
记录所有订单详情
```

**✅ 推荐：**
```
只记录关键字段：
  - 投诉单号
  - 订单数量
  - 处理结果
  - 耗时
```

**4. 异步日志写入**
```
使用 Zap 的异步模式:
  - 日志先写入缓冲区
  - 批量刷盘
  - 降低IO压力
```

### 9.3 MySQL日志优化

**问题：频繁INSERT导致binlog过大**

**优化措施：**

**1. 批量插入**
```
❌ 逐条INSERT  (N次SQL)
✅ 批量INSERT (1次SQL，多行数据)
```

**2. 控制binlog大小**
```sql
-- my.cnf 配置
max_binlog_size = 100M          # 单个binlog文件100MB
expire_logs_days = 3            # 只保留3天
binlog_format = ROW             # 使用ROW格式（更高效）
```

**3. 定期清理**
```sql
-- 清理7天前的binlog
PURGE BINARY LOGS BEFORE DATE_SUB(NOW(), INTERVAL 7 DAY);
```

**4. 减少不必要的更新**
```
只有状态变更时才UPDATE
避免频繁更新updated_at字段
```

---

## 11. 黑名单集成

### 11.1 集成概述

**核心理念：** 投诉监控系统与黑名单系统解耦，通过数据库交互实现联动

```
Golang投诉服务
    ↓
检测到风险投诉
    ↓
写入 alipay_blacklist 表
    ↓
写入 telegram_message_queue 表
    ↓
PHP黑名单系统自动接管
    ↓
TelegramMessageMonitor推送通知
```

### 11.2 触发规则

**⚠️ 重要：所有投诉都触发自动拉黑**

```
检测到投诉 → 立即写入黑名单表 → 写入消息队列 → 推送通知
```

**拉黑策略：**
- ✅ **零容忍政策**：任何投诉都立即加入黑名单
- ✅ **累计风险次数**：重复投诉时，`risk_count` 自动递增
- ✅ **风险等级标识**：根据投诉次数、类型、金额等综合评估风险等级
- ✅ **优先级推送**：高风险投诉优先推送

**风险等级评估（用于标识，不影响是否拉黑）：**

| 风险等级 | 条件 | 说明 |
|---------|------|------|
| **🔴 极高风险 (critical)** | 投诉次数≥3次 OR 投诉类型="恶意骗取退款" OR 单次涉及订单≥5笔 | 最高优先级推送 |
| **🟠 高风险 (high)** | 投诉次数=2次 OR 投诉金额>5000元 OR 24小时内重复投诉 | 高优先级推送 |
| **🟡 中风险 (medium)** | 投诉次数=1次 AND 投诉金额1000-5000元 | 普通优先级推送 |
| **🟢 低风险 (low)** | 首次投诉 AND 投诉金额<1000元 | 普通优先级推送 |

### 11.3 数据库交互

**写入黑名单表：**
```sql
-- Golang服务执行
INSERT INTO alipay_blacklist 
(alipay_user_id, device_code, ip_address, risk_count, remark, last_risk_time)
VALUES (?, ?, ?, ?, ?, NOW())
ON DUPLICATE KEY UPDATE 
    risk_count = risk_count + 1,
    last_risk_time = NOW(),
    remark = CONCAT(remark, '; ', VALUES(remark));
```

**写入消息队列：**
```sql
-- Golang服务执行
INSERT INTO telegram_message_queue
(title, content, priority, status, message_type, template_name, template_data)
VALUES (
    '🚨 新用户加入黑名单（投诉触发）',
    '',
    2,  -- 高优先级
    'pending',
    'template',
    'blacklist',
    JSON_OBJECT(
        'action', 'insert',
        'alipay_user_id', ?,
        'device_code', ?,
        'ip_address', ?,
        'risk_count', ?,
        'remark', ?,
        'message', '因投诉行为触发自动拉黑',
        'complaint_no', ?,
        'complaint_type', ?,
        'order_count', ?
    )
);
```

### 11.4 实现逻辑（伪代码）

```go
// 处理投诉详情
func (s *ComplaintService) ProcessComplaintDetail(complaint *Complaint) error {
    // 1. 保存投诉订单到数据库
    for _, order := range complaint.Orders {
        s.saveComplaintOrder(complaint, order)
    }
    
    // 2. 风险评估（用于设置推送优先级和显示，不影响是否拉黑）
    riskLevel := s.assessRisk(complaint)
    
    // 3. 所有投诉都触发拉黑 ⚠️
    // 3.1 写入黑名单表
    blacklistID := s.addToBlacklist(complaint)
    
    // 3.2 写入黑名单通知消息队列
    s.addBlacklistNotification(complaint, blacklistID, riskLevel)
    
    log.Info("用户已加入黑名单（投诉触发）", map[string]interface{}{
        "complaint_no":   complaint.ComplaintNo,
        "alipay_user_id": complaint.ComplainantID,
        "risk_level":     riskLevel,
        "order_count":    complaint.OrderCount,
    })
    
    // 4. 写入投诉通知消息队列
    s.addComplaintNotification(complaint, riskLevel)
    
    return nil
}

// 风险评估（仅用于等级标识和优先级）
func (s *ComplaintService) assessRisk(complaint *Complaint) string {
    // 查询该用户历史投诉次数
    historyCount := s.getComplaintCountAll(complaint.ComplainantID)
    
    // 极高风险条件
    if historyCount >= 3 {
        return "critical"
    }
    
    if complaint.ComplaintType == "恶意骗取退款" || complaint.ComplaintType == "虚假交易" {
        return "critical"
    }
    
    if complaint.OrderCount >= 5 {
        return "critical"
    }
    
    // 高风险条件
    if historyCount == 2 {
        return "high"
    }
    
    // 查询24小时内投诉次数
    count24h := s.getComplaintCountLast24h(complaint.ComplainantID)
    if count24h >= 2 {
        return "high"
    }
    
    // 计算总投诉金额
    totalAmount := s.calculateTotalComplaintAmount(complaint)
    if totalAmount > 5000 {
        return "high"
    }
    
    // 中风险条件
    if historyCount == 1 && totalAmount >= 1000 && totalAmount <= 5000 {
        return "medium"
    }
    
    // 低风险（首次投诉且金额较小）
    return "low"
}

// 添加到黑名单
func (s *ComplaintService) addToBlacklist(complaint *Complaint) int64 {
    blacklist := &AlipayBlacklist{
        AlipayUserID: complaint.ComplainantID,
        DeviceCode:   complaint.DeviceCode,
        IPAddress:    complaint.IPAddress,
        RiskCount:    1,
        Remark:       fmt.Sprintf("投诉触发拉黑: %s (投诉单号: %s)", 
                                   complaint.ComplaintType, complaint.ComplaintNo),
        LastRiskTime: time.Now(),
    }
    
    // 使用ON DUPLICATE KEY UPDATE处理重复
    result := db.Clauses(clause.OnConflict{
        Columns: []clause.Column{{Name: "alipay_user_id"}, {Name: "device_code"}, {Name: "ip_address"}},
        DoUpdates: clause.AssignmentColumns([]string{"risk_count", "last_risk_time", "remark"}),
    }).Create(blacklist)
    
    return blacklist.ID
}

// 添加黑名单通知到消息队列
func (s *ComplaintService) addBlacklistNotification(complaint *Complaint, blacklistID int64, riskLevel string) {
    // 根据风险等级设置优先级
    priority := s.getPriorityByRiskLevel(riskLevel)
    
    // 查询黑名单记录，获取风险次数
    blacklist := s.getBlacklistByID(blacklistID)
    
    templateData := map[string]interface{}{
        "action":          blacklist.RiskCount == 1 ? "insert" : "update",
        "alipay_user_id":  complaint.ComplainantID,
        "device_code":     complaint.DeviceCode,
        "ip_address":      complaint.IPAddress,
        "risk_count":      blacklist.RiskCount,
        "remark":          fmt.Sprintf("投诉触发拉黑: %s", complaint.ComplaintType),
        "message":         "所有投诉都会触发自动拉黑",
        "complaint_no":    complaint.ComplaintNo,
        "complaint_type":  complaint.ComplaintType,
        "order_count":     complaint.OrderCount,
        "complaint_time":  complaint.ComplaintTime.Format("2006-01-02 15:04:05"),
    }
    
    message := &TelegramMessageQueue{
        Title:        "🚨 投诉触发黑名单",
        Content:      "",
        Priority:     priority,
        Status:       "pending",
        MessageType:  "template",
        TemplateName: "blacklist",
        TemplateData: templateData,
    }
    
    db.Create(message)
}

// 根据风险等级获取推送优先级
func (s *ComplaintService) getPriorityByRiskLevel(riskLevel string) int {
    switch riskLevel {
    case "critical":
        return 1 // 极高风险，最高优先级
    case "high":
        return 2 // 高风险
    case "medium":
        return 5 // 中风险
    default:
        return 7 // 低风险
    }
}
```

### 11.5 配置项

```yaml
# config.yaml
blacklist:
  enabled: true                    # 是否启用自动拉黑（固定为true）
  
  # 风险等级评估阈值（用于优先级，不影响是否拉黑）
  risk_assessment:
    # 极高风险阈值
    critical_complaint_count: 3    # 历史投诉次数>=3次
    critical_order_count: 5        # 单次投诉涉及订单>=5笔
    critical_types:                # 极高风险投诉类型
      - "恶意骗取退款"
      - "虚假交易"
      - "欺诈行为"
    
    # 高风险阈值
    high_complaint_24h: 2          # 24小时内投诉>=2次
    high_amount: 5000              # 投诉金额>5000元
    
    # 中风险阈值
    medium_amount_min: 1000        # 投诉金额>=1000元
    medium_amount_max: 5000        # 投诉金额<=5000元
```

### 11.6 黑名单模板扩展

**扩展字段：** 在原有黑名单模板基础上，新增投诉相关字段

```json
{
  "action": "insert",
  "alipay_user_id": "2088100200300400",
  "device_code": "device_abc123",
  "ip_address": "192.168.1.100",
  "risk_count": 1,
  "remark": "投诉触发拉黑: 商品未收到",
  "message": "因多次投诉触发自动拉黑",
  
  // 新增投诉相关字段
  "complaint_no": "COM20251028001",
  "complaint_type": "商品未收到",
  "order_count": 3,
  "complaint_time": "2025-10-28 15:30:00"
}
```

**模板渲染效果：**
```
🚨 新用户加入黑名单（投诉触发）

━━━━━━━━━━━━━━━━━━━━━

📱 支付宝用户ID：
2088100200300400

📋 投诉单号：COM20251028001
📝 投诉类型：商品未收到
📦 涉及订单：3笔
⏰ 投诉时间：2025-10-28 15:30:00

💻 设备码：
device_abc123

🌐 IP地址：
192.168.1.100

⚠️ 风险次数：1 次 🟢 低风险

📝 备注信息：
投诉触发拉黑: 商品未收到

🔔 触发类型：自动拉黑

💬 详细信息：
因多次投诉触发自动拉黑

━━━━━━━━━━━━━━━━━━━━━
```

### 11.7 后续处理流程

**PHP系统接管：**

```
1. TelegramMessageMonitor每3秒检查消息队列
   ↓
2. 发现 template_name='blacklist' 的消息
   ↓
3. 调用 BlacklistTemplate::render() 渲染
   ↓
4. TelegramRobotPush::sendTemplate() 推送
   ↓
5. 更新消息状态为 'sent'
   ↓
6. Telegram群组收到通知
```

**管理员后续操作：**
- 收到Telegram通知
- 登录管理后台查看详情
- 人工审核确认
- 决定是否解除黑名单

### 11.8 数据流转完整示意图

```
┌─────────────────────────────────────────────────────────────┐
│                    Golang投诉监控服务                        │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
            ┌───────────────────────────────┐
            │  获取投诉详情                  │
            │  - 投诉人ID                   │
            │  - 设备码/IP                  │
            │  - 投诉类型                   │
            │  - 订单列表                   │
            └───────────────────────────────┘
                            │
                            ▼
            ┌───────────────────────────────┐
            │  风险评估                      │
            │  - 24h内投诉次数              │
            │  - 投诉类型                   │
            │  - 订单数量                   │
            │  - 金额大小                   │
            └───────────────────────────────┘
                            │
                ┌───────────┴───────────┐
                ▼                       ▼
    ┌───────────────────┐   ┌───────────────────┐
    │  达到拉黑条件      │   │  未达到拉黑条件    │
    └───────────────────┘   └───────────────────┘
                │                       │
                ▼                       ▼
    ┌───────────────────┐   ┌───────────────────┐
    │ 写入黑名单表       │   │ 仅记录投诉         │
    │ alipay_blacklist  │   │ complaint_detail  │
    └───────────────────┘   └───────────────────┘
                │                       │
                ▼                       │
    ┌───────────────────┐               │
    │ 写入消息队列       │               │
    │ (黑名单通知)       │               │
    └───────────────────┘               │
                │                       │
                └───────────┬───────────┘
                            ▼
            ┌───────────────────────────────┐
            │  写入消息队列                  │
            │  (投诉通知)                   │
            │  telegram_message_queue       │
            └───────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                    PHP消息监控系统                           │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
            ┌───────────────────────────────┐
            │  TelegramMessageMonitor       │
            │  (每3秒检查)                  │
            └───────────────────────────────┘
                            │
                ┌───────────┴───────────┐
                ▼                       ▼
    ┌───────────────────┐   ┌───────────────────┐
    │  黑名单通知        │   │  投诉通知          │
    │  BlacklistTemplate│   │  ComplaintTemplate│
    └───────────────────┘   └───────────────────┘
                │                       │
                └───────────┬───────────┘
                            ▼
            ┌───────────────────────────────┐
            │  TelegramRobotPush            │
            │  推送到Telegram               │
            └───────────────────────────────┘
                            │
                            ▼
                     Telegram群组
```

### 11.9 监控指标扩展

| 指标 | 说明 | 告警阈值 |
|-----|------|---------|
| **投诉触发拉黑次数** | 每小时因投诉触发的拉黑数（等于投诉数） | > 50/h |
| **重复拉黑次数** | 黑名单用户再次投诉触发的数量 | > 10/h |
| **极高风险投诉占比** | 极高风险投诉占总投诉的比例 | > 30% |
| **平均响应时间** | 从投诉发生到推送通知的时间 | > 5秒 |

### 11.10 投诉推送模板规范

为了便于核对和统一管理，投诉通知也需要使用标准化的模板格式。

#### 11.10.1 投诉模板字段定义

```json
{
  "complaint_no": "COM20251028001",              // 投诉单号
  "complaint_type": "商品未收到",                 // 投诉类型
  "complaint_status": "待处理",                  // 投诉状态
  "complainant_id": "2088100200300400",         // 投诉人ID
  "merchant_order_no": "M20251028001",          // 商户订单号
  "platform_order_no": "2025102822001234567",   // 平台订单号
  "order_amount": "299.00",                     // 订单金额
  "complaint_amount": "299.00",                 // 投诉金额
  "complaint_time": "2025-10-28 15:30:00",      // 投诉时间
  "complaint_reason": "商品未收到，申请退款",     // 投诉原因
  "merchant_name": "测试商户",                   // 商户名称
  "subject_name": "测试主体",                    // 主体名称
  "order_count": 1,                             // 涉及订单数
  "is_auto_blacklist": true,                    // 是否自动拉黑（固定为true）
  "risk_level": "low",                          // 风险等级: low/medium/high/critical
  "history_complaint_count": 1                  // 历史投诉总次数
}
```

#### 11.10.2 投诉模板渲染效果

**⚠️ 注意：所有投诉都会触发拉黑**

**示例1：首次投诉（低风险）**
```
🚨 新投诉通知（已触发拉黑）

━━━━━━━━━━━━━━━━━━━━━

🆔 投诉单号：
COM20251028001

📝 投诉类型：商品未收到
📊 投诉状态：待处理
⏰ 投诉时间：2025-10-28 15:30:00

━━━━━━━━━━━━━━━━━━━━━

👤 投诉人ID：
2088100200300400

🏪 商户名称：测试商户
🏢 主体名称：测试主体

━━━━━━━━━━━━━━━━━━━━━

📦 订单信息：
  • 商户订单号：M20251028001
  • 平台订单号：2025102822001234567
  • 订单金额：¥299.00
  • 投诉金额：¥299.00

━━━━━━━━━━━━━━━━━━━━━

💬 投诉原因：
商品未收到，申请退款

━━━━━━━━━━━━━━━━━━━━━

⚠️ 风险等级：🟢 低风险
📦 涉及订单：1笔
🚫 自动拉黑：✅ 已触发

⚡ 该用户已自动加入黑名单

━━━━━━━━━━━━━━━━━━━━━
```

**示例2：多订单高风险投诉**
```
🚨 高风险投诉通知（已触发拉黑）

━━━━━━━━━━━━━━━━━━━━━

🆔 投诉单号：
COM20251028002

📝 投诉类型：恶意骗取退款
📊 投诉状态：待处理
⏰ 投诉时间：2025-10-28 16:45:00

━━━━━━━━━━━━━━━━━━━━━

👤 投诉人ID：
2088100200300500

🏪 商户名称：测试商户B
🏢 主体名称：测试主体B

━━━━━━━━━━━━━━━━━━━━━

📦 订单信息：
  • 涉及订单：5笔
  • 总投诉金额：¥1,500.00

订单列表：
1. M20251028001 - ¥300.00
2. M20251028002 - ¥300.00
3. M20251028003 - ¥300.00
4. M20251028004 - ¥300.00
5. M20251028005 - ¥300.00

━━━━━━━━━━━━━━━━━━━━━

💬 投诉原因：
恶意骗取退款，涉及多笔订单

━━━━━━━━━━━━━━━━━━━━━

⚠️ 风险等级：🔴 极高风险
📦 涉及订单：5笔
🚫 自动拉黑：✅ 已触发
📊 历史投诉次数：5次

⚡ 该用户已自动加入黑名单，请立即处理！

━━━━━━━━━━━━━━━━━━━━━
```

#### 11.10.3 PHP投诉模板实现

**文件路径：** `app/service/robot/templates/ComplaintTemplate.php`

```php
<?php

namespace app\service\robot\templates;

/**
 * 投诉通知模板
 */
class ComplaintTemplate
{
    /**
     * 渲染投诉通知消息
     * 
     * @param array $data 投诉数据
     * @return string
     */
    public function render(array $data): string
    {
        $complaintNo = htmlspecialchars($data['complaint_no'] ?? '', ENT_QUOTES, 'UTF-8');
        $complaintType = htmlspecialchars($data['complaint_type'] ?? '未知', ENT_QUOTES, 'UTF-8');
        $complaintStatus = htmlspecialchars($data['complaint_status'] ?? '待处理', ENT_QUOTES, 'UTF-8');
        $complainantId = htmlspecialchars($data['complainant_id'] ?? '', ENT_QUOTES, 'UTF-8');
        $merchantName = htmlspecialchars($data['merchant_name'] ?? '未知', ENT_QUOTES, 'UTF-8');
        $subjectName = htmlspecialchars($data['subject_name'] ?? '未知', ENT_QUOTES, 'UTF-8');
        $complaintTime = $data['complaint_time'] ?? date('Y-m-d H:i:s');
        $complaintReason = htmlspecialchars($data['complaint_reason'] ?? '无', ENT_QUOTES, 'UTF-8');
        $orderCount = (int)($data['order_count'] ?? 1);
        $riskLevel = $data['risk_level'] ?? 'low';
        
        // ⚠️ 所有投诉都触发拉黑
        $isAutoBlacklist = true;
        
        // 风险等级图标
        $riskIcon = $this->getRiskLevelIcon($riskLevel);
        $riskText = $this->getRiskLevelText($riskLevel);
        
        // 标题和图标（所有投诉都使用警告图标）
        $icon = '🚨';
        $title = $riskLevel === 'critical' || $riskLevel === 'high' 
                 ? '高风险投诉通知（已触发拉黑）' 
                 : '新投诉通知（已触发拉黑）';
        
        $html = <<<HTML
{$icon} <b>{$title}</b>

━━━━━━━━━━━━━━━━━━━━━

🆔 <b>投诉单号：</b>
<code>{$complaintNo}</code>

📝 <b>投诉类型：</b>{$complaintType}
📊 <b>投诉状态：</b>{$complaintStatus}
⏰ <b>投诉时间：</b>{$complaintTime}

━━━━━━━━━━━━━━━━━━━━━

👤 <b>投诉人ID：</b>
<code>{$complainantId}</code>

🏪 <b>商户名称：</b>{$merchantName}
🏢 <b>主体名称：</b>{$subjectName}

━━━━━━━━━━━━━━━━━━━━━

HTML;

        // 订单信息
        if ($orderCount === 1) {
            // 单订单
            $merchantOrderNo = htmlspecialchars($data['merchant_order_no'] ?? '', ENT_QUOTES, 'UTF-8');
            $platformOrderNo = htmlspecialchars($data['platform_order_no'] ?? '', ENT_QUOTES, 'UTF-8');
            $orderAmount = $data['order_amount'] ?? '0.00';
            $complaintAmount = $data['complaint_amount'] ?? '0.00';
            
            $html .= <<<HTML
📦 <b>订单信息：</b>
  • 商户订单号：{$merchantOrderNo}
  • 平台订单号：{$platformOrderNo}
  • 订单金额：¥{$orderAmount}
  • 投诉金额：¥{$complaintAmount}

HTML;
        } else {
            // 多订单
            $totalAmount = $data['total_complaint_amount'] ?? '0.00';
            $html .= <<<HTML
📦 <b>订单信息：</b>
  • 涉及订单：{$orderCount}笔
  • 总投诉金额：¥{$totalAmount}

HTML;
            
            // 订单列表（如果提供）
            if (isset($data['order_list']) && is_array($data['order_list'])) {
                $html .= "\n<b>订单列表：</b>\n";
                foreach ($data['order_list'] as $index => $order) {
                    $orderNo = htmlspecialchars($order['merchant_order_no'] ?? '', ENT_QUOTES, 'UTF-8');
                    $amount = $order['amount'] ?? '0.00';
                    $num = $index + 1;
                    $html .= "{$num}. {$orderNo} - ¥{$amount}\n";
                }
            }
            $html .= "\n";
        }
        
        $html .= <<<HTML
━━━━━━━━━━━━━━━━━━━━━

💬 <b>投诉原因：</b>
{$complaintReason}

━━━━━━━━━━━━━━━━━━━━━

⚠️ <b>风险等级：</b>{$riskIcon} {$riskText}
📦 <b>涉及订单：</b>{$orderCount}笔
🚫 <b>自动拉黑：</b>✅ 已触发

HTML;

        // 历史投诉次数（如果提供）
        $historyCount = $data['history_complaint_count'] ?? 1;
        if ($historyCount > 1) {
            $html .= <<<HTML
📊 <b>历史投诉次数：</b>{$historyCount}次

HTML;
        }
        
        // 根据风险等级显示不同的提示信息
        if ($riskLevel === 'critical' || $riskLevel === 'high') {
            $html .= <<<HTML

⚡ <b>该用户已自动加入黑名单，请立即处理！</b>

HTML;
        } else {
            $html .= <<<HTML

⚡ <b>该用户已自动加入黑名单</b>

HTML;
        }
        
        $html .= "━━━━━━━━━━━━━━━━━━━━━";
        
        return $html;
    }
    
    /**
     * 获取风险等级图标
     */
    private function getRiskLevelIcon(string $level): string
    {
        return match($level) {
            'critical' => '🔴',
            'high' => '🟠',
            'medium' => '🟡',
            'low' => '🟢',
            default => '⚪',
        };
    }
    
    /**
     * 获取风险等级文本
     */
    private function getRiskLevelText(string $level): string
    {
        return match($level) {
            'critical' => '极高风险',
            'high' => '高风险',
            'medium' => '中风险',
            'low' => '低风险',
            default => '未知',
        };
    }
}
```

#### 11.10.4 Golang写入投诉消息队列

```go
// 添加投诉通知到消息队列
func (s *ComplaintService) addComplaintNotification(complaint *Complaint, riskLevel string) error {
    // ⚠️ 所有投诉都触发拉黑
    isAutoBlacklist := true
    
    // 查询该用户历史投诉次数
    historyCount := s.getComplaintCountAll(complaint.ComplainantID)
    
    // 准备模板数据
    templateData := map[string]interface{}{
        "complaint_no":            complaint.ComplaintNo,
        "complaint_type":          complaint.ComplaintType,
        "complaint_status":        complaint.Status,
        "complainant_id":          complaint.ComplainantID,
        "merchant_name":           complaint.MerchantName,
        "subject_name":            complaint.SubjectName,
        "complaint_time":          complaint.ComplaintTime.Format("2006-01-02 15:04:05"),
        "complaint_reason":        complaint.ComplaintReason,
        "order_count":             complaint.OrderCount,
        "is_auto_blacklist":       isAutoBlacklist,
        "risk_level":              riskLevel,
        "history_complaint_count": historyCount,
    }
    
    // 单订单或多订单
    if complaint.OrderCount == 1 && len(complaint.Orders) > 0 {
        order := complaint.Orders[0]
        templateData["merchant_order_no"] = order.MerchantOrderNo
        templateData["platform_order_no"] = order.PlatformOrderNo
        templateData["order_amount"] = order.OrderAmount
        templateData["complaint_amount"] = order.ComplaintAmount
    } else {
        // 多订单
        totalAmount := 0.0
        orderList := make([]map[string]interface{}, 0)
        
        for _, order := range complaint.Orders {
            totalAmount += order.ComplaintAmount
            orderList = append(orderList, map[string]interface{}{
                "merchant_order_no": order.MerchantOrderNo,
                "amount":           order.ComplaintAmount,
            })
        }
        
        templateData["total_complaint_amount"] = fmt.Sprintf("%.2f", totalAmount)
        templateData["order_list"] = orderList
    }
    
    // 根据风险等级设置推送优先级
    priority := s.getPriorityByRiskLevel(riskLevel)
    
    message := &TelegramMessageQueue{
        Title:        fmt.Sprintf("投诉通知: %s", complaint.ComplaintNo),
        Content:      "",
        Priority:     priority,
        Status:       "pending",
        MessageType:  "template",
        TemplateName: "complaint",
        TemplateData: templateData,
    }
    
    return db.Create(message).Error
}
```

#### 11.10.5 消息监控进程模板路由

在 `TelegramMessageMonitor::processMessageQueue()` 方法中，需要添加投诉模板的路由：

```php
// 根据模板名称选择模板类
switch ($templateName) {
    case 'blacklist':
        $template = new BlacklistTemplate();
        $renderedContent = $template->render($templateData);
        $result = $robot->sendHtml($renderedContent, $options);
        break;
        
    case 'complaint':  // 新增投诉模板
        $template = new ComplaintTemplate();
        $renderedContent = $template->render($templateData);
        $result = $robot->sendHtml($renderedContent, $options);
        break;
        
    default:
        throw new \Exception("不支持的模板类型: {$templateName}");
}
```

#### 11.10.6 投诉通知优先级规则

| 风险等级 | 优先级值 | 说明 | 推送延迟 |
|---------|---------|------|---------|
| **critical** | 1 | 极高风险，立即推送 | 实时 |
| **high** | 2 | 高风险，优先推送 | < 5秒 |
| **medium** | 5 | 中风险，正常推送 | < 10秒 |
| **low** | 7 | 低风险，延后推送 | < 30秒 |

---

## 12. 消息推送

### 12.1 推送流程

```
Golang Service 
  → INSERT INTO telegram_message_queue
      (使用投诉模板数据)

PHP TelegramMessageMonitor
  → 每3秒查询队列
  → 渲染模板
  → 推送到Telegram
```

### 10.2 消息模板数据结构

```json
{
  "title": "🚨 新投诉通知",
  "content": "",
  "priority": 2,
  "status": "pending",
  "message_type": "template",
  "template_name": "complaint",
  "template_data": {
    "subject_id": 1,
    "complaint_no": "COM20251028001",
    "complaint_type": "商品未收到",
    "complaint_reason": "已付款3天未发货",
    "complaint_amount": 1280.00,
    "order_count": 2,
    "orders": [
      {
        "order_no": "2024102801",
        "merchant_order_no": "M001",
        "order_amount": 680.00
      }
    ]
  }
}
```

---

## 13. 配置说明

### 13.1 配置文件 (config.yaml)

```yaml
# 服务配置
service:
  name: complaint-monitor
  version: 1.0.0
  
# 数据库配置
database:
  host: 127.0.0.1
  port: 3306
  user: third_party_payment
  password: rA8f@D2kLmZx!3pQ
  database: third_party_payment
  max_idle_conns: 10
  max_open_conns: 50
  conn_max_lifetime: 3600s

# Redis配置
redis:
  host: 127.0.0.1
  port: 6379
  password: ""
  db: 0
  pool_size: 20

# 投诉监控配置
complaint:
  check_interval: 2s           # 检查间隔
  detail_interval: 1s          # 详情查询间隔
  page_size: 1                 # 每次只取1条
  worker_reload_interval: 60s  # 主体重新加载间隔
  lock_ttl: 60s                # Redis锁过期时间

# 日志配置
logging:
  level: info                  # 日志级别
  file: ./logs/app.log        # 日志文件
  max_size: 100                # 单文件最大MB
  max_backups: 7               # 最多保留备份数
  max_age: 7                   # 最多保留天数
  compress: true               # 是否压缩

# 消息队列配置
message_queue:
  table: telegram_message_queue
  priority: 2                  # 默认优先级
```

---

## 14. 实施步骤

### 14.1 准备阶段（1天）

**1. 环境准备**
- 安装 Golang 1.21+
- 配置 GOPATH 和 GOPROXY
- 安装 MySQL、Redis

**2. 数据库准备**
- 执行建表SQL
- 创建索引

**3. 项目初始化**
```bash
mkdir complaint-monitor
cd complaint-monitor
go mod init complaint-monitor
```

### 14.2 开发阶段（3-4天）

**Day 1: 基础框架**
- 配置加载（Viper）
- 数据库连接（GORM）
- Redis连接
- 日志初始化（Zap）
- **证书管理器实现**

**Day 2: 核心业务**
- 支付宝API封装
- 投诉数据模型
- 投诉业务逻辑
- **证书临时文件管理**

**Day 3: 并发模型**
- SubjectLoader 实现
- SubjectWorker 实现
- 协程池管理
- **证书缓存机制**

**Day 4: 完善功能**
- 消息队列集成
- **黑名单集成逻辑**
- **风险评估算法**
- 异常处理
- 监控指标
- **临时文件清理策略**

### 14.3 测试阶段（2天）

**1. 单元测试**
- 测试各模块功能
- Mock API响应

**2. 集成测试**
- 测试完整流程
- 压力测试

**3. 内存测试**
- 长时间运行
- 监控内存变化

### 14.4 上线阶段（1天）

**1. 编译部署**
```bash
go build -o complaint-monitor main.go
chmod +x complaint-monitor
./complaint-monitor
```

**2. Systemd服务**
```ini
[Unit]
Description=Alipay Complaint Monitor
After=network.target

[Service]
Type=simple
User=www
WorkingDirectory=/path/to/complaint-monitor
ExecStart=/path/to/complaint-monitor
Restart=always

[Install]
WantedBy=multi-user.target
```

**总计：6-7天**

---

## 15. 监控告警（完善版 - 已修复）

> ⚠️ **重要**：完整的监控方案请参考《投诉监控方案-完善版.md》  
> 本节仅列出核心要点

### 15.1 监控架构

```
Golang服务（:9090/metrics）
    ↓
Prometheus（采集+规则）
    ↓
AlertManager（告警分组+去重）
    ↓
Telegram Bot（实时推送）
```

### 15.2 核心监控指标

#### 业务指标
| 指标 | 说明 | 告警阈值 | 优先级 |
|-----|------|---------|-------|
| **投诉处理速度** | 每分钟处理投诉数 | < 10/min | P1 |
| **未推送积压** | 未推送的投诉数量 | > 100 | P1 |
| **黑名单触发率** | 每小时触发次数 | > 50/h | P2 |
| **投诉处理成功率** | 成功处理的比例 | < 95% | P1 |

#### 系统指标
| 指标 | 说明 | 告警阈值 | 优先级 |
|-----|------|---------|-------|
| **协程数量** | 当前运行的协程数 | > 1000 | P0 🔴 |
| **内存占用** | 进程内存使用量 | > 1GB | P0 🔴 |
| **GC频率** | 每分钟GC次数 | > 60/min | P1 |
| **活跃Worker数** | 正在运行的Worker | < 预期值 | P1 |

#### API调用指标
| 指标 | 说明 | 告警阈值 | 优先级 |
|-----|------|---------|-------|
| **API失败率** | API调用失败率 | > 10% | P1 🟠 |
| **API响应时间** | P99响应时间 | > 5s | P2 |
| **API错误码分布** | 各错误码出现次数 | - | - |

#### 锁相关指标
| 指标 | 说明 | 告警阈值 | 优先级 |
|-----|------|---------|-------|
| **锁获取失败率** | 已被锁定的比例 | > 30% | P2 |
| **锁释放失败次数** | 释放失败总数 | > 0 | P1 🟠 |
| **锁持有时间** | P99持有时间 | > 60s | P2 |

#### 证书指标
| 指标 | 说明 | 告警阈值 | 优先级 |
|-----|------|---------|-------|
| **证书加载失败率** | 加载失败的比例 | > 5% | P2 🟡 |
| **证书缓存命中率** | 缓存命中比例 | < 80% | P2 |

#### Panic指标
| 指标 | 说明 | 告警阈值 | 优先级 |
|-----|------|---------|-------|
| **Panic总数** | 5分钟内panic次数 | > 0 | P2 🟡 |
| **Panic恢复率** | 恢复成功的比例 | < 100% | P0 🔴 |

### 15.3 指标采集实现

**暴露Prometheus指标：**

```go
import (
    "net/http"
    "github.com/prometheus/client_golang/prometheus/promhttp"
)

func main() {
    // ... 初始化

    // 暴露metrics endpoint
    http.Handle("/metrics", promhttp.Handler())
    go http.ListenAndServe(":9090", nil)
    
    // 启动系统指标采集器
    collector := monitor.NewSystemMetricsCollector(logger)
    go collector.Start()
    
    // ... 其他逻辑
}
```

**记录业务指标：**

```go
// 处理投诉时记录指标
func (w *SubjectWorker) processComplaint(complaint *Complaint) error {
    start := time.Now()
    
    // 处理逻辑
    err := w.doProcess(complaint)
    
    // 记录指标
    duration := time.Since(start)
    success := err == nil
    
    metrics.ComplaintProcessedTotal.WithLabelValues(
        fmt.Sprintf("%d", w.subjectID),
        map[bool]string{true: "success", false: "failed"}[success],
    ).Inc()
    
    metrics.ComplaintProcessDuration.WithLabelValues(
        fmt.Sprintf("%d", w.subjectID),
    ).Observe(duration.Seconds())
    
    return err
}
```

### 15.4 告警规则（核心）

**🔴 P0告警（立即处理）**

```yaml
# 服务不可用
- alert: ServiceDown
  expr: up{job="complaint-monitor"} == 0
  for: 1m

# 协程泄漏
- alert: GoroutineLeakage
  expr: goroutine_count > 1000
  for: 5m

# 内存泄漏
- alert: MemoryLeakage
  expr: memory_usage_bytes{type="alloc"} > 1073741824
  for: 5m

# Panic未恢复
- alert: PanicNotRecovered
  expr: rate(panic_total[5m]) - rate(panic_recovered_total[5m]) > 0
  for: 1m
```

**🟠 P1告警（30分钟内处理）**

```yaml
# API错误率高
- alert: APIErrorRateHigh
  expr: sum(rate(alipay_api_error_total[5m])) / sum(rate(alipay_api_call_total[5m])) > 0.1
  for: 5m

# 处理速度慢
- alert: ProcessingSlow
  expr: sum(rate(complaint_processed_total[5m])) < 10/60
  for: 10m

# 积压严重
- alert: BacklogHigh
  expr: unpushed_complaint_count > 100
  for: 10m

# 锁释放失败
- alert: LockReleaseFailure
  expr: rate(lock_release_failed_total[5m]) > 0
  for: 5m
```

**🟡 P2告警（4小时内处理）**

```yaml
# 证书加载失败
- alert: CertLoadFailure
  expr: sum(rate(cert_load_total{status="failed"}[5m])) / sum(rate(cert_load_total[5m])) > 0.05
  for: 5m

# Panic发生
- alert: PanicOccurred
  expr: increase(panic_total[5m]) > 0
  for: 1m

# 黑名单触发频繁
- alert: BlacklistFrequent
  expr: rate(blacklist_triggered_total[1h]) > 50/3600
  for: 10m
```

### 15.5 健康检查端点

```go
// HealthCheck 健康检查
type HealthCheck struct {
    Status          string    `json:"status"`
    Uptime          string    `json:"uptime"`
    Workers         int       `json:"workers"`
    MemoryMB        uint64    `json:"memory_mb"`
    Goroutines      int       `json:"goroutines"`
    ProcessedToday  int64     `json:"processed_today"`
    CertCached      int       `json:"cert_cached"`
    LastError       string    `json:"last_error,omitempty"`
    Timestamp       time.Time `json:"timestamp"`
}

// HandleHealth GET /health
func HandleHealth(w http.ResponseWriter, r *http.Request) {
    var m runtime.MemStats
    runtime.ReadMemStats(&m)
    
    health := &HealthCheck{
        Status:         "healthy",
        Uptime:         time.Since(startTime).String(),
        Workers:        getActiveWorkerCount(),
        MemoryMB:       m.Alloc / 1024 / 1024,
        Goroutines:     runtime.NumGoroutine(),
        ProcessedToday: getTodayProcessedCount(),
        CertCached:     certManager.GetCachedCount(),
        Timestamp:      time.Now(),
    }
    
    // 健康状态判断
    if health.Goroutines > 1000 || health.MemoryMB > 1024 {
        health.Status = "unhealthy"
        w.WriteHeader(http.StatusServiceUnavailable)
    }
    
    json.NewEncoder(w).Encode(health)
}
```

### 15.6 Grafana可视化

**核心面板：**

1. **服务概览**
   - 运行时间
   - 协程数量
   - 内存使用
   - 活跃Worker数

2. **业务指标**
   - 投诉处理速度（实时）
   - 累计处理数量
   - 处理成功率
   - 未推送积压趋势

3. **API监控**
   - API调用QPS
   - API响应时间分布
   - API错误率
   - 错误码分布

4. **性能监控**
   - GC频率和耗时
   - 内存分配趋势
   - CPU使用率
   - 锁竞争情况

5. **告警历史**
   - 告警触发次数
   - 告警响应时间
   - 告警恢复时间

### 15.7 告警通知（Telegram）

**告警消息格式：**

```
🔴 【P0告警】协程泄漏

告警详情：
当前协程数: 1245，可能存在协程泄漏

优先级：P0
时间：2025-10-29 15:30:25

🔗 查看详情: http://grafana.xxx.com/d/xxx
```

**恢复通知：**

```
✅ 【已恢复】协程泄漏

告警已恢复，当前协程数: 856

持续时间：15分钟
恢复时间：2025-10-29 15:45:32
```

### 15.8 监控数据保留策略

| 数据类型 | 保留时间 | 存储位置 |
|---------|---------|---------|
| 原始指标 | 15天 | Prometheus |
| 聚合数据 | 90天 | Prometheus |
| 告警历史 | 180天 | AlertManager |
| Grafana面板 | 永久 | Grafana DB |

### 15.9 监控接入步骤

1. **安装Prometheus**
   ```bash
   docker run -d -p 9090:9090 \
     -v /path/to/prometheus.yml:/etc/prometheus/prometheus.yml \
     prom/prometheus
   ```

2. **安装AlertManager**
   ```bash
   docker run -d -p 9093:9093 \
     -v /path/to/alertmanager.yml:/etc/alertmanager/alertmanager.yml \
     prom/alertmanager
   ```

3. **安装Grafana**
   ```bash
   docker run -d -p 3000:3000 grafana/grafana
   ```

4. **配置Telegram Bot**
   - 创建Bot：@BotFather
   - 获取Token和ChatID
   - 配置webhook接收告警

5. **导入Grafana Dashboard**
   - 登录Grafana
   - Import → 上传dashboard.json
   - 配置数据源

---

**详细配置和实现代码请参考：《投诉监控方案-完善版.md》**

---

## 16. 🎯 高危问题修复总结

### 16.1 已修复的P0问题

#### 🔴 问题1：证书临时文件泄露风险

**问题描述：**
- 原方案使用临时文件存储证书，存在泄露风险
- 证书残留在磁盘，可能被恶意读取
- 临时文件管理复杂，清理不及时会占用磁盘空间

**修复方案：**
- ✅ 采用**内存加载**方案，证书内容永不落盘
- ✅ 使用支付宝SDK的`LoadXxxFromData()`方法
- ✅ 数据库加密存储，AES-256-CBC加密
- ✅ 使用后显式清除内存引用，帮助GC
- ✅ 证书版本号管理，支持热更新

**影响：**
- 🔒 安全性提升：零文件泄露风险
- ⚡ 性能提升：无磁盘IO开销
- 🎯 可靠性提升：无临时文件管理问题

**相关章节：** 第6章 - 证书管理方案

---

#### 🔴 问题2：协程Panic导致服务崩溃

**问题描述：**
- 单个协程panic会导致整个程序崩溃
- 支付宝API返回异常数据可能触发panic
- 数据库连接断开可能导致panic
- 无法自动恢复，需要人工重启

**修复方案：**
- ✅ 在每个协程的`Run()`方法中添加`defer recover()`
- ✅ 在每次迭代中添加独立的panic保护
- ✅ 记录完整的panic堆栈信息
- ✅ 自动恢复并重启协程（可配置）
- ✅ 发送Telegram告警通知

**影响：**
- 🛡️ 可靠性：单个协程失败不影响其他协程
- 📊 可观测性：完整的panic堆栈记录
- 🔄 自愈能力：自动重启失败协程

**相关章节：** 第5.3节 - Panic恢复机制

---

#### 🔴 问题3：分布式锁安全问题

**问题描述：**
- 锁的value使用固定值，可能被其他协程误释放
- 锁TTL固定，处理时间长的任务会超时
- 释放锁时未验证所有权
- 未使用Lua脚本，存在竞态条件

**修复方案：**
- ✅ 锁的value使用UUID，确保唯一性
- ✅ 根据订单数量**动态计算TTL**
- ✅ 使用Lua脚本保证释放锁的原子性
- ✅ 支持锁续期（手动和自动）
- ✅ 记录锁操作指标，监控锁竞争

**影响：**
- 🔒 安全性：防止误释放锁
- ⏱️ 灵活性：动态TTL适应不同场景
- 📈 可观测性：完整的锁操作日志

**相关章节：** 第8.2节 - Redis分布式锁安全实现

---

### 16.2 已完善的监控方案

#### 📊 完善内容

**1. Prometheus指标体系**
- ✅ 业务指标：投诉处理、黑名单触发、订单分布
- ✅ 系统指标：协程、内存、GC
- ✅ API指标：调用次数、耗时、错误分布
- ✅ 锁指标：获取、释放、续期、持有时间
- ✅ 证书指标：加载、缓存命中率
- ✅ Panic指标：总数、恢复率

**2. 告警规则**
- 🔴 P0告警：服务不可用、协程泄漏、内存泄漏、Panic未恢复
- 🟠 P1告警：API错误率高、处理速度慢、积压严重
- 🟡 P2告警：证书加载失败、Panic发生、黑名单频繁

**3. 可视化**
- ✅ Grafana仪表盘配置
- ✅ 服务概览、业务指标、API监控、性能监控面板
- ✅ 实时趋势图、告警历史

**4. 告警通知**
- ✅ AlertManager告警聚合、分组、去重
- ✅ Telegram Bot推送
- ✅ 支持富文本格式
- ✅ 告警优先级路由

**影响：**
- 👀 可观测性：全方位监控
- ⚠️ 快速响应：分级告警
- 📈 趋势分析：历史数据可视化

**相关文档：** 
- 第15章 - 监控告警（完善版）
- 投诉监控方案-完善版.md（独立文档）

---

### 16.3 风险控制

#### ✅ 已缓解的风险

| 风险 | 原严重程度 | 修复后 | 缓解措施 |
|-----|----------|-------|---------|
| 证书泄露 | 🔴 高 | 🟢 低 | 内存加载，加密存储 |
| 服务崩溃 | 🔴 高 | 🟢 低 | Panic恢复，自动重启 |
| 锁误释放 | 🔴 高 | 🟢 低 | UUID+Lua脚本 |
| 协程泄漏 | 🟠 中 | 🟢 低 | Context控制，监控告警 |
| 内存泄漏 | 🟠 中 | 🟢 低 | 显式清理，GC优化 |
| 告警缺失 | 🟠 中 | 🟢 低 | 完善监控体系 |

#### ⚠️ 残留风险（需人工监控）

| 风险 | 严重程度 | 缓解措施 | 监控指标 |
|-----|---------|---------|---------|
| 支付宝API限流 | 🟡 低 | 限制page_size=1 | API错误码 |
| 数据库连接耗尽 | 🟡 低 | 连接池限制 | 连接数监控 |
| Redis不可用 | 🟡 低 | 重试机制 | Redis健康检查 |
| 网络抖动 | 🟡 低 | 超时+重试 | API响应时间 |

---

### 16.4 后续建议

#### 📋 短期（1-2周）
- [ ] 压力测试：模拟100+主体并发
- [ ] 故障演练：模拟Redis/MySQL宕机
- [ ] 日志审计：检查敏感信息泄露
- [ ] 文档完善：运维手册、故障处理指南

#### 📋 中期（1-2月）
- [ ] 引入分布式追踪（Jaeger/Zipkin）
- [ ] 实现热配置更新（无需重启）
- [ ] 优化黑名单规则（机器学习）
- [ ] 数据归档策略（历史数据压缩）

#### 📋 长期（3-6月）
- [ ] 多地域部署（容灾）
- [ ] 服务网格化（Istio）
- [ ] 智能告警（降低噪音）
- [ ] 成本优化（云资源按需伸缩）

---

**✅ 核心修复已完成，系统可进入生产环境**

---

## 附录

### A. 性能对比

| 场景 | PHP/Webman | Golang |
|-----|-----------|--------|
| 10个主体 | 10个进程 × 50MB = 500MB | 10个协程 × 2KB = 20KB |
| 50个主体 | 50个进程 × 50MB = 2.5GB | 50个协程 × 2KB = 100KB |
| 100个主体 | **内存压力大** | **轻松支持** |

### B. 版本历史

| 版本 | 日期 | 说明 |
|-----|------|------|
| v1.0 | 2025-10-28 | 初版，Golang方案设计 |
| v1.1 | 2025-10-29 | 修复P0问题，完善监控方案 |

---

## 17. 🚀 实施步骤与时间规划

### 17.1 总体时间规划

```
📅 总工期：6-7个工作日

第1天：环境准备 + 项目初始化
第2天：核心模块开发（数据库、API调用）
第3天：业务逻辑开发（投诉处理、黑名单）
第4天：监控系统集成 + 测试
第5天：压力测试 + 优化
第6天：部署上线 + 文档整理
第7天：观察期 + 问题修复
```

---

### 17.2 第一阶段：环境准备（第1天上午）

#### ✅ 任务清单

**1. 开发环境搭建**
```bash
# 安装Golang (1.21+)
wget https://golang.org/dl/go1.21.5.linux-amd64.tar.gz
sudo tar -C /usr/local -xzf go1.21.5.linux-amd64.tar.gz
export PATH=$PATH:/usr/local/go/bin
go version

# 配置GOPROXY
go env -w GOPROXY=https://goproxy.cn,direct
go env -w GO111MODULE=on
```

**2. 创建项目结构**
```bash
mkdir -p complaint-monitor
cd complaint-monitor

# 初始化Go模块
go mod init complaint-monitor

# 创建目录结构
mkdir -p {cmd,internal/{worker,lock,cert,model,repository,service,config,logger}}
mkdir -p {pkg/metrics,pkg/monitor}
mkdir -p {configs,scripts,docs}
```

**3. 安装核心依赖**
```bash
# 支付宝SDK
go get github.com/smartwalle/alipay/v3

# 数据库
go get gorm.io/gorm
go get gorm.io/driver/mysql

# Redis
go get github.com/go-redis/redis/v8

# 日志
go get go.uber.org/zap

# 监控
go get github.com/prometheus/client_golang/prometheus
go get github.com/prometheus/client_golang/prometheus/promauto
go get github.com/prometheus/client_golang/prometheus/promhttp

# 配置管理
go get github.com/spf13/viper

# HTTP客户端
go get github.com/go-resty/resty/v2
```

**4. 数据库准备**
```sql
-- 创建投诉表
CREATE TABLE `alipay_complaint` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `subject_id` INT NOT NULL,
    `complaint_no` VARCHAR(64) NOT NULL,
    `complaint_type` VARCHAR(50),
    `complaint_status` VARCHAR(20),
    `complainant_id` VARCHAR(64),
    `complaint_time` DATETIME,
    `complaint_reason` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_subject_complaint` (`subject_id`, `complaint_no`),
    KEY `idx_complaint_time` (`complaint_time`),
    KEY `idx_status` (`complaint_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建投诉详情表
CREATE TABLE `alipay_complaint_detail` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `complaint_id` BIGINT UNSIGNED NOT NULL,
    `subject_id` INT NOT NULL,
    `complaint_no` VARCHAR(64) NOT NULL,
    `merchant_order_no` VARCHAR(64) NOT NULL,
    `platform_order_no` VARCHAR(64),
    `order_amount` DECIMAL(10,2),
    `complaint_amount` DECIMAL(10,2),
    `is_pushed` TINYINT DEFAULT 0,
    `pushed_at` DATETIME,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_complaint_order` (`subject_id`, `complaint_no`, `merchant_order_no`),
    KEY `idx_is_pushed` (`is_pushed`),
    KEY `idx_complaint_id` (`complaint_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 证书版本号字段（如果不存在）
ALTER TABLE `subject` 
ADD COLUMN `cert_version` INT DEFAULT 1 COMMENT '证书版本号' 
AFTER `alipay_cert_path`;
```

**预计时间**: 2-3小时

---

### 17.3 第二阶段：项目初始化（第1天下午）

#### ✅ 任务清单

**1. 配置文件 (configs/config.yaml)**
```yaml
app:
  name: "complaint-monitor"
  environment: "production"
  log_level: "info"

database:
  host: "127.0.0.1"
  port: 3306
  username: "root"
  password: "password"
  database: "payment_db"
  max_open_conns: 100
  max_idle_conns: 10
  conn_max_lifetime: 3600

redis:
  host: "127.0.0.1"
  port: 6379
  password: ""
  db: 0
  pool_size: 10

worker:
  refresh_interval: 60  # 刷新主体列表间隔（秒）
  fetch_interval: 2     # 获取投诉间隔（秒）
  restartable: true     # 是否自动重启

cert:
  cache_ttl: 3600       # 证书缓存时间（秒）
  encryption_key: "your-32-byte-encryption-key-here"

lock:
  base_ttl: 60          # 基础锁TTL（秒）
  max_ttl: 300          # 最大锁TTL（秒）

metrics:
  port: 9090
  path: "/metrics"

health:
  port: 8080
  path: "/health"
```

**2. 主程序入口 (cmd/main.go)**
```go
package main

import (
    "context"
    "flag"
    "fmt"
    "os"
    "os/signal"
    "syscall"
    
    "complaint-monitor/internal/config"
    "complaint-monitor/internal/logger"
    "complaint-monitor/internal/worker"
    "complaint-monitor/pkg/metrics"
    "complaint-monitor/pkg/monitor"
    
    "go.uber.org/zap"
)

var (
    configPath = flag.String("config", "configs/config.yaml", "配置文件路径")
)

func main() {
    flag.Parse()
    
    // 加载配置
    cfg, err := config.Load(*configPath)
    if err != nil {
        fmt.Printf("加载配置失败: %v\n", err)
        os.Exit(1)
    }
    
    // 初始化日志
    log, err := logger.NewLogger(cfg.App.LogLevel)
    if err != nil {
        fmt.Printf("初始化日志失败: %v\n", err)
        os.Exit(1)
    }
    defer log.Sync()
    
    log.Info("投诉监控服务启动", zap.String("version", "v1.1"))
    
    // 初始化指标服务
    metricsServer := metrics.NewServer(cfg.Metrics.Port, log)
    go metricsServer.Start()
    
    // 初始化健康检查
    healthServer := monitor.NewHealthServer(cfg.Health.Port, log)
    go healthServer.Start()
    
    // 初始化Worker管理器
    ctx, cancel := context.WithCancel(context.Background())
    defer cancel()
    
    manager := worker.NewManager(cfg, log)
    go manager.Start(ctx)
    
    // 等待退出信号
    sigChan := make(chan os.Signal, 1)
    signal.Notify(sigChan, syscall.SIGINT, syscall.SIGTERM)
    
    <-sigChan
    log.Info("收到退出信号，开始优雅关闭...")
    
    cancel()
    manager.Stop()
    
    log.Info("服务已停止")
}
```

**3. 日志初始化 (internal/logger/logger.go)**
```go
package logger

import (
    "go.uber.org/zap"
    "go.uber.org/zap/zapcore"
)

func NewLogger(level string) (*zap.Logger, error) {
    cfg := zap.NewProductionConfig()
    
    // 设置日志级别
    switch level {
    case "debug":
        cfg.Level = zap.NewAtomicLevelAt(zapcore.DebugLevel)
    case "info":
        cfg.Level = zap.NewAtomicLevelAt(zapcore.InfoLevel)
    case "warn":
        cfg.Level = zap.NewAtomicLevelAt(zapcore.WarnLevel)
    case "error":
        cfg.Level = zap.NewAtomicLevelAt(zapcore.ErrorLevel)
    default:
        cfg.Level = zap.NewAtomicLevelAt(zapcore.InfoLevel)
    }
    
    // 配置编码器
    cfg.EncoderConfig.TimeKey = "timestamp"
    cfg.EncoderConfig.EncodeTime = zapcore.ISO8601TimeEncoder
    
    return cfg.Build()
}
```

**预计时间**: 2-3小时

---

### 17.4 第三阶段：核心模块开发（第2天）

#### ✅ 任务清单

**1. 数据库模型层** (4个文件)
- `internal/model/subject.go` - 主体模型
- `internal/model/complaint.go` - 投诉模型
- `internal/model/complaint_detail.go` - 投诉详情模型
- `internal/model/blacklist.go` - 黑名单模型

**2. 数据库访问层** (3个文件)
- `internal/repository/subject_repo.go` - 主体查询
- `internal/repository/complaint_repo.go` - 投诉CRUD
- `internal/repository/blacklist_repo.go` - 黑名单操作

**3. 证书管理器** (1个文件)
- `internal/cert/manager.go` - 证书加载、缓存、解密

**4. 分布式锁** (1个文件)
- `internal/lock/distributed_lock.go` - Redis分布式锁

**5. 支付宝API服务** (1个文件)
- `internal/service/alipay_service.go` - API调用封装

**开发顺序**:
```
上午：模型层 → 数据库访问层
下午：证书管理器 → 分布式锁 → API服务
```

**预计时间**: 6-8小时

---

### 17.5 第四阶段：业务逻辑开发（第3天）

#### ✅ 任务清单

**1. Worker核心逻辑** (2个文件)
- `internal/worker/subject_worker.go` - 主体Worker
  - Panic恢复
  - 投诉获取
  - 投诉处理
  - 黑名单判断

- `internal/worker/manager.go` - Worker管理器
  - 动态启停
  - 主体列表刷新

**2. 黑名单服务** (1个文件)
- `internal/service/blacklist_service.go`
  - 风险评估
  - 自动拉黑
  - 消息推送

**3. 消息推送** (1个文件)
- `internal/service/notification_service.go`
  - 写入消息队列
  - 模板数据构建

**开发顺序**:
```
上午：Worker核心逻辑（Panic恢复、投诉处理）
下午：黑名单服务 → 消息推送 → 集成测试
```

**预计时间**: 6-8小时

---

### 17.6 第五阶段：监控集成（第4天上午）

#### ✅ 任务清单

**1. Prometheus指标** (1个文件)
- `pkg/metrics/metrics.go` - 定义所有指标

**2. 指标采集器** (1个文件)
- `pkg/monitor/collector.go` - 系统指标采集

**3. 健康检查** (1个文件)
- `pkg/monitor/health.go` - 健康检查端点

**4. 部署监控栈**
```bash
# Prometheus
docker run -d --name prometheus -p 9090:9090 \
  -v $(pwd)/configs/prometheus.yml:/etc/prometheus/prometheus.yml \
  prom/prometheus

# AlertManager
docker run -d --name alertmanager -p 9093:9093 \
  -v $(pwd)/configs/alertmanager.yml:/etc/alertmanager/alertmanager.yml \
  prom/alertmanager

# Grafana
docker run -d --name grafana -p 3000:3000 \
  grafana/grafana
```

**预计时间**: 3-4小时

---

### 17.7 第六阶段：测试（第4天下午 + 第5天）

#### ✅ 测试清单

**1. 单元测试** (第4天下午)
```bash
# 测试覆盖率目标：> 80%
go test ./... -v -cover

# 关键模块测试
- 证书管理器
- 分布式锁
- 风险评估
- Panic恢复
```

**2. 集成测试** (第5天上午)
```bash
# 测试场景
- 正常投诉处理流程
- 多订单投诉拆分
- 黑名单自动触发
- 消息队列写入
- Redis锁竞争
```

**3. 压力测试** (第5天下午)
```bash
# 使用wrk或ab工具
- 模拟100个主体并发
- 每秒1000次API调用
- 持续运行1小时
- 监控内存、CPU、协程数
```

**4. 故障测试**
```bash
# 测试场景
- MySQL宕机恢复
- Redis宕机恢复
- 支付宝API超时
- 网络抖动
- Panic恢复
```

**预计时间**: 12小时

---

### 17.8 第七阶段：部署上线（第6天）

#### ✅ 部署清单

**1. 编译打包** (上午)
```bash
# 编译
CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -o complaint-monitor cmd/main.go

# 打包
tar -czf complaint-monitor-v1.1.tar.gz \
  complaint-monitor \
  configs/ \
  scripts/ \
  README.md
```

**2. 服务器部署** (上午)
```bash
# 上传到服务器
scp complaint-monitor-v1.1.tar.gz user@server:/opt/

# 解压
cd /opt && tar -xzf complaint-monitor-v1.1.tar.gz

# 配置systemd服务
cat > /etc/systemd/system/complaint-monitor.service <<EOF
[Unit]
Description=Complaint Monitor Service
After=network.target

[Service]
Type=simple
User=app
WorkingDirectory=/opt/complaint-monitor
ExecStart=/opt/complaint-monitor/complaint-monitor -config configs/config.yaml
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF

# 启动服务
systemctl daemon-reload
systemctl enable complaint-monitor
systemctl start complaint-monitor
```

**3. 验证部署** (下午)
```bash
# 检查服务状态
systemctl status complaint-monitor

# 检查日志
journalctl -u complaint-monitor -f

# 检查指标
curl http://localhost:9090/metrics

# 检查健康
curl http://localhost:8080/health

# 检查Grafana面板
# 访问 http://server:3000
```

**4. 配置告警** (下午)
```bash
# 配置Telegram Bot
# 在AlertManager中配置webhook

# 测试告警
# 人为触发P2告警，验证推送
```

**预计时间**: 6-8小时

---

### 17.9 第八阶段：观察期（第7天）

#### ✅ 观察清单

**1. 监控关键指标**
- 协程数量 < 100
- 内存使用 < 500MB
- 投诉处理速度 > 10/min
- API成功率 > 95%
- 无P0/P1告警

**2. 日志分析**
```bash
# 统计处理成功率
grep "投诉处理成功" logs/*.log | wc -l

# 检查错误日志
grep "ERROR" logs/*.log

# 检查Panic
grep "panic" logs/*.log
```

**3. 数据验证**
```sql
-- 检查投诉入库情况
SELECT COUNT(*) FROM alipay_complaint WHERE DATE(created_at) = CURDATE();

-- 检查黑名单触发
SELECT COUNT(*) FROM alipay_blacklist WHERE DATE(created_at) = CURDATE();

-- 检查消息推送
SELECT COUNT(*) FROM telegram_message_queue 
WHERE template_type = 'complaint' 
AND DATE(created_at) = CURDATE();
```

**4. 性能优化**
- 根据监控数据调整参数
- 优化慢查询
- 调整缓存策略

**预计时间**: 全天观察 + 随时处理问题

---

### 17.10 关键里程碑检查表

| 阶段 | 里程碑 | 验收标准 | 负责人 |
|-----|--------|---------|-------|
| Day 1 | 环境搭建完成 | ✅ Go环境正常<br>✅ 依赖安装完成<br>✅ 数据库表创建 | 开发 |
| Day 2 | 核心模块完成 | ✅ 模型层通过测试<br>✅ 证书管理器工作<br>✅ 锁机制验证通过 | 开发 |
| Day 3 | 业务逻辑完成 | ✅ Worker正常运行<br>✅ 投诉处理成功<br>✅ 黑名单触发正常 | 开发 |
| Day 4 | 监控集成完成 | ✅ Prometheus采集正常<br>✅ Grafana面板展示<br>✅ 告警规则生效 | 开发+运维 |
| Day 5 | 测试通过 | ✅ 单元测试覆盖率>80%<br>✅ 压力测试通过<br>✅ 故障恢复验证 | 测试 |
| Day 6 | 部署上线 | ✅ 服务稳定运行<br>✅ 监控数据正常<br>✅ 告警通道畅通 | 运维 |
| Day 7 | 稳定运行 | ✅ 无P0/P1告警<br>✅ 性能指标达标<br>✅ 数据准确性验证 | 全员 |

---

### 17.11 风险预案

| 风险 | 影响 | 应对措施 | 责任人 |
|-----|------|---------|-------|
| **支付宝API限流** | 🔴 高 | 降低请求频率，增加重试间隔 | 开发 |
| **数据库性能瓶颈** | 🟠 中 | 添加索引，优化查询，考虑分库分表 | DBA |
| **Redis宕机** | 🟠 中 | 增加哨兵模式，降级为本地锁 | 运维 |
| **内存泄漏** | 🟠 中 | 监控告警，定期重启，排查代码 | 开发 |
| **证书过期** | 🟡 低 | 提前30天告警，自动续期流程 | 运维 |
| **告警风暴** | 🟡 低 | 告警聚合，增加抑制规则 | 运维 |

---

### 17.12 上线前检查清单

#### ✅ 代码层面
- [ ] 所有TODO/FIXME已处理
- [ ] 敏感信息已移除（密码、密钥）
- [ ] 日志级别设置为INFO
- [ ] Panic恢复机制已测试
- [ ] 资源清理（defer）已添加

#### ✅ 配置层面
- [ ] 生产环境配置已确认
- [ ] 数据库连接池参数已优化
- [ ] Redis连接参数已优化
- [ ] 证书加密密钥已配置
- [ ] Telegram Bot Token已配置

#### ✅ 数据库层面
- [ ] 所有表已创建
- [ ] 索引已添加
- [ ] 权限已配置
- [ ] 备份策略已制定
- [ ] 慢查询日志已开启

#### ✅ 监控层面
- [ ] Prometheus已部署
- [ ] AlertManager已配置
- [ ] Grafana面板已导入
- [ ] 告警规则已测试
- [ ] Telegram推送已验证

#### ✅ 文档层面
- [ ] 部署文档已完成
- [ ] 运维手册已编写
- [ ] 故障处理指南已准备
- [ ] API文档已整理
- [ ] 数据字典已更新

---

### 17.13 上线后观察指标

#### 第一周观察（重点）

**每日检查**:
```bash
# 1. 服务状态
systemctl status complaint-monitor

# 2. 关键指标
curl -s http://localhost:9090/metrics | grep -E "(goroutine_count|memory_usage|complaint_processed)"

# 3. 错误日志
journalctl -u complaint-monitor --since "1 hour ago" | grep ERROR

# 4. 数据库监控
mysql -e "SELECT COUNT(*) as today_complaints FROM alipay_complaint WHERE DATE(created_at) = CURDATE();"
```

**告警阈值（第一周放宽）**:
| 指标 | 正常阈值 | 第一周阈值 |
|-----|---------|-----------|
| 协程数 | < 100 | < 200 |
| 内存使用 | < 500MB | < 800MB |
| API错误率 | < 5% | < 10% |
| 处理速度 | > 10/min | > 5/min |

#### 第二周优化

根据第一周数据进行优化：
- 调整Worker数量
- 优化缓存策略
- 调整告警阈值
- 优化数据库查询

---

**✅ 实施步骤制定完成，可按计划执行！**

---

**文档编写时间**: 2025-10-29  
**文档版本**: v1.1（Golang版 - 含实施步骤）  
**维护人员**: 开发团队


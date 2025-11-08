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

// DistributedLock 分布式锁管理器
type DistributedLock struct {
	redis    *redis.Client
	baseTTL  time.Duration
	maxTTL   time.Duration
	logger   *zap.Logger
}

// LockResult 锁结果
type LockResult struct {
	Key      string
	Value    string // UUID
	acquired bool
}

// NewDistributedLock 创建分布式锁管理器
func NewDistributedLock(redisClient *redis.Client, baseTTL, maxTTL time.Duration, logger *zap.Logger) *DistributedLock {
	return &DistributedLock{
		redis:   redisClient,
		baseTTL: baseTTL,
		maxTTL:  maxTTL,
		logger:  logger,
	}
}

// AcquireLock 获取锁（安全版本）
func (dl *DistributedLock) AcquireLock(ctx context.Context, key string, orderCount int) (*LockResult, error) {
	// 生成唯一UUID作为锁的值
	lockValue := generateUUID()

	// 根据订单数量动态调整TTL
	ttl := dl.calculateLockTTL(orderCount)

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

	// 使用Lua脚本保证原子性
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

	// 使用Lua脚本续期（只有持有锁的协程才能续期）
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

// calculateLockTTL 根据订单数量动态计算TTL
func (dl *DistributedLock) calculateLockTTL(orderCount int) time.Duration {
	// 基础TTL
	ttl := dl.baseTTL

	// 每个订单额外增加500ms
	additionalTTL := time.Duration(orderCount) * 500 * time.Millisecond

	ttl += additionalTTL

	// 不超过最大TTL
	if ttl > dl.maxTTL {
		ttl = dl.maxTTL
	}

	return ttl
}

// generateUUID 生成UUID
func generateUUID() string {
	b := make([]byte, 16)
	rand.Read(b)
	return hex.EncodeToString(b)
}

// IsAcquired 检查锁是否已获取
func (lr *LockResult) IsAcquired() bool {
	return lr != nil && lr.acquired
}


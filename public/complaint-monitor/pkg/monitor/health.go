package monitor

import (
	"context"
	"encoding/json"
	"net/http"
	"time"

	"github.com/go-redis/redis/v8"
	"go.uber.org/zap"
	"gorm.io/gorm"
)

// HealthChecker 健康检查器
type HealthChecker struct {
	db     *gorm.DB
	redis  *redis.Client
	logger *zap.Logger
}

// NewHealthChecker 创建健康检查器
func NewHealthChecker(db *gorm.DB, redis *redis.Client, logger *zap.Logger) *HealthChecker {
	return &HealthChecker{
		db:     db,
		redis:  redis,
		logger: logger,
	}
}

// HealthStatus 健康状态
type HealthStatus struct {
	Status     string                     `json:"status"` // overall, database, redis
	Timestamp  string                     `json:"timestamp"`
	Uptime     float64                    `json:"uptime_seconds"`
	Components map[string]ComponentStatus `json:"components"`
}

// ComponentStatus 组件状态
type ComponentStatus struct {
	Status  string  `json:"status"` // healthy, unhealthy, degraded
	Message string  `json:"message,omitempty"`
	Latency float64 `json:"latency_ms,omitempty"`
}

// Check 执行健康检查
func (h *HealthChecker) Check(ctx context.Context, startTime time.Time) *HealthStatus {
	status := &HealthStatus{
		Status:     "healthy",
		Timestamp:  time.Now().Format(time.RFC3339),
		Uptime:     time.Since(startTime).Seconds(),
		Components: make(map[string]ComponentStatus),
	}

	// 检查数据库
	dbStatus := h.checkDatabase(ctx)
	status.Components["database"] = dbStatus
	if dbStatus.Status != "healthy" {
		status.Status = "degraded"
	}

	// 检查Redis
	redisStatus := h.checkRedis(ctx)
	status.Components["redis"] = redisStatus
	if redisStatus.Status != "healthy" {
		status.Status = "degraded"
	}

	// 如果两个都不健康，整体状态为不健康
	if dbStatus.Status == "unhealthy" && redisStatus.Status == "unhealthy" {
		status.Status = "unhealthy"
	}

	return status
}

// checkDatabase 检查数据库连接
func (h *HealthChecker) checkDatabase(ctx context.Context) ComponentStatus {
	start := time.Now()

	sqlDB, err := h.db.DB()
	if err != nil {
		h.logger.Error("获取数据库连接失败", zap.Error(err))
		return ComponentStatus{
			Status:  "unhealthy",
			Message: "无法获取数据库连接",
		}
	}

	// 设置超时
	ctx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()

	// Ping数据库
	if err := sqlDB.PingContext(ctx); err != nil {
		h.logger.Error("数据库Ping失败", zap.Error(err))
		return ComponentStatus{
			Status:  "unhealthy",
			Message: err.Error(),
		}
	}

	latency := time.Since(start).Milliseconds()

	// 检查连接池状态
	stats := sqlDB.Stats()
	if stats.OpenConnections == stats.MaxOpenConnections {
		h.logger.Warn("数据库连接池已满")
		return ComponentStatus{
			Status:  "degraded",
			Message: "连接池已满",
			Latency: float64(latency),
		}
	}

	return ComponentStatus{
		Status:  "healthy",
		Latency: float64(latency),
	}
}

// checkRedis 检查Redis连接
func (h *HealthChecker) checkRedis(ctx context.Context) ComponentStatus {
	start := time.Now()

	// 设置超时
	ctx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()

	// Ping Redis
	if err := h.redis.Ping(ctx).Err(); err != nil {
		h.logger.Error("Redis Ping失败", zap.Error(err))
		return ComponentStatus{
			Status:  "unhealthy",
			Message: err.Error(),
		}
	}

	latency := time.Since(start).Milliseconds()

	return ComponentStatus{
		Status:  "healthy",
		Latency: float64(latency),
	}
}

// HandleHealth HTTP处理函数
func (h *HealthChecker) HandleHealth(startTime time.Time) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		ctx := r.Context()
		status := h.Check(ctx, startTime)

		w.Header().Set("Content-Type", "application/json")

		// 根据健康状态设置HTTP状态码
		switch status.Status {
		case "healthy":
			w.WriteHeader(http.StatusOK)
		case "degraded":
			w.WriteHeader(http.StatusOK) // 降级但仍可用
		case "unhealthy":
			w.WriteHeader(http.StatusServiceUnavailable)
		default:
			w.WriteHeader(http.StatusInternalServerError)
		}

		if err := json.NewEncoder(w).Encode(status); err != nil {
			h.logger.Error("编码健康检查响应失败", zap.Error(err))
		}
	}
}

// HandleLiveness 存活检查（简单返回200）
func (h *HealthChecker) HandleLiveness() http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusOK)
		w.Write([]byte(`{"status":"alive"}`))
	}
}

// HandleReadiness 就绪检查（检查所有依赖）
func (h *HealthChecker) HandleReadiness(startTime time.Time) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		ctx := r.Context()
		status := h.Check(ctx, startTime)

		w.Header().Set("Content-Type", "application/json")

		// 就绪检查要求所有组件都健康
		if status.Status == "healthy" {
			w.WriteHeader(http.StatusOK)
		} else {
			w.WriteHeader(http.StatusServiceUnavailable)
		}

		if err := json.NewEncoder(w).Encode(status); err != nil {
			h.logger.Error("编码就绪检查响应失败", zap.Error(err))
		}
	}
}

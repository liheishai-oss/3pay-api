package metrics

import (
	"github.com/prometheus/client_golang/prometheus"
	"github.com/prometheus/client_golang/prometheus/promauto"
)

// Prometheus指标定义

var (
	// Worker指标
	WorkerTotal = promauto.NewGauge(prometheus.GaugeOpts{
		Name: "complaint_monitor_worker_total",
		Help: "当前运行的Worker总数",
	})

	WorkerPanicTotal = promauto.NewCounterVec(prometheus.CounterOpts{
		Name: "complaint_monitor_worker_panic_total",
		Help: "Worker发生Panic的总次数",
	}, []string{"subject_id", "panic_type"})

	WorkerRestartTotal = promauto.NewCounterVec(prometheus.CounterOpts{
		Name: "complaint_monitor_worker_restart_total",
		Help: "Worker自动重启的总次数",
	}, []string{"subject_id"})

	// 投诉处理指标
	ComplaintFetchTotal = promauto.NewCounterVec(prometheus.CounterOpts{
		Name: "complaint_monitor_complaint_fetch_total",
		Help: "获取投诉的总次数",
	}, []string{"subject_id", "status"})

	ComplaintProcessTotal = promauto.NewCounterVec(prometheus.CounterOpts{
		Name: "complaint_monitor_complaint_process_total",
		Help: "处理投诉的总次数",
	}, []string{"subject_id", "status"})

	ComplaintProcessDuration = promauto.NewHistogramVec(prometheus.HistogramOpts{
		Name:    "complaint_monitor_complaint_process_duration_seconds",
		Help:    "处理投诉的耗时（秒）",
		Buckets: []float64{0.1, 0.5, 1, 2, 5, 10, 30, 60},
	}, []string{"subject_id"})

	// 黑名单指标
	BlacklistAddTotal = promauto.NewCounterVec(prometheus.CounterOpts{
		Name: "complaint_monitor_blacklist_add_total",
		Help: "添加黑名单的总次数",
	}, []string{"subject_id", "risk_level"})

	BlacklistTotal = promauto.NewGaugeVec(prometheus.GaugeOpts{
		Name: "complaint_monitor_blacklist_total",
		Help: "当前黑名单总数",
	}, []string{"subject_id", "risk_level"})

	// 通知推送指标
	NotificationPushTotal = promauto.NewCounterVec(prometheus.CounterOpts{
		Name: "complaint_monitor_notification_push_total",
		Help: "推送通知的总次数",
	}, []string{"template_type", "status"})

	NotificationQueueSize = promauto.NewGauge(prometheus.GaugeOpts{
		Name: "complaint_monitor_notification_queue_size",
		Help: "通知队列中待推送的消息数量",
	})

	// 证书管理指标
	CertCacheTotal = promauto.NewGauge(prometheus.GaugeOpts{
		Name: "complaint_monitor_cert_cache_total",
		Help: "证书缓存数量",
	})

	CertLoadTotal = promauto.NewCounterVec(prometheus.CounterOpts{
		Name: "complaint_monitor_cert_load_total",
		Help: "证书加载次数",
	}, []string{"subject_id", "status"})

	CertCacheHitTotal = promauto.NewCounter(prometheus.CounterOpts{
		Name: "complaint_monitor_cert_cache_hit_total",
		Help: "证书缓存命中次数",
	})

	CertCacheMissTotal = promauto.NewCounter(prometheus.CounterOpts{
		Name: "complaint_monitor_cert_cache_miss_total",
		Help: "证书缓存未命中次数",
	})

	// 分布式锁指标
	LockAcquireTotal = promauto.NewCounterVec(prometheus.CounterOpts{
		Name: "complaint_monitor_lock_acquire_total",
		Help: "获取锁的总次数",
	}, []string{"key", "status"})

	LockHoldDuration = promauto.NewHistogramVec(prometheus.HistogramOpts{
		Name:    "complaint_monitor_lock_hold_duration_seconds",
		Help:    "持有锁的时长（秒）",
		Buckets: []float64{0.01, 0.05, 0.1, 0.5, 1, 5, 10, 30},
	}, []string{"key"})

	// 数据库指标
	DBQueryTotal = promauto.NewCounterVec(prometheus.CounterOpts{
		Name: "complaint_monitor_db_query_total",
		Help: "数据库查询总次数",
	}, []string{"operation", "table", "status"})

	DBQueryDuration = promauto.NewHistogramVec(prometheus.HistogramOpts{
		Name:    "complaint_monitor_db_query_duration_seconds",
		Help:    "数据库查询耗时（秒）",
		Buckets: []float64{0.001, 0.005, 0.01, 0.05, 0.1, 0.5, 1, 5},
	}, []string{"operation", "table"})

	DBConnectionTotal = promauto.NewGaugeVec(prometheus.GaugeOpts{
		Name: "complaint_monitor_db_connection_total",
		Help: "数据库连接数",
	}, []string{"state"})

	// Redis指标
	RedisCommandTotal = promauto.NewCounterVec(prometheus.CounterOpts{
		Name: "complaint_monitor_redis_command_total",
		Help: "Redis命令执行总次数",
	}, []string{"command", "status"})

	RedisCommandDuration = promauto.NewHistogramVec(prometheus.HistogramOpts{
		Name:    "complaint_monitor_redis_command_duration_seconds",
		Help:    "Redis命令执行耗时（秒）",
		Buckets: []float64{0.0001, 0.0005, 0.001, 0.005, 0.01, 0.05, 0.1, 0.5},
	}, []string{"command"})

	// 系统指标
	SystemUptime = promauto.NewGauge(prometheus.GaugeOpts{
		Name: "complaint_monitor_system_uptime_seconds",
		Help: "系统运行时长（秒）",
	})

	SystemGoroutines = promauto.NewGauge(prometheus.GaugeOpts{
		Name: "complaint_monitor_system_goroutines",
		Help: "当前Goroutine数量",
	})

	SystemMemoryUsage = promauto.NewGauge(prometheus.GaugeOpts{
		Name: "complaint_monitor_system_memory_usage_bytes",
		Help: "内存使用量（字节）",
	})

	SystemCPUUsage = promauto.NewGauge(prometheus.GaugeOpts{
		Name: "complaint_monitor_system_cpu_usage_percent",
		Help: "CPU使用率（百分比）",
	})
)

// RecordWorkerPanic 记录Worker Panic
func RecordWorkerPanic(subjectID int, panicType string) {
	WorkerPanicTotal.WithLabelValues(string(rune(subjectID)), panicType).Inc()
}

// RecordWorkerRestart 记录Worker重启
func RecordWorkerRestart(subjectID int) {
	WorkerRestartTotal.WithLabelValues(string(rune(subjectID))).Inc()
}

// RecordComplaintFetch 记录投诉获取
func RecordComplaintFetch(subjectID int, status string) {
	ComplaintFetchTotal.WithLabelValues(string(rune(subjectID)), status).Inc()
}

// RecordComplaintProcess 记录投诉处理
func RecordComplaintProcess(subjectID int, status string, duration float64) {
	ComplaintProcessTotal.WithLabelValues(string(rune(subjectID)), status).Inc()
	ComplaintProcessDuration.WithLabelValues(string(rune(subjectID))).Observe(duration)
}

// RecordBlacklistAdd 记录添加黑名单
func RecordBlacklistAdd(subjectID int, riskLevel string) {
	BlacklistAddTotal.WithLabelValues(string(rune(subjectID)), riskLevel).Inc()
}

// RecordNotificationPush 记录通知推送
func RecordNotificationPush(templateType, status string) {
	NotificationPushTotal.WithLabelValues(templateType, status).Inc()
}

// RecordCertLoad 记录证书加载
func RecordCertLoad(subjectID int, status string) {
	CertLoadTotal.WithLabelValues(string(rune(subjectID)), status).Inc()
}

// RecordCertCacheHit 记录证书缓存命中
func RecordCertCacheHit() {
	CertCacheHitTotal.Inc()
}

// RecordCertCacheMiss 记录证书缓存未命中
func RecordCertCacheMiss() {
	CertCacheMissTotal.Inc()
}

// RecordLockAcquire 记录获取锁
func RecordLockAcquire(key, status string) {
	LockAcquireTotal.WithLabelValues(key, status).Inc()
}

// RecordLockHoldDuration 记录持有锁时长
func RecordLockHoldDuration(key string, duration float64) {
	LockHoldDuration.WithLabelValues(key).Observe(duration)
}

// RecordDBQuery 记录数据库查询
func RecordDBQuery(operation, table, status string, duration float64) {
	DBQueryTotal.WithLabelValues(operation, table, status).Inc()
	DBQueryDuration.WithLabelValues(operation, table).Observe(duration)
}

// RecordRedisCommand 记录Redis命令
func RecordRedisCommand(command, status string, duration float64) {
	RedisCommandTotal.WithLabelValues(command, status).Inc()
	RedisCommandDuration.WithLabelValues(command).Observe(duration)
}

// UpdateSystemMetrics 更新系统指标
func UpdateSystemMetrics(uptime float64, goroutines int, memoryUsage uint64, cpuUsage float64) {
	SystemUptime.Set(uptime)
	SystemGoroutines.Set(float64(goroutines))
	SystemMemoryUsage.Set(float64(memoryUsage))
	SystemCPUUsage.Set(cpuUsage)
}

// UpdateWorkerTotal 更新Worker总数
func UpdateWorkerTotal(total int) {
	WorkerTotal.Set(float64(total))
}

// UpdateCertCacheTotal 更新证书缓存总数
func UpdateCertCacheTotal(total int) {
	CertCacheTotal.Set(float64(total))
}

// UpdateNotificationQueueSize 更新通知队列大小
func UpdateNotificationQueueSize(size int64) {
	NotificationQueueSize.Set(float64(size))
}

// UpdateDBConnectionTotal 更新数据库连接数
func UpdateDBConnectionTotal(idle, inUse, open int) {
	DBConnectionTotal.WithLabelValues("idle").Set(float64(idle))
	DBConnectionTotal.WithLabelValues("in_use").Set(float64(inUse))
	DBConnectionTotal.WithLabelValues("open").Set(float64(open))
}

// UpdateBlacklistTotal 更新黑名单总数
func UpdateBlacklistTotal(subjectID int, riskLevel string, total int64) {
	BlacklistTotal.WithLabelValues(string(rune(subjectID)), riskLevel).Set(float64(total))
}

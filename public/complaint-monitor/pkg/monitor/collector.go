package monitor

import (
	"context"
	"runtime"
	"time"

	"complaint-monitor/pkg/metrics"

	"go.uber.org/zap"
)

// SystemCollector 系统指标采集器
type SystemCollector struct {
	logger    *zap.Logger
	startTime time.Time
	stopChan  chan struct{}
}

// NewSystemCollector 创建系统指标采集器
func NewSystemCollector(logger *zap.Logger) *SystemCollector {
	return &SystemCollector{
		logger:    logger,
		startTime: time.Now(),
		stopChan:  make(chan struct{}),
	}
}

// Start 启动采集器
func (c *SystemCollector) Start(ctx context.Context) {
	c.logger.Info("系统指标采集器启动")

	ticker := time.NewTicker(15 * time.Second)
	defer ticker.Stop()

	// 立即执行一次
	c.collect()

	for {
		select {
		case <-ctx.Done():
			c.logger.Info("系统指标采集器收到停止信号")
			return

		case <-c.stopChan:
			c.logger.Info("系统指标采集器被手动停止")
			return

		case <-ticker.C:
			c.collect()
		}
	}
}

// Stop 停止采集器
func (c *SystemCollector) Stop() {
	c.logger.Info("正在停止系统指标采集器...")
	close(c.stopChan)
}

// collect 采集系统指标
func (c *SystemCollector) collect() {
	// 运行时长
	uptime := time.Since(c.startTime).Seconds()

	// Goroutine数量
	goroutines := runtime.NumGoroutine()

	// 内存使用情况
	var memStats runtime.MemStats
	runtime.ReadMemStats(&memStats)
	memoryUsage := memStats.Alloc // 当前分配的内存

	// CPU使用率（简化版本，实际应该使用更精确的方法）
	// 这里使用GC暂停时间作为粗略估计
	cpuUsage := calculateCPUUsage(&memStats)

	// 更新Prometheus指标
	metrics.UpdateSystemMetrics(uptime, goroutines, memoryUsage, cpuUsage)

	c.logger.Debug("系统指标采集完成",
		zap.Float64("uptime_seconds", uptime),
		zap.Int("goroutines", goroutines),
		zap.Uint64("memory_bytes", memoryUsage),
		zap.Float64("cpu_usage_percent", cpuUsage),
	)
}

// calculateCPUUsage 计算CPU使用率（简化版本）
func calculateCPUUsage(memStats *runtime.MemStats) float64 {
	// 这是一个简化的CPU使用率估算
	// 实际生产环境应该使用更精确的方法，比如：
	// - github.com/shirou/gopsutil/cpu
	// - 直接读取 /proc/stat (Linux)

	// 这里简单地基于GC信息做估算
	gcPausePercent := float64(memStats.PauseTotalNs) / float64(time.Since(time.Time{}).Nanoseconds()) * 100

	// 限制在0-100之间
	if gcPausePercent > 100 {
		gcPausePercent = 100
	}
	if gcPausePercent < 0 {
		gcPausePercent = 0
	}

	return gcPausePercent
}

// GetMemoryStats 获取详细的内存统计信息
func (c *SystemCollector) GetMemoryStats() map[string]interface{} {
	var memStats runtime.MemStats
	runtime.ReadMemStats(&memStats)

	return map[string]interface{}{
		"alloc":           memStats.Alloc,         // 当前分配的字节数
		"total_alloc":     memStats.TotalAlloc,    // 累计分配的字节数
		"sys":             memStats.Sys,           // 从系统获取的字节数
		"heap_alloc":      memStats.HeapAlloc,     // 堆分配的字节数
		"heap_sys":        memStats.HeapSys,       // 堆从系统获取的字节数
		"heap_idle":       memStats.HeapIdle,      // 堆空闲的字节数
		"heap_inuse":      memStats.HeapInuse,     // 堆使用中的字节数
		"heap_released":   memStats.HeapReleased,  // 堆释放给系统的字节数
		"heap_objects":    memStats.HeapObjects,   // 堆对象数量
		"stack_inuse":     memStats.StackInuse,    // 栈使用中的字节数
		"stack_sys":       memStats.StackSys,      // 栈从系统获取的字节数
		"num_gc":          memStats.NumGC,         // GC次数
		"gc_cpu_fraction": memStats.GCCPUFraction, // GC占用的CPU比例
	}
}

// GetGoroutineStats 获取Goroutine统计信息
func (c *SystemCollector) GetGoroutineStats() map[string]interface{} {
	return map[string]interface{}{
		"total":          runtime.NumGoroutine(),
		"uptime_seconds": time.Since(c.startTime).Seconds(),
	}
}

// ForceGC 强制执行垃圾回收（谨慎使用）
func (c *SystemCollector) ForceGC() {
	c.logger.Warn("手动触发GC")
	before := runtime.NumGoroutine()

	runtime.GC()

	after := runtime.NumGoroutine()
	c.logger.Info("GC完成",
		zap.Int("goroutines_before", before),
		zap.Int("goroutines_after", after),
	)
}

package main

import (
	"context"
	"flag"
	"fmt"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"complaint-monitor/internal/cert"
	"complaint-monitor/internal/config"
	"complaint-monitor/internal/lock"
	"complaint-monitor/internal/logger"
	"complaint-monitor/internal/repository"
	"complaint-monitor/internal/service"
	"complaint-monitor/internal/worker"
	"complaint-monitor/pkg/metrics"
	"complaint-monitor/pkg/monitor"

	"github.com/go-redis/redis/v8"
	"github.com/prometheus/client_golang/prometheus/promhttp"
	"go.uber.org/zap"
)

var (
	configPath = flag.String("config", "configs/config.yaml", "配置文件路径")
	version    = "v1.1.0"
	buildTime  = "2025-10-29"
)

func main() {
	// 解析命令行参数
	flag.Parse()

	// 显示版本信息
	fmt.Printf("投诉监控系统 (Complaint Monitor) %s\n", version)
	fmt.Printf("构建时间: %s\n", buildTime)
	fmt.Printf("配置文件: %s\n", *configPath)
	fmt.Println("---")

	// 加载配置
	cfg, err := config.LoadWithDefaults(*configPath)
	if err != nil {
		fmt.Printf("❌ 加载配置失败: %v\n", err)
		os.Exit(1)
	}

	// 初始化日志
	log, err := logger.NewLoggerWithOptions(cfg.App.LogLevel, cfg.IsDevelopment())
	if err != nil {
		fmt.Printf("❌ 初始化日志失败: %v\n", err)
		os.Exit(1)
	}
	defer log.Sync()

	log.Info("🚀 投诉监控服务启动",
		zap.String("app_name", cfg.App.Name),
		zap.String("version", version),
		zap.String("environment", cfg.App.Environment),
		zap.String("log_level", cfg.App.LogLevel),
	)

	// 打印配置信息
	log.Info("配置信息",
		zap.String("database", fmt.Sprintf("%s:%d/%s", cfg.Database.Host, cfg.Database.Port, cfg.Database.Database)),
		zap.String("redis", cfg.Redis.GetAddress()),
		zap.String("metrics", cfg.Metrics.GetAddress()),
		zap.String("health", cfg.Health.GetAddress()),
	)

	// 初始化数据库连接
	database, err := repository.NewDatabase(&cfg.Database, log)
	if err != nil {
		log.Fatal("初始化数据库失败", zap.Error(err))
	}
	defer database.Close()

	log.Info("数据库连接成功", zap.String("database", cfg.Database.Database))

	// 初始化Redis连接
	redisClient := redis.NewClient(&redis.Options{
		Addr:     cfg.Redis.GetAddress(),
		Password: cfg.Redis.Password,
		DB:       cfg.Redis.DB,
		PoolSize: cfg.Redis.PoolSize,
	})
	defer redisClient.Close()

	// 测试Redis连接
	redisCtx, redisCancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer redisCancel()
	if err := redisClient.Ping(redisCtx).Err(); err != nil {
		log.Fatal("Redis连接失败", zap.Error(err))
	}
	log.Info("Redis连接成功", zap.String("address", cfg.Redis.GetAddress()))

	// 初始化仓库层
	db := database.GetDB()
	subjectRepo := repository.NewSubjectRepository(db, log)
	complaintRepo := repository.NewComplaintRepository(db, log)
	blacklistRepo := repository.NewBlacklistRepository(db, log)
	orderRepo := repository.NewOrderRepository(db, log)

	// 初始化证书管理器
	certManager := cert.NewCertManager(
		[]byte(cfg.Cert.EncryptionKey),
		cfg.Cert.GetCacheTTL(),
		log,
	)

	// 初始化分布式锁
	lockManager := lock.NewDistributedLock(
		redisClient,
		cfg.Lock.GetBaseTTL(),
		cfg.Lock.GetMaxTTL(),
		log,
	)

	// 初始化服务层
	alipayService := service.NewAlipayService(log)
	notificationService := service.NewNotificationService(db, log)
	blacklistService := service.NewBlacklistService(blacklistRepo, complaintRepo, notificationService, log)

	// 初始化Worker管理器
	workerManager := worker.NewManager(
		cfg,
		subjectRepo,
		complaintRepo,
		blacklistRepo,
		orderRepo,
		certManager,
		lockManager,
		alipayService,
		blacklistService,
		log,
	)

	// 创建上下文
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	// 启动Worker管理器
	go workerManager.Start(ctx)

	// 初始化系统指标采集器
	systemCollector := monitor.NewSystemCollector(log)
	go systemCollector.Start(ctx)

	// 初始化健康检查器
	healthChecker := monitor.NewHealthChecker(db, redisClient, log)

	// 启动Metrics服务
	metricsServer := &http.Server{
		Addr:              cfg.Metrics.GetAddress(),
		Handler:           promhttp.Handler(),
		ReadHeaderTimeout: 5 * time.Second,
	}
	go func() {
		log.Info("📊 Prometheus指标服务启动",
			zap.String("address", metricsServer.Addr),
			zap.String("path", cfg.Metrics.Path))

		if err := metricsServer.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Error("Metrics服务启动失败", zap.Error(err))
		}
	}()

	// 启动健康检查服务
	healthMux := http.NewServeMux()
	healthMux.HandleFunc(cfg.Health.Path, healthChecker.HandleHealth(time.Now()))
	healthMux.HandleFunc("/liveness", healthChecker.HandleLiveness())
	healthMux.HandleFunc("/readiness", healthChecker.HandleReadiness(time.Now()))

	healthServer := &http.Server{
		Addr:              cfg.Health.GetAddress(),
		Handler:           healthMux,
		ReadHeaderTimeout: 5 * time.Second,
	}
	go func() {
		log.Info("💖 健康检查服务启动",
			zap.String("address", healthServer.Addr),
			zap.String("health", cfg.Health.Path),
			zap.String("liveness", "/liveness"),
			zap.String("readiness", "/readiness"))

		if err := healthServer.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Error("健康检查服务启动失败", zap.Error(err))
		}
	}()

	// 更新初始指标
	metrics.UpdateWorkerTotal(workerManager.GetWorkerCount())

	log.Info("✅ 所有组件初始化完成，服务正常运行")
	log.Info("📊 监控端点",
		zap.String("metrics", fmt.Sprintf("http://localhost%s%s", cfg.Metrics.GetAddress(), cfg.Metrics.Path)),
		zap.String("health", fmt.Sprintf("http://localhost%s%s", cfg.Health.GetAddress(), cfg.Health.Path)),
		zap.String("liveness", fmt.Sprintf("http://localhost%s/liveness", cfg.Health.GetAddress())),
		zap.String("readiness", fmt.Sprintf("http://localhost%s/readiness", cfg.Health.GetAddress())),
	)

	// 等待退出信号
	sigChan := make(chan os.Signal, 1)
	signal.Notify(sigChan, syscall.SIGINT, syscall.SIGTERM)

	// 主循环（暂时模拟）
	ticker := time.NewTicker(10 * time.Second)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			log.Info("收到上下文取消信号")
			return

		case sig := <-sigChan:
			log.Info("收到退出信号，开始优雅关闭...",
				zap.String("signal", sig.String()),
			)

			// 创建超时上下文
			shutdownCtx, shutdownCancel := context.WithTimeout(context.Background(), 30*time.Second)
			defer shutdownCancel()

			// 执行优雅关闭
			if err := gracefulShutdown(shutdownCtx, log, workerManager, database, redisClient, metricsServer, healthServer, systemCollector); err != nil {
				log.Error("优雅关闭失败", zap.Error(err))
				os.Exit(1)
			}

			log.Info("✅ 服务已安全停止")
			return

		case <-ticker.C:
			log.Debug("服务运行中...",
				zap.String("status", "healthy"),
				zap.Time("timestamp", time.Now()),
			)
		}
	}
}

// gracefulShutdown 优雅关闭
func gracefulShutdown(
	ctx context.Context,
	log *zap.Logger,
	workerManager *worker.Manager,
	database *repository.Database,
	redisClient *redis.Client,
	metricsServer *http.Server,
	healthServer *http.Server,
	systemCollector *monitor.SystemCollector,
) error {
	log.Info("开始执行优雅关闭...")

	// 停止Worker管理器
	workerManager.Stop()
	log.Info("Worker管理器已停止")

	// 停止系统指标采集器
	systemCollector.Stop()
	log.Info("系统指标采集器已停止")

	// 关闭Metrics服务
	if err := metricsServer.Shutdown(ctx); err != nil {
		log.Error("关闭Metrics服务失败", zap.Error(err))
	} else {
		log.Info("Metrics服务已关闭")
	}

	// 关闭健康检查服务
	if err := healthServer.Shutdown(ctx); err != nil {
		log.Error("关闭健康检查服务失败", zap.Error(err))
	} else {
		log.Info("健康检查服务已关闭")
	}

	// 关闭Redis连接
	if err := redisClient.Close(); err != nil {
		log.Error("关闭Redis连接失败", zap.Error(err))
	} else {
		log.Info("Redis连接已关闭")
	}

	// 关闭数据库连接
	if err := database.Close(); err != nil {
		log.Error("关闭数据库连接失败", zap.Error(err))
	} else {
		log.Info("数据库连接已关闭")
	}

	log.Info("所有组件已安全关闭")
	return nil
}

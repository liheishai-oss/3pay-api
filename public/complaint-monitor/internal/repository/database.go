package repository

import (
	"fmt"
	"time"

	"complaint-monitor/internal/config"

	"go.uber.org/zap"
	"gorm.io/driver/mysql"
	"gorm.io/gorm"
	"gorm.io/gorm/logger"
)

// Database 数据库连接管理器
type Database struct {
	db     *gorm.DB
	logger *zap.Logger
}

// NewDatabase 创建数据库连接
func NewDatabase(cfg *config.DatabaseConfig, log *zap.Logger) (*Database, error) {
	// 配置GORM日志级别
	gormLogger := logger.Default
	if log != nil {
		gormLogger = logger.Default.LogMode(logger.Silent) // 生产环境使用Silent
	}

	// 打开数据库连接
	db, err := gorm.Open(mysql.Open(cfg.GetDSN()), &gorm.Config{
		Logger:                 gormLogger,
		SkipDefaultTransaction: true,  // 跳过默认事务以提高性能
		PrepareStmt:            true,  // 预编译SQL语句
	})
	if err != nil {
		return nil, fmt.Errorf("连接数据库失败: %w", err)
	}

	// 获取底层sql.DB
	sqlDB, err := db.DB()
	if err != nil {
		return nil, fmt.Errorf("获取sql.DB失败: %w", err)
	}

	// 设置连接池参数
	sqlDB.SetMaxOpenConns(cfg.MaxOpenConns)
	sqlDB.SetMaxIdleConns(cfg.MaxIdleConns)
	sqlDB.SetConnMaxLifetime(cfg.GetMaxLifetime())

	// 测试连接
	if err := sqlDB.Ping(); err != nil {
		return nil, fmt.Errorf("数据库ping失败: %w", err)
	}

	log.Info("数据库连接成功",
		zap.String("host", cfg.Host),
		zap.Int("port", cfg.Port),
		zap.String("database", cfg.Database),
		zap.Int("max_open_conns", cfg.MaxOpenConns),
		zap.Int("max_idle_conns", cfg.MaxIdleConns),
	)

	return &Database{
		db:     db,
		logger: log,
	}, nil
}

// GetDB 获取GORM实例
func (d *Database) GetDB() *gorm.DB {
	return d.db
}

// Close 关闭数据库连接
func (d *Database) Close() error {
	sqlDB, err := d.db.DB()
	if err != nil {
		return err
	}

	d.logger.Info("正在关闭数据库连接...")
	return sqlDB.Close()
}

// HealthCheck 健康检查
func (d *Database) HealthCheck() error {
	sqlDB, err := d.db.DB()
	if err != nil {
		return err
	}
	return sqlDB.Ping()
}

// GetStats 获取连接池统计
func (d *Database) GetStats() map[string]interface{} {
	sqlDB, err := d.db.DB()
	if err != nil {
		return map[string]interface{}{
			"error": err.Error(),
		}
	}

	stats := sqlDB.Stats()
	return map[string]interface{}{
		"max_open_connections":   stats.MaxOpenConnections,
		"open_connections":       stats.OpenConnections,
		"in_use":                 stats.InUse,
		"idle":                   stats.Idle,
		"wait_count":             stats.WaitCount,
		"wait_duration":          stats.WaitDuration.String(),
		"max_idle_closed":        stats.MaxIdleClosed,
		"max_lifetime_closed":    stats.MaxLifetimeClosed,
	}
}

// WithTransaction 执行事务
func (d *Database) WithTransaction(fn func(*gorm.DB) error) error {
	return d.db.Transaction(fn)
}

// Repository 基础仓库接口
type Repository interface {
	GetDB() *gorm.DB
}

// BaseRepository 基础仓库实现
type BaseRepository struct {
	db     *gorm.DB
	logger *zap.Logger
}

// NewBaseRepository 创建基础仓库
func NewBaseRepository(db *gorm.DB, logger *zap.Logger) *BaseRepository {
	return &BaseRepository{
		db:     db,
		logger: logger,
	}
}

// GetDB 获取DB实例
func (r *BaseRepository) GetDB() *gorm.DB {
	return r.db
}

// WithContext 带超时的上下文
func (r *BaseRepository) WithTimeout(timeout time.Duration) *gorm.DB {
	// TODO: 实现context超时控制
	return r.db
}


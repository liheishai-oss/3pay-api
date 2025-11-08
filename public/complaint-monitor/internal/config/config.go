package config

import (
	"fmt"
	"time"
)

// Config 应用配置
type Config struct {
	App      AppConfig      `mapstructure:"app"`
	Database DatabaseConfig `mapstructure:"database"`
	Redis    RedisConfig    `mapstructure:"redis"`
	Worker   WorkerConfig   `mapstructure:"worker"`
	Cert     CertConfig     `mapstructure:"cert"`
	Lock     LockConfig     `mapstructure:"lock"`
	Metrics  MetricsConfig  `mapstructure:"metrics"`
	Health   HealthConfig   `mapstructure:"health"`
}

// AppConfig 应用配置
type AppConfig struct {
	Name        string `mapstructure:"name"`
	Environment string `mapstructure:"environment"`
	LogLevel    string `mapstructure:"log_level"`
}

// DatabaseConfig 数据库配置
type DatabaseConfig struct {
	Host            string `mapstructure:"host"`
	Port            int    `mapstructure:"port"`
	Username        string `mapstructure:"username"`
	Password        string `mapstructure:"password"`
	Database        string `mapstructure:"database"`
	MaxOpenConns    int    `mapstructure:"max_open_conns"`
	MaxIdleConns    int    `mapstructure:"max_idle_conns"`
	ConnMaxLifetime int    `mapstructure:"conn_max_lifetime"` // 秒
}

// GetDSN 获取数据库连接字符串
func (c *DatabaseConfig) GetDSN() string {
	return fmt.Sprintf("%s:%s@tcp(%s:%d)/%s?charset=utf8mb4&parseTime=True&loc=Local",
		c.Username,
		c.Password,
		c.Host,
		c.Port,
		c.Database,
	)
}

// GetMaxLifetime 获取连接最大生命周期
func (c *DatabaseConfig) GetMaxLifetime() time.Duration {
	return time.Duration(c.ConnMaxLifetime) * time.Second
}

// RedisConfig Redis配置
type RedisConfig struct {
	Host     string `mapstructure:"host"`
	Port     int    `mapstructure:"port"`
	Password string `mapstructure:"password"`
	DB       int    `mapstructure:"db"`
	PoolSize int    `mapstructure:"pool_size"`
}

// GetAddress 获取Redis地址
func (c *RedisConfig) GetAddress() string {
	return fmt.Sprintf("%s:%d", c.Host, c.Port)
}

// WorkerConfig Worker配置
type WorkerConfig struct {
	RefreshInterval int  `mapstructure:"refresh_interval"` // 刷新主体列表间隔（秒）
	FetchInterval   int  `mapstructure:"fetch_interval"`   // 获取投诉间隔（秒）
	Restartable     bool `mapstructure:"restartable"`      // 是否自动重启
}

// GetRefreshInterval 获取刷新间隔
func (c *WorkerConfig) GetRefreshInterval() time.Duration {
	return time.Duration(c.RefreshInterval) * time.Second
}

// GetFetchInterval 获取获取间隔
func (c *WorkerConfig) GetFetchInterval() time.Duration {
	return time.Duration(c.FetchInterval) * time.Second
}

// CertConfig 证书配置
type CertConfig struct {
	CacheTTL      int    `mapstructure:"cache_ttl"`      // 证书缓存时间（秒）
	EncryptionKey string `mapstructure:"encryption_key"` // 加密密钥（32字节）
}

// GetCacheTTL 获取缓存TTL
func (c *CertConfig) GetCacheTTL() time.Duration {
	return time.Duration(c.CacheTTL) * time.Second
}

// Validate 验证配置
func (c *CertConfig) Validate() error {
	if len(c.EncryptionKey) != 32 {
		return fmt.Errorf("加密密钥必须是32字节，当前长度：%d", len(c.EncryptionKey))
	}
	return nil
}

// LockConfig 分布式锁配置
type LockConfig struct {
	BaseTTL int `mapstructure:"base_ttl"` // 基础锁TTL（秒）
	MaxTTL  int `mapstructure:"max_ttl"`  // 最大锁TTL（秒）
}

// GetBaseTTL 获取基础TTL
func (c *LockConfig) GetBaseTTL() time.Duration {
	return time.Duration(c.BaseTTL) * time.Second
}

// GetMaxTTL 获取最大TTL
func (c *LockConfig) GetMaxTTL() time.Duration {
	return time.Duration(c.MaxTTL) * time.Second
}

// MetricsConfig 监控配置
type MetricsConfig struct {
	Port int    `mapstructure:"port"`
	Path string `mapstructure:"path"`
}

// GetAddress 获取监控地址
func (c *MetricsConfig) GetAddress() string {
	return fmt.Sprintf(":%d", c.Port)
}

// HealthConfig 健康检查配置
type HealthConfig struct {
	Port int    `mapstructure:"port"`
	Path string `mapstructure:"path"`
}

// GetAddress 获取健康检查地址
func (c *HealthConfig) GetAddress() string {
	return fmt.Sprintf(":%d", c.Port)
}

// Validate 验证配置
func (cfg *Config) Validate() error {
	// 验证证书配置
	if err := cfg.Cert.Validate(); err != nil {
		return fmt.Errorf("证书配置错误: %w", err)
	}

	// 验证数据库配置
	if cfg.Database.Host == "" {
		return fmt.Errorf("数据库地址不能为空")
	}
	if cfg.Database.Database == "" {
		return fmt.Errorf("数据库名称不能为空")
	}

	// 验证Redis配置
	if cfg.Redis.Host == "" {
		return fmt.Errorf("Redis地址不能为空")
	}

	return nil
}

// IsDevelopment 是否开发环境
func (cfg *Config) IsDevelopment() bool {
	return cfg.App.Environment == "development"
}

// IsProduction 是否生产环境
func (cfg *Config) IsProduction() bool {
	return cfg.App.Environment == "production"
}

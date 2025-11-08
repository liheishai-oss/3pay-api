package config

import (
	"fmt"

	"github.com/spf13/viper"
)

// Load 加载配置文件
func Load(configPath string) (*Config, error) {
	v := viper.New()

	// 设置配置文件路径
	v.SetConfigFile(configPath)

	// 设置配置文件类型
	v.SetConfigType("yaml")

	// 读取配置文件
	if err := v.ReadInConfig(); err != nil {
		return nil, fmt.Errorf("读取配置文件失败: %w", err)
	}

	// 解析配置
	var cfg Config
	if err := v.Unmarshal(&cfg); err != nil {
		return nil, fmt.Errorf("解析配置失败: %w", err)
	}

	// 验证配置
	if err := cfg.Validate(); err != nil {
		return nil, fmt.Errorf("配置验证失败: %w", err)
	}

	return &cfg, nil
}

// LoadWithDefaults 加载配置文件（带默认值）
func LoadWithDefaults(configPath string) (*Config, error) {
	cfg, err := Load(configPath)
	if err != nil {
		return nil, err
	}

	// 设置默认值
	setDefaults(cfg)

	return cfg, nil
}

// setDefaults 设置默认值
func setDefaults(cfg *Config) {
	// 应用配置默认值
	if cfg.App.LogLevel == "" {
		cfg.App.LogLevel = "info"
	}

	// 数据库配置默认值
	if cfg.Database.MaxOpenConns == 0 {
		cfg.Database.MaxOpenConns = 100
	}
	if cfg.Database.MaxIdleConns == 0 {
		cfg.Database.MaxIdleConns = 10
	}
	if cfg.Database.ConnMaxLifetime == 0 {
		cfg.Database.ConnMaxLifetime = 3600
	}

	// Redis配置默认值
	if cfg.Redis.PoolSize == 0 {
		cfg.Redis.PoolSize = 10
	}

	// Worker配置默认值
	if cfg.Worker.RefreshInterval == 0 {
		cfg.Worker.RefreshInterval = 60
	}
	if cfg.Worker.FetchInterval == 0 {
		cfg.Worker.FetchInterval = 2
	}

	// 证书配置默认值
	if cfg.Cert.CacheTTL == 0 {
		cfg.Cert.CacheTTL = 3600
	}

	// 锁配置默认值
	if cfg.Lock.BaseTTL == 0 {
		cfg.Lock.BaseTTL = 60
	}
	if cfg.Lock.MaxTTL == 0 {
		cfg.Lock.MaxTTL = 300
	}

	// 监控配置默认值
	if cfg.Metrics.Port == 0 {
		cfg.Metrics.Port = 9090
	}
	if cfg.Metrics.Path == "" {
		cfg.Metrics.Path = "/metrics"
	}

	// 健康检查配置默认值
	if cfg.Health.Port == 0 {
		cfg.Health.Port = 8080
	}
	if cfg.Health.Path == "" {
		cfg.Health.Path = "/health"
	}
}

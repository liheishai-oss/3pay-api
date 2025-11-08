package config

import (
	"testing"
)

func TestConfigLoad(t *testing.T) {
	// 测试加载测试配置文件
	cfg, err := Load("../../configs/config.test.yaml")
	if err != nil {
		t.Fatalf("加载配置失败: %v", err)
	}

	// 验证应用配置
	if cfg.App.Name != "complaint-monitor-test" {
		t.Errorf("期望应用名称为 'complaint-monitor-test', 实际为 '%s'", cfg.App.Name)
	}

	if cfg.App.Environment != "test" {
		t.Errorf("期望环境为 'test', 实际为 '%s'", cfg.App.Environment)
	}

	// 验证数据库配置
	if cfg.Database.Database != "third_party_payment" {
		t.Errorf("期望数据库名称为 'third_party_payment', 实际为 '%s'", cfg.Database.Database)
	}

	if cfg.Database.Host != "mysql" {
		t.Errorf("期望数据库主机为 'mysql', 实际为 '%s'", cfg.Database.Host)
	}

	// 验证Redis配置
	if cfg.Redis.DB != 0 {
		t.Errorf("期望Redis DB为 0, 实际为 %d", cfg.Redis.DB)
	}

	if cfg.Redis.Host != "redis" {
		t.Errorf("期望Redis主机为 'redis', 实际为 '%s'", cfg.Redis.Host)
	}
}

func TestConfigValidate(t *testing.T) {
	tests := []struct {
		name    string
		config  *Config
		wantErr bool
	}{
		{
			name: "有效配置",
			config: &Config{
				Database: DatabaseConfig{
					Host:     "localhost",
					Database: "test_db",
				},
				Redis: RedisConfig{
					Host: "localhost",
				},
				Cert: CertConfig{
					EncryptionKey: "12345678901234567890123456789012", // 32字节
				},
			},
			wantErr: false,
		},
		{
			name: "无效证书密钥长度",
			config: &Config{
				Database: DatabaseConfig{
					Host:     "localhost",
					Database: "test_db",
				},
				Redis: RedisConfig{
					Host: "localhost",
				},
				Cert: CertConfig{
					EncryptionKey: "short_key", // 不足32字节
				},
			},
			wantErr: true,
		},
		{
			name: "缺少数据库主机",
			config: &Config{
				Database: DatabaseConfig{
					Database: "test_db",
				},
				Redis: RedisConfig{
					Host: "localhost",
				},
				Cert: CertConfig{
					EncryptionKey: "12345678901234567890123456789012",
				},
			},
			wantErr: true,
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			err := tt.config.Validate()
			if (err != nil) != tt.wantErr {
				t.Errorf("Validate() error = %v, wantErr %v", err, tt.wantErr)
			}
		})
	}
}

func TestDatabaseGetDSN(t *testing.T) {
	db := DatabaseConfig{
		Host:     "localhost",
		Port:     3306,
		Username: "root",
		Password: "password",
		Database: "test_db",
	}

	dsn := db.GetDSN()
	expected := "root:password@tcp(localhost:3306)/test_db?charset=utf8mb4&parseTime=True&loc=Local"

	if dsn != expected {
		t.Errorf("期望DSN为 '%s', 实际为 '%s'", expected, dsn)
	}
}

func TestRedisGetAddress(t *testing.T) {
	redis := RedisConfig{
		Host: "192.168.1.1",
		Port: 6379,
	}

	addr := redis.GetAddress()
	expected := "192.168.1.1:6379"

	if addr != expected {
		t.Errorf("期望地址为 '%s', 实际为 '%s'", expected, addr)
	}
}

func TestConfigHelperMethods(t *testing.T) {
	cfg := &Config{
		App: AppConfig{
			Environment: "development",
		},
	}

	if !cfg.IsDevelopment() {
		t.Error("应该识别为开发环境")
	}

	if cfg.IsProduction() {
		t.Error("不应该识别为生产环境")
	}

	cfg.App.Environment = "production"
	if !cfg.IsProduction() {
		t.Error("应该识别为生产环境")
	}

	if cfg.IsDevelopment() {
		t.Error("不应该识别为开发环境")
	}
}

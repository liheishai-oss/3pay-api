package logger

import (
	"fmt"

	"go.uber.org/zap"
	"go.uber.org/zap/zapcore"
)

// NewLogger 创建新的日志实例
func NewLogger(level string) (*zap.Logger, error) {
	// 创建生产环境配置
	cfg := zap.NewProductionConfig()

	// 设置日志级别
	zapLevel, err := parseLogLevel(level)
	if err != nil {
		return nil, err
	}
	cfg.Level = zap.NewAtomicLevelAt(zapLevel)

	// 配置编码器
	cfg.EncoderConfig.TimeKey = "timestamp"
	cfg.EncoderConfig.EncodeTime = zapcore.ISO8601TimeEncoder
	cfg.EncoderConfig.EncodeDuration = zapcore.StringDurationEncoder
	cfg.EncoderConfig.EncodeLevel = zapcore.CapitalLevelEncoder

	// 配置输出路径
	cfg.OutputPaths = []string{"stdout"}
	cfg.ErrorOutputPaths = []string{"stderr"}

	// 构建logger
	logger, err := cfg.Build(
		zap.AddCallerSkip(0),
		zap.AddCaller(),
		zap.AddStacktrace(zapcore.ErrorLevel),
	)
	if err != nil {
		return nil, fmt.Errorf("构建日志实例失败: %w", err)
	}

	return logger, nil
}

// NewDevelopmentLogger 创建开发环境日志实例
func NewDevelopmentLogger() (*zap.Logger, error) {
	// 创建开发环境配置
	cfg := zap.NewDevelopmentConfig()

	// 配置编码器
	cfg.EncoderConfig.EncodeLevel = zapcore.CapitalColorLevelEncoder

	// 构建logger
	logger, err := cfg.Build(
		zap.AddCallerSkip(0),
		zap.AddCaller(),
	)
	if err != nil {
		return nil, fmt.Errorf("构建开发日志实例失败: %w", err)
	}

	return logger, nil
}

// parseLogLevel 解析日志级别
func parseLogLevel(level string) (zapcore.Level, error) {
	switch level {
	case "debug":
		return zapcore.DebugLevel, nil
	case "info":
		return zapcore.InfoLevel, nil
	case "warn", "warning":
		return zapcore.WarnLevel, nil
	case "error":
		return zapcore.ErrorLevel, nil
	case "dpanic":
		return zapcore.DPanicLevel, nil
	case "panic":
		return zapcore.PanicLevel, nil
	case "fatal":
		return zapcore.FatalLevel, nil
	default:
		return zapcore.InfoLevel, fmt.Errorf("未知的日志级别: %s，使用默认级别 info", level)
	}
}

// NewLoggerWithOptions 创建带选项的日志实例
func NewLoggerWithOptions(level string, isDevelopment bool) (*zap.Logger, error) {
	if isDevelopment {
		return NewDevelopmentLogger()
	}
	return NewLogger(level)
}

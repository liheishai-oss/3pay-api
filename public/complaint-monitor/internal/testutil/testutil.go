package testutil

import (
	"testing"

	"complaint-monitor/internal/config"
	"complaint-monitor/internal/logger"

	"go.uber.org/zap"
)

// LoadTestConfig 加载测试配置
func LoadTestConfig(t *testing.T) *config.Config {
	cfg, err := config.Load("../../configs/config.test.yaml")
	if err != nil {
		t.Fatalf("加载测试配置失败: %v", err)
	}
	return cfg
}

// NewTestLogger 创建测试用日志器
func NewTestLogger(t *testing.T) *zap.Logger {
	log, err := logger.NewLogger("debug")
	if err != nil {
		t.Fatalf("创建测试日志器失败: %v", err)
	}
	return log
}

// SkipIfShort 如果是短测试则跳过
func SkipIfShort(t *testing.T) {
	if testing.Short() {
		t.Skip("跳过长时间运行的测试")
	}
}

// MockSubject 创建模拟主体数据
func MockSubject(id int, appID string) map[string]interface{} {
	return map[string]interface{}{
		"id":               id,
		"merchant_id":      1,
		"subject_name":     "测试主体",
		"app_id":           appID,
		"status":           1,
		"verify_device":    0,
		"allow_remote_order": 1,
	}
}

// MockComplaint 创建模拟投诉数据
func MockComplaint(id int, subjectID int, complaintNo string) map[string]interface{} {
	return map[string]interface{}{
		"id":               id,
		"subject_id":       subjectID,
		"complaint_no":     complaintNo,
		"complaint_type":   "商品质量",
		"complaint_status": "processing",
		"complainant_id":   "2088000000000001",
		"complaint_reason": "测试投诉原因",
	}
}

// MockBlacklist 创建模拟黑名单数据
func MockBlacklist(id int, subjectID int, alipayUID string) map[string]interface{} {
	return map[string]interface{}{
		"id":               id,
		"subject_id":       subjectID,
		"alipay_user_id":   alipayUID,
		"device_code":      "test_device_123",
		"ip_address":       "192.168.1.100",
		"blacklist_type":   "complaint",
		"risk_level":       "high",
		"complaint_count":  5,
		"status":           1,
	}
}

// AssertNoError 断言无错误
func AssertNoError(t *testing.T, err error, msg string) {
	t.Helper()
	if err != nil {
		t.Fatalf("%s: %v", msg, err)
	}
}

// AssertError 断言有错误
func AssertError(t *testing.T, err error, msg string) {
	t.Helper()
	if err == nil {
		t.Fatalf("%s: 期望有错误但没有", msg)
	}
}

// AssertEqual 断言相等
func AssertEqual(t *testing.T, got, want interface{}, msg string) {
	t.Helper()
	if got != want {
		t.Errorf("%s: 期望 %v, 实际 %v", msg, want, got)
	}
}

// AssertNotEqual 断言不相等
func AssertNotEqual(t *testing.T, got, want interface{}, msg string) {
	t.Helper()
	if got == want {
		t.Errorf("%s: 不期望 %v", msg, got)
	}
}

// AssertTrue 断言为真
func AssertTrue(t *testing.T, condition bool, msg string) {
	t.Helper()
	if !condition {
		t.Errorf("%s: 期望为true", msg)
	}
}

// AssertFalse 断言为假
func AssertFalse(t *testing.T, condition bool, msg string) {
	t.Helper()
	if condition {
		t.Errorf("%s: 期望为false", msg)
	}
}

// AssertContains 断言包含
func AssertContains(t *testing.T, haystack, needle string, msg string) {
	t.Helper()
	if !contains(haystack, needle) {
		t.Errorf("%s: '%s' 不包含 '%s'", msg, haystack, needle)
	}
}

func contains(s, substr string) bool {
	return len(s) >= len(substr) && (s == substr || len(substr) == 0 || 
		(len(s) > 0 && len(substr) > 0 && findSubstring(s, substr)))
}

func findSubstring(s, substr string) bool {
	for i := 0; i <= len(s)-len(substr); i++ {
		if s[i:i+len(substr)] == substr {
			return true
		}
	}
	return false
}


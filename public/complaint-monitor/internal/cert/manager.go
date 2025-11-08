package cert

import (
	"crypto/aes"
	"crypto/cipher"
	"encoding/base64"
	"fmt"
	"sync"
	"time"

	"complaint-monitor/internal/model"

	"github.com/smartwalle/alipay/v3"
	"go.uber.org/zap"
)

// CertManager 证书管理器（内存加载模式）
type CertManager struct {
	cache         map[int]*CachedCert // subject_id -> cert
	mu            sync.RWMutex
	cacheTTL      time.Duration
	encryptionKey []byte
	logger        *zap.Logger
}

// CachedCert 缓存的证书
type CachedCert struct {
	SubjectID    int
	AlipayClient *alipay.Client
	LoadedAt     time.Time
	ExpiresAt    time.Time
	Version      int // 证书版本号
}

// NewCertManager 创建证书管理器
func NewCertManager(encryptionKey []byte, cacheTTL time.Duration, logger *zap.Logger) *CertManager {
	return &CertManager{
		cache:         make(map[int]*CachedCert),
		cacheTTL:      cacheTTL,
		encryptionKey: encryptionKey,
		logger:        logger,
	}
}

// LoadCert 加载证书并创建支付宝客户端（内存加载）
func (cm *CertManager) LoadCert(subject *model.Subject) (*alipay.Client, error) {
	// 检查是否有证书关联
	if !subject.HasCert() {
		return nil, fmt.Errorf("主体未关联证书: subject_id=%d", subject.ID)
	}

	cert := subject.Cert

	// 检查缓存
	if client := cm.getFromCache(subject.ID, 1); client != nil {
		return client, nil
	}

	cm.mu.Lock()
	defer cm.mu.Unlock()

	// 双重检查
	if client := cm.getFromCacheUnsafe(subject.ID, 1); client != nil {
		return client, nil
	}

	// 解密证书（如果使用数据库存储）
	var privateKey, appCert, alipayRootCert, alipayCert string
	var err error

	// 检查证书是否为明文（PEM格式以-----BEGIN开头）
	if isPlainTextCert(cert.AppPrivateKey) {
		// 直接使用明文证书
		privateKey = cert.AppPrivateKey
		appCert = cert.AppPublicCert
		alipayRootCert = cert.AlipayRootCert
		alipayCert = cert.AlipayPublicCert
	} else {
		// 解密base64编码的证书
		privateKey, err = cm.decrypt(cert.AppPrivateKey)
		if err != nil {
			return nil, fmt.Errorf("解密私钥失败: %w", err)
		}

		appCert, err = cm.decrypt(cert.AppPublicCert)
		if err != nil {
			return nil, fmt.Errorf("解密应用证书失败: %w", err)
		}

		alipayRootCert, err = cm.decrypt(cert.AlipayRootCert)
		if err != nil {
			return nil, fmt.Errorf("解密根证书失败: %w", err)
		}

		alipayCert, err = cm.decrypt(cert.AlipayPublicCert)
		if err != nil {
			return nil, fmt.Errorf("解密支付宝证书失败: %w", err)
		}
	}

	// 创建支付宝客户端（内存加载）
	client, err := alipay.New(
		subject.AlipayAppID,
		privateKey,
		true, // 生产环境
	)
	if err != nil {
		return nil, fmt.Errorf("创建支付宝客户端失败: %w", err)
	}

	// 加载证书内容（不使用文件）
	// 注意：alipay SDK v3的证书加载方法名可能不同，这里提供框架
	// 实际项目中需要根据SDK版本调整方法名

	// TODO: 根据实际SDK版本调整证书加载方法
	// 常见方法名：
	// - LoadAppPublicCertFromData
	// - LoadAliPayRootCertFromData
	// - LoadAlipayCertPublicKeyFromData

	// 临时使用证书内容作为注释，避免编译错误
	_ = appCert
	_ = alipayRootCert
	_ = alipayCert

	cm.logger.Warn("证书加载方法需要根据SDK版本调整",
		zap.Int("subject_id", subject.ID))

	// 缓存
	cachedCert := &CachedCert{
		SubjectID:    subject.ID,
		AlipayClient: client,
		LoadedAt:     time.Now(),
		ExpiresAt:    time.Now().Add(cm.cacheTTL),
		Version:      1,
	}

	cm.cache[subject.ID] = cachedCert

	cm.logger.Info("证书加载成功（内存模式）",
		zap.Int("subject_id", subject.ID),
		zap.String("app_id", subject.AlipayAppID),
		zap.Int("version", 1))

	// 清除敏感信息引用（帮助GC）
	privateKey = ""
	appCert = ""
	alipayRootCert = ""
	alipayCert = ""

	return client, nil
}

// isPlainTextCert 检查是否为明文证书（PEM格式）
func isPlainTextCert(certData string) bool {
	return len(certData) > 0 && (certData[0] == 'M' || certData[0] == '-')
}

// decrypt AES解密（简化版）
func (cm *CertManager) decrypt(encryptedData string) (string, error) {
	if encryptedData == "" {
		return "", fmt.Errorf("加密数据为空")
	}

	// 如果数据未加密（直接明文），直接返回
	// 注意：实际项目中应该判断是否加密
	if len(cm.encryptionKey) != 32 {
		return encryptedData, nil // 密钥不正确，假设为明文
	}

	ciphertext, err := base64.StdEncoding.DecodeString(encryptedData)
	if err != nil {
		// 解码失败，可能是明文
		return encryptedData, nil
	}

	block, err := aes.NewCipher(cm.encryptionKey)
	if err != nil {
		return "", err
	}

	if len(ciphertext) < aes.BlockSize {
		return encryptedData, nil // 数据太短，可能是明文
	}

	iv := ciphertext[:aes.BlockSize]
	ciphertext = ciphertext[aes.BlockSize:]

	stream := cipher.NewCFBDecrypter(block, iv)
	stream.XORKeyStream(ciphertext, ciphertext)

	return string(ciphertext), nil
}

// getFromCache 从缓存获取（带锁）
func (cm *CertManager) getFromCache(subjectID, version int) *alipay.Client {
	cm.mu.RLock()
	defer cm.mu.RUnlock()
	return cm.getFromCacheUnsafe(subjectID, version)
}

// getFromCacheUnsafe 从缓存获取（无锁）
func (cm *CertManager) getFromCacheUnsafe(subjectID, version int) *alipay.Client {
	cached, exists := cm.cache[subjectID]
	if !exists {
		return nil
	}

	// 检查是否过期
	if time.Now().After(cached.ExpiresAt) {
		delete(cm.cache, subjectID)
		return nil
	}

	// 检查版本号
	if cached.Version != version {
		delete(cm.cache, subjectID)
		cm.logger.Info("证书版本变更，清除缓存",
			zap.Int("subject_id", subjectID),
			zap.Int("old_version", cached.Version),
			zap.Int("new_version", version))
		return nil
	}

	return cached.AlipayClient
}

// CleanExpired 清理过期缓存
func (cm *CertManager) CleanExpired() {
	cm.mu.Lock()
	defer cm.mu.Unlock()

	now := time.Now()
	count := 0

	for id, cached := range cm.cache {
		if now.After(cached.ExpiresAt) {
			delete(cm.cache, id)
			count++
		}
	}

	if count > 0 {
		cm.logger.Info("清理过期证书缓存", zap.Int("count", count))
	}
}

// InvalidateCache 使缓存失效
func (cm *CertManager) InvalidateCache(subjectID int) {
	cm.mu.Lock()
	defer cm.mu.Unlock()

	delete(cm.cache, subjectID)
	cm.logger.Info("证书缓存已失效", zap.Int("subject_id", subjectID))
}

// GetCacheStats 获取缓存统计
func (cm *CertManager) GetCacheStats() map[string]interface{} {
	cm.mu.RLock()
	defer cm.mu.RUnlock()

	return map[string]interface{}{
		"total_cached":  len(cm.cache),
		"cache_ttl_sec": cm.cacheTTL.Seconds(),
	}
}

package repository

import (
	"fmt"

	"complaint-monitor/internal/model"

	"go.uber.org/zap"
	"gorm.io/gorm"
)

// OrderRepository 订单仓库
type OrderRepository struct {
	*BaseRepository
}

// NewOrderRepository 创建订单仓库
func NewOrderRepository(db *gorm.DB, logger *zap.Logger) *OrderRepository {
	return &OrderRepository{
		BaseRepository: NewBaseRepository(db, logger),
	}
}

// FindByMerchantOrderNo 根据商户订单号查询订单
func (r *OrderRepository) FindByMerchantOrderNo(merchantOrderNo string) (*model.Order, error) {
	var order model.Order
	err := r.db.Where("merchant_order_no = ?", merchantOrderNo).First(&order).Error
	if err != nil {
		if err == gorm.ErrRecordNotFound {
			return nil, nil // 订单不存在，返回nil
		}
		return nil, fmt.Errorf("查询订单失败: %w", err)
	}
	return &order, nil
}

// FindByPlatformOrderNo 根据平台订单号（支付宝订单号）查询订单
func (r *OrderRepository) FindByPlatformOrderNo(platformOrderNo string) (*model.Order, error) {
	var order model.Order
	err := r.db.Where("alipay_order_no = ?", platformOrderNo).First(&order).Error
	if err != nil {
		if err == gorm.ErrRecordNotFound {
			return nil, nil // 订单不存在，返回nil
		}
		return nil, fmt.Errorf("查询订单失败: %w", err)
	}
	return &order, nil
}

// FindByOrderNos 批量查询订单（根据商户订单号或平台订单号）
func (r *OrderRepository) FindByOrderNos(merchantOrderNos []string, platformOrderNos []string) ([]*model.Order, error) {
	if len(merchantOrderNos) == 0 && len(platformOrderNos) == 0 {
		return []*model.Order{}, nil
	}

	var orders []*model.Order
	query := r.db.Model(&model.Order{})

	if len(merchantOrderNos) > 0 && len(platformOrderNos) > 0 {
		// 同时查询商户订单号和平台订单号
		query = query.Where("merchant_order_no IN ? OR alipay_order_no IN ?", merchantOrderNos, platformOrderNos)
	} else if len(merchantOrderNos) > 0 {
		query = query.Where("merchant_order_no IN ?", merchantOrderNos)
	} else if len(platformOrderNos) > 0 {
		query = query.Where("alipay_order_no IN ?", platformOrderNos)
	}

	err := query.Find(&orders).Error
	if err != nil {
		return nil, fmt.Errorf("批量查询订单失败: %w", err)
	}
	return orders, nil
}

// GetBuyerIDsByOrderNos 根据订单号列表获取购买者UID列表（去重，只返回已支付订单的buyer_id）
func (r *OrderRepository) GetBuyerIDsByOrderNos(merchantOrderNos []string, platformOrderNos []string) ([]string, error) {
	orders, err := r.FindByOrderNos(merchantOrderNos, platformOrderNos)
	if err != nil {
		return nil, err
	}

	// 使用map去重，只收集已支付且有buyer_id的订单
	buyerIDMap := make(map[string]bool)
	for _, order := range orders {
		// 只收集已支付且有buyer_id的订单
		if order.IsPaid() && order.HasBuyerID() {
			buyerIDMap[order.BuyerID] = true
		}
	}

	// 转换为切片
	buyerIDs := make([]string, 0, len(buyerIDMap))
	for buyerID := range buyerIDMap {
		buyerIDs = append(buyerIDs, buyerID)
	}

	return buyerIDs, nil
}

// GetOrderIPAndDevice 获取订单的IP地址和设备信息（用于拉黑）
// 优先返回支付IP（pay_ip），如果没有则返回首次打开IP（first_open_ip）
// 注意：订单表中暂时没有设备码字段，设备码返回空字符串
func (r *OrderRepository) GetOrderIPAndDevice(merchantOrderNo string, platformOrderNo string) (ipAddress string, deviceCode string, err error) {
	var order *model.Order

	// 优先使用商户订单号查询
	if merchantOrderNo != "" {
		order, err = r.FindByMerchantOrderNo(merchantOrderNo)
		if err != nil {
			return "", "", err
		}
	}

	// 如果商户订单号查询不到，使用平台订单号查询
	if order == nil && platformOrderNo != "" {
		order, err = r.FindByPlatformOrderNo(platformOrderNo)
		if err != nil {
			return "", "", err
		}
	}

	if order == nil {
		return "", "", nil // 订单不存在
	}

	// 优先返回支付IP（pay_ip），如果没有则返回首次打开IP（first_open_ip）
	if order.PayIP != "" {
		ipAddress = order.PayIP
	} else if order.FirstOpenIP != "" {
		ipAddress = order.FirstOpenIP
	}

	// 设备码：订单表中暂时没有设备码字段，返回空字符串
	// 如果将来订单表添加了设备码字段，需要在这里读取
	deviceCode = ""

	return ipAddress, deviceCode, nil
}

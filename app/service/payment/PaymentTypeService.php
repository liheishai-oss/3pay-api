<?php

namespace app\service\payment;

use app\model\PaymentType;
use app\model\Product;
use Exception;
use support\Log;

/**
 * 支付类型管理服务
 */
class PaymentTypeService
{
    /**
     * 获取所有启用的支付类型
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getEnabledPaymentTypes()
    {
        return PaymentType::getEnabled()->get();
    }

    /**
     * 根据产品代码获取支付类型
     * @param string $productCode 产品代码
     * @return PaymentType|null
     */
    public static function getPaymentTypeByCode(string $productCode): ?PaymentType
    {
        return PaymentType::getByProductCode($productCode);
    }

    /**
     * 根据产品编号获取支付类型
     * @param string $productCode 产品编号（4位数字）
     * @param int $agentId 代理商ID
     * @return PaymentType|null
     */
    public static function getPaymentTypeByProductCode(string $productCode, int $agentId): ?PaymentType
    {
        $product = Product::where('product_code', $productCode)
            ->where('agent_id', $agentId)
            ->where('status', Product::STATUS_ENABLED)
            ->with('paymentType')
            ->first();

        return $product ? $product->paymentType : null;
    }

    /**
     * 验证支付类型是否可用
     * @param string $productCode 产品编号
     * @param int $agentId 代理商ID
     * @return bool
     */
    public static function isPaymentTypeAvailable(string $productCode, int $agentId): bool
    {
        $paymentType = self::getPaymentTypeByProductCode($productCode, $agentId);
        return $paymentType && $paymentType->status === PaymentType::STATUS_ENABLED;
    }

    /**
     * 获取支付类型支持的支付方式
     * @param string $productCode 产品编号
     * @param int $agentId 代理商ID
     * @return array 支持的支付方式列表
     */
    public static function getSupportedPaymentMethods(string $productCode, int $agentId): array
    {
        $paymentType = self::getPaymentTypeByProductCode($productCode, $agentId);
        
        if (!$paymentType) {
            return [];
        }

        // 根据支付类型返回支持的支付方式
        switch ($paymentType->product_code) {
            case 'alipay_wap':
                return ['wap'];
            case 'alipay_app':
                return ['app'];
            case 'alipay_page':
                return ['page'];
            case 'alipay_qr':
                return ['qr'];
            case 'alipay_bar':
                return ['bar'];
            case 'alipay_preauth':
                return ['preauth'];
            default:
                return [];
        }
    }

    /**
     * 获取支付类型信息
     * @param string $productCode 产品编号
     * @param int $agentId 代理商ID
     * @return array 支付类型信息
     */
    public static function getPaymentTypeInfo(string $productCode, int $agentId): array
    {
        $paymentType = self::getPaymentTypeByProductCode($productCode, $agentId);
        
        if (!$paymentType) {
            return [];
        }

        return [
            'id' => $paymentType->id,
            'product_name' => $paymentType->product_name,
            'product_code' => $paymentType->product_code,
            'class_name' => $paymentType->class_name,
            'description' => $paymentType->description,
            'status' => $paymentType->status,
            'sort_order' => $paymentType->sort_order,
            'supported_methods' => self::getSupportedPaymentMethods($productCode, $agentId),
        ];
    }

    /**
     * 创建支付类型
     * @param array $data 支付类型数据
     * @return PaymentType
     * @throws Exception
     */
    public static function createPaymentType(array $data): PaymentType
    {
        try {
            // 验证必填字段
            $requiredFields = ['product_name', 'product_code', 'class_name'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    throw new Exception("字段 {$field} 不能为空");
                }
            }

            // 检查产品代码是否已存在
            if (PaymentType::where('product_code', $data['product_code'])->exists()) {
                throw new Exception("产品代码已存在: {$data['product_code']}");
            }

            // 创建支付类型
            $paymentType = PaymentType::create([
                'product_name' => $data['product_name'],
                'product_code' => $data['product_code'],
                'class_name' => $data['class_name'],
                'description' => $data['description'] ?? '',
                'status' => $data['status'] ?? PaymentType::STATUS_ENABLED,
                'sort_order' => $data['sort_order'] ?? 0,
            ]);

            Log::info("支付类型创建成功", [
                'id' => $paymentType->id,
                'product_code' => $paymentType->product_code,
                'product_name' => $paymentType->product_name
            ]);

            return $paymentType;

        } catch (Exception $e) {
            Log::error("支付类型创建失败", [
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * 更新支付类型
     * @param int $id 支付类型ID
     * @param array $data 更新数据
     * @return bool
     * @throws Exception
     */
    public static function updatePaymentType(int $id, array $data): bool
    {
        try {
            $paymentType = PaymentType::find($id);
            if (!$paymentType) {
                throw new Exception("支付类型不存在");
            }

            // 如果更新产品代码，检查是否重复
            if (isset($data['product_code']) && $data['product_code'] !== $paymentType->product_code) {
                if (PaymentType::where('product_code', $data['product_code'])
                    ->where('id', '!=', $id)
                    ->exists()) {
                    throw new Exception("产品代码已存在: {$data['product_code']}");
                }
            }

            $result = $paymentType->update($data);

            if ($result) {
                Log::info("支付类型更新成功", [
                    'id' => $id,
                    'updated_fields' => array_keys($data)
                ]);
            }

            return $result;

        } catch (Exception $e) {
            Log::error("支付类型更新失败", [
                'id' => $id,
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * 删除支付类型
     * @param int $id 支付类型ID
     * @return bool
     * @throws Exception
     */
    public static function deletePaymentType(int $id): bool
    {
        try {
            $paymentType = PaymentType::find($id);
            if (!$paymentType) {
                throw new Exception("支付类型不存在");
            }

            // 检查是否有产品在使用此支付类型
            $productCount = Product::where('payment_type_id', $id)->count();
            if ($productCount > 0) {
                throw new Exception("无法删除，有 {$productCount} 个产品正在使用此支付类型");
            }

            $result = $paymentType->delete();

            if ($result) {
                Log::info("支付类型删除成功", [
                    'id' => $id,
                    'product_code' => $paymentType->product_code
                ]);
            }

            return $result;

        } catch (Exception $e) {
            Log::error("支付类型删除失败", [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * 切换支付类型状态
     * @param int $id 支付类型ID
     * @return bool
     * @throws Exception
     */
    public static function togglePaymentTypeStatus(int $id): bool
    {
        try {
            $paymentType = PaymentType::find($id);
            if (!$paymentType) {
                throw new Exception("支付类型不存在");
            }

            $result = $paymentType->toggleStatus();

            if ($result) {
                Log::info("支付类型状态切换成功", [
                    'id' => $id,
                    'new_status' => $paymentType->status
                ]);
            }

            return $result;

        } catch (Exception $e) {
            Log::error("支付类型状态切换失败", [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * 获取支付类型统计信息
     * @return array 统计信息
     */
    public static function getPaymentTypeStats(): array
    {
        $total = PaymentType::count();
        $enabled = PaymentType::where('status', PaymentType::STATUS_ENABLED)->count();
        $disabled = $total - $enabled;

        // 按产品代码分组统计
        $byCode = PaymentType::selectRaw('product_code, COUNT(*) as count')
            ->groupBy('product_code')
            ->get()
            ->pluck('count', 'product_code')
            ->toArray();

        return [
            'total' => $total,
            'enabled' => $enabled,
            'disabled' => $disabled,
            'by_code' => $byCode,
        ];
    }
}

<?php

namespace app\admin\controller\v1;

use support\Request;
use support\Response;
use app\model\Subject;
use app\model\PaymentType;
use app\model\SubjectPaymentType;
use support\Log;
use Exception;

/**
 * 主体支付类型管理控制器
 */
class SubjectPaymentTypeController
{
    /**
     * 获取主体的支付类型列表
     * @param Request $request
     * @return Response
     */
    public function getSubjectPaymentTypes(Request $request): Response
    {
        try {
            $subjectId = $request->get('subject_id');
            
            if (empty($subjectId)) {
                return error('主体ID不能为空');
            }
            
            // 验证主体是否存在
            $subject = Subject::find($subjectId);
            if (!$subject) {
                return error('主体不存在');
            }
            
            // 获取所有启用的支付类型
            $allPaymentTypes = PaymentType::where('status', PaymentType::STATUS_ENABLED)
                ->orderBy('sort_order', 'asc')
                ->orderBy('id', 'asc')
                ->get();
            
            // 获取主体已绑定的支付类型
            $boundPaymentTypes = SubjectPaymentType::where('subject_id', $subjectId)
                ->where('status', SubjectPaymentType::STATUS_ENABLED)
                ->pluck('is_enabled', 'payment_type_id')
                ->toArray();
            
            // 合并数据，显示所有支付类型及其绑定状态
            $paymentTypes = $allPaymentTypes->map(function ($paymentType) use ($boundPaymentTypes, $subjectId) {
                $isBound = isset($boundPaymentTypes[$paymentType->id]);
                $isEnabled = $isBound && $boundPaymentTypes[$paymentType->id] == 1;
                
                return [
                    'id' => $paymentType->id,
                    'product_code' => $paymentType->product_code,
                    'product_name' => $paymentType->product_name,
                    'class_name' => $paymentType->class_name,
                    'description' => $paymentType->description,
                    'status' => $paymentType->status,
                    'sort_order' => $paymentType->sort_order,
                    'is_bound' => $isBound,
                    'is_enabled' => $isEnabled,
                    'bound_at' => $isBound ? SubjectPaymentType::where('subject_id', $subjectId)
                        ->where('payment_type_id', $paymentType->id)
                        ->value('created_at') : null
                ];
            });
            
            Log::info('获取主体支付类型列表', [
                'subject_id' => $subjectId,
                'total_payment_types' => $paymentTypes->count(),
                'bound_count' => count($boundPaymentTypes)
            ]);
            
            return success([
                'payment_types' => $paymentTypes,
                'subject_info' => [
                    'id' => $subject->id,
                    'company_name' => $subject->company_name,
                    'agent_id' => $subject->agent_id
                ]
            ]);
            
        } catch (Exception $e) {
            Log::error('获取主体支付类型列表失败', [
                'subject_id' => $request->get('subject_id'),
                'error' => $e->getMessage()
            ]);
            
            return error('获取支付类型列表失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 切换支付类型启用状态
     * @param Request $request
     * @return Response
     */
    public function togglePaymentType(Request $request): Response
    {
        try {
            $params = $request->all();
            
            if (empty($params['subject_id'])) {
                return error('主体ID不能为空');
            }
            
            if (empty($params['payment_type_id'])) {
                return error('支付类型ID不能为空');
            }
            
            // 验证主体是否存在
            $subject = Subject::find($params['subject_id']);
            if (!$subject) {
                return error('主体不存在');
            }
            
            // 验证支付类型是否存在且启用
            $paymentType = PaymentType::where('id', $params['payment_type_id'])
                ->where('status', PaymentType::STATUS_ENABLED)
                ->first();
            
            if (!$paymentType) {
                return error('支付类型不存在或已禁用');
            }
            
            // 查找或创建绑定记录
            $subjectPaymentType = SubjectPaymentType::where('subject_id', $params['subject_id'])
                ->where('payment_type_id', $params['payment_type_id'])
                ->first();
            
            if (!$subjectPaymentType) {
                // 创建新绑定记录
                $subjectPaymentType = SubjectPaymentType::create([
                    'subject_id' => $params['subject_id'],
                    'payment_type_id' => $params['payment_type_id'],
                    'status' => SubjectPaymentType::STATUS_ENABLED,
                    'is_enabled' => 1 // 默认启用
                ]);
            } else {
                // 切换启用状态
                $subjectPaymentType->is_enabled = $subjectPaymentType->is_enabled ? 0 : 1;
                $subjectPaymentType->save();
            }
            
            $action = $subjectPaymentType->is_enabled ? '启用' : '禁用';
            
            Log::info('支付类型状态切换成功', [
                'subject_id' => $params['subject_id'],
                'payment_type_id' => $params['payment_type_id'],
                'payment_type_code' => $paymentType->product_code,
                'action' => $action
            ]);
            
            return success([
                'is_enabled' => $subjectPaymentType->is_enabled,
                'action' => $action
            ], "支付类型{$action}成功");
            
        } catch (Exception $e) {
            Log::error('支付类型状态切换失败', [
                'params' => $request->all(),
                'error' => $e->getMessage()
            ]);
            
            return error('支付类型状态切换失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 绑定支付类型到主体
     * @param Request $request
     * @return Response
     */
    public function bindPaymentType(Request $request): Response
    {
        try {
            $params = $request->all();
            
            if (empty($params['subject_id'])) {
                return error('主体ID不能为空');
            }
            
            if (empty($params['payment_type_id'])) {
                return error('支付类型ID不能为空');
            }
            
            // 验证主体是否存在
            $subject = Subject::find($params['subject_id']);
            if (!$subject) {
                return error('主体不存在');
            }
            
            // 验证支付类型是否存在且启用
            $paymentType = PaymentType::where('id', $params['payment_type_id'])
                ->where('status', PaymentType::STATUS_ENABLED)
                ->first();
            
            if (!$paymentType) {
                return error('支付类型不存在或已禁用');
            }
            
            // 检查是否已经绑定
            $existing = SubjectPaymentType::where('subject_id', $params['subject_id'])
                ->where('payment_type_id', $params['payment_type_id'])
                ->first();
            
            if ($existing) {
                if ($existing->status == SubjectPaymentType::STATUS_ENABLED) {
                    return error('支付类型已经绑定到该主体');
                } else {
                    // 重新启用绑定
                    $existing->status = SubjectPaymentType::STATUS_ENABLED;
                    $existing->save();
                }
            } else {
                // 创建新绑定
                SubjectPaymentType::create([
                    'subject_id' => $params['subject_id'],
                    'payment_type_id' => $params['payment_type_id'],
                    'status' => SubjectPaymentType::STATUS_ENABLED
                ]);
            }
            
            Log::info('支付类型绑定成功', [
                'subject_id' => $params['subject_id'],
                'payment_type_id' => $params['payment_type_id'],
                'payment_type_code' => $paymentType->product_code
            ]);
            
            return success([], '支付类型绑定成功');
            
        } catch (Exception $e) {
            Log::error('支付类型绑定失败', [
                'params' => $request->all(),
                'error' => $e->getMessage()
            ]);
            
            return error('支付类型绑定失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 解绑支付类型
     * @param Request $request
     * @return Response
     */
    public function unbindPaymentType(Request $request): Response
    {
        try {
            $params = $request->all();
            
            if (empty($params['subject_id'])) {
                return error('主体ID不能为空');
            }
            
            if (empty($params['payment_type_id'])) {
                return error('支付类型ID不能为空');
            }
            
            // 检查绑定是否存在
            $subjectPaymentType = SubjectPaymentType::where('subject_id', $params['subject_id'])
                ->where('payment_type_id', $params['payment_type_id'])
                ->first();
            
            if (!$subjectPaymentType) {
                return error('支付类型未绑定到该主体');
            }
            
            // 软删除（设置为禁用状态）
            $subjectPaymentType->status = SubjectPaymentType::STATUS_DISABLED;
            $subjectPaymentType->save();
            
            Log::info('支付类型解绑成功', [
                'subject_id' => $params['subject_id'],
                'payment_type_id' => $params['payment_type_id']
            ]);
            
            return success([], '支付类型解绑成功');
            
        } catch (Exception $e) {
            Log::error('支付类型解绑失败', [
                'params' => $request->all(),
                'error' => $e->getMessage()
            ]);
            
            return error('支付类型解绑失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 批量绑定支付类型
     * @param Request $request
     * @return Response
     */
    public function batchBindPaymentTypes(Request $request): Response
    {
        try {
            $params = $request->all();
            
            if (empty($params['subject_id'])) {
                return error('主体ID不能为空');
            }
            
            if (empty($params['payment_type_ids']) || !is_array($params['payment_type_ids'])) {
                return error('支付类型ID列表不能为空');
            }
            
            // 验证主体是否存在
            $subject = Subject::find($params['subject_id']);
            if (!$subject) {
                return error('主体不存在');
            }
            
            $successCount = 0;
            $errorCount = 0;
            $errors = [];
            
            foreach ($params['payment_type_ids'] as $paymentTypeId) {
                try {
                    // 验证支付类型是否存在且启用
                    $paymentType = PaymentType::where('id', $paymentTypeId)
                        ->where('status', PaymentType::STATUS_ENABLED)
                        ->first();
                    
                    if (!$paymentType) {
                        $errors[] = "支付类型ID {$paymentTypeId} 不存在或已禁用";
                        $errorCount++;
                        continue;
                    }
                    
                    // 检查是否已经绑定
                    $existing = SubjectPaymentType::where('subject_id', $params['subject_id'])
                        ->where('payment_type_id', $paymentTypeId)
                        ->first();
                    
                    if ($existing && $existing->status == SubjectPaymentType::STATUS_ENABLED) {
                        continue; // 已经绑定，跳过
                    }
                    
                    if ($existing) {
                        // 重新启用绑定
                        $existing->status = SubjectPaymentType::STATUS_ENABLED;
                        $existing->save();
                    } else {
                        // 创建新绑定
                        SubjectPaymentType::create([
                            'subject_id' => $params['subject_id'],
                            'payment_type_id' => $paymentTypeId,
                            'status' => SubjectPaymentType::STATUS_ENABLED
                        ]);
                    }
                    
                    $successCount++;
                    
                } catch (Exception $e) {
                    $errors[] = "支付类型ID {$paymentTypeId} 绑定失败: " . $e->getMessage();
                    $errorCount++;
                }
            }
            
            Log::info('批量绑定支付类型完成', [
                'subject_id' => $params['subject_id'],
                'total' => count($params['payment_type_ids']),
                'success_count' => $successCount,
                'error_count' => $errorCount
            ]);
            
            $message = "批量绑定完成：成功 {$successCount} 个，失败 {$errorCount} 个";
            if (!empty($errors)) {
                $message .= "。错误详情：" . implode('; ', $errors);
            }
            
            return success([
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'errors' => $errors
            ], $message);
            
        } catch (Exception $e) {
            Log::error('批量绑定支付类型失败', [
                'params' => $request->all(),
                'error' => $e->getMessage()
            ]);
            
            return error('批量绑定失败: ' . $e->getMessage());
        }
    }
}

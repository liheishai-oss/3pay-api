<?php

namespace app\admin\controller\v1;

use support\Request;
use support\Response;
use app\model\Subject;
use app\model\Product;
use app\model\SubjectProduct;
use support\Log;
use Exception;

/**
 * 主体产品管理控制器
 */
class SubjectProductController
{
    /**
     * 获取主体的产品列表
     * @param Request $request
     * @return Response
     */
    public function getSubjectProducts(Request $request): Response
    {
        try {
            $subjectId = $request->get('subject_id');
            
            if (empty($subjectId)) {
                return $this->error('主体ID不能为空');
            }
            
            // 验证主体是否存在
            $subject = Subject::find($subjectId);
            if (!$subject) {
                return $this->error('主体不存在');
            }
            
            // 获取已绑定的产品
            $boundProducts = SubjectProduct::with('product')
                ->where('subject_id', $subjectId)
                ->where('status', SubjectProduct::STATUS_ENABLED)
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->product->id,
                        'product_code' => $item->product->product_code,
                        'product_name' => $item->product->product_name,
                        'payment_type_name' => $item->product->paymentType->name ?? '',
                        'status' => $item->product->status,
                        'bound_at' => $item->created_at->format('Y-m-d H:i:s')
                    ];
                });
            
            // 获取可绑定的产品（同代理商下的产品）
            $availableProducts = Product::with('paymentType')
                ->where('agent_id', $subject->agent_id)
                ->where('status', Product::STATUS_ENABLED)
                ->whereNotIn('id', $boundProducts->pluck('id'))
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'product_code' => $item->product_code,
                        'product_name' => $item->product_name,
                        'payment_type_name' => $item->paymentType->name ?? '',
                        'status' => $item->status
                    ];
                });
            
            Log::info('获取主体产品列表', [
                'subject_id' => $subjectId,
                'bound_count' => $boundProducts->count(),
                'available_count' => $availableProducts->count()
            ]);
            
            return $this->success([
                'bound_products' => $boundProducts,
                'available_products' => $availableProducts,
                'subject_info' => [
                    'id' => $subject->id,
                    'company_name' => $subject->company_name,
                    'agent_id' => $subject->agent_id
                ]
            ]);
            
        } catch (Exception $e) {
            Log::error('获取主体产品列表失败', [
                'subject_id' => $request->get('subject_id'),
                'error' => $e->getMessage()
            ]);
            
            return $this->error('获取产品列表失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 绑定产品到主体
     * @param Request $request
     * @return Response
     */
    public function bindProduct(Request $request): Response
    {
        try {
            $params = $request->all();
            
            if (empty($params['subject_id'])) {
                return $this->error('主体ID不能为空');
            }
            
            if (empty($params['product_id'])) {
                return $this->error('产品ID不能为空');
            }
            
            // 验证主体是否存在
            $subject = Subject::find($params['subject_id']);
            if (!$subject) {
                return $this->error('主体不存在');
            }
            
            // 验证产品是否存在且属于同一代理商
            $product = Product::where('id', $params['product_id'])
                ->where('agent_id', $subject->agent_id)
                ->where('status', Product::STATUS_ENABLED)
                ->first();
            
            if (!$product) {
                return $this->error('产品不存在或不属于同一代理商');
            }
            
            // 检查是否已经绑定
            $existing = SubjectProduct::where('subject_id', $params['subject_id'])
                ->where('product_id', $params['product_id'])
                ->first();
            
            if ($existing) {
                if ($existing->status == SubjectProduct::STATUS_ENABLED) {
                    return $this->error('产品已经绑定到该主体');
                } else {
                    // 重新启用绑定
                    $existing->status = SubjectProduct::STATUS_ENABLED;
                    $existing->save();
                }
            } else {
                // 创建新绑定
                SubjectProduct::create([
                    'subject_id' => $params['subject_id'],
                    'product_id' => $params['product_id'],
                    'status' => SubjectProduct::STATUS_ENABLED
                ]);
            }
            
            Log::info('产品绑定成功', [
                'subject_id' => $params['subject_id'],
                'product_id' => $params['product_id'],
                'product_code' => $product->product_code
            ]);
            
            return $this->success([], '产品绑定成功');
            
        } catch (Exception $e) {
            Log::error('产品绑定失败', [
                'params' => $request->all(),
                'error' => $e->getMessage()
            ]);
            
            return $this->error('产品绑定失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 解绑产品
     * @param Request $request
     * @return Response
     */
    public function unbindProduct(Request $request): Response
    {
        try {
            $params = $request->all();
            
            if (empty($params['subject_id'])) {
                return $this->error('主体ID不能为空');
            }
            
            if (empty($params['product_id'])) {
                return $this->error('产品ID不能为空');
            }
            
            // 检查绑定是否存在
            $subjectProduct = SubjectProduct::where('subject_id', $params['subject_id'])
                ->where('product_id', $params['product_id'])
                ->first();
            
            if (!$subjectProduct) {
                return $this->error('产品未绑定到该主体');
            }
            
            // 软删除（设置为禁用状态）
            $subjectProduct->status = SubjectProduct::STATUS_DISABLED;
            $subjectProduct->save();
            
            Log::info('产品解绑成功', [
                'subject_id' => $params['subject_id'],
                'product_id' => $params['product_id']
            ]);
            
            return $this->success([], '产品解绑成功');
            
        } catch (Exception $e) {
            Log::error('产品解绑失败', [
                'params' => $request->all(),
                'error' => $e->getMessage()
            ]);
            
            return $this->error('产品解绑失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 批量绑定产品
     * @param Request $request
     * @return Response
     */
    public function batchBindProducts(Request $request): Response
    {
        try {
            $params = $request->all();
            
            if (empty($params['subject_id'])) {
                return $this->error('主体ID不能为空');
            }
            
            if (empty($params['product_ids']) || !is_array($params['product_ids'])) {
                return $this->error('产品ID列表不能为空');
            }
            
            // 验证主体是否存在
            $subject = Subject::find($params['subject_id']);
            if (!$subject) {
                return $this->error('主体不存在');
            }
            
            $successCount = 0;
            $errorCount = 0;
            $errors = [];
            
            foreach ($params['product_ids'] as $productId) {
                try {
                    // 验证产品是否存在且属于同一代理商
                    $product = Product::where('id', $productId)
                        ->where('agent_id', $subject->agent_id)
                        ->where('status', Product::STATUS_ENABLED)
                        ->first();
                    
                    if (!$product) {
                        $errors[] = "产品ID {$productId} 不存在或不属于同一代理商";
                        $errorCount++;
                        continue;
                    }
                    
                    // 检查是否已经绑定
                    $existing = SubjectProduct::where('subject_id', $params['subject_id'])
                        ->where('product_id', $productId)
                        ->first();
                    
                    if ($existing && $existing->status == SubjectProduct::STATUS_ENABLED) {
                        continue; // 已经绑定，跳过
                    }
                    
                    if ($existing) {
                        // 重新启用绑定
                        $existing->status = SubjectProduct::STATUS_ENABLED;
                        $existing->save();
                    } else {
                        // 创建新绑定
                        SubjectProduct::create([
                            'subject_id' => $params['subject_id'],
                            'product_id' => $productId,
                            'status' => SubjectProduct::STATUS_ENABLED
                        ]);
                    }
                    
                    $successCount++;
                    
                } catch (Exception $e) {
                    $errors[] = "产品ID {$productId} 绑定失败: " . $e->getMessage();
                    $errorCount++;
                }
            }
            
            Log::info('批量绑定产品完成', [
                'subject_id' => $params['subject_id'],
                'total' => count($params['product_ids']),
                'success_count' => $successCount,
                'error_count' => $errorCount
            ]);
            
            $message = "批量绑定完成：成功 {$successCount} 个，失败 {$errorCount} 个";
            if (!empty($errors)) {
                $message .= "。错误详情：" . implode('; ', $errors);
            }
            
            return $this->success([
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'errors' => $errors
            ], $message);
            
        } catch (Exception $e) {
            Log::error('批量绑定产品失败', [
                'params' => $request->all(),
                'error' => $e->getMessage()
            ]);
            
            return $this->error('批量绑定失败: ' . $e->getMessage());
        }
    }
}

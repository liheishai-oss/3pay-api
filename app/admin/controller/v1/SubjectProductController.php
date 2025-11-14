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
                return error('主体ID不能为空');
            }
            
            // 验证主体是否存在
            $subject = Subject::find($subjectId);
            if (!$subject) {
                return error('主体不存在');
            }
            
            // 获取当前用户的代理商信息
            $userData = $request->userData ?? [];
            $isAgent = ($userData['user_group_id'] ?? 0) == 3;
            $currentAgentId = $userData['agent_id'] ?? null;
            
            // 确定要使用的代理商ID
            // 如果是代理商，使用当前用户的agent_id；如果是管理员，使用主体的agent_id
            $targetAgentId = $isAgent ? $currentAgentId : $subject->agent_id;
            
            // 获取已绑定的产品（包括禁用的）
            $boundProducts = SubjectProduct::with(['product.paymentType'])
                ->where('subject_id', $subjectId)
                ->get()
                ->filter(function ($item) {
                    return $item->product !== null; // 过滤掉产品已被删除的记录
                })
                ->map(function ($item) {
                    return [
                        'id' => $item->product->id,
                        'product_code' => $item->product->product_code,
                        'product_name' => $item->product->product_name,
                        'payment_type_name' => $item->product->paymentType->name ?? $item->product->paymentType->product_name ?? '',
                        'status' => $item->product->status,
                        'is_enabled' => $item->status == SubjectProduct::STATUS_ENABLED ? 1 : 0,
                        'bound_at' => $item->created_at ? (is_string($item->created_at) ? $item->created_at : $item->created_at->format('Y-m-d H:i:s')) : ''
                    ];
                });
            
            // 获取可绑定的产品（当前代理商下的产品，排除已绑定的）
            $boundProductIds = $boundProducts->pluck('id')->toArray();
            $availableProductsQuery = Product::with('paymentType')
                ->where('status', Product::STATUS_ENABLED)
                ->whereNotIn('id', $boundProductIds);
            
            // 如果确定了代理商ID，则只显示该代理商的产品
            if ($targetAgentId) {
                $availableProductsQuery->where('agent_id', $targetAgentId);
            }
            
            $availableProducts = $availableProductsQuery->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'product_code' => $item->product_code,
                        'product_name' => $item->product_name,
                        'payment_type_name' => $item->paymentType->name ?? $item->paymentType->product_name ?? '',
                        'status' => $item->status
                    ];
                });
            
            Log::info('获取主体产品列表', [
                'subject_id' => $subjectId,
                'bound_count' => $boundProducts->count(),
                'available_count' => $availableProducts->count()
            ]);
            
            return success([
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
            
            return error('获取产品列表失败: ' . $e->getMessage());
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
                return error('主体ID不能为空');
            }
            
            if (empty($params['product_id'])) {
                return error('产品ID不能为空');
            }
            
            // 验证主体是否存在
            $subject = Subject::find($params['subject_id']);
            if (!$subject) {
                return error('主体不存在');
            }
            
            // 获取当前用户的代理商信息
            $userData = $request->userData ?? [];
            $isAgent = ($userData['user_group_id'] ?? 0) == 3;
            $currentAgentId = $userData['agent_id'] ?? null;
            
            // 确定要使用的代理商ID
            $targetAgentId = $isAgent ? $currentAgentId : $subject->agent_id;
            
            // 验证产品是否存在且启用，且属于当前代理商
            $productQuery = Product::where('id', $params['product_id'])
                ->where('status', Product::STATUS_ENABLED);
            
            if ($targetAgentId) {
                $productQuery->where('agent_id', $targetAgentId);
            }
            
            $product = $productQuery->first();
            
            if (!$product) {
                return error('产品不存在、已禁用或不属于当前代理商');
            }
            
            // 检查是否已经绑定
            $existing = SubjectProduct::where('subject_id', $params['subject_id'])
                ->where('product_id', $params['product_id'])
                ->first();
            
            if ($existing) {
                if ($existing->status == SubjectProduct::STATUS_ENABLED) {
                    return error('产品已经绑定到该主体');
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
            
            return success([], '产品绑定成功');
            
        } catch (Exception $e) {
            Log::error('产品绑定失败', [
                'params' => $request->all(),
                'error' => $e->getMessage()
            ]);
            
            return error('产品绑定失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 切换产品启用状态
     * @param Request $request
     * @return Response
     */
    public function toggleProduct(Request $request): Response
    {
        try {
            $params = $request->all();
            
            if (empty($params['subject_id'])) {
                return error('主体ID不能为空');
            }
            
            if (empty($params['product_id'])) {
                return error('产品ID不能为空');
            }
            
            // 验证主体是否存在
            $subject = Subject::find($params['subject_id']);
            if (!$subject) {
                return error('主体不存在');
            }
            
            // 获取当前用户的代理商信息
            $userData = $request->userData ?? [];
            $isAgent = ($userData['user_group_id'] ?? 0) == 3;
            $currentAgentId = $userData['agent_id'] ?? null;
            
            // 确定要使用的代理商ID
            $targetAgentId = $isAgent ? $currentAgentId : $subject->agent_id;
            
            // 验证产品是否存在且启用，且属于当前代理商
            $productQuery = Product::where('id', $params['product_id'])
                ->where('status', Product::STATUS_ENABLED);
            
            if ($targetAgentId) {
                $productQuery->where('agent_id', $targetAgentId);
            }
            
            $product = $productQuery->first();
            
            if (!$product) {
                return error('产品不存在、已禁用或不属于当前代理商');
            }
            
            // 查找或创建绑定记录
            $subjectProduct = SubjectProduct::where('subject_id', $params['subject_id'])
                ->where('product_id', $params['product_id'])
                ->first();
            
            if (!$subjectProduct) {
                // 创建新绑定记录
                $subjectProduct = SubjectProduct::create([
                    'subject_id' => $params['subject_id'],
                    'product_id' => $params['product_id'],
                    'status' => SubjectProduct::STATUS_ENABLED
                ]);
            } else {
                // 切换启用状态
                $subjectProduct->status = $subjectProduct->status == SubjectProduct::STATUS_ENABLED 
                    ? SubjectProduct::STATUS_DISABLED 
                    : SubjectProduct::STATUS_ENABLED;
                $subjectProduct->save();
            }
            
            $action = $subjectProduct->status == SubjectProduct::STATUS_ENABLED ? '启用' : '禁用';
            
            Log::info('产品状态切换成功', [
                'subject_id' => $params['subject_id'],
                'product_id' => $params['product_id'],
                'product_code' => $product->product_code,
                'action' => $action
            ]);
            
            return success([
                'is_enabled' => $subjectProduct->status == SubjectProduct::STATUS_ENABLED ? 1 : 0,
                'action' => $action
            ], "产品{$action}成功");
            
        } catch (Exception $e) {
            Log::error('产品状态切换失败', [
                'params' => $request->all(),
                'error' => $e->getMessage()
            ]);
            
            return error('产品状态切换失败: ' . $e->getMessage());
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
                return error('主体ID不能为空');
            }
            
            if (empty($params['product_id'])) {
                return error('产品ID不能为空');
            }
            
            // 检查绑定是否存在
            $subjectProduct = SubjectProduct::where('subject_id', $params['subject_id'])
                ->where('product_id', $params['product_id'])
                ->first();
            
            if (!$subjectProduct) {
                return error('产品未绑定到该主体');
            }
            
            // 软删除（设置为禁用状态）
            $subjectProduct->status = SubjectProduct::STATUS_DISABLED;
            $subjectProduct->save();
            
            Log::info('产品解绑成功', [
                'subject_id' => $params['subject_id'],
                'product_id' => $params['product_id']
            ]);
            
            return success([], '产品解绑成功');
            
        } catch (Exception $e) {
            Log::error('产品解绑失败', [
                'params' => $request->all(),
                'error' => $e->getMessage()
            ]);
            
            return error('产品解绑失败: ' . $e->getMessage());
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
                return error('主体ID不能为空');
            }
            
            if (empty($params['product_ids']) || !is_array($params['product_ids'])) {
                return error('产品ID列表不能为空');
            }
            
            // 验证主体是否存在
            $subject = Subject::find($params['subject_id']);
            if (!$subject) {
                return error('主体不存在');
            }
            
            $successCount = 0;
            $errorCount = 0;
            $errors = [];
            
            // 获取当前用户的代理商信息
            $userData = $request->userData ?? [];
            $isAgent = ($userData['user_group_id'] ?? 0) == 3;
            $currentAgentId = $userData['agent_id'] ?? null;
            
            // 确定要使用的代理商ID
            $targetAgentId = $isAgent ? $currentAgentId : $subject->agent_id;
            
            foreach ($params['product_ids'] as $productId) {
                try {
                    // 验证产品是否存在且启用，且属于当前代理商
                    $productQuery = Product::where('id', $productId)
                        ->where('status', Product::STATUS_ENABLED);
                    
                    if ($targetAgentId) {
                        $productQuery->where('agent_id', $targetAgentId);
                    }
                    
                    $product = $productQuery->first();
                    
                    if (!$product) {
                        $errors[] = "产品ID {$productId} 不存在、已禁用或不属于当前代理商";
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
            
            return success([
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'errors' => $errors
            ], $message);
            
        } catch (Exception $e) {
            Log::error('批量绑定产品失败', [
                'params' => $request->all(),
                'error' => $e->getMessage()
            ]);
            
            return error('批量绑定失败: ' . $e->getMessage());
        }
    }
}

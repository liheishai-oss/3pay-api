<?php

namespace app\admin\controller\v1;

use support\Request;
use support\Db;
use support\Log;
use app\model\Subject;
use app\service\alipay\AlipayComplaintService;

/**
 * 投诉管理控制器
 * 仅支持查询，不支持添加、编辑、删除
 */
class ComplaintController
{
    /**
     * 获取投诉列表
     */
    public function list(Request $request)
    {
        try {
            $userData = $request->userData;
            $isAgent = ($userData['user_group_id'] ?? 0) == 3;
            
            $page = $request->get('page', 1);
            $pageSize = $request->get('page_size', 10);
            
            // 获取搜索参数
            $subjectId = $request->get('subject_id', '');
            // $agentId = $request->get('agent_id', ''); // 暂时屏蔽
            $complaintNo = $request->get('complaint_no', '');
            $status = $request->get('status', '');
            $startTime = $request->get('start_time', '');
            $endTime = $request->get('end_time', '');
            
                // 构建查询，添加投诉金额统计（从详情表聚合）和已退款金额（从主表读取）
                $query = Db::table('alipay_complaint as c')
                    ->leftJoin('subject as s', 'c.subject_id', '=', 's.id')
                    ->leftJoin('agent as a', 'c.agent_id', '=', 'a.id')
                    ->leftJoin(
                        Db::raw('(SELECT 
                            complaint_id, 
                            SUM(complaint_amount) as total_complaint_amount
                        FROM alipay_complaint_detail 
                        GROUP BY complaint_id) as d'), 
                        'c.id', '=', 'd.complaint_id'
                    )
                    ->select([
                        'c.id',
                        'c.subject_id',
                        'c.agent_id',
                        'c.complaint_no',
                        'c.complainant_id',
                        'c.complaint_time',
                        'c.complaint_status',
                        'c.complaint_reason',
                        'c.refund_amount',
                        'c.gmt_create',
                        'c.gmt_modified',
                        'c.created_at',
                        'c.updated_at',
                        's.company_name as subject_name',
                        'a.agent_name',
                        Db::raw('IFNULL(d.total_complaint_amount, 0) as complaint_amount')
                    ]);
            
            // 暂时屏蔽所有条件进行调试
            // if ($isAgent) {
            //     $query->where('c.agent_id', $userData['agent_id']);
            // }
            
            // if (!empty($subjectId)) {
            //     $query->where('c.subject_id', $subjectId);
            // }
            
            // if (!empty($agentId)) {
            //     $query->where('c.agent_id', $agentId);
            // }
            
            // if (!empty($complaintNo)) {
            //     $query->where('c.complaint_no', 'like', "%{$complaintNo}%");
            // }
            
            // if ($status !== '') {
            //     $query->where('c.complaint_status', $status);
            // }
            
            // if (!empty($startTime)) {
            //     $query->where('c.complaint_time', '>=', $startTime);
            // }
            
            // if (!empty($endTime)) {
            //     $query->where('c.complaint_time', '<=', $endTime);
            // }
            
            // 获取总数
            $total = $query->count();
            
            // 获取分页数据
            $list = $query->orderBy('c.id', 'desc')
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get();
            
            // 列表查询日志
            \app\service\OrderLogService::log('', '', '', '投诉列表', 'INFO', '节点36-投诉列表', ['action'=>'投诉列表','params'=>$request->all()],$request->getRealIp(), $request->header('user-agent',''));

            return success([
                'data' => $list,
                'current_page' => (int)$page,
                'per_page' => (int)$pageSize,
                'total' => (int)$total
            ]);
            
        } catch (\Throwable $e) {
            Log::error('获取投诉列表失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return error('获取投诉列表失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取投诉详情
     */
    public function detail(Request $request)
    {
        try {
            $userData = $request->userData;
            $isAgent = ($userData['user_group_id'] ?? 0) == 3;
            
            $id = $request->get('id');
            
            if (empty($id)) {
                return error('投诉ID不能为空');
            }
            
            // 查询投诉主表
            $complaint = Db::table('alipay_complaint as c')
                ->leftJoin('subject as s', 'c.subject_id', '=', 's.id')
                ->leftJoin('agent as a', 'c.agent_id', '=', 'a.id')
                ->select([
                    'c.*',
                    's.company_name as subject_name',
                    'a.agent_name'
                ])
                ->where('c.id', $id)
                ->first();
            
            if (!$complaint) {
                \app\service\OrderLogService::log(
                    '', '', '',
                    '投诉处理', 'WARN', '节点33-投诉不存在',
                    ['action'=>'投诉查询','reason'=>'complaint not found','complaint_id'=>$id],
                    $request->getRealIp(), $request->header('user-agent','')
                );
                return error('投诉记录不存在');
            }
            
            // 权限检查：代理商只能查看自己的投诉
            if ($isAgent && isset($userData['agent_id'])) {
                // 如果投诉记录有agent_id，则必须匹配
                if ($complaint->agent_id && $complaint->agent_id != $userData['agent_id']) {
                    return error('无权查看该投诉');
                }
            }
            
            // 查询投诉详情（订单列表）
            $details = Db::table('alipay_complaint_detail as d')
                ->leftJoin('agent as a', 'd.agent_id', '=', 'a.id')
                ->select([
                    'd.*',
                    'a.agent_name'
                ])
                ->where('d.complaint_id', $complaint->id)
                ->orderBy('d.id', 'asc')
                ->get();
            
            $complaint->details = $details;
            
            // 详情日志
            \app\service\OrderLogService::log('', '', '', '投诉详情', 'INFO', '节点37-投诉详情', ['action'=>'投诉详情','id'=>$id],$request->getRealIp(), $request->header('user-agent',''));

            return success($complaint);
            
        } catch (\Throwable $e) {
            Log::error('获取投诉详情失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return error('获取投诉详情失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取主体列表（用于筛选下拉框）
     */
    public function subjectList(Request $request)
    {
        try {
            $userData = $request->userData;
            $isAgent = ($userData['user_group_id'] ?? 0) == 3;
            
            $query = Db::table('subject')
                ->select(['id', 'company_name as name'])
                ->where('status', 1);
            
            // 如果是代理商，只能查看自己的主体
            if ($isAgent) {
                $query->where('agent_id', $userData['agent_id']);
            }
            
            $list = $query->orderBy('id', 'asc')->get();
            
            return success($list);
            
        } catch (\Throwable $e) {
            Log::error('获取主体列表失败', [
                'error' => $e->getMessage()
            ]);
            return error('获取主体列表失败');
        }
    }

    /**
     * 获取投诉订单详情列表
     * @param Request $request
     * @return \support\Response
     */
    public function detailList(Request $request)
    {
        try {
            $userData = $request->userData;
            $isAgent = ($userData['user_group_id'] ?? 0) == 3;

            // 获取参数
            $complaintId = $request->input('complaint_id');
            if (empty($complaintId)) {
                return error('投诉ID不能为空');
            }

            // 查询投诉记录
            $complaint = Db::table('alipay_complaint')->where('id', $complaintId)->first();
            if (!$complaint) {
                return error('投诉记录不存在');
            }

            // 代理商权限验证
            if ($isAgent && isset($userData['agent_id'])) {
                if ($complaint->agent_id && $complaint->agent_id != $userData['agent_id']) {
                    return error('无权查看该投诉');
                }
            }

            // 查询投诉详情（订单列表）
            $details = Db::table('alipay_complaint_detail as d')
                ->leftJoin('order as o', function($join) {
                    $join->on('d.merchant_order_no', '=', 'o.merchant_order_no')
                         ->orOn('d.platform_order_no', '=', 'o.platform_order_no')
                         ->orOn('d.complaint_no', '=', 'o.merchant_order_no');
                })
                ->select([
                    'd.id',
                    'd.complaint_id',
                    'd.merchant_order_no',
                    'd.platform_order_no',
                    'd.complaint_no',
                    'd.order_amount',
                    'd.complaint_amount',
                    'd.created_at',
                    'd.updated_at',
                    'o.id as order_id',
                    'o.pay_status',
                    'o.order_amount as order_real_amount',
                    'o.pay_time',
                    'o.buyer_id'
                ])
                ->where('d.complaint_id', $complaintId)
                ->orderBy('d.id', 'asc')
                ->get();

            // 格式化数据
            $detailList = [];
            foreach ($details as $detail) {
                $detailList[] = [
                    'id' => $detail->id,
                    'complaint_id' => $detail->complaint_id,
                    'merchant_order_no' => $detail->merchant_order_no,
                    'platform_order_no' => $detail->platform_order_no,
                    'complaint_no' => $detail->complaint_no,
                    'order_amount' => $detail->order_amount,
                    'complaint_amount' => $detail->complaint_amount,
                    'order_id' => $detail->order_id,
                    'pay_status' => $detail->pay_status,
                    'order_real_amount' => $detail->order_real_amount,
                    'pay_time' => $detail->pay_time,
                    'buyer_id' => $detail->buyer_id,
                    'order_exists' => !empty($detail->order_id), // 订单是否存在
                    'created_at' => $detail->created_at,
                    'updated_at' => $detail->updated_at
                ];
            }

            return success([
                'complaint_id' => $complaintId,
                'details' => $detailList,
                'total' => count($detailList)
            ]);

        } catch (\Throwable $e) {
            Log::error('获取投诉订单详情失败', [
                'complaint_id' => $complaintId ?? 0,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return error('获取投诉订单详情失败: ' . $e->getMessage());
        }
    }

    /**
     * 处理投诉
     * @param Request $request
     * @return \support\Response
     */
    public function handle(Request $request)
    {
        try {
            $userData = $request->userData;
            $isAgent = ($userData['user_group_id'] ?? 0) == 3;

            // 获取参数
            $id = $request->input('id');
            $processCode = $request->input('process_code');
            $feedback = $request->input('feedback');
            $refundAmount = $request->input('refund_amount', 0);
            $detailIds = $request->input('detail_ids', []); // 选中的投诉详情ID列表

            // 验证参数
            if (empty($id)) {
                return error('投诉ID不能为空');
            }
            if (empty($processCode)) {
                return error('处理结果码不能为空');
            }
            // 反馈内容改为选填，不再验证

            // 处理后自动设置为处理完成状态
            $status = 'PROCESSED';

            // 查询投诉记录
            $complaint = Db::table('alipay_complaint')->where('id', $id)->first();
            if (!$complaint) {
                \app\service\OrderLogService::log(
                    '', '', '',
                    '投诉处理', 'WARN', '节点33-投诉不存在',
                    ['action'=>'投诉查询','reason'=>'complaint not found','complaint_id'=>$id],
                    $request->getRealIp(), $request->header('user-agent','')
                );
                return error('投诉记录不存在');
            }

            // 代理商权限验证
            if ($isAgent && isset($userData['agent_id'])) {
                // 如果投诉记录有agent_id，则必须匹配
                if ($complaint->agent_id && $complaint->agent_id != $userData['agent_id']) {
                    return error('无权处理该投诉');
                }
            }

            // 状态验证：只有待处理和处理中的投诉可以被处理
            $allowedCurrentStatus = ['WAIT_PROCESS', 'PROCESSING'];
            if (!in_array($complaint->complaint_status, $allowedCurrentStatus)) {
                return error('该投诉当前状态不允许处理');
            }

            // 使用投诉处理服务处理投诉（包含退款逻辑）
            try {
                $result = \app\service\ComplaintService::handleComplaint(
                    $id,
                    $processCode,
                    $feedback,
                    $refundAmount,
                    $userData['id'] ?? 0,
                    $detailIds // 传递选中的订单ID列表
                );

                if (!$result['success']) {
                    \app\service\OrderLogService::log(
                        '', '', '',
                        '投诉处理', 'ERROR', '节点34-投诉处理失败',
                        ['action'=>'handle投诉','reason'=>$result['message'],'complaint_id'=>$id],
                        $request->getRealIp(), $request->header('user-agent','')
                    );
                    return error($result['message']);
                }

                \app\service\OrderLogService::log(
                    '', '', '',
                    '投诉处理', 'INFO', '节点35-投诉处理成功',
                    ['action'=>'handle投诉','process_code'=>$processCode,'complaint_id'=>$id,'status'=>$status,'handler_id'=>$userData['id']??0,'refund_amount'=>$refundAmount,'query_results'=>$result['query_results']??null,'refund_result'=>$result['refund_result']??null],
                    $request->getRealIp(), $request->header('user-agent','')
                );

                // 构建返回结果
                $responseData = [
                    'id' => $id,
                    'message' => $result['message'] ?? '处理成功',
                    'query_results' => $result['query_results'] ?? null,
                    'refund_result' => $result['refund_result'] ?? null,
                    'finish_complaint_result' => $result['finish_complaint_result'] ?? null,
                    'status_updated' => $result['status_updated'] ?? false
                ];
                
                // 如果有退款结果，提取关键信息
                if (!empty($result['refund_result'])) {
                    $refundResult = $result['refund_result'];
                    $responseData['refund_info'] = [
                        'success' => $refundResult['success'] ?? false,
                        'skipped' => $refundResult['skipped'] ?? false,
                        'refund_amount' => $refundResult['refund_amount'] ?? 0,
                        'message' => $refundResult['message'] ?? ''
                    ];
                    
                    // 如果有完结投诉结果
                    if (!empty($refundResult['finish_complaint'])) {
                        $finishComplaint = $refundResult['finish_complaint'];
                        $responseData['finish_complaint_info'] = [
                            'success' => $finishComplaint['success'] ?? false,
                            'error' => $finishComplaint['error'] ?? null,
                            'error_details' => $finishComplaint['error_details'] ?? null,
                            'result' => $finishComplaint['result'] ?? null
                        ];
                        
                        // 如果完结投诉失败，在消息中突出显示
                        if (!($finishComplaint['success'] ?? false)) {
                            $errorMsg = $finishComplaint['error'] ?? '未知错误';
                            $responseData['message'] .= '；【支付宝完结投诉失败：' . $errorMsg . '】';
                        }
                    }
                }
                
                // 检查状态是否更新
                // 如果状态未更新，说明投诉未真正处理完成，应该返回错误
                if (!($result['status_updated'] ?? false)) {
                    $responseData['message'] = '投诉处理失败：' . ($result['message'] ?? '投诉未真正处理完成');
                    $responseData['message'] .= '；【注意：投诉状态未更新为PROCESSED，可能因为退款失败或支付宝完结投诉失败】';
                    
                    // 记录详细日志
                    Log::warning('投诉处理：状态未更新，投诉未真正处理完成', [
                        'complaint_id' => $id,
                        'refund_success' => $result['refund_success'] ?? false,
                        'finish_success' => $result['finish_success'] ?? false,
                        'status_updated' => $result['status_updated'] ?? false,
                        'refund_result' => $result['refund_result'] ?? null,
                        'finish_complaint_result' => $result['finish_complaint_result'] ?? null
                    ]);
                    
                    // 返回错误，而不是成功
                    return error($responseData['message'], $responseData);
                }
                
                return success($responseData, $result['message'] ?? '处理成功');

            } catch (\Exception $e) {
                \app\service\OrderLogService::log(
                    '', '', '',
                    '投诉处理', 'ERROR', '节点34-投诉处理失败',
                    ['action'=>'handle投诉','reason'=>$e->getMessage(),'complaint_id'=>$id],
                    $request->getRealIp(), $request->header('user-agent','')
                );
                return error('处理投诉失败: ' . $e->getMessage());
            }

        } catch (\Throwable $e) {
            Log::error('处理投诉失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return error('处理投诉失败: ' . $e->getMessage());
        }
    }
}

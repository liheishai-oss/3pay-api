<?php

namespace app\admin\controller\v1;

use support\Request;
use support\Db;
use support\Log;

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

            // 验证参数
            if (empty($id)) {
                return error('投诉ID不能为空');
            }
            if (empty($processCode)) {
                return error('处理结果码不能为空');
            }
            if (empty($feedback)) {
                return error('反馈内容不能为空');
            }

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

            // 更新投诉记录
            $updateData = [
                'complaint_status' => $status,
                'process_code' => $processCode,
                'merchant_feedback' => $feedback,
                'refund_amount' => $refundAmount,
                'feedback_time' => date('Y-m-d H:i:s'),
                'handler_id' => $userData['id'] ?? 0,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $result = Db::table('alipay_complaint')
                ->where('id', $id)
                ->update($updateData);

            if ($result === false) {
                \app\service\OrderLogService::log(
                    '', '', '',
                    '投诉处理', 'ERROR', '节点34-投诉处理失败',
                    ['action'=>'update投诉','reason'=>'更新失败','complaint_id'=>$id],
                    $request->getRealIp(), $request->header('user-agent','')
                );
                return error('更新失败');
            }

            \app\service\OrderLogService::log(
                '', '', '',
                '投诉处理', 'INFO', '节点35-投诉处理成功',
                ['action'=>'handle投诉','process_code'=>$processCode,'complaint_id'=>$id,'status'=>$status,'handler_id'=>$userData['id']??0,'refund_amount'=>$refundAmount],
                $request->getRealIp(), $request->header('user-agent','')
            );

            return success(['id' => $id], '处理成功');

        } catch (\Throwable $e) {
            Log::error('处理投诉失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return error('处理投诉失败: ' . $e->getMessage());
        }
    }
}

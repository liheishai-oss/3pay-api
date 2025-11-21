<?php

namespace app\admin\controller\v1;

use app\common\helpers\MoneyHelper;
use app\model\SingleRoyalty;
use app\model\Agent;
use app\model\TransferOrder;
use app\model\Subject;
use app\model\SubjectCert;
use app\service\payment\PaymentFactory;
use app\service\alipay\AlipayService;
use app\service\royalty\RoyaltyIntegrityService;
use support\Request;
use support\Db;

class SingleRoyaltyController
{
    /**
     * 单笔分账列表
     */
    public function index(Request $request)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;
        
        $page = $request->get('page', 1);
        $limit = $request->get('page_size', $request->get('limit', 20));
        
        // 支持search参数（JSON格式）
        $searchJson = $request->get('search', '');
        $searchParams = [];
        if ($searchJson) {
            $searchParams = json_decode($searchJson, true) ?: [];
        }
        
        // 优先从search参数中获取，否则从直接参数获取
        $payeeName = $searchParams['payee_name'] ?? $request->get('payee_name', '');
        $payeeAccount = $searchParams['payee_account'] ?? $request->get('payee_account', '');
        $status = $searchParams['status'] ?? $request->get('status', '');
        $agentId = $searchParams['agent_id'] ?? $request->get('agent_id', '');

        $query = SingleRoyalty::with(['agent']);

        // 代理商只能看自己的数据
        if ($isAgent) {
            $query->where('agent_id', $userData['agent_id']);
        } elseif ($agentId) {
            $query->where('agent_id', $agentId);
        }

        if ($payeeName) {
            $query->where('payee_name', 'like', '%' . $payeeName . '%');
        }

        if ($payeeAccount) {
            $query->where('payee_account', 'like', '%' . $payeeAccount . '%');
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        $total = $query->count();
        $list = $query->orderBy('id', 'desc')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get()
            ->toArray();

        return success([
            'data' => $list,
            'total' => $total,
            'current_page' => (int)$page,
            'per_page' => (int)$limit,
            'admin' => false
        ]);
    }

    /**
     * 单笔分账详情
     */
    public function detail(Request $request, $id)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;

        $query = SingleRoyalty::with(['agent']);

        // 代理商只能看自己的数据
        if ($isAgent) {
            $query->where('agent_id', $userData['agent_id']);
        }

        $singleRoyalty = $query->find($id);

        if (!$singleRoyalty) {
            return error('单笔分账不存在');
        }

        return success($singleRoyalty->toArray());
    }

    /**
     * 添加/编辑单笔分账
     */
    public function store(Request $request)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;
        
        $param = $request->post();
        $id = $param['id'] ?? 0;

        // 验证必填字段
        if (empty($param['payee_name'])) {
            return error('请输入收款人姓名');
        }
        if (empty($param['payee_account'])) {
            return error('请输入收款人账号');
        }

        // 代理商自动使用自己的agent_id
        if ($isAgent) {
            $param['agent_id'] = $userData['agent_id'];
        } else {
            // 管理员必须选择代理商
            if (empty($param['agent_id'])) {
                return error('请选择代理商');
            }
        }

        try {
            Db::beginTransaction();

            if ($id > 0) {
                // 编辑
                $query = SingleRoyalty::where('id', $id);
                
                // 代理商只能编辑自己的数据
                if ($isAgent) {
                    $query->where('agent_id', $userData['agent_id']);
                }
                
                $singleRoyalty = $query->first();
                
                if (!$singleRoyalty) {
                    Db::rollBack();
                    return error('单笔分账不存在或无权限操作');
                }

                $singleRoyalty->payee_name = $param['payee_name'];
                $singleRoyalty->payee_account = $param['payee_account'];
                $singleRoyalty->status = $param['status'] ?? 1;
                $singleRoyalty->save();

                Db::commit();
                return success([], '编辑成功');
            } else {
                // 新增
                SingleRoyalty::create([
                    'agent_id' => $param['agent_id'],
                    'payee_name' => $param['payee_name'],
                    'payee_account' => $param['payee_account'],
                    'status' => $param['status'] ?? 1,
                ]);

                Db::commit();
                return success([], '添加成功');
            }
        } catch (\Exception $e) {
            Db::rollBack();
            return error('操作失败：' . $e->getMessage());
        }
    }

    /**
     * 删除单笔分账（支持批量删除）
     */
    public function destroy(Request $request)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;
        
        $ids = $request->post('ids');

        if (empty($ids) || !is_array($ids)) {
            return error('参数错误，缺少要删除的ID列表');
        }

        $query = SingleRoyalty::whereIn('id', $ids);
        
        // 代理商只能删除自己的数据
        if ($isAgent) {
            $query->where('agent_id', $userData['agent_id']);
        }
        
        $singleRoyalties = $query->get();

        if ($singleRoyalties->isEmpty()) {
            return error('单笔分账不存在或无权限操作');
        }

        // 获取实际可以删除的ID（权限过滤后的）
        $validIds = $singleRoyalties->pluck('id')->toArray();

        try {
            Db::beginTransaction();

            // 批量删除
            SingleRoyalty::whereIn('id', $validIds)->delete();

            Db::commit();
            return success([], '删除成功');
        } catch (\Exception $e) {
            Db::rollBack();
            return error('删除失败：' . $e->getMessage());
        }
    }

    /**
     * 切换状态
     */
    public function switch(Request $request)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;
        
        $id = $request->post('id');

        if (!$id) {
            return error('参数错误');
        }

        $query = SingleRoyalty::where('id', $id);
        
        // 代理商只能切换自己的数据
        if ($isAgent) {
            $query->where('agent_id', $userData['agent_id']);
        }
        
        $singleRoyalty = $query->first();

        if (!$singleRoyalty) {
            return error('单笔分账不存在或无权限操作');
        }

        // 自动切换状态：1变0，0变1
        $singleRoyalty->status = $singleRoyalty->status == 1 ? 0 : 1;
        $singleRoyalty->save();

        return success(['status' => $singleRoyalty->status], '操作成功');
    }

    /**
     * 获取代理商列表（供管理员选择）
     */
    public function getAgentList(Request $request)
    {
        $list = Agent::where('status', Agent::STATUS_ENABLED)
            ->select('id', 'agent_name')
            ->orderBy('id', 'desc')
            ->get()
            ->toArray();

        return success($list);
    }

    /**
     * 分账订单列表
     */
    public function orderList(Request $request)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;
        
        $page = $request->get('page', 1);
        $limit = $request->get('page_size', $request->get('limit', 20));
        
        // 支持search参数（JSON格式）
        $searchJson = $request->get('search', '');
        $searchParams = [];
        if ($searchJson) {
            $searchParams = json_decode($searchJson, true) ?: [];
        }
        
        // 优先从search参数中获取，否则从直接参数获取
        $platformOrderNo = $searchParams['platform_order_no'] ?? $request->get('platform_order_no', '');
        $merchantOrderNo = $searchParams['merchant_order_no'] ?? $request->get('merchant_order_no', '');
        $payStatus = $searchParams['pay_status'] ?? $request->get('pay_status', '');
        $royaltyStatus = $searchParams['royalty_status'] ?? $request->get('royalty_status', '');
        $royaltyType = $searchParams['royalty_type'] ?? $request->get('royalty_type', '');
        $startTime = $searchParams['start_time'] ?? $request->get('start_time', '');
        $endTime = $searchParams['end_time'] ?? $request->get('end_time', '');
        $agentId = $searchParams['agent_id'] ?? $request->get('agent_id', '');
        $merchantId = $searchParams['merchant_id'] ?? $request->get('merchant_id', '');

        $this->syncMissingRoyaltyOrders([
            'is_agent' => $isAgent,
            'agent_id' => $userData['agent_id'] ?? null,
            'filter_agent_id' => $agentId,
            'merchant_id' => $merchantId,
            'platform_order_no' => $platformOrderNo,
            'merchant_order_no' => $merchantOrderNo,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'pay_status' => $payStatus,
        ]);

        // 查询有分账记录的订单
        $query = \app\model\Order::with([
            'agent', 
            'merchant', 
            'product', 
            'subject',
            'royaltyRecords' => function($q) {
                $q->orderBy('id', 'desc')->limit(1);
            }
        ])->whereHas('royaltyRecords');

        // 代理商只能看自己的订单
        if ($isAgent) {
            $query->where('agent_id', $userData['agent_id']);
        } elseif ($agentId) {
            $query->where('agent_id', $agentId);
        }

        if ($merchantId) {
            $query->where('merchant_id', $merchantId);
        }

        if ($platformOrderNo) {
            $query->where('platform_order_no', 'like', '%' . $platformOrderNo . '%');
        }

        if ($merchantOrderNo) {
            $query->where('merchant_order_no', 'like', '%' . $merchantOrderNo . '%');
        }

        if ($payStatus !== '') {
            $query->where('pay_status', $payStatus);
        }

        if ($royaltyStatus !== '') {
            $query->whereHas('royaltyRecords', function($q) use ($royaltyStatus) {
                $q->where('royalty_status', $royaltyStatus);
            });
        }
        if ($royaltyType !== '' && $royaltyType !== null) {
            $query->whereHas('royaltyRecords', function($q) use ($royaltyType) {
                $q->where('royalty_type', $royaltyType);
            });
        }
        if ($startTime) {
            $query->where('created_at', '>=', $startTime);
        }

        if ($endTime) {
            $query->where('created_at', '<=', $endTime);
        }

        $total = $query->count();
        
        $list = $query->orderBy('id', 'desc')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get()
            ->map(function($order) {
                $orderData = $order->toArray();
                $subject = $order->getSubjectEntity();
                // 获取最新的分账记录（按 id 降序排列，获取最新的一条）
                $latestRoyalty = $order->royaltyRecords()->orderBy('id', 'desc')->first();
                if ($latestRoyalty) {
                    $orderData['royalty_status_text'] = $latestRoyalty->getStatusText();
                    $orderData['royalty_type'] = $latestRoyalty->royalty_type;
                    $orderData['royalty_status'] = $latestRoyalty->royalty_status;
                    $orderData['royalty_amount'] = $latestRoyalty->royalty_amount_yuan;
                    $orderData['royalty_time'] = $latestRoyalty->royalty_time; // 分账时间
                    // 收款账号信息：仅在分账成功后展示
                    $orderData['payee_account'] = $latestRoyalty->royalty_status === \app\model\OrderRoyalty::ROYALTY_STATUS_SUCCESS
                        ? ($latestRoyalty->payee_account ?: '-')
                        : '-';
                    // 分账主体（从subject获取）
                    $orderData['royalty_subject_name'] = $subject ? ($subject->company_name ?? '-') : '-';
                    // 备注：如果分账失败，显示失败原因；否则显示订单备注
                    if ($latestRoyalty->royalty_status === \app\model\OrderRoyalty::ROYALTY_STATUS_FAILED && $latestRoyalty->royalty_error) {
                        $orderData['order_remark'] = $latestRoyalty->royalty_error;
                    } else {
                        $orderData['order_remark'] = $order->remark ?? '-';
                    }
                    // 订单号（平台订单号）
                    $orderData['order_no'] = $order->platform_order_no;
                    // 订单金额
                    $orderData['order_amount'] = $order->order_amount;
                    // 失败原因（用于详情页显示）
                    $orderData['royalty_error'] = $latestRoyalty->royalty_error ?? null;
                }
                
                // 判断是否可以手动分账：已支付 && 未分账成功 && 满足分账条件
                $canManualRoyalty = false;
                if ($order->pay_status === \app\model\Order::PAY_STATUS_PAID) {
                    if (!$order->hasRoyalty()) {
                        $skipReason = null;
                        $canManualRoyalty = $order->canProcessRoyalty($skipReason);
                    }
                }
                $orderData['can_manual_royalty'] = $canManualRoyalty;
                
                return $orderData;
            })
            ->toArray();

        return success([
            'data' => $list,
            'total' => $total,
            'current_page' => (int)$page,
            'per_page' => (int)$limit,
            'page_total' => (int)ceil($total / $limit)
        ]);
    }

    /**
     * 分账订单详情
     */
    public function orderDetail(Request $request, $id)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;

        $query = \app\model\Order::with([
            'agent', 
            'merchant', 
            'product', 
            'subject',
            'royaltyRecords'
        ])->whereHas('royaltyRecords');

        // 代理商只能看自己的订单
        if ($isAgent) {
            $query->where('agent_id', $userData['agent_id']);
        }

        $order = $query->find($id);

        if (!$order) {
            return error('订单不存在');
        }

        $orderData = $order->toArray();
        // 获取最新的分账记录（按 id 降序排列，获取最新的一条）
        $latestRoyalty = $order->royaltyRecords()->orderBy('id', 'desc')->first();
        if ($latestRoyalty) {
            $orderData['royalty_status_text'] = $latestRoyalty->getStatusText();
            $orderData['royalty_type'] = $latestRoyalty->royalty_type;
            $orderData['royalty_status'] = $latestRoyalty->royalty_status;
            $orderData['royalty_amount'] = $latestRoyalty->royalty_amount_yuan;
            $royaltyRecordArray = $latestRoyalty->toArray();
            if ($latestRoyalty->royalty_status !== \app\model\OrderRoyalty::ROYALTY_STATUS_SUCCESS) {
                $royaltyRecordArray['payee_account'] = '-';
            }
            $orderData['royalty_record'] = $royaltyRecordArray;
            // 备注：如果分账失败，显示失败原因；否则显示订单备注
            if ($latestRoyalty->royalty_status === \app\model\OrderRoyalty::ROYALTY_STATUS_FAILED && $latestRoyalty->royalty_error) {
                $orderData['order_remark'] = $latestRoyalty->royalty_error;
            } else {
                $orderData['order_remark'] = $order->remark ?? '-';
            }
            // 失败原因（用于详情页显示）
            $orderData['royalty_error'] = $latestRoyalty->royalty_error ?? null;
        } else {
            // 如果没有分账记录，显示订单备注
            $orderData['order_remark'] = $order->remark ?? '-';
        }

        return success($orderData);
    }

    /**
     * 手动触发分账
     */
    public function manualRoyalty(Request $request)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;
        
        $orderId = $request->post('order_id');
        if (empty($orderId)) {
            return error('订单ID不能为空');
        }

        // 查询订单
        $query = \app\model\Order::with(['subject', 'product']);
        
        // 代理商只能操作自己的订单
        if ($isAgent) {
            $query->where('agent_id', $userData['agent_id']);
        }
        
        $order = $query->find($orderId);
        
        if (!$order) {
            return error('订单不存在或无权限操作');
        }

        // 检查订单是否已支付
        if ($order->pay_status !== \app\model\Order::PAY_STATUS_PAID) {
            return error('订单未支付，无法分账');
        }

        // 检查是否已分账成功
        if ($order->hasRoyalty()) {
            return error('订单已分账成功，无需重复分账');
        }

        // 检查是否需要分账
        $skipReason = null;
        if (!$order->canProcessRoyalty($skipReason)) {
            $reasonText = [
                'not_paid' => '订单未支付',
                'already_royalized' => '订单已分账',
                'subject_missing' => '订单主体不存在',
                'royalty_disabled' => '分账主体未开启分账功能',
            ];
            return error($reasonText[$skipReason] ?? '订单不满足分账条件');
        }

        // 调用分账服务
        $operatorIp = $request->getRealIp();
        $result = \app\service\royalty\RoyaltyService::manualRoyalty($orderId, $operatorIp);

        if ($result['success']) {
            return success([
                'royalty_amount' => $result['data']['royalty_amount'] ?? 0,
                'royalty_id' => $result['data']['royalty_id'] ?? 0,
            ], $result['message'] ?? '分账成功');
        } else {
            return error($result['message'] ?? '分账失败');
        }
    }

    /**
     * 转账订单列表
     */
    public function transferOrderList(Request $request)
    {
        $page = $request->get('page', 1);
        $limit = $request->get('page_size', $request->get('limit', 20));
        
        // 支持search参数（JSON格式）
        $searchJson = $request->get('search', '');
        $searchParams = [];
        if ($searchJson) {
            $searchParams = json_decode($searchJson, true) ?: [];
        }
        
        // 优先从search参数中获取，否则从直接参数获取
        $payeeName = $searchParams['payee_name'] ?? $request->get('payee_name', '');
        $payeeAccount = $searchParams['payee_account'] ?? $request->get('payee_account', '');
        $subjectId = $searchParams['subject_id'] ?? $request->get('subject_id', '');
        $startTime = $searchParams['start_time'] ?? $request->get('start_time', '');
        $endTime = $searchParams['end_time'] ?? $request->get('end_time', '');

        $query = TransferOrder::with(['subject']);

        if ($subjectId) {
            $query->where('subject_id', $subjectId);
        }

        if ($payeeName) {
            $query->where('payee_name', 'like', '%' . $payeeName . '%');
        }

        if ($payeeAccount) {
            $query->where('payee_account', 'like', '%' . $payeeAccount . '%');
        }

        if ($startTime) {
            $query->where('transfer_time', '>=', $startTime);
        }

        if ($endTime) {
            $query->where('transfer_time', '<=', $endTime);
        }

        $total = $query->count();
        $list = $query->orderBy('id', 'desc')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get()
            ->map(function($transfer) {
                $data = $transfer->toArray();
                $data['subject_name'] = $transfer->subject->company_name ?? '-';
                return $data;
            })
            ->toArray();

        return success([
            'data' => $list,
            'total' => $total,
            'current_page' => (int)$page,
            'per_page' => (int)$limit,
            'page_total' => (int)ceil($total / $limit)
        ]);
    }

    /**
     * 创建转账订单
     */
    public function transfer(Request $request)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;
        
        $subjectId = $request->post('subject_id');
        $payeeName = $request->post('payee_name');
        $payeeAccount = $request->post('payee_account');
        $amount = $request->post('amount');
        $remark = $request->post('remark', '');

        // 验证必填字段
        if (empty($subjectId)) {
            return error('请选择转出主体');
        }
        if (empty($payeeName)) {
            return error('请输入收款人姓名');
        }
        if (empty($payeeAccount)) {
            return error('请输入收款人账号');
        }
        if (empty($amount) || $amount <= 0) {
            return error('请输入有效的转账金额');
        }

        // 验证主体是否存在且属于当前代理商
        $subject = Subject::find($subjectId);
        if (!$subject) {
            return error('主体不存在');
        }

        // 如果是代理商，验证主体是否属于自己
        if ($isAgent) {
            $agentId = $userData['agent_id'] ?? 0;
            if ($subject->agent_id != $agentId) {
                return error('无权使用该主体');
            }
        }

        // 生成转账单号
        $transferNo = 'TF' . date('YmdHis') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

        // 创建转账订单
        try {
            $transferOrder = TransferOrder::create([
                'subject_id' => $subjectId,
                'payee_name' => $payeeName,
                'payee_account' => $payeeAccount,
                'amount' => $amount,
                'transfer_no' => $transferNo,
                'remark' => $remark,
                'transfer_time' => null, // 转账时间待实际转账成功后更新
            ]);

            // 调用支付宝转账接口
            try {
                // 使用反射调用 PaymentFactory 的私有方法 getCertPath，确保与支付逻辑一致
                $reflection = new \ReflectionClass(PaymentFactory::class);
                $getCertPathMethod = $reflection->getMethod('getCertPath');
                $getCertPathMethod->setAccessible(true);
                
                // 获取证书信息
                $cert = SubjectCert::where('subject_id', $subject->id)->first();
                if (!$cert) {
                    throw new \Exception("支付主体证书配置缺失");
                }
                
                // 使用 PaymentFactory 的 getCertPath 方法处理证书路径（与支付逻辑完全一致）
                $alipayCertPath = $getCertPathMethod->invoke(null, $cert->alipay_public_cert_path, $cert->alipay_public_cert, 'alipay_public_cert');
                $alipayRootCertPath = $getCertPathMethod->invoke(null, $cert->alipay_root_cert_path, $cert->alipay_root_cert, 'alipay_root_cert');
                $appCertPath = $getCertPathMethod->invoke(null, $cert->app_public_cert_path, $cert->app_public_cert, 'app_public_cert');
                
                // 构建支付配置（与 PaymentFactory::getPaymentConfig 逻辑一致）
                $appUrl = env('APP_URL', 'http://127.0.0.1:8787');
                $paymentConfig = [
                    'appid' => $subject->alipay_app_id,
                    'AppPrivateKey' => $cert->app_private_key,
                    'alipayCertPublicKey' => $alipayCertPath,
                    'alipayRootCert' => $alipayRootCertPath,
                    'appCertPublicKey' => $appCertPath,
                    'notify_url' => rtrim($appUrl, '/') . '/api/v1/payment/notify/alipay',
                    'sandbox' => false,
                ];
                
                // 验证配置完整性（使用 PaymentFactory 的验证逻辑）
                $validateMethod = $reflection->getMethod('validatePaymentConfig');
                $validateMethod->setAccessible(true);
                $validateMethod->invoke(null, $paymentConfig);
                
                // 构建转账信息
                $transferInfo = [
                    'out_biz_no' => $transferNo, // 商户转账唯一订单号
                    'trans_amount' => $amount, // 转账金额
                    'payee_type' => 'ALIPAY_LOGONID', // 收款方账户类型：支付宝登录号（手机号或邮箱）
                    'payee_account' => $payeeAccount, // 收款方账户
                    'payee_real_name' => $payeeName, // 收款方真实姓名
                    'remark' => $remark, // 转账备注
                ];
                
                // 调用支付宝转账
                $alipayService = new AlipayService();
                $transferResult = $alipayService->transfer($transferInfo, $paymentConfig);
                
                // 更新转账订单状态
                if ($transferResult['success'] && $transferResult['status'] === 'SUCCESS') {
                    // 转账成功
                    $transferOrder->transfer_time = $transferResult['trans_date'] ?? date('Y-m-d H:i:s');
                    $transferOrder->save();
                    
                    \support\Log::info('转账成功', [
                        'transfer_order_id' => $transferOrder->id,
                        'transfer_no' => $transferNo,
                        'alipay_order_id' => $transferResult['order_id'] ?? '',
                        'status' => $transferResult['status']
                    ]);
                    
                    return success([
                        'id' => $transferOrder->id,
                        'transfer_no' => $transferOrder->transfer_no,
                        'alipay_order_id' => $transferResult['order_id'] ?? '',
                        'status' => 'success'
                    ], '转账成功');
                } elseif ($transferResult['status'] === 'DEALING') {
                    // 转账处理中
                    \support\Log::info('转账处理中', [
                        'transfer_order_id' => $transferOrder->id,
                        'transfer_no' => $transferNo,
                        'alipay_order_id' => $transferResult['order_id'] ?? '',
                        'status' => $transferResult['status']
                    ]);
                    
                    return success([
                        'id' => $transferOrder->id,
                        'transfer_no' => $transferOrder->transfer_no,
                        'alipay_order_id' => $transferResult['order_id'] ?? '',
                        'status' => 'processing'
                    ], '转账处理中，请稍后查询结果');
                } else {
                    // 转账失败
                    $errorMsg = '转账失败';
                    $transferOrder->remark = ($transferOrder->remark ? $transferOrder->remark . '; ' : '') . $errorMsg;
                    $transferOrder->save();
                    
                    \support\Log::error('转账失败', [
                        'transfer_order_id' => $transferOrder->id,
                        'transfer_no' => $transferNo,
                        'status' => $transferResult['status'] ?? 'unknown',
                        'result' => $transferResult
                    ]);
                    
                    return error('转账失败，请稍后重试');
                }
            } catch (\Exception $e) {
                // 支付宝转账失败，记录错误信息
                $errorMsg = $e->getMessage();
                $transferOrder->remark = ($transferOrder->remark ? $transferOrder->remark . '; ' : '') . '转账失败: ' . $errorMsg;
                $transferOrder->save();
                
                \support\Log::error('调用支付宝转账接口失败', [
                    'transfer_order_id' => $transferOrder->id,
                    'transfer_no' => $transferNo,
                    'error' => $errorMsg,
                    'trace' => $e->getTraceAsString()
                ]);
                
                return error('转账失败：' . $errorMsg);
            }
        } catch (\Exception $e) {
            \support\Log::error('创建转账订单失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return error('创建转账订单失败：' . $e->getMessage());
        }
    }

    /**
     * 同步已支付但缺少分账记录的订单，确保能在列表中展示
     */
    private function syncMissingRoyaltyOrders(array $filters): void
    {
        try {
            $query = \app\model\Order::with(['subject'])
                ->where('pay_status', \app\model\Order::PAY_STATUS_PAID)
                ->whereDoesntHave('royaltyRecords')
                ->whereHas('subject', function ($q) {
                    $q->where('royalty_type', '!=', Subject::ROYALTY_TYPE_NONE);
                });

            if (!empty($filters['is_agent']) && !empty($filters['agent_id'])) {
                $query->where('agent_id', $filters['agent_id']);
            } elseif (!empty($filters['filter_agent_id'])) {
                $query->where('agent_id', $filters['filter_agent_id']);
            }

            if (!empty($filters['merchant_id'])) {
                $query->where('merchant_id', $filters['merchant_id']);
            }

            if (!empty($filters['platform_order_no'])) {
                $query->where('platform_order_no', 'like', '%' . $filters['platform_order_no'] . '%');
            }

            if (!empty($filters['merchant_order_no'])) {
                $query->where('merchant_order_no', 'like', '%' . $filters['merchant_order_no'] . '%');
            }

            if ($filters['pay_status'] !== '' && $filters['pay_status'] !== null) {
                $query->where('pay_status', $filters['pay_status']);
            }

            if (!empty($filters['start_time'])) {
                $query->where('created_at', '>=', $filters['start_time']);
            }

            if (!empty($filters['end_time'])) {
                $query->where('created_at', '<=', $filters['end_time']);
            }

            $query->orderBy('id', 'desc')
                ->limit(200)
                ->get()
                ->each(function ($order) {
                    RoyaltyIntegrityService::ensureSnapshot($order);
                });
        } catch (\Throwable $e) {
            \support\Log::warning('同步缺少分账记录的订单失败', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 分账订单统计
     */
    public function orderStatistics(Request $request)
    {
        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;
        
        // 支持search参数（JSON格式）
        $searchJson = $request->get('search', '');
        $searchParams = [];
        if ($searchJson) {
            $searchParams = json_decode($searchJson, true) ?: [];
        }
        
        $platformOrderNo = $searchParams['platform_order_no'] ?? $request->get('platform_order_no', '');
        $merchantOrderNo = $searchParams['merchant_order_no'] ?? $request->get('merchant_order_no', '');
        $payStatus = $searchParams['pay_status'] ?? $request->get('pay_status', '');
        $royaltyStatus = $searchParams['royalty_status'] ?? $request->get('royalty_status', '');
        $royaltyType = $searchParams['royalty_type'] ?? $request->get('royalty_type', '');
        $startTime = $searchParams['start_time'] ?? $request->get('start_time', '');
        $endTime = $searchParams['end_time'] ?? $request->get('end_time', '');
        $agentId = $searchParams['agent_id'] ?? $request->get('agent_id', '');
        $merchantId = $searchParams['merchant_id'] ?? $request->get('merchant_id', '');

        $query = \app\model\Order::whereHas('royaltyRecords');

        // 代理商只能看自己的订单
        if ($isAgent) {
            $query->where('agent_id', $userData['agent_id']);
        } elseif ($agentId) {
            $query->where('agent_id', $agentId);
        }

        if ($merchantId) {
            $query->where('merchant_id', $merchantId);
        }

        if ($platformOrderNo) {
            $query->where('platform_order_no', 'like', '%' . $platformOrderNo . '%');
        }

        if ($merchantOrderNo) {
            $query->where('merchant_order_no', 'like', '%' . $merchantOrderNo . '%');
        }

        if ($payStatus !== '') {
            $query->where('pay_status', $payStatus);
        }

        if ($royaltyStatus !== '') {
            $query->whereHas('royaltyRecords', function($q) use ($royaltyStatus) {
                $q->where('royalty_status', $royaltyStatus);
            });
        }
        
        if ($royaltyType !== '' && $royaltyType !== null) {
            $query->whereHas('royaltyRecords', function($q) use ($royaltyType) {
                $q->where('royalty_type', $royaltyType);
            });
        }
        
        if ($startTime) {
            $query->where('created_at', '>=', $startTime);
        }

        if ($endTime) {
            $query->where('created_at', '<=', $endTime);
        }

        // 总订单数
        $totalCount = (clone $query)->count();

        // 各分账状态订单数
        $pendingCount = (clone $query)->whereHas('royaltyRecords', function($q) {
            $q->where('royalty_status', \app\model\OrderRoyalty::ROYALTY_STATUS_PENDING);
        })->count();
        
        $processingCount = (clone $query)->whereHas('royaltyRecords', function($q) {
            $q->where('royalty_status', \app\model\OrderRoyalty::ROYALTY_STATUS_PROCESSING);
        })->count();
        
        $successCount = (clone $query)->whereHas('royaltyRecords', function($q) {
            $q->where('royalty_status', \app\model\OrderRoyalty::ROYALTY_STATUS_SUCCESS);
        })->count();
        
        $failedCount = (clone $query)->whereHas('royaltyRecords', function($q) {
            $q->where('royalty_status', \app\model\OrderRoyalty::ROYALTY_STATUS_FAILED);
        })->count();

        // 总金额统计
        $totalAmount = (clone $query)->sum('order_amount');
        
        // 分账成功订单的总金额
        $successAmount = (clone $query)->whereHas('royaltyRecords', function($q) {
            $q->where('royalty_status', \app\model\OrderRoyalty::ROYALTY_STATUS_SUCCESS);
        })->sum('order_amount');

        // 分账总金额（从分账记录中统计）
        $royaltyTotalAmount = \app\model\OrderRoyalty::whereHas('order', function($q) use ($query) {
            $q->whereIn('id', (clone $query)->pluck('id'));
        })->where('royalty_status', \app\model\OrderRoyalty::ROYALTY_STATUS_SUCCESS)
          ->sum('royalty_amount');
        $royaltyTotalAmount = round($royaltyTotalAmount / 100, 2); // 转换为元

        // 成功率计算
        $successRate = $totalCount > 0 ? round(($successCount / $totalCount) * 100, 2) : 0;

        return success([
            'total_count' => $totalCount,
            'pending_count' => $pendingCount,
            'processing_count' => $processingCount,
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'total_amount' => round($totalAmount, 2),
            'success_amount' => round($successAmount, 2),
            'royalty_total_amount' => $royaltyTotalAmount,
            'success_rate' => $successRate,
        ]);
    }

    /**
     * 转账订单统计
     */
    public function transferOrderStatistics(Request $request)
    {
        // 支持search参数（JSON格式）
        $searchJson = $request->get('search', '');
        $searchParams = [];
        if ($searchJson) {
            $searchParams = json_decode($searchJson, true) ?: [];
        }
        
        $payeeName = $searchParams['payee_name'] ?? $request->get('payee_name', '');
        $payeeAccount = $searchParams['payee_account'] ?? $request->get('payee_account', '');
        $subjectId = $searchParams['subject_id'] ?? $request->get('subject_id', '');
        $startTime = $searchParams['start_time'] ?? $request->get('start_time', '');
        $endTime = $searchParams['end_time'] ?? $request->get('end_time', '');

        $query = TransferOrder::query();

        if ($subjectId) {
            $query->where('subject_id', $subjectId);
        }

        if ($payeeName) {
            $query->where('payee_name', 'like', '%' . $payeeName . '%');
        }

        if ($payeeAccount) {
            $query->where('payee_account', 'like', '%' . $payeeAccount . '%');
        }

        if ($startTime) {
            $query->where('transfer_time', '>=', $startTime);
        }

        if ($endTime) {
            $query->where('transfer_time', '<=', $endTime);
        }

        // 总订单数
        $totalCount = (clone $query)->count();

        // 总金额统计
        $totalAmount = (clone $query)->sum('amount');

        // 已转账金额（有转账时间的）
        $transferredAmount = (clone $query)->whereNotNull('transfer_time')->sum('amount');

        // 待转账金额（无转账时间的）
        $pendingAmount = (clone $query)->whereNull('transfer_time')->sum('amount');

        return success([
            'total_count' => $totalCount,
            'total_amount' => round($totalAmount, 2),
            'transferred_amount' => round($transferredAmount, 2),
            'pending_amount' => round($pendingAmount, 2),
        ]);
    }
}



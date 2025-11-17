<?php

namespace app\admin\controller\v1;

use app\exception\MyBusinessException;
use app\model\Subject;
use app\model\SubjectCert;
use app\model\Agent;
use app\model\Product;
use support\Request;
use support\Response;
use support\Db;

/**
 * 支付宝主体管理控制器
 */
class SubjectController
{
    /**
     * 列表查询
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $param = $request->all();
        $search = json_decode($param['search'] ?? '{}', true);
        
        // 处理嵌套的search对象
        if (isset($search['search']) && is_array($search['search'])) {
            $search = $search['search'];
        }

        $userData = $request->userData;
        $isAgent = ($userData['user_group_id'] ?? 0) == 3;
        $agentId = $userData['agent_id'] ?? null;
    
        // 构建查询（不加载证书，提高性能）
        $query = Subject::with(['agent', 'paymentTypes']);

        // 代理商只能查看自己的主体
        if ($isAgent) {
            if (!$agentId) {
                // 如果代理商没有 agent_id，返回空列表（确保安全）
                \support\Log::warning('代理商查询主体但agent_id为空', [
                    'admin_id' => $userData['admin_id'] ?? null,
                    'is_agent' => $isAgent,
                    'agent_id' => $agentId
                ]);
                return success([
                    'data' => [],
                    'total' => 0,
                    'per_page' => $param['page_size'] ?? 10,
                    'current_page' => $param['current_page'] ?? 1,
                    'last_page' => 1
                ]);
            }
            // 强制过滤：代理商只能看到自己的主体
            // 忽略前端传递的 agent_id 参数，强制使用登录用户的 agent_id
            $query->where('agent_id', $agentId);
            
            // 调试日志
            \support\Log::info('代理商查询主体列表', [
                'admin_id' => $userData['admin_id'] ?? null,
                'agent_id' => $agentId,
                'search_agent_id' => $search['agent_id'] ?? null,
                'will_filter_by' => $agentId
            ]);
        } else {
            // 管理员可以按代理商筛选
            if (!empty($search['agent_id'])) {
                $query->where('agent_id', $search['agent_id']);
            }
        }

        // 搜索条件
        if (!empty($search['company_name'])) {
            $query->where('company_name', 'like', "%" . trim($search['company_name']) . "%");
        }

        if (!empty($search['alipay_app_id'])) {
            $query->where('alipay_app_id', 'like', "%" . trim($search['alipay_app_id']) . "%");
        }

        if (isset($search['status']) && $search['status'] !== '') {
            $query->where('status', $search['status']);
        }

        // 分页获取数据
        $data = $query->orderBy('id', 'desc')
            ->paginate($param['page_size'] ?? 10)
            ->toArray();

        return success($data);
    }

    /**
     * 详情
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function detail(Request $request, int $id): Response
    {
        $userData = $request->userData;
        $isAgent = $userData['is_agent'] ?? false;
        $agentId = $userData['agent_id'] ?? null;

        $query = Subject::with(['agent', 'cert', 'paymentTypes']);

        // 代理商只能查看自己的主体
        if ($isAgent && $agentId) {
            $query->where('agent_id', $agentId);
        }

        $subject = $query->find($id);
        
        if (!$subject) {
            // 检查主体是否真的不存在（不考虑权限）
            $subjectExists = Subject::find($id);
            if (!$subjectExists) {
                throw new MyBusinessException("主体不存在（ID: {$id}），可能已被删除，请刷新列表页面");
            } else {
                throw new MyBusinessException('无权访问此主体');
            }
        }

        $data = $subject->toArray();
        
        // 保存主体ID（防止被证书数据覆盖）
        $subjectId = $data['id'];
        
        // 将证书信息合并到主数据中
        if (isset($data['cert'])) {
            $cert = $data['cert'];
            unset($data['cert']);
            
            // 移除证书表中的subject_id，避免与主体id混淆
            unset($cert['subject_id']);
            unset($cert['id']); // 也移除证书表的id
            
            // 字段名映射：数据库字段名 -> 前端字段名
            $certMapping = [
                'app_public_cert' => 'app_cert_public_key',
                'app_public_cert_path' => 'app_cert_public_key_path',
                'alipay_public_cert' => 'alipay_cert_public_key',
                'alipay_public_cert_path' => 'alipay_cert_public_key_path',
            ];
            
            // 应用字段映射
            foreach ($certMapping as $dbField => $frontendField) {
                if (isset($cert[$dbField])) {
                    $cert[$frontendField] = $cert[$dbField];
                    unset($cert[$dbField]);
                }
            }
            
            $data = array_merge($data, $cert);
        }
        
        // 确保id字段正确（使用主体ID）
        $data['id'] = $subjectId;
        
        // 调试日志：确认返回的ID
        \support\Log::info('主体详情返回', [
            'subject_id' => $subjectId,
            'returned_id' => $data['id'],
            'has_id_field' => isset($data['id']),
            'id_type' => gettype($data['id']),
        ]);

        return success($data);
    }

    /**
     * 添加/编辑
     * @param Request $request
     * @return Response
     */
    public function store(Request $request): Response
    {
        $param = $request->post();
        $userData = $request->userData;
        $isAgent = $userData['is_agent'] ?? false;
        $agentId = $userData['agent_id'] ?? null;
        
        // 🔍 第一步调试：记录接收到的原始数据
        \support\Log::info('===== 主体保存 - 接收原始POST数据 =====', [
            'raw_post' => $param,
            'has_id' => isset($param['id']),
            'id_value' => $param['id'] ?? 'NOT_SET',
            'id_type' => isset($param['id']) ? gettype($param['id']) : 'N/A',
            'is_empty' => empty($param['id']),
            'user_is_agent' => $isAgent,
            'user_agent_id' => $agentId,
        ]);
        
        try {
            // 验证必填字段
            if (empty($param['company_name'])) {
                throw new MyBusinessException('企业名称不能为空');
            }
            
            if (empty($param['alipay_app_id'])) {
                throw new MyBusinessException('支付宝APPID不能为空');
            }

            // 修复：使用 isset 和 > 0 判断编辑模式，避免 empty(0) 的问题
            $isEdit = isset($param['id']) && $param['id'] > 0;
            
            // 调试日志：记录接收到的参数
            \support\Log::info('主体保存请求', [
                'is_edit' => $isEdit,
                'param_id' => $param['id'] ?? 'null',
                'is_agent' => $isAgent,
                'agent_id' => $agentId,
                'param_keys' => array_keys($param),
                'company_name' => $param['company_name'] ?? 'null',
            ]);

            // 如果是代理商，自动设置agent_id
            if ($isAgent) {
                if (!$agentId) {
                    throw new MyBusinessException('代理商信息不完整');
                }
                $param['agent_id'] = $agentId;
            } else {
                // 管理员必须选择代理商
                if (empty($param['agent_id'])) {
                    throw new MyBusinessException('请选择代理商');
                }
                
                // 验证代理商是否存在
                $agent = Agent::find($param['agent_id']);
                if (!$agent) {
                    throw new MyBusinessException('代理商不存在');
                }
            }

            // 开启事务
            Db::beginTransaction();
            
            try {
                if ($isEdit) {
                    // 编辑 - 先不加权限查询主体是否存在
                    \support\Log::info('🔍 尝试查找主体 - 详细信息', [
                        'param_id_raw' => $param['id'] ?? 'KEY_NOT_EXISTS',
                        'param_id_value' => isset($param['id']) ? var_export($param['id'], true) : 'NOT_SET',
                        'param_id_type' => isset($param['id']) ? gettype($param['id']) : 'N/A',
                        'param_id_is_numeric' => isset($param['id']) ? is_numeric($param['id']) : false,
                        'param_id_intval' => isset($param['id']) ? intval($param['id']) : 'N/A',
                        'all_param_keys' => array_keys($param),
                    ]);
                    
                    // 强制转换为整数
                    $searchId = isset($param['id']) ? intval($param['id']) : 0;
                    
                    \support\Log::info('🔍 查找主体 - 使用的ID', [
                        'search_id' => $searchId,
                        'search_id_type' => gettype($searchId),
                    ]);
                    
                    $subject = Subject::find($searchId);
                    
                    if (!$subject) {
                        // 尝试查询所有主体，看看是否有数据
                        $allSubjects = Subject::select('id', 'company_name')->limit(10)->get();
                        $subjectIds = Subject::pluck('id')->toArray();
                        
                        \support\Log::error('❌ 主体不存在 - 详细调试', [
                            'search_id_original' => $param['id'] ?? 'NOT_SET',
                            'search_id_converted' => $searchId,
                            'id_type' => gettype($searchId),
                            'all_subject_ids' => $subjectIds,
                            'all_subjects_sample' => $allSubjects->toArray(),
                            'total_subjects' => Subject::count(),
                            'id_exists_in_db' => in_array($searchId, $subjectIds),
                        ]);
                        
                        // 提供更友好的错误提示
                        $existingIds = implode(', ', array_slice($subjectIds, 0, 5));
                        throw new MyBusinessException("主体不存在（ID: {$searchId}），可能已被删除。当前存在的主体ID: [{$existingIds}...]，请刷新列表页面");
                    }
                    
                    // 调试日志
                    \support\Log::info('编辑主体调试', [
                        'param_id' => $param['id'] ?? 'null',
                        'is_agent' => $isAgent ? 'true' : 'false',
                        'current_agent_id' => $agentId ?? 'null',
                        'subject_agent_id' => $subject->agent_id ?? 'null',
                        'user_data' => $userData
                    ]);
                    
                    // 代理商编辑：确保主体归属于当前代理商
                    if ($isAgent && $agentId) {
                        // 如果主体的agent_id与当前代理商不匹配，检查是否允许调整
                        if ($subject->agent_id != $agentId) {
                            \support\Log::warning('主体代理商ID不匹配，自动调整', [
                                'subject_id' => $subject->id,
                                'old_agent_id' => $subject->agent_id,
                                'new_agent_id' => $agentId
                            ]);
                            // 自动调整为当前代理商
                            $subject->agent_id = $agentId;
                        }
                    }
                    
                    // 管理员编辑：检查权限（管理员可以编辑任意代理商的主体）
                    // 无需额外检查
                    
                    // 检查APPID是否重复（排除自己）
                    $exists = Subject::where('alipay_app_id', $param['alipay_app_id'])
                        ->where('id', '!=', $param['id'])
                        ->exists();
                    if ($exists) {
                        throw new MyBusinessException('该APPID已存在');
                    }
                    
                    // 更新主体基本信息
                    $subject->royalty_type = $param['royalty_type'] ?? 'none';
                    // 分账模式和分账比例仅在分账方式为single或merchant时有效
                    if (in_array($param['royalty_type'] ?? 'none', ['single', 'merchant'])) {
                        $subject->royalty_mode = $param['royalty_mode'] ?? 'normal';
                        $subject->royalty_rate = $param['royalty_rate'] ?? null;
                    } else {
                        $subject->royalty_mode = null;
                        $subject->royalty_rate = null;
                    }
                    $subject->allow_remote_order = $param['allow_remote_order'] ?? 1;
                    $subject->verify_device = $param['verify_device'] ?? 0;
                    $subject->scan_pay_enabled = $param['scan_pay_enabled'] ?? 1;
                    $subject->transaction_limit = $param['transaction_limit'] ?? null;
                    $subject->amount_min = $param['amount_min'] ?? null;
                    $subject->amount_max = $param['amount_max'] ?? null;
                    $subject->company_name = $param['company_name'];
                    $subject->alipay_app_id = $param['alipay_app_id'];
                    $subject->alipay_pid = $param['alipay_pid'] ?? null;
                    $subject->status = $param['status'] ?? 1;
                    $subject->save();
                    
                    // 更新或创建证书信息
                    $certData = [
                        'subject_id' => $subject->id,
                        'app_private_key' => $param['app_private_key'] ?? null,
                        'app_public_cert' => $param['app_cert_public_key'] ?? null,
                        'app_public_cert_path' => $param['app_cert_public_key_path'] ?? null,
                        'alipay_public_cert' => $param['alipay_cert_public_key'] ?? null,
                        'alipay_public_cert_path' => $param['alipay_cert_public_key_path'] ?? null,
                        'alipay_root_cert' => $param['alipay_root_cert'] ?? null,
                        'alipay_root_cert_path' => $param['alipay_root_cert_path'] ?? null,
                    ];
                    
                    SubjectCert::updateOrCreate(
                        ['subject_id' => $subject->id],
                        $certData
                    );
                } else {
                    // 新增
                    // 检查APPID是否重复
                    $exists = Subject::where('alipay_app_id', $param['alipay_app_id'])->exists();
                    if ($exists) {
                        throw new MyBusinessException('该APPID已存在');
                    }
                    
                    // 分账模式和分账比例处理
                    $royaltyType = $param['royalty_type'] ?? 'none';
                    $royaltyMode = null;
                    $royaltyRate = null;
                    if (in_array($royaltyType, ['single', 'merchant'])) {
                        $royaltyMode = $param['royalty_mode'] ?? 'normal';
                        $royaltyRate = $param['royalty_rate'] ?? null;
                    }
                    
                    // 创建主体基本信息
                    $subject = Subject::create([
                        'agent_id' => $param['agent_id'],
                        'royalty_type' => $royaltyType,
                        'royalty_mode' => $royaltyMode,
                        'royalty_rate' => $royaltyRate,
                        'allow_remote_order' => $param['allow_remote_order'] ?? 1,
                        'verify_device' => $param['verify_device'] ?? 0,
                        'scan_pay_enabled' => $param['scan_pay_enabled'] ?? 1,
                        'transaction_limit' => $param['transaction_limit'] ?? null,
                        'amount_min' => $param['amount_min'] ?? null,
                        'amount_max' => $param['amount_max'] ?? null,
                        'company_name' => $param['company_name'],
                        'alipay_app_id' => $param['alipay_app_id'],
                        'alipay_pid' => $param['alipay_pid'] ?? null,
                        'status' => $param['status'] ?? 1,
                    ]);
                    
                    // 创建证书信息
                    SubjectCert::create([
                        'subject_id' => $subject->id,
                        'app_private_key' => $param['app_private_key'] ?? null,
                        'app_public_cert' => $param['app_cert_public_key'] ?? null,
                        'app_public_cert_path' => $param['app_cert_public_key_path'] ?? null,
                        'alipay_public_cert' => $param['alipay_cert_public_key'] ?? null,
                        'alipay_public_cert_path' => $param['alipay_cert_public_key_path'] ?? null,
                        'alipay_root_cert' => $param['alipay_root_cert'] ?? null,
                        'alipay_root_cert_path' => $param['alipay_root_cert_path'] ?? null,
                    ]);
                }
                
                Db::commit();
                return success([], $isEdit ? '编辑成功' : '创建成功');
            } catch (\Exception $e) {
                Db::rollBack();
                throw $e;
            }
        } catch (MyBusinessException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new MyBusinessException('系统异常：' . $e->getMessage());
        }
    }

    /**
     * 删除
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request): Response
    {
        $ids = $request->post('ids');
        $userData = $request->userData;
        $isAgent = $userData['is_agent'] ?? false;
        $agentId = $userData['agent_id'] ?? null;

        try {
            if (empty($ids) || !is_array($ids)) {
                throw new MyBusinessException('参数错误，缺少要删除的ID列表');
            }

            $query = Subject::whereIn('id', $ids);
            
            // 代理商只能删除自己的主体
            if ($isAgent && $agentId) {
                $query->where('agent_id', $agentId);
            }

            $subjects = $query->get();
            
            if ($subjects->isEmpty()) {
                throw new MyBusinessException('未找到对应的主体记录或无权删除');
            }

            // 开启事务
            Db::beginTransaction();
            
            try {
                // 删除证书信息
                SubjectCert::whereIn('subject_id', $ids)->delete();
                
                // 删除主体信息
                Subject::whereIn('id', $ids)->delete();
                
                Db::commit();
                return success([], '删除成功');
            } catch (\Exception $e) {
                Db::rollBack();
                throw $e;
            }
        } catch (\Throwable $e) {
            throw new MyBusinessException('系统异常：' . $e->getMessage());
        }
    }

    /**
     * 状态切换
     * @param Request $request
     * @return Response
     */
    public function switch(Request $request): Response
    {
        $id = $request->post('id');
        $userData = $request->userData;
        $isAgent = $userData['is_agent'] ?? false;
        $agentId = $userData['agent_id'] ?? null;

        if (!$id) {
            throw new MyBusinessException('参数错误');
        }

        $query = Subject::query();
        
        // 代理商只能切换自己的主体状态
        if ($isAgent && $agentId) {
            $query->where('agent_id', $agentId);
        }

        $subject = $query->find($id);
        if (!$subject) {
            throw new MyBusinessException('主体不存在或无权操作');
        }

        $subject->toggleStatus();

        return success([], '切换成功');
    }

    /**
     * 获取代理商列表（给管理员选择用）
     * @param Request $request
     * @return Response
     */
    public function getAgentList(Request $request): Response
    {
        $agents = Agent::where('status', Agent::STATUS_ENABLED)
            ->select(['id', 'agent_name'])
            ->get()
            ->toArray();

        return success($agents);
    }

    /**
     * 获取产品列表（根据代理商筛选）
     * @param Request $request
     * @return Response
     */
    public function getProductList(Request $request): Response
    {
        $userData = $request->userData;
        $isAgent = $userData['is_agent'] ?? false;
        $agentId = $userData['agent_id'] ?? null;

        $query = Product::with('paymentType')
            ->where('status', Product::STATUS_ENABLED);

        // 代理商只能看到自己的产品
        if ($isAgent && $agentId) {
            $query->where('agent_id', $agentId);
        }

        // 管理员可以通过agent_id参数筛选
        $requestAgentId = $request->get('agent_id');
        if (!$isAgent && $requestAgentId) {
            $query->where('agent_id', $requestAgentId);
        }

        $products = $query->select(['id', 'product_name', 'payment_type_id', 'agent_id'])
            ->get()
            ->toArray();

        return success($products);
    }

    /**
     * 查询主体余额
     * @param Request $request
     * @param int $id 主体ID
     * @return Response
     */
    public function queryBalance(Request $request, int $id): Response
    {
        $userData = $request->userData;
        $isAgent = $userData['is_agent'] ?? false;
        $agentId = $userData['agent_id'] ?? null;

        $query = Subject::with(['cert']);
        
        // 代理商只能查询自己的主体
        if ($isAgent && $agentId) {
            $query->where('agent_id', $agentId);
        }

        $subject = $query->find($id);
        
        if (!$subject) {
            throw new MyBusinessException('主体不存在或无权访问');
        }

        try {
            // 检查证书是否存在
            if (!$subject->cert) {
                throw new MyBusinessException('主体证书配置缺失，无法查询余额');
            }

            // 使用 PaymentFactory 获取支付配置（确保配置格式正确）
            // 查找支付宝支付类型
            $paymentType = \app\model\PaymentType::where('product_name', 'like', '%支付宝%')->first();
            
            if (!$paymentType) {
                // 如果找不到支付宝支付类型，创建一个临时的 PaymentType 对象
                // 或者直接构建配置（复制 PaymentFactory 的逻辑）
                $cert = $subject->cert;
                
                // 处理证书路径：优先使用文件路径，如果文件不存在则使用数据库中的证书内容创建临时文件
                $alipayCertPath = null;
                if (!empty($cert->alipay_public_cert_path)) {
                    $fullPath = base_path('public' . $cert->alipay_public_cert_path);
                    if (file_exists($fullPath)) {
                        $alipayCertPath = 'public' . $cert->alipay_public_cert_path;
                    }
                }
                if (!$alipayCertPath && !empty($cert->alipay_public_cert)) {
                    $tempDir = runtime_path() . '/certs';
                    if (!is_dir($tempDir)) {
                        mkdir($tempDir, 0755, true);
                    }
                    $tempFile = $tempDir . '/alipay_public_cert_' . $subject->id . '.crt';
                    file_put_contents($tempFile, $cert->alipay_public_cert);
                    $alipayCertPath = str_replace(base_path() . '/', '', $tempFile);
                }

                $alipayRootCertPath = null;
                if (!empty($cert->alipay_root_cert_path)) {
                    $fullPath = base_path('public' . $cert->alipay_root_cert_path);
                    if (file_exists($fullPath)) {
                        $alipayRootCertPath = 'public' . $cert->alipay_root_cert_path;
                    }
                }
                if (!$alipayRootCertPath && !empty($cert->alipay_root_cert)) {
                    $tempDir = runtime_path() . '/certs';
                    if (!is_dir($tempDir)) {
                        mkdir($tempDir, 0755, true);
                    }
                    $tempFile = $tempDir . '/alipay_root_cert_' . $subject->id . '.crt';
                    file_put_contents($tempFile, $cert->alipay_root_cert);
                    $alipayRootCertPath = str_replace(base_path() . '/', '', $tempFile);
                }

                $appCertPath = null;
                if (!empty($cert->app_public_cert_path)) {
                    $fullPath = base_path('public' . $cert->app_public_cert_path);
                    if (file_exists($fullPath)) {
                        $appCertPath = 'public' . $cert->app_public_cert_path;
                    }
                }
                if (!$appCertPath && !empty($cert->app_public_cert)) {
                    $tempDir = runtime_path() . '/certs';
                    if (!is_dir($tempDir)) {
                        mkdir($tempDir, 0755, true);
                    }
                    $tempFile = $tempDir . '/app_public_cert_' . $subject->id . '.crt';
                    file_put_contents($tempFile, $cert->app_public_cert);
                    $appCertPath = str_replace(base_path() . '/', '', $tempFile);
                }

                // 构建支付配置（使用正确的字段名）
                $appUrl = env('APP_URL', 'http://127.0.0.1:8787');
                $paymentInfo = [
                    'appid' => $subject->alipay_app_id,
                    'AppPrivateKey' => $cert->app_private_key,
                    'alipayCertPublicKey' => $alipayCertPath,
                    'alipayRootCert' => $alipayRootCertPath,
                    'appCertPublicKey' => $appCertPath,
                    'notify_url' => rtrim($appUrl, '/') . '/api/v1/payment/notify/alipay',
                    'sandbox' => false,
                ];
            } else {
                // 使用 PaymentFactory 获取配置（推荐方式）
                $paymentInfo = \app\service\payment\PaymentFactory::getPaymentConfig($subject, $paymentType);
            }

            // 调用支付宝账户余额查询接口
            $config = \app\service\alipay\AlipayConfig::getConfig($paymentInfo);
            
            // execute 方法需要3个参数：API名称、文本参数、业务参数
            // 使用 alipay.data.bill.balance.query 接口查询普通商户余额
            $textParams = []; // 文本参数（可选参数，如app_auth_token等）
            
            // 构建业务参数
            // alipay.data.bill.balance.query 接口参数说明：
            // - bill_date: 账单日期（格式：yyyyMMdd），查询指定日期的余额
            // - bill_user_id: 账单归属用户ID（可选）
            $billDate = date('Ymd'); // 查询今天的余额
            
            $bizParams = [
                'bill_date' => $billDate
            ];
            
            // 如果主体有 alipay_pid，可以添加 bill_user_id 参数
            if (!empty($subject->alipay_pid)) {
                $bizParams['bill_user_id'] = $subject->alipay_pid;
            }
            
            $result = \Alipay\EasySDK\Kernel\Factory::setOptions($config)
                ->util()
                ->generic()
                ->execute('alipay.data.bill.balance.query', $textParams, $bizParams);

            // 检查响应body是否存在
            $bodyContent = null;
            if (property_exists($result, 'body') && $result->body !== null) {
                $bodyContent = $result->body;
            } elseif (property_exists($result, 'httpBody') && $result->httpBody !== null) {
                $bodyContent = $result->httpBody;
            } else {
                throw new MyBusinessException('余额查询响应为空');
            }

            if (empty($bodyContent)) {
                throw new MyBusinessException('余额查询响应为空');
            }

            $response = json_decode($bodyContent, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new MyBusinessException('余额查询响应解析失败: ' . json_last_error_msg());
            }

            // alipay.data.bill.balance.query 接口的响应格式
            if (!isset($response['alipay_data_bill_balance_query_response'])) {
                throw new MyBusinessException('余额查询响应格式错误，未找到 alipay_data_bill_balance_query_response');
            }

            $balanceResponse = $response['alipay_data_bill_balance_query_response'];

            if ($balanceResponse['code'] !== '10000') {
                $errorMsg = $balanceResponse['msg'] ?? '未知错误';
                $subMsg = $balanceResponse['sub_msg'] ?? '';
                throw new MyBusinessException("余额查询失败: {$errorMsg}" . ($subMsg ? " - {$subMsg}" : ""));
            }

            // 提取余额信息
            // alipay.data.bill.balance.query 返回的字段可能不同，需要根据实际响应调整
            // 总余额 = 可用余额 + 冻结余额
            $availableAmount = $balanceResponse['available_amount'] ?? $balanceResponse['balance'] ?? '0.00';
            $freezeAmount = $balanceResponse['freeze_amount'] ?? $balanceResponse['frozen_amount'] ?? '0.00';
            $totalAmount = $balanceResponse['total_amount'] ?? '0.00';
            
            // 如果没有总余额字段，计算总余额 = 可用余额 + 冻结余额
            if ($totalAmount == '0.00' || empty($totalAmount)) {
                $totalAmount = bcadd($availableAmount, $freezeAmount, 2);
            }
            
            $accountType = $balanceResponse['account_type'] ?? '';

            return success([
                'total_amount' => $totalAmount,        // 总余额
                'available_amount' => $availableAmount, // 可用余额
                'freeze_amount' => $freezeAmount,      // 冻结余额
                'account_type' => $accountType,
                'query_time' => date('Y-m-d H:i:s'),
                'bill_date' => $billDate
            ]);

        } catch (MyBusinessException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \support\Log::error('余额查询失败', [
                'subject_id' => $id,
                'error' => $e->getMessage()
            ]);
            throw new MyBusinessException('余额查询失败: ' . $e->getMessage());
        }
    }
}


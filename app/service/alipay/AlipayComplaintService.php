<?php

namespace app\service\alipay;

use Alipay\EasySDK\Kernel\Factory;
use app\model\Subject;
use app\model\SubjectCert;
use Exception;
use support\Log;

/**
 * 支付宝投诉服务类
 */
class AlipayComplaintService
{
    /**
     * 查询支付宝投诉详情
     * 
     * @param Subject $subject 主体
     * @param string $alipayTaskId 支付宝投诉任务ID（TaskId）
     * @return array 投诉详情
     * @throws Exception
     */
    public static function queryComplaintDetail(Subject $subject, string $alipayTaskId): array
    {
        try {
            // 1. 获取支付配置
            $paymentConfig = self::getPaymentConfig($subject);
            if (!$paymentConfig) {
                throw new Exception('获取支付配置失败');
            }

            // 2. 初始化支付宝客户端
            $config = AlipayConfig::getConfig($paymentConfig);
            
            // 3. 调用支付宝投诉详情查询API
            // 注意：支付宝API需要的是投诉主表的ID（ComplainId），不是TaskId
            // 但根据Go代码，TaskId实际上就是ComplainId
            $complainId = intval($alipayTaskId);
            
            Log::info('查询支付宝投诉详情', [
                'subject_id' => $subject->id,
                'alipay_task_id' => $alipayTaskId,
                'complain_id' => $complainId
            ]);

            // 使用支付宝EasySDK查询投诉详情
            // 使用util()->generic()->execute方法调用通用API
            // execute方法需要3个参数：method, textParams, bizParams
            $textParams = []; // 文本参数（可选参数，如app_auth_token等）
            $bizParams = [
                'complain_id' => $complainId
            ];
            
            $result = Factory::setOptions($config)
                ->util()
                ->generic()
                ->execute('alipay.security.risk.complaint.info.query', $textParams, $bizParams);

            // 检查响应状态（使用响应对象的属性）
            if ($result->code !== '10000') {
                $errorMsg = $result->msg ?? '未知错误';
                $subMsg = $result->subCode ?? '';
                $subMsgDetail = $result->subMsg ?? '';
                throw new Exception("查询投诉失败: {$errorMsg}" . ($subMsg ? " (sub_code: {$subMsg})" : "") . ($subMsgDetail ? " - {$subMsgDetail}" : ""));
            }

            // 解析响应体（httpBody包含原始JSON字符串）
            if (empty($result->httpBody)) {
                throw new Exception('支付宝投诉查询响应为空');
            }

            $response = json_decode($result->httpBody, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('支付宝投诉查询响应解析失败: ' . json_last_error_msg());
            }
            
            if (!isset($response['alipay_security_risk_complaint_info_query_response'])) {
                throw new Exception('支付宝投诉查询响应格式错误');
            }

            $complaintResponse = $response['alipay_security_risk_complaint_info_query_response'];

            // 解析投诉详情
            // 注意：根据支付宝API文档，响应字段可能有所不同
            // 支付宝API返回的订单列表字段名可能是 complaint_trade_info_list 或 trade_list
            $tradeList = $complaintResponse['complaint_trade_info_list'] ?? $complaintResponse['trade_list'] ?? [];
            
            // 如果订单列表是字符串（JSON），需要解析
            if (is_string($tradeList)) {
                $tradeList = json_decode($tradeList, true) ?? [];
            }
            
            $complaintData = [
                'task_id' => $complaintResponse['task_id'] ?? '',
                'status' => $complaintResponse['status'] ?? '',
                'complain_content' => $complaintResponse['complain_content'] ?? '',
                'opposite_pid' => $complaintResponse['opposite_pid'] ?? '',
                'opposite_name' => $complaintResponse['opposite_name'] ?? '',
                'gmt_create' => $complaintResponse['gmt_complain'] ?? $complaintResponse['gmt_create'] ?? '',
                'gmt_process' => $complaintResponse['gmt_process'] ?? '',
                'trade_list' => $tradeList
            ];
            
            // 解析订单列表，确保每个订单都有退款状态和正确的字段名
            if (!empty($complaintData['trade_list']) && is_array($complaintData['trade_list'])) {
                foreach ($complaintData['trade_list'] as &$trade) {
                    // 标准化字段名：trade_no, out_trade_no, amount, complaint_amount
                    // 支付宝API可能返回不同的字段名，需要适配
                    $trade['trade_no'] = $trade['trade_no'] ?? $trade['TradeNo'] ?? '';
                    $trade['out_trade_no'] = $trade['out_trade_no'] ?? $trade['OutNo'] ?? $trade['out_no'] ?? '';
                    $trade['amount'] = isset($trade['amount']) ? floatval($trade['amount']) : (isset($trade['Amount']) ? floatval($trade['Amount']) : 0);
                    $trade['complaint_amount'] = isset($trade['complaint_amount']) ? floatval($trade['complaint_amount']) : (isset($trade['ComplaintAmount']) ? floatval($trade['ComplaintAmount']) : $trade['amount']);
                    
                    // 检查订单是否已退款
                    // 支付宝API可能返回refund_status或refund_amount字段
                    $trade['refund_status'] = $trade['refund_status'] ?? $trade['RefundStatus'] ?? '';
                    $trade['refund_amount'] = isset($trade['refund_amount']) ? floatval($trade['refund_amount']) : (isset($trade['RefundAmount']) ? floatval($trade['RefundAmount']) : 0);
                    $trade['is_refunded'] = !empty($trade['refund_status']) || $trade['refund_amount'] > 0;
                }
                unset($trade);
            }

            Log::info('支付宝投诉查询成功', [
                'subject_id' => $subject->id,
                'alipay_task_id' => $alipayTaskId,
                'status' => $complaintData['status'],
                'trade_count' => count($complaintData['trade_list'])
            ]);

            return [
                'success' => true,
                'data' => $complaintData
            ];

        } catch (Exception $e) {
            Log::error('查询支付宝投诉详情失败', [
                'subject_id' => $subject->id ?? 0,
                'alipay_task_id' => $alipayTaskId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * 获取支付配置
     * 
     * @param Subject $subject 主体
     * @return array|null 支付配置
     */
    private static function getPaymentConfig(Subject $subject): ?array
    {
        try {
            // 获取证书信息
            $cert = SubjectCert::where('subject_id', $subject->id)->first();
            if (!$cert) {
                Log::error('主体证书不存在', ['subject_id' => $subject->id]);
                return null;
            }

            // 构建支付配置（参考PaymentFactory::getPaymentConfig）
            // 处理证书路径：优先使用文件路径，如果文件不存在则使用数据库中的证书内容创建临时文件
            $alipayCertPath = self::getCertPath($cert->alipay_public_cert_path ?? $cert->alipay_cert_path, $cert->alipay_public_cert ?? '', 'alipay_public_cert');
            $alipayRootCertPath = self::getCertPath($cert->alipay_root_cert_path ?? $cert->root_cert_path, $cert->alipay_root_cert ?? '', 'alipay_root_cert');
            $appCertPath = self::getCertPath($cert->app_public_cert_path ?? $cert->app_cert_path, $cert->app_public_cert ?? '', 'app_public_cert');
            
            $paymentConfig = [
                'appid' => $subject->alipay_app_id,
                'AppPrivateKey' => $cert->app_private_key ?? $cert->private_key,
                'alipayCertPublicKey' => $alipayCertPath,
                'alipayRootCert' => $alipayRootCertPath,
                'appCertPublicKey' => $appCertPath,
                'notify_url' => config('app.url', '') . '/api/v1/payment/notify/alipay',
                'sandbox' => config('app.alipay_sandbox', false),
            ];

            return $paymentConfig;

        } catch (Exception $e) {
            Log::error('获取支付配置失败', [
                'subject_id' => $subject->id ?? 0,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 获取证书文件路径，如果文件不存在则从数据库内容创建临时文件
     * 
     * @param string|null $certPath 证书文件路径
     * @param string|null $certContent 证书内容（数据库存储）
     * @param string $certType 证书类型（用于错误提示）
     * @return string 证书文件路径
     * @throws Exception
     */
    private static function getCertPath(?string $certPath, ?string $certContent, string $certType): string
    {
        // 如果提供了文件路径，先检查文件是否存在
        if (!empty($certPath)) {
            $fullPath = base_path('public' . $certPath);
            if (file_exists($fullPath)) {
                return 'public' . $certPath;
            }
            
            // 文件不存在，记录警告日志
            Log::warning("证书文件不存在，尝试使用数据库中的证书内容", [
                'cert_type' => $certType,
                'file_path' => $fullPath
            ]);
        }

        // 如果文件不存在，尝试使用数据库中的证书内容
        if (!empty($certContent)) {
            // 创建临时文件
            $tempDir = runtime_path() . '/certs';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $tempFile = $tempDir . '/' . uniqid($certType . '_', true) . '.crt';
            if (file_put_contents($tempFile, $certContent) === false) {
                throw new Exception("无法创建临时证书文件: {$certType}");
            }

            // 返回相对于base_path的路径
            $relativePath = str_replace(base_path() . '/', '', $tempFile);
            
            Log::info("使用数据库证书内容创建临时文件", [
                'cert_type' => $certType,
                'temp_file' => $tempFile
            ]);

            return $relativePath;
        }

        // 既没有文件也没有内容
        throw new Exception("证书配置缺失: {$certType}（文件不存在且数据库中没有证书内容）");
    }

    /**
     * 完结支付宝投诉
     * 
     * @param Subject $subject 主体
     * @param int|string $alipayComplainId 支付宝投诉主表ID（complaint_list中的id，必填）
     * @param string $alipayTaskId 支付宝投诉任务ID（TaskId，用于日志）
     * @param string $processCode 处理结果码（必填，如：CONSENSUS_WITH_CLIENT, REFUND, RECTIFICATION_NO_REFUND等）
     * @param string $remark 处理备注内容（可选）
     * @return array 处理结果
     * @throws Exception
     */
    public static function finishComplaint(Subject $subject, $alipayComplainId, string $alipayTaskId = '', string $processCode = '', string $remark = ''): array
    {
        try {
            // 1. 获取支付配置
            $paymentConfig = self::getPaymentConfig($subject);
            if (!$paymentConfig) {
                throw new Exception('获取支付配置失败');
            }

            // 2. 初始化支付宝客户端
            $config = AlipayConfig::getConfig($paymentConfig);
            
            // 3. 调用支付宝完结投诉API
            // 支付宝API：alipay.security.risk.complaint.process.finish
            // 参数说明（根据SDK定义）：
            // - id_list: 投诉ID列表（必填，数组格式，使用complaint_list中的id字段）
            // - process_code: 处理结果码（必填，如：CONSENSUS_WITH_CLIENT, REFUND, RECTIFICATION_NO_REFUND等）
            // - remark: 处理备注内容（可选）
            // - img_file_list: 图片文件列表（可选）
            $complainId = intval($alipayComplainId);
            
            if ($complainId <= 0) {
                throw new Exception('支付宝投诉主表ID（alipay_complain_id）无效: ' . $alipayComplainId);
            }
            
            // 验证处理结果码
            if (empty($processCode)) {
                throw new Exception('处理结果码（process_code）不能为空，必填参数');
            }
            
            Log::info('完结支付宝投诉', [
                'subject_id' => $subject->id,
                'alipay_complain_id' => $complainId,
                'alipay_task_id' => $alipayTaskId,
                'process_code' => $processCode,
                'remark' => $remark,
                'api' => 'alipay.security.risk.complaint.process.finish',
                'note' => '使用complaint_list中的id字段（alipay_complain_id）调用完结投诉API，参数格式：id_list（数组），process_code（必填）'
            ]);

            // 使用支付宝EasySDK完结投诉
            $textParams = []; // 文本参数（可选参数，如app_auth_token等）
            $bizParams = [
                'id_list' => [$complainId], // 必须是数组格式
                'process_code' => $processCode, // 处理结果码（必填）
            ];
            
            // 如果有备注内容，添加到参数中
            if (!empty($remark)) {
                $bizParams['remark'] = $remark;
            }
            
            // 记录请求参数
            Log::info('完结支付宝投诉：请求参数', [
                'subject_id' => $subject->id,
                'alipay_complain_id' => $complainId,
                'alipay_task_id' => $alipayTaskId,
                'process_code' => $processCode,
                'remark' => $remark,
                'api' => 'alipay.security.risk.complaint.process.finish',
                'biz_params' => $bizParams,
                'text_params' => $textParams,
                'note' => 'id_list必须是数组格式，process_code是必填参数'
            ]);

            $result = Factory::setOptions($config)
                ->util()
                ->generic()
                ->execute('alipay.security.risk.complaint.process.finish', $textParams, $bizParams);

            // 记录完整响应
            Log::info('完结支付宝投诉：API响应', [
                'subject_id' => $subject->id,
                'alipay_complain_id' => $complainId,
                'alipay_task_id' => $alipayTaskId,
                'code' => $result->code ?? 'N/A',
                'msg' => $result->msg ?? 'N/A',
                'subCode' => $result->subCode ?? 'N/A',
                'subMsg' => $result->subMsg ?? 'N/A',
                'httpBody' => $result->httpBody ?? 'N/A',
                'httpStatusCode' => $result->httpStatusCode ?? 'N/A'
            ]);

            // 检查响应状态
            if ($result->code !== '10000') {
                $errorMsg = $result->msg ?? '未知错误';
                $subCode = $result->subCode ?? '';
                $subMsg = $result->subMsg ?? '';
                
                // 记录详细错误信息
                Log::error('完结支付宝投诉：API返回错误', [
                    'subject_id' => $subject->id,
                    'alipay_complain_id' => $complainId,
                    'alipay_task_id' => $alipayTaskId,
                    'code' => $result->code,
                    'msg' => $errorMsg,
                    'sub_code' => $subCode,
                    'sub_msg' => $subMsg,
                    'http_body' => $result->httpBody ?? '',
                    'http_status_code' => $result->httpStatusCode ?? '',
                    'full_result' => json_encode($result, JSON_UNESCAPED_UNICODE)
                ]);
                
                $errorMessage = "完结投诉失败: {$errorMsg}";
                if ($subCode) {
                    $errorMessage .= " (sub_code: {$subCode})";
                }
                if ($subMsg) {
                    $errorMessage .= " - {$subMsg}";
                }
                
                throw new Exception($errorMessage);
            }

            // 解析响应体
            if (empty($result->httpBody)) {
                Log::error('完结支付宝投诉：响应体为空', [
                    'subject_id' => $subject->id,
                    'alipay_complain_id' => $complainId,
                    'alipay_task_id' => $alipayTaskId,
                    'code' => $result->code ?? 'N/A',
                    'httpStatusCode' => $result->httpStatusCode ?? 'N/A'
                ]);
                throw new Exception('支付宝完结投诉响应为空');
            }

            $response = json_decode($result->httpBody, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('完结支付宝投诉：响应解析失败', [
                    'subject_id' => $subject->id,
                    'alipay_complain_id' => $complainId,
                    'alipay_task_id' => $alipayTaskId,
                    'json_error' => json_last_error_msg(),
                    'http_body' => $result->httpBody
                ]);
                throw new Exception('支付宝完结投诉响应解析失败: ' . json_last_error_msg());
            }
            
            // 记录解析后的响应
            Log::info('完结支付宝投诉：解析后的响应', [
                'subject_id' => $subject->id,
                'alipay_complain_id' => $complainId,
                'alipay_task_id' => $alipayTaskId,
                'response_keys' => array_keys($response),
                'full_response' => $response
            ]);
            
            // 响应字段名：alipay_security_risk_complaint_process_finish_response
            $finishResponse = null;
            if (isset($response['alipay_security_risk_complaint_process_finish_response'])) {
                $finishResponse = $response['alipay_security_risk_complaint_process_finish_response'];
            } elseif (isset($response['alipay_security_risk_complaint_finish_response'])) {
                // 兼容旧的响应格式
                $finishResponse = $response['alipay_security_risk_complaint_finish_response'];
                Log::warning('完结支付宝投诉：使用旧版响应格式', [
                    'subject_id' => $subject->id,
                    'alipay_complain_id' => $complainId,
                    'alipay_task_id' => $alipayTaskId
                ]);
            } else {
                // 记录所有可用的响应字段
                Log::error('完结支付宝投诉：响应格式错误', [
                    'subject_id' => $subject->id,
                    'alipay_complain_id' => $complainId,
                    'alipay_task_id' => $alipayTaskId,
                    'response_keys' => array_keys($response),
                    'full_response' => $response
                ]);
                throw new Exception('支付宝完结投诉响应格式错误，未找到响应字段。响应字段: ' . implode(', ', array_keys($response)));
            }
            
            // 检查响应状态
            if (isset($finishResponse['code']) && $finishResponse['code'] !== '10000') {
                $errorMsg = $finishResponse['msg'] ?? '未知错误';
                $subCode = $finishResponse['sub_code'] ?? '';
                $subMsg = $finishResponse['sub_msg'] ?? '';
                
                // 记录详细错误信息
                Log::error('完结支付宝投诉：响应体中的错误', [
                    'subject_id' => $subject->id,
                    'alipay_complain_id' => $complainId,
                    'alipay_task_id' => $alipayTaskId,
                    'code' => $finishResponse['code'],
                    'msg' => $errorMsg,
                    'sub_code' => $subCode,
                    'sub_msg' => $subMsg,
                    'full_finish_response' => $finishResponse
                ]);
                
                $errorMessage = "完结投诉失败: {$errorMsg}";
                if ($subCode) {
                    $errorMessage .= " (sub_code: {$subCode})";
                }
                if ($subMsg) {
                    $errorMessage .= " - {$subMsg}";
                }
                
                throw new Exception($errorMessage);
            }

            // 检查 complaint_process_success 字段，这是支付宝返回的投诉处理是否成功的标志
            $complaintProcessSuccess = $finishResponse['complaint_process_success'] ?? false;
            
            Log::info('支付宝投诉完结成功', [
                'subject_id' => $subject->id,
                'alipay_complain_id' => $complainId,
                'alipay_task_id' => $alipayTaskId,
                'complaint_process_success' => $complaintProcessSuccess,
                'response' => $finishResponse
            ]);

            // 如果 complaint_process_success 为 false，说明投诉没有真正处理成功
            if (!$complaintProcessSuccess) {
                Log::error('支付宝投诉完结失败：complaint_process_success为false', [
                    'subject_id' => $subject->id,
                    'alipay_complain_id' => $complainId,
                    'alipay_task_id' => $alipayTaskId,
                    'complaint_process_success' => $complaintProcessSuccess,
                    'response' => $finishResponse
                ]);
                throw new Exception('支付宝投诉处理失败：complaint_process_success为false，投诉未真正处理完成');
            }

            return [
                'success' => true,
                'data' => $finishResponse,
                'complaint_process_success' => $complaintProcessSuccess
            ];

        } catch (Exception $e) {
            Log::error('完结支付宝投诉失败', [
                'subject_id' => $subject->id ?? 0,
                'alipay_complain_id' => $alipayComplainId ?? 0,
                'alipay_task_id' => $alipayTaskId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}


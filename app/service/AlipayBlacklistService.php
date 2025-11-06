<?php

namespace app\service;

use app\model\AlipayBlacklist;
use support\Log;
use Exception;

/**
 * 支付宝黑名单服务类
 * 
 * 功能：对支付宝用户的风险行为进行记录与追踪
 * 通过支付宝用户ID、设备码、IP地址三要素建立黑名单体系
 */
class AlipayBlacklistService
{
    /**
     * 添加或更新黑名单记录
     * 
     * @param string $alipayUserId 支付宝用户ID
     * @param string|null $deviceCode 设备码
     * @param string|null $ipAddress IP地址
     * @param string|null $remark 备注信息
     * @return array 返回处理结果 ['action' => 'insert|update', 'id' => xxx, 'message' => '...']
     * @throws Exception
     */
    public function addToBlacklist(string $alipayUserId, ?string $deviceCode = null, ?string $ipAddress = null, ?string $remark = null): array
    {
        try {
            Log::info('开始处理黑名单逻辑', [
                'alipay_user_id' => $alipayUserId,
                'device_code' => $deviceCode,
                'ip_address' => $ipAddress
            ]);

            // 规则①：黑名单中不存在该 alipay_user_id
            $existingRecords = AlipayBlacklist::where('alipay_user_id', $alipayUserId)->get();
            
            if ($existingRecords->isEmpty()) {
                // 新增一条黑名单记录
                $blacklist = $this->createBlacklistRecord($alipayUserId, $deviceCode, $ipAddress, $remark);
                
                Log::info('黑名单规则①：用户首次命中风险，新增记录', [
                    'id' => $blacklist->id,
                    'alipay_user_id' => $alipayUserId
                ]);
                
                return [
                    'action' => 'insert',
                    'id' => $blacklist->id,
                    'message' => '用户首次命中风险，已新增黑名单记录'
                ];
            }

            // 规则②：黑名单中存在该 alipay_user_id，但其 device_code、ip_address 为空
            $incompleteRecord = $existingRecords->first(function ($record) {
                return empty($record->device_code) || empty($record->ip_address);
            });
            
            if ($incompleteRecord) {
                // 更新该记录，补全 device_code 与 ip_address
                $this->updateBlacklistRecord($incompleteRecord, $deviceCode, $ipAddress, $remark);
                
                Log::info('黑名单规则②：补全不完整记录', [
                    'id' => $incompleteRecord->id,
                    'alipay_user_id' => $alipayUserId
                ]);
                
                return [
                    'action' => 'update',
                    'id' => $incompleteRecord->id,
                    'message' => '已补全不完整的黑名单记录'
                ];
            }

            // 规则③：黑名单中存在相同 alipay_user_id、且 device_code 与 ip_address 均一致
            $exactMatch = $existingRecords->first(function ($record) use ($deviceCode, $ipAddress) {
                return $record->device_code === $deviceCode && $record->ip_address === $ipAddress;
            });
            
            if ($exactMatch) {
                // 不新增记录，更新 updated_at 和计数字段
                $exactMatch->risk_count = ($exactMatch->risk_count ?? 0) + 1;
                $exactMatch->last_risk_time = now();
                if ($remark) {
                    $exactMatch->remark = $remark;
                }
                $exactMatch->save();
                
                Log::info('黑名单规则③：同一设备重复触发，更新计数', [
                    'id' => $exactMatch->id,
                    'risk_count' => $exactMatch->risk_count
                ]);
                
                return [
                    'action' => 'update',
                    'id' => $exactMatch->id,
                    'message' => '同一设备重复触发，已更新风险次数'
                ];
            }

            // 规则④：黑名单中存在该 alipay_user_id，但本次出现新的 device_code 或新的 ip_address
            // 用户更换设备 / IP 再次触发风险，新增一条黑名单记录
            $blacklist = $this->createBlacklistRecord($alipayUserId, $deviceCode, $ipAddress, $remark);
            
            Log::info('黑名单规则④：用户更换设备/IP再次触发风险，新增记录', [
                'id' => $blacklist->id,
                'alipay_user_id' => $alipayUserId,
                'new_device_code' => $deviceCode,
                'new_ip_address' => $ipAddress
            ]);
            
            return [
                'action' => 'insert',
                'id' => $blacklist->id,
                'message' => '用户更换设备/IP再次触发风险，已新增黑名单记录'
            ];

        } catch (Exception $e) {
            Log::error('黑名单处理异常', [
                'alipay_user_id' => $alipayUserId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * 创建黑名单记录
     * 
     * @param string $alipayUserId
     * @param string|null $deviceCode
     * @param string|null $ipAddress
     * @param string|null $remark
     * @return AlipayBlacklist
     */
    private function createBlacklistRecord(string $alipayUserId, ?string $deviceCode, ?string $ipAddress, ?string $remark): AlipayBlacklist
    {
        return AlipayBlacklist::create([
            'alipay_user_id' => $alipayUserId,
            'device_code' => $deviceCode,
            'ip_address' => $ipAddress,
            'risk_count' => 1,
            'last_risk_time' => now(),
            'remark' => $remark
        ]);
    }

    /**
     * 更新黑名单记录（补全信息）
     * 
     * @param AlipayBlacklist $record
     * @param string|null $deviceCode
     * @param string|null $ipAddress
     * @param string|null $remark
     * @return void
     */
    private function updateBlacklistRecord(AlipayBlacklist $record, ?string $deviceCode, ?string $ipAddress, ?string $remark): void
    {
        if ($deviceCode && empty($record->device_code)) {
            $record->device_code = $deviceCode;
        }
        if ($ipAddress && empty($record->ip_address)) {
            $record->ip_address = $ipAddress;
        }
        if ($remark) {
            $record->remark = $remark;
        }
        $record->risk_count = ($record->risk_count ?? 0) + 1;
        $record->last_risk_time = now();
        $record->save();
    }

    /**
     * 检查用户是否在黑名单中
     * 
     * @param string $alipayUserId 支付宝用户ID
     * @param string|null $deviceCode 设备码
     * @param string|null $ipAddress IP地址
     * @return bool
     */
    public function isInBlacklist(string $alipayUserId, ?string $deviceCode = null, ?string $ipAddress = null): bool
    {
        $query = AlipayBlacklist::where('alipay_user_id', $alipayUserId);
        
        // 如果提供了设备码或IP，进行精确匹配
        if ($deviceCode) {
            $query->where('device_code', $deviceCode);
        }
        if ($ipAddress) {
            $query->where('ip_address', $ipAddress);
        }
        
        return $query->exists();
    }

    /**
     * 检查设备或IP是否在黑名单中（规则⑤）
     * 
     * @param string|null $deviceCode 设备码
     * @param string|null $ipAddress IP地址
     * @return bool
     */
    public function isDeviceOrIpInBlacklist(?string $deviceCode = null, ?string $ipAddress = null): bool
    {
        if (!$deviceCode && !$ipAddress) {
            return false;
        }

        $query = AlipayBlacklist::query();
        
        if ($deviceCode && $ipAddress) {
            // 检查设备码或IP是否存在
            $query->where(function ($q) use ($deviceCode, $ipAddress) {
                $q->where('device_code', $deviceCode)
                  ->orWhere('ip_address', $ipAddress);
            });
        } elseif ($deviceCode) {
            $query->where('device_code', $deviceCode);
        } else {
            $query->where('ip_address', $ipAddress);
        }
        
        return $query->exists();
    }

    /**
     * 获取用户的所有黑名单记录
     * 
     * @param string $alipayUserId 支付宝用户ID
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getUserBlacklistRecords(string $alipayUserId)
    {
        return AlipayBlacklist::where('alipay_user_id', $alipayUserId)
            ->orderBy('last_risk_time', 'desc')
            ->get();
    }

    /**
     * 从黑名单中移除记录
     * 
     * @param int $id 黑名单记录ID
     * @return bool
     */
    public function removeFromBlacklist(int $id): bool
    {
        $record = AlipayBlacklist::find($id);
        
        if (!$record) {
            return false;
        }
        
        Log::info('从黑名单中移除记录', [
            'id' => $id,
            'alipay_user_id' => $record->alipay_user_id
        ]);
        
        return $record->delete();
    }

    /**
     * 批量检查（综合判断）
     * 
     * @param string $alipayUserId 支付宝用户ID
     * @param string|null $deviceCode 设备码
     * @param string|null $ipAddress IP地址
     * @return array 返回详细的黑名单信息
     */
    public function checkBlacklist(string $alipayUserId, ?string $deviceCode = null, ?string $ipAddress = null): array
    {
        $result = [
            'is_blacklisted' => false,
            'user_in_blacklist' => false,
            'device_in_blacklist' => false,
            'ip_in_blacklist' => false,
            'records' => []
        ];

        // 检查用户是否在黑名单
        $userRecords = AlipayBlacklist::where('alipay_user_id', $alipayUserId)->get();
        if ($userRecords->isNotEmpty()) {
            $result['user_in_blacklist'] = true;
            $result['is_blacklisted'] = true;
            $result['records'] = $userRecords->toArray();
        }

        // 检查设备码是否在黑名单
        if ($deviceCode) {
            $deviceExists = AlipayBlacklist::where('device_code', $deviceCode)->exists();
            if ($deviceExists) {
                $result['device_in_blacklist'] = true;
                $result['is_blacklisted'] = true;
            }
        }

        // 检查IP是否在黑名单
        if ($ipAddress) {
            $ipExists = AlipayBlacklist::where('ip_address', $ipAddress)->exists();
            if ($ipExists) {
                $result['ip_in_blacklist'] = true;
                $result['is_blacklisted'] = true;
            }
        }

        return $result;
    }
}


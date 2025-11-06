<?php

namespace app\service\robot\templates;

/**
 * 黑名单通知模板
 */
class BlacklistTemplate
{
    /**
     * 渲染黑名单通知消息
     * 
     * @param array $data 黑名单数据
     *   - action: insert|update (操作类型)
     *   - alipay_user_id: 支付宝用户ID
     *   - device_code: 设备码
     *   - ip_address: IP地址
     *   - risk_count: 风险次数
     *   - remark: 备注
     *   - message: 消息内容
     * @return string
     */
    public function render(array $data): string
    {
        $action = $data['action'] ?? 'insert';
        $alipayUserId = htmlspecialchars($data['alipay_user_id'] ?? '', ENT_QUOTES, 'UTF-8');
        $deviceCode = htmlspecialchars($data['device_code'] ?? '未知', ENT_QUOTES, 'UTF-8');
        $ipAddress = htmlspecialchars($data['ip_address'] ?? '未知', ENT_QUOTES, 'UTF-8');
        $riskCount = (int)($data['risk_count'] ?? 1);
        $remark = htmlspecialchars($data['remark'] ?? '无', ENT_QUOTES, 'UTF-8');
        $message = htmlspecialchars($data['message'] ?? '', ENT_QUOTES, 'UTF-8');
        
        // 根据操作类型选择图标和标题
        if ($action === 'insert') {
            $icon = '🚨';
            $title = '新用户加入黑名单';
            $actionText = '首次加入';
        } else {
            $icon = '⚠️';
            $title = '黑名单用户再次触发';
            $actionText = '重复触发';
        }
        
        // 风险等级
        $riskLevel = $this->getRiskLevel($riskCount);
        
        $html = <<<HTML
{$icon} <b>{$title}</b>

━━━━━━━━━━━━━━━━━━━━━

📱 <b>支付宝用户ID：</b>
<code>{$alipayUserId}</code>

💻 <b>设备码：</b>
<code>{$deviceCode}</code>

🌐 <b>IP地址：</b>
<code>{$ipAddress}</code>

⚠️ <b>风险次数：</b>{$riskCount} 次 {$riskLevel}

📝 <b>备注信息：</b>
{$remark}

🔔 <b>触发类型：</b>{$actionText}

⏰ <b>触发时间：</b>
HTML
. date('Y-m-d H:i:s') .
<<<HTML


💬 <b>详细信息：</b>
{$message}

━━━━━━━━━━━━━━━━━━━━━
HTML;

        return $html;
    }

    /**
     * 获取风险等级标识
     * @param int $count
     * @return string
     */
    private function getRiskLevel(int $count): string
    {
        if ($count >= 10) {
            return '🔴 极高风险';
        } elseif ($count >= 5) {
            return '🟠 高风险';
        } elseif ($count >= 3) {
            return '🟡 中风险';
        } else {
            return '🟢 低风险';
        }
    }
}

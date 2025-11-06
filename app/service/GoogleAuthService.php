<?php

namespace app\service;

use app\model\SystemConfig;
use app\model\Admin;
use support\Db;

class GoogleAuthService
{
    /**
     * 生成谷歌验证码二维码
     */
    public function generateQrCode(int $userId): array
    {
        // 生成随机密钥
        $secret = $this->generateSecret();
        
        // 获取用户信息
        $admin = Admin::find($userId);
        if (!$admin) {
            throw new \Exception('用户不存在');
        }
        
        // 生成二维码内容
        $issuer = '百亿三方支付管理系统';
        $accountName = $admin->username . '@' . $issuer;
        $qrCodeUrl = $this->generateQrCodeUrl($secret, $accountName, $issuer);
        
        return [
            'secret' => $secret,
            'qr_code_url' => $qrCodeUrl,
            'account_name' => $accountName,
            'issuer' => $issuer
        ];
    }

    /**
     * 验证谷歌验证码
     */
    public function verifyCode(string $secret, string $code): bool
    {
        $googleAuthenticator = new \Sonata\GoogleAuthenticator\GoogleAuthenticator();
        return $googleAuthenticator->checkCode($secret, $code);
    }

    /**
     * 保存密钥到数据库
     */
    public function saveSecret(int $userId, string $secret): void
    {
        try {
            Db::beginTransaction();
            
            // 删除旧的密钥
            SystemConfig::where('config_key', 'google_2fa_secret')
                ->where('merchant_id', $userId)
                ->delete();
            
            // 保存新密钥
            $config = new SystemConfig();
            $config->config_key = 'google_2fa_secret';
            $config->config_value = $secret;
            $config->merchant_id = $userId;
            $config->scope = 'system';
            $config->save();
            
            Db::commit();
        } catch (\Exception $e) {
            Db::rollBack();
            throw new \Exception('保存密钥失败：' . $e->getMessage());
        }
    }

    /**
     * 生成随机密钥
     */
    private function generateSecret(): string
    {
        $googleAuthenticator = new \Sonata\GoogleAuthenticator\GoogleAuthenticator();
        return $googleAuthenticator->generateSecret();
    }

    /**
     * 生成二维码URL
     */
    private function generateQrCodeUrl(string $secret, string $accountName, string $issuer): string
    {
        $url = sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s',
            urlencode($accountName),
            $secret,
            urlencode($issuer)
        );
        
        // 生成二维码图片URL
        $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($url);
        
        return $qrCodeUrl;
    }
}

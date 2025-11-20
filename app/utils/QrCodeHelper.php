<?php

namespace app\utils;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Exception;
use support\Log;

/**
 * 二维码生成工具类
 */
class QrCodeHelper
{
    /**
     * 生成二维码并返回Base64图片
     * 
     * @param string $data 二维码内容
     * @param int $size 二维码尺寸（默认300）
     * @param int $margin 边距（默认10）
     * @return string Base64编码的图片数据（包含data:image/png;base64,前缀）
     * @throws Exception
     */
    public static function generateBase64(string $data, int $size = 300, int $margin = 10): string
    {
        try {
            // 使用新版本API：使用命名参数构造Builder
            $builder = new Builder(
                writer: new PngWriter(),
                writerOptions: [],
                validateResult: false,
                data: $data,
                encoding: new Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::High,
                size: $size,
                margin: $margin,
                roundBlockSizeMode: RoundBlockSizeMode::Margin
            );

            // 调用build()方法生成结果
            $result = $builder->build();

            // 获取图片数据
            $imageData = $result->getString();
            
            // 转换为Base64
            $base64 = base64_encode($imageData);
            
            // 返回包含data URI前缀的完整字符串
            return 'data:image/png;base64,' . $base64;
            
        } catch (Exception $e) {
            Log::error('二维码生成失败', [
                'data_length' => strlen($data),
                'size' => $size,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new Exception('二维码生成失败: ' . $e->getMessage());
        }
    }

    /**
     * 生成二维码并保存为文件
     * 
     * @param string $data 二维码内容
     * @param string $savePath 保存路径（相对于public目录）
     * @param int $size 二维码尺寸（默认300）
     * @param int $margin 边距（默认10）
     * @return string 返回图片的访问URL
     * @throws Exception
     */
    public static function generateFile(string $data, string $savePath, int $size = 300, int $margin = 10): string
    {
        try {
            // 确保目录存在
            $fullPath = base_path('public/' . ltrim($savePath, '/'));
            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // 使用新版本API：使用命名参数构造Builder
            $builder = new Builder(
                writer: new PngWriter(),
                writerOptions: [],
                validateResult: false,
                data: $data,
                encoding: new Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::High,
                size: $size,
                margin: $margin,
                roundBlockSizeMode: RoundBlockSizeMode::Margin
            );

            // 调用build()方法生成结果
            $result = $builder->build();

            // 保存文件
            $result->saveToFile($fullPath);
            
            // 返回访问URL（移除public前缀）
            $url = '/' . ltrim($savePath, '/');
            
            Log::info('二维码文件生成成功', [
                'save_path' => $savePath,
                'url' => $url,
                'data_length' => strlen($data)
            ]);
            
            return $url;
            
        } catch (Exception $e) {
            Log::error('二维码文件生成失败', [
                'save_path' => $savePath,
                'data_length' => strlen($data),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new Exception('二维码文件生成失败: ' . $e->getMessage());
        }
    }

    /**
     * 为订单生成支付二维码（Base64格式）
     * 
     * @param string $paymentUrl 支付URL
     * @param int $size 二维码尺寸（默认300）
     * @return string Base64编码的图片数据
     * @throws Exception
     */
    public static function generateOrderQrCode(string $paymentUrl, int $size = 300): string
    {
        return self::generateBase64($paymentUrl, $size);
    }

    /**
     * 为订单生成支付二维码（文件格式）
     * 
     * @param string $paymentUrl 支付URL
     * @param string $orderNo 订单号（用于生成文件名）
     * @param int $size 二维码尺寸（默认300）
     * @return string 图片访问URL
     * @throws Exception
     */
    public static function generateOrderQrCodeFile(string $paymentUrl, string $orderNo, int $size = 300): string
    {
        $savePath = 'uploads/qrcode/' . date('Y/m/d') . '/' . $orderNo . '.png';
        return self::generateFile($paymentUrl, $savePath, $size);
    }
}


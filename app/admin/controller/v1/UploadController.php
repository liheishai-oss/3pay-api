<?php

namespace app\admin\controller\v1;

use app\exception\MyBusinessException;
use support\Request;
use support\Response;

/**
 * 文件上传控制器
 */
class UploadController
{
    /**
     * 上传证书文件
     * @param Request $request
     * @return Response
     */
    public function uploadCert(Request $request): Response
    {
        try {
            // 记录调试信息
            \support\Log::info('证书上传请求', [
                'files' => $request->file(),
                'post' => $request->post(),
                'headers' => $request->header(),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
                'max_file_uploads' => ini_get('max_file_uploads'),
                'memory_limit' => ini_get('memory_limit')
            ]);
            
            $file = $request->file('file');
            
            if (!$file) {
                \support\Log::error('未找到上传文件');
                throw new MyBusinessException('请选择要上传的文件');
            }

            // 记录文件信息
            \support\Log::info('上传文件信息', [
                'upload_name' => $file->getUploadName(),
                'extension' => $file->getUploadExtension(),
                'temp_file' => $file->getPathname(),
                'is_uploaded_file' => is_uploaded_file($file->getPathname()),
                'file_exists' => file_exists($file->getPathname()),
                'is_readable' => is_readable($file->getPathname())
            ]);

            // 验证文件类型（证书文件）
            $allowedExtensions = ['pem', 'crt', 'key', 'txt'];
            $extension = strtolower($file->getUploadExtension());
            
            if (!in_array($extension, $allowedExtensions)) {
                throw new MyBusinessException('仅支持上传 .pem, .crt, .key, .txt 格式的证书文件');
            }

            // 验证文件大小（不超过2MB）
            $fileSize = $file->getSize();
            if ($fileSize === false) {
                // 如果getSize()失败，尝试使用filesize()
                $tempPath = $file->getPathname();
                if (file_exists($tempPath)) {
                    $fileSize = filesize($tempPath);
                }
            }
            
            if ($fileSize === false || $fileSize > 2 * 1024 * 1024) {
                throw new MyBusinessException('文件大小不能超过2MB或文件读取失败');
            }

            \support\Log::info('文件大小验证通过', ['size' => $fileSize]);

            // 生成文件名（使用时间戳+随机数）
            $fileName = date('Ymd') . '_' . uniqid() . '.' . $extension;
            
            // 设置保存路径（按日期分目录）
            $dateDir = date('Y-m-d');
            $uploadPath = public_path() . '/uploads/certs/' . $dateDir;
            
            // 确保目录存在
            if (!is_dir($uploadPath)) {
                mkdir($uploadPath, 0755, true);
            }

            // 完整文件路径
            $filePath = $uploadPath . '/' . $fileName;
            
            // 移动文件
            if (!$file->move($filePath)) {
                throw new MyBusinessException('文件保存失败');
            }

            // 验证文件是否成功保存
            if (!file_exists($filePath)) {
                throw new MyBusinessException('文件保存后验证失败');
            }

            // 读取文件内容
            $content = file_get_contents($filePath);
            
            if ($content === false) {
                throw new MyBusinessException('读取文件内容失败');
            }

            // 验证文件内容不为空
            if (empty($content)) {
                throw new MyBusinessException('文件内容为空');
            }

            // 返回相对路径（用于保存到数据库）和文件内容
            $relativePath = '/uploads/certs/' . $dateDir . '/' . $fileName;

            return success([
                'path' => $relativePath,
                'content' => $content,
                'filename' => $file->getUploadName(),
                'size' => $fileSize
            ], '上传成功');

        } catch (MyBusinessException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new MyBusinessException('上传失败：' . $e->getMessage());
        }
    }

    /**
     * 删除证书文件
     * @param Request $request
     * @return Response
     */
    public function deleteCert(Request $request): Response
    {
        try {
            $path = $request->post('path');
            
            if (empty($path)) {
                throw new MyBusinessException('文件路径不能为空');
            }

            // 构建完整路径
            $fullPath = public_path() . $path;

            // 检查文件是否存在
            if (!file_exists($fullPath)) {
                // 文件不存在也返回成功（幂等操作）
                return success([], '删除成功');
            }

            // 删除文件
            if (unlink($fullPath)) {
                return success([], '删除成功');
            } else {
                throw new MyBusinessException('删除文件失败');
            }

        } catch (MyBusinessException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new MyBusinessException('删除失败：' . $e->getMessage());
        }
    }
}

<?php

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * XHProf性能分析中间件
 * 用于分析商户创建订单等关键接口的性能
 */
class XhprofMiddleware implements MiddlewareInterface
{
    /**
     * 需要性能分析的路径
     */
    protected array $profiledPaths = [
        '/api/v1/order/create',
        '/api/v1/order/query',
        '/api/v1/order/notify',
    ];

    /**
     * 是否启用性能分析
     */
    protected bool $enabled = false;

    /**
     * 输出目录
     */
    protected string $outputDir = '';

    public function __construct()
    {
        // 从环境变量或配置中读取是否启用
        $this->enabled = env('XHPROF_ENABLED', false);
        $this->outputDir = env('XHPROF_OUTPUT_DIR', runtime_path() . '/xhprof');
        
        // 确保输出目录存在
        if ($this->enabled && !is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }
    }

    public function process(Request $request, callable $next): Response
    {
        // 检查是否需要性能分析
        if (!$this->enabled || !$this->shouldProfile($request)) {
            return $next($request);
        }

        // 开始性能分析
        if (function_exists('xhprof_enable')) {
            xhprof_enable(
                XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY,
                ['ignored_functions' => ['call_user_func', 'call_user_func_array']]
            );
        }

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        // 执行请求
        $response = $next($request);

        $endTime = microtime(true);
        $endMemory = memory_get_usage();

        // 保存性能数据
        $this->saveProfileData($request, $startTime, $endTime, $startMemory, $endMemory);

        return $response;
    }

    /**
     * 判断是否需要性能分析
     */
    protected function shouldProfile(Request $request): bool
    {
        $path = $request->path();
        return in_array($path, $this->profiledPaths);
    }

    /**
     * 保存性能分析数据
     */
    protected function saveProfileData(Request $request, float $startTime, float $endTime, int $startMemory, int $endMemory): void
    {
        try {
            $profileData = [
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'duration' => round(($endTime - $startTime) * 1000, 2), // 毫秒
                'memory_usage' => $endMemory - $startMemory,
                'memory_peak' => memory_get_peak_usage(),
                'timestamp' => date('Y-m-d H:i:s'),
                'request_data' => $this->getRequestData($request),
            ];

            // 如果有xhprof数据，保存详细的分析结果
            if (function_exists('xhprof_disable')) {
                $xhprofData = xhprof_disable();
                $profileData['xhprof_data'] = $xhprofData;
            }

            // 保存到文件
            $filename = $this->outputDir . '/profile_' . date('Ymd_His') . '_' . uniqid() . '.json';
            file_put_contents($filename, json_encode($profileData, JSON_PRETTY_PRINT));

            // 记录到日志
            \support\Log::info('XHProf性能分析完成', [
                'url' => $request->fullUrl(),
                'duration_ms' => $profileData['duration'],
                'memory_mb' => round($profileData['memory_usage'] / 1024 / 1024, 2),
                'file' => $filename
            ]);

        } catch (\Exception $e) {
            \support\Log::error('XHProf性能分析保存失败', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 获取请求数据（脱敏处理）
     */
    protected function getRequestData(Request $request): array
    {
        $data = $request->all();
        
        // 脱敏处理敏感信息
        $sensitiveFields = ['sign', 'merchant_key', 'notify_url', 'return_url'];
        foreach ($sensitiveFields as $field) {
            if (isset($data[$field])) {
                $data[$field] = '***';
            }
        }

        return $data;
    }
}


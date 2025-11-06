<?php

namespace app\middleware;
use ReflectionClass;
use Webman\Http\Request;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use XHProfRuns_Default;

class Debug implements MiddlewareInterface
{
    /**
     * 需要性能分析的路径
     */
    protected array $profiledPaths = [
        '/api/v1/order/create',
        '/api/v1/order/query',
        '/api/v1/order/notify',
    ];

    public function process(Request $request, callable $handler) : Response
    {
        $xhprof = $request->get('xhprof', 0);
        // 对于商户订单创建接口，默认启用性能分析
        if ($request->path() === '/api/v1/order/create') {
            $xhprof = 1;
        }
        
        $extension = extension_loaded('xhprof');

        if ($xhprof && $extension) {
            // xhprof_lib 在下载的包里存在这个目录,记得将目录包含到运行的php代码中
            include_once public_path() . "/xhprof/xhprof_lib/utils/xhprof_lib.php";
            include_once public_path() . "/xhprof/xhprof_lib/utils/xhprof_runs.php";
            xhprof_enable(XHPROF_FLAGS_NO_BUILTINS + XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY);
            
            // 记录开始时间
            $GLOBALS['xhprof_start_time'] = microtime(true);
            $GLOBALS['xhprof_start_memory'] = memory_get_usage();
        }

        $response = $handler($request);
        
        if ($xhprof && $extension) {
            $data = xhprof_disable();
            $objXhprofRun = new XHProfRuns_Default();
            
            // 生成更详细的运行名称
            $runName = $this->generateRunName($request, $data);
            $objXhprofRun->save_run($data, $runName);
            
            // 记录性能日志
            $this->logPerformanceData($request, $runName);
        }

        return $response;
    }

    /**
     * 生成运行名称
     */
    protected function generateRunName(Request $request, array $data): string
    {
        $path = str_replace('/', '_', trim($request->path(), '/'));
        $timestamp = date("YmdHis");
        $microtime = substr(microtime(), 2, 6);
        
        // 如果是订单创建，添加更多标识信息
        if ($request->path() === '/api/v1/order/create') {
            $merchantKey = $request->input('merchant_key', 'unknown');
            $orderNo = $request->input('merchant_order_no', 'unknown');
            return sprintf('%s_%s_%s_%s_%s', $path, $merchantKey, $orderNo, $timestamp, $microtime);
        }
        
        return sprintf('%s_%s_%s', $path, $timestamp, $microtime);
    }

    /**
     * 记录性能数据
     */
    protected function logPerformanceData(Request $request, string $runName): void
    {
        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        
        $duration = isset($GLOBALS['xhprof_start_time']) 
            ? round(($endTime - $GLOBALS['xhprof_start_time']) * 1000, 2) 
            : 0;
            
        $memoryUsage = isset($GLOBALS['xhprof_start_memory']) 
            ? $endMemory - $GLOBALS['xhprof_start_memory'] 
            : 0;

        // 记录到日志
        \support\Log::info('XHProf性能分析完成', [
            'url' => $request->fullUrl(),
            'path' => $request->path(),
            'method' => $request->method(),
            'duration_ms' => $duration,
            'memory_usage_mb' => round($memoryUsage / 1024 / 1024, 2),
            'memory_peak_mb' => round(memory_get_peak_usage() / 1024 / 1024, 2),
            'run_name' => $runName,
            'xhprof_ui_url' => $this->getXhprofUIUrl($runName)
        ]);
    }

    /**
     * 获取XHProf UI访问URL
     */
    protected function getXhprofUIUrl(string $runName): string
    {
        $baseUrl = config('app.app_host', 'http://localhost');
        return $baseUrl . '/xhprof/index.php?run=' . $runName . '&source=xhprof_ui';
    }
}
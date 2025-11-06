<?php

/**
 * 简单的xhprof集成测试
 * 验证xhprof是否正常工作
 */

// 检查xhprof扩展
if (!extension_loaded('xhprof')) {
    echo "❌ xhprof扩展未安装\n";
    exit(1);
}

echo "✅ xhprof扩展已安装\n";

// 检查xhprof UI
$publicPath = __DIR__ . '/../public';
if (!is_dir($publicPath . '/xhprof')) {
    echo "❌ xhprof UI未安装到 " . $publicPath . "/xhprof\n";
    exit(1);
}

echo "✅ xhprof UI已安装\n";

// 测试xhprof功能
echo "\n开始xhprof测试...\n";

// 包含xhprof库
include_once $publicPath . "/xhprof/xhprof_lib/utils/xhprof_lib.php";
include_once $publicPath . "/xhprof/xhprof_lib/utils/xhprof_runs.php";

// 开始性能分析
xhprof_enable(XHPROF_FLAGS_NO_BUILTINS + XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY);

// 模拟一些工作
echo "执行测试函数...\n";
$result = testFunction();
echo "测试函数结果: $result\n";

// 停止性能分析
$data = xhprof_disable();

// 保存运行数据
$objXhprofRun = new XHProfRuns_Default();
$runName = 'simple_test_' . date('YmdHis') . '_' . uniqid();
$objXhprofRun->save_run($data, $runName);

echo "✅ xhprof数据已保存，运行名称: $runName\n";

// 生成访问URL
$baseUrl = 'http://localhost';
$xhprofUrl = $baseUrl . '/xhprof/index.php?run=' . $runName . '&source=xhprof_ui';
echo "✅ XHProf UI访问地址: $xhprofUrl\n";

// 测试函数
function testFunction() {
    $sum = 0;
    for ($i = 0; $i < 1000; $i++) {
        $sum += $i;
    }
    
    // 模拟数据库查询
    usleep(10000); // 10ms延迟
    
    return $sum;
}

echo "\n✅ xhprof集成测试完成！\n";
echo "请访问 $xhprofUrl 查看详细的性能分析结果。\n";

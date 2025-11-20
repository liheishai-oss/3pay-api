<?php
/**
 * 删除 single_royalty 表的 payee_user_id 字段
 * 使用方法：php alter_single_royalty_remove_payee_user_id.php
 * 
 * 通过 Docker 直接执行 SQL
 */

// 读取数据库配置文件
$configFile = __DIR__ . '/config/database.php';
if (!file_exists($configFile)) {
    die("❌ 数据库配置文件不存在: {$configFile}\n");
}

$dbConfigArray = require $configFile;
$dbConfig = $dbConfigArray['connections']['mysql'];

$database = $dbConfig['database'];
$username = $dbConfig['username'];
$password = $dbConfig['password'];
$dockerContainer = 'mysql'; // Docker 容器名称

echo "开始删除 single_royalty 表的 payee_user_id 字段...\n";
echo "数据库: {$database}\n";
echo "Docker 容器: {$dockerContainer}\n\n";

try {
    // 检查 Docker 容器是否存在
    $checkContainer = "docker ps --format '{{.Names}}' | grep -w '^{$dockerContainer}$'";
    $containerExists = shell_exec($checkContainer);
    
    if (empty(trim($containerExists))) {
        throw new Exception("Docker 容器 '{$dockerContainer}' 不存在或未运行");
    }
    
    echo "✓ Docker 容器存在\n\n";
    
    // 检查字段是否存在
    $checkColumnSql = "SHOW COLUMNS FROM `{$database}`.`single_royalty` LIKE 'payee_user_id'";
    $checkCmd = sprintf(
        'docker exec -i %s mysql -u%s -p%s -e "%s"',
        escapeshellarg($dockerContainer),
        escapeshellarg($username),
        escapeshellarg($password),
        $checkColumnSql
    );
    
    $checkResult = shell_exec($checkCmd . ' 2>&1');
    
    // 如果没有找到字段（返回空结果或只有表头），则字段不存在
    if (empty(trim($checkResult)) || strpos($checkResult, 'Field') === false || strpos($checkResult, 'payee_user_id') === false) {
        echo "✓ 字段 payee_user_id 不存在，无需删除。\n";
        exit(0);
    }
    
    echo "字段 payee_user_id 存在，开始删除...\n";
    
    // 执行删除字段的 SQL
    $dropColumnSql = "ALTER TABLE `{$database}`.`single_royalty` DROP COLUMN `payee_user_id`";
    $dropCmd = sprintf(
        'docker exec -i %s mysql -u%s -p%s -e "%s"',
        escapeshellarg($dockerContainer),
        escapeshellarg($username),
        escapeshellarg($password),
        $dropColumnSql
    );
    
    $dropResult = shell_exec($dropCmd . ' 2>&1');
    
    // 检查是否有错误
    if (!empty($dropResult) && strpos($dropResult, 'ERROR') !== false) {
        throw new Exception("删除字段失败: " . trim($dropResult));
    }
    
    // 再次验证字段是否已删除
    $verifyResult = shell_exec($checkCmd . ' 2>&1');
    
    if (!empty(trim($verifyResult)) && strpos($verifyResult, 'payee_user_id') !== false) {
        throw new Exception("字段删除失败，验证时仍存在");
    }
    
    echo "✓ 成功删除 payee_user_id 字段！\n\n";
    
    // 显示当前表结构
    $showColumnsSql = "SHOW COLUMNS FROM `{$database}`.`single_royalty`";
    $showCmd = sprintf(
        'docker exec -i %s mysql -u%s -p%s -e "%s"',
        escapeshellarg($dockerContainer),
        escapeshellarg($username),
        escapeshellarg($password),
        $showColumnsSql
    );
    
    $columnsResult = shell_exec($showCmd . ' 2>&1');
    
    if (!empty($columnsResult)) {
        echo "当前表结构字段：\n";
        echo $columnsResult;
    }
    
    echo "\n✓ 执行完成！\n";
    
} catch (\Exception $e) {
    echo "❌ 执行失败: " . $e->getMessage() . "\n";
    echo "错误位置: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}


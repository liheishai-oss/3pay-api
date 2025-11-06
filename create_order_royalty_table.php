<?php
/**
 * 自动创建订单分账记录表
 * 使用方法：php create_order_royalty_table.php
 * 
 * 直接使用本地数据库连接（127.0.0.1）
 */

// 读取数据库配置文件
$configFile = __DIR__ . '/config/database.php';
if (!file_exists($configFile)) {
    die("❌ 数据库配置文件不存在: {$configFile}\n");
}

$dbConfigArray = require $configFile;
$dbConfig = $dbConfigArray['connections']['mysql'];

// 强制使用本地连接
$host = '127.0.0.1';
$port = $dbConfig['port'] ?? '3306';
$database = $dbConfig['database'];
$username = $dbConfig['username'];
$password = $dbConfig['password'];
$charset = $dbConfig['charset'] ?? 'utf8mb4';

echo "开始创建订单分账记录表...\n";
echo "数据库: {$database}\n";
echo "主机: {$host}:{$port}\n\n";

try {
    // 使用PDO直接连接本地数据库
    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "✓ 数据库连接成功\n\n";
    
    // 读取SQL文件
    $sqlFile = __DIR__ . '/create_order_royalty_table.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("SQL文件不存在: {$sqlFile}");
    }

    $sql = file_get_contents($sqlFile);
    if (empty($sql)) {
        throw new Exception("SQL文件为空");
    }

    // 移除注释（保留SQL语句）
    $lines = explode("\n", $sql);
    $cleanLines = [];
    foreach ($lines as $line) {
        $line = trim($line);
        // 跳过注释行和空行
        if (empty($line) || strpos($line, '--') === 0) {
            continue;
        }
        // 移除行内注释
        if (strpos($line, '--') !== false) {
            $line = substr($line, 0, strpos($line, '--'));
            $line = trim($line);
        }
        if (!empty($line)) {
            $cleanLines[] = $line;
        }
    }
    $sql = implode(' ', $cleanLines);
    
    // 分割多个SQL语句（如果有分号分隔）
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    // 检查表是否已存在
    $stmt = $pdo->query("SHOW TABLES LIKE 'order_royalty'");
    $tableExists = $stmt->fetchAll();
    
    if (!empty($tableExists)) {
        echo "⚠️  表 order_royalty 已存在\n";
        echo "是否要删除并重新创建？(y/N): ";
        
        // 非交互式模式下跳过（如果表存在）
        if (php_sapi_name() === 'cli' && !stream_isatty(STDIN)) {
            echo "非交互模式，跳过创建（表已存在）\n";
            exit(0);
        }
        
        $handle = fopen("php://stdin", "r");
        $line = trim(fgets($handle));
        fclose($handle);
        
        if (strtolower($line) !== 'y') {
            echo "已取消创建。\n";
            exit(0);
        }
        
        // 删除旧表
        echo "删除旧表...\n";
        $pdo->exec("DROP TABLE IF EXISTS `order_royalty`");
        echo "✓ 旧表已删除\n\n";
    }

    // 执行SQL
    echo "正在创建表...\n";
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            $pdo->exec($statement);
        }
    }
    
    // 验证表是否创建成功
    $stmt = $pdo->query("SHOW TABLES LIKE 'order_royalty'");
    $tableExists = $stmt->fetchAll();
    
    if (empty($tableExists)) {
        throw new Exception("表创建失败，验证时未找到表");
    }

    // 检查表结构
    $stmt = $pdo->query("SHOW COLUMNS FROM `order_royalty`");
    $columns = $stmt->fetchAll();
    $columnCount = count($columns);
    
    // 检查索引
    $stmt = $pdo->query("SHOW INDEX FROM `order_royalty`");
    $indexes = $stmt->fetchAll();
    $indexCount = count($indexes);
    
    echo "\n✓ 表创建成功！\n";
    echo "✓ 表包含 {$columnCount} 个字段\n";
    echo "✓ 表包含 {$indexCount} 个索引\n";
    
    // 显示关键字段
    echo "\n关键字段：\n";
    $keyFields = ['id', 'order_id', 'platform_order_no', 'royalty_status', 'royalty_amount', 'subject_amount'];
    foreach ($columns as $column) {
        if (in_array($column['Field'], $keyFields)) {
            echo "  - {$column['Field']}: {$column['Type']}\n";
        }
    }
    
    echo "\n✓ 创建完成！\n";
    echo "表名: order_royalty\n";
    echo "数据库: {$database}\n";

} catch (\Exception $e) {
    echo "❌ 创建失败: " . $e->getMessage() . "\n";
    echo "错误位置: " . $e->getFile() . ":" . $e->getLine() . "\n";
    if (method_exists($e, 'getTraceAsString')) {
        echo "堆栈跟踪:\n" . $e->getTraceAsString() . "\n";
    }
    exit(1);
}


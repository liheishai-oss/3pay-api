<?php
/**
 * 检查数据库中是否存在分账订单表
 * 使用方法：php check_royalty_table.php
 * 
 * 支持Docker容器连接（host: mysql）和本地连接（host: 127.0.0.1）
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
$charset = $dbConfig['charset'] ?? 'utf8mb4';

echo str_repeat("=", 80) . "\n";
echo "检查分账订单表 order_royalty\n";
echo str_repeat("=", 80) . "\n";
echo "数据库: {$database}\n";
echo "字符集: {$charset}\n\n";

// 尝试连接的两个主机
$hosts = [
    ['name' => 'Docker容器', 'host' => 'mysql', 'port' => $dbConfig['port'] ?? '3306'],
    ['name' => '本地连接', 'host' => '127.0.0.1', 'port' => $dbConfig['port'] ?? '3306'],
];

$connected = false;
$pdo = null;
$connectedHost = null;

foreach ($hosts as $hostInfo) {
    $host = $hostInfo['host'];
    $port = $hostInfo['port'];
    
    echo "尝试连接: {$hostInfo['name']} ({$host}:{$port})...\n";
    
    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 3,
        ]);
        
        // 测试连接
        $pdo->query("SELECT 1");
        $connected = true;
        $connectedHost = $hostInfo['name'];
        echo "✓ 连接成功: {$hostInfo['name']}\n\n";
        break;
        
    } catch (PDOException $e) {
        echo "✗ 连接失败: {$e->getMessage()}\n\n";
        continue;
    }
}

if (!$connected) {
    die("❌ 无法连接到数据库，请检查数据库配置和Docker容器状态\n");
}

try {
    // 1. 检查表是否存在
    echo str_repeat("-", 80) . "\n";
    echo "1. 检查表是否存在\n";
    echo str_repeat("-", 80) . "\n";
    
    $stmt = $pdo->query("SHOW TABLES LIKE 'order_royalty'");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($tables)) {
        echo "❌ 表 order_royalty 不存在\n\n";
        echo "建议：运行以下命令创建表：\n";
        echo "  php create_order_royalty_table.php\n\n";
        exit(1);
    }
    
    echo "✓ 表 order_royalty 存在\n\n";
    
    // 2. 检查表结构
    echo str_repeat("-", 80) . "\n";
    echo "2. 检查表结构\n";
    echo str_repeat("-", 80) . "\n";
    
    $stmt = $pdo->query("SHOW COLUMNS FROM `order_royalty`");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "表字段数量: " . count($columns) . "\n\n";
    
    // 检查关键字段
    $requiredFields = [
        'id' => '主键ID',
        'order_id' => '订单ID',
        'platform_order_no' => '平台订单号',
        'royalty_status' => '分账状态',
        'royalty_amount' => '分账金额',
        'subject_amount' => '主体金额',
        'royalty_type' => '分账方式',
        'royalty_mode' => '分账模式',
        'royalty_rate' => '分账比例',
        'payee_user_id' => '收款人支付宝用户ID',
        'alipay_royalty_no' => '支付宝分账单号',
        'created_at' => '创建时间',
        'updated_at' => '更新时间',
    ];
    
    $existingFields = array_column($columns, 'Field');
    $missingFields = [];
    
    foreach ($requiredFields as $field => $desc) {
        if (in_array($field, $existingFields)) {
            $colInfo = array_filter($columns, fn($c) => $c['Field'] === $field);
            $colInfo = reset($colInfo);
            echo "  ✓ {$field} ({$desc}): {$colInfo['Type']}\n";
        } else {
            echo "  ✗ {$field} ({$desc}): 缺失\n";
            $missingFields[] = $field;
        }
    }
    
    if (!empty($missingFields)) {
        echo "\n⚠️  缺少关键字段: " . implode(', ', $missingFields) . "\n";
    }
    
    echo "\n";
    
    // 3. 检查索引
    echo str_repeat("-", 80) . "\n";
    echo "3. 检查索引\n";
    echo str_repeat("-", 80) . "\n";
    
    $stmt = $pdo->query("SHOW INDEX FROM `order_royalty`");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "索引数量: " . count($indexes) . "\n\n";
    
    // 检查关键索引
    $indexGroups = [];
    foreach ($indexes as $index) {
        $keyName = $index['Key_name'];
        if (!isset($indexGroups[$keyName])) {
            $indexGroups[$keyName] = [
                'name' => $keyName,
                'unique' => $index['Non_unique'] == 0 ? 'UNIQUE' : 'INDEX',
                'columns' => []
            ];
        }
        $indexGroups[$keyName]['columns'][] = $index['Column_name'];
    }
    
    foreach ($indexGroups as $index) {
        $columnsStr = implode(', ', $index['columns']);
        echo "  - {$index['name']} ({$index['unique']}): {$columnsStr}\n";
    }
    
    echo "\n";
    
    // 4. 检查表数据统计
    echo str_repeat("-", 80) . "\n";
    echo "4. 表数据统计\n";
    echo str_repeat("-", 80) . "\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM `order_royalty`");
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    echo "总记录数: {$total}\n\n";
    
    if ($total > 0) {
        // 按状态统计
        $stmt = $pdo->query("
            SELECT 
                royalty_status,
                COUNT(*) as count,
                SUM(royalty_amount) as total_amount
            FROM `order_royalty`
            GROUP BY royalty_status
            ORDER BY royalty_status
        ");
        $statusStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $statusMap = [
            0 => '待分账',
            1 => '分账中',
            2 => '分账成功',
            3 => '分账失败',
        ];
        
        echo "按状态统计:\n";
        foreach ($statusStats as $stat) {
            $status = $stat['royalty_status'];
            $statusText = $statusMap[$status] ?? "未知({$status})";
            $count = $stat['count'];
            $amount = number_format($stat['total_amount'], 2);
            echo "  - {$statusText}: {$count} 条, 总金额: {$amount}\n";
        }
        
        echo "\n";
        
        // 最近的分账记录
        $stmt = $pdo->query("
            SELECT 
                id,
                platform_order_no,
                royalty_status,
                royalty_amount,
                created_at
            FROM `order_royalty`
            ORDER BY id DESC
            LIMIT 5
        ");
        $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($recent)) {
            echo "最近5条记录:\n";
            foreach ($recent as $record) {
                $statusText = $statusMap[$record['royalty_status']] ?? "未知";
                echo "  - ID:{$record['id']}, 订单号:{$record['platform_order_no']}, 状态:{$statusText}, 金额:{$record['royalty_amount']}, 创建时间:{$record['created_at']}\n";
            }
        }
    }
    
    echo "\n";
    
    // 5. 总结
    echo str_repeat("=", 80) . "\n";
    echo "检查完成\n";
    echo str_repeat("=", 80) . "\n";
    echo "✓ 表 order_royalty 存在\n";
    echo "✓ 表结构正常\n";
    echo "✓ 总记录数: {$total}\n";
    echo "✓ 连接方式: {$connectedHost}\n";
    echo "\n";
    
} catch (PDOException $e) {
    echo "\n❌ 检查失败: " . $e->getMessage() . "\n";
    echo "错误代码: " . $e->getCode() . "\n";
    exit(1);
}



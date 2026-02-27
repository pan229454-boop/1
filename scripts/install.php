<?php

declare(strict_types=1);

/**
 * 一键初始化脚本：
 * 1) 执行 SQL 建表
 * 2) 创建默认管理员 admin@local.test / Admin@123
 */

require_once __DIR__ . '/../server/core/Env.php';

use ChatApp\Core\Env;

$envPath = dirname(__DIR__) . '/.env';
if (!is_file($envPath) && is_file(dirname(__DIR__) . '/.env.example')) {
    copy(dirname(__DIR__) . '/.env.example', $envPath);
}
Env::load($envPath);

$host = (string) Env::get('DB_HOST', '127.0.0.1');
$port = (string) Env::get('DB_PORT', '3306');
$user = (string) Env::get('DB_USER', 'root');
$pass = (string) Env::get('DB_PASS', '');
$charset = (string) Env::get('DB_CHARSET', 'utf8mb4');

$dsn = "mysql:host={$host};port={$port};charset={$charset}";
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$sql = file_get_contents(__DIR__ . '/schema.sql');
if ($sql === false) {
    echo "读取 schema.sql 失败\n";
    exit(1);
}

$chunks = array_filter(array_map('trim', explode(';', $sql)));
foreach ($chunks as $statement) {
    if ($statement === '') {
        continue;
    }
    $pdo->exec($statement);
}

$dbName = (string) Env::get('DB_NAME', 'minichat');
$pdo->exec('USE `' . str_replace('`', '', $dbName) . '`');

$stmt = $pdo->query('SELECT id FROM users WHERE email = "admin@local.test" LIMIT 1');
$admin = $stmt->fetch();

if (!$admin) {
    $pwdHash = password_hash('Admin@123', PASSWORD_BCRYPT);
    $pdo->exec('SET @next_no = IFNULL((SELECT MAX(user_no) FROM users), 0) + 1');
    $insert = $pdo->prepare('INSERT INTO users (user_no, email, phone, password_hash, nickname, avatar, status, is_admin, created_at, updated_at) VALUES (@next_no, :email, :phone, :pwd, :nickname, :avatar, 1, 1, NOW(), NOW())');
    $insert->execute([
        'email' => 'admin@local.test',
        'phone' => '',
        'pwd' => $pwdHash,
        'nickname' => '系统管理员',
        'avatar' => '/assets/default-avatar.png',
    ]);
    echo "管理员账号已创建: admin@local.test / Admin@123\n";
} else {
    echo "管理员账号已存在\n";
}

echo "安装完成\n";

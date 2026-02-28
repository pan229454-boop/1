<?php
/**
 * 极聊（商用版）- 数据库操作类
 * 
 * 基于 PDO 封装，支持预处理语句防注入
 * 单例模式，整个请求生命周期内复用连接
 * 
 * @package JiLiao\Core
 */

declare(strict_types=1);

namespace JiLiao\Core;

use PDO;
use PDOException;
use PDOStatement;

class Database
{
    /** @var Database|null 单例实例 */
    private static ?Database $instance = null;

    /** @var PDO PDO 连接 */
    private PDO $pdo;

    /**
     * 私有构造方法
     * 
     * @throws \RuntimeException 连接失败时抛出
     */
    private function __construct()
    {
        $host    = Env::get('DB_HOST', '127.0.0.1');
        $port    = Env::get('DB_PORT', '3306');
        $name    = Env::get('DB_NAME', 'jiliao');
        $user    = Env::get('DB_USER', 'root');
        $pass    = Env::get('DB_PASS', '');
        $charset = Env::get('DB_CHARSET', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // 异常模式
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // 关联数组
                PDO::ATTR_EMULATE_PREPARES   => false,                    // 真正预处理
                PDO::ATTR_PERSISTENT         => false,                    // 非持久连接
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE {$charset}_unicode_ci",
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException('数据库连接失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取单例实例
     * 
     * @return Database
     */
    public static function getInstance(): static
    {
        if (self::$instance === null) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    /**
     * 执行查询并返回所有行
     * 
     * @param  string $sql    SQL语句（含占位符）
     * @param  array  $params 绑定参数
     * @return array<int,array<string,mixed>>
     */
    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdoExecute($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * 执行查询并返回单行
     * 
     * @param  string $sql
     * @param  array  $params
     * @return array<string,mixed>|null
     */
    public function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdoExecute($sql, $params);
        $row  = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * 执行查询并返回单列值
     * 
     * @param  string $sql
     * @param  array  $params
     * @return mixed|null
     */
    public function queryScalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdoExecute($sql, $params);
        $val  = $stmt->fetchColumn();
        return $val !== false ? $val : null;
    }

    /**
     * 执行 INSERT/UPDATE/DELETE，返回影响行数
     * 
     * @param  string $sql
     * @param  array  $params
     * @return int 影响行数
     */
    public function exec(string $sql, array $params = []): int
    {
        $stmt = $this->pdoExecute($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * 执行 INSERT，返回最后插入 ID
     * 
     * @param  string $sql
     * @param  array  $params
     * @return string|false
     */
    public function insert(string $sql, array $params = []): string|false
    {
        $this->pdoExecute($sql, $params);
        return $this->pdo->lastInsertId();
    }

    /**
     * 快速插入一张表（传入字段=>值数组）
     * 
     * @param  string $table  表名
     * @param  array  $data   字段=>值
     * @return string 自增ID
     */
    public function insertRow(string $table, array $data): string
    {
        $cols        = array_keys($data);
        $placeholders = array_map(fn($c) => ':' . $c, $cols);
        $sql         = "INSERT INTO `{$table}` (`" . implode('`,`', $cols) . "`) "
                     . "VALUES (" . implode(',', $placeholders) . ")";
        return $this->insert($sql, $data) ?: '0';
    }

    /**
     * 快速更新一张表
     * 
     * @param  string $table  表名
     * @param  array  $data   更新的字段=>值
     * @param  array  $where  条件字段=>值（AND 关系）
     * @return int 影响行数
     */
    public function updateRow(string $table, array $data, array $where): int
    {
        $set   = implode(',', array_map(fn($k) => "`{$k}`=:set_{$k}", array_keys($data)));
        $cond  = implode(' AND ', array_map(fn($k) => "`{$k}`=:w_{$k}", array_keys($where)));
        $params = [];
        foreach ($data  as $k => $v) { $params["set_{$k}"] = $v; }
        foreach ($where as $k => $v) { $params["w_{$k}"]   = $v; }
        return $this->exec("UPDATE `{$table}` SET {$set} WHERE {$cond}", $params);
    }

    // ── 便捷别名方法 ──────────────────────────────────────────────

    /**
     * 查询单行（queryOne 别名，语义更直观）
     *
     * @param  string $sql
     * @param  array  $params
     * @return array<string,mixed>|null
     */
    public function first(string $sql, array $params = []): ?array
    {
        return $this->queryOne($sql, $params);
    }

    /**
     * 查询单列值（queryScalar 别名）
     *
     * @param  string $sql
     * @param  array  $params
     * @return mixed|null
     */
    public function single(string $sql, array $params = []): mixed
    {
        return $this->queryScalar($sql, $params);
    }

    /**
     * 执行写操作语句（exec 别名），返回影响行数
     *
     * @param  string $sql
     * @param  array  $params
     * @return int
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->exec($sql, $params);
    }

    /**
     * 开启事务
     */
    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    /**
     * 提交事务
     */
    public function commit(): void
    {
        $this->pdo->commit();
    }

    /**
     * 回滚事务
     */
    public function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * 内部执行预处理语句
     * 
     * @param  string $sql
     * @param  array  $params
     * @return PDOStatement
     * @throws \RuntimeException
     */
    private function pdoExecute(string $sql, array $params): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            // 自动判断类型绑定
            $type = match(true) {
                is_int($v)  => PDO::PARAM_INT,
                is_bool($v) => PDO::PARAM_BOOL,
                is_null($v) => PDO::PARAM_NULL,
                default     => PDO::PARAM_STR,
            };
            // 支持 :key 和 ?  两种占位符
            $stmt->bindValue(is_string($k) ? ":{$k}" : ($k + 1), $v, $type);
        }
        $stmt->execute();
        return $stmt;
    }
}

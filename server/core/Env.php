<?php
/**
 * 极聊（商用版）- 环境变量管理类
 * 
 * 轻量版 .env 解析器，不依赖 Composer（安装向导阶段可用）
 * 
 * @package JiLiao\Core
 */

declare(strict_types=1);

namespace JiLiao\Core;

class Env
{
    /** @var array<string,string> 已加载的环境变量 */
    private static array $vars = [];

    /**
     * 加载 .env 文件
     * 
     * @param  string $path .env 文件绝对路径
     * @return void
     */
    public static function load(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // 跳过注释行
            if (str_starts_with(trim($line), '#')) {
                continue;
            }

            // 解析 KEY=VALUE
            if (strpos($line, '=') !== false) {
                [$key, $value] = array_map('trim', explode('=', $line, 2));
                // 去除引号
                $value = trim($value, '"\'');
                self::$vars[$key] = $value;
                // 同步写入 $_ENV（供 phpmailer 等第三方库读取）
                $_ENV[$key] = $value;
            }
        }
    }

    /**
     * 获取环境变量值
     * 
     * @param  string $key     变量键名
     * @param  mixed  $default 默认值
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$vars[$key] ?? $_ENV[$key] ?? getenv($key) ?: $default;
    }

    /**
     * 设置运行时环境变量（不写入文件）
     * 
     * @param string $key
     * @param string $value
     */
    public static function set(string $key, string $value): void
    {
        self::$vars[$key] = $value;
    }

    /**
     * 将 .env 写入文件（安装向导用）
     * 
     * @param  string         $path 文件路径
     * @param  array<string,string> $data KV数组
     * @return bool
     */
    public static function write(string $path, array $data): bool
    {
        $lines = ["# 极聊（商用版）自动生成配置文件 - " . date('Y-m-d H:i:s'), ""];
        foreach ($data as $k => $v) {
            // 含空格或特殊字符时加引号
            if (preg_match('/\s/', $v)) {
                $v = '"' . addcslashes($v, '"') . '"';
            }
            $lines[] = "{$k}={$v}";
        }
        $lines[] = "";
        return file_put_contents($path, implode("\n", $lines)) !== false;
    }
}

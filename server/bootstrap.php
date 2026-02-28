<?php
/**
 * 极聊（商用版）- 应用引导文件
 * 
 * 加载自动加载器、环境变量、全局工具函数
 * 所有入口文件（API/WebSocket）均需 require 此文件
 */

declare(strict_types=1);

// ── 兼容性检查 ────────────────────────────────────────────
if (PHP_VERSION_ID < 70400) {
    die(json_encode(['code' => 500, 'msg' => 'PHP >= 7.4 required']));
}

// ── 路径常量 ──────────────────────────────────────────────
define('ROOT_PATH',    dirname(__DIR__));                    // 项目根目录
define('SERVER_PATH',  __DIR__);                             // server/ 目录
define('STORAGE_PATH', ROOT_PATH . '/storage');              // 存储目录
define('CHAT_PATH',    STORAGE_PATH . '/chat');              // TXT 聊天记录
define('UPLOAD_PATH',  ROOT_PATH . '/public/uploads');       // 上传文件（置于public内供Nginx直接服务）
define('LOG_PATH',     STORAGE_PATH . '/logs');              // 日志

// ── Composer 自动加载 ─────────────────────────────────────
$autoload = ROOT_PATH . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

// ── 环境变量加载 ──────────────────────────────────────────
require_once SERVER_PATH . '/core/Env.php';
\JiLiao\Core\Env::load(ROOT_PATH . '/.env');

// ── 错误处理 ──────────────────────────────────────────────
$debug = \JiLiao\Core\Env::get('APP_DEBUG', 'false') === 'true';
if ($debug) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// ── 时区 ──────────────────────────────────────────────────
date_default_timezone_set('Asia/Shanghai');

// ── 全局辅助函数 ──────────────────────────────────────────

/**
 * 生成 UUID v4
 * 
 * @return string 格式: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
 */
function uuid4(): string
{
    $data    = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * 安全获取客户端 IP
 * 
 * @return string IP地址字符串
 */
function client_ip(): string
{
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

/**
 * 过滤 XSS（保留换行，去除危险标签）
 * 
 * @param  string $str 原始字符串
 * @return string 过滤后字符串
 */
function xss_clean(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * 递归安全创建目录
 * 
 * @param  string $path 目录路径
 * @return bool
 */
function mkdir_safe(string $path): bool
{
    if (!is_dir($path)) {
        return mkdir($path, 0755, true);
    }
    return true;
}

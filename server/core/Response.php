<?php
/**
 * 极聊（商用版）- HTTP 响应辅助类
 * 
 * 统一 API 响应格式：{ code, msg, data, ts }
 * 
 * @package JiLiao\Core
 */

declare(strict_types=1);

namespace JiLiao\Core;

class Response
{
    /**
     * 输出 JSON 成功响应并终止
     * 
     * @param  mixed  $data  返回数据
     * @param  string $msg   提示信息
     * @param  int    $code  HTTP 状态码 (默认200)
     * @return never
     */
    public static function success(mixed $data = null, string $msg = 'ok', int $code = 200): never
    {
        self::json(['code' => 0, 'msg' => $msg, 'data' => $data, 'ts' => time()], $code);
    }

    /**
     * 输出 JSON 失败响应并终止
     * 
     * @param  string $msg      错误信息
     * @param  int    $errCode  业务错误码（非 HTTP 状态码）
     * @param  int    $httpCode HTTP 状态码 (默认 200，让前端 fetch 不抛异常)
     * @return never
     */
    public static function fail(string $msg = 'error', int $errCode = 1, int $httpCode = 200): never
    {
        self::json(['code' => $errCode, 'msg' => $msg, 'data' => null, 'ts' => time()], $httpCode);
    }

    /**
     * 401 未授权
     * 
     * @return never
     */
    public static function unauthorized(string $msg = '请先登录'): never
    {
        self::fail($msg, 401, 401);
    }

    /**
     * 403 禁止访问
     * 
     * @return never
     */
    public static function forbidden(string $msg = '权限不足'): never
    {
        self::fail($msg, 403, 403);
    }

    /**
     * 429 频率限制
     * 
     * @return never
     */
    public static function tooManyRequests(string $msg = '请求频繁，请稍后再试'): never
    {
        self::fail($msg, 429, 429);
    }

    /**
     * 输出 JSON 并终止
     * 
     * @param  array $data
     * @param  int   $httpCode
     * @return never
     */
    public static function json(array $data, int $httpCode = 200): never
    {
        // 设置 CORS 头（根据配置域名限制）
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Allow-Credentials: true');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

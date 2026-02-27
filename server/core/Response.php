<?php

declare(strict_types=1);

namespace ChatApp\Core;

/**
 * 统一 JSON 输出
 */
final class Response
{
    public static function json(bool $ok, string $message = '', array $data = [], int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => $ok,
            'message' => $message,
            'data' => $data,
            'timestamp' => time(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

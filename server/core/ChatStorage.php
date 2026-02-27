<?php

declare(strict_types=1);

namespace ChatApp\Core;

/**
 * 聊天记录双写：MySQL + TXT。
 * TXT 规则：chat_{type}_{id}_YYYYMMDD.txt
 */
final class ChatStorage
{
    public static function appendTxt(string $type, string $chatId, array $payload): void
    {
        $date = date('Ymd');
        $baseDir = dirname(__DIR__, 2) . '/storage/chat_logs';
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0777, true);
        }

        $fileName = sprintf('chat_%s_%s_%s.txt', $type, $chatId, $date);
        $path = $baseDir . '/' . $fileName;

        $line = json_encode($payload, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    public static function searchTxt(string $keyword, int $limit = 100): array
    {
        $baseDir = dirname(__DIR__, 2) . '/storage/chat_logs';
        if (!is_dir($baseDir)) {
            return [];
        }

        $result = [];
        $files = glob($baseDir . '/chat_*_*.txt') ?: [];
        rsort($files);

        foreach ($files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                if (stripos($line, $keyword) === false) {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $result[] = $decoded;
                }
                if (count($result) >= $limit) {
                    return $result;
                }
            }
        }

        return $result;
    }
}

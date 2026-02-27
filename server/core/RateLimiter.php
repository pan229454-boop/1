<?php

declare(strict_types=1);

namespace ChatApp\Core;

use PDO;

/**
 * IP 限流（按分钟）
 */
final class RateLimiter
{
    public static function check(PDO $pdo, string $action, int $limitPerMinute): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $minuteKey = date('YmdHi');

        $stmt = $pdo->prepare('SELECT id, hit_count FROM ip_rate_limits WHERE ip = :ip AND action = :action AND minute_key = :minute_key LIMIT 1');
        $stmt->execute([
            'ip' => $ip,
            'action' => $action,
            'minute_key' => $minuteKey,
        ]);
        $row = $stmt->fetch();

        if (!$row) {
            $insert = $pdo->prepare('INSERT INTO ip_rate_limits (ip, action, minute_key, hit_count, created_at) VALUES (:ip, :action, :minute_key, 1, NOW())');
            $insert->execute([
                'ip' => $ip,
                'action' => $action,
                'minute_key' => $minuteKey,
            ]);
            return true;
        }

        $count = (int) $row['hit_count'] + 1;
        $update = $pdo->prepare('UPDATE ip_rate_limits SET hit_count = :count WHERE id = :id');
        $update->execute([
            'count' => $count,
            'id' => (int) $row['id'],
        ]);

        return $count <= $limitPerMinute;
    }
}

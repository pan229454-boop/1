<?php

declare(strict_types=1);

namespace ChatApp\Core;

use PDO;

/**
 * 鉴权工具：基于 token 表 + Bearer Token
 */
final class Auth
{
    public static function currentUser(PDO $pdo): ?array
    {
        $token = self::readBearerToken();
        if ($token === '') {
            return null;
        }

        $stmt = $pdo->prepare('SELECT ut.user_id, u.user_no, u.email, u.nickname, u.avatar, u.status, u.is_admin FROM user_tokens ut JOIN users u ON u.id = ut.user_id WHERE ut.token = :token AND ut.expired_at > NOW() LIMIT 1');
        $stmt->execute(['token' => $token]);
        $user = $stmt->fetch();

        if (!$user || (int) $user['status'] !== 1) {
            return null;
        }

        return $user;
    }

    public static function issueToken(PDO $pdo, int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare('INSERT INTO user_tokens (user_id, token, expired_at, created_at) VALUES (:user_id, :token, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW())');
        $stmt->execute([
            'user_id' => $userId,
            'token' => $token,
        ]);
        return $token;
    }

    public static function revokeToken(PDO $pdo, string $token): void
    {
        $stmt = $pdo->prepare('DELETE FROM user_tokens WHERE token = :token');
        $stmt->execute(['token' => $token]);
    }

    public static function readBearerToken(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = $headers['Authorization'] ?? '';
        }

        if (!preg_match('/Bearer\\s+(.*)$/i', $header, $matches)) {
            return '';
        }

        return trim($matches[1]);
    }
}

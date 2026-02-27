<?php

declare(strict_types=1);

use ChatApp\Core\Database;
use ChatApp\Core\Env;
use Workerman\Lib\Timer;
use Workerman\Worker;

require_once __DIR__ . '/../bootstrap.php';

$host = (string) Env::get('WS_HOST', '0.0.0.0');
$port = (int) Env::int('WS_PORT', 2346);

$ws = new Worker("websocket://{$host}:{$port}");
$ws->count = 1;

/** @var array<int, array<int, bool>> $chatSubscribers */
$chatSubscribers = [];
/** @var array<int, array<int, Worker>> $userConnections */
$userConnections = [];

$eventFile = dirname(__DIR__, 2) . '/storage/runtime/ws_events.ndjson';
$onlineFile = dirname(__DIR__, 2) . '/storage/runtime/ws_online.json';
if (!is_dir(dirname($eventFile))) {
    mkdir(dirname($eventFile), 0777, true);
}
if (!is_file($eventFile)) {
    file_put_contents($eventFile, '');
}

$ws->onConnect = function ($connection): void {
    $connection->userId = 0;
    $connection->subChats = [];
};

$ws->onMessage = function ($connection, $message) use (&$chatSubscribers, &$userConnections): void {
    $data = json_decode((string) $message, true);
    if (!is_array($data)) {
        $connection->send(json_encode(['type' => 'error', 'message' => '无效消息格式'], JSON_UNESCAPED_UNICODE));
        return;
    }

    $action = (string) ($data['action'] ?? '');

    if ($action === 'auth') {
        $token = trim((string) ($data['token'] ?? ''));
        if ($token === '') {
            $connection->send(json_encode(['type' => 'auth', 'ok' => false, 'message' => 'token不能为空'], JSON_UNESCAPED_UNICODE));
            return;
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT u.id FROM user_tokens ut JOIN users u ON u.id = ut.user_id WHERE ut.token = :token AND ut.expired_at > NOW() AND u.status = 1 LIMIT 1');
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch();

        if (!$row) {
            $connection->send(json_encode(['type' => 'auth', 'ok' => false, 'message' => 'token无效'], JSON_UNESCAPED_UNICODE));
            return;
        }

        $uid = (int) $row['id'];
        $connection->userId = $uid;
        if (!isset($userConnections[$uid])) {
            $userConnections[$uid] = [];
        }
        $userConnections[$uid][$connection->id] = $connection;

        saveOnlineUsers($userConnections);

        $connection->send(json_encode(['type' => 'auth', 'ok' => true, 'user_id' => $uid], JSON_UNESCAPED_UNICODE));
        return;
    }

    if ($action === 'subscribe') {
        if ((int) $connection->userId <= 0) {
            $connection->send(json_encode(['type' => 'error', 'message' => '请先认证'], JSON_UNESCAPED_UNICODE));
            return;
        }

        $chatId = (int) ($data['chat_id'] ?? 0);
        if ($chatId <= 0) {
            $connection->send(json_encode(['type' => 'error', 'message' => 'chat_id不合法'], JSON_UNESCAPED_UNICODE));
            return;
        }

        $connection->subChats[$chatId] = true;
        if (!isset($chatSubscribers[$chatId])) {
            $chatSubscribers[$chatId] = [];
        }
        $chatSubscribers[$chatId][$connection->id] = true;

        $connection->send(json_encode(['type' => 'subscribe', 'ok' => true, 'chat_id' => $chatId], JSON_UNESCAPED_UNICODE));
        return;
    }

    if ($action === 'unsubscribe') {
        $chatId = (int) ($data['chat_id'] ?? 0);
        unset($connection->subChats[$chatId]);
        if (isset($chatSubscribers[$chatId][$connection->id])) {
            unset($chatSubscribers[$chatId][$connection->id]);
        }
        $connection->send(json_encode(['type' => 'unsubscribe', 'ok' => true, 'chat_id' => $chatId], JSON_UNESCAPED_UNICODE));
        return;
    }

    if ($action === 'ping') {
        $connection->send(json_encode(['type' => 'pong', 'ts' => time()], JSON_UNESCAPED_UNICODE));
    }
};

$ws->onClose = function ($connection) use (&$chatSubscribers, &$userConnections): void {
    if ((int) $connection->userId > 0 && isset($userConnections[(int) $connection->userId][$connection->id])) {
        unset($userConnections[(int) $connection->userId][$connection->id]);
        if (empty($userConnections[(int) $connection->userId])) {
            unset($userConnections[(int) $connection->userId]);
        }
    }

    foreach ((array) $connection->subChats as $chatId => $_) {
        if (isset($chatSubscribers[(int) $chatId][$connection->id])) {
            unset($chatSubscribers[(int) $chatId][$connection->id]);
        }
    }

    saveOnlineUsers($userConnections);
};

$ws->onWorkerStart = function () use ($ws, $eventFile, $onlineFile, &$chatSubscribers, &$userConnections): void {
    $offset = filesize($eventFile) ?: 0;

    Timer::add(1.0, function () use ($ws, $eventFile, &$offset, &$chatSubscribers): void {
        clearstatcache(true, $eventFile);
        $size = filesize($eventFile) ?: 0;
        if ($size < $offset) {
            $offset = 0;
        }
        if ($size === $offset) {
            return;
        }

        $fp = fopen($eventFile, 'rb');
        if (!$fp) {
            return;
        }
        fseek($fp, $offset);

        while (($line = fgets($fp)) !== false) {
            $payload = json_decode(trim($line), true);
            if (!is_array($payload)) {
                continue;
            }

            $chatId = (int) ($payload['chat_id'] ?? 0);
            if ($chatId > 0 && isset($chatSubscribers[$chatId])) {
                foreach ($ws->connections as $conn) {
                    if (isset($conn->subChats[$chatId])) {
                        $conn->send(json_encode(['type' => 'event', 'event' => $payload], JSON_UNESCAPED_UNICODE));
                    }
                }
            } else {
                foreach ($ws->connections as $conn) {
                    $conn->send(json_encode(['type' => 'event', 'event' => $payload], JSON_UNESCAPED_UNICODE));
                }
            }
        }

        $offset = ftell($fp) ?: $size;
        fclose($fp);
    });

    Timer::add(5.0, function () use ($ws, $onlineFile): void {
        $online = 0;
        if (is_file($onlineFile)) {
            $map = json_decode((string) file_get_contents($onlineFile), true);
            $online = is_array($map) ? count($map) : 0;
        }

        foreach ($ws->connections as $conn) {
            $conn->send(json_encode(['type' => 'online', 'online' => $online], JSON_UNESCAPED_UNICODE));
        }
    });
};

Worker::runAll();

/**
 * 持久化在线用户（去重后）
 *
 * @param array<int, array<int, Worker>> $userConnections
 */
function saveOnlineUsers(array $userConnections): void
{
    $path = dirname(__DIR__, 2) . '/storage/runtime/ws_online.json';
    $users = [];
    foreach ($userConnections as $uid => $_connections) {
        $users[(string) $uid] = true;
    }
    file_put_contents($path, json_encode($users, JSON_UNESCAPED_UNICODE));
}

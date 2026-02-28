<?php
/**
 * 极聊（商用版）- WebSocket 服务器
 *
 * 基于 Workerman 实现实时双向通信
 * 支持：私聊、群聊、@提及、引用回复、心跳保活、断线重连同步
 *
 * 启动方式：php server/ws/server.php start -d
 * 停止方式：php server/ws/server.php stop
 * 重启方式：php server/ws/server.php restart
 *
 * 需要先安装 Workerman：composer require workerman/workerman
 *
 * @package JiLiao
 */

declare(strict_types=1);

// ── 引导 ──────────────────────────────────────────────────────
// ws/server.php 位于 server/ws/，向上2级到达项目根目录
require_once dirname(__DIR__, 2) . '/server/bootstrap.php';
// autoload 由 bootstrap 已通过 composer 加载，此处作兜底
if (!class_exists('Workerman\Worker')) {
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
}

use JiLiao\Core\Auth;
use JiLiao\Core\ChatStorage;
use JiLiao\Core\Database;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

// ── 读取配置 ──────────────────────────────────────────────────
$wsHost  = \JiLiao\Core\Env::get('WS_HOST', '0.0.0.0');
$wsPort  = \JiLiao\Core\Env::get('WS_PORT', '9501');
$wsCount = (int)\JiLiao\Core\Env::get('WS_PROCESSES', '1'); // 生产可改为 CPU 核数

// ── 确保存储目录存在 ─────────────────────────────────────────
$logDir = ROOT_PATH . '/storage/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// ── Workerman 全局路径配置（必须在 new Worker 之前设置）──────
Worker::$logFile    = $logDir . '/workerman.log';
Worker::$pidFile    = $logDir . '/workerman.pid';
Worker::$statusFile = $logDir . '/workerman.status';

// ── 创建 WebSocket Worker ─────────────────────────────────────
$ws = new Worker("websocket://{$wsHost}:{$wsPort}");
$ws->count = $wsCount;
$ws->name  = 'JiLiao-WS';

// ── 全局连接映射（进程内共享）────────────────────────────────
// $clients[uid]         = TcpConnection  (uid → 连接)
// $connUser[conn_id]    = uid             (conn → uid)
// $connGroups[conn_id]  = [gid, ...]      (conn 所在的所有群)
$clients    = [];   // uid => connection
$connUser   = [];   // connection_id => uid
$connGroups = [];   // connection_id => [gid...]

// ──────────────────────────────────────────────────────────────
//  连接建立
// ──────────────────────────────────────────────────────────────
$ws->onConnect = function (TcpConnection $conn) use (&$clients, &$connUser, &$connGroups) {
    // 连接刚建立时未认证，30秒内未完成握手则关闭
    $conn->authTimer = Timer::add(30, function () use ($conn) {
        if (empty($conn->uid)) {
            $conn->close(json_encode(['type' => 'error', 'msg' => '认证超时']));
        }
    }, null, false);
};

// ──────────────────────────────────────────────────────────────
//  收到消息
// ──────────────────────────────────────────────────────────────
$ws->onMessage = function (TcpConnection $conn, string $rawData)
    use (&$clients, &$connUser, &$connGroups) {

    $packet = json_decode($rawData, true);
    if (!$packet || !isset($packet['type'])) return;

    $type = $packet['type'];
    $db   = Database::getInstance();

    switch ($type) {

        // ── 客户端认证握手 ──────────────────────────────────
        case 'auth':
            // 前端传入 session_id（通过 Cookie 或 token）
            $sessionId = $packet['session_id'] ?? '';
            $uid       = verifySession($sessionId, $db);
            if (!$uid) {
                $conn->close(json_encode(['type' => 'error', 'msg' => '认证失败，请重新登录']));
                return;
            }

            // 取消认证超时定时器
            if (!empty($conn->authTimer)) {
                Timer::del($conn->authTimer);
            }

            // 绑定 uid 到连接
            $conn->uid    = $uid;
            $conn->connId = spl_object_id($conn);
            $clients[$uid]              = $conn;
            $connUser[$conn->connId]    = $uid;

            // 加载该用户所在的群列表
            $groups = $db->query(
                'SELECT gid FROM group_members WHERE uid=? AND is_banned=0',
                [$uid]
            );
            $connGroups[$conn->connId] = array_column($groups, 'gid');

            // 标记在线
            $db->execute('UPDATE users SET is_online=1 WHERE uid=?', [$uid]);

            // 通知客户端认证成功
            $conn->send(json_encode(['type' => 'auth_ok', 'uid' => $uid]));

            // 推送离线未读消息数
            pushUnreadCount($conn, $uid, $db);
            break;

        // ── 心跳 ────────────────────────────────────────────
        case 'ping':
            $conn->send(json_encode(['type' => 'pong', 'ts' => time()]));
            break;

        // ── 发送聊天消息 ────────────────────────────────────
        case 'msg':
            if (empty($conn->uid)) return;
            $uid      = $conn->uid;
            $chatType = (int)($packet['chat_type'] ?? 2); // 1=私聊 2=群聊
            $toId     = $packet['to_id'] ?? '';
            $msgType  = (int)($packet['msg_type'] ?? 1);
            $content  = $packet['content'] ?? '';
            $replyId  = $packet['reply_msg_id'] ?? null;
            $atUids   = $packet['at_uids'] ?? [];

            if (!$toId || !$content) return;

            // ── 检查禁言 ─────────────────────────────────────
            if ($chatType === 2) {
                $gm = $db->first(
                    'SELECT is_muted,mute_until FROM group_members WHERE gid=? AND uid=?',
                    [$toId, $uid]
                );
                if ($gm && $gm['is_muted'] && (!$gm['mute_until'] || strtotime($gm['mute_until']) > time())) {
                    $conn->send(json_encode(['type' => 'error', 'msg' => '您已被禁言']));
                    return;
                }
                // 全群禁言检查
                $group = $db->first("SELECT is_muted FROM `groups` WHERE gid=?", [$toId]);
                if ($group && $group['is_muted'] && ($gm['role'] ?? 0) < 1) {
                    $conn->send(json_encode(['type' => 'error', 'msg' => '全群禁言中']));
                    return;
                }
            }

            // ── 获取发送者信息 ────────────────────────────────
            $sender = $db->first('SELECT nickname,avatar,role FROM users WHERE uid=?', [$uid]);

            // ── 获取群头衔 ────────────────────────────────────
            $title = '';
            if ($chatType === 2) {
                $gm2   = $db->first('SELECT title FROM group_members WHERE gid=? AND uid=?', [$toId, $uid]);
                $title = $gm2['title'] ?? '';
            }

            // ── 构造消息对象 ──────────────────────────────────
            $msgId = uuid4();
            $ts    = date('Y-m-d H:i:s');
            $msgPacket = [
                'type'          => 'msg',
                'msg_id'        => $msgId,
                'chat_type'     => $chatType,
                'from_uid'      => $uid,
                'from_nickname' => $sender['nickname'] ?? '',
                'from_avatar'   => $sender['avatar'] ?? '',
                'from_title'    => $title,
                'to_id'         => $toId,
                'msg_type'      => $msgType,
                'content'       => $content,
                'reply_msg_id'  => $replyId,
                'at_uids'       => $atUids,
                'ts'            => $ts,
            ];

            // ── 持久化存储 ────────────────────────────────────
            $storage = new ChatStorage();
            $storage->save([
                'msg_id'       => $msgId,
                'chat_type'    => $chatType,
                'from_uid'     => $uid,
                'to_id'        => $toId,
                'msg_type'     => $msgType,
                'content'      => $content,
                'reply_msg_id' => $replyId,
                'at_uids'      => $atUids,
            ]);

            // ── 推送给接收方 ──────────────────────────────────
            $json = json_encode($msgPacket, JSON_UNESCAPED_UNICODE);
            if ($chatType === 2) {
                // 群聊：推送给所有在线群成员
                broadcastGroup($toId, $json, $clients, $connUser, $connGroups);
            } else {
                // 私聊：推送给对方（如在线）
                $targetUid = (int)$toId;
                if (isset($clients[$targetUid])) {
                    $clients[$targetUid]->send($json);
                }
                // 推送给自己（多设备同步）
                $conn->send($json);
                // 更新未读计数（对方离线时）
                if (!isset($clients[$targetUid])) {
                    $db->execute(
                        "INSERT INTO unread_counts (uid,chat_type,from_id,cnt,updated_at)
                         VALUES (?,?,?,1,NOW())
                         ON DUPLICATE KEY UPDATE cnt=cnt+1, updated_at=NOW()",
                        [$targetUid, $chatType, (string)$uid]
                    );
                }
            }
            break;

        // ── 撤回消息 ────────────────────────────────────────
        case 'recall':
            if (empty($conn->uid)) return;
            $msgId    = $packet['msg_id'] ?? '';
            $storage  = new ChatStorage();
            $result   = $storage->recall($msgId, $conn->uid);
            $conn->send(json_encode(['type' => 'recall_result'] + $result));

            if ($result['code'] === 0) {
                // 广播撤回通知
                $chatType = (int)($packet['chat_type'] ?? 2);
                $toId     = $packet['to_id'] ?? '';
                $notice   = json_encode(['type' => 'recall', 'msg_id' => $msgId, 'from_uid' => $conn->uid]);
                if ($chatType === 2) {
                    broadcastGroup($toId, $notice, $clients, $connUser, $connGroups);
                } elseif (isset($clients[(int)$toId])) {
                    $clients[(int)$toId]->send($notice);
                }
            }
            break;

        // ── 已读/清除未读 ────────────────────────────────────
        case 'read':
            if (empty($conn->uid)) return;
            $chatType = (int)($packet['chat_type'] ?? 2);
            $fromId   = $packet['from_id'] ?? '';
            $db->execute(
                'DELETE FROM unread_counts WHERE uid=? AND chat_type=? AND from_id=?',
                [$conn->uid, $chatType, $fromId]
            );
            break;
    }
};

// ──────────────────────────────────────────────────────────────
//  连接关闭
// ──────────────────────────────────────────────────────────────
$ws->onClose = function (TcpConnection $conn) use (&$clients, &$connUser, &$connGroups) {
    $connId = spl_object_id($conn);
    if (isset($connUser[$connId])) {
        $uid = $connUser[$connId];
        unset($clients[$uid]);
        unset($connUser[$connId]);
        unset($connGroups[$connId]);
        // 标记离线
        try {
            $db = Database::getInstance();
            $db->execute('UPDATE users SET is_online=0 WHERE uid=?', [$uid]);
        } catch (\Throwable $e) {
            // 忽略断开时的数据库异常
        }
    }
};

// ──────────────────────────────────────────────────────────────
//  Worker 启动后全局定时器（仅主进程）
// ──────────────────────────────────────────────────────────────
$ws->onWorkerStart = function (Worker $worker) {
    if ($worker->id === 0) {
        // 每小时清理一次限流缓存
        Timer::add(3600, function () {
            $limiter = new \JiLiao\Core\RateLimiter();
            $limiter->gc(3600);
        });
        // 每天凌晨 2 点清理过期归档（通过检查时间模拟）
        Timer::add(3600, function () {
            $now = (int)date('G'); // 0-23
            if ($now === 2) {
                $storage = new ChatStorage();
                $storage->cleanOldArchives(90);
            }
        });
    }
};

// ──────────────────────────────────────────────────────────────
//  辅助函数
// ──────────────────────────────────────────────────────────────

/**
 * 通过 session_id 验证用户（读取 PHP Session 文件）
 *
 * @param  string   $sessionId Session ID
 * @param  Database $db
 * @return int      uid，失败返回 0
 */
function verifySession(string $sessionId, Database $db): int
{
    if (!preg_match('/^[a-zA-Z0-9,\-]{20,128}$/', $sessionId)) return 0;

    // 读取 session 文件（默认存 /tmp/sess_xxx）
    $sessionFile = session_save_path() . "/sess_{$sessionId}";
    if (!file_exists($sessionFile)) {
        // 尝试默认路径
        $sessionFile = sys_get_temp_dir() . "/sess_{$sessionId}";
    }
    if (!file_exists($sessionFile)) return 0;

    $raw = file_get_contents($sessionFile);
    if (!$raw) return 0;

    // 解析 PHP Session 序列化格式
    if (preg_match('/jl_user\|.*?uid[^;]+;i:(\d+);/', $raw, $m)) {
        return (int)$m[1];
    }
    // 备用：解析整个 session
    session_id($sessionId);
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $uid = (int)($_SESSION['jl_user']['uid'] ?? 0);
    @session_write_close();
    return $uid;
}

/**
 * 向群组所有在线成员广播消息
 *
 * @param  string $gid         群ID
 * @param  string $json        已编码的JSON字符串
 * @param  array  &$clients    uid => connection
 * @param  array  &$connUser   conn_id => uid
 * @param  array  &$connGroups conn_id => [gid...]
 */
function broadcastGroup(string $gid, string $json, array &$clients, array &$connUser, array &$connGroups): void
{
    foreach ($connGroups as $connId => $groups) {
        if (in_array($gid, $groups, true)) {
            $uid = $connUser[$connId] ?? null;
            if ($uid && isset($clients[$uid])) {
                $clients[$uid]->send($json);
            }
        }
    }
}

/**
 * 推送未读消息总数给刚上线的用户
 *
 * @param  TcpConnection $conn
 * @param  int           $uid
 * @param  Database      $db
 */
function pushUnreadCount(TcpConnection $conn, int $uid, Database $db): void
{
    $unreads = $db->query(
        'SELECT chat_type, from_id, cnt FROM unread_counts WHERE uid=?',
        [$uid]
    );
    $conn->send(json_encode(['type' => 'unread', 'data' => $unreads]));
}

// ── 运行 Worker ───────────────────────────────────────────────
Worker::runAll();

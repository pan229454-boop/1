<?php
/**
 * 极聊（商用版）- API 总路由入口
 *
 * URL 格式：/api.php?action=xxx  或 Nginx 重写后 /api/xxx
 * 所有接口统一 JSON 输出，Content-Type: application/json; charset=UTF-8
 *
 * @package JiLiao
 */

declare(strict_types=1);

// ── 引导 ──────────────────────────────────────────────────────
require_once dirname(__DIR__, 2) . '/server/bootstrap.php';

use JiLiao\Core\Auth;
use JiLiao\Core\ChatStorage;
use JiLiao\Core\Database;
use JiLiao\Core\RateLimiter;
use JiLiao\Core\Response;

// ── CORS 跨域（生产环境应限制 Origin）────────────────────────────
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

// ── 全局限流：每 IP 每分钟最多 120 次 ─────────────────────────
$limiter = new RateLimiter();
$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$clientIp = explode(',', $clientIp)[0];

if (!$limiter->check("ip:{$clientIp}", 120, 60)) {
    Response::fail('请求过于频繁，请稍后重试', 429, 429);
}

// ── 解析 action ────────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$action = preg_replace('/[^a-zA-Z0-9_\/.\\-]/', '', $action);

// ── 读取 JSON Body（POST）─────────────────────────────────────
$body = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw ?: '{}', true) ?: $_POST;
}

// ── 整合参数 ─────────────────────────────────────────────────
$data = array_merge($_GET, $body);

// ── 路由分发 ─────────────────────────────────────────────────
$auth    = new Auth();
$db      = Database::getInstance();
$storage = new ChatStorage();

switch ($action) {

    // ────────── 用户认证 ──────────────────────────────────────

    case 'auth/register':
        Response::json($auth->register($data));
        break;

    case 'auth/login':
        $res = $auth->login(
            $data['username'] ?? '',
            $data['password'] ?? '',
            $clientIp
        );
        Response::json($res);
        break;

    case 'auth/logout':
        $deleteAccount = ($data['delete_account'] ?? '0') === '1';
        $auth->logout($deleteAccount);
        Response::json(['code' => 0, 'msg' => '已注销']);
        break;

    case 'auth/me':
        $user = $auth->user();
        Response::json(['code' => 0, 'data' => $user]);
        break;

    // ────────── 上传头像 ──────────────────────────────────────

    case 'user/avatar':
        $me = $auth->requireLogin();
        $result = handleAvatarUpload($me['uid'], $db);
        Response::json($result);
        break;

    // ────────── 用户资料 ──────────────────────────────────────

    case 'user/info':
        $uid = (int)($data['uid'] ?? 0);
        if (!$uid) Response::fail('uid 必填');
        $user = $db->first('SELECT uid,nickname,avatar,role,is_online,created_at FROM users WHERE uid=?', [$uid]);
        Response::json(['code' => 0, 'data' => $user]);
        break;

    case 'user/online_count':
        $total  = (int)$db->single('SELECT COUNT(*) FROM users WHERE status=1');
        $online = (int)$db->single('SELECT COUNT(*) FROM users WHERE is_online=1');
        Response::json(['code' => 0, 'data' => ['total' => $total, 'online' => $online]]);
        break;

    // ────────── 好友 ─────────────────────────────────────────

    case 'friend/list':
        $me = $auth->requireLogin();
        $friends = $db->query(
            'SELECT u.uid,u.nickname,u.avatar,u.is_online
             FROM friendships f
             JOIN users u ON u.uid = IF(f.uid_a=?, f.uid_b, f.uid_a)
             WHERE (f.uid_a=? OR f.uid_b=?) AND f.status=1',
            [$me['uid'], $me['uid'], $me['uid']]
        );
        Response::json(['code' => 0, 'data' => $friends]);
        break;

    case 'friend/add':
        $me      = $auth->requireLogin();
        $target  = (int)($data['target_uid'] ?? 0);
        if (!$target || $target === $me['uid']) Response::fail('无效的目标用户');
        $a = min($me['uid'], $target); $b = max($me['uid'], $target);
        $exists = $db->first('SELECT id,status FROM friendships WHERE uid_a=? AND uid_b=?', [$a, $b]);
        if ($exists) {
            if ($exists['status'] == 1) Response::fail('已经是好友了');
            $db->execute('UPDATE friendships SET status=1 WHERE uid_a=? AND uid_b=?', [$a, $b]);
        } else {
            $db->execute('INSERT INTO friendships (uid_a,uid_b,status) VALUES (?,?,1)', [$a, $b]);
        }
        Response::json(['code' => 0, 'msg' => '添加成功']);
        break;

    // ────────── 群组 ─────────────────────────────────────────

    case 'group/list':
        $me = $auth->requireLogin();
        $groups = $db->query(
            'SELECT g.gid,g.name,g.avatar,g.is_default,gm.role,gm.title,
                    (SELECT COUNT(*) FROM group_members WHERE gid=g.gid AND is_banned=0) as member_count
             FROM group_members gm
             JOIN `groups` g ON g.gid=gm.gid
             WHERE gm.uid=? AND g.status=1',
            [$me['uid']]
        );
        Response::json(['code' => 0, 'data' => $groups]);
        break;

    case 'group/create':
        $me   = $auth->requireLogin();
        $name = trim($data['name'] ?? '');
        if (!$name) Response::fail('群名称不能为空');
        // 分配 gid (0002–9999)
        $maxGid = $db->single("SELECT COALESCE(MAX(CAST(gid AS UNSIGNED)),1) FROM `groups`");
        $newGid = str_pad((string)((int)$maxGid + 1), 4, '0', STR_PAD_LEFT);
        if ((int)$newGid > 9999) Response::fail('群组容量已满');
        $db->execute("INSERT INTO `groups` (gid,name,owner_uid,status,created_at) VALUES (?,?,?,1,NOW())", [$newGid, $name, $me['uid']]);
        $db->execute('INSERT INTO group_members (gid,uid,role) VALUES (?,?,2)', [$newGid, $me['uid']]);
        Response::json(['code' => 0, 'msg' => '群组创建成功', 'gid' => $newGid]);
        break;

    case 'group/info':
        $gid = $data['gid'] ?? '';
        if (!$gid) Response::fail('gid 必填');
        $group = $db->first("SELECT * FROM `groups` WHERE gid=?", [$gid]);
        $members = $db->query(
            'SELECT u.uid,u.nickname,u.avatar,u.is_online,gm.role,gm.title,gm.is_muted
             FROM group_members gm JOIN users u ON u.uid=gm.uid
             WHERE gm.gid=? AND gm.is_banned=0 ORDER BY gm.role DESC',
            [$gid]
        );
        Response::json(['code' => 0, 'data' => ['group' => $group, 'members' => $members]]);
        break;

    case 'group/join':
        $me  = $auth->requireLogin();
        $gid = $data['gid'] ?? '';
        if (!$gid) Response::fail('gid 必填');
        $group = $db->first("SELECT id,join_approval,status FROM `groups` WHERE gid=?", [$gid]);
        if (!$group || $group['status'] != 1) Response::fail('群不存在或已解散');
        $already = $db->first('SELECT id FROM group_members WHERE gid=? AND uid=?', [$gid, $me['uid']]);
        if ($already) Response::fail('您已在群中');
        if ($group['join_approval']) {
            // 需要审批：写入申请记录
            $db->execute("INSERT INTO join_requests (gid,uid,status,created_at) VALUES (?,?,'pending',NOW()) ON DUPLICATE KEY UPDATE status='pending'", [$gid, $me['uid']]);
            Response::json(['code' => 0, 'msg' => '已发送入群申请，请等待审核']);
        } else {
            $db->execute('INSERT INTO group_members (gid,uid,role) VALUES (?,?,0)', [$gid, $me['uid']]);
            Response::json(['code' => 0, 'msg' => '入群成功']);
        }
        break;

    case 'group/kick':
        $me     = $auth->requireRole(1);
        $gid    = $data['gid'] ?? '';
        $target = (int)($data['target_uid'] ?? 0);
        $me_gm  = $db->first('SELECT role FROM group_members WHERE gid=? AND uid=?', [$gid, $me['uid']]);
        if (!$me_gm || $me_gm['role'] < 1) Response::fail('权限不足');
        $db->execute('UPDATE group_members SET is_banned=1 WHERE gid=? AND uid=?', [$gid, $target]);
        Response::json(['code' => 0, 'msg' => '已踢出']);
        break;

    case 'group/mute':
        $me     = $auth->requireRole(1);
        $gid    = $data['gid'] ?? '';
        $target = (int)($data['target_uid'] ?? 0);
        $until  = $data['until'] ?? null; // datetime string 或 null=取消
        $me_gm  = $db->first('SELECT role FROM group_members WHERE gid=? AND uid=?', [$gid, $me['uid']]);
        if (!$me_gm || $me_gm['role'] < 1) Response::fail('权限不足');
        $db->execute('UPDATE group_members SET is_muted=?, mute_until=? WHERE gid=? AND uid=?',
            [$until ? 1 : 0, $until, $gid, $target]);
        Response::json(['code' => 0, 'msg' => $until ? '禁言成功' : '已解除禁言']);
        break;

    case 'group/dissolve':
        $me  = $auth->requireLogin();
        $gid = $data['gid'] ?? '';
        $g   = $db->first("SELECT owner_uid,is_default FROM `groups` WHERE gid=?", [$gid]);
        if (!$g) Response::fail('群不存在');
        if ($g['is_default']) Response::fail('综合群不可解散');
        if ($g['owner_uid'] != $me['uid'] && $me['role'] < 9) Response::fail('只有群主可解散群');
        $db->execute("UPDATE `groups` SET status=0 WHERE gid=?", [$gid]);
        Response::json(['code' => 0, 'msg' => '群已解散']);
        break;

    case 'group/transfer':
        $me     = $auth->requireLogin();
        $gid    = $data['gid'] ?? '';
        $target = (int)($data['target_uid'] ?? 0);
        $g      = $db->first("SELECT owner_uid FROM `groups` WHERE gid=?", [$gid]);
        if (!$g || $g['owner_uid'] != $me['uid']) Response::fail('只有群主可转让');
        $db->execute("UPDATE `groups` SET owner_uid=? WHERE gid=?", [$target, $gid]);
        $db->execute('UPDATE group_members SET role=2 WHERE gid=? AND uid=?', [$gid, $target]);
        $db->execute('UPDATE group_members SET role=0 WHERE gid=? AND uid=?', [$gid, $me['uid']]);
        Response::json(['code' => 0, 'msg' => '群主已转让']);
        break;

    // ────────── 消息 ─────────────────────────────────────────

    case 'msg/history':
        $auth->requireLogin();
        $chatType = (int)($data['chat_type'] ?? 2);
        $toId     = $data['to_id'] ?? '';
        $date     = $data['date'] ?? '';
        $limit    = min((int)($data['limit'] ?? 50), 200);
        $me       = $auth->user();
        $msgs     = $storage->load($chatType, $me['uid'], $toId, $limit, $date);
        Response::json(['code' => 0, 'data' => $msgs]);
        break;

    case 'msg/send':
        $me = $auth->requireLogin();
        // WebSocket 为主，此接口用于降级 HTTP 发送
        $msg = [
            'msg_id'       => uuid4(),
            'chat_type'    => (int)($data['chat_type'] ?? 2),
            'from_uid'     => $me['uid'],
            'to_id'        => $data['to_id'] ?? '',
            'msg_type'     => (int)($data['msg_type'] ?? 1),
            'content'      => $data['content'] ?? '',
            'reply_msg_id' => $data['reply_msg_id'] ?? null,
            'at_uids'      => $data['at_uids'] ?? [],
        ];
        if (!$msg['to_id']) Response::fail('to_id 不能为空');
        $result = $storage->save($msg);
        Response::json($result);
        break;

    case 'msg/recall':
        $me    = $auth->requireLogin();
        $msgId = $data['msg_id'] ?? '';
        Response::json($storage->recall($msgId, $me['uid']));
        break;

    case 'msg/search':
        $me       = $auth->requireLogin();
        $chatType = (int)($data['chat_type'] ?? 2);
        $toId     = $data['to_id'] ?? '';
        $keyword  = $data['keyword'] ?? '';
        if (!$keyword) Response::fail('请输入搜索关键词');
        $results  = $storage->search($chatType, $me['uid'], $toId, $keyword, 30);
        Response::json(['code' => 0, 'data' => $results]);
        break;

    case 'msg/top':
        $me     = $auth->requireLogin();
        $msgId  = $data['msg_id'] ?? '';
        $isTop  = (int)($data['is_top'] ?? 1);
        $db->execute('UPDATE messages SET is_top=? WHERE msg_id=?', [$isTop, $msgId]);
        Response::json(['code' => 0, 'msg' => $isTop ? '已置顶' : '已取消置顶']);
        break;

    case 'msg/essence':
        $me       = $auth->requireLogin();
        $msgId    = $data['msg_id'] ?? '';
        $isEssence = (int)($data['is_essence'] ?? 1);
        $db->execute('UPDATE messages SET is_essence=? WHERE msg_id=?', [$isEssence, $msgId]);
        Response::json(['code' => 0, 'msg' => $isEssence ? '已设为精华' : '已取消精华']);
        break;

    // ────────── 公告 ─────────────────────────────────────────

    case 'notice/get':
        $gid    = $data['gid'] ?? '';
        $notice = $db->first('SELECT * FROM notices WHERE gid=? ORDER BY created_at DESC LIMIT 1', [$gid]);
        Response::json(['code' => 0, 'data' => $notice]);
        break;

    case 'notice/set':
        $me      = $auth->requireRole(1);
        $gid     = $data['gid'] ?? '';
        $content = trim($data['content'] ?? '');
        if (!$content) Response::fail('公告内容不能为空');
        $db->execute('INSERT INTO notices (gid,content,created_by,created_at) VALUES (?,?,?,NOW())',
            [$gid, $content, $me['uid']]);
        Response::json(['code' => 0, 'msg' => '群公告已更新']);
        break;

    // ────────── 文件上传 ─────────────────────────────────────

    case 'upload/image':
        $me = $auth->requireLogin();
        Response::json(handleImageUpload($me['uid'], $me['role'], $db));
        break;

    // ────────── 管理后台 ─────────────────────────────────────

    case 'admin/settings/get':
        $auth->requireRole(3);
        $settings = $db->query('SELECT `key`,val FROM settings');
        $map = array_column($settings, 'val', 'key');
        Response::json(['code' => 0, 'data' => $map]);
        break;

    case 'admin/settings/save':
        $auth->requireRole(3);
        foreach ((array)($data['settings'] ?? []) as $k => $v) {
            $k = preg_replace('/[^a-zA-Z0-9_]/', '', $k);
            $db->execute("INSERT INTO settings (`key`,val) VALUES (?,?) ON DUPLICATE KEY UPDATE val=?", [$k, $v, $v]);
        }
        Response::json(['code' => 0, 'msg' => '设置已保存']);
        break;

    case 'admin/stats':
        $auth->requireRole(3);
        $totalUsers    = (int)$db->single('SELECT COUNT(*) FROM users');
        $totalGroups   = (int)$db->single('SELECT COUNT(*) FROM `groups`');
        $totalMessages = (int)$db->single('SELECT COUNT(*) FROM messages');
        // 在线人数：5 分钟内有活跃 session 即视为在线（简化：取 last_login_at 最近 5 分钟）
        $onlineUsers   = (int)$db->single("SELECT COUNT(*) FROM users WHERE last_login_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
        Response::json(['code' => 0, 'data' => [
            'total_users'    => $totalUsers,
            'online_users'   => $onlineUsers,
            'total_groups'   => $totalGroups,
            'total_messages' => $totalMessages,
        ]]);
        break;

    case 'admin/users':
        $auth->requireRole(3);
        $page  = max(1, (int)($data['page'] ?? 1));
        $size  = min(50, (int)($data['size'] ?? 20));
        $offset = ($page - 1) * $size;
        $total = (int)$db->single('SELECT COUNT(*) FROM users');
        $users = $db->query("SELECT uid,username,nickname,role,status,email,phone,created_at,last_login_at FROM users ORDER BY id DESC LIMIT {$size} OFFSET {$offset}");
        Response::json(['code' => 0, 'data' => ['total' => $total, 'list' => $users, 'page' => $page, 'size' => $size]]);
        break;

    case 'admin/user/status':
        $auth->requireRole(3);
        $uid    = (int)($data['uid'] ?? 0);
        $status = (int)($data['status'] ?? 1);
        if (!in_array($status, [0, 1, 2])) Response::fail('无效状态值');
        $db->execute('UPDATE users SET status=? WHERE uid=?', [$status, $uid]);
        Response::json(['code' => 0, 'msg' => '状态已更新']);
        break;

    case 'admin/groups':
        $auth->requireRole(3);
        $groups = $db->query("SELECT g.*,(SELECT COUNT(*) FROM group_members WHERE gid=g.gid) as mc FROM `groups` g ORDER BY g.id DESC LIMIT 100");
        Response::json(['code' => 0, 'data' => $groups]);
        break;

    case 'admin/mail/test':
        $auth->requireRole(9);
        $to = $data['to'] ?? '';
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) Response::fail('邮箱格式错误');
        Response::json(sendTestMail($to, $db));
        break;

    default:
        Response::fail('未知接口: ' . $action, 404, 404);
}

// ──────────────────────────────────────────────────────────────
//  辅助函数
// ──────────────────────────────────────────────────────────────

/**
 * 处理头像上传
 * 支持格式：jpg/png/gif/webp，默认最大 2 MB
 */
function handleAvatarUpload(int $uid, Database $db): array
{
    if (!isset($_FILES['avatar'])) return ['code' => 400, 'msg' => '未上传文件'];
    $f      = $_FILES['avatar'];
    $maxMB  = 2;
    $allow  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    if ($f['size'] > $maxMB * 1048576) return ['code' => 400, 'msg' => "头像不能超过 {$maxMB}MB"];
    $mime = mime_content_type($f['tmp_name']);
    if (!in_array($mime, $allow)) return ['code' => 400, 'msg' => '仅支持 jpg/png/gif/webp'];

    $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime];
    $dir  = UPLOAD_PATH . '/avatars';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $path = "/uploads/avatars/av_{$uid}_{time()}.{$ext}";
    move_uploaded_file($f['tmp_name'], UPLOAD_PATH . "/avatars/av_{$uid}_" . time() . ".{$ext}");
    $db->execute('UPDATE users SET avatar=? WHERE uid=?', [$path, $uid]);
    return ['code' => 0, 'path' => $path];
}

/**
 * 处理图片消息上传
 * 按角色限制大小：普通1MB / 会员5MB / 管理员10MB
 */
function handleImageUpload(int $uid, int $role, Database $db): array
{
    $limits = [1 => 1, 2 => 5, 3 => 10, 9 => 20]; // MB
    $maxMB  = $limits[$role] ?? 1;

    if (!isset($_FILES['image'])) return ['code' => 400, 'msg' => '未上传文件'];
    $f    = $_FILES['image'];
    $mime = mime_content_type($f['tmp_name']);
    $allow = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    if ($f['size'] > $maxMB * 1048576) return ['code' => 400, 'msg' => "图片不能超过 {$maxMB}MB"];
    if (!in_array($mime, $allow)) return ['code' => 400, 'msg' => '仅支持图片文件'];

    // 检查每日上传数量（普通用户限 20 张）
    $today  = date('Ymd');
    $cntKey = "upload_count_{$uid}_{$today}";
    // 简单用文件计数
    $cntFile = STORAGE_PATH . "/cache/uc_{$uid}_{$today}.cnt";
    $cnt     = file_exists($cntFile) ? (int)file_get_contents($cntFile) : 0;
    $dayLimit = $role >= 3 ? 9999 : ($role >= 2 ? 100 : 20);
    if ($cnt >= $dayLimit) return ['code' => 429, 'msg' => '今日上传已达上限'];

    file_put_contents($cntFile, $cnt + 1);

    $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime];
    $name = 'img_' . $uid . '_' . time() . '_' . mt_rand(1000, 9999) . ".{$ext}";
    $dir  = UPLOAD_PATH . '/images/' . date('Ym');
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    move_uploaded_file($f['tmp_name'], "{$dir}/{$name}");

    return ['code' => 0, 'url' => "/uploads/images/" . date('Ym') . "/{$name}"];
}

/**
 * 发送测试邮件（SMTP）
 */
function sendTestMail(string $to, Database $db): array
{
    $cfg = $db->query('SELECT `key`,val FROM settings WHERE `key` LIKE "smtp_%"');
    $s   = array_column($cfg, 'val', 'key');

    $host = $s['smtp_host'] ?? '';
    $port = (int)($s['smtp_port'] ?? 465);
    $user = $s['smtp_user'] ?? '';
    $pass = $s['smtp_pass'] ?? '';
    $from = $s['smtp_from'] ?? $user;

    if (!$host || !$user) return ['code' => 400, 'msg' => '请先配置 SMTP 服务器'];

    // 使用 PHP 内置 SMTP（生产建议用 PHPMailer）
    $headers  = "From: 极聊 <{$from}>\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $subject  = '极聊 - 邮件发送测试';
    $body     = '<h2>测试邮件</h2><p>如果您收到此邮件，说明 SMTP 配置正确。</p>';

    ini_set('SMTP', $host);
    ini_set('smtp_port', (string)$port);
    ini_set('sendmail_from', $from);

    $sent = @mail($to, $subject, $body, $headers);
    return $sent ? ['code' => 0, 'msg' => '测试邮件已发送'] : ['code' => 500, 'msg' => '发送失败，请检查 SMTP 配置'];
}

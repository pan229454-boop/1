<?php

declare(strict_types=1);

use ChatApp\Core\Auth;
use ChatApp\Core\ChatStorage;
use ChatApp\Core\Database;
use ChatApp\Core\Env;
use ChatApp\Core\RateLimiter;
use ChatApp\Core\Response;

require_once __DIR__ . '/../bootstrap.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$pdo = Database::getConnection();

if (!RateLimiter::check($pdo, 'api_request', getSettingInt($pdo, 'ip_rate_limit_per_min', Env::int('IP_RATE_LIMIT_PER_MIN', 120)))) {
    Response::json(false, '请求过于频繁，请稍后重试', [], 429);
}

$route = routePath();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($route === 'health' && $method === 'GET') {
        Response::json(true, 'ok', ['time' => date('Y-m-d H:i:s')]);
    }

    if ($route === 'public/settings' && $method === 'GET') {
        $rows = $pdo->query('SELECT `key`, `value` FROM app_settings WHERE `key` IN ("custom_css_file", "email_verify_enabled")')->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['key']] = (string) $row['value'];
        }
        Response::json(true, 'ok', ['settings' => $map]);
    }

    if ($route === 'auth/register' && $method === 'POST') {
        $input = jsonInput();
        $email = trim((string) ($input['email'] ?? ''));
        $nickname = trim((string) ($input['nickname'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $code = trim((string) ($input['code'] ?? ''));

        if ($nickname === '' || mb_strlen($nickname) > 60) {
            Response::json(false, '昵称长度应在1~60字符');
        }
        if (strlen($password) < 6) {
            Response::json(false, '密码至少6位');
        }

        $emailVerifyEnabled = getSettingBool($pdo, 'email_verify_enabled', Env::bool('EMAIL_VERIFY_ENABLED', false));
        if ($emailVerifyEnabled) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Response::json(false, '邮箱格式不正确');
            }
            verifyEmailCodeOrFail($pdo, $email, 'register', $code);
        } else {
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Response::json(false, '邮箱格式不正确');
            }
        }

        if ($email !== '') {
            $cooldown = $pdo->prepare('SELECT blocked_until FROM user_delete_cooldowns WHERE email = :email LIMIT 1');
            $cooldown->execute(['email' => $email]);
            $cooldownRow = $cooldown->fetch();
            if ($cooldownRow && strtotime((string) $cooldownRow['blocked_until']) > time()) {
                Response::json(false, '该邮箱处于注销冷却期，一个月内不可重新注册');
            }
        }

        $existsStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $existsStmt->execute(['email' => $email]);
        if ($email !== '' && $existsStmt->fetch()) {
            Response::json(false, '该邮箱已注册');
        }

        $nextNo = (int) $pdo->query('SELECT IFNULL(MAX(user_no), 0) + 1 AS n FROM users')->fetch()['n'];
        if ($nextNo > 99999) {
            Response::json(false, '系统用户数已达到上限');
        }

        $insert = $pdo->prepare('INSERT INTO users (user_no, email, phone, password_hash, nickname, avatar, status, is_admin, created_at, updated_at) VALUES (:user_no, :email, :phone, :password_hash, :nickname, :avatar, 1, 0, NOW(), NOW())');
        $insert->execute([
            'user_no' => $nextNo,
            'email' => $email === '' ? null : $email,
            'phone' => '',
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'nickname' => $nickname,
            'avatar' => '/assets/default-avatar.png',
        ]);

        $userId = (int) $pdo->lastInsertId();
        ensureInSystemGroup($pdo, $userId);
        $token = Auth::issueToken($pdo, $userId);

        Response::json(true, '注册成功', ['token' => $token, 'user' => fetchUserById($pdo, $userId)]);
    }

    if ($route === 'auth/login' && $method === 'POST') {
        $input = jsonInput();
        $account = trim((string) ($input['account'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($account === '' || $password === '') {
            Response::json(false, '请输入账号和密码');
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $failLimit = getSettingInt($pdo, 'login_fail_limit', Env::int('LOGIN_FAIL_LIMIT', 5));
        $stmt = $pdo->prepare('SELECT * FROM users WHERE (email = :account OR CAST(user_no AS CHAR) = :account OR nickname = :account) AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['account' => $account]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            $ins = $pdo->prepare('INSERT INTO login_fail_logs (email, ip, created_at) VALUES (:email, :ip, NOW())');
            $ins->execute(['email' => $account, 'ip' => $ip]);
            $recentFail = (int) $pdo->query("SELECT COUNT(*) c FROM login_fail_logs WHERE ip = " . $pdo->quote($ip) . " AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)")->fetch()['c'];
            if ($recentFail >= $failLimit) {
                Response::json(false, '登录失败次数过多，请10分钟后再试', [], 429);
            }
            Response::json(false, '账号或密码错误');
        }

        if ((int) $user['status'] !== 1) {
            Response::json(false, '账号已被禁用或封禁');
        }

        ensureInSystemGroup($pdo, (int) $user['id']);
        $token = Auth::issueToken($pdo, (int) $user['id']);
        $pdo->prepare('UPDATE users SET fail_login_count = 0, last_login_at = NOW(), updated_at = NOW() WHERE id = :id')->execute(['id' => (int) $user['id']]);
        Response::json(true, '登录成功', ['token' => $token, 'user' => fetchUserById($pdo, (int) $user['id'])]);
    }

    if ($route === 'auth/send-email-code' && $method === 'POST') {
        $input = jsonInput();
        $email = trim((string) ($input['email'] ?? ''));
        $scene = trim((string) ($input['scene'] ?? 'register'));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(false, '邮箱格式不正确');
        }
        if (!in_array($scene, ['register', 'delete_account', 'reset_password'], true)) {
            Response::json(false, '场景参数不合法');
        }

        $code = (string) random_int(100000, 999999);
        $stmt = $pdo->prepare('INSERT INTO email_verify_codes (email, scene, code, expired_at, used_at, created_at) VALUES (:email, :scene, :code, DATE_ADD(NOW(), INTERVAL 10 MINUTE), NULL, NOW())');
        $stmt->execute(['email' => $email, 'scene' => $scene, 'code' => $code]);

        $sent = sendEmailCode($email, $scene, $code);
        Response::json(true, $sent ? '验证码已发送' : '未配置SMTP，验证码已生成（测试模式）', [
            'email' => $email,
            'scene' => $scene,
            'debug_code' => Env::bool('APP_DEBUG', false) ? $code : '',
        ]);
    }

    if ($route === 'auth/reset-password' && $method === 'POST') {
        $input = jsonInput();
        $email = trim((string) ($input['email'] ?? ''));
        $code = trim((string) ($input['code'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(false, '邮箱格式不正确');
        }
        if (strlen($password) < 6) {
            Response::json(false, '新密码至少6位');
        }

        verifyEmailCodeOrFail($pdo, $email, 'reset_password', $code);

        $stmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash, updated_at = NOW() WHERE email = :email AND deleted_at IS NULL');
        $stmt->execute(['password_hash' => password_hash($password, PASSWORD_BCRYPT), 'email' => $email]);

        Response::json(true, '密码重置成功');
    }

    $currentUser = Auth::currentUser($pdo);
    if (!$currentUser) {
        Response::json(false, '未登录或登录已失效', [], 401);
    }

    if ($route === 'auth/me' && $method === 'GET') {
        $total = (int) $pdo->query('SELECT COUNT(*) c FROM users WHERE deleted_at IS NULL')->fetch()['c'];
        $online = getOnlineCount();
        Response::json(true, 'ok', ['user' => $currentUser, 'online' => ['total' => $total, 'online' => $online]]);
    }

    if ($route === 'auth/logout' && $method === 'POST') {
        $token = Auth::readBearerToken();
        if ($token !== '') {
            Auth::revokeToken($pdo, $token);
        }
        Response::json(true, '已退出登录');
    }

    if ($route === 'auth/update-profile' && $method === 'POST') {
        $input = jsonInput();
        $nickname = trim((string) ($input['nickname'] ?? ''));
        $avatar = trim((string) ($input['avatar'] ?? ''));

        if ($nickname === '' || mb_strlen($nickname) > 60) {
            Response::json(false, '昵称长度应在1~60字符');
        }

        $stmt = $pdo->prepare('UPDATE users SET nickname = :nickname, avatar = :avatar, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'nickname' => $nickname,
            'avatar' => $avatar !== '' ? $avatar : $currentUser['avatar'],
            'id' => (int) $currentUser['user_id'],
        ]);

        Response::json(true, '资料已更新', ['user' => fetchUserById($pdo, (int) $currentUser['user_id'])]);
    }

    if ($route === 'account/delete' && $method === 'POST') {
        $input = jsonInput();
        $code = trim((string) ($input['code'] ?? ''));
        $email = trim((string) $currentUser['email']);

        if ($email === '') {
            Response::json(false, '当前账号未绑定邮箱，无法注销');
        }

        verifyEmailCodeOrFail($pdo, $email, 'delete_account', $code);

        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET status = 3, nickname = CONCAT("已注销用户", user_no), email = NULL, phone = NULL, avatar = "/assets/default-avatar.png", deleted_at = NOW(), updated_at = NOW() WHERE id = :id')
            ->execute(['id' => (int) $currentUser['user_id']]);
        $pdo->prepare('DELETE FROM user_tokens WHERE user_id = :id')->execute(['id' => (int) $currentUser['user_id']]);
        $pdo->prepare('DELETE FROM chat_members WHERE user_id = :id')->execute(['id' => (int) $currentUser['user_id']]);
        $pdo->prepare('INSERT INTO user_delete_cooldowns (email, blocked_until, reason, created_at) VALUES (:email, DATE_ADD(NOW(), INTERVAL 1 MONTH), :reason, NOW()) ON DUPLICATE KEY UPDATE blocked_until = DATE_ADD(NOW(), INTERVAL 1 MONTH), reason = VALUES(reason), created_at = NOW()')
            ->execute(['email' => $email, 'reason' => '用户主动注销']);
        $pdo->commit();

        Response::json(true, '账号已注销');
    }

    if ($route === 'chats/list' && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT c.id, c.chat_no, c.type, c.name, c.is_system_fixed,
            IFNULL(u2.unread_count, 0) AS unread_count,
            (SELECT m.content FROM messages m WHERE m.chat_id = c.id AND m.is_deleted = 0 ORDER BY m.id DESC LIMIT 1) AS last_content,
            (SELECT m.created_at FROM messages m WHERE m.chat_id = c.id AND m.is_deleted = 0 ORDER BY m.id DESC LIMIT 1) AS last_time
            FROM chats c
            JOIN chat_members cm ON cm.chat_id = c.id AND cm.user_id = :uid AND cm.is_blacklisted = 0
            LEFT JOIN chat_unreads u2 ON u2.chat_id = c.id AND u2.user_id = :uid
            WHERE c.is_dissolved = 0
            ORDER BY IFNULL(last_time, c.created_at) DESC');
        $stmt->execute(['uid' => (int) $currentUser['user_id']]);
        Response::json(true, 'ok', ['items' => $stmt->fetchAll()]);
    }

    if ($route === 'chats/messages' && $method === 'GET') {
        $chatId = (int) ($_GET['chat_id'] ?? 0);
        $beforeId = (int) ($_GET['before_id'] ?? 0);
        mustBeChatMember($pdo, $chatId, (int) $currentUser['user_id']);

        if ($beforeId > 0) {
            $stmt = $pdo->prepare('SELECT m.*, u.nickname sender_nickname, u.avatar sender_avatar, u.user_no sender_no FROM messages m JOIN users u ON u.id = m.sender_user_id WHERE m.chat_id = :chat_id AND m.id < :before_id AND m.is_deleted = 0 ORDER BY m.id DESC LIMIT 50');
            $stmt->execute(['chat_id' => $chatId, 'before_id' => $beforeId]);
        } else {
            $stmt = $pdo->prepare('SELECT m.*, u.nickname sender_nickname, u.avatar sender_avatar, u.user_no sender_no FROM messages m JOIN users u ON u.id = m.sender_user_id WHERE m.chat_id = :chat_id AND m.is_deleted = 0 ORDER BY m.id DESC LIMIT 50');
            $stmt->execute(['chat_id' => $chatId]);
        }

        $items = array_reverse($stmt->fetchAll());
        Response::json(true, 'ok', ['items' => $items]);
    }

    if ($route === 'chats/read' && $method === 'POST') {
        $input = jsonInput();
        $chatId = (int) ($input['chat_id'] ?? 0);
        mustBeChatMember($pdo, $chatId, (int) $currentUser['user_id']);

        $stmt = $pdo->prepare('INSERT INTO chat_unreads (chat_id, user_id, unread_count, updated_at) VALUES (:chat_id, :user_id, 0, NOW()) ON DUPLICATE KEY UPDATE unread_count = 0, updated_at = NOW()');
        $stmt->execute(['chat_id' => $chatId, 'user_id' => (int) $currentUser['user_id']]);

        pushWsEvent(['type' => 'chat_read', 'chat_id' => $chatId, 'user_id' => (int) $currentUser['user_id']]);
        Response::json(true, '已读状态已更新');
    }

    if ($route === 'chats/send' && $method === 'POST') {
        $input = jsonInput();
        $chatId = (int) ($input['chat_id'] ?? 0);
        $msgType = (string) ($input['msg_type'] ?? 'text');
        $content = trim((string) ($input['content'] ?? ''));
        $imageUrl = trim((string) ($input['image_url'] ?? ''));
        $quoteMessageId = (int) ($input['quote_message_id'] ?? 0);

        $memberInfo = mustBeChatMember($pdo, $chatId, (int) $currentUser['user_id']);

        if ($memberInfo && !empty($memberInfo['muted_until']) && strtotime((string) $memberInfo['muted_until']) > time()) {
            Response::json(false, '你已被禁言，暂不可发言');
        }

        if (!in_array($msgType, ['text', 'image'], true)) {
            Response::json(false, '不支持的消息类型');
        }
        if ($msgType === 'text' && $content === '') {
            Response::json(false, '消息内容不能为空');
        }
        if ($msgType === 'image' && $imageUrl === '') {
            Response::json(false, '图片地址不能为空');
        }

        $stmt = $pdo->prepare('INSERT INTO messages (chat_id, sender_user_id, msg_type, content, image_url, quote_message_id, is_recalled, is_deleted, is_pinned, is_featured, created_at) VALUES (:chat_id, :sender_user_id, :msg_type, :content, :image_url, :quote_message_id, 0, 0, 0, 0, NOW())');
        $stmt->execute([
            'chat_id' => $chatId,
            'sender_user_id' => (int) $currentUser['user_id'],
            'msg_type' => $msgType,
            'content' => $content,
            'image_url' => $imageUrl === '' ? null : $imageUrl,
            'quote_message_id' => $quoteMessageId > 0 ? $quoteMessageId : null,
        ]);

        $messageId = (int) $pdo->lastInsertId();
        $message = $pdo->prepare('SELECT m.*, u.nickname sender_nickname, u.avatar sender_avatar, u.user_no sender_no FROM messages m JOIN users u ON u.id = m.sender_user_id WHERE m.id = :id LIMIT 1');
        $message->execute(['id' => $messageId]);
        $messageRow = $message->fetch();

        $incUnread = $pdo->prepare('INSERT INTO chat_unreads (chat_id, user_id, unread_count, updated_at)
            SELECT :chat_id, cm.user_id, 1, NOW() FROM chat_members cm WHERE cm.chat_id = :chat_id AND cm.user_id <> :sender AND cm.is_blacklisted = 0
            ON DUPLICATE KEY UPDATE unread_count = unread_count + 1, updated_at = NOW()');
        $incUnread->execute(['chat_id' => $chatId, 'sender' => (int) $currentUser['user_id']]);

        $chat = fetchChatById($pdo, $chatId);
        ChatStorage::appendTxt((string) $chat['type'], (string) $chat['chat_no'], [
            'message_id' => $messageId,
            'chat_id' => $chatId,
            'chat_no' => $chat['chat_no'],
            'chat_type' => $chat['type'],
            'sender_user_id' => (int) $currentUser['user_id'],
            'sender_nickname' => $currentUser['nickname'],
            'msg_type' => $msgType,
            'content' => $content,
            'image_url' => $imageUrl,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        pushWsEvent(['type' => 'new_message', 'chat_id' => $chatId, 'message' => $messageRow]);
        Response::json(true, '发送成功', ['message' => $messageRow]);
    }

    if ($route === 'chats/recall' && $method === 'POST') {
        $input = jsonInput();
        $messageId = (int) ($input['message_id'] ?? 0);

        $msg = $pdo->prepare('SELECT * FROM messages WHERE id = :id LIMIT 1');
        $msg->execute(['id' => $messageId]);
        $row = $msg->fetch();
        if (!$row) {
            Response::json(false, '消息不存在');
        }

        mustBeChatMember($pdo, (int) $row['chat_id'], (int) $currentUser['user_id']);
        $isOwner = (int) $row['sender_user_id'] === (int) $currentUser['user_id'];
        $isAdmin = (int) $currentUser['is_admin'] === 1;
        if (!$isOwner && !$isAdmin) {
            Response::json(false, '仅可撤回自己消息或管理员操作');
        }

        $pdo->prepare('UPDATE messages SET is_recalled = 1, content = "[已撤回]", image_url = NULL WHERE id = :id')->execute(['id' => $messageId]);
        pushWsEvent(['type' => 'message_recall', 'chat_id' => (int) $row['chat_id'], 'message_id' => $messageId]);
        Response::json(true, '撤回成功');
    }

    if ($route === 'chats/delete-message' && $method === 'POST') {
        $input = jsonInput();
        $messageId = (int) ($input['message_id'] ?? 0);

        $msg = $pdo->prepare('SELECT * FROM messages WHERE id = :id LIMIT 1');
        $msg->execute(['id' => $messageId]);
        $row = $msg->fetch();
        if (!$row) {
            Response::json(false, '消息不存在');
        }

        mustBeChatMember($pdo, (int) $row['chat_id'], (int) $currentUser['user_id']);
        $isOwner = (int) $row['sender_user_id'] === (int) $currentUser['user_id'];
        $isAdmin = (int) $currentUser['is_admin'] === 1;
        if (!$isOwner && !$isAdmin) {
            Response::json(false, '仅可删除自己消息或管理员操作');
        }

        $pdo->prepare('UPDATE messages SET is_deleted = 1 WHERE id = :id')->execute(['id' => $messageId]);
        pushWsEvent(['type' => 'message_delete', 'chat_id' => (int) $row['chat_id'], 'message_id' => $messageId]);
        Response::json(true, '删除成功');
    }

    if ($route === 'groups/create' && $method === 'POST') {
        $input = jsonInput();
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            Response::json(false, '群名称不能为空');
        }

        $nextNo = (int) $pdo->query('SELECT IFNULL(MAX(CAST(chat_no AS UNSIGNED)), 0) + 1 AS n FROM chats WHERE type = "group"')->fetch()['n'];
        $chatNo = str_pad((string) $nextNo, 4, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare('INSERT INTO chats (chat_no, type, name, owner_user_id, is_system_fixed, announcement, is_dissolved, created_at, updated_at) VALUES (:chat_no, "group", :name, :owner_user_id, 0, "", 0, NOW(), NOW())');
        $stmt->execute([
            'chat_no' => $chatNo,
            'name' => $name,
            'owner_user_id' => (int) $currentUser['user_id'],
        ]);
        $chatId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO chat_members (chat_id, user_id, title, role, muted_until, is_blacklisted, joined_at) VALUES (:chat_id, :user_id, "群主", "owner", NULL, 0, NOW())')
            ->execute(['chat_id' => $chatId, 'user_id' => (int) $currentUser['user_id']]);

        Response::json(true, '群创建成功', ['chat_id' => $chatId, 'chat_no' => $chatNo]);
    }

    if ($route === 'groups/members' && $method === 'GET') {
        $chatId = (int) ($_GET['chat_id'] ?? 0);
        mustBeChatMember($pdo, $chatId, (int) $currentUser['user_id']);

        $stmt = $pdo->prepare('SELECT cm.user_id, cm.title, cm.role, cm.muted_until, u.nickname, u.avatar, u.user_no FROM chat_members cm JOIN users u ON u.id = cm.user_id WHERE cm.chat_id = :chat_id AND cm.is_blacklisted = 0 ORDER BY FIELD(cm.role, "owner", "admin", "member"), u.user_no ASC');
        $stmt->execute(['chat_id' => $chatId]);
        Response::json(true, 'ok', ['items' => $stmt->fetchAll()]);
    }

    if ($route === 'groups/manage-member' && $method === 'POST') {
        $input = jsonInput();
        $chatId = (int) ($input['chat_id'] ?? 0);
        $targetUserId = (int) ($input['target_user_id'] ?? 0);
        $action = (string) ($input['action'] ?? '');
        $title = trim((string) ($input['title'] ?? '成员'));

        $operator = mustBeChatMember($pdo, $chatId, (int) $currentUser['user_id']);
        if (!in_array((string) $operator['role'], ['owner', 'admin'], true) && (int) $currentUser['is_admin'] !== 1) {
            Response::json(false, '仅群主/管理员可执行群管理');
        }

        if ($action === 'mute') {
            $minutes = max(1, (int) ($input['minutes'] ?? 10));
            $pdo->prepare('UPDATE chat_members SET muted_until = DATE_ADD(NOW(), INTERVAL :minutes MINUTE) WHERE chat_id = :chat_id AND user_id = :user_id')
                ->execute(['minutes' => $minutes, 'chat_id' => $chatId, 'user_id' => $targetUserId]);
            Response::json(true, '已禁言');
        }
        if ($action === 'unmute') {
            $pdo->prepare('UPDATE chat_members SET muted_until = NULL WHERE chat_id = :chat_id AND user_id = :user_id')
                ->execute(['chat_id' => $chatId, 'user_id' => $targetUserId]);
            Response::json(true, '已解除禁言');
        }
        if ($action === 'kick') {
            $pdo->prepare('DELETE FROM chat_members WHERE chat_id = :chat_id AND user_id = :user_id')
                ->execute(['chat_id' => $chatId, 'user_id' => $targetUserId]);
            Response::json(true, '已移出群聊');
        }
        if ($action === 'blacklist') {
            $pdo->prepare('UPDATE chat_members SET is_blacklisted = 1 WHERE chat_id = :chat_id AND user_id = :user_id')
                ->execute(['chat_id' => $chatId, 'user_id' => $targetUserId]);
            Response::json(true, '已加入黑名单');
        }
        if ($action === 'set_title') {
            $pdo->prepare('UPDATE chat_members SET title = :title WHERE chat_id = :chat_id AND user_id = :user_id')
                ->execute(['title' => $title, 'chat_id' => $chatId, 'user_id' => $targetUserId]);
            Response::json(true, '头衔已更新');
        }
        if ($action === 'transfer_owner') {
            if ((string) $operator['role'] !== 'owner' && (int) $currentUser['is_admin'] !== 1) {
                Response::json(false, '仅群主可转让群');
            }
            $pdo->prepare('UPDATE chat_members SET role = "member", title = "成员" WHERE chat_id = :chat_id AND user_id = :user_id')
                ->execute(['chat_id' => $chatId, 'user_id' => (int) $currentUser['user_id']]);
            $pdo->prepare('UPDATE chat_members SET role = "owner", title = "群主" WHERE chat_id = :chat_id AND user_id = :user_id')
                ->execute(['chat_id' => $chatId, 'user_id' => $targetUserId]);
            $pdo->prepare('UPDATE chats SET owner_user_id = :owner_user_id, updated_at = NOW() WHERE id = :chat_id')
                ->execute(['owner_user_id' => $targetUserId, 'chat_id' => $chatId]);
            Response::json(true, '群主已转让');
        }
        if ($action === 'dissolve') {
            if ((string) $operator['role'] !== 'owner' && (int) $currentUser['is_admin'] !== 1) {
                Response::json(false, '仅群主可解散群');
            }
            $chat = fetchChatById($pdo, $chatId);
            if ((int) $chat['is_system_fixed'] === 1) {
                Response::json(false, '综合聊天群不可解散');
            }
            $pdo->prepare('UPDATE chats SET is_dissolved = 1, updated_at = NOW() WHERE id = :chat_id')->execute(['chat_id' => $chatId]);
            Response::json(true, '群聊已解散');
        }

        Response::json(false, '未知操作');
    }

    if ($route === 'private/open' && $method === 'POST') {
        $input = jsonInput();
        $targetUserId = (int) ($input['target_user_id'] ?? 0);
        if ($targetUserId <= 0 || $targetUserId === (int) $currentUser['user_id']) {
            Response::json(false, '目标用户不合法');
        }

        $chatId = getOrCreatePrivateChat($pdo, (int) $currentUser['user_id'], $targetUserId);
        Response::json(true, 'ok', ['chat_id' => $chatId]);
    }

    if ($route === 'friends/delete' && $method === 'POST') {
        $input = jsonInput();
        $targetUserId = (int) ($input['target_user_id'] ?? 0);
        if ($targetUserId <= 0) {
            Response::json(false, '目标用户不合法');
        }

        $chatId = getOrCreatePrivateChat($pdo, (int) $currentUser['user_id'], $targetUserId);
        $stmt = $pdo->prepare('DELETE FROM chat_members WHERE chat_id = :chat_id AND user_id = :user_id');
        $stmt->execute(['chat_id' => $chatId, 'user_id' => (int) $currentUser['user_id']]);
        Response::json(true, '好友已删除（你将不再看到该私聊）');
    }

    if ($route === 'upload/image' && $method === 'POST') {
        if (!isset($_FILES['image'])) {
            Response::json(false, '未接收到图片文件');
        }
        $maxMb = getSettingInt($pdo, 'upload_image_max_mb', Env::int('UPLOAD_IMAGE_MAX_MB', 0));
        $file = $_FILES['image'];

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            Response::json(false, '文件上传失败');
        }

        $size = (int) $file['size'];
        if ($maxMb > 0 && $size > $maxMb * 1024 * 1024) {
            Response::json(false, '图片超出大小限制');
        }

        $mime = mime_content_type((string) $file['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            Response::json(false, '仅支持常见图片格式');
        }

        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = 'jpg';
        }
        $targetDir = dirname(__DIR__, 2) . '/public/uploads/images/' . date('Ymd');
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }
        $newName = uniqid('img_', true) . '.' . $ext;
        $targetPath = $targetDir . '/' . $newName;
        if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
            Response::json(false, '保存图片失败');
        }

        $url = '/uploads/images/' . date('Ymd') . '/' . $newName;
        Response::json(true, '上传成功', ['url' => $url]);
    }

    if ($route === 'upload/avatar' && $method === 'POST') {
        if (!isset($_FILES['avatar'])) {
            Response::json(false, '未接收到头像文件');
        }

        $file = $_FILES['avatar'];
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            Response::json(false, '头像上传失败');
        }

        $mime = mime_content_type((string) $file['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            Response::json(false, '头像格式不支持');
        }

        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $ext = $ext !== '' ? $ext : 'jpg';
        $targetDir = dirname(__DIR__, 2) . '/public/uploads/avatars';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }
        $newName = 'u' . (int) $currentUser['user_id'] . '_' . time() . '.' . $ext;
        $targetPath = $targetDir . '/' . $newName;
        if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
            Response::json(false, '保存头像失败');
        }

        $url = '/uploads/avatars/' . $newName;
        $pdo->prepare('UPDATE users SET avatar = :avatar, updated_at = NOW() WHERE id = :id')
            ->execute(['avatar' => $url, 'id' => (int) $currentUser['user_id']]);

        Response::json(true, '头像更新成功', ['url' => $url]);
    }

    if (strpos($route, 'admin/') === 0) {
        if ((int) $currentUser['is_admin'] !== 1) {
            Response::json(false, '仅管理员可访问', [], 403);
        }

        if ($route === 'admin/stats' && $method === 'GET') {
            $users = (int) $pdo->query('SELECT COUNT(*) c FROM users WHERE deleted_at IS NULL')->fetch()['c'];
            $online = getOnlineCount();
            $messages = (int) $pdo->query('SELECT COUNT(*) c FROM messages')->fetch()['c'];
            $groups = (int) $pdo->query('SELECT COUNT(*) c FROM chats WHERE type = "group" AND is_dissolved = 0')->fetch()['c'];
            Response::json(true, 'ok', ['users' => $users, 'online' => $online, 'messages' => $messages, 'groups' => $groups]);
        }

        if ($route === 'admin/settings/get' && $method === 'GET') {
            $rows = $pdo->query('SELECT `key`, `value` FROM app_settings')->fetchAll();
            $map = [];
            foreach ($rows as $row) {
                $map[(string) $row['key']] = $row['value'];
            }
            Response::json(true, 'ok', ['settings' => $map]);
        }

        if ($route === 'admin/settings/set' && $method === 'POST') {
            $input = jsonInput();
            $settings = (array) ($input['settings'] ?? []);
            foreach ($settings as $k => $v) {
                $stmt = $pdo->prepare('INSERT INTO app_settings (`key`, `value`, updated_at) VALUES (:k, :v, NOW()) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()');
                $stmt->execute(['k' => (string) $k, 'v' => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v]);
            }
            Response::json(true, '设置已保存');
        }

        if ($route === 'admin/users/list' && $method === 'GET') {
            $rows = $pdo->query('SELECT id, user_no, email, nickname, avatar, status, is_admin, created_at FROM users ORDER BY id DESC LIMIT 500')->fetchAll();
            Response::json(true, 'ok', ['items' => $rows]);
        }

        if ($route === 'admin/users/update' && $method === 'POST') {
            $input = jsonInput();
            $userId = (int) ($input['user_id'] ?? 0);
            $status = (int) ($input['status'] ?? 1);
            $isAdmin = (int) ($input['is_admin'] ?? 0);

            $stmt = $pdo->prepare('UPDATE users SET status = :status, is_admin = :is_admin, updated_at = NOW() WHERE id = :id');
            $stmt->execute(['status' => $status, 'is_admin' => $isAdmin, 'id' => $userId]);
            Response::json(true, '用户已更新');
        }

        if ($route === 'admin/users/reset-password' && $method === 'POST') {
            $input = jsonInput();
            $userId = (int) ($input['user_id'] ?? 0);
            $newPassword = (string) ($input['new_password'] ?? '');
            if (strlen($newPassword) < 6) {
                Response::json(false, '密码至少6位');
            }
            $stmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id');
            $stmt->execute(['password_hash' => password_hash($newPassword, PASSWORD_BCRYPT), 'id' => $userId]);
            Response::json(true, '密码重置成功');
        }

        if ($route === 'admin/chats/list' && $method === 'GET') {
            $rows = $pdo->query('SELECT id, chat_no, type, name, owner_user_id, is_system_fixed, announcement, is_dissolved, created_at FROM chats ORDER BY id DESC LIMIT 500')->fetchAll();
            Response::json(true, 'ok', ['items' => $rows]);
        }

        if ($route === 'admin/chats/set-notice' && $method === 'POST') {
            $input = jsonInput();
            $chatId = (int) ($input['chat_id'] ?? 0);
            $announcement = trim((string) ($input['announcement'] ?? ''));
            $stmt = $pdo->prepare('UPDATE chats SET announcement = :announcement, updated_at = NOW() WHERE id = :id');
            $stmt->execute(['announcement' => $announcement, 'id' => $chatId]);
            Response::json(true, '群公告已更新');
        }

        if ($route === 'admin/txt/search' && $method === 'GET') {
            $keyword = trim((string) ($_GET['keyword'] ?? ''));
            if ($keyword === '') {
                Response::json(false, '请输入搜索关键词');
            }
            $items = ChatStorage::searchTxt($keyword, 200);
            Response::json(true, 'ok', ['items' => $items]);
        }

        if ($route === 'admin/txt/archive' && $method === 'POST') {
            $input = jsonInput();
            $keepDays = max(1, (int) ($input['keep_days'] ?? 30));
            $baseDir = dirname(__DIR__, 2) . '/storage/chat_logs';
            $archiveDir = dirname(__DIR__, 2) . '/storage/archive';
            if (!is_dir($archiveDir)) {
                mkdir($archiveDir, 0777, true);
            }

            $files = glob($baseDir . '/chat_*_*.txt') ?: [];
            $count = 0;
            foreach ($files as $file) {
                if (filemtime($file) < strtotime('-' . $keepDays . ' days')) {
                    $gzPath = $archiveDir . '/' . basename($file) . '.gz';
                    $content = file_get_contents($file);
                    if ($content !== false) {
                        file_put_contents($gzPath, gzencode($content, 9));
                        unlink($file);
                        $count++;
                    }
                }
            }

            Response::json(true, '归档完成', ['archived_count' => $count]);
        }

        if ($route === 'admin/custom-css/upload' && $method === 'POST') {
            if (!isset($_FILES['css'])) {
                Response::json(false, '未接收到CSS文件');
            }
            $file = $_FILES['css'];
            if ((int) $file['error'] !== UPLOAD_ERR_OK) {
                Response::json(false, '上传失败');
            }
            $targetDir = dirname(__DIR__, 2) . '/public/uploads/custom_css';
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0777, true);
            }
            $name = 'custom_' . date('Ymd_His') . '.css';
            $target = $targetDir . '/' . $name;
            if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
                Response::json(false, '保存CSS失败');
            }

            $stmt = $pdo->prepare('INSERT INTO app_settings (`key`, `value`, updated_at) VALUES ("custom_css_file", :v, NOW()) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()');
            $stmt->execute(['v' => '/uploads/custom_css/' . $name]);
            Response::json(true, '自定义CSS已上传', ['path' => '/uploads/custom_css/' . $name]);
        }
    }

    Response::json(false, '接口不存在: ' . $route, [], 404);
} catch (Throwable $exception) {
    Response::json(false, Env::bool('APP_DEBUG', false) ? $exception->getMessage() : '服务异常，请稍后重试', [], 500);
}

function routePath(): string
{
    $r = trim((string) ($_GET['r'] ?? ''), '/');
    if ($r !== '') {
        return $r;
    }
    $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
    if (strpos($path, 'api/') === 0) {
        $path = substr($path, 4);
    }
    return trim($path, '/');
}

function jsonInput(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return $_POST ?: [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : ($_POST ?: []);
}

function verifyEmailCodeOrFail(PDO $pdo, string $email, string $scene, string $code): void
{
    if ($code === '') {
        Response::json(false, '请输入邮箱验证码');
    }
    $stmt = $pdo->prepare('SELECT id FROM email_verify_codes WHERE email = :email AND scene = :scene AND code = :code AND expired_at > NOW() AND used_at IS NULL ORDER BY id DESC LIMIT 1');
    $stmt->execute(['email' => $email, 'scene' => $scene, 'code' => $code]);
    $row = $stmt->fetch();
    if (!$row) {
        Response::json(false, '验证码错误或已过期');
    }
    $pdo->prepare('UPDATE email_verify_codes SET used_at = NOW() WHERE id = :id')->execute(['id' => (int) $row['id']]);
}

function ensureInSystemGroup(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('INSERT IGNORE INTO chat_members (chat_id, user_id, title, role, muted_until, is_blacklisted, joined_at) VALUES (1, :user_id, "成员", "member", NULL, 0, NOW())');
    $stmt->execute(['user_id' => $userId]);
}

function fetchUserById(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT id AS user_id, user_no, email, nickname, avatar, status, is_admin FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();
    return $row ?: [];
}

function fetchChatById(PDO $pdo, int $chatId): array
{
    $stmt = $pdo->prepare('SELECT * FROM chats WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $chatId]);
    $chat = $stmt->fetch();
    if (!$chat) {
        Response::json(false, '聊天不存在');
    }
    return $chat;
}

function mustBeChatMember(PDO $pdo, int $chatId, int $userId): array
{
    $stmt = $pdo->prepare('SELECT * FROM chat_members WHERE chat_id = :chat_id AND user_id = :user_id LIMIT 1');
    $stmt->execute(['chat_id' => $chatId, 'user_id' => $userId]);
    $row = $stmt->fetch();
    if (!$row || (int) $row['is_blacklisted'] === 1) {
        Response::json(false, '你不在该聊天中或已被拉黑', [], 403);
    }
    return $row;
}

function getOrCreatePrivateChat(PDO $pdo, int $a, int $b): int
{
    $x = min($a, $b);
    $y = max($a, $b);
    $chatNo = 'P_' . $x . '_' . $y;

    $stmt = $pdo->prepare('SELECT id FROM chats WHERE chat_no = :chat_no AND type = "private" LIMIT 1');
    $stmt->execute(['chat_no' => $chatNo]);
    $row = $stmt->fetch();
    if ($row) {
        return (int) $row['id'];
    }

    $u1 = fetchUserById($pdo, $a);
    $u2 = fetchUserById($pdo, $b);
    $name = (string) (($u1['nickname'] ?? '用户' . $a) . ' 与 ' . ($u2['nickname'] ?? '用户' . $b) . ' 私聊');

    $ins = $pdo->prepare('INSERT INTO chats (chat_no, type, name, owner_user_id, is_system_fixed, announcement, is_dissolved, created_at, updated_at) VALUES (:chat_no, "private", :name, NULL, 0, "", 0, NOW(), NOW())');
    $ins->execute(['chat_no' => $chatNo, 'name' => $name]);
    $chatId = (int) $pdo->lastInsertId();

    $m = $pdo->prepare('INSERT INTO chat_members (chat_id, user_id, title, role, muted_until, is_blacklisted, joined_at) VALUES (:chat_id, :user_id, "好友", "member", NULL, 0, NOW())');
    $m->execute(['chat_id' => $chatId, 'user_id' => $a]);
    $m->execute(['chat_id' => $chatId, 'user_id' => $b]);

    return $chatId;
}

function getSettingInt(PDO $pdo, string $key, int $default): int
{
    $stmt = $pdo->prepare('SELECT `value` FROM app_settings WHERE `key` = :k LIMIT 1');
    $stmt->execute(['k' => $key]);
    $row = $stmt->fetch();
    if (!$row) {
        return $default;
    }
    return (int) $row['value'];
}

function getSettingBool(PDO $pdo, string $key, bool $default): bool
{
    $val = (string) getSettingInt($pdo, $key, $default ? 1 : 0);
    return in_array(strtolower($val), ['1', 'true', 'yes', 'on'], true);
}

function pushWsEvent(array $event): void
{
    $path = dirname(__DIR__, 2) . '/storage/runtime/ws_events.ndjson';
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, json_encode($event, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function getOnlineCount(): int
{
    $path = dirname(__DIR__, 2) . '/storage/runtime/ws_online.json';
    if (!is_file($path)) {
        return 0;
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return 0;
    }
    return count($data);
}

function sendEmailCode(string $email, string $scene, string $code): bool
{
    $smtpHost = (string) Env::get('SMTP_HOST', '');
    if ($smtpHost === '') {
        return false;
    }

    $subject = '【MiniChat】验证码';
    $content = "场景: {$scene}\n验证码: {$code}\n10分钟内有效。";

    return @mail($email, $subject, $content);
}

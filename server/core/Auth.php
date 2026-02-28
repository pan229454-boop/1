<?php
/**
 * 极聊（商用版）- 用户认证类
 *
 * 负责：注册、登录、会话校验、注销、密码哈希、登录锁定
 *
 * @package JiLiao\Core
 */

declare(strict_types=1);

namespace JiLiao\Core;

class Auth
{
    /** 登录失败最大次数（超出后锁定账户） */
    private const MAX_FAIL = 5;

    /** 锁定时长（秒） = 30 分钟 */
    private const LOCK_SECONDS = 1800;

    /** Session 用户键名 */
    private const SESS_KEY = 'jl_user';

    /** @var Database 数据库实例 */
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
        // 若 Session 未启动则启动
        if (session_status() === PHP_SESSION_NONE) {
            $this->startSession();
        }
    }

    // ──────────────────────────────────────────────────────────
    //  Session 配置
    // ──────────────────────────────────────────────────────────

    /**
     * 安全启动 Session
     */
    private function startSession(): void
    {
        $secure   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $lifetime = (int)Env::get('SESSION_LIFETIME', '86400'); // 默认 1 天

        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    // ──────────────────────────────────────────────────────────
    //  注册
    // ──────────────────────────────────────────────────────────

    /**
     * 用户注册
     *
     * @param  array $data 包含 username/nickname/password/email/phone 等字段
     * @return array ['code'=>0,'msg'=>'ok','uid'=>xxx]
     */
    public function register(array $data): array
    {
        $username = trim($data['username'] ?? '');
        $nickname = trim($data['nickname'] ?? '') ?: $username;
        $password = $data['password'] ?? '';
        $email    = trim($data['email'] ?? '');
        $phone    = trim($data['phone'] ?? '');

        // ── 基础校验 ──────────────────────────────────────────
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
            return ['code' => 400, 'msg' => '用户名须为3-20位字母/数字/下划线'];
        }
        if (mb_strlen($password) < 6) {
            return ['code' => 400, 'msg' => '密码长度不能少于6位'];
        }

        // ── 邮箱验证开关 ─────────────────────────────────────
        $emailRequired = $this->getSetting('email_verify_required', '0') === '1';
        if ($emailRequired) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['code' => 400, 'msg' => '邮箱格式不正确'];
            }
        }

        // ── 手机号验证开关 ────────────────────────────────────
        $phoneRequired = $this->getSetting('phone_verify_required', '0') === '1';
        if ($phoneRequired) {
            $phoneRegex = $this->getSetting('phone_regex', '/^1[3-9]\d{9}$/');
            if (!preg_match($phoneRegex, $phone)) {
                return ['code' => 400, 'msg' => '手机号格式不正确'];
            }
        }

        // ── 查重 ─────────────────────────────────────────────
        if ($this->db->first('SELECT id FROM users WHERE username=?', [$username])) {
            return ['code' => 409, 'msg' => '该用户名已被注册'];
        }
        if ($email && $this->db->first('SELECT id FROM users WHERE email=?', [$email])) {
            return ['code' => 409, 'msg' => '该邮箱已被注册'];
        }

        // ── 分配顺序 UID（1–99999）────────────────────────────
        $maxUid = $this->db->single('SELECT COALESCE(MAX(uid),0) FROM users');
        $newUid = (int)$maxUid + 1;
        if ($newUid > 99999) {
            return ['code' => 503, 'msg' => '账号容量已满，请联系管理员'];
        }

        // ── 密码哈希 ─────────────────────────────────────────
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        // ── 写入用户 ─────────────────────────────────────────
        $this->db->execute(
            'INSERT INTO users (uid,username,nickname,password,email,phone,role,status,created_at)
             VALUES (?,?,?,?,?,?,1,1,NOW())',
            [$newUid, $username, $nickname, $hash, $email, $phone]
        );

        // ── 自动加入综合群（gid=0001） ─────────────────────────
        $this->joinDefaultGroup($newUid);

        return ['code' => 0, 'msg' => '注册成功', 'uid' => $newUid];
    }

    // ──────────────────────────────────────────────────────────
    //  登录
    // ──────────────────────────────────────────────────────────

    /**
     * 用户登录
     *
     * @param  string $username 用户名
     * @param  string $password 明文密码
     * @param  string $ip       客户端IP（用于锁定记录）
     * @return array  ['code'=>0,'msg'=>'ok','user'=>[...]]
     */
    public function login(string $username, string $password, string $ip = ''): array
    {
        // ── 检查 IP/账号登录失败锁定 ──────────────────────────
        $lockKey = 'login_fail_' . md5($username . '_' . $ip);
        $fails   = (int)($this->getCache($lockKey) ?? 0);
        if ($fails >= self::MAX_FAIL) {
            return ['code' => 429, 'msg' => '登录失败次数过多，请30分钟后重试'];
        }

        // ── 查询用户 ─────────────────────────────────────────
        $user = $this->db->first(
            'SELECT id,uid,username,nickname,avatar,role,status,freeze_until,password FROM users WHERE username=?',
            [$username]
        );

        if (!$user || !password_verify($password, $user['password'])) {
            // 累计失败次数
            $this->setCache($lockKey, $fails + 1, self::LOCK_SECONDS);
            $left = self::MAX_FAIL - $fails - 1;
            return ['code' => 401, 'msg' => "用户名或密码错误，还可尝试 {$left} 次"];
        }

        // ── 状态检查 ─────────────────────────────────────────
        if ($user['status'] == 0) {
            return ['code' => 403, 'msg' => '账号已被封禁，请联系管理员'];
        }
        if ($user['status'] == 2) {
            $until = $user['freeze_until'];
            if ($until && strtotime($until) > time()) {
                return ['code' => 403, 'msg' => "账号冻结中，解冻时间：{$until}"];
            }
        }

        // ── 清除失败计数，更新登录信息 ───────────────────────
        $this->deleteCache($lockKey);
        $this->db->execute(
            'UPDATE users SET is_online=1, last_login_at=NOW(), last_ip=? WHERE uid=?',
            [$ip, $user['uid']]
        );

        // ── 写入 Session ─────────────────────────────────────
        $payload = [
            'uid'      => (int)$user['uid'],
            'username' => $user['username'],
            'nickname' => $user['nickname'],
            'avatar'   => $user['avatar'],
            'role'     => (int)$user['role'],
        ];
        $_SESSION[self::SESS_KEY] = $payload;

        return ['code' => 0, 'msg' => '登录成功', 'user' => $payload];
    }

    // ──────────────────────────────────────────────────────────
    //  注销（含30天冻结）
    // ──────────────────────────────────────────────────────────

    /**
     * 注销当前登录状态
     *
     * @param  bool $deleteAccount 是否同时冻结账号（注销流程）
     * @return void
     */
    public function logout(bool $deleteAccount = false): void
    {
        $uid = $this->uid();
        if ($uid && $deleteAccount) {
            // 30 天冻结，而非物理删除（保护数据）
            $until = date('Y-m-d H:i:s', strtotime('+30 days'));
            $this->db->execute(
                'UPDATE users SET status=2, freeze_until=?, is_online=0 WHERE uid=?',
                [$until, $uid]
            );
        } elseif ($uid) {
            $this->db->execute('UPDATE users SET is_online=0 WHERE uid=?', [$uid]);
        }

        // 销毁 Session
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    // ──────────────────────────────────────────────────────────
    //  会话校验
    // ──────────────────────────────────────────────────────────

    /**
     * 获取当前登录用户信息（未登录返回 null）
     *
     * @return array|null
     */
    public function user(): ?array
    {
        return $_SESSION[self::SESS_KEY] ?? null;
    }

    /**
     * 获取当前 uid（未登录返回 0）
     *
     * @return int
     */
    public function uid(): int
    {
        return (int)($_SESSION[self::SESS_KEY]['uid'] ?? 0);
    }

    /**
     * 要求已登录，否则返回 401 并终止
     *
     * @return array 当前用户信息
     */
    public function requireLogin(): array
    {
        $user = $this->user();
        if (!$user) {
            Response::json(['code' => 401, 'msg' => '请先登录'], 401);
            exit;
        }
        return $user;
    }

    /**
     * 要求指定角色
     *
     * @param  int $minRole 最低角色（1=普通 3=管理员 9=超管）
     * @return array 当前用户信息
     */
    public function requireRole(int $minRole): array
    {
        $user = $this->requireLogin();
        if ($user['role'] < $minRole) {
            Response::json(['code' => 403, 'msg' => '权限不足'], 403);
            exit;
        }
        return $user;
    }

    // ──────────────────────────────────────────────────────────
    //  辅助方法
    // ──────────────────────────────────────────────────────────

    /**
     * 自动加入综合群（gid=0001）
     *
     * @param  int $uid 用户UID
     * @return void
     */
    private function joinDefaultGroup(int $uid): void
    {
        // 确保默认群存在（防止安装脚本漏执行时静默失败）
        $groupExists = $this->db->first("SELECT gid FROM `groups` WHERE gid='0001'", []);
        if (!$groupExists) {
            $this->db->execute(
                "INSERT IGNORE INTO `groups` (gid,name,description,owner_uid,is_default,status,created_at) VALUES ('0001','综合群','所有人的默认交流群',0,1,1,NOW())",
                []
            );
        }
        $exists = $this->db->first(
            'SELECT id FROM group_members WHERE gid=? AND uid=?',
            ['0001', $uid]
        );
        if (!$exists) {
            $this->db->execute(
                'INSERT INTO group_members (gid,uid,role,joined_at) VALUES (?,?,0,NOW())',
                ['0001', $uid]
            );
        }
    }

    /**
     * 获取系统设置项
     *
     * @param  string $key     设置键
     * @param  string $default 默认值
     * @return string
     */
    private function getSetting(string $key, string $default = ''): string
    {
        $row = $this->db->first('SELECT val FROM settings WHERE `key`=?', [$key]);
        return $row['val'] ?? $default;
    }

    /**
     * 简易文件缓存：读取
     *
     * @param  string $key 缓存键
     * @return mixed|null
     */
    private function getCache(string $key)
    {
        $file = STORAGE_PATH . '/cache/' . md5($key) . '.cache';
        if (!file_exists($file)) return null;
        $data = unserialize(file_get_contents($file));
        if ($data['expires'] < time()) {
            @unlink($file);
            return null;
        }
        return $data['value'];
    }

    /**
     * 简易文件缓存：写入
     *
     * @param  string $key     缓存键
     * @param  mixed  $value   缓存值
     * @param  int    $seconds 有效秒数
     * @return void
     */
    private function setCache(string $key, $value, int $seconds): void
    {
        $dir = STORAGE_PATH . '/cache';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $file = $dir . '/' . md5($key) . '.cache';
        file_put_contents($file, serialize(['expires' => time() + $seconds, 'value' => $value]));
    }

    /**
     * 简易文件缓存：删除
     *
     * @param  string $key 缓存键
     * @return void
     */
    private function deleteCache(string $key): void
    {
        $file = STORAGE_PATH . '/cache/' . md5($key) . '.cache';
        if (file_exists($file)) @unlink($file);
    }
}

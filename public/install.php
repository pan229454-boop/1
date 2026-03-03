<?php
/**
 * 极聊安装向导
 * 文件：public/install.php
 * ─────────────────────────────────────────────────────────────────
 * 安全机制：安装完成后会在根目录写入 install.lock 文件，
 * 下次访问此页面将直接返回 403。
 * ─────────────────────────────────────────────────────────────────
 */

// 根目录（install.php 在 public/ 下，根目录向上一级）
define('ROOT', realpath(__DIR__ . '/..'));
define('LOCK_FILE', ROOT . '/install.lock');
define('ENV_FILE',  ROOT . '/.env');
define('SCHEMA_FILE', ROOT . '/scripts/schema.sql');

// 已安装锁定
if (file_exists(LOCK_FILE)) {
    http_response_code(403);
    die('<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>已安装</title>
    <style>body{font-family:system-ui,sans-serif;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;background:#f5f7ff;}
    .box{text-align:center;padding:2rem;background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);}
    h2{color:#4f73f8}p{color:#666}</style></head>
    <body><div class="box"><h2>极聊已安装</h2><p>系统已安装完成，请删除 <code>install.lock</code> 后方可重装。</p>
    <a href="/" style="color:#4f73f8">返回首页</a></div></body></html>');
}

// ─────────────────────────────────────────────────────────────────
//  处理表单提交（Ajax JSON 接口）
// ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'check_env': echo json_encode(checkEnvironment()); break;
        case 'test_db':   echo json_encode(testDatabase($_POST)); break;
        case 'run_sql':   echo json_encode(runSchema($_POST));   break;
        case 'create_admin': echo json_encode(createAdmin($_POST)); break;
        case 'write_env': echo json_encode(writeEnv($_POST));   break;
        case 'finish':    echo json_encode(finishInstall());     break;
        default: echo json_encode(['ok' => false, 'msg' => '未知操作']);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────────
//  安装步骤函数
// ─────────────────────────────────────────────────────────────────

/** 步骤 1：环境检测 */
function checkEnvironment(): array {
    $checks = [
        ['name' => 'PHP 版本 ≥ 7.4',    'ok' => version_compare(PHP_VERSION, '7.4.0', '>='), 'val' => PHP_VERSION],
        ['name' => 'PDO 扩展（MySQL）',  'ok' => extension_loaded('pdo_mysql'), 'val' => extension_loaded('pdo_mysql') ? '已加载' : '未加载'],
        ['name' => 'ZipArchive 扩展',    'ok' => class_exists('ZipArchive'), 'val' => class_exists('ZipArchive') ? '已加载' : '未加载'],
        ['name' => 'fileinfo 扩展',      'ok' => extension_loaded('fileinfo'), 'val' => extension_loaded('fileinfo') ? '已加载' : '未加载'],
        ['name' => 'JSON 扩展',          'ok' => extension_loaded('json'), 'val' => extension_loaded('json') ? '已加载' : '未加载'],
        ['name' => 'storage/ 可写',      'ok' => ensureDir(ROOT . '/storage'), 'val' => is_writable(ROOT . '/storage') ? '可写' : '不可写'],
        ['name' => 'public/uploads/ 可写','ok' => ensureDir(ROOT . '/public/uploads'), 'val' => is_writable(ROOT . '/public/uploads') ? '可写' : '不可写'],
        ['name' => '根目录可写（.env）', 'ok' => is_writable(ROOT), 'val' => is_writable(ROOT) ? '可写' : '不可写'],
    ];
    $allOk = array_reduce($checks, fn($c, $i) => $c && $i['ok'], true);
    return ['ok' => $allOk, 'checks' => $checks];
}

/** 确保目录存在并可写 */
function ensureDir(string $path): bool {
    if (!is_dir($path)) { @mkdir($path, 0755, true); }
    return is_writable($path);
}

/** 步骤 2：测试数据库连接 */
function testDatabase(array $post): array {
    try {
        $dsn = "mysql:host={$post['db_host']};port={$post['db_port']};charset=utf8mb4";
        $pdo = new PDO($dsn, $post['db_user'], $post['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // 尝试创建数据库
        $dbname = preg_replace('/[^a-z0-9_]/i', '', $post['db_name']);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        return ['ok' => true, 'msg' => '数据库连接成功'];
    } catch (Exception $e) {
        return ['ok' => false, 'msg' => '连接失败：' . $e->getMessage()];
    }
}

/** 步骤 2（续）：执行 schema.sql */
function runSchema(array $post): array {
    if (!file_exists(SCHEMA_FILE)) {
        return ['ok' => false, 'msg' => '找不到 schema.sql 文件'];
    }
    try {
        $dsn = "mysql:host={$post['db_host']};port={$post['db_port']};dbname={$post['db_name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $post['db_user'], $post['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $sql = file_get_contents(SCHEMA_FILE);

        // 按分号拆分并逐条执行（跳过注释和空语句）
        $stmts = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($stmts as $stmt) {
            if (!empty($stmt) && str_starts_with_ci($stmt, '--') === false) {
                $pdo->exec($stmt);
            }
        }
        return ['ok' => true, 'msg' => '数据库初始化成功'];
    } catch (Exception $e) {
        return ['ok' => false, 'msg' => 'SQL 执行失败：' . $e->getMessage()];
    }
}

/** 大小写不敏感的 str_starts_with */
function str_starts_with_ci(string $s, string $prefix): bool {
    return stripos($s, $prefix) === 0;
}

/** 步骤 3：创建超管账号 */
function createAdmin(array $post): array {
    try {
        $dsn = "mysql:host={$post['db_host']};port={$post['db_port']};dbname={$post['db_name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $post['db_user'], $post['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $username = trim($post['admin_user'] ?? '');
        $password = trim($post['admin_pass'] ?? '');
        $nickname = trim($post['admin_nick'] ?? 'Super Admin');

        if (strlen($username) < 3 || strlen($password) < 6) {
            return ['ok' => false, 'msg' => '用户名至少 3 位，密码至少 6 位'];
        }

        // 检查是否已存在超管
        $exist = $pdo->query("SELECT COUNT(*) FROM `users` WHERE `role` = 9")->fetchColumn();
        if ($exist > 0) {
            return ['ok' => true, 'msg' => '超管账号已存在，跳过创建'];
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        // uid 从 1 开始
        $pdo->exec("INSERT IGNORE INTO `users`(`uid`,`username`,`nickname`,`password`,`role`,`status`,`created_at`)
                    VALUES(1, " . $pdo->quote($username) . ", " . $pdo->quote($nickname) . ", " . $pdo->quote($hash) . ", 9, 1, NOW())");

        // 加入默认群组
        $pdo->exec("INSERT IGNORE INTO `group_members`(`gid`,`uid`,`role`,`joined_at`) VALUES('0001',1,2,NOW())");

        return ['ok' => true, 'msg' => '超管账号创建成功'];
    } catch (Exception $e) {
        return ['ok' => false, 'msg' => '创建失败：' . $e->getMessage()];
    }
}

/** 步骤 4：写入 .env */
function writeEnv(array $post): array {
    $p = $post;
    $lines = [
        "# 极聊环境配置",
        "# 由安装向导自动生成",
        "",
        "APP_NAME=\"{$p['app_name']}\"",
        "APP_URL={$p['app_url']}",
        "APP_DEBUG=false",
        "APP_TIMEZONE=Asia/Shanghai",
        "",
        "DB_HOST={$p['db_host']}",
        "DB_PORT={$p['db_port']}",
        "DB_NAME={$p['db_name']}",
        "DB_USER={$p['db_user']}",
        "DB_PASS={$p['db_pass']}",
        "DB_CHARSET=utf8mb4",
        "",
        "SESSION_NAME=JILIAO_SESS",
        "SESSION_LIFETIME=86400",
        "SESSION_SECURE=" . ($p['https'] === '1' ? 'true' : 'false'),
        "",
        "WS_HOST=0.0.0.0",
        "WS_PORT={$p['ws_port']}",
        "",
        "UPLOAD_MAX_MB=10",
        "UPLOAD_ALLOWED_TYPES=jpg,jpeg,png,gif,webp",
        "",
        "RATE_LIMIT_MAX=120",
        "RATE_LIMIT_WINDOW=60",
        "",
        "CAPTCHA_ENABLED=true",
    ];
    $content = implode("\n", $lines) . "\n";
    if (file_put_contents(ENV_FILE, $content) === false) {
        return ['ok' => false, 'msg' => '无法写入 .env 文件，请检查根目录写权限'];
    }
    return ['ok' => true, 'msg' => '.env 文件写入成功'];
}

/** 步骤 5：写锁并完成 */
function finishInstall(): array {
    if (file_put_contents(LOCK_FILE, date('Y-m-d H:i:s')) === false) {
        return ['ok' => false, 'msg' => '无法写入锁文件，安装未锁定'];
    }
    return ['ok' => true, 'msg' => '安装完成'];
}

// ─────────────────────────────────────────────────────────────────
//  HTML 输出（向导界面）
// ─────────────────────────────────────────────────────────────────
?><!DOCTYPE html>
<html lang="zh-CN" data-theme="light">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>极聊安装向导</title>
  <link rel="stylesheet" href="/assets/css/app.css" />
  <style>
    body { display: flex; min-height: 100vh; align-items: center; justify-content: center; background: var(--surface-2); }
    .install-wrap { width: 100%; max-width: 600px; margin: 2rem auto; }
    .install-card { background: var(--surface-1); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 2rem; }
    .install-header { text-align: center; margin-bottom: 2rem; }
    .install-header h1 { font-size: 1.5rem; font-weight: 800; color: var(--primary); }
    .install-header p  { color: var(--text-muted); font-size: .875rem; margin-top: .25rem; }

    /* 步骤指示器 */
    .steps { display: flex; justify-content: center; gap: 0; margin-bottom: 2rem; }
    .step-dot {
      display: flex; flex-direction: column; align-items: center; gap: .25rem;
      font-size: .72rem; color: var(--text-muted); position: relative; flex: 1;
    }
    .step-dot::before {
      content: ''; position: absolute; top: 12px; left: calc(-50% + 12px); right: calc(50% + 12px);
      height: 2px; background: var(--border);
    }
    .step-dot:first-child::before { display: none; }
    .step-dot .dot {
      width: 24px; height: 24px; border-radius: 50%; border: 2px solid var(--border);
      display: flex; align-items: center; justify-content: center; font-size: .72rem;
      background: var(--surface-1); z-index: 1; font-weight: 700;
    }
    .step-dot.active .dot   { border-color: var(--primary); color: var(--primary); }
    .step-dot.done   .dot   { border-color: #22c55e; background: #22c55e; color: #fff; }
    .step-dot.done::before  { background: #22c55e; }
    .step-dot.active::before { background: var(--primary); }

    /* 环境检查表 */
    .check-row { display: flex; justify-content: space-between; align-items: center; padding: .5rem 0; border-bottom: 1px solid var(--border); font-size: .875rem; }
    .check-row .ok  { color: #16a34a; font-weight: 600; }
    .check-row .err { color: #dc2626; font-weight: 600; }

    /* 步骤面板 */
    .step-panel { display: none; }
    .step-panel.active { display: block; }

    .nav-btns { display: flex; justify-content: space-between; margin-top: 1.5rem; }
  </style>
</head>
<body>
<div class="install-wrap">
  <div class="install-card">
    <div class="install-header">
      <h1>极聊 安装向导</h1>
      <p>请按步骤完成系统安装，全程约 3 分钟</p>
    </div>

    <!-- 步骤指示器 -->
    <div class="steps">
      <div class="step-dot active" id="step-indicator-1"><div class="dot">1</div><span>环境检测</span></div>
      <div class="step-dot"        id="step-indicator-2"><div class="dot">2</div><span>数据库</span></div>
      <div class="step-dot"        id="step-indicator-3"><div class="dot">3</div><span>管理员</span></div>
      <div class="step-dot"        id="step-indicator-4"><div class="dot">4</div><span>系统配置</span></div>
      <div class="step-dot"        id="step-indicator-5"><div class="dot">5</div><span>完成</span></div>
    </div>

    <!-- ── 步骤 1：环境检测 ── -->
    <div class="step-panel active" id="panel-1">
      <h3 style="font-weight:700;margin-bottom:1rem">环境检测</h3>
      <div id="checkList"><div class="spinner" style="margin:2rem auto"></div></div>
      <div class="nav-btns">
        <span></span>
        <button class="btn btn-primary" id="nextTo2" onclick="gotoStep(2)" disabled>下一步 →</button>
      </div>
    </div>

    <!-- ── 步骤 2：数据库配置 ── -->
    <div class="step-panel" id="panel-2">
      <h3 style="font-weight:700;margin-bottom:1rem">数据库配置</h3>
      <div class="form-group">
        <label class="form-label">数据库主机</label>
        <input class="form-input" id="db_host" value="127.0.0.1" placeholder="127.0.0.1" />
      </div>
      <div class="form-group">
        <label class="form-label">端口</label>
        <input class="form-input" id="db_port" value="3306" type="number" />
      </div>
      <div class="form-group">
        <label class="form-label">数据库名称</label>
        <input class="form-input" id="db_name" value="jiliao" placeholder="jiliao" />
      </div>
      <div class="form-group">
        <label class="form-label">用户名</label>
        <input class="form-input" id="db_user" placeholder="root" />
      </div>
      <div class="form-group">
        <label class="form-label">密码</label>
        <input class="form-input" id="db_pass" type="password" placeholder="数据库密码" />
      </div>
      <div id="dbTestResult" style="font-size:.85rem;margin:.5rem 0"></div>
      <div class="nav-btns">
        <button class="btn btn-secondary" onclick="gotoStep(1)">← 上一步</button>
        <button class="btn btn-primary" onclick="testAndInitDB()">测试并初始化 →</button>
      </div>
    </div>

    <!-- ── 步骤 3：管理员账号 ── -->
    <div class="step-panel" id="panel-3">
      <h3 style="font-weight:700;margin-bottom:1rem">创建超级管理员</h3>
      <div class="form-group">
        <label class="form-label">登录用户名（≥3位）</label>
        <input class="form-input" id="admin_user" placeholder="admin" />
      </div>
      <div class="form-group">
        <label class="form-label">密码（≥6位）</label>
        <input class="form-input" id="admin_pass" type="password" placeholder="强密码" />
      </div>
      <div class="form-group">
        <label class="form-label">管理员昵称</label>
        <input class="form-input" id="admin_nick" value="Super Admin" />
      </div>
      <div id="adminResult" style="font-size:.85rem;margin:.5rem 0"></div>
      <div class="nav-btns">
        <button class="btn btn-secondary" onclick="gotoStep(2)">← 上一步</button>
        <button class="btn btn-primary" onclick="createAdminAccount()">下一步 →</button>
      </div>
    </div>

    <!-- ── 步骤 4：系统配置 + 写 .env ── -->
    <div class="step-panel" id="panel-4">
      <h3 style="font-weight:700;margin-bottom:1rem">系统配置</h3>
      <div class="form-group">
        <label class="form-label">站点名称</label>
        <input class="form-input" id="app_name" value="极聊" />
      </div>
      <div class="form-group">
        <label class="form-label">网站 URL（含协议和域名，结尾不含斜杠）</label>
        <input class="form-input" id="app_url" placeholder="https://chat.example.com" />
      </div>
      <div class="form-group">
        <label class="form-label">WebSocket 端口</label>
        <input class="form-input" id="ws_port" value="9501" type="number" />
      </div>
      <div class="form-group" style="display:flex;align-items:center;justify-content:space-between">
        <label class="form-label" style="margin-bottom:0">启用 HTTPS</label>
        <input type="checkbox" id="https" />
      </div>
      <div id="envResult" style="font-size:.85rem;margin:.5rem 0"></div>
      <div class="nav-btns">
        <button class="btn btn-secondary" onclick="gotoStep(3)">← 上一步</button>
        <button class="btn btn-primary" onclick="writeEnvStep()">写入配置 →</button>
      </div>
    </div>

    <!-- ── 步骤 5：完成 ── -->
    <div class="step-panel" id="panel-5">
      <div style="text-align:center;padding:1.5rem 0">
        <div style="font-size:3.5rem">🎉</div>
        <h2 style="margin-top:.75rem;font-size:1.4rem;color:var(--primary)">安装完成！</h2>
        <p style="color:var(--text-muted);margin-top:.5rem;font-size:.9rem">极聊已成功安装，现在可以开始使用了</p>
      </div>
      <div style="background:var(--surface-2);border-radius:var(--radius);padding:1rem;font-size:.82rem;color:var(--text-secondary);margin-bottom:1.5rem">
        <strong>后续步骤：</strong><br>
        1. 前往后台设置 SMTP 邮件，用于验证码发送<br>
        2. 用 Supervisor 守护 WebSocket 进程：<code>php server/ws/server.php start -d</code><br>
        3. 配置 Nginx 反向代理（参考 README.md）<br>
        4. 建议删除或重命名 <code>public/install.php</code>
      </div>
      <div style="display:flex;flex-direction:column;gap:.625rem">
        <a href="/auth.html?tab=login" class="btn btn-primary" style="text-align:center;text-decoration:none">🚀 立即登录</a>
        <a href="/admin.html" class="btn btn-secondary" style="text-align:center;text-decoration:none">⚙️ 进入后台</a>
      </div>
    </div>

  </div><!-- /install-card -->
</div><!-- /install-wrap -->

<div id="toast-container"></div>
<script src="/assets/js/common.js"></script>
<script>
'use strict';

// 临时存储数据库配置（用于后续步骤复用）
const INSTALL = {};
let currentStep = 1;

// ── 页面加载即检测环境 ──
window.addEventListener('DOMContentLoaded', runEnvCheck);

async function runEnvCheck() {
  const res     = await postInstall('check_env', {});
  const checks  = res.checks ?? [];
  const allOk   = res.ok;
  const list    = document.getElementById('checkList');
  list.innerHTML = checks.map(c => `
    <div class="check-row">
      <span>${c.name}</span>
      <span class="${c.ok ? 'ok' : 'err'}">${c.ok ? '✓ ' : '✗ '}${c.val}</span>
    </div>`).join('');

  document.getElementById('nextTo2').disabled = !allOk;
  if (!allOk) {
    list.insertAdjacentHTML('beforeend',
      '<p style="color:#dc2626;font-size:.82rem;margin-top:.75rem">⚠ 存在不满足的环境要求，请先修复后刷新页面重试。</p>');
  }
}

// ── 测试数据库并执行 Schema ──
async function testAndInitDB() {
  const cfg = {
    db_host: document.getElementById('db_host').value.trim(),
    db_port: document.getElementById('db_port').value.trim(),
    db_name: document.getElementById('db_name').value.trim(),
    db_user: document.getElementById('db_user').value.trim(),
    db_pass: document.getElementById('db_pass').value,
  };
  const result = document.getElementById('dbTestResult');
  result.innerHTML = '<span style="color:var(--text-muted)">连接测试中…</span>';
  const r1 = await postInstall('test_db', cfg);
  if (!r1.ok) { result.innerHTML = `<span style="color:#dc2626">✗ ${escHtml(r1.msg)}</span>`; return; }
  result.innerHTML = `<span style="color:#16a34a">✓ ${escHtml(r1.msg)}，正在执行 Schema…</span>`;
  const r2 = await postInstall('run_sql', cfg);
  if (!r2.ok) { result.innerHTML = `<span style="color:#dc2626">✗ ${escHtml(r2.msg)}</span>`; return; }
  result.innerHTML = `<span style="color:#16a34a">✓ ${escHtml(r2.msg)}</span>`;
  Object.assign(INSTALL, cfg);
  setTimeout(() => gotoStep(3), 800);
}

// ── 创建管理员 ──
async function createAdminAccount() {
  const cfg = {
    ...INSTALL,
    admin_user: document.getElementById('admin_user').value.trim(),
    admin_pass: document.getElementById('admin_pass').value,
    admin_nick: document.getElementById('admin_nick').value.trim(),
  };
  const result = document.getElementById('adminResult');
  result.innerHTML = '<span style="color:var(--text-muted)">创建中…</span>';
  const r = await postInstall('create_admin', cfg);
  result.innerHTML = `<span style="color:${r.ok ? '#16a34a' : '#dc2626'}">${r.ok ? '✓' : '✗'} ${escHtml(r.msg)}</span>`;
  if (r.ok) { Object.assign(INSTALL, cfg); setTimeout(() => gotoStep(4), 800); }
}

// ── 写入 .env ──
async function writeEnvStep() {
  const cfg = {
    ...INSTALL,
    app_name: document.getElementById('app_name').value.trim(),
    app_url:  document.getElementById('app_url').value.trim(),
    ws_port:  document.getElementById('ws_port').value.trim(),
    https:    document.getElementById('https').checked ? '1' : '0',
  };
  const result = document.getElementById('envResult');
  result.innerHTML = '<span style="color:var(--text-muted)">写入中…</span>';
  const r = await postInstall('write_env', cfg);
  result.innerHTML = `<span style="color:${r.ok ? '#16a34a' : '#dc2626'}">${r.ok ? '✓' : '✗'} ${escHtml(r.msg)}</span>`;
  if (r.ok) {
    const r2 = await postInstall('finish', {});
    if (r2.ok) setTimeout(() => gotoStep(5), 800);
    else result.innerHTML += `<br><span style="color:#dc2626">✗ ${escHtml(r2.msg)}</span>`;
  }
}

// ── 步骤切换 ──
function gotoStep(n) {
  document.querySelectorAll('.step-panel').forEach(p => p.classList.remove('active'));
  document.getElementById(`panel-${n}`).classList.add('active');

  document.querySelectorAll('.step-dot').forEach((dot, i) => {
    const num = i + 1;
    dot.classList.remove('active', 'done');
    if (num < n)  dot.classList.add('done');
    if (num === n) dot.classList.add('active');
  });
  currentStep = n;
}

// ── HTTP POST 工具 ──
async function postInstall(action, data) {
  const body = new URLSearchParams({ action, ...data });
  const res  = await fetch('/install.php', { method: 'POST', body });
  return res.json();
}
</script>
</body>
</html>

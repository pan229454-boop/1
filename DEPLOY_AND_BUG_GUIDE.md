# 极聊（商用版）—— 完整部署 & 踩坑修复教程

> 本文档整理了从零部署到生产可用所遇到的所有问题，以及每个问题的根因分析和解决方案。
> 适合：宝塔面板 + Linux 云服务器环境。

---

## 目录

1. [部署流程（宝塔版）](#一-部署流程宝塔版)
2. [踩坑汇总（按遇到顺序）](#二-踩坑汇总)
   - 2.1 [WebSocket 认证失败 / 无法收发消息](#21-websocket-认证失败--无法收发消息)
   - 2.2 [安装完成后超管账号被封禁](#22-安装完成后超管账号被封禁)
   - 2.3 [用户默认没有进入默认群组](#23-用户默认没有进入默认群组)
   - 2.4 [后台用户状态显示相反](#24-后台用户状态显示相反)
   - 2.5 [手机端会话列表不显示](#25-手机端会话列表不显示)
   - 2.6 [图片上传成功但无法显示](#26-图片上传成功但无法显示)
   - 2.7 [断线重连时 Toast 骚扰频繁](#27-断线重连时-toast-骚扰频繁)
   - 2.8 [auth/me 在用户未登录时报错](#28-authme-在用户未登录时报错)
   - 2.9 [自动登录 / 记住用户名失效](#29-自动登录--记住用户名失效)
   - 2.10 [管理后台移动端适配差](#210-管理后台移动端适配差)
   - 2.11 [手机端聊天面板头部布局混乱](#211-手机端聊天面板头部布局混乱)
   - 2.12 [历史消息显示 UID 数字而非昵称](#212-历史消息显示-uid-数字而非昵称)
   - 2.13 [群成员列表点击后没有反应](#213-群成员列表点击后没有反应)
   - 2.14 [普通用户可以撤回他人消息](#214-普通用户可以撤回他人消息)
   - 2.15 [撤回时限硬编码无法配置](#215-撤回时限硬编码无法配置)
   - 2.16 [任何用户都可发布群公告](#216-任何用户都可发布群公告)
   - 2.17 [群公告不显示发布人和时间](#217-群公告不显示发布人和时间)
   - 2.18 [群公告无法编辑和删除](#218-群公告无法编辑和删除)
3. [功能扩展说明](#三-功能扩展说明)
   - 3.1 [界面皮肤/主题系统](#31-界面皮肤主题系统)
   - 3.2 [消息撤回角色时限后台配置](#32-消息撤回角色时限后台配置)
4. [生产环境核查清单](#四-生产环境核查清单)
5. [推荐后续改进](#五-推荐后续改进)

---

## 一、部署流程（宝塔版）

### 1.1 环境准备

| 软件 | 版本要求 | 宝塔安装路径 |
|------|---------|------------|
| PHP  | 8.1 推荐 | 软件商店 → PHP 8.1 |
| MySQL | 5.7+ / MariaDB 10.3+ | 软件商店 → MySQL 8.0 |
| Nginx | 1.16+ | 软件商店 → Nginx |
| Supervisor | 任意 | 软件商店搜索安装 |
| Composer | 2.x | 终端手动安装 |

**必装 PHP 扩展：** `pdo_mysql` `json` `fileinfo` `zip` `pcntl` `posix`

```bash
# 检查扩展是否已装
php -m | grep -E "pdo_mysql|pcntl|posix|zip|fileinfo"
```

### 1.2 建站

1. 宝塔 → 网站 → 添加站点
   - 域名：`chat.yourdomain.com`
   - **根目录：`/www/wwwroot/jiliao/public`**（⚠️ 必须指向 `public` 子目录）
   - PHP 版本：8.1

### 1.3 上传代码

```bash
# 方式1：Git
cd /www/wwwroot
git clone <仓库地址> jiliao

# 方式2：SFTP 上传压缩包后解压
cd /www/wwwroot/jiliao
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
mv composer.phar /usr/local/bin/composer

# 安装 PHP 依赖
composer install --no-dev --optimize-autoloader
```

### 1.4 目录权限

```bash
chown -R www:www /www/wwwroot/jiliao
chmod -R 755     /www/wwwroot/jiliao
chmod -R 775     /www/wwwroot/jiliao/storage
chmod -R 775     /www/wwwroot/jiliao/public/uploads
chmod 640        /www/wwwroot/jiliao/.env
```

### 1.5 Nginx 配置

宝塔 → 站点 → 设置 → 配置文件，替换为：

```nginx
server {
    listen 80;
    server_name chat.yourdomain.com;
    root /www/wwwroot/jiliao/public;
    index index.html index.php;
    charset utf-8;

    # API 入口（PHP 处理）
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass  unix:/tmp/php-cgi-81.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # WebSocket 反向代理（关键！）
    location /ws {
        proxy_pass         http://127.0.0.1:9501;
        proxy_http_version 1.1;
        proxy_set_header   Upgrade    $http_upgrade;
        proxy_set_header   Connection "upgrade";
        proxy_set_header   Host       $host;
        proxy_set_header   X-Real-IP  $remote_addr;
        proxy_read_timeout 3600s;
    }

    # 静态资源缓存
    location ~* \.(js|css|png|jpg|jpeg|gif|webp|svg|woff2?)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # 禁止访问后端目录
    location ~ ^/(server|scripts|storage)/ { deny all; }
    location ~ /\.                          { deny all; }
    location = /.env                        { deny all; }

    location / {
        try_files $uri $uri/ $uri.html =404;
    }
}
```

### 1.6 运行安装向导

浏览器访问 `http://chat.yourdomain.com/install.php`，按步骤：

1. **环境检测** — 确认所有项绿色
2. **数据库配置** — 填写 MySQL 信息，点击「测试连接」
3. **创建超管账号** — 记住用户名和密码
4. **站点配置** — 填写完整 URL（含 `http://` 前缀，无尾部斜杠）、WebSocket 端口 9501
5. **完成** — 看到「安装成功」后立即删除安装文件

```bash
# 安装完成后删除安装文件（重要！防止被重置）
rm /www/wwwroot/jiliao/public/install.php
```

### 1.7 WebSocket 进程守护（Supervisor）

```bash
# 创建配置
cat > /etc/supervisor/conf.d/jiliao-ws.conf << 'EOF'
[program:jiliao-ws]
command     = /usr/bin/php8.1 /www/wwwroot/jiliao/server/ws/server.php start
user        = www
autostart   = true
autorestart = true
startretries= 5
startsecs   = 3
redirect_stderr   = true
stdout_logfile    = /www/wwwlogs/jiliao-ws.log
stdout_logfile_maxbytes = 50MB
EOF

# 加载并启动
supervisorctl reread
supervisorctl update
supervisorctl start jiliao-ws

# 验证
supervisorctl status jiliao-ws   # 应显示 RUNNING
```

### 1.8 SSL（HTTPS）

1. 宝塔 → 站点 → SSL → Let's Encrypt → 申请免费证书 → 开启强制 HTTPS
2. 修改 `.env`：
   ```
   APP_URL=https://chat.yourdomain.com
   SESSION_SECURE=true
   ```
3. WebSocket 自动由 Nginx 代理，无需额外改动（ws:// 变成 wss:// 由前端自动适配）

---

## 二、踩坑汇总

### 2.1 WebSocket 认证失败 / 无法收发消息

**现象：** 登录后界面正常，但发送/接收消息无反应；浏览器 Console 显示 WS 连接成功，但消息推送不工作。

**根因：** PHP Session Cookie 设置了 `HttpOnly`，前端 JS 无法通过 `document.cookie` 读取 `JILIAO_SESS`，WebSocket 握手时无法携带 session_id，服务端拒绝认证。

**修复方案：**

```php
// server/core/Auth.php — authMe() 方法
// 在 API 响应里追加 session_id 字段
public function me(): array {
    // ... 原有逻辑 ...
    return [
        'code' => 0,
        'data' => array_merge($user, [
            'session_id' => session_id(),  // ← 新增这一行
        ]),
    ];
}
```

```javascript
// app.html — init() 中获取 session_id 写入 meta 标签
const meRes = await api('auth/me', {}, 'GET');
ME = meRes.data;
// 注入到 <meta name="session-id"> 供 JLSocket 读取
document.getElementById('meta-session').content = meRes.data.session_id ?? '';
```

---

### 2.2 安装完成后超管账号被封禁

**现象：** 安装向导完成后，用超管账号登录提示「账号已被封禁」。

**根因：** 安装脚本 `scripts/install.php` 创建用户时 `status` 字段赋值为 `0`，而系统规定 `0 = 封禁，1 = 正常`。

**修复方案：**

```php
// scripts/install.php — 创建超管的 INSERT 语句
$db->execute(
    "INSERT INTO users (username, password, nickname, role, status, created_at)
     VALUES (?, ?, ?, 9, 1, NOW())",  // ← status 改为 1
    [$username, $hashedPwd, $nickname]
);
```

---

### 2.3 用户默认没有进入默认群组

**现象：** 新用户注册后，会话列表里没有默认群（如「综合讨论组」），需要手动加入。

**根因：** `joinDefaultGroup()` 函数执行顺序问题——在群组尚未创建或初始化完成时就尝试执行加入操作，静默失败。

**修复方案：**

```php
// server/core/Auth.php — register() 方法
public function register(array $data): array {
    // 1. 先创建用户
    $uid = $this->createUser($data);

    // 2. 查询所有默认群（is_default=1）
    $defaultGroups = $this->db->query(
        'SELECT gid FROM `groups` WHERE is_default=1 AND status=1'
    );

    // 3. 逐一加入
    foreach ($defaultGroups as $g) {
        $exists = $this->db->single(
            'SELECT COUNT(*) FROM group_members WHERE gid=? AND uid=?',
            [$g['gid'], $uid]
        );
        if (!$exists) {
            $this->db->execute(
                'INSERT INTO group_members (gid, uid, role, joined_at) VALUES (?,?,0,NOW())',
                [$g['gid'], $uid]
            );
        }
    }
    // ...
}
```

---

### 2.4 后台用户状态显示相反

**现象：** 管理后台用户列表中，正常用户显示「封禁」，封禁用户显示「正常」。

**根因：** `admin.html` 前端显示逻辑中状态映射写反了。

**修复方案：**

```javascript
// admin.html — renderUsers() 中状态显示
// 修改前（错误）
const statusLabel = u.status === 0 ? '正常' : '封禁';

// 修改后（正确）
const statusLabel = u.status === 1 ? '正常' : '封禁';
const statusClass = u.status === 1 ? 'ok' : 'frozen';
```

---

### 2.5 手机端会话列表不显示

**现象：** 手机浏览器打开 app.html，白屏或进入后看不到左侧会话列表，只有空白。

**根因：** CSS 媒体查询在 ≤768px 时将 `.session-panel` 改为 `transform: translateX(-100%)`（屏幕外），初始化时没有添加 `.open` 类。

**修复方案：**

```javascript
// app.html — init() 末尾
if (window.innerWidth <= 768) {
    document.getElementById('backBtn').style.display = '';
    document.getElementById('panel-chat').classList.add('open'); // ← 手机端默认展开
}
```

---

### 2.6 图片上传成功但无法显示

**现象：** 上传图片接口返回成功，但消息气泡里的图片显示 404。

**根因：** `server/core/ChatStorage.php` 中上传路径常量 `UPLOAD_PATH` 指向 `/storage/uploads/`（不在 Web 根目录），Nginx 无法访问。

**修复方案：**

```php
// server/bootstrap.php 或 Env.php
// 修改前
define('UPLOAD_PATH', ROOT_PATH . '/storage/uploads');
define('UPLOAD_URL',  '/storage/uploads');

// 修改后（指向 public 目录下）
define('UPLOAD_PATH', ROOT_PATH . '/public/uploads');
define('UPLOAD_URL',  '/uploads');
```

同时确保目录已创建且可写：

```bash
mkdir -p /www/wwwroot/jiliao/public/uploads
chown -R www:www /www/wwwroot/jiliao/public/uploads
chmod 775 /www/wwwroot/jiliao/public/uploads
```

---

### 2.7 断线重连时 Toast 骚扰频繁

**现象：** 网络稍有抖动就立即弹出多个「连接断开」Toast 通知，严重打扰用户体验。

**根因：** `JLSocket` 类每次断连都立即触发回调，调用方直接 `toast()`。

**修复方案：** 只在重连 ≥3 次时才弹提示，且改用消息队列防止重复弹出。

```javascript
// assets/js/common.js — JLSocket._scheduleRetry()
_scheduleRetry() {
    if (this.retryCount >= this.maxRetry) {
        toast('连接服务器失败，请刷新页面重试', 'error', 8000);
        return;
    }
    const delay = Math.min(this.retryDelay * Math.pow(1.5, this.retryCount), 30000);
    this.retryCount++;
    // ← 只有第3次及之后才显示提示
    if (this.retryCount >= 3) {
        toast(`连接中断，正在第 ${this.retryCount} 次重连…`, 'warning', 2000);
    }
    setTimeout(() => this.connect(), delay);
}
```

---

### 2.8 auth/me 在用户未登录时报错

**现象：** 未登录用户访问 app.html，API 抛 PHP Fatal Error；或者 JSON 解析错误导致页面卡死在加载层。

**根因：** `auth/me` 接口调用了 `$auth->requireLogin()`，未登录时直接抛异常而不是返回 null。

**修复方案：**

```php
// public/api.php
case 'auth/me':
    $user = $auth->me();       // ← 改用不抛异常的方法
    Response::json(['code' => 0, 'data' => $user]); // user 为 null 时也正常返回
    break;
```

```php
// server/core/Auth.php — 新增 me() 方法
public function me(): ?array {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) return null;
    $user = $this->db->first('SELECT * FROM users WHERE uid=?', [$uid]);
    if (!$user) return null;
    return array_merge($user, ['session_id' => session_id()]);
}
```

前端：

```javascript
// app.html — init()
const meRes = await api('auth/me', {}, 'GET');
if (!meRes.data) {
    location.href = '/auth.html'; // ← null 时跳转登录页
    return;
}
```

---

### 2.9 自动登录 / 记住用户名失效

**现象：** 填写过用户名后刷新登录页，输入框为空；已登录的用户刷新后要重新输入密码。

**修复方案（双重策略）：**

```javascript
// auth.html

// 1. 记住用户名（localStorage）
document.getElementById('input-username').value =
    localStorage.getItem('jl_last_user') ?? '';

// 登录成功后保存用户名
localStorage.setItem('jl_last_user', username);

// 2. 检测已有 session，自动跳转（免重复登录）
(async function checkSession() {
    const res = await api('auth/me', {}, 'GET').catch(() => null);
    if (res?.data) {
        location.href = '/app.html'; // 已登录直接进入聊天
    }
})();
```

---

### 2.10 管理后台移动端适配差

**现象：** 手机上打开 `admin.html`，侧边栏占满屏幕，内容无法查看，按钮无法点击。

**修复方案：** 侧边栏改为「抽屉式」——默认隐藏，点击汉堡按钮展开，背景加遮罩。

**关键 CSS：**

```css
/* admin.html 内嵌样式 */
@media (max-width: 640px) {
    .admin-sidebar {
        position: fixed;
        left: 0; top: 0; bottom: 0;
        transform: translateX(-100%);
        transition: transform .25s ease;
        z-index: 200;
    }
    .admin-sidebar.open {
        transform: translateX(0);
    }
    .sidebar-overlay {
        display: none;
        position: fixed; inset: 0;
        background: rgba(0,0,0,.5);
        z-index: 199;
    }
    .sidebar-overlay.open { display: block; }
    .admin-main { margin-left: 0; }
}
```

**关键 JS：**

```javascript
function toggleSidebar(open) {
    document.getElementById('adminSidebar').classList.toggle('open', open);
    document.getElementById('sidebarOverlay').classList.toggle('open', open);
}
```

---

### 2.11 手机端聊天面板头部布局混乱

**现象：** 手机上打开会话列表面板，顶部只有「消息」文字和右侧按钮，没有用户头像，无法快速访问个人账号。

**修复方案：** 手机端在标题左侧显示用户头像，标题绝对居中，右侧追加主题切换按钮。

**HTML 结构改动（app.html）：**

```html
<div class="session-panel-header">
  <!-- 移动端左侧用户头像 -->
  <div id="panel-avatar-btn"
       class="session-panel-avatar-btn"
       onclick="showMeMenu()">
    <!-- 由 renderSelfAvatar() 填充 -->
  </div>
  <span class="session-panel-title">消息</span>
  <div style="display:flex;gap:.25rem;align-items:center">
    <button class="btn-icon js-theme-toggle session-panel-theme-btn">🌙</button>
    <button class="btn-icon" onclick="createGroupModal()">+</button>
    <button class="btn-icon" id="menuBtn" onclick="showMainMenu(this)">☰</button>
  </div>
</div>
```

**CSS（app.css）：**

```css
/* 移动端默认隐藏，桌面端不占位 */
.session-panel-avatar-btn { display: none; ... }
.session-panel-theme-btn  { display: none; }

@media (max-width: 768px) {
    .session-panel-avatar-btn { display: flex; }
    .session-panel-theme-btn  { display: flex; }
    /* 标题绝对居中 */
    .session-panel-header     { position: relative; }
    .session-panel-title      {
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
        pointer-events: none;
    }
}
```

---

### 2.12 历史消息显示 UID 数字而非昵称

**现象：** 打开一个群聊，历史消息里发送者显示为数字（如 `3`），而非用户昵称。

**根因：** `ChatStorage::save()` 的 TXT 记录格式仅保存 `from: uid`（数字），`loadHistory()` 的 JS 代码直接将原始 UID 当昵称用：

```javascript
// 错误写法
from_nickname: m.from,  // m.from 是数字 UID
```

**修复方案：** 加载历史消息前，先从已缓存的 `groupMembers` 构建 `uid→nickname` 映射。

```javascript
// app.html — loadHistory()
const nickMap = {};
if (s.type === 'group') {
    (groupMembers[s.id] ?? []).forEach(m => { nickMap[m.uid] = m.nickname; });
}

res.data.forEach(m => appendMessage({
    msg_id:        m.id,
    chat_type:     s.type === 'group' ? 2 : 1,
    from_uid:      m.from,
    from_nickname: s.type === 'group'
        ? (nickMap[m.from] ?? `用户${m.from}`)        // 群聊：查映射表
        : (m.from == ME.uid ? ME.nickname : s.name),  // 私聊：自己/对方
    msg_type:  m.type,
    content:   m.content,
    reply_msg_id: m.reply,
    ts:        m.ts,
}));
```

> ⚠️ **注意顺序：** 必须先调用 `loadGroupMembers(gid)` 填充 `groupMembers`，再调用 `loadHistory()`；或者让 `loadHistory()` 内部异步等待成员数据就绪。

---

### 2.13 群成员列表点击后没有反应

**现象：** 点击聊天页顶部「成员列表」按钮，手机端完全没有反应，桌面端偶尔也不显示。

**根因：** CSS 中有：

```css
@media (max-width: 1100px) {
    .info-panel { display: none; }  /* ← 覆盖了 JS 设置 */
}
```

JS 设置 `panel.style.display = ''` 后立即被媒体查询的 `display:none` 覆盖。

**修复方案（JS 层 Modal 降级）：**

```javascript
function toggleInfoPanel() {
    // ≤1100px 用 Modal 弹窗代替侧边板
    if (window.innerWidth <= 1100) {
        if (!currentSession || currentSession.type !== 'group') return;
        const members = groupMembers[currentSession.id] ?? [];
        const rows = members.map(m => `
            <div style="display:flex;align-items:center;gap:.625rem;padding:.4rem 0;cursor:pointer"
                 onclick="document.querySelector('.modal-backdrop')?.remove();clickAvatar(${m.uid})">
              ${avatarHtml(m.nickname, 'avatar-xs')}
              <div class="truncate text-sm font-semibold">${escHtml(m.nickname)}</div>
              ${m.role >= 2 ? '<span title="群主">👑</span>' :
                m.role >= 1 ? '<span title="管理员">⚡</span>' : ''}
            </div>`).join('');
        showModal({
            title: `群成员 (${members.length})`,
            body: `<div style="max-height:55vh;overflow-y:auto">${rows}</div>`,
            confirmText: '关闭',
        });
        return;
    }
    // 桌面端：切换侧边固定面板
    const panel = document.getElementById('infoPanel');
    const isOpen = panel.style.display !== 'none';
    panel.style.display = isOpen ? 'none' : '';
    if (!isOpen && currentSession?.type === 'group') renderInfoPanel();
}
```

---

### 2.14 普通用户可以撤回他人消息

**现象：** 在群聊中，右键任意消息都可以看到「撤回」选项，即使不是自己发的消息。

**根因：**

- **前端：** `showMsgMenu()` 中 `if (isSelf)` 判断逻辑正确，但未验证服务端权限。
- **后端：** `ChatStorage::recall()` 中 `from_uid != $uid` 时直接返回 403，但前端没有传递 `chat_type` 和 `to_id`，服务端无法核查群角色。

**修复方案：**

**前端（显示逻辑）：**

```javascript
function showMsgMenu(e, pkt) {
    const isSelf = pkt.from_uid == ME.uid;
    const gid = currentSession?.type === 'group' ? currentSession.id : null;
    const gm  = gid ? (groupMembers[gid] ?? []).find(m => m.uid == ME.uid) : null;
    const myGroupRole     = gm?.role ?? 0;
    const canRecallOthers = ME.role >= 3 || myGroupRole >= 1; // 系统管理员 or 群管理员

    const items = [ /* ... */ ];
    // 只有「自己消息」或「有权限管理他人」时才显示撤回
    if (isSelf || canRecallOthers) {
        items.push({ label: '撤回消息', icon: '↩', danger: true, action: () => recallMsg(pkt) });
    }
}
```

**后端（权限校验）：**

```php
// api.php — msg/recall
case 'msg/recall':
    $me    = $auth->requireLogin();
    $msgId = $data['msg_id'] ?? '';
    $msgRow = $db->first('SELECT from_uid, to_id, chat_type FROM messages WHERE msg_id=?', [$msgId]);
    $canRecallOthers = false;
    if ($msgRow && (int)$msgRow['chat_type'] === 2) {
        if ($me['role'] >= 3) {
            $canRecallOthers = true; // 系统管理员
        } else {
            $gm = $db->first('SELECT role FROM group_members WHERE gid=? AND uid=?',
                             [$msgRow['to_id'], $me['uid']]);
            $canRecallOthers = ($gm && (int)$gm['role'] >= 1); // 群管理员/群主
        }
    }
    Response::json($storage->recall($msgId, $me['uid'], $me['role'], $canRecallOthers));
    break;
```

---

### 2.15 撤回时限硬编码无法配置

**现象：** 撤回消息时提示「超过撤回时限（2分钟）」，无法在后台修改不同角色的时限。

**根因：** `ChatStorage::recall()` 硬编码了 `$recallWindow = 120`。

**修复方案：** 从 `settings` 表读取配置，并支持按用户角色差异化时限。

```php
// server/core/ChatStorage.php — recall() 方法
public function recall(string $msgId, int $uid, int $userRole = 1, bool $canRecallOthers = false): array {
    $row = $this->db->first('SELECT from_uid, created_at FROM messages WHERE msg_id=?', [$msgId]);
    if (!$row) return ['code' => 404, 'msg' => '消息不存在'];

    $isSelf = ((int)$row['from_uid'] === $uid);
    if (!$isSelf && !$canRecallOthers) {
        return ['code' => 403, 'msg' => '只能撤回自己的消息'];
    }

    // 从后台设置读取角色时限（默认值：普通120s、VIP300s、管理3600s、超管不限）
    $roleKey  = min($userRole, 9);
    $keyMap   = [1=>'recall_time_role1', 2=>'recall_time_role2', 3=>'recall_time_role3', 9=>'recall_time_role9'];
    $defaults = [1=>120, 2=>300, 3=>3600, 9=>0];
    $settRow  = $this->db->first("SELECT val FROM settings WHERE `key`=?", [$keyMap[$roleKey] ?? 'recall_time_role1']);
    $window   = $settRow ? (int)$settRow['val'] : ($defaults[$roleKey] ?? 120);

    if ($window > 0 && time() - strtotime($row['created_at']) > $window) {
        return ['code' => 400, 'msg' => "超过撤回时限（{$window}秒）"];
    }

    $this->db->execute('UPDATE messages SET is_deleted=1 WHERE msg_id=?', [$msgId]);
    return ['code' => 0, 'msg' => '已撤回'];
}
```

**后台设置（admin.html）：**

在「系统配置」→「撤回设置」中可配置：
- 普通用户撤回时限（秒）：默认 120
- VIP 用户撤回时限（秒）：默认 300
- 管理员撤回时限（秒）：默认 3600
- 超管撤回时限（秒）：默认 0（不限制）

---

### 2.16 任何用户都可发布群公告

**现象：** 普通用户在前端可以发布和修改群公告，没有权限限制。

**根因：** `api.php` 中 `notice/set` 使用了 `$auth->requireRole(1)`（任意登录用户），而不是检查群角色。

**修复方案：**

```php
// api.php — notice/set
case 'notice/set':
    $me  = $auth->requireLogin();
    $gid = $data['gid'] ?? '';
    if (!$gid) Response::fail('gid 不能为空');
    // 系统管理员（role>=3）或群管理员/群主（group_members.role>=1）可发布
    if ($me['role'] < 3) {
        $gm = $db->first('SELECT role FROM group_members WHERE gid=? AND uid=?', [$gid, $me['uid']]);
        if (!$gm || (int)$gm['role'] < 1) {
            Response::fail('仅群管理员或系统管理员可发布公告');
        }
    }
    // ... INSERT 逻辑 ...
```

---

### 2.17 群公告不显示发布人和时间

**现象：** 公告栏只显示公告内容，不显示是谁发的、什么时间发的。

**修复方案：**

**后端 — JOIN 用户表：**

```php
// api.php — notice/get
case 'notice/get':
    $notice = $db->first(
        'SELECT n.*, u.nickname AS author_name
         FROM notices n
         LEFT JOIN users u ON u.uid = n.created_by
         WHERE n.gid=? ORDER BY n.created_at DESC LIMIT 1',
        [$data['gid'] ?? '']
    );
    Response::json(['code' => 0, 'data' => $notice]);
```

**前端 — 显示发布人和时间：**

```javascript
// app.html — loadGroupNotice()
async function loadGroupNotice(gid) {
    const res = await api('notice/get', { gid }, 'GET');
    const bar = document.getElementById('noticeBar');
    if (res.code === 0 && res.data) {
        currentNotice = res.data;
        const author = res.data.author_name ? `${res.data.author_name}: ` : '';
        document.getElementById('noticeContent').textContent =
            `📢 ${author}${res.data.content}`;
        document.getElementById('noticeTime').textContent =
            formatTime(res.data.created_at);
        bar.classList.remove('hidden');
    }
}
```

**HTML 公告栏（增加时间和操作按钮）：**

```html
<div class="notice-bar hidden" id="noticeBar">
  <span id="noticeContent" class="truncate flex-1"></span>
  <span id="noticeTime" style="font-size:.75rem;color:var(--text-muted)"></span>
  <button id="noticeEditBtn"   class="btn-icon hidden" onclick="editNotice()">✏️</button>
  <button id="noticeDeleteBtn" class="btn-icon hidden" onclick="deleteNotice()">🗑️</button>
</div>
```

---

### 2.18 群公告无法编辑和删除

**现象：** 发布公告后，没有任何入口可以修改或删除错误的公告内容。

**修复方案：** 新增两个 API 端点，前端按权限显示操作按钮。

**后端新增接口：**

```php
// api.php — notice/update
case 'notice/update':
    $me = $auth->requireLogin();
    $noticeId = (int)($data['notice_id'] ?? 0);
    // 权限校验（同 notice/set）
    // ...
    $db->execute('UPDATE notices SET content=?, created_by=?, created_at=NOW() WHERE id=?',
                 [$content, $me['uid'], $noticeId]);
    Response::json(['code' => 0, 'msg' => '公告已更新']);
    break;

// api.php — notice/delete
case 'notice/delete':
    $me = $auth->requireLogin();
    $noticeId = (int)($data['notice_id'] ?? 0);
    // 权限校验
    // ...
    $db->execute('DELETE FROM notices WHERE id=?', [$noticeId]);
    Response::json(['code' => 0, 'msg' => '公告已删除']);
    break;
```

**前端权限判断（加载成员后重新评估）：**

```javascript
function refreshNoticeActions(gid) {
    if (!currentNotice) return;
    const gm = (groupMembers[gid] ?? []).find(m => m.uid == ME.uid);
    const canManage = ME.role >= 3 || (gm?.role ?? 0) >= 1
                    || currentNotice.created_by == ME.uid;
    document.getElementById('noticeEditBtn')?.classList.toggle('hidden', !canManage);
    document.getElementById('noticeDeleteBtn')?.classList.toggle('hidden', !canManage);
}
```

---

## 三、功能扩展说明

### 3.1 界面皮肤/主题系统

系统内置 10 套主题皮肤，在「管理后台 → 界面皮肤」中可视化选择：

| 主题名 | 风格 | 主色 |
|--------|------|------|
| 极聊默认 | 现代蓝 | #4f73f8 |
| 原神风格 | 金琥珀 | #c49a1c |
| 鸣潮风格 | 蒸汽青 | #00bfa5 |
| 王者荣耀 | 皇金赤 | #d4af37 |
| 和平精英 | 军绿 | #7a9e4e |
| 二次元 | 洋红紫 | #e91e8c |
| 樱花 | 樱桃粉 | #ff6b9d |
| 赛博朋克 | 霓虹紫青 | #b14fff |
| 深海蓝 | 深蓝 | #0891b2 |
| 墨林绿 | 翠绿 | #059669 |

**工作原理：**

1. 管理员在后台选择皮肤 → 写入 `settings` 表 `ui_skin` 字段
2. 所有页面加载时调用 `settings/public`（无需登录的公开 API）
3. JavaScript 读取后调用 `applyUITheme(name)` 覆盖 CSS 变量
4. 本地 `localStorage` 缓存上次皮肤，刷新时无闪烁

**自定义皮肤：** 在 `assets/js/common.js` 的 `UI_THEMES` 对象中追加条目即可。

### 3.2 消息撤回角色时限后台配置

后台 → 撤回设置，可配置各角色撤回时限（秒）：

| 设置 Key | 角色 | 默认值 |
|----------|------|--------|
| `recall_time_role1` | 普通用户 | 120 秒 |
| `recall_time_role2` | VIP 用户 | 300 秒 |
| `recall_time_role3` | 管理员 | 3600 秒 |
| `recall_time_role9` | 超管 | 0（不限） |

设为 `0` 表示不限时撤回。群管理员和群主可撤回群内任意消息（受其系统角色对应时限约束）。

---

## 四、生产环境核查清单

部署完成后，逐项检查：

```
[ ] .env 配置正确（APP_URL、DB_*、SESSION_SECURE）
[ ] install.php 已删除
[ ] SSL 证书已配置，强制 HTTPS 已开启
[ ] supervisor jiliao-ws 状态为 RUNNING
[ ] public/uploads/ 目录可写（www 用户权限）
[ ] storage/ 目录可写
[ ] Nginx /ws 代理 Upgrade 头正确配置
[ ] PHP pcntl、posix 扩展已启用（WebSocket 必须）
[ ] 防火墙 80/443 已放行
[ ] 超管账号密码已修改为强密码
[ ] APP_DEBUG=false
[ ] 后台撤回时限已按需配置
[ ] 界面皮肤已选择
```

---

## 五、推荐后续改进

以下功能在当前版本中缺失，推荐按优先级补充：

### 高优先级

| 功能 | 说明 |
|------|------|
| **好友申请审批流** | 当前 `friend/add` 直接添加，应改为：发送申请 → 对方同意/拒绝 |
| **消息已读回执** | 私聊显示「已读 ✓✓」，群聊显示已读人数 |
| **文件/视频发送** | 目前仅支持图片；需增加通用文件上传和预览 |

### 中优先级

| 功能 | 说明 |
|------|------|
| **正在输入提示** | WS 推送 `typing` 事件，聊天头部显示「xxx 正在输入…」 |
| **消息表情回应** | 长按消息显示 emoji 快速回应（❤️😂👍等） |
| **敏感词过滤** | 后台录入关键词列表，发送时自动屏蔽或替换 |
| **离线推送通知** | 接入 Bark / 企业微信 Webhook，用户离线时推送通知 |

### 低优先级

| 功能 | 说明 |
|------|------|
| **聊天背景自定义** | 用户为每个会话设置独立背景图 |
| **消息转发** | 长按 → 转发到其他会话 |
| **群邀请链接** | 生成带有效期的邀请链接，分享后自动入群 |
| **消息导出** | 管理后台导出群/私聊聊天记录（CSV/TXT） |
| **Android/iOS App** | 将现有 Web 封装为 Capacitor/WebView App |
| **管理员操作日志** | 记录后台所有操作（封号、解散群、修改设置等） |

---

*最后更新：2026-02-28*

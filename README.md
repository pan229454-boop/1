# 极聊（商用版）

> 基于 PHP + Workerman + MySQL + TXT 混合存储的商用即时通讯系统

---

## 目录

1. [系统要求](#系统要求)
2. [目录结构](#目录结构)
3. [宝塔面板快速部署](#宝塔面板快速部署)
   - 3.1 [建站与 PHP 配置](#建站与-php-配置)
   - 3.2 [数据库创建](#数据库创建)
   - 3.3 [上传代码](#上传代码)
   - 3.4 [Composer 安装依赖](#composer-安装依赖)
   - 3.5 [运行安装向导](#运行安装向导)
4. [Nginx 配置](#nginx-配置)
5. [WebSocket 服务部署](#websocket-服务部署)
   - 5.1 [Supervisor 守护配置](#supervisor-守护配置)
6. [防火墙端口放行](#防火墙端口放行)
7. [SSL（HTTPS）配置](#sslhttps配置)
8. [目录权限](#目录权限)
9. [环境变量说明](#环境变量说明)
10. [常见问题](#常见问题)
11. [License](#license)

---

## 系统要求

| 项目 | 最低要求 |
|------|---------|
| 操作系统 | Linux（推荐 CentOS 7 / Ubuntu 20+） |
| PHP | **7.4 +**（推荐 8.1）|
| PHP 扩展 | `pdo_mysql` `json` `fileinfo` `zip` `pcntl` `posix` |
| MySQL / MariaDB | 5.7 + / 10.3 + |
| Composer | 2.x |
| Nginx | 1.16 + |
| 内存 | 512 MB +（WebSocket 进程） |
| 宝塔面板 | 7.x / 8.x（可选，不强依赖） |

---

## 目录结构

```
/
├── composer.json
├── .env                # 环境配置（由安装向导生成）
├── install.lock        # 安装锁文件（安装完成后自动创建）
├── README.md
├── public/             # Nginx 指向此目录（Web 根目录）
│   ├── index.html      # 首页
│   ├── auth.html       # 登录/注册
│   ├── app.html        # 主聊天界面
│   ├── admin.html      # 管理后台
│   ├── install.php     # 安装向导
│   ├── api.php         # API 入口
│   └── assets/
│       ├── css/app.css
│       └── js/common.js
├── scripts/
│   └── schema.sql      # 数据库建表 SQL
├── server/
│   ├── bootstrap.php   # 应用引导
│   ├── core/           # 核心类（Auth、Database、ChatStorage …）
│   ├── api/            # API 业务逻辑
│   └── ws/
│       └── server.php  # WebSocket 服务（Workerman）
└── storage/            # 运行时数据（自动创建，需可写）
    ├── chat/           # TXT 消息文件
    ├── uploads/        # 用户上传文件
    ├── cache/          # 速率限制等缓存
    └── logs/           # 日志
```

---

## 宝塔面板快速部署清枫

### 建站与 PHP 配置

1. 宝塔后台 → **网站** → **添加站点**
   - 域名：`chat.example.com`
   - 根目录：`/www/wwwroot/jiliao/public`（注意指向 `public/`）
   - PHP 版本：**8.1**（若无，先在「软件商店」安装）

2. 进入站点设置 → **PHP 版本**，确认为 8.1

3. **安装/确认 PHP 扩展**：宝塔面板 → 软件商店 → PHP 8.1 → 设置 → 安装扩展
   勾选并安装：`pdo_mysql` · `zip` · `fileinfo` · `pcntl` · `posix`

4. PHP 设置 → `php.ini` 中确认：
   ```ini
   extension=pdo_mysql
   extension=zip
   ```

### 数据库创建

1. 宝塔后台 → **数据库** → **添加数据库**
   - 数据库名：`jiliao`
   - 用户名：`jiliao`
   - 密码：自定义强密码
   - 访问权限：`127.0.0.1`

### 上传代码

使用 SFTP / 宝塔文件管理器将项目文件上传至 `/www/wwwroot/jiliao/`（**不含** public 子目录本身，即根目录内含 public/ server/ 等文件夹）。

### Composer 安装依赖

在宝塔终端或 SSH 中执行：

```bash
cd /www/wwwroot/jiliao
composer install --no-dev --optimize-autoloader
```

### 运行安装向导

浏览器访问：`http://chat.example.com/install.php`

按页面提示完成 5 步骤：
1. 环境检测 → 确认全绿
2. 填写数据库信息 → 测试并初始化
3. 创建超级管理员账号
4. 填写网站 URL、WebSocket 端口 → 写入 `.env`
5. 完成页面 → 立即登录

> ⚠️ **安装完成后请删除或重命名 `public/install.php`**

---

## Nginx 配置

宝塔站点 → **设置** → **配置文件**，将默认内容替换为以下配置：

```nginx
server {
    listen       80;
    listen       [::]:80;
    server_name  chat.example.com;

    # 网站根目录指向 public/
    root   /www/wwwroot/jiliao/public;
    index  index.html index.php;

    # 字符集
    charset utf-8;

    # 日志
    access_log  /www/wwwlogs/jiliao_access.log;
    error_log   /www/wwwlogs/jiliao_error.log;

    # API 路由（api.php）
    location /api.php {
        include fastcgi_params;
        fastcgi_pass  unix:/tmp/php-cgi-81.sock;   # 按实际 PHP 版本修改
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # 安装向导（可选，安装后删除）
    location = /install.php {
        include fastcgi_params;
        fastcgi_pass  unix:/tmp/php-cgi-81.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # WebSocket 反向代理（将 /ws 代理到 Workerman）
    location /ws {
        proxy_pass          http://127.0.0.1:9501;
        proxy_http_version  1.1;
        proxy_set_header    Upgrade    $http_upgrade;
        proxy_set_header    Connection "upgrade";
        proxy_set_header    Host       $host;
        proxy_set_header    X-Real-IP  $remote_addr;
        proxy_read_timeout  3600s;
        proxy_send_timeout  3600s;
    }

    # 静态资源缓存
    location ~* \.(js|css|ico|png|jpg|jpeg|gif|webp|svg|woff2?)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # 禁止访问敏感目录
    location ~ ^/(server|scripts|storage)/ {
        deny all;
        return 403;
    }
    location ~ /\. { deny all; }
    location = /.env { deny all; }

    # HTML 路由（SPA 降级）
    location / {
        try_files $uri $uri/ $uri.html =404;
    }
}
```

> 配置 SSL 后将 `listen 80` 替换为 `listen 443 ssl`，并在下方加 HTTPS 证书块。

---

## WebSocket 服务部署

### 手动启动（测试用）

```bash
cd /www/wwwroot/jiliao
php server/ws/server.php start        # 前台运行
php server/ws/server.php start -d     # 后台 daemon 运行
php server/ws/server.php status       # 查看状态
php server/ws/server.php stop         # 停止
php server/ws/server.php restart      # 重启
```

### Supervisor 守护配置

推荐使用 Supervisor 守护 WebSocket 进程，确保崩溃后自动重启。

#### 安装 Supervisor（宝塔软件商店搜索安装，或手动）

```bash
# Ubuntu / Debian
apt install -y supervisor

# CentOS
yum install -y supervisor
systemctl enable supervisord
```

#### 创建配置文件

新建 `/etc/supervisor/conf.d/jiliao-ws.conf`：

```ini
[program:jiliao-ws]
; 程序名称
process_name = jiliao-ws

; 启动命令（使用 PHP 8.1 可执行文件路径，按实际修改）
command = /usr/bin/php8.1 /www/wwwroot/jiliao/server/ws/server.php start

; 以 www 用户运行（宝塔默认 Web 用户）
user             = www
autostart        = true          ; 随 Supervisor 自动启动
autorestart      = true          ; 进程异常退出后自动重启
startretries     = 5             ; 最多重试次数
startsecs        = 3             ; 启动后 3 秒未退出视为成功
stopwaitsecs     = 10
redirect_stderr  = true
stdout_logfile   = /www/wwwlogs/jiliao-ws.log
stdout_logfile_maxbytes = 50MB
stdout_logfile_backups  = 3
environment      = HOME="/www",USER="www"
```

#### 加载并启动

```bash
supervisorctl reread
supervisorctl update
supervisorctl start jiliao-ws
supervisorctl status             # 应显示 RUNNING
```

---

## 防火墙端口放行

| 端口 | 用途 | 是否必须 |
|------|------|---------|
| 80   | HTTP | ✅ |
| 443  | HTTPS | 推荐 |
| 9501 | WebSocket（Workerman）| ✅ 内部，Nginx 代理则仅需内网可访问 |

**宝塔操作**：安全 → 防火墙 → 放行端口 `9501`（若 WebSocket 不走 Nginx 代理时）

若 WebSocket 通过 Nginx `/ws` 路径反向代理，则外网只需开放 80/443，无需对外开放 9501。

---

## SSL（HTTPS）配置

1. 宝塔后台 → 站点 → 设置 → **SSL**
2. 选择 **Let's Encrypt** 免费证书，填写域名，一键申请
3. 打开 **强制 HTTPS**
4. 安装向导或 `.env` 中将 `APP_URL` 修改为 `https://` 前缀，并设置 `SESSION_SECURE=true`
5. Nginx `ws` 代理块地址不变（因为代理是内网 HTTP，SSL 终止在 Nginx）

---

## 目录权限

```bash
chown -R www:www /www/wwwroot/jiliao
chmod -R 755     /www/wwwroot/jiliao
# 可写目录
chmod -R 775 /www/wwwroot/jiliao/storage
chmod -R 775 /www/wwwroot/jiliao/public/uploads
# 保护敏感文件
chmod 640 /www/wwwroot/jiliao/.env
```

---

## 环境变量说明

| 变量 | 默认值 | 说明 |
|------|--------|------|
| `APP_NAME` | `极聊（商用版）` | 站点名称 |
| `APP_URL` | `http://localhost` | 完整 URL（含协议，无尾斜杠） |
| `APP_DEBUG` | `false` | 调试模式，生产环境务必 `false` |
| `DB_HOST` | `127.0.0.1` | 数据库主机 |
| `DB_PORT` | `3306` | 数据库端口 |
| `DB_NAME` | `jiliao` | 数据库名称 |
| `DB_USER` | — | 数据库账号 |
| `DB_PASS` | — | 数据库密码 |
| `SESSION_NAME` | `JILIAO_SESS` | Cookie 名称 |
| `SESSION_LIFETIME` | `86400` | Session 有效期（秒） |
| `SESSION_SECURE` | `false` | HTTPS 时设为 `true` |
| `WS_PORT` | `9501` | WebSocket 监听端口 |
| `UPLOAD_MAX_MB` | `10` | 最大上传文件大小（MB） |
| `RATE_LIMIT_MAX` | `120` | 接口速率上限（次/窗口） |
| `RATE_LIMIT_WINDOW` | `60` | 速率限制窗口（秒） |
| `CAPTCHA_ENABLED` | `true` | 是否启用滑块验证码 |

---

## 常见问题

### Q：WebSocket 无法连接？
1. 确认 Supervisor 进程状态为 `RUNNING`：`supervisorctl status`
2. 检查 Workerman 日志：`tail -f /www/wwwlogs/jiliao-ws.log`
3. 确认 Nginx `/ws` 代理配置正确，Upgrade/Connection 头已传递
4. 浏览器 Console 查看 WS 错误信息

### Q：API 返回 500？
1. 开启 `APP_DEBUG=true` 查看详细错误（生产环境排错后关闭）
2. 查看 PHP 错误日志：`tail -f /www/wwwlogs/jiliao_error.log`
3. 检查 `.env` 数据库配置是否正确

### Q：文件上传失败？
1. 确认 `storage/` 和 `public/uploads/` 目录对 `www` 用户可写
2. 检查 PHP `upload_max_filesize` 和 `post_max_size`（宝塔 PHP 设置中修改）

### Q：安装向导已锁，如何重装？
删除项目根目录下的 `install.lock` 文件，再次访问 `/install.php`。

### Q：中文/Emoji 乱码？
确认数据库已创建为 `utf8mb4_unicode_ci`，`.env` 中 `DB_CHARSET=utf8mb4`。

### Q：消息 TXT 文件在哪里？
`storage/chat/group/` 存群聊，`storage/chat/private/` 存私聊，文件名格式：
```
chat_{群号/uid_uid}_{YYYYMMDD}.txt
```
每行为一个 JSON 对象，超过 5 MB 自动归档为 `.zip`。

---

## License

MIT License © 极聊（商用版）开发团队

本项目仅供学习与商业使用，禁止用于违法违规用途。

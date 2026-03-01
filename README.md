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

## 宝塔面板快速部署

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


首先确认系统是否安装了 supervisor：

```bash
rpm -qa | grep supervisor   # CentOS/RHEL
# 或者
dpkg -l | grep supervisor   # Debian/Ubuntu
```

#### 如未安装则安装 Supervisor（宝塔软件商店搜索安装，或手动）

```bash
# Ubuntu / Debian
apt install -y supervisor


# CentOS
yum install -y supervisor
systemctl enable supervisord
```


```bash
# CentOS/RHEL
yum install supervisor -y

# Ubuntu/Debian
apt-get update && apt-get install supervisor -y
```

---

2. 启动 Supervisor 服务

根据你的 Linux 发行版和初始化系统，选择以下一种方式启动：

方法 A：使用 systemd（CentOS 7+ / Ubuntu 16+ / Debian 8+）

```bash
sudo systemctl start supervisord
```

如果提示 Unit supervisord.service not found，可能服务名是 supervisor：

```bash
sudo systemctl start supervisor
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


--------------------------------------------------------------------------------------------------------------------------------------------------------
下面中间这里不用管可不看
--------------------------------------------------------------------------------------------------------------------------------------------------------

##                                  Git 自动部署（宝塔 + GitHub Webhook）

代码推送到 GitHub 后，服务器自动拉取最新代码并重启 WebSocket，无需手动登录服务器。

### 工作原理

```
git push → GitHub → 发送 Webhook POST → /webhook.php → 验证签名 → deploy.sh
                                                                     ├─ git pull
                                                                     ├─ composer install（按需）
                                                                     ├─ 修正目录权限
                                                                     └─ supervisorctl restart（按需）
```

### 第一步：配置 SSH Key（让服务器能 git pull）

宝塔的 `www` 用户没有密码和登录 Shell，**不需要 `su -s /bin/bash www`**。直接在宝塔终端（root 或当前 SSH 会话）执行即可。

```bash
# 1. 生成 SSH Key（直接在当前终端执行，一路回车）
ssh-keygen -t ed25519 -C "deploy@yourdomain.com" -f /root/.ssh/id_ed25519_deploy

# 2. 查看公钥（复制全部输出）
cat /root/.ssh/id_ed25519_deploy.pub

# 3. 把私钥同步给 www 用户，让 deploy.sh 以 www 身份 git pull 时也能用到
mkdir -p /www/.ssh
cp /root/.ssh/id_ed25519_deploy     /www/.ssh/id_ed25519
cp /root/.ssh/id_ed25519_deploy.pub /www/.ssh/id_ed25519.pub
chown -R www:www /www/.ssh
chmod 700 /www/.ssh
chmod 600 /www/.ssh/id_ed25519

# 4. 写入 SSH 配置，避免每次连接都要确认 host
cat > /www/.ssh/config << 'EOF'
Host github.com
    HostName github.com
    User git
    IdentityFile ~/.ssh/id_ed25519
    StrictHostKeyChecking no
EOF
chown www:www /www/.ssh/config
chmod 600 /www/.ssh/config
```

将 `cat /root/.ssh/id_ed25519_deploy.pub` 输出的内容添加到 GitHub 仓库：
- **Settings → Deploy keys → Add deploy key**（只读，无需 Allow write access）

验证 www 用户的连通性（宝塔中 www 没有登录 shell，改用 sudo -u）：

```bash
sudo -u www ssh -T git@github.com
# 成功提示：Hi pan229454-boop/1! You've successfully authenticated...
```

> ⚠️ 如果提示 `sudo: command not found`，宝塔 CentOS 版本改用：
> ```bash
> su -s /bin/sh -c "ssh -T git@github.com" www
> ```

### 第二步：修改仓库远端为 SSH 协议

```bash
cd /www/wwwroot/jiliao

# 查看当前远端（若为 https:// 则需要修改）
git remote -v

# 改为 SSH 协议
git remote set-url origin git@github.com:你的用户名/仓库名.git

# 验证
git remote -v
# 应显示：origin  git@github.com:xxx/xxx.git (fetch)

# 测试可以正常拉取
git fetch origin
```

### 第三步：在 .env 中配置 Webhook 密钥

```bash
# 生成随机密钥（复制输出结果）
openssl rand -hex 32

# 编辑 .env，添加一行
WEBHOOK_SECRET=上面生成的随机字符串
```

### 第四步：赋予部署脚本执行权限

```bash
chmod +x /www/wwwroot/jiliao/scripts/deploy.sh
```

### 第五步：Nginx 放行 webhook.php

宝塔站点默认屏蔽 `.php` 文件访问根目录外的脚本。确认 Nginx 的 `root` 指向 `public/`，则 `webhook.php` 在 `public/` 内，浏览器访问 `https://你的域名/webhook.php` 应返回 `405 Method not allowed`（GET 请求被正确拦截），说明配置正确。

若返回 404，检查 Nginx 配置中 `index` 或 `try_files` 规则是否需要补充：

```nginx
location ~ \.php$ {
    fastcgi_pass unix:/tmp/php-cgi-81.sock;
    fastcgi_index index.php;
    include fastcgi.conf;
}
```

### 第六步：GitHub 配置 Webhook

打开 GitHub 仓库 → **Settings → Webhooks → Add webhook**：

| 字段 | 填写值 |
|------|--------|
| Payload URL | `https://你的域名/webhook.php` |
| Content type | `application/json` |
| Secret | 与 `.env` 中 `WEBHOOK_SECRET` 相同 |
| Which events | **Just the push event** |
| Active | ✅ 勾选 |

点击 **Add webhook** 保存，GitHub 会立即发一次 `ping` 请求，返回 `200` 表示连通。

### 第七步：测试自动部署

```bash
# 本地推送任意代码变更到 main 分支
git push origin main

# 服务器查看部署日志
tail -f /www/wwwlogs/jiliao-deploy.log
```

部署日志格式示例：

```
[2026-02-28 14:30:01] INFO  Received event=push branch=main
[2026-02-28 14:30:01] INFO  Deploy triggered: 2 commit(s) by yourname on branch main
[2026-02-28 14:30:01] ========== 开始部署 ==========
[2026-02-28 14:30:02] [1/5] git pull origin main
[2026-02-28 14:30:03] 当前 commit: a1b2c3d - feat: 新增功能
[2026-02-28 14:30:03] [2/5] composer.json 无变更，跳过
[2026-02-28 14:30:03] [3/5] 修正目录权限
[2026-02-28 14:30:03] [4/5] 跳过独立迁移
[2026-02-28 14:30:04] [5/5] server/ 目录有变更，重启 WebSocket 进程…
[2026-02-28 14:30:04] WebSocket 重启成功
[2026-02-28 14:30:04] ========== 部署完成 ==========
```

### 手动触发部署

无需 Webhook 也可直接在服务器上手动执行：

```bash
bash /www/wwwroot/jiliao/scripts/deploy.sh
```

### 常见问题

#### Webhook 返回 403

`.env` 中的 `WEBHOOK_SECRET` 与 GitHub 填写的 Secret 不一致。重新生成并保持两处一致。

#### `git pull` 报 `Permission denied (publickey)`

**宝塔常见原因及修复：**

```bash
# 原因 1：远端仍使用 HTTPS，切换为 SSH
git remote set-url origin git@github.com:你的用户名/仓库名.git

# 原因 2：www 用户 ~/.ssh/id_ed25519 不存在，重新执行第一步的 cp 命令
ls -la /www/.ssh/

# 原因 3：私钥权限不对（必须是 600）
chmod 600 /www/.ssh/id_ed25519

# 原因 4：公钥未添加到 GitHub Deploy Keys
# 在 GitHub 仓库 Settings → Deploy keys 确认已添加

# 测试调试
sudo -u www ssh -vT git@github.com 2>&1 | grep -E 'offering|Authenticated|Permission'
```

#### `supervisorctl restart` 提示 `no such process`

`deploy.sh` 中 `WS_SERVICE` 变量与实际 supervisor 配置名不一致，检查：

```bash
supervisorctl status   # 确认进程名
```

然后修改 [scripts/deploy.sh](scripts/deploy.sh) 第 12 行的 `WS_SERVICE` 值。

#### PHP 版本不对

`deploy.sh` 默认使用 `/www/server/php/81/bin/php`，若使用其他版本修改第 9 行：

```bash
PHP_BIN=/www/server/php/82/bin/php bash scripts/deploy.sh
```


-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
自动更新结束
-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

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
| `WEBHOOK_SECRET` | — | GitHub Webhook 签名密钥（自动部署用，留空则禁用）|

---

## 常见问题

> 排查前建议先运行快速环境检查，确认PHP版本与必需扩展：
> ```bash
> php -v                              # 确认 >= 7.4
> php -m | grep -E 'pcntl|posix|pdo|mbstring|curl|gd'   # 确认扩展存在
> ```

---

### 一、Composer 安装依赖报错

#### 1. `requires php >=7.4`（PHP 版本不符）

```
Your requirements could not be resolved to an installable set of packages.
  Problem 1 - workerman/workerman 4.x requires php >=7.4 ...
```

**原因：** 系统默认 PHP 版本低于 7.4。

**解决：**
```bash
# 宝塔：在「软件商店 → PHP 管理」安装 PHP 8.1，然后切换命令行版本
ln -sf /www/server/php/81/bin/php /usr/local/bin/php
php -v    # 确认已指向 8.1
composer install
```

---

#### 2. `The requested PHP extension ext-pcntl / ext-posix is missing`

```
workerman/workerman requires ext-pcntl * -> it is missing from your system.
```

**原因：** `pcntl`、`posix` 扩展未安装（WebSocket 进程必须）。

**解决（宝塔）：** 软件商店 → PHP 8.1 → 安装扩展 → 搜索 `pcntl` / `posix` → 一键安装

**手动安装（Ubuntu/Debian）：**
```bash
apt install -y php8.1-dev
pecl install pcntl   # 若发行版自带则直接：
php8.1 -m | grep pcntl   # 宝塔面板通常自带，重启PHP即可
```

> 安装完扩展后必须重启 PHP-FPM 并重新启动 WebSocket 进程。

---

#### 3. 网络超时 / GitHub 限流

```
Failed to download vendor/package from dist: Could not authenticate against github.com
The process timed out after 300 seconds
```

**解决：切换阿里云 Composer 镜像**
```bash
composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/
composer install
```

若仍超时（服务器完全无法访问 GitHub），改用 `--prefer-dist --no-scripts`：
```bash
composer install --prefer-dist --no-scripts --no-progress
```

---

#### 4. `proc_open()` 被禁用（宝塔安全策略）

```
proc_open(): open_basedir restriction in effect / proc_open has been disabled
```

**原因：** 宝塔默认把 `proc_open`、`proc_get_status` 等函数加入禁用列表。

**解决：** 宝塔面板 → 软件商店 → PHP 8.1 → 配置 → 禁用函数 → 删除 `proc_open` 和 `proc_get_status` → 保存

---

#### 5. `Allowed memory size exhausted`

```
PHP Fatal error: Allowed memory size of 134217728 bytes exhausted
```

**解决：临时增大内存限制**
```bash
php -d memory_limit=512M /usr/local/bin/composer install
```

---

### 二、WebSocket 服务启动报错

#### 1. `PHP Extension pcntl / posix is required`

```
PHP Extension pcntl is not installed, please install it.
```

**原因：** Workerman 强依赖这两个扩展。

**解决：** 同"Composer 报 ext-pcntl 缺失"，安装扩展后重新启动：
```bash
# 宝塔 PHP 8.1 扩展页面安装 pcntl、posix
supervisorctl restart jiliao-ws
```

---

#### 2. `Address already in use` / 端口冲突

```
bind(): Address already in use: 9501
```

**原因：** 9501 端口已被其他进程占用（通常是前一个遗留进程）。

**解决：**
```bash
# 找到占用进程
lsof -i :9501
# 或
fuser 9501/tcp

# 强制释放端口
fuser -k 9501/tcp

# 若通过 Supervisor 管理，先 stop 再 start
supervisorctl stop jiliao-ws
fuser -k 9501/tcp
supervisorctl start jiliao-ws
```

---

#### 3. `.env 文件不存在` / 启动时配置读取失败

```
.env file not found. Please run the installer first.
[ERROR] Failed to open stream: No such file or directory /www/wwwroot/jiliao/.env
```

**原因：** 未运行安装向导，`.env` 尚未生成。

**解决：** 先访问 `http://你的域名/install.php` 完成安装，再启动 WebSocket。

若安装向导已跑过但 `.env` 消失：手动复制并填写配置
```bash
cp .env.example .env
# 编辑 DB_* APP_URL 等字段
nano .env
```

---

#### 4. `Failed to daemonize`（Daemon 化失败）

```
Workerman: [warning] can not daemonize process without pcntl extension
```

**原因：** `pcntl` 或 `posix` 扩展缺失，进程无法以后台 daemon 方式运行。

**解决：** 安装两个扩展（同第 1 条）。测试时可以不加 `-d` 参数，前台运行验证：
```bash
php server/ws/server.php start    # 不加 -d，手动Ctrl+C退出
```

---

#### 5. 以 root 用户运行出现警告

```
Workerman: WARNING: Running as root is not recommended. Use a dedicated user instead.
```

**原因：** 出于安全，Workerman 不推荐 root 权限运行。

**解决（生产环境）：** Supervisor 配置中指定 `user = www`（宝塔默认 Web 用户）

```ini
[program:jiliao-ws]
user = www
command = /usr/bin/php8.1 /www/wwwroot/jiliao/server/ws/server.php start
```

> 测试环境可忽略此警告，不影响功能。

---

### 三、Supervisor 进程管理问题

#### 1. 进程状态为 FATAL / BACKOFF（启动失败）

```bash
supervisorctl status
# jiliao-ws   FATAL   Exited too quickly (process log may have details)
```

**诊断：先查看日志**
```bash
tail -50 /www/wwwlogs/jiliao-ws.log
```

常见原因及处理：

| 日志关键词 | 原因 | 解决 |
|-----------|------|------|
| `pcntl extension is not installed` | 缺失扩展 | 安装 pcntl/posix 扩展 |
| `Address already in use` | 端口冲突 | `fuser -k 9501/tcp` |
| `.env file not found` | 未跑安装向导 | 先完成安装 |
| `No such file or directory` | command 路径错误 | 用 `which php8.1` 确认 PHP 路径 |
| `Permission denied` | 用户权限不足 | 检查 `user=www` 及目录权限 |

---

#### 2. PHP 可执行文件路径不正确

```bash
# 先确认实际路径
which php8.1
# /www/server/php/81/bin/php

# 修改 supervisor 配置中的 command
command = /www/server/php/81/bin/php /www/wwwroot/jiliao/server/ws/server.php start
```

---

#### 3. 修改配置后 Supervisor 不生效

```bash
# 修改 .conf 文件后必须执行
supervisorctl reread      # 重新读取配置
supervisorctl update      # 应用变更（会重启受影响进程）
supervisorctl status      # 确认 RUNNING
```

---

#### 4. Supervisor 服务本身未启动

```bash
systemctl status supervisord     # 查看状态
systemctl start supervisord      # 启动
systemctl enable supervisord     # 开机自启
```

---

### 四、Nginx WebSocket 代理问题

#### 1. WebSocket 握手返回 502 Bad Gateway

**原因：** 通常是 Nginx 未将 `Upgrade` 协议头传递给 Workerman。

**检查 Nginx 配置是否包含以下关键行：**

```nginx
location /ws {
    proxy_pass http://127.0.0.1:9501;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;       # ← 必须
    proxy_set_header Connection "Upgrade";         # ← 必须
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_read_timeout 3600s;                      # 长连接不能超时
    proxy_send_timeout 3600s;
}
```

> `proxy_read_timeout` 默认 60 秒，WebSocket 长连接会被 Nginx 主动断开，必须设为 3600 或更大。

---

#### 2. `proxy_pass` 端口与 `.env WS_PORT` 不一致

确认三处端口一致：
- `.env`：`WS_PORT=9501`
- Nginx：`proxy_pass http://127.0.0.1:9501`
- Supervisor command 中启动的端口

---

#### 3. WebSocket 握手返回 400 / 101 后立即断连

查看 Workerman 日志：
```bash
tail -f /www/wwwlogs/jiliao-ws.log
```

若日志无异常，检查浏览器 Console → Network → WS 连接 → 查看 Headers，确认请求头包含 `Upgrade: websocket`。

---

### 五、安装向导数据库报错

#### 1. `Access denied for user 'xxx'@'localhost'`

**原因：** `.env` 数据库账号/密码错误，或该用户没有目标库的权限。

**解决：**
1. 宝塔面板 → 数据库 → 确认数据库用户名和密码（可重置）
2. 确认该用户对 `jiliao` 数据库有 **ALL PRIVILEGES**
3. 更新 `.env` 中 `DB_USER` / `DB_PASS`

---

#### 2. `SQLSTATE[HY000] [2002] Connection refused`

**原因：** MySQL 服务未运行，或 `DB_HOST` 填写有误。

**解决：**
```bash
systemctl status mysql      # 检查 MySQL 状态
systemctl start mysql       # 若未运行则启动
```

宝塔：软件商店 → MySQL → 启动

---

#### 3. 安装时报 `Table 'xxx' already exists`

**原因：** 数据库已有旧表，重新安装时冲突。

**解决：** 删除数据库并重建（宝塔：数据库 → 删除 → 重新创建），然后重新运行安装向导。

---

#### 4. 安装向导已锁，无法重新安装

删除锁文件后重新访问 `/install.php`：
```bash
rm /www/wwwroot/jiliao/install.lock
```

---

### 六、HTTPS / SSL 下 WebSocket 断连

#### 1. 浏览器控制台提示 `Mixed Content`

```
Mixed Content: The page at 'https://…' was loaded over HTTPS, 
but attempted to connect a non-secure WebSocket 'ws://...'
```

**原因：** 站点已用 HTTPS，但前端仍使用 `ws://` 协议。

**解决：**
1. `.env` 中将 `APP_URL` 改为 `https://你的域名`
2. 重新安装或手动编辑 `.env` 使其生效
3. 前端 JS 会根据 `APP_URL` 自动判断使用 `wss://` 还是 `ws://`

---

#### 2. `wss://` 连接失败（SSL 证书错误）

**原因：** Nginx SSL 配置不全，或 WebSocket 代理未启用 SSL。

**标准 wss 代理配置（Nginx）：**
```nginx
server {
    listen 443 ssl;
    server_name 你的域名;

    ssl_certificate     /path/to/fullchain.pem;
    ssl_certificate_key /path/to/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;

    # WebSocket 代理（Nginx 做 SSL 终止，回源到 ws://）
    location /ws {
        proxy_pass http://127.0.0.1:9501;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }
}
```

> Workerman 本身监听普通 `ws://`，由 Nginx 在前端提供 SSL 终止，无需在 Workerman 层面配置证书。

---

#### 3. `SESSION_SECURE` 导致登录 Cookie 在 HTTP 下丢失

HTTPS 站点必须将 `.env` 中 `SESSION_SECURE=true`；
若在 HTTP 环境下却设成了 `true`，Cookie 无法传递，会导致登录失败。

---

### 七、目录权限问题

#### 1. `mkdir(): Permission denied` / 图片上传失败

```bash
# 赋予 uploads 和 storage 目录 www 用户写权限
chown -R www:www /www/wwwroot/jiliao/public/uploads
chown -R www:www /www/wwwroot/jiliao/storage
chmod -R 775     /www/wwwroot/jiliao/public/uploads
chmod -R 775     /www/wwwroot/jiliao/storage
```

---

#### 2. `.env` 无法写入（安装向导报错）

```bash
chown www:www /www/wwwroot/jiliao/.env
chmod 664     /www/wwwroot/jiliao/.env
```

---

### 八、其他常见问题

#### Q：API 返回 500？
1. 临时开启 `APP_DEBUG=true`，刷新后查看错误详情
2. 查看 PHP 错误日志：`tail -f /www/wwwlogs/jiliao_error.log`
3. 检查 `.env` 数据库配置是否正确

#### Q：中文 / Emoji 乱码？
确认数据库创建时使用 `utf8mb4_unicode_ci` 字符集，`.env` 中 `DB_CHARSET=utf8mb4`。

#### Q：消息 TXT 文件在哪里？
- 群聊：`storage/chat/group/`
- 私聊：`storage/chat/private/`
- 文件名格式：`chat_{群号/uid_uid}_{YYYYMMDD}.txt`
- 超过 5 MB 自动归档为 `.zip`

#### Q：如何查看/清理实时日志？
```bash
# WebSocket 进程日志
tail -f /www/wwwlogs/jiliao-ws.log

# PHP/Nginx 错误日志
tail -f /www/wwwlogs/jiliao_error.log

# 清空日志（不影响运行）
> /www/wwwlogs/jiliao-ws.log
```

---

## License

MIT License © 极聊（商用版）开发团队

本项目仅供学习与商业使用，禁止用于违法违规用途。

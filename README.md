# MiniChat（PHP + Workerman）

仿 QQ/微信风格的极简聊天系统，支持私聊、群聊、图片消息、消息撤回、未读计数、群成员侧栏、管理员后台、TXT + MySQL 双重存储。

## 1. 运行环境

- PHP 7.4+
- MySQL 5.7+
- Composer
- 可选：Supervisor（用于守护 WebSocket 进程）

## 2. 项目结构

- `public/` 前端页面与静态资源（站点根目录）
- `server/api/index.php` REST API 入口
- `server/ws/server.php` Workerman WebSocket 服务
- `server/core/` 核心类（环境、数据库、鉴权、限流、响应、TXT 存储）
- `scripts/schema.sql` 数据库结构
- `scripts/install.php` 安装初始化脚本
- `storage/chat_logs/` TXT 聊天日志（按日期切片）
- `storage/archive/` 归档压缩文件

## 3. 安装步骤（本地/服务器通用）

1) 安装依赖

```bash
composer install
```

2) 配置环境

```bash
cp .env.example .env
```

编辑 `.env`，填好数据库账号密码与站点地址。

3) 初始化数据库与管理员

```bash
php scripts/install.php
```

默认管理员：

- 账号：`admin@local.test`
- 密码：`Admin@123`

4) 启动 WebSocket

```bash
php server/ws/server.php start -d
```

停止：

```bash
php server/ws/server.php stop
```

5) 站点根目录指向 `public/`

浏览器访问：

- 首页：`/index.html`
- 登录：`/auth.html`
- 聊天列表：`/app.html`
- 聊天详情：`/chat.html`
- 管理后台：`/admin.html`

## 4. 功能清单

- 宣传主页 + 立即体验
- 登录 / 注册 / 忘记密码（支持邮箱验证码）
- 固定综合群（`0001`），新用户自动加入
- 聊天列表未读红点与在线人数（应到/实到）
- 聊天详情支持文字/图片、引用、撤回、删除
- 群成员侧栏，点击头像发起私聊
- 群管理：禁言、踢人、黑名单、转让群、解散群
- 账号注销：邮箱验证码确认 + 30天邮箱冷却
- 管理后台：统计、用户管理、群公告、TXT 搜索/归档
- 自定义 CSS 上传并全站生效

## 5. 存储策略

- MySQL 保存结构化数据（用户、群、成员关系、消息、权限、设置）
- TXT 保存聊天冗余日志：
	- 文件命名：`chat_{type}_{id}_YYYYMMDD.txt`
	- 存储目录：`storage/chat_logs/`
	- 支持按天切片与后台归档压缩

## 6. 宝塔部署简要

详见 `docs/baota-deploy.md`。

## 7. 注意事项

- 如果是 HTTPS 站点，请确认 WebSocket 使用 `wss://`（前端会自动跟随协议）
- Nginx/Apache 需放通 WebSocket 端口（默认 `2346`）
- 生产环境请修改管理员初始密码与数据库口令
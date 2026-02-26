# 宝塔面板部署说明（Ubuntu / CentOS 通用）

## 1) 上传代码

- 将项目上传到服务器，例如：`/www/wwwroot/minichat`
- 站点根目录设置为：`/www/wwwroot/minichat/public`

## 2) 安装依赖

在宝塔终端执行：

```bash
cd /www/wwwroot/minichat
composer install --no-dev
cp .env.example .env
```

编辑 `.env`：

- `APP_URL` 改为你的域名
- `DB_*` 改为宝塔数据库信息

## 3) 初始化数据库

```bash
php scripts/install.php
```

默认管理员：`admin@local.test / Admin@123`

## 4) 配置伪静态

### Apache

项目已提供 `public/.htaccess`，直接生效。

### Nginx（宝塔常用）

在站点配置中加入：

```nginx
location / {
    try_files $uri $uri/ /index.html;
}

location /api/ {
    rewrite ^/api/(.*)$ /api.php?r=$1 last;
}

location ~ \.php$ {
    fastcgi_pass unix:/tmp/php-cgi-74.sock;
    fastcgi_index index.php;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

> `fastcgi_pass` 请改为你的 PHP-FPM 实际 sock 或端口。

## 5) 启动 WebSocket

```bash
cd /www/wwwroot/minichat
php server/ws/server.php start -d
```

查看状态：

```bash
php server/ws/server.php status
```

停止：

```bash
php server/ws/server.php stop
```

## 6) 宝塔“计划任务/守护进程”建议

可配置 `Supervisor` 或宝塔“守护进程”命令：

```bash
cd /www/wwwroot/minichat && php server/ws/server.php start
```

## 7) 端口与防火墙

- 默认 WebSocket 端口：`2346`
- 安全组与防火墙放行该端口（或使用 Nginx 反代到同域名）

## 8) 常见问题

- 页面能打开但实时消息不推送：检查 `WS_HOST/WS_PORT` 与防火墙
- 上传图片失败：检查 `public/uploads/` 目录写权限
- 验证码收不到：先在后台查看是否启用 SMTP；未配置 SMTP 时系统走测试模式（调试可返回验证码）

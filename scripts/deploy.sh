#!/bin/bash
# =============================================================
#  极聊（商用版）- 自动部署脚本
#  由 webhook.php 触发，或手动执行：bash scripts/deploy.sh
# =============================================================

set -euo pipefail

# ── 项目根目录（脚本位于 scripts/ 子目录下）─────────────────────
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# ── 配置 ──────────────────────────────────────────────────────
PHP_BIN="${PHP_BIN:-/www/server/php/81/bin/php}"
COMPOSER_BIN="${COMPOSER_BIN:-/usr/local/bin/composer}"
WS_SERVICE="jiliao-ws"           # supervisor 进程名
BRANCH="${DEPLOY_BRANCH:-main}"  # 要拉取的分支

# ── 日志函数 ──────────────────────────────────────────────────
log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }

log "========== 开始部署 =========="
log "项目目录: $ROOT"
log "PHP: $PHP_BIN"

cd "$ROOT"

# ── 1. 拉取最新代码 ───────────────────────────────────────────
log "[1/5] git pull origin $BRANCH"
git fetch origin "$BRANCH"
git reset --hard "origin/$BRANCH"
log "当前 commit: $(git rev-parse --short HEAD) - $(git log -1 --pretty='%s')"

# ── 2. 安装/更新 Composer 依赖（仅在 composer.json 变更时）────
if git diff HEAD@{1} HEAD --name-only 2>/dev/null | grep -q "composer"; then
    log "[2/5] composer.json 有变更，更新依赖…"
    "$PHP_BIN" "$COMPOSER_BIN" install \
        --no-dev \
        --optimize-autoloader \
        --no-interaction \
        --prefer-dist
else
    log "[2/5] composer.json 无变更，跳过"
fi

# ── 3. 确保目录权限正确 ───────────────────────────────────────
log "[3/5] 修正目录权限"
mkdir -p "$ROOT/public/uploads/avatars" "$ROOT/public/uploads/images"
mkdir -p "$ROOT/storage/chat/group"     "$ROOT/storage/chat/private"
mkdir -p "$ROOT/storage/logs"
chown -R www:www "$ROOT/public/uploads" "$ROOT/storage" 2>/dev/null || true
chmod -R 775     "$ROOT/public/uploads" "$ROOT/storage" 2>/dev/null || true

# ── 4. 执行数据库在线迁移（仅首次或结构有变更时生效，API 内已内联）─
log "[4/5] 跳过独立迁移（迁移已内联在 API 启动逻辑中）"

# ── 5. 重启 WebSocket（若 server/ 目录有变更才重启）─────────────
if git diff HEAD@{1} HEAD --name-only 2>/dev/null | grep -qE "^server/"; then
    log "[5/5] server/ 目录有变更，重启 WebSocket 进程…"
    if command -v supervisorctl &>/dev/null; then
        supervisorctl restart "$WS_SERVICE" && log "WebSocket 重启成功" || log "WARN: supervisorctl restart 失败，请手动重启"
    else
        log "WARN: supervisorctl 未找到，跳过 WebSocket 重启"
    fi
else
    log "[5/5] server/ 无变更，跳过 WebSocket 重启"
fi

log "========== 部署完成 =========="

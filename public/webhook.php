<?php
/**
 * 极聊（商用版）- GitHub Webhook 自动部署入口
 *
 * 配置方法：
 *   1. 在 GitHub 仓库 Settings → Webhooks → Add webhook
 *      Payload URL : https://你的域名/webhook.php
 *      Content type: application/json
 *      Secret      : 与 WEBHOOK_SECRET 相同的随机字符串
 *      Events      : Just the push event
 *   2. 将 WEBHOOK_SECRET 写入项目根目录的 .env 文件
 *      WEBHOOK_SECRET=your_random_secret_here
 *   3. 确保 www 用户对项目目录有 git pull 权限（见 README 说明）
 *
 * 部署日志写入 /www/wwwlogs/jiliao-deploy.log
 */

declare(strict_types=1);

// ── 配置 ──────────────────────────────────────────────────────
define('PROJECT_DIR',  dirname(__DIR__));
define('DEPLOY_LOG',   '/www/wwwlogs/jiliao-deploy.log');
define('DEPLOY_SCRIPT', PROJECT_DIR . '/scripts/deploy.sh');

header('Content-Type: application/json; charset=UTF-8');

// ── 加载 .env 读取 WEBHOOK_SECRET ──────────────────────────────
$envFile = PROJECT_DIR . '/.env';
$secret  = '';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), 'WEBHOOK_SECRET=')) {
            $secret = trim(substr($line, strrpos($line, '=') + 1), " \t\n\r\"'");
            break;
        }
    }
}
if (!$secret) {
    http_response_code(500);
    exit(json_encode(['error' => 'WEBHOOK_SECRET not configured in .env']));
}

// ── 验证请求方法 ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

// ── 读取原始 Body ───────────────────────────────────────────────
$rawBody = file_get_contents('php://input');

// ── 验证 GitHub HMAC-SHA256 签名 ───────────────────────────────
$sigHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if (!$sigHeader) {
    http_response_code(401);
    exit(json_encode(['error' => 'Missing signature header']));
}

$expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
if (!hash_equals($expected, $sigHeader)) {
    http_response_code(403);
    logDeploy('WARN', 'Signature mismatch from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    exit(json_encode(['error' => 'Invalid signature']));
}

// ── 解析 payload ────────────────────────────────────────────────
$payload = json_decode($rawBody, true);
$event   = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? 'push';
$branch  = basename($payload['ref'] ?? 'refs/heads/main');

logDeploy('INFO', "Received event={$event} branch={$branch}");

// 只处理 push 到 main/master 分支
if ($event !== 'push' || !in_array($branch, ['main', 'master'])) {
    exit(json_encode(['status' => 'skipped', 'reason' => "event={$event} branch={$branch}"]));
}

// ── 执行部署脚本 ────────────────────────────────────────────────
if (!file_exists(DEPLOY_SCRIPT)) {
    http_response_code(500);
    exit(json_encode(['error' => 'deploy.sh not found: ' . DEPLOY_SCRIPT]));
}

// 异步执行（立即返回 200，后台继续部署）
$logFile = DEPLOY_LOG;
$cmd     = "bash " . escapeshellarg(DEPLOY_SCRIPT) . " >> " . escapeshellarg($logFile) . " 2>&1 &";
exec($cmd);

$pusher  = $payload['pusher']['name'] ?? 'unknown';
$commits = count($payload['commits'] ?? []);
$msg     = "Deploy triggered: {$commits} commit(s) by {$pusher} on branch {$branch}";
logDeploy('INFO', $msg);

echo json_encode(['status' => 'ok', 'msg' => $msg]);

// ── 写入日志 ────────────────────────────────────────────────────
function logDeploy(string $level, string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . "] [{$level}] {$msg}\n";
    @file_put_contents(DEPLOY_LOG, $line, FILE_APPEND | LOCK_EX);
}

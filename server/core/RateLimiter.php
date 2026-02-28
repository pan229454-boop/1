<?php
/**
 * 极聊（商用版）- 限流器
 *
 * 基于滑动窗口算法，使用文件缓存实现 IP 请求频率限制
 *
 * @package JiLiao\Core
 */

declare(strict_types=1);

namespace JiLiao\Core;

class RateLimiter
{
    /** @var string 缓存目录 */
    private string $cacheDir;

    public function __construct()
    {
        $this->cacheDir = STORAGE_PATH . '/cache/rate';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * 检查并记录一次请求，超限则返回 false
     *
     * @param  string $key      限流键（如 ip:127.0.0.1 或 uid:123）
     * @param  int    $limit    时间窗口内允许的最大请求次数
     * @param  int    $window   时间窗口（秒）
     * @return bool  true=通过 false=超限
     */
    public function check(string $key, int $limit = 60, int $window = 60): bool
    {
        $file = $this->cacheDir . '/' . md5($key) . '.rate';
        $now  = time();

        // ── 读取历史请求时间戳列表 ────────────────────────────
        $timestamps = [];
        if (file_exists($file)) {
            $raw = file_get_contents($file);
            $timestamps = $raw ? array_map('intval', explode(',', trim($raw))) : [];
        }

        // ── 滑动窗口：过滤掉窗口外的请求 ─────────────────────
        $timestamps = array_filter($timestamps, fn($t) => $t > $now - $window);
        $timestamps = array_values($timestamps);

        if (count($timestamps) >= $limit) {
            return false; // 超限
        }

        // ── 追加当前时间戳并保存 ──────────────────────────────
        $timestamps[] = $now;
        file_put_contents($file, implode(',', $timestamps), LOCK_EX);

        return true;
    }

    /**
     * 获取当前限流剩余次数
     *
     * @param  string $key    限流键
     * @param  int    $limit  最大次数
     * @param  int    $window 时间窗口（秒）
     * @return int    剩余次数
     */
    public function remaining(string $key, int $limit = 60, int $window = 60): int
    {
        $file = $this->cacheDir . '/' . md5($key) . '.rate';
        $now  = time();
        if (!file_exists($file)) return $limit;

        $raw        = file_get_contents($file);
        $timestamps = $raw ? array_map('intval', explode(',', trim($raw))) : [];
        $active     = array_filter($timestamps, fn($t) => $t > $now - $window);

        return max(0, $limit - count($active));
    }

    /**
     * 重置指定键的限流计数
     *
     * @param  string $key 限流键
     * @return void
     */
    public function reset(string $key): void
    {
        $file = $this->cacheDir . '/' . md5($key) . '.rate';
        if (file_exists($file)) @unlink($file);
    }

    /**
     * 清理过期缓存文件（建议通过 cron 定期调用）
     *
     * @param  int $maxAge 最大保留秒数，默认 3600
     * @return void
     */
    public function gc(int $maxAge = 3600): void
    {
        foreach (glob($this->cacheDir . '/*.rate') ?: [] as $f) {
            if (filemtime($f) < time() - $maxAge) {
                @unlink($f);
            }
        }
    }
}

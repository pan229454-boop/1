<?php
/**
 * 极聊（商用版）- 聊天记录存储类
 *
 * TXT 为主存储，数据库保存消息元数据（索引/撤回/精华等）
 * TXT 路径规则：
 *   群聊   → storage/chat/group/chat_{gid}_{YYYYMMDD}.txt
 *   私聊   → storage/chat/private/chat_{小uid}_{大uid}_{YYYYMMDD}.txt
 *
 * @package JiLiao\Core
 */

declare(strict_types=1);

namespace JiLiao\Core;

class ChatStorage
{
    /** TXT 单条消息分隔符 */
    private const LINE_SEP = "\n";

    /** 归档触发阈值（字节）默认 5 MB */
    private const ARCHIVE_SIZE = 5242880;

    /** @var Database 数据库实例 */
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
        // 确保存储目录存在
        foreach ([CHAT_PATH . '/group', CHAT_PATH . '/private'] as $dir) {
            if (!is_dir($dir)) mkdir($dir, 0755, true);
        }
    }

    // ──────────────────────────────────────────────────────────
    //  写入消息
    // ──────────────────────────────────────────────────────────

    /**
     * 保存一条消息到 TXT + 数据库元数据
     *
     * @param  array $msg {
     *   msg_id, chat_type(1私/2群), from_uid, to_id,
     *   msg_type(1文字/2图片), content, reply_msg_id, at_uids[]
     * }
     * @return array ['code'=>0,'msg_id'=>'...']
     */
    public function save(array $msg): array
    {
        $msgId    = $msg['msg_id']    ?? uuid4();
        $chatType = (int)($msg['chat_type'] ?? 2);
        $fromUid  = (int)$msg['from_uid'];
        $toId     = $msg['to_id'];         // 群聊=gid, 私聊=对方uid
        $msgType  = (int)($msg['msg_type'] ?? 1);
        $content  = $msg['content'] ?? '';
        $replyId  = $msg['reply_msg_id'] ?? null;
        $atUids   = implode(',', (array)($msg['at_uids'] ?? []));
        $ts       = date('Y-m-d H:i:s');

        // ── 拼装 TXT 行（JSON 格式，便于检索）──────────────────
        $line = json_encode([
            'id'       => $msgId,
            'type'     => $msgType,
            'from'     => $fromUid,
            'content'  => $content,
            'reply'    => $replyId,
            'at'       => $atUids,
            'ts'       => $ts,
        ], JSON_UNESCAPED_UNICODE) . self::LINE_SEP;

        // ── 写入 TXT ─────────────────────────────────────────
        $file = $this->txtPath($chatType, $fromUid, $toId);
        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

        // ── 检查是否需要归档 ──────────────────────────────────
        if (file_exists($file) && filesize($file) >= self::ARCHIVE_SIZE) {
            $this->archive($file);
        }

        // ── 写入数据库元数据（用于撤回/精华/索引）─────────────
        $this->db->execute(
            'INSERT INTO messages
             (msg_id,chat_type,from_uid,to_id,msg_type,is_deleted,is_top,is_essence,at_uids,reply_msg_id,created_at)
             VALUES (?,?,?,?,?,0,0,0,?,?,?)',
            [$msgId, $chatType, $fromUid, $toId, $msgType, $atUids, $replyId, $ts]
        );

        return ['code' => 0, 'msg_id' => $msgId];
    }

    // ──────────────────────────────────────────────────────────
    //  读取历史消息
    // ──────────────────────────────────────────────────────────

    /**
     * 读取指定会话的最近消息（倒序最新 N 条）
     *
     * @param  int    $chatType  1=私聊 2=群聊
     * @param  int    $fromUid   当前用户 UID
     * @param  string $toId      目标ID
     * @param  int    $limit     返回条数，默认50
     * @param  string $date      指定日期 YYYYMMDD，默认今天
     * @return array  消息数组（时间正序）
     */
    public function load(int $chatType, int $fromUid, string $toId, int $limit = 50, string $date = ''): array
    {
        $date  = $date ?: date('Ymd');
        $file  = $this->txtPath($chatType, $fromUid, $toId, $date);

        if (!file_exists($file)) return [];

        // ── 读取 TXT 行 ───────────────────────────────────────
        $lines = array_filter(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $lines = array_slice($lines, -$limit); // 只取最后 N 条

        // ── 解析 JSON 并过滤已撤回消息（对己不展示）─────────────
        $deletedIds = $this->db->query(
            'SELECT msg_id FROM messages WHERE is_deleted=1 AND from_uid=? AND to_id=?',
            [$fromUid, $toId]
        );
        $deletedSet = array_column($deletedIds, 'msg_id');

        $result = [];
        foreach ($lines as $line) {
            $m = json_decode($line, true);
            if (!$m) continue;
            if (in_array($m['id'], $deletedSet, true)) continue; // 跳过已撤回
            $result[] = $m;
        }
        return $result;
    }

    // ──────────────────────────────────────────────────────────
    //  消息搜索
    // ──────────────────────────────────────────────────────────

    /**
     * 在 TXT 中按关键词全文搜索（支持多天）
     *
     * @param  int    $chatType 1=私聊 2=群聊
     * @param  int    $fromUid  当前用户 UID
     * @param  string $toId     目标ID
     * @param  string $keyword  搜索词
     * @param  int    $days     向前搜索天数，默认30天
     * @return array  匹配消息列表
     */
    public function search(int $chatType, int $fromUid, string $toId, string $keyword, int $days = 30): array
    {
        $results = [];
        $keyword = strtolower($keyword);

        for ($i = 0; $i < $days; $i++) {
            $date = date('Ymd', strtotime("-{$i} days"));
            $file = $this->txtPath($chatType, $fromUid, $toId, $date);
            if (!file_exists($file)) continue;

            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (stripos($line, $keyword) !== false) {
                    $m = json_decode($line, true);
                    if ($m) $results[] = $m;
                }
            }
        }
        return $results;
    }

    // ──────────────────────────────────────────────────────────
    //  撤回 / 删除
    // ──────────────────────────────────────────────────────────

    /**
     * 撤回消息（仅对己方隐藏，TXT 原始记录保留）
     *
     * @param  string $msgId   消息UUID
     * @param  int    $uid     操作者UID
     * @return array
     */
    public function recall(string $msgId, int $uid): array
    {
        // 仅允许2分钟内撤回（可后台配置）
        $row = $this->db->first(
            'SELECT from_uid, created_at FROM messages WHERE msg_id=?',
            [$msgId]
        );
        if (!$row) return ['code' => 404, 'msg' => '消息不存在'];
        if ($row['from_uid'] != $uid) return ['code' => 403, 'msg' => '只能撤回自己的消息'];

        $recallWindow = 120; // 秒
        if (time() - strtotime($row['created_at']) > $recallWindow) {
            return ['code' => 400, 'msg' => '超过撤回时限（2分钟）'];
        }

        $this->db->execute(
            'UPDATE messages SET is_deleted=1 WHERE msg_id=? AND from_uid=?',
            [$msgId, $uid]
        );
        return ['code' => 0, 'msg' => '已撤回'];
    }

    // ──────────────────────────────────────────────────────────
    //  TXT 归档（按日期自动分片 + ZIP 压缩）
    // ──────────────────────────────────────────────────────────

    /**
     * 将超大 TXT 文件压缩为 ZIP 并清理
     *
     * @param  string $file 原始 TXT 路径
     * @return void
     */
    public function archive(string $file): void
    {
        if (!file_exists($file)) return;

        $zip      = new \ZipArchive();
        $zipPath  = $file . '_' . date('YmdHis') . '.zip';

        if ($zip->open($zipPath, \ZipArchive::CREATE) === true) {
            $zip->addFile($file, basename($file));
            $zip->close();
            @unlink($file); // 删除原文件
        }
    }

    /**
     * 清理过期归档（超过 N 天的 ZIP 文件）
     *
     * @param  int $days 保留天数，默认90天
     * @return void
     */
    public function cleanOldArchives(int $days = 90): void
    {
        $dirs = [CHAT_PATH . '/group', CHAT_PATH . '/private'];
        foreach ($dirs as $dir) {
            foreach (glob("{$dir}/*.zip") ?: [] as $zip) {
                if (filemtime($zip) < strtotime("-{$days} days")) {
                    @unlink($zip);
                }
            }
        }
    }

    // ──────────────────────────────────────────────────────────
    //  辅助：计算 TXT 文件路径
    // ──────────────────────────────────────────────────────────

    /**
     * 根据会话类型计算 TXT 存储路径
     *
     * @param  int    $chatType 1=私聊 2=群聊
     * @param  int    $fromUid  当前用户UID
     * @param  string $toId     目标ID
     * @param  string $date     日期字符串 YYYYMMDD，默认今天
     * @return string 完整文件路径
     */
    private function txtPath(int $chatType, int $fromUid, string $toId, string $date = ''): string
    {
        $date = $date ?: date('Ymd');

        if ($chatType === 2) {
            // 群聊：chat_{gid}_{date}.txt
            return CHAT_PATH . "/group/chat_{$toId}_{$date}.txt";
        } else {
            // 私聊：chat_{小uid}_{大uid}_{date}.txt（保证双方读同一文件）
            $a = min($fromUid, (int)$toId);
            $b = max($fromUid, (int)$toId);
            return CHAT_PATH . "/private/chat_{$a}_{$b}_{$date}.txt";
        }
    }
}

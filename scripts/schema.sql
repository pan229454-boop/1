-- ============================================================
-- 极聊（商用版）数据库结构
-- 支持 MySQL 5.7+ / MariaDB 10.3+
-- 字符集：utf8mb4（支持 emoji）
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 用户表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
    `uid`           MEDIUMINT UNSIGNED NOT NULL COMMENT '对外账号ID 1-99999 顺序分配',
    `username`      VARCHAR(32) NOT NULL COMMENT '用户名（登录用）',
    `nickname`      VARCHAR(32) NOT NULL COMMENT '昵称（展示用）',
    `password`      VARCHAR(128) NOT NULL COMMENT 'bcrypt 哈希密码',
    `avatar`        VARCHAR(255) DEFAULT '' COMMENT '头像相对路径',
    `email`         VARCHAR(128) DEFAULT '' COMMENT '邮箱',
    `phone`         VARCHAR(20) DEFAULT '' COMMENT '手机号',
    `role`          TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '角色: 1=普通 2=会员 3=管理员 9=超管',
    `status`        TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态: 0=禁用 1=正常 2=冻结',
    `is_online`     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否在线',
    `last_ip`       VARCHAR(45) DEFAULT '' COMMENT '最后登录IP',
    `last_login_at` DATETIME DEFAULT NULL COMMENT '最后登录时间',
    `freeze_until`  DATETIME DEFAULT NULL COMMENT '冻结到期时间（注销30天冻结）',    `cancel_at`     DATETIME DEFAULT NULL COMMENT '申请注销时间（设置则进入冷静期）',    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '注册时间',
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_uid` (`uid`),
    UNIQUE KEY `uk_username` (`username`),
    KEY `idx_email` (`email`),
    KEY `idx_phone` (`phone`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户表';

-- ------------------------------------------------------------
-- 群组表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `groups` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
    `gid`           CHAR(4) NOT NULL COMMENT '群ID 0001-9999 固定4位',
    `name`          VARCHAR(64) NOT NULL COMMENT '群名称',
    `avatar`        VARCHAR(255) DEFAULT '' COMMENT '群头像',
    `description`   TEXT DEFAULT NULL COMMENT '群简介',
    `owner_uid`     MEDIUMINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '群主UID',
    `is_default`    TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否默认群（综合群 0001）',
    `is_muted`      TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '全群禁言',
    `join_approval` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '加群审批: 0=自由 1=需审批',
    `max_members`   INT UNSIGNED NOT NULL DEFAULT 500 COMMENT '最大成员数',
    `status`        TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态: 0=解散 1=正常',
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_gid` (`gid`),
    KEY `idx_owner` (`owner_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='群组表';

-- ------------------------------------------------------------
-- 群成员表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `group_members` (
    `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `gid`       CHAR(4) NOT NULL COMMENT '群ID',
    `uid`       MEDIUMINT UNSIGNED NOT NULL COMMENT '用户UID',
    `role`      TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '群角色: 0=成员 1=管理员 2=群主',
    `title`     VARCHAR(32) DEFAULT '' COMMENT '群头衔（如"作者"）',
    `is_muted`  TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否被禁言',
    `is_banned` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否在黑名单',
    `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '入群时间',
    `mute_until` DATETIME DEFAULT NULL COMMENT '禁言到期时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_gid_uid` (`gid`, `uid`),
    KEY `idx_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='群成员表';

-- ------------------------------------------------------------
-- 好友关系表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `friendships` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uid_a`      MEDIUMINT UNSIGNED NOT NULL COMMENT '用户A UID（较小值）',
    `uid_b`      MEDIUMINT UNSIGNED NOT NULL COMMENT '用户B UID（较大值）',
    `status`     TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1=好友 2=A拉黑B 3=B拉黑A',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ab` (`uid_a`, `uid_b`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='好友关系表';

-- ------------------------------------------------------------
-- 消息表（结构化存储，TXT为主，此处为索引/元数据）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `messages` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `msg_id`       CHAR(36) NOT NULL COMMENT 'UUID消息唯一标识',
    `chat_type`    TINYINT UNSIGNED NOT NULL COMMENT '1=私聊 2=群聊',
    `from_uid`     MEDIUMINT UNSIGNED NOT NULL COMMENT '发送者UID',
    `to_id`        VARCHAR(10) NOT NULL COMMENT '目标ID（私聊=对方UID, 群聊=gid）',
    `msg_type`     TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1=文字 2=图片 3=文件 4=系统',
    `content`      TEXT NOT NULL COMMENT '消息内容（文字）或文件路径',
    `at_uids`      VARCHAR(255) DEFAULT '' COMMENT '@提及的UID列表，逗号分隔',
    `reply_msg_id` CHAR(36) DEFAULT NULL COMMENT '引用回复的消息UUID',
    `is_top`       TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否置顶',
    `is_essence`   TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否精华',
    `essence_until` DATETIME DEFAULT NULL COMMENT '精华到期时间',
    `is_deleted`   TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '逻辑删除（仅己方不可见）',
    `is_recalled`  TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否已撤回',
    `recalled_at`  DATETIME DEFAULT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_msg_id` (`msg_id`),
    KEY `idx_chat` (`chat_type`, `to_id`, `created_at`),
    KEY `idx_from` (`from_uid`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='消息索引表（内容同步写入TXT）';

-- ------------------------------------------------------------
-- 群公告表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `announcements` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `gid`        CHAR(4) NOT NULL,
    `content`    TEXT NOT NULL,
    `author_uid` MEDIUMINT UNSIGNED NOT NULL,
    `is_pinned`  TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_gid` (`gid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='群公告表';

-- ------------------------------------------------------------
-- 加群申请表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `join_requests` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `gid`        CHAR(4) NOT NULL,
    `uid`        MEDIUMINT UNSIGNED NOT NULL,
    `message`    VARCHAR(255) DEFAULT '' COMMENT '申请留言',
    `status`     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=待审 1=通过 2=拒绝',
    `handler_uid` MEDIUMINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_gid_uid` (`gid`, `uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='加群申请表';

-- ------------------------------------------------------------
-- 系统设置表（KV结构）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
    `key`        VARCHAR(64) NOT NULL,
    `val`        TEXT DEFAULT NULL COMMENT '设置值',
    `group`      VARCHAR(32) NOT NULL DEFAULT 'general',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统设置KV表';

-- ------------------------------------------------------------
-- 登录失败记录表（防暴力破解）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_failures` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `identifier` VARCHAR(128) NOT NULL COMMENT '用户名或IP',
    `ip`         VARCHAR(45) NOT NULL,
    `count`      TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `locked_until` DATETIME DEFAULT NULL,
    `last_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_identifier` (`identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='登录失败记录表';

-- ------------------------------------------------------------
-- 邮件验证码表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `verify_codes` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `target`     VARCHAR(128) NOT NULL COMMENT '邮箱或手机号',
    `code`       VARCHAR(8) NOT NULL,
    `type`       VARCHAR(32) NOT NULL COMMENT 'register/reset/email_change',
    `expires_at` DATETIME NOT NULL,
    `used`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_target_type` (`target`, `type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='验证码表';

-- ------------------------------------------------------------
-- 文件上传记录表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `uploads` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uid`        MEDIUMINT UNSIGNED NOT NULL,
    `filename`    VARCHAR(255) NOT NULL,
    `filepath`    VARCHAR(512) NOT NULL,
    `filetype`    VARCHAR(32) NOT NULL,
    `filesize`    INT UNSIGNED NOT NULL COMMENT '字节',
    `upload_date` DATE NOT NULL COMMENT '用于日限额统计',
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_uid_date` (`uid`, `upload_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文件上传记录';

-- ------------------------------------------------------------
-- 未读消息计数表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `unread_counts` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uid`        MEDIUMINT UNSIGNED NOT NULL COMMENT '接收方UID',
    `chat_type`  TINYINT UNSIGNED NOT NULL COMMENT '1=私聊 2=群聊',
    `from_id`    VARCHAR(10) NOT NULL COMMENT '私聊=发送者UID, 群聊=gid',
    `cnt`        INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '未读条数',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_uid_type_from` (`uid`, `chat_type`, `from_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='未读消息计数表';

-- ------------------------------------------------------------
-- 群公告表（notices，API端使用）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notices` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `gid`        CHAR(4) NOT NULL COMMENT '群ID',
    `content`    TEXT NOT NULL COMMENT '公告内容',
    `created_by` MEDIUMINT UNSIGNED NOT NULL COMMENT '发布者UID',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_gid` (`gid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='群公告表';

-- ------------------------------------------------------------
-- 插入默认数据
-- ------------------------------------------------------------

-- 综合群（ID=0001，不可退出的默认群）
INSERT IGNORE INTO `groups` (`gid`,`name`,`description`,`owner_uid`,`is_default`,`status`)
VALUES ('0001','综合群','全站公共群组，所有用户自动加入',0,1,1);

-- 默认系统设置
INSERT IGNORE INTO `settings` (`key`,`val`,`group`) VALUES
('register_enable','1','auth'),
('register_email_verify','0','auth'),
('register_phone_verify','0','auth'),
('register_invite_only','0','auth'),
('captcha_enable','1','security'),
('captcha_slider_enable','1','security'),
('upload_max_image','10','upload'),
('upload_max_file','50','upload'),
('upload_forbidden_ext','exe,sh,bat,cmd,ps1,php,phtml,asp,aspx,jsp','upload'),
('mail_enable','0','mail'),
('app_name','极聊（商用版）','general'),
('app_logo','','general'),
('login_bg','','theme'),
('register_bg','','theme'),
('home_bg','','theme'),
('chat_bg','','theme'),
('custom_css_login','','theme'),
('custom_css_chat','','theme'),
('group_join_approval_default','0','group'),
('chat_archive_days','30','storage'),
('chat_archive_keep_days','365','storage'),
('smtp_host','','mail'),
('smtp_port','465','mail'),
('smtp_user','','mail'),
('smtp_pass','','mail'),
('smtp_from','','mail'),
('phone_regex','/^1[3-9]\\d{9}$/','auth'),
('email_verify_required','0','auth'),
('phone_verify_required','0','auth');

SET FOREIGN_KEY_CHECKS = 1;

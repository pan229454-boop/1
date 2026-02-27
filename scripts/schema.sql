CREATE DATABASE IF NOT EXISTS `minichat` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `minichat`;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_no INT UNSIGNED NOT NULL UNIQUE COMMENT '顺序分配ID(1-99999)',
  email VARCHAR(120) DEFAULT NULL UNIQUE,
  phone VARCHAR(30) DEFAULT NULL,
  password_hash VARCHAR(255) NOT NULL,
  nickname VARCHAR(60) NOT NULL,
  avatar VARCHAR(255) DEFAULT '/assets/default-avatar.png',
  status TINYINT NOT NULL DEFAULT 1 COMMENT '1正常 2禁用 3封禁',
  is_admin TINYINT NOT NULL DEFAULT 0,
  fail_login_count INT NOT NULL DEFAULT 0,
  last_login_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  deleted_at DATETIME DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS email_verify_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(120) NOT NULL,
  scene VARCHAR(50) NOT NULL,
  code VARCHAR(12) NOT NULL,
  expired_at DATETIME NOT NULL,
  used_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token VARCHAR(128) NOT NULL UNIQUE,
  expired_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_user_id (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chats (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chat_no VARCHAR(20) NOT NULL UNIQUE COMMENT '群ID或私聊ID',
  type ENUM('group','private') NOT NULL,
  name VARCHAR(120) NOT NULL,
  owner_user_id INT UNSIGNED DEFAULT NULL,
  is_system_fixed TINYINT NOT NULL DEFAULT 0,
  announcement TEXT,
  is_dissolved TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chat_members (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chat_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(60) DEFAULT '成员',
  role ENUM('owner','admin','member') NOT NULL DEFAULT 'member',
  muted_until DATETIME DEFAULT NULL,
  is_blacklisted TINYINT NOT NULL DEFAULT 0,
  joined_at DATETIME NOT NULL,
  UNIQUE KEY uk_chat_user (chat_id, user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chat_id BIGINT UNSIGNED NOT NULL,
  sender_user_id INT UNSIGNED NOT NULL,
  msg_type ENUM('text','image','system') NOT NULL,
  content TEXT,
  image_url VARCHAR(255) DEFAULT NULL,
  quote_message_id BIGINT UNSIGNED DEFAULT NULL,
  is_recalled TINYINT NOT NULL DEFAULT 0,
  is_deleted TINYINT NOT NULL DEFAULT 0,
  is_pinned TINYINT NOT NULL DEFAULT 0,
  is_featured TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  INDEX idx_chat_time (chat_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chat_unreads (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chat_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  unread_count INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uk_chat_unread (chat_id, user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_delete_cooldowns (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(120) NOT NULL UNIQUE,
  blocked_until DATETIME NOT NULL,
  reason VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS app_settings (
  `key` VARCHAR(80) PRIMARY KEY,
  `value` TEXT,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ip_rate_limits (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(64) NOT NULL,
  action VARCHAR(60) NOT NULL,
  minute_key VARCHAR(20) NOT NULL,
  hit_count INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  INDEX idx_limit (ip, action, minute_key)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_fail_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(120) DEFAULT NULL,
  ip VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO chats (id, chat_no, type, name, owner_user_id, is_system_fixed, announcement, is_dissolved, created_at, updated_at)
VALUES (1, '0001', 'group', '综合聊天群', NULL, 1, '欢迎来到综合聊天群', 0, NOW(), NOW());

INSERT IGNORE INTO app_settings (`key`, `value`, updated_at) VALUES
('email_verify_enabled', '0', NOW()),
('smtp_config', '{}', NOW()),
('imap_config', '{}', NOW()),
('pop3_config', '{}', NOW()),
('sms_config', '{}', NOW()),
('upload_image_max_mb', '0', NOW()),
('upload_policy', '{"enabled":0,"types":["jpg","jpeg","png","webp","gif"],"count":0}', NOW()),
('custom_css_file', '', NOW()),
('ip_rate_limit_per_min', '120', NOW()),
('login_fail_limit', '5', NOW()),
('captcha_enabled', '0', NOW()),
('recaptcha_site_key', '', NOW()),
('recaptcha_secret_key', '', NOW());

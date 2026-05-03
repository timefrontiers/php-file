-- ============================================================
-- timefrontiers/php-file — Fresh Install Schema
-- Engine: MariaDB 10.4+ / MySQL 8.0+
-- Charset: utf8mb4_unicode_ci
-- ============================================================

SET SQL_MODE   = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone  = "+00:00";
START TRANSACTION;

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- ============================================================
-- file_meta
-- Primary record for every file regardless of storage driver.
-- id     : surrogate BIGINT PK (used as FK in all related tables)
-- code   : human-facing 15-char TF code (prefix 583), used in URLs / API
-- _path  : relative to the configured upload_path root; never changes even
--          if the root moves (e.g. /User-Files/08744307265)
-- ============================================================

CREATE TABLE `file_meta` (
  `id`              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `code`            VARCHAR(15)       NOT NULL,
  `nice_name`       VARCHAR(256)      NOT NULL,
  `type_group`      CHAR(32)          DEFAULT NULL,
  `caption`         VARCHAR(255)      DEFAULT NULL,
  `owner`           VARCHAR(64)       NOT NULL                  COMMENT 'User code, system constant (e.g. SYSTEM, SYSTEM.HIDDEN), or any string identifier',
  `privacy`         ENUM('public','private') NOT NULL DEFAULT 'public',
  `storage_driver`  ENUM('local','s3','minio','gcs','onedrive','dropbox') NOT NULL DEFAULT 'local',
  `storage_bucket`  VARCHAR(128)      DEFAULT NULL              COMMENT 'Bucket / container name for cloud drivers',
  `_name`           VARCHAR(250)      NOT NULL                  COMMENT 'Filename as stored in the driver (unique)',
  `_path`           VARCHAR(255)      NOT NULL                  COMMENT 'Relative path inside upload root',
  `_type`           CHAR(95)          NOT NULL                  COMMENT 'MIME type',
  `_size`           BIGINT UNSIGNED   NOT NULL                  COMMENT 'File size in bytes',
  `_checksum`       CHAR(128)         DEFAULT NULL              COMMENT 'SHA-512 hex digest, set on lock()',
  `_locked`         TINYINT(1)        NOT NULL DEFAULT 0,
  `_watermarked`    TINYINT(1)        NOT NULL DEFAULT 0,
  `_creator`        VARCHAR(128)      NOT NULL DEFAULT 'SYSTEM',
  `_updated`        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `_created`        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  UNIQUE  KEY `code`  (`code`),
  UNIQUE  KEY `_name` (`_name`),
  KEY `owner` (`owner`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- file_tokens
-- Expiring / download-limited download tokens.
-- Resolved via HMAC-signed opaque string; DB confirms expiry + counter.
-- ============================================================

CREATE TABLE `file_tokens` (
  `id`              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `code`            VARCHAR(15)       NOT NULL                  COMMENT 'TF token code (prefix 584)',
  `file_id`         BIGINT UNSIGNED   NOT NULL,
  `token`           CHAR(64)          NOT NULL                  COMMENT 'HMAC-SHA256 signed opaque token',
  `expires_at`      DATETIME          DEFAULT NULL              COMMENT 'NULL = never expires',
  `max_downloads`   INT UNSIGNED      DEFAULT NULL              COMMENT 'NULL = unlimited',
  `download_count`  INT UNSIGNED      NOT NULL DEFAULT 0,
  `created_by`      VARCHAR(128)      NOT NULL DEFAULT 'SYSTEM',
  `_created`        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code`  (`code`),
  UNIQUE KEY `token` (`token`),
  KEY `file_id` (`file_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- file_default
-- Maps a (user_id, set_key) to one or more files.
-- multi_set = false  → only one row per (user_id, set_key); old rows replaced
-- multi_set = true   → multiple rows allowed; srt controls order
-- ============================================================

CREATE TABLE `file_default` (
  `id`              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `user`            VARCHAR(64)       NOT NULL                  COMMENT 'User code, system identifier, or any string owner value',
  `set_key`         VARCHAR(64)       NOT NULL                  COMMENT 'Context key, e.g. "avatar", "banner"',
  `file_id`         BIGINT UNSIGNED   NOT NULL,
  `srt`             TINYINT UNSIGNED  NOT NULL DEFAULT 0        COMMENT 'Sort order within a set',
  `_updated`        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `user`     (`user`),
  KEY `file_id`  (`file_id`),
  KEY `set_key`  (`set_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- folders
-- Named containers that group files for a user.
-- ============================================================

CREATE TABLE `folders` (
  `id`        INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `name`      VARCHAR(64)     NOT NULL                          COMMENT 'URL-safe slug',
  `title`     VARCHAR(128)    NOT NULL,
  `owner`     VARCHAR(64)     NOT NULL                          COMMENT 'User code, system identifier, or any string owner value',
  `_author`   VARCHAR(128)    NOT NULL,
  `_created`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `owner` (`owner`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- folder_files
-- Pivot: which files belong to which folder.
-- ============================================================

CREATE TABLE `folder_files` (
  `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `folder_id`  INT UNSIGNED    NOT NULL,
  `file_id`    BIGINT UNSIGNED NOT NULL,
  `_created`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY  (`id`),
  KEY `folder_id` (`folder_id`),
  KEY `file_id`   (`file_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

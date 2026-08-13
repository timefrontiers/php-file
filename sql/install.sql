-- timefrontiers/php-file v1.1.0 fresh-install schema
-- MariaDB 10.4+ / MySQL 8.0+, InnoDB, UTC

SET time_zone = '+00:00';
SET NAMES utf8mb4;
START TRANSACTION;

CREATE TABLE `file_meta` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(15) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nice_name` VARCHAR(256) NOT NULL,
  `type_group` VARCHAR(32) DEFAULT NULL,
  `caption` VARCHAR(255) DEFAULT NULL,
  `owner` VARCHAR(64) NOT NULL,
  `privacy` ENUM('public','private') NOT NULL DEFAULT 'public',
  `storage_driver` ENUM('local','s3','minio') NOT NULL,
  `storage_bucket` VARCHAR(128) DEFAULT NULL,
  `_name` VARCHAR(250) NOT NULL,
  `_path` VARCHAR(700) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `object_key` VARCHAR(700) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `_type` VARCHAR(127) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `_size` BIGINT UNSIGNED NOT NULL,
  `_checksum` CHAR(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `_locked` TINYINT(1) NOT NULL DEFAULT 0,
  `_watermarked` TINYINT(1) NOT NULL DEFAULT 0,
  `lifecycle_state` ENUM('pending_upload','active','deleting','cleanup_required','deleted') NOT NULL DEFAULT 'active',
  `cleanup_required_at` DATETIME DEFAULT NULL,
  `cleanup_error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `cleanup_operation` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `cleanup_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `deleted_at` DATETIME DEFAULT NULL,
  `_creator` VARCHAR(128) NOT NULL DEFAULT 'SYSTEM',
  `_updated` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_file_meta_code` (`code`),
  UNIQUE KEY `uq_file_meta_driver_object` (`storage_driver`, `object_key`),
  KEY `ix_file_meta_owner_state` (`owner`, `lifecycle_state`),
  KEY `ix_file_meta_cleanup` (`lifecycle_state`, `cleanup_required_at`),
  CONSTRAINT `chk_file_meta_code` CHECK (`code` REGEXP '^583[0-9]{8,12}$'),
  CONSTRAINT `chk_file_meta_object_key` CHECK (
    `object_key` <> ''
    AND LEFT(`object_key`, 1) <> '/'
    AND RIGHT(`object_key`, 1) <> '/'
    AND LOCATE(CHAR(92), `object_key`) = 0
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `file_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(15) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `file_id` BIGINT UNSIGNED NOT NULL,
  `token_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `token_key_id` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expires_at` DATETIME DEFAULT NULL,
  `max_downloads` INT UNSIGNED DEFAULT NULL,
  `download_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `revoked_at` DATETIME DEFAULT NULL,
  `created_by` VARCHAR(128) NOT NULL DEFAULT 'SYSTEM',
  `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_file_tokens_code` (`code`),
  UNIQUE KEY `uq_file_tokens_digest` (`token_key_id`, `token_digest`),
  KEY `ix_file_tokens_consume` (`token_key_id`, `token_digest`, `file_id`, `revoked_at`, `expires_at`),
  KEY `ix_file_tokens_file` (`file_id`),
  CONSTRAINT `fk_file_tokens_file` FOREIGN KEY (`file_id`) REFERENCES `file_meta` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_file_tokens_code` CHECK (`code` REGEXP '^584[0-9]{8,12}$'),
  CONSTRAINT `chk_file_tokens_limit` CHECK (`max_downloads` IS NULL OR `max_downloads` > 0),
  CONSTRAINT `chk_file_tokens_count` CHECK (`max_downloads` IS NULL OR `download_count` <= `max_downloads`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `file_default_sets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user` VARCHAR(64) NOT NULL,
  `set_key` VARCHAR(64) NOT NULL,
  `mode` VARCHAR(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `_updated` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_file_default_sets_identity` (`user`, `set_key`),
  UNIQUE KEY `uq_file_default_sets_id_mode` (`id`, `mode`),
  CONSTRAINT `chk_file_default_sets_mode` CHECK (`mode` IN ('single','multi'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `file_default` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `set_id` BIGINT UNSIGNED NOT NULL,
  `set_mode` VARCHAR(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `file_id` BIGINT UNSIGNED NOT NULL,
  `srt` INT UNSIGNED NOT NULL DEFAULT 0,
  `_updated` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_file_default_position` (`set_id`, `srt`),
  UNIQUE KEY `uq_file_default_file` (`set_id`, `file_id`),
  KEY `ix_file_default_file` (`file_id`),
  KEY `ix_file_default_set_mode` (`set_id`, `set_mode`),
  CONSTRAINT `fk_file_default_set_mode` FOREIGN KEY (`set_id`, `set_mode`)
    REFERENCES `file_default_sets` (`id`, `mode`) ON UPDATE RESTRICT ON DELETE CASCADE,
  CONSTRAINT `fk_file_default_file` FOREIGN KEY (`file_id`)
    REFERENCES `file_meta` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_file_default_mode` CHECK (`set_mode` IN ('single','multi')),
  CONSTRAINT `chk_file_default_single_position` CHECK (`set_mode` = 'multi' OR `srt` = 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `folders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(64) NOT NULL,
  `title` VARCHAR(128) NOT NULL,
  `owner` VARCHAR(64) NOT NULL,
  `_author` VARCHAR(128) NOT NULL,
  `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_folders_owner_name` (`owner`, `name`),
  KEY `ix_folders_owner` (`owner`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `folder_files` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `folder_id` BIGINT UNSIGNED NOT NULL,
  `file_id` BIGINT UNSIGNED NOT NULL,
  `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_folder_files_membership` (`folder_id`, `file_id`),
  KEY `ix_folder_files_file` (`file_id`),
  CONSTRAINT `fk_folder_files_folder` FOREIGN KEY (`folder_id`) REFERENCES `folders` (`id`) ON UPDATE RESTRICT ON DELETE CASCADE,
  CONSTRAINT `fk_folder_files_file` FOREIGN KEY (`file_id`) REFERENCES `file_meta` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

CREATE TABLE `file_meta` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(15) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nice_name` VARCHAR(256) NOT NULL,
  `type_group` CHAR(32) DEFAULT NULL,
  `caption` VARCHAR(255) DEFAULT NULL,
  `owner` VARCHAR(64) NOT NULL,
  `privacy` ENUM('public','private') NOT NULL DEFAULT 'public',
  `storage_driver` ENUM('local','s3','minio','gcs','onedrive','dropbox') NOT NULL DEFAULT 'local',
  `storage_bucket` VARCHAR(128) DEFAULT NULL,
  `_name` VARCHAR(250) NOT NULL,
  `_path` VARCHAR(255) NOT NULL,
  `_type` CHAR(95) NOT NULL,
  `_size` BIGINT UNSIGNED NOT NULL,
  `_checksum` CHAR(128) DEFAULT NULL,
  `_locked` TINYINT(1) NOT NULL DEFAULT 0,
  `_watermarked` TINYINT(1) NOT NULL DEFAULT 0,
  `_creator` VARCHAR(128) NOT NULL DEFAULT 'SYSTEM',
  `_updated` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  UNIQUE KEY `_name` (`_name`),
  KEY `owner` (`owner`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `file_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(15) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `file_id` BIGINT UNSIGNED NOT NULL,
  `token` CHAR(64) NOT NULL,
  `expires_at` DATETIME DEFAULT NULL,
  `max_downloads` INT UNSIGNED DEFAULT NULL,
  `download_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_by` VARCHAR(128) NOT NULL DEFAULT 'SYSTEM',
  `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  UNIQUE KEY `token` (`token`),
  KEY `file_id` (`file_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `file_default` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user` VARCHAR(64) NOT NULL,
  `set_key` VARCHAR(64) NOT NULL,
  `file_id` BIGINT UNSIGNED NOT NULL,
  `srt` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `_updated` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user` (`user`),
  KEY `file_id` (`file_id`),
  KEY `set_key` (`set_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `folders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(64) NOT NULL,
  `title` VARCHAR(128) NOT NULL,
  `owner` VARCHAR(64) NOT NULL,
  `_author` VARCHAR(128) NOT NULL,
  `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_folders_owner_name` (`owner`, `name`),
  KEY `owner` (`owner`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `folder_files` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `folder_id` INT UNSIGNED NOT NULL,
  `file_id` BIGINT UNSIGNED NOT NULL,
  `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_folder_file` (`folder_id`, `file_id`),
  KEY `folder_id` (`folder_id`),
  KEY `file_id` (`file_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- timefrontiers/php-file upgrade from v1.0.x to v1.1.0
-- MariaDB 10.4+ / MySQL 8.0+
--
-- Prerequisites:
--   * Take a verified backup.
--   * Put token issuance and file mutations into maintenance mode.
--   * Run preflight-v1.1.0.sql successfully.
--
-- DDL auto-commits on the supported engines. Every structural operation is
-- guarded through information_schema so this script can be resumed safely.

SET time_zone = '+00:00';

DELIMITER //
DROP PROCEDURE IF EXISTS `tf_file_v11_exec_if`//
CREATE PROCEDURE `tf_file_v11_exec_if`(IN should_execute BOOLEAN, IN ddl LONGTEXT)
BEGIN
  IF should_execute THEN
    SET @tf_file_v11_ddl = ddl;
    PREPARE tf_file_v11_statement FROM @tf_file_v11_ddl;
    EXECUTE tf_file_v11_statement;
    DEALLOCATE PREPARE tf_file_v11_statement;
  END IF;
END//
DELIMITER ;

-- Canonical object identity and recoverable lifecycle.
CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'file_meta' AND COLUMN_NAME = 'object_key'),
  'ALTER TABLE `file_meta` ADD COLUMN `object_key` VARCHAR(700) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL AFTER `_path`'
);
CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'file_meta' AND COLUMN_NAME = 'lifecycle_state'),
  'ALTER TABLE `file_meta` ADD COLUMN `lifecycle_state` ENUM(''pending_upload'',''active'',''deleting'',''cleanup_required'',''deleted'') NOT NULL DEFAULT ''active'' AFTER `_watermarked`'
);
CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'file_meta' AND COLUMN_NAME = 'cleanup_required_at'),
  'ALTER TABLE `file_meta` ADD COLUMN `cleanup_required_at` DATETIME NULL AFTER `lifecycle_state`, ADD COLUMN `cleanup_error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER `cleanup_required_at`, ADD COLUMN `cleanup_operation` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER `cleanup_error_code`, ADD COLUMN `cleanup_attempts` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `cleanup_operation`, ADD COLUMN `deleted_at` DATETIME NULL AFTER `cleanup_attempts`'
);

UPDATE `file_meta`
SET `_path` = TRIM(BOTH '/' FROM `_path`),
    `object_key` = CONCAT_WS('/', NULLIF(TRIM(BOTH '/' FROM `_path`), ''), `_name`)
WHERE `object_key` IS NULL OR `object_key` = '';

ALTER TABLE `file_meta`
  MODIFY `_path` VARCHAR(700) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  MODIFY `object_key` VARCHAR(700) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  MODIFY `storage_driver` ENUM('local','s3','minio') NOT NULL;

CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'file_meta' AND INDEX_NAME = 'uq_file_meta_driver_object'),
  'ALTER TABLE `file_meta` ADD UNIQUE KEY `uq_file_meta_driver_object` (`storage_driver`, `object_key`)'
);
CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'file_meta' AND INDEX_NAME = '_name' AND NON_UNIQUE = 0),
  'ALTER TABLE `file_meta` DROP INDEX `_name`'
);
CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'file_meta' AND INDEX_NAME = 'ix_file_meta_owner_state'),
  'ALTER TABLE `file_meta` ADD KEY `ix_file_meta_owner_state` (`owner`, `lifecycle_state`)'
);
CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'file_meta' AND INDEX_NAME = 'ix_file_meta_cleanup'),
  'ALTER TABLE `file_meta` ADD KEY `ix_file_meta_cleanup` (`lifecycle_state`, `cleanup_required_at`)'
);
CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 0 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'file_meta' AND CONSTRAINT_NAME = 'chk_file_meta_object_key'),
  'ALTER TABLE `file_meta` ADD CONSTRAINT `chk_file_meta_object_key` CHECK (`object_key` <> '''' AND LEFT(`object_key`, 1) <> ''/'' AND RIGHT(`object_key`, 1) <> ''/'' AND LOCATE(CHAR(92), `object_key`) = 0)'
);

-- Digest-only, rotatable, revocable tokens. All v1.0 bearer tokens are revoked
-- because the old CHAR(64) column may already have truncated signed values.
CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'file_tokens' AND COLUMN_NAME = 'token'),
  'ALTER TABLE `file_tokens` MODIFY `token` VARCHAR(255) NULL'
);
CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'file_tokens' AND COLUMN_NAME = 'token_digest'),
  'ALTER TABLE `file_tokens` ADD COLUMN `token_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER `file_id`, ADD COLUMN `token_key_id` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER `token_digest`, ADD COLUMN `revoked_at` DATETIME NULL AFTER `download_count`'
);

UPDATE `file_tokens`
SET `token_digest` = COALESCE(
      `token_digest`,
      CAST(SHA2(CONCAT('revoked-legacy:', `id`, ':', `code`), 256) AS CHAR CHARACTER SET ascii)
    ),
    `token_key_id` = COALESCE(
      `token_key_id`,
      CAST('revoked-legacy' AS CHAR CHARACTER SET ascii)
    ),
    `revoked_at` = COALESCE(`revoked_at`, UTC_TIMESTAMP())
WHERE `token_key_id` IS NULL OR `token_digest` IS NULL;

CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'file_tokens' AND COLUMN_NAME = 'token'),
  'UPDATE `file_tokens` SET `token` = NULL'
);
CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'file_tokens' AND INDEX_NAME = 'token'),
  'ALTER TABLE `file_tokens` DROP INDEX `token`'
);
CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'file_tokens' AND COLUMN_NAME = 'token'),
  'ALTER TABLE `file_tokens` DROP COLUMN `token`'
);

ALTER TABLE `file_tokens`
  MODIFY `token_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY `token_key_id` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'file_tokens' AND INDEX_NAME = 'uq_file_tokens_digest'),
  'ALTER TABLE `file_tokens` ADD UNIQUE KEY `uq_file_tokens_digest` (`token_key_id`, `token_digest`)'
);
CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'file_tokens' AND INDEX_NAME = 'ix_file_tokens_consume'),
  'ALTER TABLE `file_tokens` ADD KEY `ix_file_tokens_consume` (`token_key_id`, `token_digest`, `file_id`, `revoked_at`, `expires_at`)'
);
CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 0 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'file_tokens' AND CONSTRAINT_NAME = 'chk_file_tokens_limit'),
  'ALTER TABLE `file_tokens` ADD CONSTRAINT `chk_file_tokens_limit` CHECK (`max_downloads` IS NULL OR `max_downloads` > 0)'
);
CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 0 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'file_tokens' AND CONSTRAINT_NAME = 'chk_file_tokens_count'),
  'ALTER TABLE `file_tokens` ADD CONSTRAINT `chk_file_tokens_count` CHECK (`max_downloads` IS NULL OR `download_count` <= `max_downloads`)'
);

-- Normalize default-set identity and mode before applying uniqueness.
CREATE TABLE IF NOT EXISTS `file_default_sets` (
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

INSERT INTO `file_default_sets` (`user`, `set_key`, `mode`)
SELECT `user`, `set_key`, IF(COUNT(*) > 1, 'multi', 'single')
FROM `file_default`
WHERE `user` IS NOT NULL AND `set_key` IS NOT NULL
GROUP BY `user`, `set_key`
ON DUPLICATE KEY UPDATE `id` = `file_default_sets`.`id`;

CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'file_default' AND COLUMN_NAME = 'set_id'),
  'ALTER TABLE `file_default` ADD COLUMN `set_id` BIGINT UNSIGNED NULL AFTER `id`, ADD COLUMN `set_mode` VARCHAR(6) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER `set_id`'
);
UPDATE `file_default` d
INNER JOIN `file_default_sets` s ON s.`user` = d.`user` AND s.`set_key` = d.`set_key`
SET d.`set_id` = s.`id`, d.`set_mode` = s.`mode`
WHERE d.`user` IS NOT NULL AND d.`set_key` IS NOT NULL
  AND (d.`set_id` IS NULL OR d.`set_mode` IS NULL);

UPDATE `file_default` d
INNER JOIN `file_default_sets` s ON s.`id` = d.`set_id`
SET d.`srt` = 0
WHERE s.`mode` = 'single' AND d.`srt` <> 0;

ALTER TABLE `file_default`
  MODIFY `user` VARCHAR(64) NULL DEFAULT NULL,
  MODIFY `set_key` VARCHAR(64) NULL DEFAULT NULL,
  MODIFY `set_id` BIGINT UNSIGNED NOT NULL,
  MODIFY `set_mode` VARCHAR(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY `srt` INT UNSIGNED NOT NULL DEFAULT 0;

CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'file_default' AND INDEX_NAME = 'uq_file_default_position'),
  'ALTER TABLE `file_default` ADD UNIQUE KEY `uq_file_default_position` (`set_id`, `srt`)'
);
CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'file_default' AND INDEX_NAME = 'uq_file_default_file'),
  'ALTER TABLE `file_default` ADD UNIQUE KEY `uq_file_default_file` (`set_id`, `file_id`)'
);
CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'file_default' AND INDEX_NAME = 'ix_file_default_set_mode'),
  'ALTER TABLE `file_default` ADD KEY `ix_file_default_set_mode` (`set_id`, `set_mode`)'
);
CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 0 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'file_default' AND CONSTRAINT_NAME = 'chk_file_default_mode'),
  'ALTER TABLE `file_default` ADD CONSTRAINT `chk_file_default_mode` CHECK (`set_mode` IN (''single'',''multi''))'
);
CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 0 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'file_default' AND CONSTRAINT_NAME = 'chk_file_default_single_position'),
  'ALTER TABLE `file_default` ADD CONSTRAINT `chk_file_default_single_position` CHECK (`set_mode` = ''multi'' OR `srt` = 0)'
);

-- Referential integrity. Constraint names are checked before every addition.
CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 0 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'file_tokens' AND CONSTRAINT_NAME = 'fk_file_tokens_file'),
  'ALTER TABLE `file_tokens` ADD CONSTRAINT `fk_file_tokens_file` FOREIGN KEY (`file_id`) REFERENCES `file_meta` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
);
CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 0 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'file_default' AND CONSTRAINT_NAME = 'fk_file_default_set_mode'),
  'ALTER TABLE `file_default` ADD CONSTRAINT `fk_file_default_set_mode` FOREIGN KEY (`set_id`, `set_mode`) REFERENCES `file_default_sets` (`id`, `mode`) ON UPDATE RESTRICT ON DELETE CASCADE'
);
CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 0 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'file_default' AND CONSTRAINT_NAME = 'fk_file_default_file'),
  'ALTER TABLE `file_default` ADD CONSTRAINT `fk_file_default_file` FOREIGN KEY (`file_id`) REFERENCES `file_meta` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
);
CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'folder_files'
      AND INDEX_NAME IN ('uq_folder_files_membership', 'uq_folder_file')),
  'ALTER TABLE `folder_files` ADD UNIQUE KEY `uq_folder_files_membership` (`folder_id`, `file_id`)'
);
CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 0 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'folder_files' AND CONSTRAINT_NAME = 'fk_folder_files_folder'),
  'ALTER TABLE `folder_files` ADD CONSTRAINT `fk_folder_files_folder` FOREIGN KEY (`folder_id`) REFERENCES `folders` (`id`) ON UPDATE RESTRICT ON DELETE CASCADE'
);
CALL `tf_file_v11_exec_if`(
  (SELECT COUNT(*) = 0 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'folder_files' AND CONSTRAINT_NAME = 'fk_folder_files_file'),
  'ALTER TABLE `folder_files` ADD CONSTRAINT `fk_folder_files_file` FOREIGN KEY (`file_id`) REFERENCES `file_meta` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT'
);

DROP PROCEDURE `tf_file_v11_exec_if`;

-- The application may enable v1.1 token issuance only after this script has
-- completed and the new digest/consume indexes are present.

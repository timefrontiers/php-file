-- ============================================================
-- timefrontiers/php-file — Migration from linktude/php-file
-- Source schema : lnk_files (linktude)
-- Target schema : file     (timefrontiers)
-- Run this AFTER creating the new schema via install.sql
-- ============================================================
-- IMPORTANT BEFORE RUNNING:
--   1. Back up your database.
--   2. Set @source_db below to match your old linktude database name.
-- ============================================================

SET @source_db = 'lnk_files';   -- old linktude database name

START TRANSACTION;

-- ============================================================
-- §1  file_meta
--     • id char(16)      → becomes `code` VARCHAR(15)
--     • new BIGINT AUTO_INCREMENT `id` added (surrogate PK)
--     • owner char(128)  → owner VARCHAR(64) (kept as string — no mapping needed)
--     • privacy char(25) DEFAULT 'PUBLIC' → ENUM('public','private')
--     • _size int(11)    → BIGINT UNSIGNED
--     • new columns: storage_driver (default 'local'), storage_bucket
-- ============================================================

-- 1a. Drop the old primary key so we can rename the column
ALTER TABLE `lnk_files`.`file_meta`
  DROP PRIMARY KEY;

-- 1b. Rename id → code, widen type to VARCHAR(15)
ALTER TABLE `lnk_files`.`file_meta`
  CHANGE `id` `code` VARCHAR(15) NOT NULL;

-- 1c. Widen _size to BIGINT UNSIGNED
ALTER TABLE `lnk_files`.`file_meta`
  MODIFY `_size` BIGINT UNSIGNED NOT NULL DEFAULT 0;

-- 1d. Drop the UNIQUE on _name so we can rebuild properly after migration
ALTER TABLE `lnk_files`.`file_meta`
  DROP INDEX IF EXISTS `_name`;

-- 1e. Add new BIGINT surrogate primary key; AUTO_INCREMENT starts at 1
ALTER TABLE `lnk_files`.`file_meta`
  ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

-- MariaDB assigns AUTO_INCREMENT from max(id)+1 automatically after the above.
-- Force reset to 1 in case of quirks (safe: existing rows already have IDs assigned).
ALTER TABLE `lnk_files`.`file_meta` AUTO_INCREMENT = 1;

-- 1f. Add storage columns (default local — all existing files are local)
ALTER TABLE `lnk_files`.`file_meta`
  ADD COLUMN `storage_driver` ENUM('local','s3','minio','gcs','onedrive','dropbox')
    NOT NULL DEFAULT 'local' AFTER `privacy`,
  ADD COLUMN `storage_bucket` VARCHAR(128) DEFAULT NULL AFTER `storage_driver`;

-- 1g. Normalise privacy values to lowercase enum
UPDATE `lnk_files`.`file_meta`
  SET `privacy` = 'public'
  WHERE UPPER(`privacy`) = 'PUBLIC';

UPDATE `lnk_files`.`file_meta`
  SET `privacy` = 'private'
  WHERE UPPER(`privacy`) != 'PUBLIC';

-- 1h. Change privacy column to ENUM now that values are clean
ALTER TABLE `lnk_files`.`file_meta`
  MODIFY `privacy` ENUM('public','private') NOT NULL DEFAULT 'public';

-- 1i. Narrow owner from char(128) → VARCHAR(64), keep existing string values
ALTER TABLE `lnk_files`.`file_meta`
  MODIFY `owner` VARCHAR(64) NOT NULL;

-- 1j. Restore UNIQUE on _name and add index on owner
ALTER TABLE `lnk_files`.`file_meta`
  ADD UNIQUE KEY `_name` (`_name`),
  ADD KEY `owner` (`owner`);


-- ============================================================
-- §2  file_default
--     • user char(32)    → user VARCHAR(64) (string — no type change needed)
--     • file_id char(16) → BIGINT UNSIGNED (join on file_meta.code → id)
--     • set_key char(32) → VARCHAR(64)
-- ============================================================

-- 2a. Add a temp BIGINT column for the new file_id
ALTER TABLE `lnk_files`.`file_default`
  ADD COLUMN `file_id_new` BIGINT UNSIGNED DEFAULT NULL;

-- 2b. Populate file_id_new by matching old char(16) code → new BIGINT id
UPDATE `lnk_files`.`file_default` fd
  INNER JOIN `lnk_files`.`file_meta` fm ON fd.`file_id` = fm.`code`
  SET fd.`file_id_new` = fm.`id`;

-- 2c. Swap out the old file_id for the new BIGINT one
ALTER TABLE `lnk_files`.`file_default`
  DROP COLUMN `file_id`;

ALTER TABLE `lnk_files`.`file_default`
  CHANGE `file_id_new` `file_id` BIGINT UNSIGNED NOT NULL;

-- 2d. Widen user and set_key
ALTER TABLE `lnk_files`.`file_default`
  MODIFY `user`    VARCHAR(64) NOT NULL,
  MODIFY `set_key` VARCHAR(64) NOT NULL;

-- 2e. Add indexes
ALTER TABLE `lnk_files`.`file_default`
  ADD KEY `user`    (`user`),
  ADD KEY `file_id` (`file_id`),
  ADD KEY `set_key` (`set_key`);

ALTER TABLE `lnk_files`.`file_default` AUTO_INCREMENT = 1;


-- ============================================================
-- §3  folders & folder_files
--     • folders.owner char(16)    → VARCHAR(64) (kept as string)
--     • folders.name  char(28)    → VARCHAR(64)
--     • folders.title char(32)    → VARCHAR(128)
--     • folder_files.file_id char(16) → BIGINT UNSIGNED
-- ============================================================

-- 3a. Folders: widen columns, keep owner as string
ALTER TABLE `lnk_files`.`folders`
  MODIFY `owner`   VARCHAR(64)  NOT NULL,
  MODIFY `name`    VARCHAR(64)  NOT NULL,
  MODIFY `title`   VARCHAR(128) NOT NULL,
  MODIFY `_author` VARCHAR(128) NOT NULL,
  MODIFY `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP();

ALTER TABLE `lnk_files`.`folders`
  ADD KEY `owner` (`owner`);

-- 3b. folder_files: migrate file_id char(16) → BIGINT
ALTER TABLE `lnk_files`.`folder_files`
  ADD COLUMN `file_id_new` BIGINT UNSIGNED DEFAULT NULL;

UPDATE `lnk_files`.`folder_files` ff
  INNER JOIN `lnk_files`.`file_meta` fm ON ff.`file_id` = fm.`code`
  SET ff.`file_id_new` = fm.`id`;

ALTER TABLE `lnk_files`.`folder_files`
  DROP COLUMN `file_id`;

ALTER TABLE `lnk_files`.`folder_files`
  CHANGE `file_id_new` `file_id` BIGINT UNSIGNED NOT NULL;

ALTER TABLE `lnk_files`.`folder_files`
  MODIFY `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  ADD KEY `folder_id` (`folder_id`),
  ADD KEY `file_id`   (`file_id`);

ALTER TABLE `lnk_files`.`folders`      AUTO_INCREMENT = 1;
ALTER TABLE `lnk_files`.`folder_files` AUTO_INCREMENT = 1;


-- ============================================================
-- §4  Add file_tokens table (new — did not exist in old schema)
-- ============================================================

CREATE TABLE IF NOT EXISTS `lnk_files`.`file_tokens` (
  `id`              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `code`            VARCHAR(15)       NOT NULL,
  `file_id`         BIGINT UNSIGNED   NOT NULL,
  `token`           CHAR(64)          NOT NULL,
  `expires_at`      DATETIME          DEFAULT NULL,
  `max_downloads`   INT UNSIGNED      DEFAULT NULL,
  `download_count`  INT UNSIGNED      NOT NULL DEFAULT 0,
  `created_by`      VARCHAR(128)      NOT NULL DEFAULT 'SYSTEM',
  `_created`        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code`  (`code`),
  UNIQUE KEY `token` (`token`),
  KEY `file_id` (`file_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


COMMIT;

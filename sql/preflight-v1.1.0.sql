-- timefrontiers/php-file v1.1.0 upgrade preflight
-- Run against the selected file database before upgrade-v1.1.0.sql.
-- This script is read-only apart from its temporary stored procedure.

DELIMITER //
DROP PROCEDURE IF EXISTS `tf_file_v11_assert`//
CREATE PROCEDURE `tf_file_v11_assert`(IN violation_count BIGINT, IN failure_message VARCHAR(255))
BEGIN
  IF violation_count > 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = failure_message;
  END IF;
END//
DELIMITER ;

CALL `tf_file_v11_assert`(
  (SELECT COUNT(*) FROM `file_meta`
    WHERE `_path` REGEXP '[[:cntrl:]]'
       OR `_name` REGEXP '[[:cntrl:]]'
       OR LOCATE(CHAR(92), `_path`) > 0
       OR LOCATE(CHAR(92), `_name`) > 0
       OR LOCATE('/', `_name`) > 0
       OR LOCATE(':', `_path`) > 0
       OR LOCATE(':', `_name`) > 0
       OR `_path` LIKE '%//%'
       OR TRIM(`_path`) <> `_path`
       OR TRIM(`_name`) <> `_name`
       OR `_path` REGEXP '(^|/)[[:space:]]|[[:space:]](/|$)'
       OR CONCAT('/', TRIM(BOTH '/' FROM `_path`), '/') REGEXP '/(\\.|\\.\\.)/'
       OR `_name` IN ('.', '..')
       OR `_name` LIKE '/%'
       OR OCTET_LENGTH(CONCAT_WS('/', NULLIF(TRIM(BOTH '/' FROM `_path`), ''), `_name`)) > 700),
  'Preflight failed: unsafe legacy file paths or names must be remediated.'
);

CALL `tf_file_v11_assert`(
  (SELECT COUNT(*) FROM (
    SELECT `storage_driver`, CONCAT_WS('/', NULLIF(TRIM(BOTH '/' FROM `_path`), ''), `_name`) AS k
    FROM `file_meta`
    GROUP BY `storage_driver`, k HAVING COUNT(*) > 1
  ) duplicates),
  'Preflight failed: duplicate canonical object keys must be remediated.'
);

CALL `tf_file_v11_assert`(
  (SELECT COUNT(*) FROM `file_meta` WHERE `storage_driver` NOT IN ('local','s3','minio')),
  'Preflight failed: records using stub or unknown storage drivers must be migrated.'
);

CALL `tf_file_v11_assert`(
  (SELECT COUNT(*) FROM `file_tokens` t LEFT JOIN `file_meta` f ON f.`id` = t.`file_id` WHERE f.`id` IS NULL),
  'Preflight failed: orphan file token rows must be removed or repaired.'
);

CALL `tf_file_v11_assert`(
  (SELECT COUNT(*) FROM `file_tokens` WHERE `max_downloads` = 0 OR `download_count` > `max_downloads`),
  'Preflight failed: invalid token limits must be remediated.'
);

CALL `tf_file_v11_assert`(
  (SELECT COUNT(*) FROM `file_default` d LEFT JOIN `file_meta` f ON f.`id` = d.`file_id` WHERE f.`id` IS NULL),
  'Preflight failed: orphan default-file rows must be removed or repaired.'
);

CALL `tf_file_v11_assert`(
  (SELECT COUNT(*) FROM (
    SELECT `user`, `set_key`, `file_id` FROM `file_default`
    GROUP BY `user`, `set_key`, `file_id` HAVING COUNT(*) > 1
  ) duplicates),
  'Preflight failed: duplicate default-file memberships must be remediated.'
);

CALL `tf_file_v11_assert`(
  (SELECT COUNT(*) FROM (
    SELECT `user`, `set_key`, `srt` FROM `file_default`
    GROUP BY `user`, `set_key`, `srt` HAVING COUNT(*) > 1
  ) duplicates),
  'Preflight failed: duplicate default-file sort positions must be remediated.'
);

CALL `tf_file_v11_assert`(
  (SELECT COUNT(*) FROM `folder_files` x LEFT JOIN `folders` f ON f.`id` = x.`folder_id` WHERE f.`id` IS NULL),
  'Preflight failed: folder membership rows with missing folders must be remediated.'
);

CALL `tf_file_v11_assert`(
  (SELECT COUNT(*) FROM `folder_files` x LEFT JOIN `file_meta` f ON f.`id` = x.`file_id` WHERE f.`id` IS NULL),
  'Preflight failed: folder membership rows with missing files must be remediated.'
);

CALL `tf_file_v11_assert`(
  (SELECT COUNT(*) FROM (
    SELECT `folder_id`, `file_id` FROM `folder_files`
    GROUP BY `folder_id`, `file_id` HAVING COUNT(*) > 1
  ) duplicates),
  'Preflight failed: duplicate folder memberships must be remediated.'
);

SELECT COUNT(*) AS `legacy_tokens_to_be_revoked`
FROM `file_tokens`
WHERE EXISTS (
  SELECT 1 FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'file_tokens' AND COLUMN_NAME = 'token'
);

DROP PROCEDURE `tf_file_v11_assert`;

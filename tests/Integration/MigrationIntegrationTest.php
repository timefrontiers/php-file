<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Tests\Integration;

final class MigrationIntegrationTest extends DatabaseIntegrationTestCase
{
  public function testLegacyTokenAndRelationshipMigrationIsIdempotent(): void
  {
    $pdo = $this->integrationPdo();
    $this->resetPackageTables($pdo);
    $root = dirname(__DIR__, 2);
    $this->runSqlScript($pdo, dirname(__DIR__) . '/Fixtures/install-v1.0.7.sql');

    $pdo->exec("INSERT INTO `file_meta`
      (`code`,`nice_name`,`type_group`,`owner`,`privacy`,`storage_driver`,`_name`,`_path`,`_type`,`_size`)
      VALUES ('583111111111111','Legacy','text','owner','private','local','legacy.txt','Files/owner','text/plain',6)");
    $pdo->exec("INSERT INTO `file_tokens`
      (`code`,`file_id`,`token`,`expires_at`,`max_downloads`,`download_count`)
      VALUES ('584111111111111',1,'" . str_repeat('x', 64) . "',UTC_TIMESTAMP() + INTERVAL 1 DAY,1,0)");
    $pdo->exec("INSERT INTO `file_default` (`user`,`set_key`,`file_id`,`srt`)
      VALUES ('owner','avatar',1,0)");
    $pdo->exec("INSERT INTO `folders` (`name`,`title`,`owner`,`_author`)
      VALUES ('docs','Documents','owner','TEST')");
    $pdo->exec("INSERT INTO `folder_files` (`folder_id`,`file_id`) VALUES (1,1)");

    $this->runSqlScript($pdo, $root . '/sql/preflight-v1.1.0.sql');
    $this->runSqlScript($pdo, $root . '/sql/upgrade-v1.1.0.sql');

    self::assertSame('Files/owner/legacy.txt', $this->column($pdo,
      'SELECT `object_key` FROM `file_meta` WHERE `id` = 1'
    ));
    $token = $this->row($pdo,
      'SELECT `token_digest`,`token_key_id`,`revoked_at` FROM `file_tokens` WHERE `id` = 1'
    );
    self::assertSame(64, strlen((string)$token['token_digest']));
    self::assertSame('revoked-legacy', $token['token_key_id']);
    self::assertNotNull($token['revoked_at']);
    self::assertSame(0, (int)$this->column($pdo,
      "SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'file_tokens' AND COLUMN_NAME = 'token'"
    ));
    self::assertSame('single', $this->column($pdo,
      'SELECT `mode` FROM `file_default_sets` WHERE `user` = \'owner\' AND `set_key` = \'avatar\''
    ));
    self::assertSame(1, (int)$this->column($pdo,
      'SELECT COUNT(*) FROM `folder_files` WHERE `folder_id` = 1 AND `file_id` = 1'
    ));

    // A resumable migration must be safe after all constraints already exist.
    $this->runSqlScript($pdo, $root . '/sql/preflight-v1.1.0.sql');
    $this->runSqlScript($pdo, $root . '/sql/upgrade-v1.1.0.sql');
    self::assertSame(1, (int)$this->column($pdo, 'SELECT COUNT(*) FROM `file_meta`'));
    self::assertSame(1, (int)$this->column($pdo, 'SELECT COUNT(*) FROM `file_tokens`'));
    self::assertSame(1, (int)$this->column($pdo, 'SELECT COUNT(*) FROM `file_default`'));
  }

  private function column(\PDO $pdo, string $sql): mixed
  {
    $statement = $pdo->query($sql);
    if ($statement === false) throw new \RuntimeException('Migration assertion query failed.');
    return $statement->fetchColumn();
  }

  /** @return array<string, mixed> */
  private function row(\PDO $pdo, string $sql): array
  {
    $statement = $pdo->query($sql);
    if ($statement === false) throw new \RuntimeException('Migration assertion query failed.');
    $row = $statement->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new \RuntimeException('Migration assertion row is missing.');
    return $row;
  }
}

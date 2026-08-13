<?php
declare(strict_types=1);

namespace TimeFrontiers\File;

use TimeFrontiers\File\Database\DatabaseGateway;
use TimeFrontiers\SQLDatabase;

/** Transactional single- and multi-default registry. */
/** @phpstan-consistent-constructor */
class FileDefault
{
  use \TimeFrontiers\Helper\DatabaseObject;

  protected static string $_primary_key = 'id';
  protected static string $_db_name = '';
  protected static string $_table_name = 'file_default';
  /** @var list<string> */
  protected static array $_db_fields = [
    'id', 'set_id', 'set_mode', 'file_id', 'srt', '_updated',
  ];

  public ?int $id = null;
  public ?int $set_id = null;
  public string $set_mode = 'single';
  public string $user = '';
  public ?string $set_key = null;
  public ?int $file_id = null;
  public int $srt = 0;
  public ?string $_updated = null;

  public function __construct(SQLDatabase $conn)
  {
    FileConfig::requireConfigured();
    static::$_db_name = (string)FileConfig::get('db_name', 'file');
    $this->setConnection($conn);
    static::useConnection($conn);
  }

  public static function setup(SQLDatabase $conn): void
  {
    static::$_db_name = (string)FileConfig::get('db_name', 'file');
    static::useConnection($conn);
  }

  public static function set(
    SQLDatabase $conn,
    string $user,
    string $setKey,
    int $fileId,
    bool $multiSet = false,
    int $srt = 0
  ): static|false {
    if ($user === '' || $setKey === '' || $fileId < 1 || $srt < 0) {
      throw new \InvalidArgumentException('Default file identifiers and sort positions are invalid.');
    }
    $db = (string)FileConfig::get('db_name', 'file');
    $mode = $multiSet ? 'multi' : 'single';

    try {
      /** @var static $result */
      $result = $conn->transaction(function (SQLDatabase $conn) use ($db, $user, $setKey, $fileId, $mode, $srt): static {
        $file = DatabaseGateway::fetchOne(
          $conn,
          "SELECT `id` FROM `{$db}`.`file_meta`
            WHERE `id` = ? AND `lifecycle_state` = 'active' FOR UPDATE",
          [$fileId]
        );
        if (!is_array($file)) {
          throw new \RuntimeException('Defaults can reference only a persisted active file.');
        }
        $set = DatabaseGateway::fetchOne($conn,
          "SELECT * FROM `{$db}`.`file_default_sets` WHERE `user` = ? AND `set_key` = ? FOR UPDATE",
          [$user, $setKey]
        );
        if (!is_array($set)) {
          if (DatabaseGateway::execute($conn,
            "INSERT INTO `{$db}`.`file_default_sets` (`user`, `set_key`, `mode`) VALUES (?, ?, ?)",
            [$user, $setKey, $mode]
          ) === false || $conn->affectedRows() !== 1) {
            throw new \RuntimeException('The default-set row was not created exactly once.');
          }
          $setId = DatabaseGateway::insertId($conn);
        } else {
          $setId = (int)$set['id'];
          if (($set['mode'] ?? null) !== $mode) {
            $priorEntries = DatabaseGateway::fetchAll(
              $conn,
              "SELECT `file_id`, `srt` FROM `{$db}`.`file_default`
                WHERE `set_id` = ? ORDER BY `srt` ASC, `id` ASC FOR UPDATE",
              [$setId]
            );
            if (!is_array($priorEntries)) {
              throw new \RuntimeException('The old default-set entries could not be locked.');
            }
            if (DatabaseGateway::execute($conn, "DELETE FROM `{$db}`.`file_default` WHERE `set_id` = ?", [$setId]) === false
              || $conn->affectedRows() !== count($priorEntries)
            ) {
              throw new \RuntimeException('The old default-set entries were not cleared exactly.');
            }
            if (DatabaseGateway::execute($conn,
              "UPDATE `{$db}`.`file_default_sets` SET `mode` = ? WHERE `id` = ?",
              [$mode, $setId]
            ) === false || $conn->affectedRows() !== 1) {
              throw new \RuntimeException('The default-set mode was not changed exactly once.');
            }
            if ($mode === 'multi') {
              foreach ($priorEntries as $priorEntry) {
                if (!DatabaseGateway::executeExactly(
                  $conn,
                  "INSERT INTO `{$db}`.`file_default` (`set_id`, `set_mode`, `file_id`, `srt`)
                    VALUES (?, 'multi', ?, ?)",
                  [$setId, (int)$priorEntry['file_id'], (int)$priorEntry['srt']],
                  1
                )) {
                  throw new \RuntimeException('A prior default-set entry could not be restored after mode change.');
                }
              }
            }
          }
        }

        if ($mode === 'single') {
          $existing = self::countEntries($conn, $db, $setId);
          if (DatabaseGateway::execute($conn, "DELETE FROM `{$db}`.`file_default` WHERE `set_id` = ?", [$setId]) === false
            || $conn->affectedRows() !== $existing
          ) {
            throw new \RuntimeException('The prior single default was not replaced exactly.');
          }
          $position = 0;
        } else {
          $duplicate = DatabaseGateway::fetchOne($conn,
            "SELECT `id` FROM `{$db}`.`file_default` WHERE `set_id` = ? AND `file_id` = ? LIMIT 1",
            [$setId, $fileId]
          );
          if (is_array($duplicate)) {
            $row = self::fetchJoinedById($conn, $db, (int)$duplicate['id']);
            return static::_instantiateFromRow($row, $conn);
          }
          if ($srt > 0) {
            $position = $srt;
          } else {
            $row = DatabaseGateway::fetchOne($conn,
              "SELECT COALESCE(MAX(`srt`), -1) + 1 AS `next_srt` FROM `{$db}`.`file_default` WHERE `set_id` = ?",
              [$setId]
            );
            $position = (int)($row['next_srt'] ?? 0);
          }
        }

        if (DatabaseGateway::execute($conn,
          "INSERT INTO `{$db}`.`file_default` (`set_id`, `set_mode`, `file_id`, `srt`) VALUES (?, ?, ?, ?)",
          [$setId, $mode, $fileId, $position]
        ) === false || $conn->affectedRows() !== 1) {
          throw new \RuntimeException('The default entry was not created exactly once.');
        }
        $row = self::fetchJoinedById($conn, $db, DatabaseGateway::insertId($conn));
        return static::_instantiateFromRow($row, $conn);
      });
      return $result;
    } catch (\Throwable) {
      return false;
    }
  }

  public static function get(SQLDatabase $conn, string $user, string $setKey): static|false
  {
    $rows = self::fetchJoined($conn, $user, $setKey, limit: 1);
    return isset($rows[0]) ? static::_instantiateFromRow($rows[0], $conn) : false;
  }

  /** @return list<static> */
  public static function getAll(SQLDatabase $conn, string $user, string $setKey): array
  {
    return array_map(
      static fn(array $row): static => static::_instantiateFromRow($row, $conn),
      self::fetchJoined($conn, $user, $setKey)
    );
  }

  public static function remove(SQLDatabase $conn, string $user, string $setKey, int $fileId): bool
  {
    $db = (string)FileConfig::get('db_name', 'file');
    $result = DatabaseGateway::execute($conn,
      "DELETE d FROM `{$db}`.`file_default` d
        INNER JOIN `{$db}`.`file_default_sets` s ON s.`id` = d.`set_id`
        WHERE s.`user` = ? AND s.`set_key` = ? AND d.`file_id` = ?",
      [$user, $setKey, $fileId]
    );
    return $result !== false && $conn->affectedRows() === 1;
  }

  public static function clearSet(SQLDatabase $conn, string $user, string $setKey): bool
  {
    $db = (string)FileConfig::get('db_name', 'file');
    try {
      return (bool)$conn->transaction(function (SQLDatabase $conn) use ($db, $user, $setKey): bool {
        $set = DatabaseGateway::fetchOne($conn,
          "SELECT `id` FROM `{$db}`.`file_default_sets` WHERE `user` = ? AND `set_key` = ? FOR UPDATE",
          [$user, $setKey]
        );
        if (!is_array($set)) {
          return true;
        }
        $setId = (int)$set['id'];
        $expected = self::countEntries($conn, $db, $setId);
        return DatabaseGateway::execute($conn, "DELETE FROM `{$db}`.`file_default` WHERE `set_id` = ?", [$setId]) !== false
          && $conn->affectedRows() === $expected;
      });
    } catch (\Throwable) {
      return false;
    }
  }

  public function save(): bool
  {
    throw new \LogicException('Use FileDefault::set(); default records cannot be saved directly.');
  }

  public function delete(): bool
  {
    if ($this->id === null) return false;
    $db = (string)FileConfig::get('db_name', 'file');
    $result = DatabaseGateway::execute(
      $this->conn(),
      "DELETE FROM `{$db}`.`file_default` WHERE `id` = ?",
      [$this->id]
    );
    return $result !== false && $this->conn()->affectedRows() === 1;
  }

  public static function isInUse(int $fileId, ?SQLDatabase $conn = null): bool
  {
    $conn ??= static::_getStaticConnection();
    $db = (string)FileConfig::get('db_name', 'file');
    $row = DatabaseGateway::fetchOne($conn, "SELECT 1 FROM `{$db}`.`file_default` WHERE `file_id` = ? LIMIT 1", [$fileId]);
    return is_array($row);
  }

  /** @param array<string, mixed> $row */
  public static function _instantiateFromRow(array $row, ?SQLDatabase $conn = null): static
  {
    $conn ??= static::_getStaticConnection();
    $instance = new static($conn);
    foreach (['id', 'set_id', 'set_mode', 'user', 'set_key', 'file_id', 'srt', '_updated'] as $field) {
      if (!array_key_exists($field, $row)) continue;
      $instance->$field = match ($field) {
        'id', 'set_id', 'file_id' => $row[$field] === null ? null : (int)$row[$field],
        'srt' => (int)$row[$field],
        default => $row[$field],
      };
    }
    $instance->setConnection($conn);
    return $instance;
  }

  /** @return list<array<string, mixed>> */
  private static function fetchJoined(SQLDatabase $conn, string $user, string $setKey, ?int $limit = null): array
  {
    $db = (string)FileConfig::get('db_name', 'file');
    $sql = "SELECT d.*, s.`user`, s.`set_key`, s.`mode` AS `set_mode`
      FROM `{$db}`.`file_default` d
      INNER JOIN `{$db}`.`file_default_sets` s ON s.`id` = d.`set_id`
      WHERE s.`user` = ? AND s.`set_key` = ? ORDER BY d.`srt` ASC, d.`id` ASC";
    if ($limit !== null) $sql .= ' LIMIT ' . $limit;
    $rows = DatabaseGateway::fetchAll($conn, $sql, [$user, $setKey]);
    return is_array($rows) ? array_values($rows) : [];
  }

  /** @return array<string, mixed> */
  private static function fetchJoinedById(SQLDatabase $conn, string $db, int $id): array
  {
    $row = DatabaseGateway::fetchOne($conn,
      "SELECT d.*, s.`user`, s.`set_key`, s.`mode` AS `set_mode`
        FROM `{$db}`.`file_default` d
        INNER JOIN `{$db}`.`file_default_sets` s ON s.`id` = d.`set_id`
        WHERE d.`id` = ? LIMIT 1",
      [$id]
    );
    if (!is_array($row)) throw new \RuntimeException('The persisted default entry could not be reloaded.');
    return $row;
  }

  private static function countEntries(SQLDatabase $conn, string $db, int $setId): int
  {
    $row = DatabaseGateway::fetchOne($conn, "SELECT COUNT(*) AS `c` FROM `{$db}`.`file_default` WHERE `set_id` = ?", [$setId]);
    return (int)($row['c'] ?? 0);
  }
}

<?php
declare(strict_types=1);

namespace TimeFrontiers\File;

use TimeFrontiers\File\Database\DatabaseGateway;
use TimeFrontiers\SQLDatabase;

/** @phpstan-consistent-constructor */
class FolderFile
{
  use \TimeFrontiers\Helper\DatabaseObject;

  protected static string $_primary_key = 'id';
  protected static string $_db_name = '';
  protected static string $_table_name = 'folder_files';
  /** @var list<string> */
  protected static array $_db_fields = ['id', 'folder_id', 'file_id', '_created'];

  public ?int $id = null;
  public ?int $folder_id = null;
  public ?int $file_id = null;
  public ?string $_created = null;

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

  public static function add(SQLDatabase $conn, int $folderId, int $fileId): static|false
  {
    if ($folderId < 1 || $fileId < 1) throw new \InvalidArgumentException('Folder and file IDs must be positive.');
    $db = (string)FileConfig::get('db_name', 'file');
    try {
      /** @var static $result */
      $result = $conn->transaction(function (SQLDatabase $conn) use ($db, $folderId, $fileId): static {
        $folder = DatabaseGateway::fetchOne(
          $conn,
          "SELECT `id` FROM `{$db}`.`folders` WHERE `id` = ? FOR UPDATE",
          [$folderId]
        );
        $file = DatabaseGateway::fetchOne(
          $conn,
          "SELECT `id` FROM `{$db}`.`file_meta`
            WHERE `id` = ? AND `lifecycle_state` = 'active' FOR UPDATE",
          [$fileId]
        );
        if (!is_array($folder) || !is_array($file)) {
          throw new \RuntimeException('Folder membership requires an existing folder and active file.');
        }
        $row = DatabaseGateway::fetchOne(
          $conn,
          "SELECT * FROM `{$db}`.`folder_files`
            WHERE `folder_id` = ? AND `file_id` = ? LIMIT 1 FOR UPDATE",
          [$folderId, $fileId]
        );
        if (is_array($row)) {
          return static::_instantiateFromRow($row, $conn);
        }
        if (DatabaseGateway::execute(
          $conn,
          "INSERT INTO `{$db}`.`folder_files` (`folder_id`, `file_id`) VALUES (?, ?)",
          [$folderId, $fileId]
        ) === false || $conn->affectedRows() !== 1) {
          throw new \RuntimeException('Folder membership was not created exactly once.');
        }
        $row = DatabaseGateway::fetchOne(
          $conn,
          "SELECT * FROM `{$db}`.`folder_files` WHERE `id` = ?",
          [DatabaseGateway::insertId($conn)]
        );
        if (!is_array($row)) {
          throw new \RuntimeException('The persisted folder membership could not be reloaded.');
        }
        return static::_instantiateFromRow($row, $conn);
      });
      return $result;
    } catch (\Throwable) {
      return false;
    }
  }

  public static function remove(SQLDatabase $conn, int $folderId, int $fileId): bool
  {
    $db = (string)FileConfig::get('db_name', 'file');
    $result = DatabaseGateway::execute($conn,
      "DELETE FROM `{$db}`.`folder_files` WHERE `folder_id` = ? AND `file_id` = ?",
      [$folderId, $fileId]
    );
    return $result !== false && $conn->affectedRows() === 1;
  }

  public static function clearFolder(SQLDatabase $conn, int $folderId): bool
  {
    return self::deleteAllMatching($conn, 'folder_id', $folderId);
  }

  public static function removeFile(SQLDatabase $conn, int $fileId): bool
  {
    return self::deleteAllMatching($conn, 'file_id', $fileId);
  }

  public function save(): bool
  {
    throw new \LogicException('Use FolderFile::add(); folder memberships cannot be saved directly.');
  }

  public function delete(): bool
  {
    if ($this->id === null) return false;
    $db = (string)FileConfig::get('db_name', 'file');
    $result = DatabaseGateway::execute(
      $this->conn(),
      "DELETE FROM `{$db}`.`folder_files` WHERE `id` = ?",
      [$this->id]
    );
    return $result !== false && $this->conn()->affectedRows() === 1;
  }

  /** @return list<static> */
  public static function forFolder(SQLDatabase $conn, int $folderId): array
  {
    $db = (string)FileConfig::get('db_name', 'file');
    $rows = DatabaseGateway::fetchAll($conn,
      "SELECT * FROM `{$db}`.`folder_files` WHERE `folder_id` = ? ORDER BY `_created` ASC, `id` ASC",
      [$folderId]
    );
    if (!is_array($rows)) return [];
    return array_values(array_map(static fn(array $row): static => static::_instantiateFromRow($row, $conn), $rows));
  }

  /** @param array<string, mixed> $row */
  public static function _instantiateFromRow(array $row, ?SQLDatabase $conn = null): static
  {
    $conn ??= static::_getStaticConnection();
    $instance = new static($conn);
    foreach (static::$_db_fields as $field) {
      if (!array_key_exists($field, $row)) continue;
      $instance->$field = in_array($field, ['id', 'folder_id', 'file_id'], true)
        ? ($row[$field] === null ? null : (int)$row[$field])
        : $row[$field];
    }
    $instance->setConnection($conn);
    return $instance;
  }

  private static function deleteAllMatching(SQLDatabase $conn, string $column, int $value): bool
  {
    $db = (string)FileConfig::get('db_name', 'file');
    try {
      return (bool)$conn->transaction(function (SQLDatabase $conn) use ($db, $column, $value): bool {
        $row = DatabaseGateway::fetchOne($conn,
          "SELECT COUNT(*) AS `c` FROM `{$db}`.`folder_files` WHERE `{$column}` = ? FOR UPDATE",
          [$value]
        );
        $expected = (int)($row['c'] ?? 0);
        return DatabaseGateway::execute($conn, "DELETE FROM `{$db}`.`folder_files` WHERE `{$column}` = ?", [$value]) !== false
          && $conn->affectedRows() === $expected;
      });
    } catch (\Throwable) {
      return false;
    }
  }
}

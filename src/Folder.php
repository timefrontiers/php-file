<?php
declare(strict_types=1);

namespace TimeFrontiers\File;

use TimeFrontiers\File\Database\DatabaseGateway;
use TimeFrontiers\SQLDatabase;

/** @phpstan-consistent-constructor */
class Folder
{
  use \TimeFrontiers\Helper\DatabaseObject {
    _create as private _databaseObjectCreate;
  }

  protected static string $_primary_key = 'id';
  protected static string $_db_name = '';
  protected static string $_table_name = 'folders';
  /** @var list<string> */
  protected static array $_db_fields = ['id', 'name', 'title', 'owner', '_author', '_created'];

  public ?int $id = null;
  public string $name = '';
  public string $title = '';
  public string $owner = '';
  public string $_author = 'SYSTEM';
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

  public function create(): bool
  {
    return $this->_create();
  }

  public function update(): bool
  {
    return $this->id !== null && $this->_update();
  }

  public function save(): bool
  {
    return $this->id === null ? $this->create() : $this->update();
  }

  public function delete(): bool
  {
    return $this->destroy();
  }

  public function destroy(): bool
  {
    if ($this->id === null) return false;
    $db = static::$_db_name;
    try {
      return (bool)$this->conn()->transaction(function (SQLDatabase $conn) use ($db): bool {
        $folder = DatabaseGateway::fetchOne(
          $conn,
          "SELECT `id` FROM `{$db}`.`folders` WHERE `id` = ? FOR UPDATE",
          [$this->id]
        );
        if (!is_array($folder)) return false;
        $rows = DatabaseGateway::fetchAll($conn,
          "SELECT `id` FROM `{$db}`.`folder_files` WHERE `folder_id` = ? FOR UPDATE",
          [$this->id]
        );
        $expected = is_array($rows) ? count($rows) : 0;
        if (DatabaseGateway::execute($conn, "DELETE FROM `{$db}`.`folder_files` WHERE `folder_id` = ?", [$this->id]) === false
          || $conn->affectedRows() !== $expected
        ) return false;
        return DatabaseGateway::execute($conn, "DELETE FROM `{$db}`.`folders` WHERE `id` = ?", [$this->id]) !== false
          && $conn->affectedRows() === 1;
      });
    } catch (\Throwable) {
      return false;
    }
  }

  /** @return list<static> */
  public static function forOwner(SQLDatabase $conn, string $owner): array
  {
    $db = (string)FileConfig::get('db_name', 'file');
    $rows = DatabaseGateway::fetchAll($conn,
      "SELECT * FROM `{$db}`.`folders` WHERE `owner` = ? ORDER BY `title` ASC, `id` ASC",
      [$owner]
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
      $instance->$field = $field === 'id' ? ($row[$field] === null ? null : (int)$row[$field]) : $row[$field];
    }
    $instance->setConnection($conn);
    return $instance;
  }

  /** Normalize PDO's numeric-string insert ID for the declared integer key. */
  protected function _create(): bool
  {
    try {
      return $this->_databaseObjectCreate();
    } catch (\TypeError $exception) {
      $id = DatabaseGateway::insertId($this->conn());
      if ($this->id === null && $id > 0 && str_contains($exception->getMessage(), '::$id')) {
        $this->id = $id;
        return true;
      }
      throw $exception;
    }
  }
}

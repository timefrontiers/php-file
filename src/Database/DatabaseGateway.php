<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Database;

use TimeFrontiers\SQLDatabase;

/** Typed access to operations delegated by the SQLDatabase facade via __call(). */
final class DatabaseGateway
{
  /**
   * @param list<mixed> $params
   * @return array<string, mixed>|false
   */
  public static function fetchOne(SQLDatabase $connection, string $sql, array $params = []): array|false
  {
    $row = $connection->getInstance()->fetchOne($sql, $params);
    return is_array($row) ? $row : false;
  }

  /**
   * @param list<mixed> $params
   * @return list<array<string, mixed>>|false
   */
  public static function fetchAll(SQLDatabase $connection, string $sql, array $params = []): array|false
  {
    $rows = $connection->getInstance()->fetchAll($sql, $params);
    return is_array($rows) ? array_values($rows) : false;
  }

  /** @param list<mixed> $params */
  public static function execute(SQLDatabase $connection, string $sql, array $params = []): bool
  {
    return $connection->getInstance()->execute($sql, $params) !== false;
  }

  /** @param list<mixed> $params */
  public static function executeExactly(
    SQLDatabase $connection,
    string $sql,
    array $params,
    int $expectedRows
  ): bool {
    $driver = $connection->getInstance();
    return $driver->execute($sql, $params) !== false && $driver->affectedRows() === $expectedRows;
  }

  public static function insertId(SQLDatabase $connection): int
  {
    return (int)$connection->getInstance()->insertId();
  }
}

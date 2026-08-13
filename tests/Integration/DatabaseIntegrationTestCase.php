<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Tests\Integration;

use TimeFrontiers\File\Drivers\StorageDriverFactoryInterface;
use TimeFrontiers\File\FileConfig;
use TimeFrontiers\File\Tests\TestCase;
use TimeFrontiers\SQLDatabase;

abstract class DatabaseIntegrationTestCase extends TestCase
{
  /** @return iterable<string, array{string}> */
  public static function adapters(): iterable
  {
    yield 'mysqli' => ['mysqli'];
    yield 'pdo-mysql' => ['pdo'];
  }

  /** @return array{connection:SQLDatabase,root:string} */
  protected function environment(string $adapter, ?StorageDriverFactoryInterface $factory = null): array
  {
    $settings = $this->integrationSettings();
    $host = $settings['host'];
    $user = $settings['user'];
    $password = $settings['password'];
    $database = $settings['database'];
    $port = $settings['port'];

    $this->installFreshSchema($host, $port, $database, $user, $password);
    $connection = $adapter === 'pdo'
      ? SQLDatabase::pdo('mysql', $host, $port, $database, $user, $password)
      : new SQLDatabase($host, $user, $password, $database, true, (string)$port);
    $root = $this->temporaryDirectory();
    FileConfig::configure([
      'db_name' => $database,
      'path_prefix' => 'Files',
      'service_url' => 'https://files.example.test',
      'token_enabled' => true,
      'token_keys' => [
        'retired' => str_repeat('r', 32),
        'current' => str_repeat('c', 32),
      ],
      'active_token_key_id' => 'current',
    ], ['local' => ['upload_path' => $root]], $factory);
    return ['connection' => $connection, 'root' => $root];
  }

  protected function integrationPdo(): \PDO
  {
    $settings = $this->integrationSettings();
    try {
      return new \PDO(
        "mysql:host={$settings['host']};port={$settings['port']};dbname={$settings['database']};charset=utf8mb4",
        $settings['user'],
        $settings['password'],
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
      );
    } catch (\Throwable $exception) {
      self::markTestSkipped('The configured integration database is unavailable: ' . $exception->getMessage());
    }
  }

  protected function resetPackageTables(\PDO $pdo): void
  {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['folder_files', 'folders', 'file_default', 'file_default_sets', 'file_tokens', 'file_meta'] as $table) {
      $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
  }

  protected function runSqlScript(\PDO $pdo, string $path): void
  {
    $sql = file_get_contents($path);
    if ($sql === false) throw new \RuntimeException("SQL script is unavailable: {$path}");
    $delimiter = ';';
    $statement = '';
    foreach (preg_split('/\R/', $sql) ?: [] as $line) {
      if (trim($statement) === '' && (trim($line) === '' || str_starts_with(ltrim($line), '--'))) {
        continue;
      }
      if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $matches) === 1) {
        $delimiter = $matches[1];
        continue;
      }
      $statement .= $line . "\n";
      $trimmed = rtrim($statement);
      if (!str_ends_with($trimmed, $delimiter)) continue;
      $statementToRun = trim(substr($trimmed, 0, -strlen($delimiter)));
      if ($statementToRun !== '') {
        $result = $pdo->query($statementToRun);
        if ($result !== false) {
          do {
            if ($result->columnCount() > 0) $result->fetchAll();
          } while ($result->nextRowset());
          $result->closeCursor();
        }
      }
      $statement = '';
    }
    if (trim($statement) !== '') {
      throw new \RuntimeException("SQL script ended before its {$delimiter} delimiter: {$path}");
    }
  }

  /** @return array{host:string,user:string,password:string,database:string,port:int} */
  private function integrationSettings(): array
  {
    $host = getenv('TF_FILE_TEST_HOST') ?: '';
    $user = getenv('TF_FILE_TEST_USER') ?: '';
    $password = getenv('TF_FILE_TEST_PASSWORD') ?: '';
    $database = getenv('TF_FILE_TEST_DATABASE') ?: '';
    $port = (int)(getenv('TF_FILE_TEST_PORT') ?: 3306);
    if ($host === '' || $user === '' || $database === '') {
      self::markTestSkipped('Set TF_FILE_TEST_HOST, USER, PASSWORD, and DATABASE to run database integration tests.');
    }
    if (preg_match('/(?:_test|_testing)$/D', $database) !== 1) {
      self::fail('TF_FILE_TEST_DATABASE must end in _test or _testing because its package tables are rebuilt.');
    }
    return compact('host', 'user', 'password', 'database', 'port');
  }

  private function installFreshSchema(
    string $host,
    int $port,
    string $database,
    string $user,
    string $password
  ): void {
    try {
      $pdo = new \PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
      );
    } catch (\Throwable $exception) {
      self::markTestSkipped('The configured integration database is unavailable: ' . $exception->getMessage());
    }
    $this->resetPackageTables($pdo);
    $this->runSqlScript($pdo, dirname(__DIR__, 2) . '/sql/install.sql');
  }
}

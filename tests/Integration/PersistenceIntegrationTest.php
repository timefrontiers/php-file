<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use TimeFrontiers\File\Database\DatabaseGateway;
use TimeFrontiers\File\Drivers\StorageDriverFactory;
use TimeFrontiers\File\File;
use TimeFrontiers\File\FileDefault;
use TimeFrontiers\File\FileToken;
use TimeFrontiers\File\Folder;
use TimeFrontiers\File\FolderFile;
use TimeFrontiers\File\Tests\Fakes\InMemoryStorageDriver;
use TimeFrontiers\SQLDatabase;

final class PersistenceIntegrationTest extends DatabaseIntegrationTestCase
{
  #[DataProvider('adapters')]
  public function testTokenIsDigestOnlyFileBoundAndAtomicallyExhausted(string $adapter): void
  {
    ['connection' => $connection, 'root' => $root] = $this->environment($adapter);
    $first = $this->localFile($connection, $root, 'first.txt', 'first');
    $second = $this->localFile($connection, $root, 'second.txt', 'second');
    $bearer = $first->createToken('+1 hour', 1, 'TEST');

    self::assertFalse(FileToken::consume($bearer, (int)$second->id, $connection));
    self::assertTrue(FileToken::consume($bearer, (int)$first->id, $connection));
    self::assertFalse(FileToken::consume($bearer, (int)$first->id, $connection));

    $row = DatabaseGateway::fetchOne(
      $connection,
      'SELECT `token_digest`, `token_key_id` FROM `file_tokens` WHERE `file_id` = ?',
      [$first->id]
    );
    self::assertIsArray($row);
    self::assertSame(64, strlen((string)$row['token_digest']));
    self::assertSame('current', $row['token_key_id']);
    self::assertStringNotContainsString($bearer, implode('|', $row));
    $legacyColumn = DatabaseGateway::fetchOne(
      $connection,
      "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'file_tokens' AND COLUMN_NAME = 'token'"
    );
    self::assertFalse($legacyColumn);
  }

  #[DataProvider('adapters')]
  public function testExpiryRevocationPersistenceFailureAndTwoConnectionConsumption(string $adapter): void
  {
    ['connection' => $firstConnection, 'root' => $root] = $this->environment($adapter);
    $file = $this->localFile($firstConnection, $root, 'token.txt', 'token');
    $expired = $file->createToken('+1 hour');
    DatabaseGateway::execute(
      $firstConnection,
      'UPDATE `file_tokens` SET `expires_at` = UTC_TIMESTAMP() WHERE `file_id` = ?',
      [$file->id]
    );
    self::assertFalse(FileToken::consume($expired, (int)$file->id, $firstConnection));

    $limited = $file->createToken('+1 hour', 1);
    $results = $this->concurrentlyConsume($adapter, $limited, (int)$file->id, $root);
    self::assertSame(1, count(array_filter($results)));

    $revoked = $file->createToken('+1 hour');
    $record = FileToken::resolve($revoked, $firstConnection);
    self::assertNotFalse($record);
    self::assertTrue($record->revoke());
    self::assertFalse(FileToken::consume($revoked, (int)$file->id, $firstConnection));

    $this->expectException(\RuntimeException::class);
    FileToken::mint(9_999_999, '+1 hour', conn: $firstConnection);
  }

  #[DataProvider('adapters')]
  public function testDefaultsAndFolderMembershipAreUniquenessBacked(string $adapter): void
  {
    ['connection' => $connection, 'root' => $root] = $this->environment($adapter);
    $first = $this->localFile($connection, $root, 'one.txt', 'one');
    $second = $this->localFile($connection, $root, 'two.txt', 'two');

    self::assertNotFalse(FileDefault::set($connection, 'owner', 'avatar', (int)$first->id));
    self::assertNotFalse(FileDefault::set($connection, 'owner', 'avatar', (int)$second->id));
    $avatar = FileDefault::get($connection, 'owner', 'avatar');
    self::assertInstanceOf(FileDefault::class, $avatar);
    self::assertSame($second->id, $avatar->file_id);
    self::assertCount(1, FileDefault::getAll($connection, 'owner', 'avatar'));

    self::assertNotFalse(FileDefault::set($connection, 'owner', 'gallery', (int)$first->id));
    self::assertNotFalse(FileDefault::set($connection, 'owner', 'gallery', (int)$second->id, true));
    self::assertCount(2, FileDefault::getAll($connection, 'owner', 'gallery'));

    $folder = new Folder($connection);
    $folder->name = 'documents';
    $folder->title = 'Documents';
    $folder->owner = 'owner';
    self::assertTrue($folder->create());
    $firstAttach = FolderFile::add($connection, (int)$folder->id, (int)$first->id);
    $secondAttach = FolderFile::add($connection, (int)$folder->id, (int)$first->id);
    self::assertNotFalse($firstAttach);
    self::assertNotFalse($secondAttach);
    self::assertSame($firstAttach->id, $secondAttach->id);
  }

  #[DataProvider('adapters')]
  public function testDeleteFailureBecomesRetryableCleanupState(string $adapter): void
  {
    $driver = new InMemoryStorageDriver('local');
    $factory = new StorageDriverFactory(['local' => $driver]);
    ['connection' => $connection, 'root' => $root] = $this->environment($adapter, $factory);
    $source = $root . DIRECTORY_SEPARATOR . 'source.txt';
    file_put_contents($source, 'recoverable');
    $file = new File($connection);
    $file->owner = 'owner';
    self::assertTrue($file->import($source, 'source.txt'));

    $driver->failDelete = true;
    self::assertFalse($file->destroy(force: true));
    self::assertSame('cleanup_required', $file->lifecycleState());
    $driver->failDelete = false;
    self::assertTrue($file->retryCleanup());
    self::assertSame('deleted', $file->lifecycleState());
  }

  #[DataProvider('adapters')]
  public function testFileCodeCollisionRetriesAgainstDatabaseUniqueness(string $adapter): void
  {
    ['connection' => $connection, 'root' => $root] = $this->environment($adapter);
    CollisionFile::useNumbers(['111111111111']);
    $first = $this->collisionFile($connection, $root, 'collision-one.txt', 'one');
    self::assertSame('583111111111111', $first->code);

    CollisionFile::useNumbers(['111111111111', '222222222222']);
    $second = $this->collisionFile($connection, $root, 'collision-two.txt', 'two');
    self::assertSame('583222222222222', $second->code);
  }

  private function localFile(SQLDatabase $connection, string $root, string $name, string $contents): File
  {
    $directory = $root . DIRECTORY_SEPARATOR . 'Files' . DIRECTORY_SEPARATOR . 'owner';
    if (!is_dir($directory)) mkdir($directory, 0755, true);
    $path = $directory . DIRECTORY_SEPARATOR . $name;
    file_put_contents($path, $contents);
    $file = new File($connection);
    $file->owner = 'owner';
    $file->nice_name = $name;
    $file->fromDisk($path, 'Files/owner');
    self::assertTrue($file->create());
    return $file;
  }

  private function collisionFile(
    SQLDatabase $connection,
    string $root,
    string $name,
    string $contents
  ): CollisionFile {
    $directory = $root . DIRECTORY_SEPARATOR . 'Files' . DIRECTORY_SEPARATOR . 'owner';
    if (!is_dir($directory)) mkdir($directory, 0755, true);
    $path = $directory . DIRECTORY_SEPARATOR . $name;
    file_put_contents($path, $contents);
    $file = new CollisionFile($connection);
    $file->owner = 'owner';
    $file->nice_name = $name;
    $file->fromDisk($path, 'Files/owner');
    self::assertTrue($file->create());
    return $file;
  }

  /** @return list<bool> */
  private function concurrentlyConsume(string $adapter, string $bearer, int $fileId, string $root): array
  {
    $barrier = $this->temporaryDirectory();
    $go = $barrier . DIRECTORY_SEPARATOR . 'go';
    $worker = __DIR__ . DIRECTORY_SEPARATOR . 'token-consume-worker.php';
    $host = (string)getenv('TF_FILE_TEST_HOST');
    $user = (string)getenv('TF_FILE_TEST_USER');
    $password = (string)getenv('TF_FILE_TEST_PASSWORD');
    $database = (string)getenv('TF_FILE_TEST_DATABASE');
    $port = (int)(getenv('TF_FILE_TEST_PORT') ?: 3306);

    /** @var list<array{process:resource,pipes:array<int, resource>,ready:string}> $processes */
    $processes = [];
    for ($index = 0; $index < 2; $index++) {
      $ready = $barrier . DIRECTORY_SEPARATOR . "ready-{$index}";
      $pipes = [];
      $process = proc_open(
        [PHP_BINARY, $worker],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__, 2)
      );
      if (!is_resource($process)) {
        self::fail('Could not start a concurrent token-consumption worker.');
      }
      $payload = json_encode([
        'adapter' => $adapter,
        'host' => $host,
        'port' => $port,
        'database' => $database,
        'user' => $user,
        'password' => $password,
        'root' => $root,
        'bearer' => $bearer,
        'file_id' => $fileId,
        'ready' => $ready,
        'go' => $go,
      ], JSON_THROW_ON_ERROR);
      fwrite($pipes[0], $payload);
      fclose($pipes[0]);
      $processes[] = ['process' => $process, 'pipes' => $pipes, 'ready' => $ready];
    }

    $deadline = microtime(true) + 10;
    do {
      $ready = count(array_filter($processes, static fn(array $item): bool => is_file($item['ready'])));
      if ($ready === 2) break;
      usleep(10_000);
    } while (microtime(true) < $deadline);
    if ($ready !== 2) {
      self::fail('Concurrent token-consumption workers did not reach the barrier.');
    }
    if (file_put_contents($go, 'go', LOCK_EX) === false) {
      self::fail('Could not release the concurrent token-consumption barrier.');
    }

    $results = [];
    foreach ($processes as $item) {
      $output = trim((string)stream_get_contents($item['pipes'][1]));
      $error = trim((string)stream_get_contents($item['pipes'][2]));
      fclose($item['pipes'][1]);
      fclose($item['pipes'][2]);
      $exitCode = proc_close($item['process']);
      self::assertSame('', $error, "Concurrent worker error: {$error}");
      self::assertSame(0, $exitCode, 'Concurrent worker exited unsuccessfully.');
      self::assertContains($output, ['0', '1'], 'Concurrent worker returned an invalid result.');
      $results[] = $output === '1';
    }
    return $results;
  }
}

/** @phpstan-consistent-constructor */
final class CollisionFile extends File
{
  /** @var list<string> */
  private static array $numbers = [];

  /** @param list<string> $numbers */
  public static function useNumbers(array $numbers): void
  {
    self::$numbers = $numbers;
  }

  protected function _randomNumeric(int $length): string
  {
    $number = array_shift(self::$numbers);
    if (!is_string($number) || strlen($number) !== $length || !ctype_digit($number)) {
      throw new \RuntimeException('The collision-test number queue is invalid.');
    }
    return $number;
  }
}

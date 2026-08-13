<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Tests\Unit;

use TimeFrontiers\File\File;
use TimeFrontiers\File\FileConfig;
use TimeFrontiers\File\Drivers\StorageDriverFactory;
use TimeFrontiers\File\Tests\Fakes\InMemoryStorageDriver;
use TimeFrontiers\File\Tests\TestCase;
use TimeFrontiers\SQLDatabase;

final class WriterAndUrlTest extends TestCase
{
  public function testZeroByteAppendAndStreamingRewriteSucceed(): void
  {
    $root = $this->temporaryDirectory();
    $directory = $root . DIRECTORY_SEPARATOR . 'Files' . DIRECTORY_SEPARATOR . 'owner';
    mkdir($directory, 0755, true);
    $path = $directory . DIRECTORY_SEPARATOR . 'a.txt';
    file_put_contents($path, "one\ntwo\n");
    FileConfig::configure(['service_url' => 'https://files.example.test'], ['local' => ['upload_path' => $root]]);
    $file = WritableTestFile::_instantiateFromRow($this->row(), $this->createMock(SQLDatabase::class));

    self::assertTrue($file->write('', FILE_WRITER_APPEND));
    self::assertTrue($file->write("zero\n", FILE_WRITER_PREPEND));
    self::assertTrue($file->writeLine(1, "ONE\n"));
    self::assertSame("zero\nONE\ntwo\n", file_get_contents($path));
    self::assertSame('https://files.example.test/file/583000000000001', $file->url());
    self::assertStringNotContainsString('Files/owner', $file->url());
  }

  public function testWriterRejectsRemoteFilesExplicitly(): void
  {
    FileConfig::configure(['default_driver' => 's3'], [
      's3' => ['bucket' => 'valid-bucket', 'region' => 'us-east-1', 'key' => 'key', 'secret' => 'secret'],
    ]);
    $row = array_replace($this->row(), ['storage_driver' => 's3']);
    $file = WritableTestFile::_instantiateFromRow($row, $this->createMock(SQLDatabase::class));
    self::assertFalse($file->write('value'));
  }

  public function testStreamingProviderFailureIsReportedAsReadFailure(): void
  {
    $driver = new InMemoryStorageDriver('local');
    $driver->failRead = true;
    FileConfig::configure(
      [],
      ['local' => ['upload_path' => $this->temporaryDirectory()]],
      new StorageDriverFactory(['local' => $driver])
    );
    $file = WritableTestFile::_instantiateFromRow($this->row(), $this->createMock(SQLDatabase::class));
    self::assertFalse($file->readAll());
  }

  public function testRemoteRecordCannotBeRedirectedToSameNamedLocalObject(): void
  {
    $root = $this->temporaryDirectory();
    $directory = $root . DIRECTORY_SEPARATOR . 'Files' . DIRECTORY_SEPARATOR . 'owner';
    mkdir($directory, 0755, true);
    file_put_contents($directory . DIRECTORY_SEPARATOR . 'a.txt', 'local decoy');
    FileConfig::configure(['default_driver' => 's3'], [
      'local' => ['upload_path' => $root],
      's3' => ['bucket' => 'valid-bucket', 'region' => 'us-east-1', 'key' => 'key', 'secret' => 'secret'],
    ]);
    $file = WritableTestFile::_instantiateFromRow(
      array_replace($this->row(), ['storage_driver' => 's3']),
      $this->createMock(SQLDatabase::class)
    );
    $file->storage_driver = 'local';

    $this->expectException(\LogicException::class);
    $file->write('must not reach the local file');
  }

  /** @return array<string, mixed> */
  private function row(): array
  {
    return [
      'id' => 1, 'code' => '583000000000001', 'nice_name' => 'a.txt',
      'type_group' => 'text', 'caption' => null, 'owner' => 'owner',
      'privacy' => 'public', 'storage_driver' => 'local', 'storage_bucket' => null,
      '_name' => 'a.txt', '_path' => 'Files/owner', 'object_key' => 'Files/owner/a.txt',
      '_type' => 'text/plain', '_size' => 8, '_checksum' => str_repeat('a', 128),
      '_locked' => 0, '_watermarked' => 0, 'lifecycle_state' => 'active',
      'cleanup_required_at' => null, 'cleanup_error_code' => null,
      'cleanup_operation' => null, 'cleanup_attempts' => 0, 'deleted_at' => null,
      '_creator' => 'SYSTEM', '_updated' => null, '_created' => null,
    ];
  }
}

/** @phpstan-consistent-constructor */
final class WritableTestFile extends File
{
  protected function _persistObservedMetadata(): bool { return true; }
}

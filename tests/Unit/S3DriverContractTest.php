<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use TimeFrontiers\File\Drivers\AwsS3Driver;
use TimeFrontiers\File\Drivers\MinioDriver;
use TimeFrontiers\File\Drivers\StorageDeleteResult;
use TimeFrontiers\File\Drivers\StorageDriverInterface;
use TimeFrontiers\File\Drivers\StorageMoveResult;
use TimeFrontiers\File\Drivers\StorageWriteResult;
use TimeFrontiers\File\Exceptions\ProviderException;
use TimeFrontiers\File\FileConfig;
use TimeFrontiers\File\Storage\ObjectKey;
use TimeFrontiers\File\Tests\Fakes\FakeS3Client;
use TimeFrontiers\File\Tests\TestCase;

final class S3DriverContractTest extends TestCase
{
  #[DataProvider('drivers')]
  public function testS3CompatibleDriverUsesExactKeyAndStreamingContract(string $name): void
  {
    $sourceDirectory = $this->temporaryDirectory();
    $source = $sourceDirectory . DIRECTORY_SEPARATOR . 'source.txt';
    file_put_contents($source, 'remote bytes');
    FileConfig::configure(['default_driver' => $name], [
      's3' => ['bucket' => 'valid-bucket', 'region' => 'eu-west-1', 'key' => 'key', 'secret' => 'secret'],
      'minio' => [
        'endpoint' => 'https://minio.example.test', 'bucket' => 'valid-minio',
        'region' => 'us-east-1', 'key' => 'key', 'secret' => 'secret',
      ],
    ]);
    $client = new FakeS3Client();
    $driver = $name === 's3'
      ? new AwsS3Driver(FileConfig::snapshot(), $client)
      : new MinioDriver(FileConfig::snapshot(), $client);
    $key = ObjectKey::fromString('Files/owner/object.txt');

    self::assertSame(StorageWriteResult::Stored, $driver->put($source, $key));
    self::assertSame($key->value(), $client->lastPut['Key'] ?? null);
    self::assertSame('*', $client->lastPut['IfNoneMatch'] ?? null);
    $stream = $driver->readStream($key);
    self::assertSame('remote bytes', stream_get_contents($stream));
    fclose($stream);
    $movedKey = ObjectKey::fromString('Files/owner/moved.txt');
    self::assertSame(StorageMoveResult::Moved, $driver->move($key, $movedKey));
    self::assertFalse($driver->exists($key));
    self::assertTrue($driver->exists($movedKey));
    self::assertSame(StorageDeleteResult::Deleted, $driver->delete($movedKey));
    self::assertSame(StorageDeleteResult::AlreadyAbsent, $driver->delete($movedKey));
  }

  public function testConditionalRemotePutDoesNotOverwriteExistingKey(): void
  {
    $sourceDirectory = $this->temporaryDirectory();
    $source = $sourceDirectory . DIRECTORY_SEPARATOR . 'source.txt';
    file_put_contents($source, 'new');
    FileConfig::configure(['default_driver' => 's3'], [
      's3' => ['bucket' => 'valid-bucket', 'region' => 'eu-west-1', 'key' => 'key', 'secret' => 'secret'],
    ]);
    $client = new FakeS3Client();
    $key = ObjectKey::fromString('Files/owner/object.txt');
    $client->objects[$key->value()] = 'old';
    $driver = new AwsS3Driver(FileConfig::snapshot(), $client);
    $this->expectException(ProviderException::class);
    $driver->put($source, $key);
  }

  /** @return iterable<string, array{string}> */
  public static function drivers(): iterable
  {
    yield 'AWS S3' => ['s3'];
    yield 'MinIO' => ['minio'];
  }
}

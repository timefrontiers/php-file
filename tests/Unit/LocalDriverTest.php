<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Tests\Unit;

use TimeFrontiers\File\Drivers\LocalDriver;
use TimeFrontiers\File\Drivers\StorageDeleteResult;
use TimeFrontiers\File\Drivers\StorageWriteResult;
use TimeFrontiers\File\FileConfig;
use TimeFrontiers\File\Storage\ObjectKey;
use TimeFrontiers\File\Tests\TestCase;

final class LocalDriverTest extends TestCase
{
  public function testPutReadMoveAndIdempotentDeleteUseExactKey(): void
  {
    $root = $this->temporaryDirectory();
    $sourceDirectory = $this->temporaryDirectory();
    $source = $sourceDirectory . DIRECTORY_SEPARATOR . 'source.txt';
    file_put_contents($source, 'verified bytes');
    FileConfig::configure([], ['local' => ['upload_path' => $root]]);
    $driver = new LocalDriver(FileConfig::snapshot());
    $key = ObjectKey::fromString('Files/owner/object.txt');

    self::assertSame(StorageWriteResult::Stored, $driver->put($source, $key));
    self::assertTrue($driver->exists($key));
    $stream = $driver->readStream($key);
    self::assertSame('verified bytes', stream_get_contents($stream));
    fclose($stream);

    $moved = ObjectKey::fromString('Files/owner/archive/object.txt');
    $driver->move($key, $moved);
    self::assertFalse($driver->exists($key));
    self::assertTrue($driver->exists($moved));
    self::assertSame(StorageDeleteResult::Deleted, $driver->delete($moved));
    self::assertSame(StorageDeleteResult::AlreadyAbsent, $driver->delete($moved));
  }

  public function testZeroByteObjectIsStoredSuccessfully(): void
  {
    $root = $this->temporaryDirectory();
    $sourceDirectory = $this->temporaryDirectory();
    $source = $sourceDirectory . DIRECTORY_SEPARATOR . 'empty.txt';
    file_put_contents($source, '');
    FileConfig::configure([], ['local' => ['upload_path' => $root]]);
    $driver = new LocalDriver(FileConfig::snapshot());
    $key = ObjectKey::fromString('empty/object.txt');
    self::assertSame(StorageWriteResult::Stored, $driver->put($source, $key));
    self::assertSame(0, filesize(FileConfig::snapshot()->localPathResolver()->resolve($key)));
  }
}

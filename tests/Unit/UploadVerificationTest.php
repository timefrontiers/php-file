<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Tests\Unit;

use TimeFrontiers\File\File;
use TimeFrontiers\File\FileConfig;
use TimeFrontiers\File\Tests\TestCase;
use TimeFrontiers\SQLDatabase;

final class UploadVerificationTest extends TestCase
{
  public function testCallerProvidedSizeCannotHideOversizedSource(): void
  {
    $root = $this->temporaryDirectory();
    $sourceDirectory = $this->temporaryDirectory();
    $source = $sourceDirectory . DIRECTORY_SEPARATOR . 'payload.txt';
    file_put_contents($source, 'two bytes or more');
    FileConfig::configure(['max_size' => 1], ['local' => ['upload_path' => $root]]);
    $file = new HttpBoundaryTestFile($this->createMock(SQLDatabase::class));
    $file->owner = 'owner';
    self::assertFalse($file->upload([
      'error' => UPLOAD_ERR_OK,
      'tmp_name' => $source,
      'name' => 'payload.txt',
      'size' => 0,
      'type' => 'text/plain',
    ]));
  }

  public function testRecognizedExtensionCannotOverrideDetectedContent(): void
  {
    $root = $this->temporaryDirectory();
    $sourceDirectory = $this->temporaryDirectory();
    $source = $sourceDirectory . DIRECTORY_SEPARATOR . 'fake.jpg';
    file_put_contents($source, 'plain text');
    FileConfig::configure([], ['local' => ['upload_path' => $root]]);
    $file = new HttpBoundaryTestFile($this->createMock(SQLDatabase::class));
    $file->owner = 'owner';
    self::assertFalse($file->upload([
      'error' => UPLOAD_ERR_OK,
      'tmp_name' => $source,
      'name' => 'fake.jpg',
      'size' => filesize($source),
      'type' => 'image/jpeg',
    ]));
  }

  public function testNonHttpTemporaryFileIsRejectedByProductionBoundary(): void
  {
    $root = $this->temporaryDirectory();
    $sourceDirectory = $this->temporaryDirectory();
    $source = $sourceDirectory . DIRECTORY_SEPARATOR . 'payload.txt';
    file_put_contents($source, 'payload');
    FileConfig::configure([], ['local' => ['upload_path' => $root]]);
    $file = new File($this->createMock(SQLDatabase::class));
    self::assertFalse($file->upload(['error' => UPLOAD_ERR_OK, 'tmp_name' => $source, 'name' => 'payload.txt']));
  }
}

final class HttpBoundaryTestFile extends File
{
  protected function _isHttpUploadedFile(string $path): bool { return is_file($path); }
}

<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Tests\Unit;

use TimeFrontiers\File\Exceptions\InvalidObjectKeyException;
use TimeFrontiers\File\Storage\LocalPathResolver;
use TimeFrontiers\File\Storage\ObjectKey;
use TimeFrontiers\File\Tests\TestCase;

final class LocalPathResolverTest extends TestCase
{
  public function testFutureTargetIsCreatedAndContained(): void
  {
    $root = $this->temporaryDirectory();
    $resolver = new LocalPathResolver($root);
    $target = $resolver->resolve(ObjectKey::fromString('owner/nested/file.txt'), true);
    self::assertDirectoryExists(dirname($target));
    $canonicalRoot = realpath($root);
    if ($canonicalRoot === false) self::fail('The temporary root did not resolve.');
    self::assertStringStartsWith(str_replace('\\', '/', $canonicalRoot), str_replace('\\', '/', $target));
  }

  public function testSymlinkOrWindowsJunctionAncestorCannotEscapeRoot(): void
  {
    $root = $this->temporaryDirectory();
    $outside = $this->temporaryDirectory();
    $link = $root . DIRECTORY_SEPARATOR . 'linked';
    $created = @symlink($outside, $link);
    $junction = false;
    if (!$created && DIRECTORY_SEPARATOR === '\\') {
      $output = [];
      $exitCode = 1;
      exec(
        'cmd.exe /d /c mklink /J ' . escapeshellarg($link) . ' ' . escapeshellarg($outside) . ' 2>&1',
        $output,
        $exitCode
      );
      $created = $exitCode === 0 && is_dir($link);
      $junction = $created;
    }
    if (!$created) {
      self::markTestSkipped('Creating symlinks is not permitted on this test host.');
    }
    $resolver = new LocalPathResolver($root);
    $this->expectException(InvalidObjectKeyException::class);
    try {
      $resolver->resolve(ObjectKey::fromString('linked/escape.txt'), true);
    } finally {
      if ($junction) @rmdir($link);
    }
  }
}

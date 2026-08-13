<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use TimeFrontiers\File\Exceptions\InvalidObjectKeyException;
use TimeFrontiers\File\Storage\ObjectKey;
use TimeFrontiers\File\Tests\TestCase;

final class ObjectKeyTest extends TestCase
{
  public function testCanonicalKeyExposesStableParts(): void
  {
    $key = ObjectKey::fromString('User-Files/583123/avatar.jpg');
    self::assertSame('avatar.jpg', $key->basename());
    self::assertSame('User-Files/583123', $key->directory());
    self::assertSame('User-Files/583123/avatar.jpg', (string)$key);
  }

  #[DataProvider('unsafeKeys')]
  public function testUnsafeWindowsAndPosixKeysAreRejected(string $value): void
  {
    $this->expectException(InvalidObjectKeyException::class);
    ObjectKey::fromString($value);
  }

  /** @return iterable<string, array{string}> */
  public static function unsafeKeys(): iterable
  {
    yield 'posix absolute' => ['/etc/passwd'];
    yield 'drive prefix' => ['C:/Windows/system.ini'];
    yield 'drive backslash' => ['C:\\Windows\\system.ini'];
    yield 'unc' => ['\\\\server\\share\\file'];
    yield 'backslash traversal' => ['safe\\..\\escape'];
    yield 'dot' => ['safe/./file'];
    yield 'dot dot' => ['safe/../file'];
    yield 'empty segment' => ['safe//file'];
    yield 'trailing slash' => ['safe/file/'];
    yield 'nul' => ["safe/fi\0le"];
    yield 'control' => ["safe/fi\nle"];
    yield 'ntfs alternate stream' => ['safe/file.txt:payload'];
  }
}

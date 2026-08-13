<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Tests;

use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use TimeFrontiers\File\FileConfig;

abstract class TestCase extends PhpUnitTestCase
{
  /** @var list<string> */
  private array $temporaryDirectories = [];

  protected function tearDown(): void
  {
    $configuration = new \ReflectionClass(FileConfig::class);
    $configuration->getProperty('snapshot')->setValue(null, null);
    $configuration->getProperty('driverFactory')->setValue(null, null);
    foreach (array_reverse($this->temporaryDirectories) as $directory) {
      $this->removeTree($directory);
    }
    parent::tearDown();
  }

  protected function temporaryDirectory(): string
  {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tf-file-test-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700) && !is_dir($directory)) {
      throw new \RuntimeException('Could not create a temporary test directory.');
    }
    $this->temporaryDirectories[] = $directory;
    return $directory;
  }

  private function removeTree(string $directory): void
  {
    if (!is_dir($directory)) return;
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
      if ($entry->isLink() || $entry->isFile()) {
        @unlink($entry->getPathname());
      } else {
        @rmdir($entry->getPathname());
      }
    }
    @rmdir($directory);
  }
}

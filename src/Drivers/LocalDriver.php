<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Drivers;

use TimeFrontiers\File\FileConfig;

/**
 * Local filesystem storage driver.
 *
 * Files live at:  upload_path + storagePath
 * Public URLs :   storage_url + storagePath
 */
class LocalDriver implements StorageDriverInterface
{
  // -------------------------------------------------------------------------
  // Internal helpers
  // -------------------------------------------------------------------------

  private function fullPath(string $storagePath): string
  {
    return FileConfig::uploadPath() . '/' . ltrim($storagePath, '/');
  }

  // -------------------------------------------------------------------------
  // Interface
  // -------------------------------------------------------------------------

  public function upload(string $tmpPath, string $storagePath): bool
  {
    $dest = $this->fullPath($storagePath);
    $dir  = dirname($dest);

    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
      return false;
    }

    // move_uploaded_file() for real HTTP uploads; rename() for programmatic use
    if (is_uploaded_file($tmpPath)) {
      return move_uploaded_file($tmpPath, $dest);
    }

    return rename($tmpPath, $dest);
  }

  public function delete(string $storagePath): bool
  {
    $full = $this->fullPath($storagePath);

    if (!file_exists($full)) {
      return true; // already absent — treat as success
    }

    return unlink($full);
  }

  public function exists(string $storagePath): bool
  {
    return file_exists($this->fullPath($storagePath));
  }

  public function url(string $storagePath): string
  {
    return FileConfig::storageUrl('local') . '/' . ltrim($storagePath, '/');
  }

  public function read(string $storagePath): mixed
  {
    $full = $this->fullPath($storagePath);

    if (!file_exists($full) || !is_readable($full)) {
      return false;
    }

    return fopen($full, 'rb');
  }
}

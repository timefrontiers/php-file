<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Drivers;

use TimeFrontiers\File\Exceptions\DriverException;

/**
 * Google Cloud Storage driver — STUB.
 *
 * This driver is reserved for a future implementation.
 * Configure drivers['gcs'] only after this class is fully implemented.
 *
 * When implemented, configure via drivers['gcs']:
 *   'storage_url' => ''   // optional CDN override
 */
class GoogleGcsDriver implements StorageDriverInterface
{
  public function __construct()
  {
    throw new DriverException(
      'Google Cloud Storage driver is not yet implemented in timefrontiers/php-file.'
    );
  }

  public function upload(string $tmpPath, string $storagePath): bool   { return false; }
  public function delete(string $storagePath): bool                    { return false; }
  public function exists(string $storagePath): bool                    { return false; }
  public function url(string $storagePath): string                     { return ''; }
  public function read(string $storagePath): mixed                     { return false; }
}

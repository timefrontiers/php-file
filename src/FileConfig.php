<?php
declare(strict_types=1);

namespace TimeFrontiers\File;

use TimeFrontiers\File\Exceptions\ConfigurationException;

/**
 * Static configuration store for timefrontiers/php-file.
 *
 * Bootstrap once in your application:
 *
 *   File::configure(
 *       base: [
 *           'db_name'       => 'file',
 *           'upload_path'   => '/var/www/storage',
 *           'storage_url'   => 'https://cdn.example.com',
 *           'service_url'   => 'https://files.example.com',
 *           'token_secret'  => 'your-hmac-key',
 *           'max_size'      => 1024 * 1024 * 25,
 *           'min_size'      => 1024 * 15,
 *           'max_width_px'  => 2000,
 *           'max_height_px' => 2000,
 *           'min_width_px'  => null,
 *           'min_height_px' => null,
 *       ],
 *       driver: [
 *           'name'     => 'local',  // 'local'|'s3'|'gcs'|'onedrive'|'dropbox'
 *           // S3:
 *           'bucket'   => '',
 *           'region'   => 'us-east-1',
 *           'key'      => '',
 *           'secret'   => '',
 *           'endpoint' => null,     // for S3-compatible stores (MinIO etc.)
 *       ]
 *   );
 */
final class FileConfig
{
  /** @var array<string, mixed> */
  private static array $base = [];

  /** @var array<string, mixed> */
  private static array $driver = [];

  private static bool $configured = false;

  // -------------------------------------------------------------------------
  // Defaults
  // -------------------------------------------------------------------------

  private const BASE_DEFAULTS = [
    'db_name'       => 'file',
    'upload_path'   => '',
    'storage_url'   => '',      // base URL for direct public file access
    'service_url'   => '',      // base URL for token-based download endpoint
    'token_secret'  => '',
    'max_size'      => 26_214_400,  // 25 MB
    'min_size'      => 0,
    'max_width_px'  => null,
    'max_height_px' => null,
    'min_width_px'  => null,
    'min_height_px' => null,
  ];

  private const DRIVER_DEFAULTS = [
    'name'     => 'local',
    'bucket'   => null,
    'region'   => 'us-east-1',
    'key'      => '',
    'secret'   => '',
    'endpoint' => null,
  ];

  // -------------------------------------------------------------------------
  // Bootstrap
  // -------------------------------------------------------------------------

  /**
   * @param array<string, mixed> $base
   * @param array<string, mixed> $driver
   */
  public static function configure(array $base, array $driver = []): void
  {
    self::$base   = array_merge(self::BASE_DEFAULTS, $base);
    self::$driver = array_merge(self::DRIVER_DEFAULTS, $driver);
    self::$configured = true;
  }

  // -------------------------------------------------------------------------
  // Readers
  // -------------------------------------------------------------------------

  public static function get(string $key, mixed $default = null): mixed
  {
    return self::$base[$key] ?? $default;
  }

  public static function driver(string $key = 'name', mixed $default = null): mixed
  {
    return self::$driver[$key] ?? $default;
  }

  public static function isConfigured(): bool
  {
    return self::$configured;
  }

  /**
   * Throw if configure() has never been called.
   *
   * @throws ConfigurationException
   */
  public static function requireConfigured(): void
  {
    if (!self::$configured) {
      throw new ConfigurationException(
        'timefrontiers/php-file is not configured. '
        . 'Call File::configure(base: [...], driver: [...]) before use.'
      );
    }
  }

  /**
   * Return the fully-qualified upload root (no trailing slash).
   */
  public static function uploadPath(): string
  {
    return rtrim(self::$base['upload_path'] ?? '', '/');
  }

  /**
   * Return the service URL base (for token download URLs).
   */
  public static function serviceUrl(): string
  {
    return rtrim(self::$base['service_url'] ?? '', '/');
  }

  /**
   * Return the storage URL base (for direct public file URLs).
   */
  public static function storageUrl(): string
  {
    return rtrim(self::$base['storage_url'] ?? '', '/');
  }

  // -------------------------------------------------------------------------
  // Testing helper
  // -------------------------------------------------------------------------

  /** @internal Reset for unit tests only. */
  public static function reset(): void
  {
    self::$base       = [];
    self::$driver     = [];
    self::$configured = false;
  }
}

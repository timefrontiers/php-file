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
 *           'default_driver' => 'local',
 *           'db_name'        => 'file',
 *           'service_url'    => 'https://files.example.com',
 *           'token_secret'   => 'your-hmac-key',
 *           'max_size'       => 1024 * 1024 * 25,
 *           'min_size'       => 0,
 *           'max_width_px'   => 2000,
 *           'max_height_px'  => 2000,
 *           'min_width_px'   => null,
 *           'min_height_px'  => null,
 *       ],
 *       drivers: [
 *           'local' => [
 *               'upload_path' => '/var/www/storage',
 *               'storage_url' => 'https://cdn.example.com',
 *           ],
 *           's3' => [
 *               'bucket'      => 'my-bucket',
 *               'region'      => 'us-east-1',
 *               'key'         => 'ACCESS_KEY_ID',
 *               'secret'      => 'SECRET_ACCESS_KEY',
 *               'endpoint'    => null,
 *               'storage_url' => '',
 *           ],
 *           'minio' => [
 *               'endpoint'    => 'http://localhost:9000',
 *               'bucket'      => 'my-bucket',
 *               'region'      => 'us-east-1',
 *               'key'         => 'minioadmin',
 *               'secret'      => 'minioadmin',
 *               'storage_url' => '',
 *           ],
 *       ]
 *   );
 */
final class FileConfig
{
  /** @var array<string, mixed> */
  private static array $base = [];

  /** @var array<string, array<string, mixed>> */
  private static array $drivers = [];

  private static bool $configured = false;

  // -------------------------------------------------------------------------
  // Defaults
  // -------------------------------------------------------------------------

  private const BASE_DEFAULTS = [
    'default_driver' => 'local',
    'db_name'        => 'file',
    'path_prefix'    => '',          // logical path namespace, e.g. 'User-Files' — appended before /{owner}
    'service_url'    => '',
    'token_secret'   => '',
    'max_size'       => 26_214_400,  // 25 MB
    'min_size'       => 0,
    'max_width_px'   => null,
    'max_height_px'  => null,
    'min_width_px'   => null,
    'min_height_px'  => null,
  ];

  /** Per-driver defaults merged before caller-supplied config. */
  private const DRIVER_DEFAULTS = [
    'local' => [
      'upload_path' => '',
      'storage_url' => '',
    ],
    's3' => [
      'bucket'      => null,
      'region'      => 'us-east-1',
      'key'         => '',
      'secret'      => '',
      'endpoint'    => null,
      'storage_url' => '',
    ],
    'minio' => [
      'endpoint'    => '',
      'bucket'      => null,
      'region'      => 'us-east-1',
      'key'         => '',
      'secret'      => '',
      'storage_url' => '',
    ],
    'gcs' => [
      'storage_url' => '',
    ],
    'onedrive' => [
      'storage_url' => '',
    ],
    'dropbox' => [
      'storage_url' => '',
    ],
  ];

  // -------------------------------------------------------------------------
  // Bootstrap
  // -------------------------------------------------------------------------

  /**
   * @param array<string, mixed>               $base
   * @param array<string, array<string, mixed>> $drivers
   *
   * @throws ConfigurationException
   */
  public static function configure(array $base, array $drivers = []): void
  {
    // RULE 1 — drivers map must not be empty
    if (empty($drivers)) {
      throw new ConfigurationException(
        'timefrontiers/php-file: drivers[] must not be empty. '
        . 'Configure at least one driver (e.g. "local").'
      );
    }

    $mergedBase = array_merge(self::BASE_DEFAULTS, $base);

    // RULE 2 — default_driver must be present in base
    if (empty($mergedBase['default_driver'])) {
      throw new ConfigurationException(
        "timefrontiers/php-file: base['default_driver'] is required."
      );
    }

    // RULE 3 — default_driver value must be a key in the drivers map
    if (!array_key_exists($mergedBase['default_driver'], $drivers)) {
      $available = implode(', ', array_keys($drivers));
      throw new ConfigurationException(
        "timefrontiers/php-file: default_driver \"{$mergedBase['default_driver']}\" "
        . "is not present in the drivers map. Available: [{$available}]."
      );
    }

    // RULE 4 — every driver entry must be an array
    $mergedDrivers = [];
    foreach ($drivers as $name => $cfg) {
      if (!is_array($cfg)) {
        throw new ConfigurationException(
          "timefrontiers/php-file: drivers[\"{$name}\"] must be an array, "
          . gettype($cfg) . ' given.'
        );
      }
      $defaults = self::DRIVER_DEFAULTS[$name] ?? [];
      $mergedDrivers[$name] = array_merge($defaults, $cfg);
    }

    self::$base      = $mergedBase;
    self::$drivers   = $mergedDrivers;
    self::$configured = true;
  }

  // -------------------------------------------------------------------------
  // Readers — base config
  // -------------------------------------------------------------------------

  public static function get(string $key, mixed $default = null): mixed
  {
    return self::$base[$key] ?? $default;
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
        . 'Call File::configure(base: [...], drivers: [...]) before use.'
      );
    }
  }

  // -------------------------------------------------------------------------
  // Readers — drivers config
  // -------------------------------------------------------------------------

  /**
   * Return the full config array for one driver, or the entire drivers map.
   *
   * @return array<string, mixed>
   */
  public static function drivers(?string $name = null): array
  {
    if ($name === null) {
      return self::$drivers;
    }
    return self::$drivers[$name] ?? [];
  }

  /**
   * Read one key from a specific driver's merged config.
   */
  public static function driverConfig(string $driver, string $key, mixed $default = null): mixed
  {
    return self::$drivers[$driver][$key] ?? $default;
  }

  // -------------------------------------------------------------------------
  // Convenience URL / path helpers
  // -------------------------------------------------------------------------

  /**
   * Return the fully-qualified upload root for the local driver (no trailing slash).
   * Only meaningful for the 'local' driver.
   */
  public static function uploadPath(): string
  {
    return rtrim(self::$drivers['local']['upload_path'] ?? '', '/');
  }

  /**
   * Return the service URL base for token-based download URLs (no trailing slash).
   * This is driver-agnostic — the download endpoint lives at the application layer.
   */
  public static function serviceUrl(): string
  {
    return rtrim(self::$base['service_url'] ?? '', '/');
  }

  /**
   * Return the storage URL base for a given driver (no trailing slash).
   * Each driver has its own storage_url in its config block.
   *
   * @param string $driver  e.g. 'local', 's3', 'minio'
   */
  public static function storageUrl(string $driver = 'local'): string
  {
    return rtrim(self::$drivers[$driver]['storage_url'] ?? '', '/');
  }

  // -------------------------------------------------------------------------
  // Testing helper
  // -------------------------------------------------------------------------

  /** @internal Reset for unit tests only. */
  public static function reset(): void
  {
    self::$base       = [];
    self::$drivers    = [];
    self::$configured = false;
  }
}

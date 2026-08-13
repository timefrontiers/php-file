<?php
declare(strict_types=1);

namespace TimeFrontiers\File;

use TimeFrontiers\File\Drivers\StorageDriverFactory;
use TimeFrontiers\File\Drivers\StorageDriverFactoryInterface;
use TimeFrontiers\File\Exceptions\ConfigurationException;
use TimeFrontiers\File\Storage\LocalPathResolver;
use TimeFrontiers\File\Storage\ObjectKey;
use TimeFrontiers\Validation\Validator;

/** Validated, call-once configuration facade. */
final class FileConfig
{
  private const SUPPORTED_DRIVERS = ['local', 's3', 'minio'];
  private const STUB_DRIVERS = ['gcs', 'onedrive', 'dropbox'];

  private const BASE_DEFAULTS = [
    'default_driver' => 'local',
    'db_name' => 'file',
    'path_prefix' => '',
    'service_url' => '',
    'public_url_path' => 'file',
    'download_url_path' => 'download',
    'token_enabled' => false,
    'token_secret' => '', // v1.0 compatibility input; converted to token_keys.
    'token_keys' => [],
    'active_token_key_id' => '',
    'max_size' => 26_214_400,
    'min_size' => 0,
    'max_width_px' => null,
    'max_height_px' => null,
    'min_width_px' => null,
    'min_height_px' => null,
    'max_image_pixels' => 40_000_000,
    'max_image_memory_bytes' => 268_435_456,
    'read_all_max_bytes' => 16_777_216,
    'code_retry_limit' => 5,
    'allowed_extensions' => [],
    'allowed_mime_types' => [],
    'denied_extensions' => [
      'app', 'bat', 'cgi', 'cmd', 'com', 'cpl', 'dll', 'exe', 'hta', 'htaccess',
      'htm', 'html', 'js', 'jse', 'mjs', 'msi', 'phtml', 'phar', 'php', 'php3',
      'php4', 'php5', 'php7', 'php8', 'pl', 'ps1', 'py', 'rb', 'reg', 'scr',
      'sh', 'svg', 'vbs', 'wsf',
    ],
    'denied_mime_types' => [
      'application/x-dosexec', 'application/x-executable', 'application/x-httpd-php',
      'application/x-msdownload', 'application/x-sh', 'application/x-shellscript',
      'text/html', 'text/javascript', 'image/svg+xml',
    ],
  ];

  /** @var array<string, list<string>> */
  private const DRIVER_KEYS = [
    'local' => ['upload_path', 'storage_url'],
    's3' => ['bucket', 'region', 'key', 'secret', 'endpoint', 'storage_url'],
    'minio' => ['endpoint', 'bucket', 'region', 'key', 'secret', 'storage_url'],
  ];

  private static ?FileConfiguration $snapshot = null;
  private static ?StorageDriverFactoryInterface $driverFactory = null;

  /**
   * @param array<string, mixed> $base
   * @param array<string, array<string, mixed>> $drivers
   */
  public static function configure(
    array $base,
    array $drivers = [],
    ?StorageDriverFactoryInterface $driverFactory = null
  ): void {
    if (self::$snapshot !== null) {
      throw new ConfigurationException('timefrontiers/php-file configuration is already frozen.');
    }
    if ($drivers === []) {
      throw new ConfigurationException('Configure at least one supported storage driver.');
    }

    $unknownBase = array_diff(array_keys($base), array_keys(self::BASE_DEFAULTS));
    if ($unknownBase !== []) {
      throw new ConfigurationException('Unknown base configuration keys: ' . implode(', ', $unknownBase));
    }
    $merged = array_replace(self::BASE_DEFAULTS, $base);

    $result = Validator::make($merged, [
      'default_driver' => ['required', ['in', self::SUPPORTED_DRIVERS, true]],
      'db_name' => ['required', ['pattern', '/^[A-Za-z0-9_]+$/D']],
      'path_prefix' => [['text', 0, ObjectKey::MAX_LENGTH]],
      'token_enabled' => ['boolean'],
      'max_size' => [['int', 1, null]],
      'min_size' => [['int', 0, null]],
      'max_image_pixels' => [['int', 1, null]],
      'max_image_memory_bytes' => [['int', 1, null]],
      'read_all_max_bytes' => [['int', 1, null]],
      'code_retry_limit' => [['int', 1, 20]],
      'allowed_extensions' => ['array'],
      'allowed_mime_types' => ['array'],
      'denied_extensions' => ['array'],
      'denied_mime_types' => ['array'],
    ]);
    if ($result->fails()) {
      throw new ConfigurationException($result->first() ?? 'File configuration validation failed.');
    }
    foreach ($result->validated() as $key => $value) {
      $merged[$key] = $value;
    }

    if ((int)$merged['min_size'] > (int)$merged['max_size']) {
      throw new ConfigurationException('min_size must not exceed max_size.');
    }
    foreach (['max_width_px', 'max_height_px', 'min_width_px', 'min_height_px'] as $key) {
      $value = $merged[$key];
      if ($value !== null && (!is_int($value) || $value < 1)) {
        throw new ConfigurationException("{$key} must be null or a positive integer.");
      }
    }
    if ($merged['min_width_px'] !== null && $merged['max_width_px'] !== null
      && (int)$merged['min_width_px'] > (int)$merged['max_width_px']
    ) {
      throw new ConfigurationException('min_width_px must not exceed max_width_px.');
    }
    if ($merged['min_height_px'] !== null && $merged['max_height_px'] !== null
      && (int)$merged['min_height_px'] > (int)$merged['max_height_px']
    ) {
      throw new ConfigurationException('min_height_px must not exceed max_height_px.');
    }
    foreach (['allowed_extensions', 'denied_extensions'] as $key) {
      $merged[$key] = self::normalisePolicyList((array)$merged[$key], $key, '/^[a-z0-9]{1,10}$/D');
    }
    foreach (['allowed_mime_types', 'denied_mime_types'] as $key) {
      $merged[$key] = self::normalisePolicyList(
        (array)$merged[$key],
        $key,
        '~^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*$~D'
      );
    }

    $prefix = (string)$merged['path_prefix'];
    if ($prefix !== '') {
      ObjectKey::fromString($prefix);
    }
    self::assertHttpUrl((string)$merged['service_url'], 'service_url', allowEmpty: true);
    foreach (['public_url_path', 'download_url_path'] as $key) {
      ObjectKey::fromString((string)$merged[$key]);
    }

    $normalisedDrivers = [];
    foreach ($drivers as $name => $config) {
      if (in_array($name, self::STUB_DRIVERS, true)) {
        throw new ConfigurationException("The {$name} storage driver is a stub and cannot be configured in v1.1.");
      }
      if (!in_array($name, self::SUPPORTED_DRIVERS, true)) {
        throw new ConfigurationException("Unsupported storage driver: {$name}.");
      }
      if (!is_array($config)) {
        throw new ConfigurationException("drivers[{$name}] must be an array.");
      }
      $unknown = array_diff(array_keys($config), self::DRIVER_KEYS[$name]);
      if ($unknown !== []) {
        throw new ConfigurationException("Unknown {$name} driver keys: " . implode(', ', $unknown));
      }
      $normalisedDrivers[$name] = self::validateDriver($name, $config);
    }

    $defaultDriver = (string)$merged['default_driver'];
    if (!array_key_exists($defaultDriver, $normalisedDrivers)) {
      throw new ConfigurationException("The default driver {$defaultDriver} is not configured.");
    }

    self::normaliseTokenSettings($merged);

    $resolver = isset($normalisedDrivers['local'])
      ? new LocalPathResolver((string)$normalisedDrivers['local']['upload_path'])
      : null;
    if ($resolver !== null) {
      $normalisedDrivers['local']['upload_path'] = $resolver->root();
    }

    self::$snapshot = new FileConfiguration($merged, $normalisedDrivers, $resolver);
    self::$driverFactory = $driverFactory ?? new StorageDriverFactory();
  }

  public static function requireConfigured(): void
  {
    if (self::$snapshot === null) {
      throw new ConfigurationException(
        'timefrontiers/php-file is not configured. Call File::configure() during application bootstrap.'
      );
    }
  }

  public static function isConfigured(): bool
  {
    return self::$snapshot !== null;
  }

  public static function snapshot(): FileConfiguration
  {
    self::requireConfigured();
    $snapshot = self::$snapshot;
    if ($snapshot === null) {
      throw new ConfigurationException('File configuration is unexpectedly unavailable.');
    }
    return $snapshot;
  }

  public static function driverFactory(): StorageDriverFactoryInterface
  {
    self::requireConfigured();
    $factory = self::$driverFactory;
    if ($factory === null) {
      throw new ConfigurationException('The storage driver factory is unexpectedly unavailable.');
    }
    return $factory;
  }

  public static function get(string $key, mixed $default = null): mixed
  {
    return self::snapshot()->get($key, $default);
  }

  /** @return array<string, mixed>|array<string, array<string, mixed>> */
  public static function drivers(?string $name = null): array
  {
    return self::snapshot()->drivers($name);
  }

  public static function driverConfig(string $driver, string $key, mixed $default = null): mixed
  {
    return self::snapshot()->driver($driver, $key, $default);
  }

  public static function uploadPath(): string
  {
    return self::snapshot()->localPathResolver()->root();
  }

  public static function serviceUrl(): string
  {
    return self::snapshot()->serviceUrl();
  }

  /** @deprecated Public URLs are code-based service routes in v1.1. */
  public static function storageUrl(string $driver = 'local'): string
  {
    return rtrim((string)self::driverConfig($driver, 'storage_url', ''), '/');
  }

  /**
   * @param array<string, mixed> $config
   * @return array<string, mixed>
   */
  private static function validateDriver(string $name, array $config): array
  {
    if ($name === 'local') {
      $root = $config['upload_path'] ?? '';
      if (!is_string($root) || $root === '') {
        throw new ConfigurationException('drivers.local.upload_path is required.');
      }
      $url = (string)($config['storage_url'] ?? '');
      self::assertHttpUrl($url, 'drivers.local.storage_url', allowEmpty: true);
      return ['upload_path' => $root, 'storage_url' => $url];
    }

    $bucket = $config['bucket'] ?? '';
    $region = $config['region'] ?? 'us-east-1';
    $key = $config['key'] ?? '';
    $secret = $config['secret'] ?? '';
    if (!is_string($bucket) || preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $bucket) !== 1) {
      throw new ConfigurationException("drivers.{$name}.bucket is invalid.");
    }
    foreach (['region' => $region, 'key' => $key, 'secret' => $secret] as $field => $value) {
      if (!is_string($value) || $value === '') {
        throw new ConfigurationException("drivers.{$name}.{$field} is required.");
      }
    }
    $endpoint = $config['endpoint'] ?? null;
    if ($name === 'minio' && (!is_string($endpoint) || $endpoint === '')) {
      throw new ConfigurationException('drivers.minio.endpoint is required.');
    }
    if ($endpoint !== null) {
      self::assertHttpUrl((string)$endpoint, "drivers.{$name}.endpoint", allowEmpty: false);
    }
    $storageUrl = (string)($config['storage_url'] ?? '');
    self::assertHttpUrl($storageUrl, "drivers.{$name}.storage_url", allowEmpty: true);

    return [
      'bucket' => $bucket,
      'region' => $region,
      'key' => $key,
      'secret' => $secret,
      'endpoint' => $endpoint,
      'storage_url' => $storageUrl,
    ];
  }

  /** @param array<string, mixed> $base */
  private static function normaliseTokenSettings(array &$base): void
  {
    $legacy = (string)$base['token_secret'];
    $keys = $base['token_keys'];
    if (!is_array($keys)) {
      throw new ConfigurationException('token_keys must be a key-ID to secret map.');
    }
    if ($legacy !== '') {
      if ($keys !== []) {
        throw new ConfigurationException('Use token_secret or token_keys, not both.');
      }
      $keys = ['legacy' => $legacy];
      $base['active_token_key_id'] = 'legacy';
      $base['token_enabled'] = true;
    }
    if ($keys !== []) {
      $base['token_enabled'] = true;
    }

    $normalised = [];
    foreach ($keys as $keyId => $secret) {
      if (!is_string($keyId) || preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $keyId) !== 1) {
        throw new ConfigurationException('Every token key ID must be 1-32 URL-safe characters.');
      }
      if (!is_string($secret) || strlen($secret) < 32) {
        throw new ConfigurationException("Token secret {$keyId} must contain at least 32 bytes.");
      }
      $normalised[$keyId] = $secret;
    }

    if ((bool)$base['token_enabled']) {
      $active = (string)$base['active_token_key_id'];
      if ($normalised === [] || $active === '' || !array_key_exists($active, $normalised)) {
        throw new ConfigurationException('Token downloads require token_keys and a valid active_token_key_id.');
      }
      if ((string)$base['service_url'] === '') {
        throw new ConfigurationException('Token downloads require a non-empty service_url.');
      }
    }

    $base['token_keys'] = $normalised;
    unset($base['token_secret']);
  }

  private static function assertHttpUrl(string $value, string $field, bool $allowEmpty): void
  {
    if ($value === '' && $allowEmpty) {
      return;
    }
    if (filter_var($value, FILTER_VALIDATE_URL) === false
      || !in_array(strtolower((string)parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
    ) {
      throw new ConfigurationException("{$field} must be an HTTP(S) URL.");
    }
  }

  /**
   * @param array<array-key, mixed> $values
   * @return list<string>
   */
  private static function normalisePolicyList(array $values, string $field, string $pattern): array
  {
    if (!array_is_list($values)) {
      throw new ConfigurationException("{$field} must be a list.");
    }
    $normalised = [];
    foreach ($values as $value) {
      if (!is_string($value) || preg_match($pattern, strtolower($value)) !== 1) {
        throw new ConfigurationException("{$field} contains an invalid value.");
      }
      $normalised[] = strtolower($value);
    }
    return array_values(array_unique($normalised));
  }
}

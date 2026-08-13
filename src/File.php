<?php
declare(strict_types=1);

namespace TimeFrontiers\File;

use TimeFrontiers\File\Concerns\Downloader;
use TimeFrontiers\File\Concerns\FileData;
use TimeFrontiers\File\Concerns\ImageProcessor;
use TimeFrontiers\File\Concerns\Reader;
use TimeFrontiers\File\Concerns\Uploader;
use TimeFrontiers\File\Concerns\Writer;
use TimeFrontiers\File\Database\DatabaseGateway;
use TimeFrontiers\File\Drivers\StorageDriverFactoryInterface;
use TimeFrontiers\File\Exceptions\ConfigurationException;
use TimeFrontiers\File\Exceptions\StorageException;
use TimeFrontiers\File\Storage\ObjectKey;
use TimeFrontiers\SQLDatabase;

/** @phpstan-consistent-constructor */
class File
{
  use \TimeFrontiers\Helper\DatabaseObject {
    _create as private _databaseObjectCreate;
  }
  use \TimeFrontiers\Helper\Pagination;
  use FileData;
  use ImageProcessor;
  use Uploader;
  use Downloader;
  use Writer;
  use Reader;

  public const CODE_PREFIX = '583';

  protected static string $_primary_key = 'id';
  protected static string $_db_name = '';
  protected static string $_table_name = 'file_meta';
  /** @var list<string> */
  protected static array $_db_fields = [
    'id', 'code', 'nice_name', 'type_group', 'caption',
    'owner', 'privacy', 'storage_driver', 'storage_bucket',
    '_name', '_path', 'object_key', '_type', '_size',
    '_checksum', '_locked', '_watermarked',
    'lifecycle_state', 'cleanup_required_at', 'cleanup_error_code',
    'cleanup_operation', 'cleanup_attempts', 'deleted_at',
    '_creator', '_updated', '_created',
  ];

  public ?int $id = null;
  public ?string $code = null;
  public string $nice_name = '';
  public ?string $type_group = null;
  public ?string $caption = null;
  public string $owner = '';
  public string $privacy = 'public';
  public string $storage_driver = 'local';
  public ?string $storage_bucket = null;

  protected string $_name = '';
  protected string $_path = '';
  protected string $object_key = '';
  protected string $_type = '';
  protected int $_size = 0;
  protected ?string $_checksum = null;
  protected bool $_locked = false;
  protected bool $_watermarked = false;
  protected string $lifecycle_state = 'active';
  protected ?string $cleanup_required_at = null;
  protected ?string $cleanup_error_code = null;
  protected ?string $cleanup_operation = null;
  protected int $cleanup_attempts = 0;
  protected ?string $deleted_at = null;
  protected string $_creator = 'SYSTEM';
  protected ?string $_updated = null;
  protected ?string $_created = null;

  private readonly FileConfiguration $configuration;
  private string $storage_identity;
  private ?string $path_suffix = null;

  /**
   * @param array<string, mixed> $base
   * @param array<string, array<string, mixed>> $drivers
   */
  public static function configure(
    array $base,
    array $drivers = [],
    ?StorageDriverFactoryInterface $driverFactory = null
  ): void {
    FileConfig::configure($base, $drivers, $driverFactory);
    static::$_db_name = (string)FileConfig::get('db_name', 'file');
  }

  public function __construct(SQLDatabase $conn, ?string $driver = null)
  {
    FileConfig::requireConfigured();
    $this->configuration = FileConfig::snapshot();
    static::$_db_name = (string)$this->configuration->get('db_name', 'file');

    $resolved = $driver ?? (string)$this->configuration->get('default_driver', 'local');
    if (!array_key_exists($resolved, $this->configuration->drivers())) {
      throw new ConfigurationException("Unknown or unconfigured storage driver: {$resolved}.");
    }
    $this->storage_driver = $resolved;
    $this->storage_identity = $resolved;
    $this->setConnection($conn);
    $this->empty_props = ['_size', '_locked', '_watermarked', 'cleanup_attempts'];

    // Backwards-compatible static entry points use only an explicitly supplied
    // package connection. Instance operations never fall back to this value.
    static::useConnection($conn);
    FileToken::setup($conn);
    FileDefault::setup($conn);
    Folder::setup($conn);
    FolderFile::setup($conn);
  }

  /** Set a canonical relative suffix below {path_prefix}/{owner}. */
  public function setPath(string $path): static
  {
    if ($path === '') {
      $this->path_suffix = null;
      return $this;
    }
    $this->path_suffix = ObjectKey::fromString($path)->value();
    return $this;
  }

  public function privacy(string $value): static
  {
    $normalised = strtolower($value);
    if (!in_array($normalised, ['public', 'private'], true)) {
      throw new \InvalidArgumentException('File privacy must be public or private.');
    }
    $this->privacy = $normalised;
    return $this;
  }

  public function load(int|string $identifier): static
  {
    $file = is_string($identifier)
      && preg_match('/^' . self::CODE_PREFIX . '[0-9]{8,12}$/D', $identifier) === 1
        ? static::findByCode($identifier, $this->conn())
        : static::findById($identifier, $this->conn());

    if (!$file) {
      throw new \RuntimeException("No file found with identifier: [{$identifier}]");
    }
    $this->copyPersistentState($file);
    return $this;
  }

  public static function findByCode(string $code, ?SQLDatabase $conn = null): static|false
  {
    if (preg_match('/^' . self::CODE_PREFIX . '[0-9]{8,12}$/D', $code) !== 1) {
      return false;
    }
    $conn ??= static::_getStaticConnection();
    $row = DatabaseGateway::fetchOne($conn,
      'SELECT * FROM `' . static::$_db_name . '`.`file_meta` WHERE `code` = ? AND `lifecycle_state` <> ? LIMIT 1',
      [$code, 'deleted']
    );
    return is_array($row) ? static::_instantiateFromRow($row, $conn) : false;
  }

  public static function findById(int|string $id, ?SQLDatabase $conn = null): static|false
  {
    if (is_string($id) && preg_match('/^' . self::CODE_PREFIX . '[0-9]{8,12}$/D', $id) === 1) {
      return static::findByCode($id, $conn);
    }
    if (!is_int($id) && !ctype_digit($id)) {
      return false;
    }
    $conn ??= static::_getStaticConnection();
    $row = DatabaseGateway::fetchOne($conn,
      'SELECT * FROM `' . static::$_db_name . '`.`file_meta` WHERE `id` = ? AND `lifecycle_state` <> ? LIMIT 1',
      [(int)$id, 'deleted']
    );
    return is_array($row) ? static::_instantiateFromRow($row, $conn) : false;
  }

  /**
   * Register a local object that already exists beneath the configured root.
   * The source must resolve to the exact key supplied by storagePath + basename.
   */
  public function fromDisk(string $absolutePath, string $storagePath): static
  {
    if ($this->_storageDriverName() !== 'local') {
      throw new \LogicException('fromDisk() is available only for the local driver.');
    }
    $directory = ObjectKey::fromString($storagePath)->value();
    $key = ObjectKey::fromComponents([$directory, basename($absolutePath)]);
    $expected = $this->configuration->localPathResolver()->resolve($key);
    $actual = realpath($absolutePath);
    if ($actual === false || !is_file($actual) || !$this->sameFilesystemPath($expected, $actual)) {
      throw new \RuntimeException('The disk source does not belong to the configured local storage key.');
    }

    $extension = strtolower(pathinfo($key->basename(), PATHINFO_EXTENSION));
    [$mime, $group] = $this->_inspectType($actual, $extension);
    $size = filesize($actual);
    $checksum = hash_file('sha512', $actual);
    if ($size === false || $checksum === false) {
      throw new \RuntimeException('The local object metadata could not be observed.');
    }
    $this->_assertSize((int)$size);
    $this->_name = $key->basename();
    $this->_path = $key->directory();
    $this->object_key = $key->value();
    $this->_type = $mime;
    $this->_size = (int)$size;
    $this->_checksum = $checksum;
    $this->type_group = $group;
    $this->lifecycle_state = 'active';
    return $this;
  }

  /** Persist manually prepared metadata for an object that already exists. */
  public function create(): bool
  {
    if ($this->id !== null || $this->object_key === '' || $this->storage_driver !== $this->storage_identity) {
      $this->_userError('create', 'A new file requires a verified object key and no existing ID.');
      return false;
    }
    try {
      $key = $this->objectKeyValue();
      if (!$this->_resolveDriver()->exists($key)) {
        $this->_userError('create', 'The storage object does not exist.');
        return false;
      }
    } catch (StorageException $exception) {
      $this->_systemError('create', $exception->operation . ':' . $exception->driver);
      return false;
    }

    $attempts = (int)$this->configuration->get('code_retry_limit', 5);
    for ($attempt = 0; $attempt < $attempts; $attempt++) {
      $this->code = self::CODE_PREFIX . $this->_randomNumeric(12);
      if ($this->_create()) {
        return true;
      }
      if (!in_array((string)$this->conn()->lastErrorCode(), ['1062', '23000'], true)) {
        return false;
      }
      $this->id = null;
    }
    $this->_systemError('create', 'A unique file code could not be allocated within the retry limit.');
    return false;
  }

  public function update(): bool
  {
    if ($this->storage_driver !== $this->storage_identity) {
      $this->_userError('update', 'A file storage driver cannot be changed after construction or hydration.');
      return false;
    }
    if ($this->_locked || $this->lifecycle_state !== 'active') {
      $this->_userError('update', 'Only active, unlocked files may be updated.');
      return false;
    }
    if ($this->id === null || !in_array($this->privacy, ['public', 'private'], true)) {
      return false;
    }
    $db = static::$_db_name;
    return DatabaseGateway::execute(
      $this->conn(),
      "UPDATE `{$db}`.`file_meta`
          SET `nice_name` = ?, `caption` = ?, `privacy` = ?
        WHERE `id` = ? AND `lifecycle_state` = 'active' AND `_locked` = 0",
      [$this->nice_name, $this->caption, $this->privacy, $this->id]
    );
  }

  /** Preserve the Active Record entry point without bypassing hardened CRUD. */
  public function save(): bool
  {
    return $this->id === null ? $this->create() : $this->update();
  }

  /** Preserve the Active Record entry point while retaining cleanup state. */
  public function delete(): bool
  {
    return $this->destroy();
  }

  /** Compatibility setter limited to application-owned mutable fields. */
  public function setProp(string $prop, mixed $value): void
  {
    if ($prop === 'privacy' && is_string($value)) {
      $this->privacy($value);
      return;
    }
    if ($prop === 'nice_name' && is_string($value)) {
      $this->nice_name = $value;
      return;
    }
    if ($prop === 'caption' && (is_string($value) || $value === null)) {
      $this->caption = $value;
      return;
    }
    if ($prop === 'owner' && is_string($value) && $this->id === null) {
      $this->owner = $value;
      return;
    }
    if ($prop === 'type_group' && (is_string($value) || $value === null) && $this->id === null) {
      $this->type_group = $value;
    }
  }

  /**
   * Recoverable deletion: active/cleanup_required -> deleting -> deleted.
   * Deleted metadata is retained as the durable audit and retry boundary.
   */
  public function destroy(bool $force = false): bool
  {
    if ($this->id === null || $this->lifecycle_state === 'deleted') {
      return $this->lifecycle_state === 'deleted';
    }
    if ($this->storage_driver !== $this->storage_identity) {
      $this->_userError('destroy', 'A file storage driver cannot be changed after construction or hydration.');
      return false;
    }

    try {
      /** @var string $deletionState */
      $deletionState = $this->conn()->transaction(function (SQLDatabase $conn) use ($force): string {
        $db = static::$_db_name;
        $row = DatabaseGateway::fetchOne(
          $conn,
          "SELECT `lifecycle_state` FROM `{$db}`.`file_meta` WHERE `id` = ? FOR UPDATE",
          [$this->id]
        );
        if (!is_array($row)) {
          return 'missing';
        }
        $state = (string)($row['lifecycle_state'] ?? '');
        if ($state === 'deleted') {
          return 'deleted';
        }
        if (!in_array($state, ['active', 'cleanup_required', 'pending_upload'], true)) {
          return 'invalid_state';
        }
        if (!$force && is_array(DatabaseGateway::fetchOne(
          $conn,
          "SELECT `id` FROM `{$db}`.`file_default` WHERE `file_id` = ? LIMIT 1",
          [$this->id]
        ))) {
          return 'default_in_use';
        }
        if (DatabaseGateway::execute(
          $conn,
          "UPDATE `{$db}`.`file_meta` SET `lifecycle_state` = 'deleting'
            WHERE `id` = ? AND `lifecycle_state` = ?",
          [$this->id, $state]
        ) === false || $conn->affectedRows() !== 1) {
          throw new \RuntimeException('The deleting state transition did not affect exactly one row.');
        }
        return 'deleting';
      });
    } catch (\Throwable $exception) {
      $this->_systemError('destroy', $exception::class . ': ' . $exception->getMessage());
      return false;
    }
    if ($deletionState === 'deleted') {
      $this->lifecycle_state = 'deleted';
      return true;
    }
    if ($deletionState === 'default_in_use') {
      $this->_userError('destroy', 'The file is currently registered as a default.');
      return false;
    }
    if ($deletionState !== 'deleting') {
      $this->_systemError('destroy', 'The file could not enter the deleting state.');
      return false;
    }
    $this->lifecycle_state = 'deleting';

    try {
      $this->_resolveDriver()->delete($this->objectKeyValue());
    } catch (StorageException $exception) {
      $this->_markCleanupRequired('delete_storage_failure', $exception->operation);
      $this->_userError('destroy', 'The storage object could not be deleted.');
      return false;
    }

    try {
      $this->conn()->transaction(function (SQLDatabase $conn): void {
        $db = static::$_db_name;
        $tokenRows = DatabaseGateway::fetchAll(
          $conn,
          "SELECT `id` FROM `{$db}`.`file_tokens`
            WHERE `file_id` = ? AND `revoked_at` IS NULL FOR UPDATE",
          [$this->id]
        );
        $expectedTokens = is_array($tokenRows) ? count($tokenRows) : 0;
        if (!DatabaseGateway::execute(
          $conn,
          "UPDATE `{$db}`.`file_tokens` SET `revoked_at` = UTC_TIMESTAMP() WHERE `file_id` = ? AND `revoked_at` IS NULL",
          [$this->id]
        ) || $conn->affectedRows() !== $expectedTokens) {
          throw new \RuntimeException('Token revocation did not affect the expected rows.');
        }
        foreach (['folder_files', 'file_default'] as $table) {
          $rows = DatabaseGateway::fetchAll(
            $conn,
            "SELECT `id` FROM `{$db}`.`{$table}` WHERE `file_id` = ? FOR UPDATE",
            [$this->id]
          );
          $expected = is_array($rows) ? count($rows) : 0;
          if (!DatabaseGateway::execute(
            $conn,
            "DELETE FROM `{$db}`.`{$table}` WHERE `file_id` = ?",
            [$this->id]
          ) || $conn->affectedRows() !== $expected) {
            throw new \RuntimeException("{$table} cleanup did not affect the expected rows.");
          }
        }
        if (DatabaseGateway::execute($conn,
          "UPDATE `{$db}`.`file_meta`
             SET `lifecycle_state` = 'deleted', `deleted_at` = UTC_TIMESTAMP(),
                 `cleanup_required_at` = NULL, `cleanup_error_code` = NULL,
                 `cleanup_operation` = NULL
           WHERE `id` = ? AND `lifecycle_state` = 'deleting'",
          [$this->id]
        ) === false || $conn->affectedRows() !== 1) {
          throw new \RuntimeException('The deleted state transition did not affect exactly one row.');
        }
      });
    } catch (\Throwable $exception) {
      $this->_markCleanupRequired('delete_database_failure', 'relationship_cleanup');
      $this->_systemError('destroy', $exception::class . ': ' . $exception->getMessage());
      return false;
    }

    $this->lifecycle_state = 'deleted';
    $this->deleted_at = gmdate('Y-m-d H:i:s');
    return true;
  }

  public function retryCleanup(): bool
  {
    if ($this->lifecycle_state !== 'cleanup_required') {
      return $this->lifecycle_state === 'deleted';
    }
    return $this->destroy(force: true);
  }

  public function lock(): bool
  {
    if ($this->id === null || $this->lifecycle_state !== 'active') {
      return false;
    }
    try {
      $stream = $this->_resolveDriver()->readStream($this->objectKeyValue());
      $hash = hash_init('sha512');
      hash_update_stream($hash, $stream);
      fclose($stream);
      $checksum = hash_final($hash);
      $db = static::$_db_name;
      $result = DatabaseGateway::execute(
        $this->conn(),
        "UPDATE `{$db}`.`file_meta`
            SET `_checksum` = ?, `_locked` = 1
          WHERE `id` = ? AND `lifecycle_state` = 'active' AND `_locked` = 0",
        [$checksum, $this->id]
      );
      if (!$result || $this->conn()->affectedRows() !== 1) {
        return false;
      }
      $this->_checksum = $checksum;
      $this->_locked = true;
      return true;
    } catch (StorageException $exception) {
      $this->_systemError('lock', $exception->operation . ':' . $exception->driver);
      return false;
    }
  }

  /** Move the object to a new relative suffix beneath the same owner namespace. */
  public function moveTo(string $relativePath): bool
  {
    if ($this->id === null || $this->lifecycle_state !== 'active') {
      return false;
    }
    $suffix = ObjectKey::fromString($relativePath)->value();
    $newDirectory = ObjectKey::fromComponents([
      (string)$this->configuration->get('path_prefix', ''),
      $this->owner,
      $suffix,
    ])->value();
    $old = $this->objectKeyValue();
    $new = ObjectKey::fromComponents([$newDirectory, $this->_name]);

    try {
      $this->_resolveDriver()->move($old, $new);
      $db = static::$_db_name;
      $ok = DatabaseGateway::execute($this->conn(),
        "UPDATE `{$db}`.`file_meta` SET `_path` = ?, `object_key` = ? WHERE `id` = ? AND `object_key` = ?",
        [$new->directory(), $new->value(), $this->id, $old->value()]
      );
      if ($ok === false || $this->conn()->affectedRows() !== 1) {
        $this->_resolveDriver()->move($new, $old);
        $this->_systemError('move', 'The moved object metadata could not be committed.');
        return false;
      }
      $this->_path = $new->directory();
      $this->object_key = $new->value();
      return true;
    } catch (StorageException $exception) {
      $this->_systemError('move', $exception->operation . ':' . $exception->driver);
      return false;
    }
  }

  /** @deprecated Only local-driver files have filesystem paths. */
  public function fullPath(): string
  {
    if ($this->_storageDriverName() !== 'local') {
      throw new \LogicException('A remote storage object has no local filesystem path.');
    }
    return $this->configuration->localPathResolver()->resolve($this->objectKeyValue());
  }

  public function name(): string { return $this->_name; }
  public function path(): string { return $this->_path; }
  public function objectKey(): string { return $this->object_key; }
  public function size(): int { return $this->_size; }
  public function type(): string { return $this->_type; }
  public function checksum(): ?string { return $this->_checksum; }
  public function locked(): bool { return $this->_locked; }
  public function watermarked(): bool { return $this->_watermarked; }
  public function creator(): string { return $this->_creator; }
  public function lifecycleState(): string { return $this->lifecycle_state; }
  public function dbName(): string { return static::$_db_name; }
  public function tableName(): string { return static::$_table_name; }

  public function sizeAsText(): string
  {
    return match (true) {
      $this->_size < 1_024 => "{$this->_size} bytes",
      $this->_size < 1_048_576 => round($this->_size / 1_024) . ' KB',
      default => round($this->_size / 1_048_576, 1) . ' MB',
    };
  }

  /** @param array<string, mixed> $row */
  public static function _instantiateFromRow(array $row, ?SQLDatabase $conn = null): static
  {
    $conn ??= static::_getStaticConnection();
    $driver = is_string($row['storage_driver'] ?? null) ? $row['storage_driver'] : null;
    $instance = new static($conn, $driver);
    foreach (static::$_db_fields as $field) {
      if (!array_key_exists($field, $row) || !property_exists($instance, $field)) {
        continue;
      }
      $value = $row[$field];
      $instance->$field = match ($field) {
        'id' => $value === null ? null : (int)$value,
        '_size', 'cleanup_attempts' => (int)$value,
        '_locked', '_watermarked' => (bool)$value,
        default => $value,
      };
    }
    $instance->setConnection($conn);
    $instance->objectKeyValue(); // fail closed on untrusted persisted paths.
    return $instance;
  }

  protected function _configuration(): FileConfiguration
  {
    return $this->configuration;
  }

  protected function _storageDriverName(): string
  {
    if ($this->storage_driver !== $this->storage_identity) {
      throw new \LogicException('A file storage driver is immutable after construction or hydration.');
    }
    return $this->storage_identity;
  }

  protected function _buildStorageDirectory(): string
  {
    return ObjectKey::fromComponents(array_values(array_filter([
      (string)$this->configuration->get('path_prefix', ''),
      $this->owner,
      $this->path_suffix ?? '',
    ], static fn(string $part): bool => $part !== '')))->value();
  }

  protected function objectKeyValue(): ObjectKey
  {
    $key = ObjectKey::fromString($this->object_key);
    if ($this->_name !== '' && $key->basename() !== $this->_name) {
      throw new \RuntimeException('Persisted file name and object key do not match.');
    }
    if ($this->_path !== '' && $key->directory() !== $this->_path) {
      throw new \RuntimeException('Persisted file path and object key do not match.');
    }
    return $key;
  }

  protected function _transitionLifecycle(string $from, string $to): bool
  {
    if ($this->id === null) {
      return false;
    }
    $db = static::$_db_name;
    $result = DatabaseGateway::execute($this->conn(),
      "UPDATE `{$db}`.`file_meta` SET `lifecycle_state` = ? WHERE `id` = ? AND `lifecycle_state` = ?",
      [$to, $this->id, $from]
    );
    if ($result !== false && $this->conn()->affectedRows() === 1) {
      $this->lifecycle_state = $to;
      return true;
    }
    return false;
  }

  protected function _markCleanupRequired(string $errorCode, string $operation): void
  {
    if ($this->id === null) {
      return;
    }
    $errorCode = preg_replace('/[^a-z0-9_.-]/i', '_', $errorCode) ?: 'unknown';
    $operation = preg_replace('/[^a-z0-9_.-]/i', '_', $operation) ?: 'unknown';
    $db = static::$_db_name;
    if (DatabaseGateway::execute($this->conn(),
      "UPDATE `{$db}`.`file_meta`
          SET `lifecycle_state` = 'cleanup_required', `cleanup_required_at` = UTC_TIMESTAMP(),
              `cleanup_error_code` = ?, `cleanup_operation` = ?,
              `cleanup_attempts` = `cleanup_attempts` + 1
        WHERE `id` = ? AND `lifecycle_state` <> 'deleted'",
      [$errorCode, $operation, $this->id]
    ) !== false) {
      $this->lifecycle_state = 'cleanup_required';
      $this->cleanup_required_at = gmdate('Y-m-d H:i:s');
      $this->cleanup_error_code = $errorCode;
      $this->cleanup_operation = $operation;
      $this->cleanup_attempts++;
    }
  }

  protected function _persistObservedMetadata(): bool
  {
    if ($this->id === null) {
      return false;
    }
    $db = static::$_db_name;
    return DatabaseGateway::execute(
      $this->conn(),
      "UPDATE `{$db}`.`file_meta`
          SET `_size` = ?, `_checksum` = ?
        WHERE `id` = ? AND `lifecycle_state` = 'active' AND `_locked` = 0",
      [$this->_size, $this->_checksum, $this->id]
    );
  }

  /** Normalize PDO's numeric-string insert ID for the declared integer key. */
  protected function _create(): bool
  {
    try {
      return $this->_databaseObjectCreate();
    } catch (\TypeError $exception) {
      $id = DatabaseGateway::insertId($this->conn());
      if ($this->id === null && $id > 0 && str_contains($exception->getMessage(), '::$id')) {
        $this->id = $id;
        return true;
      }
      throw $exception;
    }
  }

  private function copyPersistentState(self $source): void
  {
    foreach (static::$_db_fields as $field) {
      if (property_exists($this, $field)) {
        $this->$field = $source->$field;
      }
    }
    $this->storage_identity = $source->storage_identity;
    // Ensure a loaded stored driver is still configured for this snapshot.
    $this->_resolveDriver();
  }

  private function sameFilesystemPath(string $left, string $right): bool
  {
    $left = str_replace('\\', '/', $left);
    $right = str_replace('\\', '/', $right);
    return DIRECTORY_SEPARATOR === '\\'
      ? strtolower($left) === strtolower($right)
      : $left === $right;
  }

}

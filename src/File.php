<?php
declare(strict_types=1);

namespace TimeFrontiers\File;

use TimeFrontiers\SQLDatabase;
use TimeFrontiers\File\FileConfig;
use TimeFrontiers\File\Concerns\FileData;
use TimeFrontiers\File\Concerns\ImageProcessor;
use TimeFrontiers\File\Concerns\Uploader;
use TimeFrontiers\File\Concerns\Downloader;
use TimeFrontiers\File\Concerns\Writer;
use TimeFrontiers\File\Concerns\Reader;

/**
 * Core file entity for timefrontiers/php-file.
 *
 * ── Bootstrap (once, in your app boot file) ─────────────────────────────────
 *
 *   File::configure(
 *       base: [
 *           'db_name'       => 'file',
 *           'upload_path'   => '/var/www/storage',
 *           'storage_url'   => 'https://cdn.example.com',
 *           'service_url'   => 'https://files.example.com',
 *           'token_secret'  => 'your-hmac-key',
 *           'max_size'      => 1024 * 1024 * 25,   // 25 MB
 *           'min_size'      => 0,
 *           'max_width_px'  => 2000,
 *           'max_height_px' => 2000,
 *           'min_width_px'  => null,
 *           'min_height_px' => null,
 *       ],
 *       driver: [
 *           'name'    => 'local',  // 'local' | 's3' | 'gcs' | 'onedrive' | 'dropbox'
 *           // S3:
 *           'bucket'  => '',
 *           'region'  => 'us-east-1',
 *           'key'     => '',
 *           'secret'  => '',
 *           'endpoint'=> null,
 *       ]
 *   );
 *
 * ── Upload ───────────────────────────────────────────────────────────────────
 *
 *   $file = new File($conn);
 *   $file->setPath('/User-Files/12345')
 *        ->privacy('private');
 *   $ok = $file->upload($_FILES['avatar'], userId: $userId);
 *
 * ── Load ─────────────────────────────────────────────────────────────────────
 *
 *   $file = (new File($conn))->load(42);          // by BIGINT id
 *   $file = (new File($conn))->load('583012345678901'); // by code
 *
 * ── Download token ───────────────────────────────────────────────────────────
 *
 *   $token = $file->createToken(expiresAt: '+24 hours', maxDownloads: 5, createdBy: $userId);
 *   $url   = $file->tokenUrl($token);
 *
 *   // On the download endpoint:
 *   $file = File::resolveToken($token) or abort(403);
 *   $file->download($token);          // inline
 *   $file->forceDownload($token);     // attachment
 *
 * ── Default files ────────────────────────────────────────────────────────────
 *
 *   FileDefault::set($conn, userId: $uid, setKey: 'avatar',  fileId: $file->id);
 *   FileDefault::set($conn, userId: $uid, setKey: 'gallery', fileId: $file->id, multiSet: true);
 *   $row = FileDefault::get($conn, userId: $uid, setKey: 'avatar');
 */
#[\AllowDynamicProperties]
class File
{
  use \TimeFrontiers\Helper\DatabaseObject {
    \TimeFrontiers\Helper\DatabaseObject::save as _traitSave;
  }
  use \TimeFrontiers\Helper\Pagination;
  use FileData;
  use ImageProcessor;
  use Uploader;
  use Downloader;
  use Writer;
  use Reader;

  // -------------------------------------------------------------------------
  // Constants
  // -------------------------------------------------------------------------

  /** Prefix for file_meta.code. */
  const CODE_PREFIX = '583';

  // -------------------------------------------------------------------------
  // DatabaseObject config
  // -------------------------------------------------------------------------

  protected static string $_primary_key = 'id';
  protected static string $_db_name     = '';
  protected static string $_table_name  = 'file_meta';
  protected static array  $_db_fields   = [
    'id', 'code', 'nice_name', 'type_group', 'caption',
    'owner', 'privacy', 'storage_driver', 'storage_bucket',
    '_name', '_path', '_type', '_size',
    '_checksum', '_locked', '_watermarked',
    '_creator', '_updated', '_created',
  ];

  // -------------------------------------------------------------------------
  // Properties
  // -------------------------------------------------------------------------

  // Public / application-settable
  public ?int    $id             = null;
  public ?string $code           = null;
  public string  $nice_name      = '';
  public ?string $type_group     = null;
  public ?string $caption        = null;
  public string  $owner          = '';       // user code, 'SYSTEM', 'SYSTEM.HIDDEN', etc.
  public string  $privacy        = 'public';
  public string  $storage_driver = 'local';
  public ?string $storage_bucket = null;

  // Storage details (set by driver / load())
  protected string  $_name        = '';
  protected string  $_path        = '';
  protected string  $_type        = '';
  protected int     $_size        = 0;
  protected ?string $_checksum    = null;
  protected bool    $_locked      = false;
  protected bool    $_watermarked = false;
  protected string  $_creator     = 'SYSTEM';
  protected ?string $_updated     = null;
  protected ?string $_created     = null;

  /**
   * Stored to allow _instantiateFromRow to work without a global.
   * Set on every __construct call.
   */
  protected static ?SQLDatabase $_static_conn = null;

  // -------------------------------------------------------------------------
  // Bootstrap (static — call once in app boot)
  // -------------------------------------------------------------------------

  /**
   * @param array<string, mixed> $base
   * @param array<string, mixed> $driver
   */
  public static function configure(array $base, array $driver = []): void
  {
    FileConfig::configure($base, $driver);
  }

  // -------------------------------------------------------------------------
  // Constructor
  // -------------------------------------------------------------------------

  public function __construct(SQLDatabase $conn, ?string $driverOverride = null)
  {
    FileConfig::requireConfigured();
    static::$_db_name    = FileConfig::get('db_name', 'file');
    static::$_static_conn = $conn;

    if ($driverOverride !== null) {
      $this->storage_driver = $driverOverride;
    } else {
      $this->storage_driver = FileConfig::driver('name', 'local');
    }

    $this->setConnection($conn);
    static::useConnection($conn);

    // Propagate db_name + connection to sibling entity classes so that
    // their static methods (e.g. FileToken::resolve()) work without
    // requiring a separate instantiation first.
    FileToken::setup($conn);
    FileDefault::setup($conn);
    Folder::setup($conn);
    FolderFile::setup($conn);
  }

  // -------------------------------------------------------------------------
  // Path (caller sets this before upload)
  // -------------------------------------------------------------------------

  /**
   * Set the relative storage path for this file.
   * e.g.  $file->setPath('/User-Files/08744307265')
   *
   * Full path on disk = upload_path + _path + '/' + _name
   */
  public function setPath(string $path): static
  {
    $this->_path = '/' . trim($path, '/');
    return $this;
  }

  // -------------------------------------------------------------------------
  // Privacy fluent setter
  // -------------------------------------------------------------------------

  public function privacy(string $value): static
  {
    $this->privacy = match (strtolower($value)) {
      'private' => 'private',
      default   => 'public',
    };
    return $this;
  }

  // -------------------------------------------------------------------------
  // Load
  // -------------------------------------------------------------------------

  /**
   * Load an existing file record by BIGINT id or 15-char code.
   *
   * @throws \RuntimeException if not found.
   */
  public function load(int|string $identifier): static
  {
    $file = is_int($identifier) || ctype_digit((string)$identifier)
      ? static::findById((int)$identifier)
      : static::findByCode((string)$identifier);

    if (!$file) {
      throw new \RuntimeException(
        "No file found with identifier: [{$identifier}]"
      );
    }

    foreach (get_object_vars($file) as $key => $value) {
      $this->$key = $value;
    }

    return $this;
  }

  /**
   * Find by the 15-char code (human-facing identifier).
   *
   * @return static|false
   */
  public static function findByCode(string $code): static|false
  {
    return static::query()->where('code', $code)->first() ?: false;
  }

  // -------------------------------------------------------------------------
  // CRUD
  // -------------------------------------------------------------------------

  /**
   * Manually create a file record (without going through upload()).
   * Useful when the file already exists in storage (e.g. seeding).
   */
  public function create(): bool
  {
    if (empty($this->code)) {
      $this->code = $this->_generateFileCode();
    }
    if (empty($this->storage_driver)) {
      $this->storage_driver = FileConfig::driver('name', 'local');
    }

    $result = $this->_create();

    if ($result && empty($this->id)) {
      $this->id = (int)$this->conn()->insertId();
    }

    return $result;
  }

  /**
   * Update mutable metadata columns.
   * Locked files cannot be updated.
   */
  public function update(): bool
  {
    if ($this->_locked) {
      $this->_userError('update', 'File is locked and cannot be updated.');
      return false;
    }

    return !empty($this->id) ? $this->_update() : false;
  }

  /**
   * Delete the file from storage and remove all associated records.
   *
   * Refuses to delete if the file is currently set as a default anywhere.
   * Call FileDefault::remove() first, or pass $force = true to bypass.
   */
  public function destroy(bool $force = false): bool
  {
    if (!$force && FileDefault::isInUse((int)$this->id)) {
      $this->_userError(
        'destroy',
        'File is set as a default somewhere. Remove it from defaults first, '
        . 'or call destroy(force: true).'
      );
      return false;
    }

    try {
      // Remove from storage
      $storagePath = rtrim($this->_path, '/') . '/' . $this->_name;
      $this->_resolveDriver()->delete($storagePath);

      // Remove from folder pivot
      FolderFile::removeFile($this->conn(), (int)$this->id);

      // Revoke all tokens
      FileToken::revokeForFile((int)$this->id);

      // Remove any default references
      $db = static::$_db_name;
      $this->conn()->execute(
        "DELETE FROM `{$db}`.`file_default` WHERE `file_id` = ?",
        [$this->id]
      );

      return $this->_delete();
    } catch (\Throwable $e) {
      $this->_systemError('destroy', "Failed to destroy file: {$e->getMessage()}");
      return false;
    }
  }

  // -------------------------------------------------------------------------
  // Lock
  // -------------------------------------------------------------------------

  /**
   * Compute SHA-512 checksum and mark file as locked.
   * Locked files cannot be updated or re-uploaded over.
   * Only works with local driver (checksum needs file on disk).
   */
  public function lock(): bool
  {
    if ($this->storage_driver === 'local') {
      $fullPath = $this->fullPath();
      if (file_exists($fullPath)) {
        $this->_checksum = hash_file('sha512', $fullPath);
      }
    }

    $this->_locked = true;
    return $this->_update();
  }

  // -------------------------------------------------------------------------
  // Path helpers
  // -------------------------------------------------------------------------

  /**
   * Full absolute path to the file on the local filesystem.
   * Only meaningful for the 'local' driver.
   */
  public function fullPath(): string
  {
    return FileConfig::uploadPath()
      . '/' . ltrim($this->_path . '/' . $this->_name, '/');
  }

  // -------------------------------------------------------------------------
  // Accessors
  // -------------------------------------------------------------------------

  public function name(): string      { return $this->_name; }
  public function path(): string      { return $this->_path; }
  public function size(): int         { return $this->_size; }
  public function type(): string      { return $this->_type; }
  public function checksum(): ?string { return $this->_checksum; }
  public function locked(): bool      { return (bool)$this->_locked; }
  public function watermarked(): bool { return (bool)$this->_watermarked; }
  public function creator(): string   { return $this->_creator; }
  public function dbName(): string    { return static::$_db_name; }
  public function tableName(): string { return static::$_table_name; }

  /**
   * Human-readable file size string.
   */
  public function sizeAsText(): string
  {
    return match (true) {
      $this->_size < 1_024           => "{$this->_size} bytes",
      $this->_size < 1_048_576       => round($this->_size / 1_024) . ' KB',
      default                        => round($this->_size / 1_048_576, 1) . ' MB',
    };
  }

  // -------------------------------------------------------------------------
  // DatabaseObject hook
  // -------------------------------------------------------------------------

  public static function _instantiateFromRow(array $row): static
  {
    $conn = static::$_static_conn
      ?? throw new \LogicException(
        'No static DB connection on File. Ensure new File($conn) was called first.'
      );

    $instance = new static($conn);
    foreach ($row as $key => $value) {
      if (!is_int($key)) {
        $
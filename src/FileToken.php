<?php
declare(strict_types=1);

namespace TimeFrontiers\File;

use TimeFrontiers\Data\Random;
use TimeFrontiers\Data\Signer;
use TimeFrontiers\SQLDatabase;

/**
 * Represents a single download token in the file_tokens table.
 *
 * Tokens are HMAC-SHA256 signed using TimeFrontiers\Data\Signer so that
 * the signature can be verified without a DB hit, and then the DB record
 * confirms expiry and download limits.
 *
 * Token prefix: 584
 */
#[\AllowDynamicProperties]
class FileToken
{
  use \TimeFrontiers\Helper\DatabaseObject;

  const CODE_PREFIX = '584';

  protected static string $_primary_key = 'id';
  protected static string $_db_name     = '';
  protected static string $_table_name  = 'file_tokens';
  protected static array  $_db_fields   = [
    'id', 'code', 'file_id', 'token',
    'expires_at', 'max_downloads', 'download_count',
    'created_by', '_created',
  ];

  public ?int    $id             = null;
  public ?string $code           = null;
  public ?int    $file_id        = null;
  public ?string $token          = null;
  public ?string $expires_at     = null;   // DATETIME string or null
  public ?int    $max_downloads  = null;
  public int     $download_count = 0;
  public string  $created_by     = 'SYSTEM';
  public ?string $_created       = null;

  // -------------------------------------------------------------------------
  // Bootstrap
  // -------------------------------------------------------------------------

  public function __construct(SQLDatabase $conn)
  {
    FileConfig::requireConfigured();
    static::$_db_name = FileConfig::get('db_name', 'file');
    $this->setConnection($conn);
    static::useConnection($conn);
  }

  /**
   * Static setup — called by File::__construct() so that static methods
   * (e.g. FileToken::resolve()) work before any FileToken instance is created.
   */
  public static function setup(SQLDatabase $conn): void
  {
    static::$_db_name = FileConfig::get('db_name', 'file');
    static::useConnection($conn);
  }

  // -------------------------------------------------------------------------
  // Factory: mint a new token
  // -------------------------------------------------------------------------

  /**
   * Create, persist, and return a new FileToken.
   *
   * @param int                                    $fileId
   * @param string|int|\DateTimeInterface|null     $expiresAt
   * @param int|null                               $maxDownloads
   * @param string                                 $createdBy
   */
  public static function mint(
    int $fileId,
    string|int|\DateTimeInterface|null $expiresAt = null,
    ?int $maxDownloads = null,
    string $createdBy = 'SYSTEM'
  ): static {
    $conn = static::$_connection
      ?? throw new \LogicException('No DB connection on FileToken. Ensure File::configure() was called.');

    $instance                 = new static($conn);
    $instance->file_id        = $fileId;
    $instance->max_downloads  = $maxDownloads;
    $instance->created_by     = $createdBy;
    $instance->expires_at     = $instance->_normaliseExpiry($expiresAt);
    $instance->token          = $instance->_generateToken();
    $instance->code           = $instance->_generateCode();

    $instance->_create();

    if (empty($instance->id)) {
      $instance->id = (int)$instance->conn()->insertId();
    }

    return $instance;
  }

  // -------------------------------------------------------------------------
  // Factory: resolve an incoming token string
  // -------------------------------------------------------------------------

  /**
   * Validate an incoming token string and return the model if valid.
   *
   * Returns false if:
   *   - HMAC signature is invalid
   *   - Token not found in DB
   *   - Token has expired
   *   - Download limit reached
   *
   * @return static|false
   */
  public static function resolve(string $tokenString): static|false
  {
    // 1. Verify HMAC signature
    Signer::setKey(FileConfig::get('token_secret', ''));
    $raw = Signer::verify($tokenString);

    if ($raw === false) {
      return false; // tampered or wrong key
    }

    // 2. Look up in DB by the raw token (stored pre-signature)
    /** @var static|false $model */
    $model = static::query()->where('token', $tokenString)->first();
    if (!$model) {
      return false;
    }

    // 3. Check expiry
    if ($model->expires_at !== null) {
      $expiresTs = strtotime($model->expires_at);
      if ($expiresTs !== false && $expiresTs < time()) {
        return false;
      }
    }

    // 4. Check download limit
    if ($model->max_downloads !== null
      && $model->download_count >= (int)$model->max_downloads) {
      return false;
    }

    return $model;
  }

  // -------------------------------------------------------------------------
  // Actions
  // -------------------------------------------------------------------------

  /**
   * Atomically increment the download counter.
   */
  public function incrementDownload(): bool
  {
    $sql = "UPDATE `{$this->_db_name}`.`file_tokens`
        SET `download_count` = `download_count` + 1
        WHERE `id` = ?";

    $result = $this->conn()->execute($sql, [$this->id]);
    if ($result) {
      $this->download_count++;
    }
    return $result;
  }

  /**
   * Hard-delete this token (revoke it).
   */
  public function revoke(): bool
  {
    return $this->_delete();
  }

  /**
   * Revoke all tokens for a given file_id.
   * Called by File::destroy() before deleting the file record.
   */
  public static function revokeForFile(int $fileId): bool
  {
    $conn = static::$_connection;
    if (!$conn) return false;

    $db = FileConfig::get('db_name', 'file');
    return $conn->execute(
      "DELETE FROM `{$db}`.`file_tokens` WHERE `file_id` = ?",
      [$fileId]
    );
  }

  // -------------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------------

  /**
   * Normalise various expiry representations to a DATETIME string or null.
   */
  private function _normaliseExpiry(mixed $value): ?string
  {
    if ($value === null) {
      return null;
    }

    if ($value instanceof \DateTimeInterface) {
      return $value->format('Y-m-d H:i:s');
    }

    if (is_int($value)) {
      return date('Y-m-d H:i:s', $value);
    }

    // String: either a datetime or a strtotime-compatible relative string
    $ts = strtotime((string)$value);
    if ($ts === false) {
      throw new \InvalidArgumentException(
        "Invalid expiresAt value: [{$value}]. Use a datetime string, Unix timestamp, or relative string like '+24 hours'."
      );
    }

    return date('Y-m-d H:i:s', $ts);
  }

  /**
   * Generate an HMAC-signed opaque token string (64 chars).
   * The raw payload is a 32-char random hex string; the signed version
   * is stored in the DB so resolve() can verify the signature directly.
   */
  private function _generateToken(): string
  {
    Signer::setKey(FileConfig::get('token_secret', ''));
    $payload = Random::hex(32);       // 32-char random hex
    return Signer::sign($payload);    // data--signature format
  }

  private function _generateCode(): string
  {
    $prefix = static::CODE_PREFIX;
    do {
      $code = $prefix . Random::numeric(12);
    } while (static::query()->where('code', $code)->exists());

    return $code;
  }

  public static function _instantiateFromRow(array $row): static
  {
    $conn = static::$_connection
    
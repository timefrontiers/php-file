<?php
declare(strict_types=1);

namespace TimeFrontiers\File;

use TimeFrontiers\File\Database\DatabaseGateway;
use TimeFrontiers\File\Security\TokenCodec;
use TimeFrontiers\SQLDatabase;

/** @phpstan-consistent-constructor */
class FileToken
{
  use \TimeFrontiers\Helper\DatabaseObject {
    _create as private _databaseObjectCreate;
  }

  public const CODE_PREFIX = '584';

  protected static string $_primary_key = 'id';
  protected static string $_db_name = '';
  protected static string $_table_name = 'file_tokens';
  /** @var list<string> */
  protected static array $_db_fields = [
    'id', 'code', 'file_id', 'token_digest', 'token_key_id',
    'expires_at', 'max_downloads', 'download_count', 'revoked_at',
    'created_by', '_created',
  ];

  public ?int $id = null;
  public ?string $code = null;
  public ?int $file_id = null;
  protected string $token_digest = '';
  protected string $token_key_id = '';
  public ?string $expires_at = null;
  public ?int $max_downloads = null;
  public int $download_count = 0;
  public ?string $revoked_at = null;
  public string $created_by = 'SYSTEM';
  public ?string $_created = null;

  private readonly FileConfiguration $configuration;

  public function __construct(SQLDatabase $conn)
  {
    FileConfig::requireConfigured();
    $this->configuration = FileConfig::snapshot();
    static::$_db_name = (string)$this->configuration->get('db_name', 'file');
    $this->setConnection($conn);
    static::useConnection($conn);
  }

  public static function setup(SQLDatabase $conn): void
  {
    FileConfig::requireConfigured();
    static::$_db_name = (string)FileConfig::get('db_name', 'file');
    static::useConnection($conn);
  }

  public static function mint(
    int $fileId,
    string|int|\DateTimeInterface|null $expiresAt = null,
    ?int $maxDownloads = null,
    string $createdBy = 'SYSTEM',
    ?SQLDatabase $conn = null
  ): IssuedFileToken {
    if ($fileId < 1) {
      throw new \InvalidArgumentException('A persisted file ID is required to mint a token.');
    }
    if ($maxDownloads !== null && $maxDownloads < 1) {
      throw new \InvalidArgumentException('maxDownloads must be null or a positive integer.');
    }
    if ($createdBy === '') {
      throw new \InvalidArgumentException('createdBy must not be empty.');
    }
    $conn ??= static::_getStaticConnection();
    $expiry = self::normaliseExpiry($expiresAt);

    /** @var IssuedFileToken $result */
    $result = $conn->transaction(function (SQLDatabase $conn) use (
      $fileId,
      $maxDownloads,
      $createdBy,
      $expiry
    ): IssuedFileToken {
      $instance = new static($conn);
      $db = (string)$instance->configuration->get('db_name', 'file');
      $file = DatabaseGateway::fetchOne(
        $conn,
        "SELECT `id` FROM `{$db}`.`file_meta`
          WHERE `id` = ? AND `lifecycle_state` = 'active' FOR UPDATE",
        [$fileId]
      );
      if (!is_array($file)) {
        throw new \RuntimeException('Tokens can be minted only for a persisted active file.');
      }

      $issued = (new TokenCodec($instance->configuration))->issue();
      $instance->file_id = $fileId;
      $instance->max_downloads = $maxDownloads;
      $instance->created_by = $createdBy;
      $instance->expires_at = $expiry;
      $instance->token_digest = $issued['digest'];
      $instance->token_key_id = $issued['key_id'];

      $attempts = (int)$instance->configuration->get('code_retry_limit', 5);
      $persisted = false;
      for ($attempt = 0; $attempt < $attempts; $attempt++) {
        $instance->code = self::CODE_PREFIX . self::randomNumeric(12);
        if ($instance->_create()) {
          $persisted = true;
          break;
        }
        if (!in_array((string)$conn->lastErrorCode(), ['1062', '23000'], true)) {
          break;
        }
        $instance->id = null;
      }
      if (!$persisted || $instance->id === null) {
        throw new \RuntimeException('The download token could not be persisted.');
      }

      return new IssuedFileToken($issued['bearer'], $instance);
    });
    return $result;
  }

  /** Validate without consuming; intended only to resolve the associated file. */
  public static function resolve(string $bearer, ?SQLDatabase $conn = null): static|false
  {
    $conn ??= static::_getStaticConnection();
    $configuration = FileConfig::snapshot();
    $inspected = (new TokenCodec($configuration))->inspect($bearer);
    if ($inspected === null) {
      return false;
    }
    $db = (string)$configuration->get('db_name', 'file');
    $row = DatabaseGateway::fetchOne($conn,
      "SELECT * FROM `{$db}`.`file_tokens`
        WHERE `token_key_id` = ? AND `token_digest` = ? AND `revoked_at` IS NULL
        LIMIT 1",
      [$inspected['key_id'], $inspected['digest']]
    );
    if (!is_array($row)) {
      return false;
    }
    $token = static::_instantiateFromRow($row, $conn);
    if ($token->expires_at !== null) {
      $expiry = new \DateTimeImmutable($token->expires_at, new \DateTimeZone('UTC'));
      if ($expiry <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
        return false;
      }
    }
    if ($token->max_downloads !== null && $token->download_count >= $token->max_downloads) {
      return false;
    }
    return $token;
  }

  /**
   * Atomically bind and consume a token immediately before response bytes begin.
   */
  public static function consume(string $bearer, int $fileId, SQLDatabase $conn): bool
  {
    if ($fileId < 1) {
      return false;
    }
    $configuration = FileConfig::snapshot();
    $inspected = (new TokenCodec($configuration))->inspect($bearer);
    if ($inspected === null) {
      return false;
    }
    $db = (string)$configuration->get('db_name', 'file');
    $result = DatabaseGateway::execute($conn,
      "UPDATE `{$db}`.`file_tokens` t
        INNER JOIN `{$db}`.`file_meta` f ON f.`id` = t.`file_id`
          SET t.`download_count` = t.`download_count` + 1
        WHERE t.`token_key_id` = ? AND t.`token_digest` = ? AND t.`file_id` = ?
          AND t.`revoked_at` IS NULL AND f.`lifecycle_state` = 'active'
          AND (t.`expires_at` IS NULL OR t.`expires_at` > UTC_TIMESTAMP())
          AND (t.`max_downloads` IS NULL OR t.`download_count` < t.`max_downloads`)",
      [$inspected['key_id'], $inspected['digest'], $fileId]
    );
    return $result !== false && $conn->affectedRows() === 1;
  }

  /** @deprecated Consumption requires the bearer and requested file binding. */
  public function incrementDownload(): bool
  {
    throw new \LogicException('Use FileToken::consume() with a bearer token and file ID.');
  }

  public function revoke(): bool
  {
    if ($this->id === null) {
      return false;
    }
    $database = (string)$this->configuration->get('db_name');
    $result = DatabaseGateway::execute($this->conn(),
      "UPDATE `{$database}`.`file_tokens`
          SET `revoked_at` = COALESCE(`revoked_at`, UTC_TIMESTAMP()) WHERE `id` = ?",
      [$this->id]
    );
    if ($result === false || ($this->conn()->affectedRows() !== 1 && $this->revoked_at === null)) {
      return false;
    }
    $this->revoked_at ??= gmdate('Y-m-d H:i:s');
    return true;
  }

  public function save(): bool
  {
    throw new \LogicException('Use FileToken::mint(); token records cannot be saved directly.');
  }

  public function delete(): bool
  {
    return $this->revoke();
  }

  public static function revokeForFile(int $fileId, ?SQLDatabase $conn = null): bool
  {
    $conn ??= static::_getStaticConnection();
    $db = (string)FileConfig::get('db_name', 'file');
    return DatabaseGateway::execute($conn,
      "UPDATE `{$db}`.`file_tokens`
          SET `revoked_at` = COALESCE(`revoked_at`, UTC_TIMESTAMP()) WHERE `file_id` = ?",
      [$fileId]
    ) !== false;
  }

  public function keyId(): string { return $this->token_key_id; }

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

  /** @param array<string, mixed> $row */
  public static function _instantiateFromRow(array $row, ?SQLDatabase $conn = null): static
  {
    $conn ??= static::_getStaticConnection();
    $instance = new static($conn);
    foreach (static::$_db_fields as $field) {
      if (!array_key_exists($field, $row) || !property_exists($instance, $field)) {
        continue;
      }
      $value = $row[$field];
      $instance->$field = match ($field) {
        'id', 'file_id', 'max_downloads' => $value === null ? null : (int)$value,
        'download_count' => (int)$value,
        default => $value,
      };
    }
    $instance->setConnection($conn);
    return $instance;
  }

  private static function normaliseExpiry(string|int|\DateTimeInterface|null $value): ?string
  {
    if ($value === null) {
      return null;
    }
    $utc = new \DateTimeZone('UTC');
    try {
      if ($value instanceof \DateTimeInterface) {
        $expiry = \DateTimeImmutable::createFromInterface($value)->setTimezone($utc);
      } elseif (is_int($value)) {
        $expiry = (new \DateTimeImmutable('@' . $value))->setTimezone($utc);
      } else {
        $expiry = new \DateTimeImmutable($value, $utc);
      }
    } catch (\Throwable $exception) {
      throw new \InvalidArgumentException('expiresAt is not a valid date, timestamp, or relative expression.', 0, $exception);
    }
    if ($expiry <= new \DateTimeImmutable('now', $utc)) {
      throw new \InvalidArgumentException('expiresAt must be strictly in the future.');
    }
    return $expiry->format('Y-m-d H:i:s');
  }

  private static function randomNumeric(int $length): string
  {
    $digits = '';
    while (strlen($digits) < $length) {
      $digits .= (string)random_int(0, 9);
    }
    return $digits;
  }
}

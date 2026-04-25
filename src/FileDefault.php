<?php
declare(strict_types=1);

namespace TimeFrontiers\File;

use TimeFrontiers\SQLDatabase;

/**
 * Maps a (user, set_key) to one or more files.
 *
 * multi_set = false  → single default per (user, set_key): old rows deleted before insert
 * multi_set = true   → multiple files per (user, set_key): new row appended, srt managed
 *
 * Examples:
 *   FileDefault::set($conn, userId: 1, setKey: 'avatar', fileId: 42);            // replace
 *   FileDefault::set($conn, userId: 1, setKey: 'gallery', fileId: 99, multi: true); // append
 *
 *   FileDefault::get($conn, userId: 1, setKey: 'avatar');   // → FileDefault|false
 *   FileDefault::getAll($conn, userId: 1, setKey: 'gallery'); // → FileDefault[]
 */
#[\AllowDynamicProperties]
class FileDefault
{
  use \TimeFrontiers\Helper\DatabaseObject;

  protected static string $_primary_key = 'id';
  protected static string $_db_name     = '';
  protected static string $_table_name  = 'file_default';
  protected static array  $_db_fields   = [
    'id', 'user', 'set_key', 'file_id', 'srt', '_updated',
  ];

  public ?int    $id       = null;
  public string  $user     = '';     // user code, system identifier, or any string owner value
  public ?string $set_key  = null;
  public ?int    $file_id  = null;
  public int     $srt      = 0;
  public ?string $_updated = null;

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

  /** Static setup — called by File::__construct(). */
  public static function setup(SQLDatabase $conn): void
  {
    static::$_db_name = FileConfig::get('db_name', 'file');
    static::useConnection($conn);
  }

  // -------------------------------------------------------------------------
  // Set (create or replace)
  // -------------------------------------------------------------------------

  /**
   * Set a file as default for a given user + set_key.
   *
   * @param SQLDatabase $conn
   * @param string      $user        User code, system identifier, or any string owner value.
   * @param string      $setKey      Context key, e.g. 'avatar', 'banner', 'gallery'.
   * @param int         $fileId      BIGINT id from file_meta.
   * @param bool        $multiSet    false = replace existing; true = append to list.
   * @param int         $srt         Sort position within the set (used when multiSet = true).
   * @return static|false
   */
  public static function set(
    SQLDatabase $conn,
    string $user,
    string $setKey,
    int $fileId,
    bool $multiSet = false,
    int $srt = 0
  ): static|false {
    $instance          = new static($conn);
    $instance->user    = $user;
    $instance->set_key = $setKey;
    $instance->file_id = $fileId;

    if (!$multiSet) {
      // Remove all existing defaults for this user+setKey before inserting
      static::clearSet($conn, $user, $setKey);
      $instance->srt = 0;
    } else {
      // Append: srt = max existing + 1 (or caller-supplied value)
      $instance->srt = $srt > 0 ? $srt : static::_nextSrt($conn, $user, $setKey);
    }

    if (!$instance->_create()) {
      return false;
    }

    if (empty($instance->id)) {
      $instance->id = (int)$instance->conn()->insertId();
    }

    return $instance;
  }

  // -------------------------------------------------------------------------
  // Get
  // -------------------------------------------------------------------------

  /**
   * Return the single default file row for a user+setKey.
   * Returns the lowest-srt row if multiple exist.
   *
   * @return static|false
   */
  public static function get(SQLDatabase $conn, string $user, string $setKey): static|false
  {
    static::useConnection($conn);
    return static::query()
      ->where('user', $user)
      ->where('set_key', $setKey)
      ->orderBy('srt', 'ASC')
      ->first()
      ?: false;
  }

  /**
   * Return all default file rows for a user+setKey, ordered by srt.
   *
   * @return static[]
   */
  public static function getAll(SQLDatabase $conn, string $user, string $setKey): array
  {
    static::useConnection($conn);
    return static::query()
      ->where('user', $user)
      ->where('set_key', $setKey)
      ->orderBy('srt', 'ASC')
      ->get();
  }

  // -------------------------------------------------------------------------
  // Remove
  // -------------------------------------------------------------------------

  /**
   * Remove a specific file from a user's set.
   */
  public static function remove(SQLDatabase $conn, string $user, string $setKey, int $fileId): bool
  {
    $db = FileConfig::get('db_name', 'file');
    return $conn->execute(
      "DELETE FROM `{$db}`.`file_default`
       WHERE `user` = ? AND `set_key` = ? AND `file_id` = ?",
      [$user, $setKey, $fileId]
    );
  }

  /**
   * Remove all defaults for a user+setKey.
   */
  public static function clearSet(SQLDatabase $conn, string $user, string $setKey): bool
  {
    $db = FileConfig::get('db_name', 'file');
    return $conn->execute(
      "DELETE FROM `{$db}`.`file_default` WHERE `user` = ? AND `set_key` = ?",
      [$user, $setKey]
    );
  }

  // -------------------------------------------------------------------------
  // Safety check (used by File::destroy)
  // -------------------------------------------------------------------------

  /**
   * Return true if the given file_id is currently set as a default anywhere.
   */
  public static function isInUse(int $fileId): bool
  {
    return static::query()->where('file_id', $fileId)->exists();
  }

  // -------------------------------------------------------------------------
  // Private helpers
  // -------------------------------------------------------------------------

  private static function _nextSrt(SQLDatabase $conn, string $user, string $setKey): int
  {
    $db  = FileConfig::get('db_name', 'file');
    $row = $conn->fetchOne(
      "SELECT MAX(`srt`) AS max_srt FROM `{$db}`.`file_default`
       WHERE `user` = ? AND `set_key` = ?",
      [$user, $setKey]
    );

    return (int)($row['max_srt'] ?? -1) + 1;
  }

  // -------------------------------------------------------------------------
  // DatabaseObject hook
  // -------------------------------------------------------------------------

  public static function _instantiateFromRow(array $row): static
  {
    $conn = static::$_co
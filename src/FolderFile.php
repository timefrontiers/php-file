<?php
declare(strict_types=1);

namespace TimeFrontiers\File;

use TimeFrontiers\SQLDatabase;

/**
 * Pivot: which files belong to which folder.
 * Table: file.folder_files
 */
#[\AllowDynamicProperties]
class FolderFile
{
  use \TimeFrontiers\Helper\DatabaseObject;

  protected static string $_primary_key = 'id';
  protected static string $_db_name     = '';
  protected static string $_table_name  = 'folder_files';
  protected static array  $_db_fields   = [
    'id', 'folder_id', 'file_id', '_created',
  ];

  public ?int    $id        = null;
  public ?int    $folder_id = null;
  public ?int    $file_id   = null;
  public ?string $_created  = null;

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
  // Pivot helpers
  // -------------------------------------------------------------------------

  /**
   * Add a file to a folder. Silently skips if the pair already exists.
   */
  public static function add(SQLDatabase $conn, int $folderId, int $fileId): static|false
  {
    static::useConnection($conn);

    // Prevent duplicates
    $exists = static::query()
      ->where('folder_id', $folderId)
      ->where('file_id', $fileId)
      ->exists();

    if ($exists) {
      return static::query()
        ->where('folder_id', $folderId)
        ->where('file_id', $fileId)
        ->first();
    }

    $instance            = new static($conn);
    $instance->folder_id = $folderId;
    $instance->file_id   = $fileId;

    if (!$instance->_create()) {
      return false;
    }

    if (empty($instance->id)) {
      $instance->id = (int)$instance->conn()->insertId();
    }

    return $instance;
  }

  /**
   * Remove a specific file from a folder.
   */
  public static function remove(SQLDatabase $conn, int $folderId, int $fileId): bool
  {
    $db = FileConfig::get('db_name', 'file');
    return $conn->execute(
      "DELETE FROM `{$db}`.`folder_files` WHERE `folder_id` = ? AND `file_id` = ?",
      [$folderId, $fileId]
    );
  }

  /**
   * Remove all files from a folder (used when deleting a folder).
   */
  public static function clearFolder(SQLDatabase $conn, int $folderId): bool
  {
    $db = FileConfig::get('db_name', 'file');
    return $conn->execute(
      "DELETE FROM `{$db}`.`folder_files` WHERE `folder_id` = ?",
      [$folderId]
    );
  }

  /**
   * Remove a file from all folders (used when deleting a file).
   */
  public static function removeFile(SQLDatabase $conn, int $fileId): bool
  {
    $db = FileConfig::get('db_name', 'file');
    return $conn->execute(
      "DELETE FROM `{$db}`.`folder_files` WHERE `file_id` = ?",
      [$fileId]
    );
  }

  /**
   * Return all pivot rows for a folder.
   *
   * @return static[]
   */
  public static function forFolder(SQLDatabase $conn, int $folderId): array
  {
    static::useConnection($conn);
    return static::query()
      ->where('folder_id', $folderId)
      ->orderBy('_created', 'ASC')
      ->get();
  }

  // -------------------------------------------------------------------------
  // DatabaseObject hook
  // -------------------------------------------------------------------------

  public static function _instantiateFromRow(array $row): static
  {
    $conn = static::$_connection
      ?? throw new \LogicException('No DB connection for FolderFile in
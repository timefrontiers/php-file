<?php
declare(strict_types=1);

namespace TimeFrontiers\File;

use TimeFrontiers\SQLDatabase;

/**
 * Represents a named folder that groups files for a user.
 * Table: file.folders
 */
#[\AllowDynamicProperties]
class Folder
{
  use \TimeFrontiers\Helper\DatabaseObject;

  protected static string $_primary_key = 'id';
  protected static string $_db_name     = '';
  protected static string $_table_name  = 'folders';
  protected static array  $_db_fields   = [
    'id', 'name', 'title', 'owner', '_author', '_created',
  ];

  public ?int    $id       = null;
  public string  $name     = '';      // URL-safe slug
  public string  $title    = '';
  public string  $owner    = '';      // user code, system identifier, or any string owner value
  public string  $_author  = 'SYSTEM';
  public ?string $_created = null;

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
  // CRUD helpers
  // -------------------------------------------------------------------------

  public function create(): bool
  {
    $result = $this->_create();
    if ($result && empty($this->id)) {
      $this->id = (int)$this->conn()->insertId();
    }
    return $result;
  }

  public function update(): bool
  {
    return !empty($this->id) ? $this->_update() : false;
  }

  public function destroy(): bool
  {
    // Remove all pivot rows first, then delete the folder
    FolderFile::clearFolder($this->conn(), (int)$this->id);
    return $this->_delete();
  }

  // -------------------------------------------------------------------------
  // Query helpers
  // -------------------------------------------------------------------------

  /**
   * Return all folders for an owner.
   *
   * @return static[]
   */
  public static function forOwner(SQLDatabase $conn, string $owner): array
  {
    static::useConnection($conn);
    return static::query()
      ->where('owner', $owner)
      ->orderBy('title', 'ASC')
      ->get();
  }

  // -------------------------------------------------------------------------
  // DatabaseObject hook
  // -------------------------------------------------------------------------

  public static function _instantiateFromRow(array $row): static
  {
    $conn = static::$_connection
      
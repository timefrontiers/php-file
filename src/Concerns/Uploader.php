<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Concerns;

use TimeFrontiers\Data\Random;
use TimeFrontiers\File\FileConfig;
use TimeFrontiers\File\Drivers\StorageDriverInterface;
use TimeFrontiers\File\Drivers\LocalDriver;
use TimeFrontiers\File\Drivers\AwsS3Driver;
use TimeFrontiers\File\Drivers\GoogleGcsDriver;
use TimeFrontiers\File\Drivers\OneDriveDriver;
use TimeFrontiers\File\Drivers\DropboxDriver;

/**
 * File upload and storage dispatch logic.
 *
 * Depends on (resolved via the host class):
 *   - FileData   — mimeForExtension(), groupForMime(), isImage()
 *   - ImageProcessor — validateImageDimensions(), constrainImageSize()
 *   - HasErrors  — _userError(), _systemError(), hasErrors()
 *   - DatabaseObject — _create(), _update(), query()
 *
 * Usage:
 *   $file = new File($conn);
 *   $file->setPath('/User-Files/12345');
 *   $file->owner    = $ownerCode;
 *   $file->nice_name = 'My Avatar';
 *   $result = $file->upload($_FILES['avatar']);
 */
trait Uploader
{
  // -------------------------------------------------------------------------
  // State
  // -------------------------------------------------------------------------

  private string $_temp_path = '';
  private string $_extension = '';
  public  bool   $over_write  = false;

  protected static array $_upload_errors = [
    UPLOAD_ERR_OK         => 'No errors.',
    UPLOAD_ERR_INI_SIZE   => 'Larger than upload_max_filesize.',
    UPLOAD_ERR_FORM_SIZE  => 'Larger than form MAX_FILE_SIZE.',
    UPLOAD_ERR_PARTIAL    => 'Partial upload.',
    UPLOAD_ERR_NO_FILE    => 'No file.',
    UPLOAD_ERR_NO_TMP_DIR => 'No temporary directory.',
    UPLOAD_ERR_CANT_WRITE => "Can't write to disk.",
    UPLOAD_ERR_EXTENSION  => 'File upload stopped by extension.',
  ];

  // -------------------------------------------------------------------------
  // Public API
  // -------------------------------------------------------------------------

  /**
   * Upload a file from a $_FILES array entry.
   * setPath() must be called before this.
   *
   * @param array       $file    A single $_FILES['field'] entry.
   * @param string|null $owner   Optional: owner string (user code, 'SYSTEM', etc.).
   * @param string      $creator Optional: identity string for _creator column.
   */
  public function upload(array $file, ?string $owner = null, string $creator = 'SYSTEM'): bool
  {
    FileConfig::requireConfigured();

    if (empty($this->_path)) {
      throw new \RuntimeException(
        'Upload path is not set. Call setPath() before upload().'
      );
    }

    if ($owner !== null) {
      $this->owner = $owner;
    }

    if (empty($this->_creator) || $this->_creator === 'SYSTEM') {
      $this->_creator = $creator;
    }

    if (!$this->_attachFile($file)) {
      return false;
    }

    // Reject images that are too small
    if ($this->isImage() && !$this->validateImageDimensions($this->_temp_path)) {
      return false;
    }

    return $this->_saveUpload();
  }

  // -------------------------------------------------------------------------
  // Internal: attach + validate the raw $_FILES entry
  // -------------------------------------------------------------------------

  private function _attachFile(array $file): bool
  {
    if (empty($file) || !is_array($file)) {
      $this->_userError('upload', 'No file was provided.');
      return false;
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      $errCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
      $this->_userError(
        'upload',
        static::$_upload_errors[$errCode] ?? 'Unknown upload error.'
      );
      return false;
    }

    $size    = (int)($file['size'] ?? 0);
    $maxSize = (int)FileConfig::get('max_size', 26_214_400);
    $minSize = (int)FileConfig::get('min_size', 0);

    if ($size > $maxSize) {
      $maxMb = round($maxSize / 1_048_576, 1);
      $this->_userError('upload', "File exceeds the maximum allowed size of {$maxMb} MB.");
      return false;
    }

    if ($minSize > 0 && $size < $minSize) {
      $minKb = round($minSize / 1024);
      $this->_userError('upload', "File is smaller than the minimum allowed size of {$minKb} KB.");
      return false;
    }

    $originalName = (string)($file['name'] ?? '');
    $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $mime         = static::mimeForExtension($ext)
            ?? mime_content_type((string)($file['tmp_name'] ?? ''))
            ?: null;

    if ($mime === null || static::mimeForExtension($ext) === null) {
      $this->_userError('upload', "File type [{$ext}] is not permitted.");
      return false;
    }

    $this->_temp_path  = (string)($file['tmp_name'] ?? '');
    $this->_type       = $mime;
    $this->_size       = $size;
    $this->_extension  = $ext;
    $this->type_group  = static::groupForMime($mime);
    $this->caption     = !empty($this->caption)
      ? $this->caption
      : pathinfo($originalName, PATHINFO_FILENAME);
    $this->nice_name   = !empty($this->nice_name)
      ? $this->nice_name
      : pathinfo($originalName, PATHINFO_FILENAME) . ".{$ext}";

    return true;
  }

  // -------------------------------------------------------------------------
  // Internal: constrain, dispatch to driver, persist
  // -------------------------------------------------------------------------

  private function _saveUpload(): bool
  {
    if (isset($this->id)) {
      // Existing record — only update metadata columns
      return $this->_update();
    }

    if ($this->hasErrors()) {
      return false;
    }

    if (empty($this->_temp_path)) {
      $this->_userError('upload', 'Temp file path is unavailable.');
      return false;
    }

    // Resize image if it exceeds max dimensions (modifies temp file in-place)
    if ($this->isImage()) {
      $this->constrainImageSize($this->_temp_path);
      // Re-read size after potential resize
      clearstatcache(true, $this->_temp_path);
      $this->_size = (int)filesize($this->_temp_path);
    }

    // Generate unique storage filename
    $uniqueName  = strtolower(Random::hex(20)) . '.' . $this->_extension;
    $storagePath = rtrim($this->_path, '/') . '/' . $uniqueName;

    // Dispatch to driver
    $driver = $this->_resolveDriver();
    if (!$driver->upload($this->_temp_path, $storagePath)) {
      $this->_userError('upload', 'File could not be moved to storage.');
      return false;
    }

    $this->_name          = $uniqueName;
    $this->storage_driver = FileConfig::driver('name', 'local');
    $this->storage_bucket = FileConfig::driver('bucket');
    $this->code           = $this->_generateFileCode();

    if (!$this->_create()) {
      // Rollback — remove from storage
      $driver->delete($storagePath);
      $this->_systemError('upload', 'File record could not be saved to database.');
      return false;
    }

    // Capture AUTO_INCREMENT id if the trait didn't set it
    if (empty($this->id)) {
      $this->id = (int)$this->conn()->insertId();
    }

    unset($this->_temp_path);
    return true;
  }

  // -------------------------------------------------------------------------
  // Driver resolution
  // -------------------------------------------------------------------------

  /**
   * Build the correct driver for the active (or overriding) storage_driver value.
   */
  protected function _resolveDriver(?string $override = null): StorageDriverInterface
  {
    $name = $override ?? $this->storage_driver ?? FileConfig::driver('name', 'local');

    return match ($name) {
      's3'       => new AwsS3Driver(),
      'gcs'      => new GoogleGcsDriver(),
      'onedrive' => new OneDriveDriver(),
      'dropbox'  => new DropboxDriver(),
      default    => new LocalDriver(),
    };
  }

  // -------------------------------------------------------------------------
  // Code generation
  // -------------------------------------------------------------------------

  private function _generateFileCode(): string
  {
    $prefix = static::CODE_PREFIX;  // '583', defined on File
    do {
      $code = $prefix . Random::numeric(12); // 3 + 12 = 15 chars
    } while (static::query()->where('code', $code)->exists());

   
<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Concerns;

use TimeFrontiers\File\Drivers\StorageDriverInterface;
use TimeFrontiers\File\Exceptions\StorageException;
use TimeFrontiers\File\FileConfig;
use TimeFrontiers\File\Storage\ObjectKey;

trait Uploader
{
  /** @var array<string, StorageDriverInterface> */
  private array $_driver_cache = [];
  public bool $over_write = false;

  /** @var array<int, string> */
  protected static array $_upload_errors = [
    UPLOAD_ERR_OK => 'No errors.',
    UPLOAD_ERR_INI_SIZE => 'The upload exceeds the server size limit.',
    UPLOAD_ERR_FORM_SIZE => 'The upload exceeds the form size limit.',
    UPLOAD_ERR_PARTIAL => 'The upload was incomplete.',
    UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
    UPLOAD_ERR_NO_TMP_DIR => 'The server upload directory is unavailable.',
    UPLOAD_ERR_CANT_WRITE => 'The server could not write the upload.',
    UPLOAD_ERR_EXTENSION => 'A server extension rejected the upload.',
  ];

  /** Accept only a genuine PHP HTTP upload. */
  /** @param array<string, mixed> $file */
  public function upload(array $file, ?string $owner = null, string $creator = 'SYSTEM'): bool
  {
    if ($this->id !== null) {
      $this->_userError('upload', 'An existing file record cannot be overwritten through upload().');
      return false;
    }
    $rawError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    $error = is_int($rawError) ? $rawError : (is_string($rawError) && ctype_digit($rawError) ? (int)$rawError : null);
    if ($error === null || $error !== UPLOAD_ERR_OK) {
      $this->_userError('upload', $error === null
        ? 'The HTTP upload is malformed.'
        : (static::$_upload_errors[$error] ?? 'The HTTP upload failed with an unknown error.'));
      return false;
    }
    $tmpPath = $file['tmp_name'] ?? null;
    $originalName = $file['name'] ?? null;
    if (!is_string($tmpPath) || $tmpPath === '' || !$this->_isHttpUploadedFile($tmpPath)) {
      $this->_userError('upload', 'The file was not received through PHP HTTP upload handling.');
      return false;
    }
    if (!is_string($originalName) || $originalName === '') {
      $this->_userError('upload', 'The uploaded filename is missing.');
      return false;
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $originalName) === 1) {
      $this->_userError('upload', 'The uploaded filename contains invalid control characters.');
      return false;
    }

    return $this->_ingest($tmpPath, $originalName, $owner, $creator);
  }

  /** Explicit API for a trusted application-owned local source file. */
  public function import(
    string $sourcePath,
    ?string $originalName = null,
    ?string $owner = null,
    string $creator = 'SYSTEM'
  ): bool {
    if ($this->id !== null) {
      $this->_userError('import', 'An existing file record cannot be overwritten through import().');
      return false;
    }
    if (!is_file($sourcePath) || is_link($sourcePath) || !is_readable($sourcePath)) {
      $this->_userError('import', 'The trusted import source is unavailable.');
      return false;
    }

    return $this->_ingest($sourcePath, $originalName ?? basename($sourcePath), $owner, $creator);
  }

  private function _ingest(
    string $sourcePath,
    string $originalName,
    ?string $owner,
    string $creator
  ): bool {
    FileConfig::requireConfigured();
    $configuration = $this->_configuration();
    if ($owner !== null) {
      $this->owner = $owner;
    }
    if ($this->owner === '' || str_contains($this->owner, '/')) {
      $this->_userError('upload', 'A single, relative owner identifier is required.');
      return false;
    }
    try {
      ObjectKey::fromString($this->owner);
    } catch (\InvalidArgumentException $exception) {
      $this->_userError('upload', 'The owner identifier is not safe for storage.');
      return false;
    }

    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (preg_match('/^[a-z0-9]{1,10}$/D', $extension) !== 1) {
      $this->_userError('upload', 'The file extension is missing or invalid.');
      return false;
    }

    $staged = null;
    try {
      $staged = $this->_stageSource($sourcePath, $extension);
      [$mime, $group] = $this->_inspectType($staged, $extension);
      $this->_type = $mime;
      $this->type_group = $group;

      if ($this->isImage()) {
        $this->inspectImage($staged, $configuration);
        $this->constrainImageSize($staged, $configuration);
      }

      // Server-observed metadata is recalculated after every transformation.
      clearstatcache(true, $staged);
      $size = filesize($staged);
      if ($size === false) {
        throw new \RuntimeException('The transformed upload size could not be observed.');
      }
      $this->_assertSize((int)$size);
      [$finalMime, $finalGroup] = $this->_inspectType($staged, $extension);
      if ($this->isImage()) {
        $this->inspectImage($staged, $configuration);
      }

      $checksum = hash_file('sha512', $staged);
      if ($checksum === false) {
        throw new \RuntimeException('The transformed upload checksum could not be calculated.');
      }

      $this->_type = $finalMime;
      $this->type_group = $finalGroup;
      $this->_size = (int)$size;
      $this->_checksum = $checksum;
      $this->_creator = $creator !== '' ? $creator : 'SYSTEM';
      $safeBase = pathinfo(basename(str_replace(["\r", "\n"], '', $originalName)), PATHINFO_FILENAME);
      $this->caption = $this->caption !== null && $this->caption !== '' ? $this->caption : $safeBase;
      $this->nice_name = $this->nice_name !== '' ? $this->nice_name : $safeBase . '.' . $extension;

      return $this->_persistVerifiedUpload($staged, $extension);
    } catch (StorageException $exception) {
      $this->_userError('upload', 'The file could not be stored.');
      $this->_systemError('upload', json_encode([
        'driver' => $exception->driver,
        'operation' => $exception->operation,
        'context' => $exception->context,
      ], JSON_THROW_ON_ERROR));
      return false;
    } catch (\Throwable $exception) {
      $this->_userError('upload', $exception instanceof \InvalidArgumentException
        ? $exception->getMessage()
        : 'The file failed server-side verification.');
      $this->_systemError('upload', $exception::class . ': ' . $exception->getMessage());
      return false;
    } finally {
      if ($staged !== null && is_file($staged)) {
        @unlink($staged);
      }
    }
  }

  private function _stageSource(string $sourcePath, string $extension): string
  {
    $max = (int)$this->_configuration()->get('max_size');
    $base = tempnam(sys_get_temp_dir(), 'tf-file-');
    if ($base === false) {
      throw new \RuntimeException('A secure staging file could not be created.');
    }
    $staged = $base . '.' . $extension;
    if (!@rename($base, $staged)) {
      @unlink($base);
      throw new \RuntimeException('The secure staging file could not be prepared.');
    }

    try {
      $input = @fopen($sourcePath, 'rb');
      $output = @fopen($staged, 'wb');
      if (!is_resource($input) || !is_resource($output)) {
        if (is_resource($input)) fclose($input);
        if (is_resource($output)) fclose($output);
        throw new \RuntimeException('The upload source could not be staged.');
      }
      try {
        $copied = stream_copy_to_stream($input, $output, $max + 1);
        if ($copied === false || !fflush($output)) {
          throw new \RuntimeException('The upload source could not be staged completely.');
        }
      } finally {
        fclose($input);
        fclose($output);
      }
      $this->_assertSize((int)$copied);
      return $staged;
    } catch (\Throwable $exception) {
      @unlink($staged);
      throw $exception;
    }
  }

  private function _assertSize(int $size): void
  {
    $configuration = $this->_configuration();
    $minimum = (int)$configuration->get('min_size');
    $maximum = (int)$configuration->get('max_size');
    if ($size < $minimum) {
      throw new \InvalidArgumentException('The file is smaller than the configured minimum size.');
    }
    if ($size > $maximum) {
      throw new \InvalidArgumentException('The file exceeds the configured maximum size.');
    }
  }

  /** @return array{string, string} */
  private function _inspectType(string $path, string $extension): array
  {
    $configuration = $this->_configuration();
    $extension = strtolower($extension);
    $deniedExtensions = array_map('strtolower', (array)$configuration->get('denied_extensions', []));
    if (in_array($extension, $deniedExtensions, true)) {
      throw new \InvalidArgumentException("File type {$extension} is not permitted.");
    }
    $allowedExtensions = array_map('strtolower', (array)$configuration->get('allowed_extensions', []));
    if ($allowedExtensions !== [] && !in_array($extension, $allowedExtensions, true)) {
      throw new \InvalidArgumentException("File type {$extension} is not in the configured allowlist.");
    }

    $expectedMime = static::mimeForExtension($extension);
    if ($expectedMime === null) {
      throw new \InvalidArgumentException("File type {$extension} is not recognized.");
    }
    $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!is_string($detected) || $detected === '') {
      throw new \RuntimeException('The file content type could not be detected.');
    }
    $detected = strtolower(trim(explode(';', $detected, 2)[0]));
    $logicalMime = $this->_matchingLogicalMime($path, $extension, $expectedMime, $detected);

    $deniedMimes = array_map('strtolower', (array)$configuration->get('denied_mime_types', []));
    if (in_array($detected, $deniedMimes, true) || in_array($logicalMime, $deniedMimes, true)) {
      throw new \InvalidArgumentException('The detected file content is not permitted.');
    }
    $group = static::groupForMime($logicalMime);
    if ($group === null || $group === 'script' || $group === 'flash-video') {
      throw new \InvalidArgumentException('Executable and script-like file content is not permitted.');
    }
    $allowedMimes = array_map('strtolower', (array)$configuration->get('allowed_mime_types', []));
    if ($allowedMimes !== [] && !in_array($logicalMime, $allowedMimes, true)) {
      throw new \InvalidArgumentException('The detected content type is not in the configured allowlist.');
    }

    return [$logicalMime, $group];
  }

  private function _matchingLogicalMime(
    string $path,
    string $extension,
    string $expected,
    string $detected
  ): string {
    if ($detected === strtolower($expected)) {
      return strtolower($expected);
    }
    $aliases = [
      'csv' => ['text/plain', 'application/csv'],
      'txt' => ['application/octet-stream'],
      'xml' => ['text/xml'],
      'rar' => ['application/vnd.rar'],
    ];
    if (in_array($detected, $aliases[$extension] ?? [], true)) {
      return strtolower($expected);
    }

    $containers = ['docx' => 'word/', 'xlsx' => 'xl/', 'pptx' => 'ppt/'];
    if (isset($containers[$extension]) && $detected === 'application/zip') {
      if (!class_exists(\ZipArchive::class)) {
        throw new \RuntimeException('ZIP support is required to verify this document format.');
      }
      $archive = new \ZipArchive();
      if ($archive->open($path) !== true) {
        throw new \InvalidArgumentException('The document container is malformed.');
      }
      try {
        if ($archive->locateName('[Content_Types].xml') === false) {
          throw new \InvalidArgumentException('The document container has no content-type manifest.');
        }
        $prefix = $containers[$extension];
        $found = false;
        for ($index = 0; $index < $archive->numFiles; $index++) {
          $name = $archive->getNameIndex($index);
          if (is_string($name) && str_starts_with($name, $prefix)) {
            $found = true;
            break;
          }
        }
        if (!$found) {
          throw new \InvalidArgumentException('The document container does not match its extension.');
        }
      } finally {
        $archive->close();
      }
      return strtolower($expected);
    }

    throw new \InvalidArgumentException('The file extension does not match the detected content type.');
  }

  private function _persistVerifiedUpload(string $staged, string $extension): bool
  {
    $directory = $this->_buildStorageDirectory();
    $attempts = (int)$this->_configuration()->get('code_retry_limit', 5);
    $created = false;

    for ($attempt = 0; $attempt < $attempts; $attempt++) {
      $this->_name = bin2hex(random_bytes(20)) . '.' . $extension;
      $key = ObjectKey::fromComponents([$directory, $this->_name]);
      $this->_path = $key->directory();
      $this->object_key = $key->value();
      $this->code = static::CODE_PREFIX . $this->_randomNumeric(12);
      $this->storage_bucket = $this->_configuration()->driver($this->_storageDriverName(), 'bucket');
      $this->lifecycle_state = 'pending_upload';

      if ($this->_create()) {
        $created = true;
        break;
      }
      if (!in_array((string)$this->conn()->lastErrorCode(), ['1062', '23000'], true)) {
        break;
      }
      $this->id = null;
    }

    if (!$created || $this->id === null) {
      $this->_systemError('upload', 'The pending file record could not be persisted.');
      return false;
    }

    $key = $this->objectKeyValue();
    try {
      $this->_resolveDriver()->put($staged, $key);
    } catch (StorageException $exception) {
      $this->_markCleanupRequired('upload_storage_failure', $exception->operation);
      throw $exception;
    }

    if (!$this->_transitionLifecycle('pending_upload', 'active')) {
      try {
        $this->_resolveDriver()->delete($key);
      } catch (StorageException) {
        // The persisted pending record is the durable retry marker.
      }
      $this->_markCleanupRequired('upload_activation_failure', 'activate');
      $this->_systemError('upload', 'The stored object could not be activated in the database.');
      return false;
    }

    return true;
  }

  protected function _resolveDriver(): StorageDriverInterface
  {
    $name = $this->_storageDriverName();
    if (!array_key_exists($name, $this->_configuration()->drivers())) {
      throw new \TimeFrontiers\File\Exceptions\UnsupportedDriverException(
        'resolve', $name, 'The stored file references an unsupported driver.'
      );
    }
    return $this->_driver_cache[$name]
      ??= FileConfig::driverFactory()->make($name, $this->_configuration());
  }

  /** @internal Separated only so the HTTP boundary can be tested deterministically. */
  protected function _isHttpUploadedFile(string $path): bool
  {
    return is_uploaded_file($path);
  }

  protected function _randomNumeric(int $length): string
  {
    $digits = '';
    while (strlen($digits) < $length) {
      $digits .= (string)random_int(0, 9);
    }
    return $digits;
  }
}

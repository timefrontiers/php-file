<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Concerns;

defined('FILE_WRITER_PREPEND') || define('FILE_WRITER_PREPEND', 'prepend');
defined('FILE_WRITER_APPEND') || define('FILE_WRITER_APPEND', 'append');

trait Writer
{
  public function write(string $value, string $option = FILE_WRITER_PREPEND): bool
  {
    if (!$this->_assertLocalWritable('write')) {
      return false;
    }
    if (!in_array($option, [FILE_WRITER_PREPEND, FILE_WRITER_APPEND], true)) {
      throw new \InvalidArgumentException('The writer option must be prepend or append.');
    }
    $path = $this->fullPath();
    $success = $option === FILE_WRITER_APPEND
      ? $this->_appendLocked($path, $value)
      : $this->_rewriteLocked($path, static function ($input, $output) use ($value): bool {
          return self::_writeAll($output, $value)
            && stream_copy_to_stream($input, $output) !== false;
        });
    return $success && $this->_refreshWrittenMetadata('write');
  }

  public function writeLine(int $line, string $value): bool
  {
    if ($line < 0) {
      throw new \InvalidArgumentException('Line indexes must be zero or greater.');
    }
    if (!$this->_assertLocalWritable('writeLine')) {
      return false;
    }
    $found = false;
    $success = $this->_rewriteLocked(
      $this->fullPath(),
      static function ($input, $output) use ($line, $value, &$found): bool {
        $index = 0;
        while (($current = fgets($input)) !== false) {
          if ($index === $line) {
            $current = $value;
            $found = true;
          }
          if (!self::_writeAll($output, $current)) {
            return false;
          }
          $index++;
        }
        return $found;
      }
    );
    if (!$found) {
      $this->_userError('writeLine', "Line {$line} does not exist in the file.");
    }
    return $success && $found && $this->_refreshWrittenMetadata('writeLine');
  }

  private function _assertLocalWritable(string $operation): bool
  {
    if ($this->_storageDriverName() !== 'local') {
      $this->_userError($operation, 'Text writer operations are supported only by the local driver.');
      return false;
    }
    if ($this->lifecycleState() !== 'active' || $this->locked()) {
      $this->_userError($operation, 'Only active, unlocked files can be written.');
      return false;
    }
    $path = $this->fullPath();
    if (!is_file($path) || !is_writable($path)) {
      $this->_userError($operation, 'The local object is not writable.');
      return false;
    }
    return true;
  }

  private function _appendLocked(string $path, string $value): bool
  {
    $stream = @fopen($path, 'c+b');
    if (!is_resource($stream)) {
      return false;
    }
    try {
      if (!flock($stream, LOCK_EX) || fseek($stream, 0, SEEK_END) !== 0) {
        return false;
      }
      return self::_writeAll($stream, $value) && fflush($stream);
    } finally {
      flock($stream, LOCK_UN);
      fclose($stream);
    }
  }

  /** @param callable(resource, resource):bool $writer */
  private function _rewriteLocked(string $path, callable $writer): bool
  {
    $lockPath = $path . '.tf-lock';
    $lock = @fopen($lockPath, 'x+b');
    if (!is_resource($lock)) {
      $this->_userError('write', 'Another writer is already modifying the object.');
      return false;
    }
    $temporary = tempnam(dirname($path), '.tf-write-');
    if ($temporary === false) {
      fclose($lock);
      @unlink($lockPath);
      return false;
    }

    try {
      if (!flock($lock, LOCK_EX)) {
        return false;
      }
      $input = @fopen($path, 'rb');
      $output = @fopen($temporary, 'w+b');
      if (!is_resource($input) || !is_resource($output)) {
        if (is_resource($input)) fclose($input);
        if (is_resource($output)) fclose($output);
        return false;
      }
      try {
        $ok = $writer($input, $output) && fflush($output);
      } finally {
        fclose($input);
        fclose($output);
      }
      if (!$ok) {
        return false;
      }
      @chmod($temporary, fileperms($path) & 0777);
      return @rename($temporary, $path);
    } finally {
      flock($lock, LOCK_UN);
      fclose($lock);
      @unlink($lockPath);
      @unlink($temporary);
    }
  }

  /** @param resource $stream */
  private static function _writeAll($stream, string $value): bool
  {
    $length = strlen($value);
    if ($length === 0) {
      return true;
    }
    $offset = 0;
    while ($offset < $length) {
      $written = fwrite($stream, substr($value, $offset));
      if ($written === false || $written === 0) {
        return false;
      }
      $offset += $written;
    }
    return true;
  }

  private function _refreshWrittenMetadata(string $operation): bool
  {
    $path = $this->fullPath();
    clearstatcache(true, $path);
    $size = filesize($path);
    $checksum = hash_file('sha512', $path);
    if ($size === false || $checksum === false) {
      $this->_markCleanupRequired('write_observation_failure', $operation);
      return false;
    }
    $this->_size = (int)$size;
    $this->_checksum = $checksum;
    if (!$this->_persistObservedMetadata()) {
      $this->_markCleanupRequired('write_metadata_failure', $operation);
      return false;
    }
    return true;
  }
}

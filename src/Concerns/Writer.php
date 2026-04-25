<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Concerns;

define('FILE_WRITER_PREPEND', 'prepend');
define('FILE_WRITER_APPEND',  'append');

/**
 * Text-file write utilities.
 *
 * Currently only implemented for local-driver files.
 * Cloud-driver support requires download → modify → re-upload logic,
 * which will be added when timefrontiers/php-image lands.
 */
trait Writer
{
  /**
   * Write content to the beginning or end of a text file.
   *
   * @param string $value   Content to write.
   * @param string $option  FILE_WRITER_PREPEND | FILE_WRITER_APPEND
   */
  public function write(string $value, string $option = FILE_WRITER_PREPEND): bool
  {
    $path = $this->fullPath();

    if (!is_writable($path)) {
      $this->_userError('write', 'File is not writable.');
      return false;
    }

    if ($option === FILE_WRITER_APPEND) {
      return (bool)file_put_contents($path, $value, FILE_APPEND | LOCK_EX);
    }

    // Prepend: read existing content then write new content before it
    $existing = file_get_contents($path) ?: '';
    return (bool)file_put_contents($path, $value . $existing, LOCK_EX);
  }

  /**
   * Replace the content of a specific line (0-indexed) in a text file.
   *
   * @param int    $line  Zero-based line index.
   * @param string $value New content for that line (should include \n if needed).
   */
  public function writeLine(int $line, string $value): bool
  {
    $path = $this->fullPath();

    if (!is_readable($path) || !is_writable($path)) {
      $this->_userError('writeLine', 'File is either not readable or not writable.');
      return false;
    }

    $lines = file($path);
    if ($lines === false) {
      $this->_systemError('writeLine', 'Could not read file lines.');
      return false;
    }

    if (!array_key_exists($line, $lines)) {
      $this->_userError('writeLine', "Line {$line} does not exist in the file.");
      return false;
    }

    $lines[$line] = $value;
    return (bool)file_put_contents($path, implode('', $lines), LOCK_EX);
  }
}

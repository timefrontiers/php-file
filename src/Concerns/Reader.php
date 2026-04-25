<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Concerns;

/**
 * Text-file read utilities.
 *
 * Currently only implemented for local-driver files.
 */
trait Reader
{
  /**
   * Return the full raw content of the file as a string.
   * Returns false on failure.
   */
  public function readAll(): string|false
  {
    $path = $this->fullPath();

    if (!file_exists($path) || !is_readable($path)) {
      $this->_userError('read', 'File does not exist or is not readable.');
      return false;
    }

    return file_get_contents($path);
  }

  /**
   * Return the file as an array of lines (newlines preserved).
   *
   * @return string[]|false
   */
  public function readLines(): array|false
  {
    $path = $this->fullPath();

    if (!file_exists($path) || !is_readable($path)) {
      $this->_userError('read', 'File does not exist or is not readable.');
      return false;
    }

    return file($path);
  }

  /**
   * Return a single line by index (0-based).
   * Returns null if the line does not exist.
   */
  public function readLine(int $index): string|null|false
  {
    $lines = $this->readLines();
    if ($lines === false) {
      return false;
    }

    return $lines[$index] ?? null;
  }

  /**
   * Return the number of lines in the file.
   */
  public function lineCount(): int|false
  {
    $lines = $this->readLines();
    return $lines !== false ? count($lines) : false;
  }
}

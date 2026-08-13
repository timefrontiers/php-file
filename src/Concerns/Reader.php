<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Concerns;

use TimeFrontiers\File\Exceptions\StorageException;

trait Reader
{
  public function readAll(): string|false
  {
    $limit = (int)$this->_configuration()->get('read_all_max_bytes', 16_777_216);
    if ($this->size() > $limit) {
      $this->_userError('read', 'The object exceeds the bounded readAll() limit; use openReadStream().');
      return false;
    }
    try {
      $stream = $this->openReadStream();
      $contents = stream_get_contents($stream, $limit + 1);
      fclose($stream);
      if ($contents === false || strlen($contents) > $limit) {
        $this->_userError('read', 'The object exceeds the bounded readAll() limit.');
        return false;
      }
      return $contents;
    } catch (StorageException $exception) {
      $this->_systemError('read', $exception->operation . ':' . $exception->driver);
      return false;
    }
  }

  /** @return resource */
  public function openReadStream(): mixed
  {
    if ($this->lifecycleState() !== 'active') {
      throw new \LogicException('Only active files can be read.');
    }
    return $this->_resolveDriver()->readStream($this->objectKeyValue());
  }

  /** @return list<string>|false */
  public function readLines(): array|false
  {
    $limit = (int)$this->_configuration()->get('read_all_max_bytes', 16_777_216);
    try {
      $stream = $this->openReadStream();
      $lines = [];
      $observed = 0;
      while (($line = fgets($stream)) !== false) {
        $observed += strlen($line);
        if ($observed > $limit) {
          fclose($stream);
          $this->_userError('read', 'The object exceeds the bounded readLines() limit.');
          return false;
        }
        $lines[] = $line;
      }
      fclose($stream);
      return $lines;
    } catch (StorageException $exception) {
      $this->_systemError('read', $exception->operation . ':' . $exception->driver);
      return false;
    }
  }

  public function readLine(int $index): string|null|false
  {
    if ($index < 0) {
      throw new \InvalidArgumentException('Line indexes must be zero or greater.');
    }
    try {
      $stream = $this->openReadStream();
      $current = 0;
      while (($line = fgets($stream)) !== false) {
        if ($current++ === $index) {
          fclose($stream);
          return $line;
        }
      }
      fclose($stream);
      return null;
    } catch (StorageException $exception) {
      $this->_systemError('read', $exception->operation . ':' . $exception->driver);
      return false;
    }
  }

  public function lineCount(): int|false
  {
    try {
      $stream = $this->openReadStream();
      $count = 0;
      while (fgets($stream) !== false) {
        $count++;
      }
      fclose($stream);
      return $count;
    } catch (StorageException $exception) {
      $this->_systemError('read', $exception->operation . ':' . $exception->driver);
      return false;
    }
  }
}

<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Tests\Fakes;

use TimeFrontiers\File\Drivers\StorageDeleteResult;
use TimeFrontiers\File\Drivers\StorageDriverInterface;
use TimeFrontiers\File\Drivers\StorageMoveResult;
use TimeFrontiers\File\Drivers\StorageWriteResult;
use TimeFrontiers\File\Exceptions\ObjectNotFoundException;
use TimeFrontiers\File\Exceptions\ProviderException;
use TimeFrontiers\File\Storage\ObjectKey;

final class InMemoryStorageDriver implements StorageDriverInterface
{
  /** @var array<string, string> */
  public array $objects = [];
  public bool $failDelete = false;
  public bool $failPut = false;
  public bool $failRead = false;

  public function __construct(private readonly string $driverName = 'memory') {}
  public function name(): string { return $this->driverName; }

  public function put(string $sourcePath, ObjectKey $key, bool $overwrite = false): StorageWriteResult
  {
    if ($this->failPut) throw new ProviderException('put', $this->name(), 'Injected put failure.');
    if (!$overwrite && isset($this->objects[$key->value()])) {
      throw new ProviderException('put', $this->name(), 'Object exists.');
    }
    $replaced = isset($this->objects[$key->value()]);
    $contents = file_get_contents($sourcePath);
    if ($contents === false) throw new ObjectNotFoundException('put', $this->name(), 'Source missing.');
    $this->objects[$key->value()] = $contents;
    return $replaced ? StorageWriteResult::Replaced : StorageWriteResult::Stored;
  }

  public function delete(ObjectKey $key): StorageDeleteResult
  {
    if ($this->failDelete) throw new ProviderException('delete', $this->name(), 'Injected delete failure.');
    if (!isset($this->objects[$key->value()])) return StorageDeleteResult::AlreadyAbsent;
    unset($this->objects[$key->value()]);
    return StorageDeleteResult::Deleted;
  }

  public function exists(ObjectKey $key): bool { return isset($this->objects[$key->value()]); }

  public function readStream(ObjectKey $key): mixed
  {
    if ($this->failRead) throw new ProviderException('read', $this->name(), 'Injected read failure.');
    if (!isset($this->objects[$key->value()])) {
      throw new ObjectNotFoundException('read', $this->name(), 'Object missing.');
    }
    $stream = fopen('php://temp', 'w+b');
    if (!is_resource($stream)) throw new \RuntimeException('Could not create the in-memory stream.');
    fwrite($stream, $this->objects[$key->value()]);
    rewind($stream);
    return $stream;
  }

  public function move(ObjectKey $from, ObjectKey $to, bool $overwrite = false): StorageMoveResult
  {
    if (!isset($this->objects[$from->value()])) {
      throw new ObjectNotFoundException('move', $this->name(), 'Object missing.');
    }
    if (!$overwrite && isset($this->objects[$to->value()])) {
      throw new ProviderException('move', $this->name(), 'Object exists.');
    }
    $replaced = isset($this->objects[$to->value()]);
    $this->objects[$to->value()] = $this->objects[$from->value()];
    unset($this->objects[$from->value()]);
    return $replaced ? StorageMoveResult::Replaced : StorageMoveResult::Moved;
  }
}

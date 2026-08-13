<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Drivers;

use TimeFrontiers\File\Exceptions\UnsupportedDriverException;
use TimeFrontiers\File\Storage\ObjectKey;

/** @deprecated Dropbox is intentionally unsupported until a production driver is shipped. */
final class DropboxDriver implements StorageDriverInterface
{
  public function __construct() { throw $this->unsupported('configure'); }
  public function name(): string { return 'dropbox'; }
  public function put(string $sourcePath, ObjectKey $key, bool $overwrite = false): StorageWriteResult { throw $this->unsupported('put'); }
  public function delete(ObjectKey $key): StorageDeleteResult { throw $this->unsupported('delete'); }
  public function exists(ObjectKey $key): bool { throw $this->unsupported('exists'); }
  public function readStream(ObjectKey $key): mixed { throw $this->unsupported('read'); }
  public function move(ObjectKey $from, ObjectKey $to, bool $overwrite = false): StorageMoveResult { throw $this->unsupported('move'); }
  private function unsupported(string $operation): UnsupportedDriverException
  {
    return new UnsupportedDriverException($operation, $this->name(), 'The Dropbox driver is not implemented.');
  }
}

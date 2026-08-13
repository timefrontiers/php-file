<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Drivers;

use TimeFrontiers\File\Storage\ObjectKey;

interface StorageDriverInterface
{
  public function name(): string;

  /** Copy a verified local source into storage. */
  public function put(string $sourcePath, ObjectKey $key, bool $overwrite = false): StorageWriteResult;

  /** Idempotently delete an object. */
  public function delete(ObjectKey $key): StorageDeleteResult;

  public function exists(ObjectKey $key): bool;

  /** @return resource Readable binary stream positioned at byte zero. */
  public function readStream(ObjectKey $key): mixed;

  public function move(ObjectKey $from, ObjectKey $to, bool $overwrite = false): StorageMoveResult;
}

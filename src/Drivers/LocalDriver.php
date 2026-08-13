<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Drivers;

use TimeFrontiers\File\Exceptions\ObjectNotFoundException;
use TimeFrontiers\File\Exceptions\ProviderException;
use TimeFrontiers\File\Exceptions\TransportException;
use TimeFrontiers\File\FileConfiguration;
use TimeFrontiers\File\Storage\LocalPathResolver;
use TimeFrontiers\File\Storage\ObjectKey;

final class LocalDriver implements StorageDriverInterface
{
  private readonly LocalPathResolver $paths;

  public function __construct(FileConfiguration $configuration)
  {
    $this->paths = $configuration->localPathResolver();
  }

  public function name(): string
  {
    return 'local';
  }

  public function put(string $sourcePath, ObjectKey $key, bool $overwrite = false): StorageWriteResult
  {
    if (!is_file($sourcePath) || is_link($sourcePath) || !is_readable($sourcePath)) {
      throw new ObjectNotFoundException('put', $this->name(), 'The verified upload source is unavailable.');
    }

    $destination = $this->paths->resolve($key, createParents: true);
    $replacing = file_exists($destination);
    if ($replacing && !$overwrite) {
      throw new ProviderException('put', $this->name(), 'An object already exists at the target key.');
    }

    $temporary = $destination . '.tmp-' . bin2hex(random_bytes(8));
    $input = @fopen($sourcePath, 'rb');
    $output = @fopen($temporary, 'x+b');
    if (!is_resource($input) || !is_resource($output)) {
      if (is_resource($input)) fclose($input);
      if (is_resource($output)) fclose($output);
      @unlink($temporary);
      throw new TransportException('put', $this->name(), 'Local storage could not open a transfer stream.');
    }

    try {
      $copied = stream_copy_to_stream($input, $output);
      if ($copied === false || !fflush($output)) {
        throw new TransportException('put', $this->name(), 'Local storage could not complete the transfer.');
      }
    } finally {
      fclose($input);
      fclose($output);
    }

    // Revalidate the parent immediately before the final filesystem mutation.
    $destination = $this->paths->resolve($key, createParents: true);
    if (!$overwrite && file_exists($destination)) {
      @unlink($temporary);
      throw new ProviderException('put', $this->name(), 'An object appeared at the target key during transfer.');
    }
    if ($replacing && $overwrite && file_exists($destination) && !@unlink($destination)) {
      @unlink($temporary);
      throw new ProviderException('put', $this->name(), 'The existing local object could not be replaced.');
    }
    if (!@rename($temporary, $destination)) {
      @unlink($temporary);
      throw new TransportException('put', $this->name(), 'The local object could not be committed.');
    }

    return $replacing ? StorageWriteResult::Replaced : StorageWriteResult::Stored;
  }

  public function delete(ObjectKey $key): StorageDeleteResult
  {
    $path = $this->paths->resolve($key);
    if (!file_exists($path)) {
      return StorageDeleteResult::AlreadyAbsent;
    }
    if (!is_file($path) || !@unlink($path)) {
      throw new ProviderException('delete', $this->name(), 'The local object could not be deleted.');
    }
    return StorageDeleteResult::Deleted;
  }

  public function exists(ObjectKey $key): bool
  {
    return is_file($this->paths->resolve($key));
  }

  public function readStream(ObjectKey $key): mixed
  {
    $path = $this->paths->resolve($key);
    if (!is_file($path) || !is_readable($path)) {
      throw new ObjectNotFoundException('read', $this->name(), 'The requested object was not found.');
    }
    $stream = @fopen($path, 'rb');
    if (!is_resource($stream)) {
      throw new TransportException('read', $this->name(), 'The local object could not be opened.');
    }
    return $stream;
  }

  public function move(ObjectKey $from, ObjectKey $to, bool $overwrite = false): StorageMoveResult
  {
    $source = $this->paths->resolve($from);
    if (!is_file($source)) {
      throw new ObjectNotFoundException('move', $this->name(), 'The source object was not found.');
    }
    $destination = $this->paths->resolve($to, createParents: true);
    $replacing = file_exists($destination);
    if ($replacing && !$overwrite) {
      throw new ProviderException('move', $this->name(), 'An object already exists at the target key.');
    }
    if ($replacing && !@unlink($destination)) {
      throw new ProviderException('move', $this->name(), 'The target object could not be replaced.');
    }
    $destination = $this->paths->resolve($to, createParents: true);
    if (!$overwrite && file_exists($destination)) {
      throw new ProviderException('move', $this->name(), 'An object appeared at the target key during the move.');
    }
    if (!@rename($source, $destination)) {
      throw new TransportException('move', $this->name(), 'The local object could not be moved.');
    }
    return $replacing ? StorageMoveResult::Replaced : StorageMoveResult::Moved;
  }
}

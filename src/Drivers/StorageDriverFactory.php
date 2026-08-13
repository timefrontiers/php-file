<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Drivers;

use TimeFrontiers\File\Exceptions\UnsupportedDriverException;
use TimeFrontiers\File\FileConfiguration;

/** Default factory with optional named overrides for tests and injected clients. */
final class StorageDriverFactory implements StorageDriverFactoryInterface
{
  /** @param array<string, StorageDriverInterface|callable(FileConfiguration):StorageDriverInterface> $overrides */
  public function __construct(private readonly array $overrides = []) {}

  public function make(string $name, FileConfiguration $configuration): StorageDriverInterface
  {
    if (isset($this->overrides[$name])) {
      $override = $this->overrides[$name];
      $driver = $override instanceof StorageDriverInterface
        ? $override
        : $override($configuration);
      if (!$driver instanceof StorageDriverInterface) {
        throw new \LogicException("The injected {$name} driver factory returned an invalid value.");
      }
      return $driver;
    }

    return match ($name) {
      'local' => new LocalDriver($configuration),
      's3' => new AwsS3Driver($configuration),
      'minio' => new MinioDriver($configuration),
      default => throw new UnsupportedDriverException(
        'resolve',
        $name,
        'The configured storage driver is not supported.'
      ),
    };
  }
}

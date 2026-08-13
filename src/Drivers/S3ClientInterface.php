<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Drivers;

interface S3ClientInterface
{
  /** @param array<string, mixed> $arguments */
  public function putObject(array $arguments): mixed;
  /** @param array<string, mixed> $arguments */
  public function deleteObject(array $arguments): mixed;
  public function doesObjectExistV2(string $bucket, string $key): bool;
  /**
   * @param array<string, mixed> $arguments
   * @return array<string, mixed>
   */
  public function getObject(array $arguments): array;
}

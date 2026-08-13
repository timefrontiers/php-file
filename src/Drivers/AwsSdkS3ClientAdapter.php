<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Drivers;

final readonly class AwsSdkS3ClientAdapter implements S3ClientInterface
{
  public function __construct(private \Aws\S3\S3Client $client) {}
  public function putObject(array $arguments): mixed { return $this->client->putObject($arguments); }
  public function deleteObject(array $arguments): mixed { return $this->client->deleteObject($arguments); }
  public function doesObjectExistV2(string $bucket, string $key): bool { return $this->client->doesObjectExistV2($bucket, $key); }
  /** @return array<string, mixed> */
  public function getObject(array $arguments): array { return $this->client->getObject($arguments)->toArray(); }
}

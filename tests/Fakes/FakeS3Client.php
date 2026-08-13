<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Tests\Fakes;

use TimeFrontiers\File\Drivers\S3ClientInterface;

final class FakeS3Client implements S3ClientInterface
{
  /** @var array<string, string> */
  public array $objects = [];
  /** @var array<string, mixed>|null */
  public ?array $lastPut = null;

  public function putObject(array $arguments): mixed
  {
    $key = (string)$arguments['Key'];
    if (($arguments['IfNoneMatch'] ?? null) === '*' && isset($this->objects[$key])) {
      throw new \RuntimeException('PreconditionFailed', 412);
    }
    $contents = file_get_contents((string)$arguments['SourceFile']);
    if ($contents === false) throw new \RuntimeException('Source missing.');
    $this->lastPut = $arguments;
    $this->objects[$key] = $contents;
    return true;
  }

  public function deleteObject(array $arguments): mixed
  {
    unset($this->objects[(string)$arguments['Key']]);
    return true;
  }

  public function doesObjectExistV2(string $bucket, string $key): bool
  {
    return isset($this->objects[$key]);
  }

  public function getObject(array $arguments): array
  {
    $key = (string)$arguments['Key'];
    if (!isset($this->objects[$key])) throw new \RuntimeException('NotFound', 404);
    return ['Body' => new DetachableBody($this->objects[$key])];
  }

}

final readonly class DetachableBody
{
  public function __construct(private string $contents) {}

  /** @return resource */
  public function detach(): mixed
  {
    $stream = fopen('php://temp', 'w+b');
    if (!is_resource($stream)) throw new \RuntimeException('Could not create stream.');
    fwrite($stream, $this->contents);
    rewind($stream);
    return $stream;
  }
}

<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Drivers;

use TimeFrontiers\File\Exceptions\ObjectNotFoundException;
use TimeFrontiers\File\Exceptions\ProviderException;
use TimeFrontiers\File\Exceptions\TransportException;
use TimeFrontiers\File\FileConfiguration;
use TimeFrontiers\File\Storage\ObjectKey;

class AwsS3Driver implements StorageDriverInterface
{
  protected readonly S3ClientInterface $client;
  protected readonly string $bucket;

  public function __construct(
    protected readonly FileConfiguration $configuration,
    ?S3ClientInterface $client = null,
    protected readonly string $driverName = 's3'
  ) {
    $this->bucket = (string)$configuration->driver($driverName, 'bucket');
    if ($client !== null) {
      $this->client = $client;
      return;
    }
    if (!class_exists(\Aws\S3\S3Client::class)) {
      throw new ProviderException('configure', $driverName, 'The AWS SDK required by this storage driver is unavailable.');
    }

    $options = [
      'version' => 'latest',
      'region' => (string)$configuration->driver($driverName, 'region', 'us-east-1'),
      'credentials' => [
        'key' => (string)$configuration->driver($driverName, 'key'),
        'secret' => (string)$configuration->driver($driverName, 'secret'),
      ],
    ];
    $endpoint = $configuration->driver($driverName, 'endpoint');
    if (is_string($endpoint) && $endpoint !== '') {
      $options['endpoint'] = $endpoint;
      $options['use_path_style_endpoint'] = true;
    }

    try {
      $this->client = new AwsSdkS3ClientAdapter(new \Aws\S3\S3Client($options));
    } catch (\Throwable $exception) {
      throw $this->failure('configure', $exception);
    }
  }

  public function name(): string
  {
    return $this->driverName;
  }

  public function put(string $sourcePath, ObjectKey $key, bool $overwrite = false): StorageWriteResult
  {
    if (!is_file($sourcePath) || is_link($sourcePath) || !is_readable($sourcePath)) {
      throw new ObjectNotFoundException('put', $this->name(), 'The verified upload source is unavailable.');
    }
    $replacing = $this->exists($key);
    if ($replacing && !$overwrite) {
      throw new ProviderException('put', $this->name(), 'An object already exists at the target key.');
    }
    try {
      $arguments = [
        'Bucket' => $this->bucket,
        'Key' => $key->value(),
        'SourceFile' => $sourcePath,
        'ContentType' => (new \finfo(FILEINFO_MIME_TYPE))->file($sourcePath) ?: 'application/octet-stream',
      ];
      if (!$overwrite) {
        $arguments['IfNoneMatch'] = '*';
      }
      $this->client->putObject($arguments);
      return $replacing ? StorageWriteResult::Replaced : StorageWriteResult::Stored;
    } catch (\Throwable $exception) {
      throw $this->failure('put', $exception);
    }
  }

  public function delete(ObjectKey $key): StorageDeleteResult
  {
    if (!$this->exists($key)) {
      return StorageDeleteResult::AlreadyAbsent;
    }
    try {
      $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $key->value()]);
      return StorageDeleteResult::Deleted;
    } catch (\Throwable $exception) {
      throw $this->failure('delete', $exception);
    }
  }

  public function exists(ObjectKey $key): bool
  {
    try {
      return (bool)$this->client->doesObjectExistV2($this->bucket, $key->value());
    } catch (\Throwable $exception) {
      throw $this->failure('exists', $exception);
    }
  }

  public function readStream(ObjectKey $key): mixed
  {
    try {
      $result = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $key->value()]);
      $body = $result['Body'] ?? null;
      $stream = is_object($body) && method_exists($body, 'detach') ? $body->detach() : null;
      if (!is_resource($stream)) {
        throw new \RuntimeException('The provider did not return a readable stream.');
      }
      return $stream;
    } catch (\Throwable $exception) {
      if ($this->isNotFound($exception)) {
        throw new ObjectNotFoundException('read', $this->name(), 'The requested object was not found.');
      }
      throw $this->failure('read', $exception);
    }
  }

  public function move(ObjectKey $from, ObjectKey $to, bool $overwrite = false): StorageMoveResult
  {
    if ($from->value() === $to->value()) {
      if (!$this->exists($from)) {
        throw new ObjectNotFoundException('move', $this->name(), 'The source object was not found.');
      }
      return StorageMoveResult::Moved;
    }
    if (!$this->exists($from)) {
      throw new ObjectNotFoundException('move', $this->name(), 'The source object was not found.');
    }
    $replacing = $this->exists($to);
    if ($replacing && !$overwrite) {
      throw new ProviderException('move', $this->name(), 'An object already exists at the target key.');
    }

    $temporaryPath = tempnam(sys_get_temp_dir(), 'tf-file-s3-');
    if ($temporaryPath === false) {
      throw new TransportException('move', $this->name(), 'A temporary stream could not be allocated for the move.');
    }
    $sourceStream = null;
    $temporaryStream = null;
    $targetWritten = false;
    try {
      $sourceStream = $this->readStream($from);
      $temporaryStream = fopen($temporaryPath, 'w+b');
      if (!is_resource($temporaryStream)
        || stream_copy_to_stream($sourceStream, $temporaryStream) === false
        || !fflush($temporaryStream)
      ) {
        throw new \RuntimeException('The remote object could not be staged for the move.');
      }
      fclose($temporaryStream);
      $temporaryStream = null;
      $result = $this->put($temporaryPath, $to, $overwrite);
      $targetWritten = true;
      $this->delete($from);
      return $result === StorageWriteResult::Replaced
        ? StorageMoveResult::Replaced
        : StorageMoveResult::Moved;
    } catch (\Throwable $exception) {
      if ($targetWritten && !$replacing) {
        try {
          $this->delete($to);
        } catch (\Throwable) {
          // The typed move failure remains authoritative; cleanup is retryable by exact key.
        }
      }
      if ($exception instanceof ObjectNotFoundException
        || $exception instanceof ProviderException
        || $exception instanceof TransportException
      ) {
        throw $exception;
      }
      throw $this->failure('move', $exception);
    } finally {
      if (is_resource($sourceStream)) fclose($sourceStream);
      if (is_resource($temporaryStream)) fclose($temporaryStream);
      @unlink($temporaryPath);
    }
  }

  protected function failure(string $operation, \Throwable $exception): ProviderException|TransportException
  {
    $class = strtolower($exception::class);
    $context = ['provider_exception' => $exception::class, 'provider_code' => (string)$exception->getCode()];
    if (str_contains($class, 'connect') || str_contains($class, 'timeout') || str_contains($class, 'network')) {
      return new TransportException($operation, $this->name(), 'The storage provider could not be reached.', $context, $exception);
    }
    return new ProviderException($operation, $this->name(), 'The storage provider rejected the operation.', $context, $exception);
  }

  protected function isNotFound(\Throwable $exception): bool
  {
    if (method_exists($exception, 'getStatusCode') && $exception->getStatusCode() === 404) {
      return true;
    }
    return in_array((string)$exception->getCode(), ['404', 'NoSuchKey', 'NotFound'], true);
  }
}

<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Drivers;

use TimeFrontiers\File\FileConfig;
use TimeFrontiers\File\Exceptions\DriverException;

/**
 * AWS S3 (and S3-compatible) storage driver.
 *
 * Requires:  composer require aws/aws-sdk-php
 *
 * Configure via drivers['s3']:
 *   'bucket'      => 'my-bucket'
 *   'region'      => 'us-east-1'
 *   'key'         => 'ACCESS_KEY_ID'
 *   'secret'      => 'SECRET_ACCESS_KEY'
 *   'endpoint'    => null      // set for S3-compatible stores (MinIO etc.)
 *   'storage_url' => ''        // optional CDN override; if empty → native S3 URL
 *
 * Token-based expiry is handled at the FileToken layer (driver-agnostic).
 * S3 native pre-signed URLs are intentionally not used here.
 */
class AwsS3Driver implements StorageDriverInterface
{
  private \Aws\S3\S3Client $client;
  private string $bucket;

  /**
   * @throws DriverException if the AWS SDK is not installed or bucket is missing.
   */
  public function __construct()
  {
    if (!class_exists(\Aws\S3\S3Client::class)) {
      throw new DriverException(
        'AWS S3 driver requires aws/aws-sdk-php. Run: composer require aws/aws-sdk-php'
      );
    }

    $bucket = FileConfig::driverConfig('s3', 'bucket', '');
    if (empty($bucket)) {
      throw new DriverException(
        "S3 driver requires drivers['s3']['bucket'] to be set in File::configure()."
      );
    }

    $this->bucket = $bucket;

    $config = [
      'version'     => 'latest',
      'region'      => FileConfig::driverConfig('s3', 'region', 'us-east-1'),
      'credentials' => [
        'key'    => FileConfig::driverConfig('s3', 'key', ''),
        'secret' => FileConfig::driverConfig('s3', 'secret', ''),
      ],
    ];

    if ($endpoint = FileConfig::driverConfig('s3', 'endpoint')) {
      $config['endpoint']                = $endpoint;
      $config['use_path_style_endpoint'] = true;
    }

    $this->client = new \Aws\S3\S3Client($config);
  }

  // -------------------------------------------------------------------------
  // Interface
  // -------------------------------------------------------------------------

  public function upload(string $tmpPath, string $storagePath): bool
  {
    try {
      $this->client->putObject([
        'Bucket'      => $this->bucket,
        'Key'         => ltrim($storagePath, '/'),
        'SourceFile'  => $tmpPath,
        'ContentType' => mime_content_type($tmpPath) ?: 'application/octet-stream',
      ]);
      return true;
    } catch (\Throwable $e) {
      return false;
    }
  }

  public function delete(string $storagePath): bool
  {
    try {
      $this->client->deleteObject([
        'Bucket' => $this->bucket,
        'Key'    => ltrim($storagePath, '/'),
      ]);
      return true;
    } catch (\Throwable $e) {
      return false;
    }
  }

  public function exists(string $storagePath): bool
  {
    try {
      return $this->client->doesObjectExistV2(
        $this->bucket,
        ltrim($storagePath, '/')
      );
    } catch (\Throwable $e) {
      return false;
    }
  }

  public function url(string $storagePath): string
  {
    // If a CDN or custom storage_url is configured for s3, use it.
    $storageUrl = FileConfig::storageUrl('s3');
    if ($storageUrl) {
      return $storageUrl . '/' . ltrim($storagePath, '/');
    }

    return $this->client->getObjectUrl(
      $this->bucket,
      ltrim($storagePath, '/')
    );
  }

  public function read(string $storagePath): mixed
  {
    try {
      $result = $this->client->getObject([
        'Bucket' => $this->bucket,
        'Key'    => ltrim($storagePath, '/'),
      ]);

      // Detach the Guzzle stream to a plain PHP resource
      return $result['Body']->detach();
    } catch (\Throwable $e) {
      return false;
    }
  }
}

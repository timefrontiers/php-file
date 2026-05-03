<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Drivers;

use TimeFrontiers\File\FileConfig;
use TimeFrontiers\File\Exceptions\DriverException;

/**
 * MinIO storage driver.
 *
 * MinIO is an S3-compatible object store. This driver uses aws/aws-sdk-php
 * with path-style endpoints (required by MinIO) so no separate SDK is needed.
 *
 * Requires:  composer require aws/aws-sdk-php
 *
 * Configure via drivers['minio']:
 *   'endpoint'    => 'http://localhost:9000'   // REQUIRED — MinIO server URL
 *   'bucket'      => 'my-bucket'               // REQUIRED
 *   'region'      => 'us-east-1'               // MinIO ignores this but SDK requires it
 *   'key'         => 'minioadmin'              // MinIO access key
 *   'secret'      => 'minioadmin'              // MinIO secret key
 *   'storage_url' => ''                        // optional CDN/public URL override
 *
 * If storage_url is empty, url() builds: {endpoint}/{bucket}/{storagePath}.
 * For private buckets use token-based download (File::createToken()) instead.
 */
class MinioDriver implements StorageDriverInterface
{
  private \Aws\S3\S3Client $client;
  private string $bucket;
  private string $endpoint;

  /**
   * @throws DriverException if aws/aws-sdk-php is not installed,
   *                          or if endpoint / bucket are missing.
   */
  public function __construct()
  {
    if (!class_exists(\Aws\S3\S3Client::class)) {
      throw new DriverException(
        'MinIO driver requires aws/aws-sdk-php. Run: composer require aws/aws-sdk-php'
      );
    }

    $endpoint = FileConfig::driverConfig('minio', 'endpoint', '');
    if (empty($endpoint)) {
      throw new DriverException(
        "MinIO driver requires drivers['minio']['endpoint'] to be set in File::configure()."
      );
    }

    $bucket = FileConfig::driverConfig('minio', 'bucket', '');
    if (empty($bucket)) {
      throw new DriverException(
        "MinIO driver requires drivers['minio']['bucket'] to be set in File::configure()."
      );
    }

    $this->endpoint = rtrim($endpoint, '/');
    $this->bucket   = $bucket;

    $this->client = new \Aws\S3\S3Client([
      'version'                  => 'latest',
      'region'                   => FileConfig::driverConfig('minio', 'region', 'us-east-1'),
      'endpoint'                 => $this->endpoint,
      'use_path_style_endpoint'  => true,   // required for MinIO
      'credentials'              => [
        'key'    => FileConfig::driverConfig('minio', 'key', ''),
        'secret' => FileConfig::driverConfig('minio', 'secret', ''),
      ],
    ]);
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
      throw new DriverException('MinIO upload failed: ' . $e->getMessage(), 0, $e);
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
    // Prefer a configured CDN or public URL override.
    $storageUrl = FileConfig::storageUrl('minio');
    if ($storageUrl) {
      return $storageUrl . '/' . ltrim($storagePath, '/');
    }

    // Fall back to direct MinIO object URL: endpoint/bucket/storagePath
    return $this->endpoint
      . '/' . $this->bucket
      . '/' . ltrim($storagePath, '/');
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

<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Drivers;

use TimeFrontiers\File\FileConfiguration;

final class MinioDriver extends AwsS3Driver
{
  public function __construct(FileConfiguration $configuration, ?S3ClientInterface $client = null)
  {
    parent::__construct($configuration, $client, 'minio');
  }
}

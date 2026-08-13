<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Tests\Unit;

use TimeFrontiers\File\Exceptions\ConfigurationException;
use TimeFrontiers\File\FileConfig;
use TimeFrontiers\File\Tests\TestCase;

final class FileConfigTest extends TestCase
{
  public function testConfigurationIsValidatedFrozenAndSnapshotted(): void
  {
    $root = $this->temporaryDirectory();
    FileConfig::configure(
      ['default_driver' => 'local', 'db_name' => 'file_test', 'path_prefix' => 'Files'],
      ['local' => ['upload_path' => $root]]
    );
    self::assertSame(realpath($root), FileConfig::uploadPath());
    self::assertSame('Files', FileConfig::snapshot()->get('path_prefix'));

    $this->expectException(ConfigurationException::class);
    FileConfig::configure([], ['local' => ['upload_path' => $root]]);
  }

  public function testStubDefaultDriverIsRejected(): void
  {
    $this->expectException(ConfigurationException::class);
    FileConfig::configure(
      ['default_driver' => 'gcs'],
      ['gcs' => ['storage_url' => 'https://storage.example.test']]
    );
  }

  public function testUnknownDriverIsRejectedInsteadOfFallingBackToLocal(): void
  {
    $this->expectException(ConfigurationException::class);
    FileConfig::configure(
      ['default_driver' => 'local'],
      ['local' => ['upload_path' => $this->temporaryDirectory()], 'mystery' => []]
    );
  }

  public function testWeakTokenSecretIsRejected(): void
  {
    $root = $this->temporaryDirectory();
    $this->expectException(ConfigurationException::class);
    FileConfig::configure(
      [
        'default_driver' => 'local',
        'service_url' => 'https://files.example.test',
        'token_enabled' => true,
        'token_keys' => ['current' => 'short'],
        'active_token_key_id' => 'current',
      ],
      ['local' => ['upload_path' => $root]]
    );
  }
}

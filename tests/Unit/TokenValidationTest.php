<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Tests\Unit;

use TimeFrontiers\File\FileConfig;
use TimeFrontiers\File\FileToken;
use TimeFrontiers\File\Tests\TestCase;
use TimeFrontiers\SQLDatabase;

final class TokenValidationTest extends TestCase
{
  public function testZeroDownloadLimitIsRejectedBeforePersistence(): void
  {
    $connection = $this->configuredConnection();
    $this->expectException(\InvalidArgumentException::class);
    FileToken::mint(1, '+1 hour', 0, conn: $connection);
  }

  public function testPastExpiryIsRejectedBeforePersistence(): void
  {
    $connection = $this->configuredConnection();
    $this->expectException(\InvalidArgumentException::class);
    FileToken::mint(1, '-1 second', conn: $connection);
  }

  private function configuredConnection(): SQLDatabase
  {
    $root = $this->temporaryDirectory();
    FileConfig::configure([
      'service_url' => 'https://files.example.test',
      'token_enabled' => true,
      'token_keys' => ['current' => str_repeat('c', 32)],
      'active_token_key_id' => 'current',
    ], ['local' => ['upload_path' => $root]]);
    $connection = $this->createMock(SQLDatabase::class);
    FileToken::setup($connection);
    return $connection;
  }
}

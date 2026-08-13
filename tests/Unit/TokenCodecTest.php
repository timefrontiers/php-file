<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Tests\Unit;

use TimeFrontiers\File\FileConfig;
use TimeFrontiers\File\Security\TokenCodec;
use TimeFrontiers\File\Tests\TestCase;

final class TokenCodecTest extends TestCase
{
  public function testBearerIsVersionedAndOnlyKeyedDigestIsProducedForStorage(): void
  {
    $root = $this->temporaryDirectory();
    FileConfig::configure([
      'service_url' => 'https://files.example.test',
      'token_enabled' => true,
      'token_keys' => ['old' => str_repeat('o', 32), 'current' => str_repeat('c', 32)],
      'active_token_key_id' => 'current',
    ], ['local' => ['upload_path' => $root]]);

    $codec = new TokenCodec(FileConfig::snapshot());
    $issued = $codec->issue();
    self::assertMatchesRegularExpression('/^v1\.current\.[A-Za-z0-9_-]{43}$/D', $issued['bearer']);
    self::assertSame(64, strlen($issued['digest']));
    self::assertNotSame($issued['bearer'], $issued['digest']);
    self::assertSame(
      ['key_id' => 'current', 'digest' => $issued['digest']],
      $codec->inspect($issued['bearer'])
    );

    $oldRandom = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $oldBearer = "v1.old.{$oldRandom}";
    self::assertSame([
      'key_id' => 'old',
      'digest' => hash_hmac('sha256', $oldBearer, str_repeat('o', 32)),
    ], $codec->inspect($oldBearer));
  }

  public function testUnknownRotationKeyIsRejected(): void
  {
    $root = $this->temporaryDirectory();
    FileConfig::configure([
      'service_url' => 'https://files.example.test',
      'token_enabled' => true,
      'token_keys' => ['current' => str_repeat('c', 32)],
      'active_token_key_id' => 'current',
    ], ['local' => ['upload_path' => $root]]);
    $random = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    self::assertNull((new TokenCodec(FileConfig::snapshot()))->inspect("v1.retired.{$random}"));
  }
}

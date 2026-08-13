<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Security;

use TimeFrontiers\File\FileConfiguration;

final readonly class TokenCodec
{
  public function __construct(private FileConfiguration $configuration) {}

  /** @return array{bearer:string,key_id:string,digest:string} */
  public function issue(): array
  {
    if (!(bool)$this->configuration->get('token_enabled', false)) {
      throw new \LogicException('Download-token issuance is disabled.');
    }
    $keyId = $this->configuration->activeTokenKeyId();
    $random = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $bearer = "v1.{$keyId}.{$random}";
    return ['bearer' => $bearer, 'key_id' => $keyId, 'digest' => $this->digest($bearer, $keyId)];
  }

  /** @return array{key_id:string,digest:string}|null */
  public function inspect(string $bearer): ?array
  {
    if (preg_match('/^v1\.([A-Za-z0-9_-]{1,32})\.([A-Za-z0-9_-]{43})$/D', $bearer, $matches) !== 1) {
      return null;
    }
    $keyId = $matches[1];
    if (!array_key_exists($keyId, $this->configuration->tokenKeys())) {
      return null;
    }
    return ['key_id' => $keyId, 'digest' => $this->digest($bearer, $keyId)];
  }

  private function digest(string $bearer, string $keyId): string
  {
    $secret = $this->configuration->tokenKeys()[$keyId] ?? null;
    if (!is_string($secret) || $secret === '') {
      throw new \LogicException('The requested token key is unavailable.');
    }
    return hash_hmac('sha256', $bearer, $secret);
  }
}

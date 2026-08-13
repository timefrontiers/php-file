<?php
declare(strict_types=1);

namespace TimeFrontiers\File;

use TimeFrontiers\File\Storage\LocalPathResolver;

/** Immutable configuration snapshot supplied to each File and driver instance. */
final readonly class FileConfiguration
{
  /**
   * @param array<string, mixed> $base
   * @param array<string, array<string, mixed>> $drivers
   */
  public function __construct(
    private array $base,
    private array $drivers,
    private ?LocalPathResolver $localPathResolver
  ) {}

  public function get(string $key, mixed $default = null): mixed
  {
    return $this->base[$key] ?? $default;
  }

  /** @return array<string, mixed>|array<string, array<string, mixed>> */
  public function drivers(?string $name = null): array
  {
    return $name === null ? $this->drivers : ($this->drivers[$name] ?? []);
  }

  public function driver(string $name, string $key, mixed $default = null): mixed
  {
    return $this->drivers[$name][$key] ?? $default;
  }

  public function localPathResolver(): LocalPathResolver
  {
    return $this->localPathResolver
      ?? throw new \LogicException('The local storage driver is not configured.');
  }

  public function serviceUrl(): string
  {
    return rtrim((string)$this->get('service_url', ''), '/');
  }

  public function publicFileUrl(string $code): string
  {
    $path = trim((string)$this->get('public_url_path', 'file'), '/');
    return $this->serviceUrl() . '/' . $path . '/' . rawurlencode($code);
  }

  public function downloadUrl(string $token): string
  {
    $path = trim((string)$this->get('download_url_path', 'download'), '/');
    return $this->serviceUrl() . '/' . $path . '/' . rawurlencode($token);
  }

  /** @return array<string, non-empty-string> */
  public function tokenKeys(): array
  {
    /** @var array<string, non-empty-string> $keys */
    $keys = $this->get('token_keys', []);
    return $keys;
  }

  public function activeTokenKeyId(): string
  {
    return (string)$this->get('active_token_key_id', '');
  }
}

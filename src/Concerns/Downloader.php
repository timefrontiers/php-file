<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Concerns;

use TimeFrontiers\File\FileToken;
use TimeFrontiers\SQLDatabase;

trait Downloader
{
  public function createToken(
    string|int|\DateTimeInterface|null $expiresAt = null,
    ?int $maxDownloads = null,
    string $createdBy = 'SYSTEM'
  ): string {
    if ($this->id === null || $this->lifecycleState() !== 'active') {
      throw new \LogicException('Tokens can be created only for persisted active files.');
    }
    return FileToken::mint(
      fileId: $this->id,
      expiresAt: $expiresAt,
      maxDownloads: $maxDownloads,
      createdBy: $createdBy,
      conn: $this->conn()
    )->bearer;
  }

  public function tokenUrl(string $token): string
  {
    if ($this->_configuration()->serviceUrl() === '') {
      throw new \LogicException('A service_url is required to build download URLs.');
    }
    return $this->_configuration()->downloadUrl($token);
  }

  public function url(): string
  {
    if ($this->privacy === 'private') {
      throw new \RuntimeException('Private files require a download token.');
    }
    if ($this->code === null || $this->lifecycleState() !== 'active') {
      throw new \LogicException('Only persisted active files have public URLs.');
    }
    if ($this->_configuration()->serviceUrl() === '') {
      throw new \LogicException('A service_url is required to build public file URLs.');
    }
    return $this->_configuration()->publicFileUrl($this->code);
  }

  public static function resolveToken(string $tokenString, ?SQLDatabase $conn = null): static|false
  {
    $token = FileToken::resolve($tokenString, $conn);
    if ($token === false || $token->file_id === null) {
      return false;
    }
    $file = static::findById($token->file_id, $token->conn());
    return $file !== false && $file->lifecycleState() === 'active' ? $file : false;
  }

  public function download(?string $tokenString = null): never
  {
    $this->_streamToClient(inline: true, tokenString: $tokenString);
  }

  public function forceDownload(?string $tokenString = null): never
  {
    $this->_streamToClient(inline: false, tokenString: $tokenString);
  }

  private function _streamToClient(bool $inline, ?string $tokenString): never
  {
    if ($this->id === null || $this->lifecycleState() !== 'active') {
      http_response_code(404);
      exit('File not found.');
    }

    try {
      // Storage/provider failure before this point does not consume the token.
      $stream = $this->_resolveDriver()->readStream($this->objectKeyValue());
    } catch (\Throwable $exception) {
      $this->_systemError('download', $exception::class);
      http_response_code(404);
      exit('File not found.');
    }
    if (!is_resource($stream)) {
      http_response_code(502);
      exit('File stream is unavailable.');
    }

    if ($tokenString !== null && !FileToken::consume($tokenString, $this->id, $this->conn())) {
      fclose($stream);
      http_response_code(403);
      exit('Download token is invalid, expired, revoked, exhausted, or belongs to another file.');
    }

    $disposition = $inline ? 'inline' : 'attachment';
    $filename = str_replace(["\r", "\n", '"', '\\'], '_', $this->nice_name ?: $this->name());
    $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'download';

    header('Content-Type: ' . ($this->type() ?: 'application/octet-stream'));
    header('Content-Length: ' . $this->size());
    header("Content-Disposition: {$disposition}; filename=\"{$ascii}\"; filename*=UTF-8''" . rawurlencode($filename));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    fpassthru($stream);
    fclose($stream);
    exit;
  }
}

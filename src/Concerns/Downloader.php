<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Concerns;

use TimeFrontiers\File\FileConfig;
use TimeFrontiers\File\FileToken;

/**
 * Download and URL generation logic.
 *
 * Depends on (resolved via the host class):
 *   - Uploader  — _resolveDriver()
 *   - FileData  — isImage(), mimeType()
 *   - DatabaseObject — findById()
 *
 * Token URL flow:
 *   1. $token = $file->createToken(expiresAt: '+24 hours', maxDownloads: 5, createdBy: $userId);
 *   2. $url   = $file->tokenUrl($token);   // returns full service URL
 *   3. On the endpoint: File::resolveToken($token) → $file → $file->download($token);
 */
trait Downloader
{
  // -------------------------------------------------------------------------
  // Token creation
  // -------------------------------------------------------------------------

  /**
   * Mint a new download token for this file.
   *
   * @param string|int|\DateTimeInterface|null $expiresAt
   *        Accepts: '+24 hours' | '2026-12-31 23:59:59' | timestamp int | DateTime | null (never)
   * @param int|null    $maxDownloads  null = unlimited
   * @param string      $createdBy     Identity string (user id, name, etc.)
   * @return string The opaque token string to embed in a URL.
   */
  public function createToken(
    string|int|\DateTimeInterface|null $expiresAt = null,
    ?int $maxDownloads = null,
    string $createdBy = 'SYSTEM'
  ): string {
    if (empty($this->id)) {
      throw new \LogicException('Cannot create a token for an unsaved File (id is empty).');
    }

    $tokenModel = FileToken::mint(
      fileId:       (int)$this->id,
      expiresAt:    $expiresAt,
      maxDownloads: $maxDownloads,
      createdBy:    $createdBy
    );

    return $tokenModel->token;
  }

  // -------------------------------------------------------------------------
  // URL generation
  // -------------------------------------------------------------------------

  /**
   * Build the full download URL for a given token string.
   *
   * Returns: {service_url}/download/{token}
   * The consuming app is responsible for routing that endpoint to
   * File::resolveToken() + $file->download().
   */
  public function tokenUrl(string $token): string
  {
    return FileConfig::serviceUrl() . '/download/' . $token;
  }

  /**
   * Direct public URL (no token required).
   * Only valid for privacy = 'public' files.
   *
   * @throws \RuntimeException for private files.
   */
  public function url(): string
  {
    if (($this->privacy ?? 'public') === 'private') {
      throw new \RuntimeException(
        'Cannot get a direct URL for a private file. Use tokenUrl() instead.'
      );
    }

    // Public URL uses only _name (UNIQUE) — the internal _path folder structure
    // is a storage-side concern and must not be exposed in public-facing URLs.
    // e.g. https://cdn.example.com/a1b2c3d4e5f6g7h8i9j0.jpg
    return $this->_resolveDriver($this->storage_driver ?? null)->url($this->_name);
  }

  // -------------------------------------------------------------------------
  // Token resolution (static — used on the download endpoint)
  // -------------------------------------------------------------------------

  /**
   * Resolve a token string to the associated File instance.
   *
   * Validates the HMAC signature, checks expiry, and checks the
   * download counter. Returns false if any check fails.
   *
   * @return static|false
   */
  public static function resolveToken(string $tokenString): static|false
  {
    $tokenModel = FileToken::resolve($tokenString);
    if ($tokenModel === false) {
      return false;
    }

    $file = static::findById((int)$tokenModel->file_id);
    if (!$file) {
      return false;
    }

    return $file;
  }

  // -------------------------------------------------------------------------
  // Streaming
  // -------------------------------------------------------------------------

  /**
   * Stream the file inline to the client.
   *
   * If $tokenString is provided the download counter is incremented.
   * Call after resolveToken() to ensure the token is valid before streaming.
   *
   * @param string|null $tokenString Pass the token to increment its counter.
   */
  public function download(?string $tokenString = null): never
  {
    if ($tokenString !== null) {
      $tokenModel = FileToken::resolve($tokenString);
      if ($tokenModel === false) {
        http_response_code(403);
        exit('Download token is invalid, expired, or exhausted.');
      }
      $tokenModel->incrementDownload();
    }

    $this->_streamToClient(inline: true);
  }

  /**
   * Force a browser download (Content-Disposition: attachment).
   *
   * @param string|null $tokenString Pass the token to increment its counter.
   */
  public function forceDownload(?string $tokenString = null): never
  {
    if ($tokenString !== null) {
      $tokenModel = FileToken::resolve($tokenString);
      if ($tokenModel === false) {
        http_response_code(403);
        exit('Download token is invalid, expired, or exhausted.');
      }
      $tokenModel->incrementDownload();
    }

    $this->_streamToClient(inline: false);
  }

  // -------------------------------------------------------------------------
  // Internal streaming
  // -------------------------------------------------------------------------

  private function _streamToClient(bool $inline): never
  {
    $storagePath = rtrim($this->_path, '/') . '/' . $this->_name;
    $stream      = $this->_resolveDriver($this->storage_driver ?? null)->read($storagePath);

    if ($stream === false || $stream === null) {
      http_response_code(404);
      exit('File not found in storage.');
    }

    $disposition = $inline ? 'inline' : 'attachment';
    $filename    = rawurlencode($this->nice_name ?: $this->_name);

    header('Content-Type: ' . ($this->_type ?: 'application/octet-stream'));
    header('Content-Length: ' . $this->_size);
    header("Content-Disposition: {$disposition}; filename=\"{$filename}\"");
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    if (is_resource($stream)) {
      fpassthru($stream);
      fclose($stream);
    } else {
      echo $stream;
    }

    exit;
  }
}

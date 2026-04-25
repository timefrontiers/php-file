<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Drivers;

/**
 * Contract that every storage driver must implement.
 *
 * $storagePath is always the RELATIVE path inside the driver's storage root,
 * e.g. "/User-Files/08744307265/a1b2c3.jpg".
 * It is composed of  _path + '/' + _name  from file_meta.
 *
 * Drivers must never concern themselves with expiry or download counters;
 * those are handled by FileToken at the application layer.
 */
interface StorageDriverInterface
{
  /**
   * Move an uploaded temp file to permanent storage.
   *
   * @param string $tmpPath     Absolute path to the PHP temp file.
   * @param string $storagePath Relative target path inside the storage root.
   */
  public function upload(string $tmpPath, string $storagePath): bool;

  /**
   * Permanently delete a file from storage.
   * Must return true even if the file was already absent.
   */
  public function delete(string $storagePath): bool;

  /**
   * Return true if the file currently exists in storage.
   */
  public function exists(string $storagePath): bool;

  /**
   * Return the permanent public URL for the file (used for public files only).
   * For local driver this is storage_url + storagePath.
   * For S3 this is the native object URL (or CloudFront URL if configured).
   */
  public function url(string $storagePath): string;

  /**
   * Open the file for reading. Returns a stream resource or raw string.
   * Returns false on failure.
   *
   * Used by Downloader::download() to stream bytes to the client.
   */
  public function read(string $storagePath): mixed;
}

<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Exceptions;

/** @deprecated Catch StorageException or a concrete storage exception. */
class DriverException extends StorageException
{
  public function __construct(string $message, int $code = 0, ?\Throwable $previous = null)
  {
    parent::__construct('legacy', 'unknown', $message, ['legacy_code' => $code], $previous);
  }
}

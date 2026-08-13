<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Exceptions;

class StorageException extends \RuntimeException
{
  /** @param array<string, scalar|null> $context */
  public function __construct(
    public readonly string $operation,
    public readonly string $driver,
    string $safeMessage,
    public readonly array $context = [],
    ?\Throwable $previous = null
  ) {
    parent::__construct($safeMessage, 0, $previous);
  }
}

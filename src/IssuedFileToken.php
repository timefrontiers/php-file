<?php
declare(strict_types=1);

namespace TimeFrontiers\File;

/** One-time issuance result. The bearer is never copied onto the persisted model. */
final readonly class IssuedFileToken
{
  public function __construct(
    public string $bearer,
    public FileToken $record
  ) {}
}

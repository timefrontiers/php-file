<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Drivers;

enum StorageWriteResult: string
{
  case Stored = 'stored';
  case Replaced = 'replaced';
}

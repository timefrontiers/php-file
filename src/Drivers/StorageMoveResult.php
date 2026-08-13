<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Drivers;

enum StorageMoveResult: string
{
  case Moved = 'moved';
  case Replaced = 'replaced';
}

<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Drivers;

enum StorageDeleteResult: string
{
  case Deleted = 'deleted';
  case AlreadyAbsent = 'already_absent';
}

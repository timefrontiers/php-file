<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Drivers;

use TimeFrontiers\File\FileConfiguration;

interface StorageDriverFactoryInterface
{
  public function make(string $name, FileConfiguration $configuration): StorageDriverInterface;
}

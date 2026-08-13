<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Tests\Unit;

use TimeFrontiers\File\Concerns\FileData;
use TimeFrontiers\File\Concerns\ImageProcessor;
use TimeFrontiers\File\FileConfig;
use TimeFrontiers\File\FileConfiguration;
use TimeFrontiers\File\Tests\TestCase;

final class ImageLimitTest extends TestCase
{
  public function testImagePixelBombIsRejectedBeforeDecodeResize(): void
  {
    $root = $this->temporaryDirectory();
    $imagePath = $root . DIRECTORY_SEPARATOR . 'image.png';
    $image = imagecreatetruecolor(20, 20);
    imagepng($image, $imagePath);
    FileConfig::configure(['max_image_pixels' => 100], ['local' => ['upload_path' => $root]]);

    $this->expectException(\RuntimeException::class);
    (new ImageInspectionProbe())->inspect($imagePath, FileConfig::snapshot());
  }

  public function testHeaderOnlyTruncatedImageFailsFullDecode(): void
  {
    $root = $this->temporaryDirectory();
    $imagePath = $root . DIRECTORY_SEPARATOR . 'truncated.png';
    $image = imagecreatetruecolor(20, 20);
    imagepng($image, $imagePath);
    $bytes = file_get_contents($imagePath);
    if (!is_string($bytes)) self::fail('The fixture image could not be read.');
    file_put_contents($imagePath, substr($bytes, 0, 40));
    self::assertNotFalse(getimagesize($imagePath), 'The fixture must retain a parseable header.');
    FileConfig::configure([], ['local' => ['upload_path' => $root]]);

    $this->expectException(\RuntimeException::class);
    (new ImageInspectionProbe())->inspect($imagePath, FileConfig::snapshot());
  }
}

final class ImageInspectionProbe
{
  use FileData;
  use ImageProcessor;
  public ?string $type_group = 'image';
  protected string $_type = 'image/png';

  /** @return array{width:int,height:int,bits:int,channels:int}|null */
  public function inspect(string $path, FileConfiguration $configuration): ?array
  {
    return $this->inspectImage($path, $configuration);
  }
}

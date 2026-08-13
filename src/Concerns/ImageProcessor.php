<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Concerns;

use TimeFrontiers\File\FileConfiguration;

trait ImageProcessor
{
  /**
   * Validate that a raster image can be decoded within configured resource limits.
   *
   * @return array{width:int,height:int,bits:int,channels:int}|null
   */
  protected function inspectImage(string $filepath, FileConfiguration $configuration): ?array
  {
    if (!$this->isImage()) {
      return null;
    }
    $info = @getimagesize($filepath);
    if ($info === false || $info[0] < 1 || $info[1] < 1) {
      throw new \RuntimeException('The uploaded image could not be decoded.');
    }

    $width = (int)$info[0];
    $height = (int)$info[1];
    $bits = max(8, (int)($info['bits'] ?? 8));
    $channels = max(4, (int)($info['channels'] ?? 4));
    $pixels = $width * $height;
    if (intdiv($pixels, $height) !== $width) {
      throw new \RuntimeException('The uploaded image dimensions overflow the supported range.');
    }
    if ($pixels > (int)$configuration->get('max_image_pixels')) {
      throw new \RuntimeException('The uploaded image exceeds the configured pixel limit.');
    }

    // GD keeps a decoded source and a destination canvas during resize.
    $estimatedBytes = (int)ceil($pixels * $channels * ($bits / 8) * 2.5 + (filesize($filepath) ?: 0));
    if ($estimatedBytes > (int)$configuration->get('max_image_memory_bytes')) {
      throw new \RuntimeException('The uploaded image exceeds the configured decoder-memory limit.');
    }

    if (!class_exists(\Gumlet\ImageResize::class)) {
      throw new \RuntimeException('The image decoder required for upload verification is unavailable.');
    }
    try {
      // Construction performs a real GD decode. Header-only getimagesize()
      // success is not sufficient evidence that the raster is valid.
      $probe = @new \Gumlet\ImageResize($filepath);
      unset($probe);
    } catch (\Throwable $exception) {
      throw new \RuntimeException('The uploaded image could not be decoded safely.', 0, $exception);
    }

    $minWidth = $configuration->get('min_width_px');
    $minHeight = $configuration->get('min_height_px');
    if ($minWidth !== null && $width < (int)$minWidth) {
      throw new \RuntimeException("The uploaded image is narrower than {$minWidth}px.");
    }
    if ($minHeight !== null && $height < (int)$minHeight) {
      throw new \RuntimeException("The uploaded image is shorter than {$minHeight}px.");
    }

    return ['width' => $width, 'height' => $height, 'bits' => $bits, 'channels' => $channels];
  }

  /** Resize in place when configured maximum dimensions are exceeded. */
  protected function constrainImageSize(string $filepath, FileConfiguration $configuration): void
  {
    $image = $this->inspectImage($filepath, $configuration);
    if ($image === null) {
      return;
    }

    $maxWidth = $configuration->get('max_width_px');
    $maxHeight = $configuration->get('max_height_px');
    if ($maxWidth === null && $maxHeight === null) {
      return;
    }
    if (($maxWidth === null || $image['width'] <= (int)$maxWidth)
      && ($maxHeight === null || $image['height'] <= (int)$maxHeight)
    ) {
      return;
    }
    if (!class_exists(\Gumlet\ImageResize::class)) {
      throw new \RuntimeException('The configured image constraint cannot run because the image library is unavailable.');
    }

    try {
      $resizer = new \Gumlet\ImageResize($filepath);
      if ($maxWidth !== null && $maxHeight !== null) {
        $resizer->resizeToBestFit((int)$maxWidth, (int)$maxHeight);
      } elseif ($maxWidth !== null) {
        $resizer->resizeToWidth((int)$maxWidth);
      } else {
        $resizer->resizeToHeight((int)$maxHeight);
      }
      $resizer->save($filepath);
    } catch (\Throwable $exception) {
      throw new \RuntimeException('The uploaded image could not be resized safely.', 0, $exception);
    }

    // Decode the resulting image again instead of trusting the conversion.
    $this->inspectImage($filepath, $configuration);
  }
}

<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Concerns;

use TimeFrontiers\File\FileConfig;

/**
 * Thin image resize/validation seam.
 *
 * Responsibilities:
 *   - Reject uploads whose pixel dimensions fall below configured minimums.
 *   - Constrain uploaded images to configured maximum dimensions before storage.
 *
 * Only raster images supported (PNG, JPEG, GIF, BMP, WEBP).
 * SVG and other vector/non-raster types are passed through untouched.
 *
 * When timefrontiers/php-image is available this trait will delegate
 * to it; until then it uses gumlet/php-image-resize directly.
 *
 * Config keys (all optional):
 *   max_width_px   — resize down to this width  (null = no limit)
 *   max_height_px  — resize down to this height (null = no limit)
 *   min_width_px   — reject if image is narrower (null = no limit)
 *   min_height_px  — reject if image is shorter  (null = no limit)
 *
 * Requires: $this->isImage() and $this->_userError() from sibling traits.
 */
trait ImageProcessor
{
  // -------------------------------------------------------------------------
  // Validation (min dimensions — called before storage)
  // -------------------------------------------------------------------------

  /**
   * Validate pixel dimensions against configured minimums.
   *
   * @param string $filepath Absolute path to the temp file.
   * @return bool  true = accept; false = rejected (error added via HasErrors)
   */
  protected function validateImageDimensions(string $filepath): bool
  {
    if (!$this->isImage()) {
      return true;
    }

    [$width, $height] = $this->_readImageSize($filepath);

    if ($width === 0 && $height === 0) {
      // Can't read dimensions — don't block on technicality
      return true;
    }

    $minW = FileConfig::get('min_width_px');
    $minH = FileConfig::get('min_height_px');

    if ($minW !== null && $width < (int)$minW) {
      $this->_userError(
        'upload',
        "Image width ({$width}px) is below the required minimum of {$minW}px."
      );
      return false;
    }

    if ($minH !== null && $height < (int)$minH) {
      $this->_userError(
        'upload',
        "Image height ({$height}px) is below the required minimum of {$minH}px."
      );
      return false;
    }

    return true;
  }

  // -------------------------------------------------------------------------
  // Constraint (max dimensions — called after validation, before storage)
  // -------------------------------------------------------------------------

  /**
   * Resize image in-place if it exceeds configured maximum dimensions.
   * Silently skips if gumlet/php-image-resize is not available.
   *
   * @param string $filepath Absolute path to the (temp) file to resize.
   */
  protected function constrainImageSize(string $filepath): void
  {
    if (!$this->isImage()) {
      return;
    }

    if (!class_exists(\Gumlet\ImageResize::class)) {
      // Library not installed — skip silently
      return;
    }

    $maxW = FileConfig::get('max_width_px');
    $maxH = FileConfig::get('max_height_px');

    if ($maxW === null && $maxH === null) {
      return; // no constraint configured
    }

    [$width, $height] = $this->_readImageSize($filepath);

    if ($width === 0 && $height === 0) {
      return; // unreadable — skip
    }

    $exceedsW = $maxW !== null && $width  > (int)$maxW;
    $exceedsH = $maxH !== null && $height > (int)$maxH;

    if (!$exceedsW && !$exceedsH) {
      return; // already within bounds
    }

    try {
      $rz = new \Gumlet\ImageResize($filepath);

      if ($maxW !== null && $maxH !== null) {
        // Scale to fit within the bounding box, preserving aspect ratio
        $rz->resizeToBestFit((int)$maxW, (int)$maxH);
      } elseif ($maxW !== null) {
        $rz->resizeToWidth((int)$maxW);
      } else {
        $rz->resizeToHeight((int)$maxH);
      }

      $rz->save($filepath);
    } catch (\Throwable) {
      // Non-fatal — continue with original file if resize fails
    }
  }

  // -------------------------------------------------------------------------
  // Private
  // -------------------------------------------------------------------------

  /**
   * Read pixel dimensions safely.
   *
   * @return array{int, int}  [width, height]; [0, 0] on failure
   */
  private function _readImageSize(string $filepath): array
  {
    if (!file_exists($filepath)) {
      return [0, 0];
    }

    $info = @getimagesize($filepath);
    if ($info === false) {
      return [0, 0];
    }

    return [(int)$info[0], (int)$info[1]];
  }
}

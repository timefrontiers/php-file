<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Storage;

use TimeFrontiers\File\Exceptions\InvalidObjectKeyException;

/**
 * Canonical, driver-neutral key for an object inside a configured storage root.
 *
 * Keys always use forward slashes and never begin or end with a separator.
 */
final readonly class ObjectKey implements \Stringable
{
  // 700 UTF-8 characters remain indexable with a driver discriminator under
  // InnoDB's 3072-byte index-key limit.
  public const MAX_LENGTH = 700;

  private string $value;

  private function __construct(string $value)
  {
    $this->value = $value;
  }

  public static function fromString(string $value): self
  {
    if ($value === '') {
      throw new InvalidObjectKeyException('An object key must not be empty.');
    }
    if (strlen($value) > self::MAX_LENGTH) {
      throw new InvalidObjectKeyException(
        'The object key exceeds the maximum length of ' . self::MAX_LENGTH . ' bytes.'
      );
    }
    if (preg_match('//u', $value) !== 1) {
      throw new InvalidObjectKeyException('An object key must be valid UTF-8.');
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
      throw new InvalidObjectKeyException('Control characters are not permitted in an object key.');
    }
    if (str_contains($value, '\\')) {
      throw new InvalidObjectKeyException('Backslashes are not permitted in an object key.');
    }
    if (str_starts_with($value, '/') || preg_match('/^[A-Za-z]:/', $value) === 1) {
      throw new InvalidObjectKeyException('Absolute, drive-prefixed, and UNC object keys are not permitted.');
    }

    $segments = explode('/', $value);
    foreach ($segments as $segment) {
      if ($segment === '' || $segment === '.' || $segment === '..') {
        throw new InvalidObjectKeyException('Empty, dot, and dot-dot key segments are not permitted.');
      }
      if ($segment !== trim($segment)) {
        throw new InvalidObjectKeyException('Object key segments may not start or end with whitespace.');
      }
      // Colons are unsafe on Windows because they can address NTFS alternate streams.
      if (str_contains($segment, ':')) {
        throw new InvalidObjectKeyException('Colons are not permitted in an object key.');
      }
    }

    return new self($value);
  }

  /**
   * Join already-relative logical components into one canonical key.
   *
   * @param list<string> $components
   */
  public static function fromComponents(array $components): self
  {
    $parts = [];
    foreach ($components as $component) {
      if ($component === '') {
        continue;
      }
      if (str_starts_with($component, '/') || str_ends_with($component, '/')) {
        throw new InvalidObjectKeyException('Object key components must be relative and canonical.');
      }
      foreach (explode('/', $component) as $segment) {
        $parts[] = $segment;
      }
    }

    return self::fromString(implode('/', $parts));
  }

  public function append(string $relative): self
  {
    return self::fromString($this->value . '/' . $relative);
  }

  public function value(): string
  {
    return $this->value;
  }

  /** @return list<string> */
  public function segments(): array
  {
    return explode('/', $this->value);
  }

  public function basename(): string
  {
    $segments = $this->segments();
    return $segments[count($segments) - 1];
  }

  public function directory(): string
  {
    $segments = $this->segments();
    array_pop($segments);
    return implode('/', $segments);
  }

  public function __toString(): string
  {
    return $this->value;
  }
}

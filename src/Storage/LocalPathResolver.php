<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Storage;

use TimeFrontiers\File\Exceptions\InvalidObjectKeyException;

/** Resolves ObjectKey values beneath one immutable, canonical local root. */
final class LocalPathResolver
{
  private readonly string $root;

  public function __construct(string $configuredRoot)
  {
    if ($configuredRoot === '' || preg_match('/[\x00-\x1F\x7F]/', $configuredRoot) === 1) {
      throw new \InvalidArgumentException('The local upload root is empty or invalid.');
    }
    if (!self::isAbsolutePath($configuredRoot)) {
      throw new \InvalidArgumentException('The local upload root must be an absolute path.');
    }
    if (is_link($configuredRoot)) {
      throw new \InvalidArgumentException('The local upload root must not be a symbolic link.');
    }

    $resolved = realpath($configuredRoot);
    if ($resolved === false || !is_dir($resolved)) {
      throw new \InvalidArgumentException('The configured local upload root must already exist.');
    }

    $trimmed = rtrim($resolved, "\\/");
    if ($trimmed === '') {
      $trimmed = DIRECTORY_SEPARATOR;
    } elseif (preg_match('/^[A-Za-z]:$/D', $trimmed) === 1) {
      $trimmed .= DIRECTORY_SEPARATOR;
    }
    $this->root = $trimmed;
  }

  public function root(): string
  {
    return $this->root;
  }

  /** Resolve an existing or future target and optionally create safe parents. */
  public function resolve(ObjectKey $key, bool $createParents = false): string
  {
    $segments = $key->segments();
    $filename = array_pop($segments);
    $current = $this->root;

    foreach ($segments as $segment) {
      $next = rtrim($current, "\\/") . DIRECTORY_SEPARATOR . $segment;
      if (is_link($next)) {
        throw new InvalidObjectKeyException('Symbolic links are not permitted beneath the local upload root.');
      }

      if (!file_exists($next)) {
        if (!$createParents) {
          // Remaining path is safe lexically because the current directory has
          // already been resolved and ObjectKey rejects traversal segments.
          $current = $next;
          continue;
        }
        if (!mkdir($next, 0755) && !is_dir($next)) {
          throw new \RuntimeException('A local storage directory could not be created.');
        }
      }

      if (!is_dir($next)) {
        throw new InvalidObjectKeyException('An object key parent is not a directory.');
      }

      $resolved = realpath($next);
      if ($resolved === false || !$this->isInsideRoot($resolved)) {
        throw new InvalidObjectKeyException('The object key resolves outside the local upload root.');
      }
      $current = $resolved;
    }

    $target = rtrim($current, "\\/") . DIRECTORY_SEPARATOR . $filename;
    if (is_link($target)) {
      throw new InvalidObjectKeyException('Symbolic-link storage targets are not permitted.');
    }
    if (file_exists($target)) {
      $resolved = realpath($target);
      if ($resolved === false || !$this->isInsideRoot($resolved)) {
        throw new InvalidObjectKeyException('The storage target resolves outside the local upload root.');
      }
      return $resolved;
    }

    if (!$this->isInsideRoot($current)) {
      throw new InvalidObjectKeyException('The storage target is outside the local upload root.');
    }

    return $target;
  }

  private function isInsideRoot(string $path): bool
  {
    $root = str_replace('\\', '/', $this->root);
    $candidate = str_replace('\\', '/', $path);
    if (DIRECTORY_SEPARATOR === '\\') {
      $root = strtolower($root);
      $candidate = strtolower($candidate);
    }

    $rootWithoutTrailingSlash = rtrim($root, '/');
    if ($rootWithoutTrailingSlash === '') {
      return str_starts_with($candidate, '/');
    }
    return rtrim($candidate, '/') === $rootWithoutTrailingSlash
      || str_starts_with($candidate, $rootWithoutTrailingSlash . '/');
  }

  private static function isAbsolutePath(string $path): bool
  {
    return str_starts_with($path, '/')
      || str_starts_with($path, '\\\\')
      || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
  }
}

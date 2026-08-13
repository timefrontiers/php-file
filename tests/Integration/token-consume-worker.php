<?php
declare(strict_types=1);

use TimeFrontiers\File\FileConfig;
use TimeFrontiers\File\FileToken;
use TimeFrontiers\SQLDatabase;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

try {
  $input = stream_get_contents(STDIN);
  if (!is_string($input) || $input === '') {
    throw new RuntimeException('The worker payload is missing.');
  }
  /** @var array<string, mixed> $payload */
  $payload = json_decode($input, true, flags: JSON_THROW_ON_ERROR);
  $adapter = (string)($payload['adapter'] ?? '');
  $host = (string)($payload['host'] ?? '');
  $port = (int)($payload['port'] ?? 3306);
  $database = (string)($payload['database'] ?? '');
  $user = (string)($payload['user'] ?? '');
  $password = (string)($payload['password'] ?? '');
  $root = (string)($payload['root'] ?? '');
  $ready = (string)($payload['ready'] ?? '');
  $go = (string)($payload['go'] ?? '');

  $connection = $adapter === 'pdo'
    ? SQLDatabase::pdo('mysql', $host, $port, $database, $user, $password)
    : new SQLDatabase($host, $user, $password, $database, true, (string)$port);
  FileConfig::configure([
    'db_name' => $database,
    'path_prefix' => 'Files',
    'service_url' => 'https://files.example.test',
    'token_enabled' => true,
    'token_keys' => [
      'retired' => str_repeat('r', 32),
      'current' => str_repeat('c', 32),
    ],
    'active_token_key_id' => 'current',
  ], ['local' => ['upload_path' => $root]]);

  if ($ready === '' || file_put_contents($ready, 'ready', LOCK_EX) === false) {
    throw new RuntimeException('The worker could not reach the concurrency barrier.');
  }
  $deadline = microtime(true) + 10;
  while (!is_file($go) && microtime(true) < $deadline) {
    usleep(10_000);
  }
  if (!is_file($go)) {
    throw new RuntimeException('The concurrency barrier timed out.');
  }

  echo FileToken::consume(
    (string)($payload['bearer'] ?? ''),
    (int)($payload['file_id'] ?? 0),
    $connection
  ) ? '1' : '0';
} catch (Throwable $exception) {
  fwrite(STDERR, $exception::class . ': ' . $exception->getMessage());
  exit(1);
}

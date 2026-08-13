<?php
declare(strict_types=1);

namespace TimeFrontiers\File\Tests\Unit;

use TimeFrontiers\File\File;
use TimeFrontiers\File\FileConfig;
use TimeFrontiers\File\Tests\TestCase;
use TimeFrontiers\SQLDatabase;

final class HydrationAndDriverTest extends TestCase
{
  public function testHydrationIgnoresUnexpectedColumnsAndKeepsExactConnection(): void
  {
    $root = $this->temporaryDirectory();
    FileConfig::configure([], ['local' => ['upload_path' => $root]]);
    $first = $this->createMock(SQLDatabase::class);
    $second = $this->createMock(SQLDatabase::class);
    $row = $this->row();
    $row['unexpected_column'] = 'must not hydrate';

    $one = File::_instantiateFromRow($row, $first);
    $two = File::_instantiateFromRow(array_replace($row, ['id' => 2, 'code' => '583000000000002']), $second);
    self::assertSame($first, $one->conn());
    self::assertSame($second, $two->conn());
    self::assertFalse(property_exists($one, 'unexpected_column'));
  }

  public function testFilesystemPathApiRejectsRemoteRecord(): void
  {
    $root = $this->temporaryDirectory();
    FileConfig::configure(
      ['default_driver' => 's3'],
      [
        'local' => ['upload_path' => $root],
        's3' => ['bucket' => 'valid-bucket', 'region' => 'us-east-1', 'key' => 'key', 'secret' => 'secret'],
      ]
    );
    $file = new File($this->createMock(SQLDatabase::class), 's3');
    $this->expectException(\LogicException::class);
    $file->fullPath();
  }

  /** @return array<string, mixed> */
  private function row(): array
  {
    return [
      'id' => 1, 'code' => '583000000000001', 'nice_name' => 'a.txt',
      'type_group' => 'text', 'caption' => null, 'owner' => 'owner',
      'privacy' => 'public', 'storage_driver' => 'local', 'storage_bucket' => null,
      '_name' => 'a.txt', '_path' => 'Files/owner', 'object_key' => 'Files/owner/a.txt',
      '_type' => 'text/plain', '_size' => 1, '_checksum' => str_repeat('a', 128),
      '_locked' => 0, '_watermarked' => 0, 'lifecycle_state' => 'active',
      'cleanup_required_at' => null, 'cleanup_error_code' => null,
      'cleanup_operation' => null, 'cleanup_attempts' => 0, 'deleted_at' => null,
      '_creator' => 'SYSTEM', '_updated' => null, '_created' => null,
    ];
  }
}
